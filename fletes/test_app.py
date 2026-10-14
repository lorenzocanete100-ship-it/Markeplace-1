import hashlib
import hmac
import io
import re
import sqlite3
import tempfile
import time
from contextlib import closing
import unittest
import uuid
from datetime import date, timedelta
from pathlib import Path

from PIL import Image
from werkzeug.security import generate_password_hash

from app import create_app, valid_cuil, valid_plate

FUTURE = (date.today() + timedelta(days=365)).isoformat()
TOMORROW = (date.today() + timedelta(days=1)).isoformat()


def make_cuil(prefix='20', dni='12345678'):
    for last in range(10):
        if valid_cuil(f'{prefix}{dni}{last}'):
            return f'{prefix}{dni}{last}'
    return make_cuil('23', dni)


def png():
    out = io.BytesIO()
    Image.new('RGB', (40, 30), 'blue').save(out, 'PNG')
    return out.getvalue()


ORDER = {'service': 'flete', 'origin': 'palermo-caba', 'destination': 'quilmes-gba-sur', 'vehicle': 'utilitario',
         'kg': 200, 'helpers': 1, 'declared_value': 100000, 'insurance': True, 'pickup_date': TOMORROW, 'slot': 'am',
         'origin_address': 'Av. Santa Fe 3200 5B', 'dest_address': 'Mitre 450', 'description': 'Heladera y 5 cajas',
         'receiver_name': 'Ana Pérez', 'receiver_phone': '11 2345 6789'}


class FletesTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.config = {'TESTING': True, 'SECRET_KEY': 'test-secret-not-for-production-12345678', 'DATA_DIR': self.temp.name,
                       'ADMIN_EMAIL': 'admin@example.com', 'MP_ACCESS_TOKEN': '', 'PUBLIC_URL': '', 'DEMO_PAYMENTS': True}
        self.app = create_app(self.config)

    def tearDown(self):
        self.temp.cleanup()

    def client_for(self, email, name='Usuario'):
        c = self.app.test_client()
        token = c.get('/api/session').json['csrf']
        r = c.post('/api/auth/register', json={'email': email, 'password': 'a-test-password-123', 'name': name, 'phone': '1122334455'},
                   headers={'X-CSRF-Token': token})
        self.assertEqual(r.status_code, 200, r.json)
        c.csrf = r.json['csrf']
        return c

    def admin(self):
        with closing(sqlite3.connect(Path(self.temp.name) / 'fletes.db')) as con, con:
            con.execute('INSERT INTO users VALUES (?,?,?,?,?,?)', (uuid.uuid4().hex, 'admin@example.com', 'Admin', '1100000000',
                                                                    generate_password_hash('a-test-password-123'), int(time.time())))
        c = self.app.test_client()
        token = c.get('/api/session').json['csrf']
        r = c.post('/api/auth/login', json={'email': 'admin@example.com', 'password': 'a-test-password-123'}, headers={'X-CSRF-Token': token})
        c.csrf = r.json['csrf']
        self.assertTrue(r.json['user']['admin'])
        return c

    def post(self, c, path, **kw):
        return c.post(path, headers={'X-CSRF-Token': c.csrf}, **kw)

    def apply(self, c, vehicle='camioneta', **overrides):
        data = {'cuil': make_cuil(), 'vehicle': vehicle, 'vehicle_model': 'Renault Master', 'plate': 'AB 123 CD', 'base_city': 'caballito-caba',
                'license_expiry': FUTURE, 'insurance_expiry': FUTURE, 'long_distance': '1'}
        for kind in ('dni_front', 'dni_back', 'selfie', 'license', 'vehicle_card'):
            data[kind] = (io.BytesIO(png()), kind + '.png')
        data['insurance'] = (io.BytesIO(b'%PDF-1.4 poliza'), 'poliza.pdf')
        data['background'] = (io.BytesIO(png()), 'antecedentes.png')
        data.update(overrides)
        return self.post(c, '/api/driver/apply', data=data)

    def verified_driver(self, email='chofer@example.com', vehicle='camioneta'):
        d = self.client_for(email, 'Chofer')
        self.assertEqual(self.apply(d, vehicle).status_code, 201)
        admin = self.admin() if not hasattr(self, '_admin') else self._admin
        self._admin = admin
        uid = admin.get('/api/admin/drivers').json['items'][-1]['user_id']
        self.assertEqual(self.post(admin, '/api/admin/drivers/' + uid, json={'action': 'approve'}).status_code, 200)
        return d

    def test_validators(self):
        self.assertTrue(valid_cuil(make_cuil()))
        self.assertFalse(valid_cuil('20123456780' if not valid_cuil('20123456780') else '20123456781'))
        self.assertTrue(valid_plate('AB123CD') and valid_plate('ABC123') and valid_plate('A123BCD'))
        self.assertFalse(valid_plate('12345'))

    def test_quotes(self):
        c = self.app.test_client()
        catalog = c.get('/api/catalog').json
        self.assertGreater(len(catalog['cities']), 100)
        short = c.post('/api/quote', json=ORDER, headers={'X-CSRF-Token': c.get('/api/session').json['csrf']}).json
        self.assertFalse(short['long_distance'])
        self.assertEqual(short['insurance'], 1500)
        csrf = c.get('/api/session').json['csrf']
        long_trip = c.post('/api/quote', json={**ORDER, 'destination': 'cordoba-cordoba'}, headers={'X-CSRF-Token': csrf}).json
        self.assertTrue(long_trip['long_distance'])
        self.assertGreater(long_trip['distance_km'], 600)
        self.assertGreater(long_trip['price'], short['price'])
        self.assertLess(long_trip['payout'], long_trip['price'])
        bultos = c.post('/api/quote', json={**ORDER, 'service': 'bultos', 'kg': 10, 'destination': 'mendoza-mendoza'}, headers={'X-CSRF-Token': csrf}).json
        self.assertEqual((bultos['vehicle'], bultos['helpers']), ('auto', 0))
        moto = c.post('/api/quote', json={**ORDER, 'vehicle': 'moto', 'kg': 5, 'destination': 'rosario-santa-fe'}, headers={'X-CSRF-Token': csrf})
        self.assertEqual(moto.status_code, 400)
        heavy = c.post('/api/quote', json={**ORDER, 'vehicle': 'auto', 'kg': 900}, headers={'X-CSRF-Token': csrf})
        self.assertEqual(heavy.status_code, 400)

    def test_full_flow_demo_payment(self):
        client = self.client_for('cliente@example.com', 'Cliente')
        self.assertEqual(self.app.test_client().post('/api/shipments', json=ORDER).status_code, 403)
        r = self.post(client, '/api/shipments', json=ORDER)
        self.assertEqual(r.status_code, 201, r.json)
        sid, code = r.json['id'], r.json['code']
        detail = client.get('/api/shipments/' + sid).json
        pin = detail['pin']
        self.assertEqual(detail['status'], 'pending_payment')

        driver = self.verified_driver()
        self.assertEqual(driver.get('/api/driver/jobs').json['items'], [])  # unpaid jobs are not published
        self.assertEqual(self.post(client, f'/api/shipments/{sid}/pay', json={'method': 'card'}).json, {'demo': True})
        self.assertEqual(self.post(client, f'/api/shipments/{sid}/demo-pay', json={'method': 'card'}).status_code, 200)

        jobs = driver.get('/api/driver/jobs').json['items']
        self.assertEqual(len(jobs), 1)
        self.assertNotIn('origin_address', jobs[0])  # exact address only after accepting
        self.assertNotIn('pin', jobs[0])
        small = self.verified_driver('auto@example.com', 'auto')
        self.assertEqual(small.get('/api/driver/jobs').json['items'], [])
        self.assertEqual(self.post(small, f'/api/shipments/{sid}/accept').status_code, 403)

        accepted = self.post(driver, f'/api/shipments/{sid}/accept')
        self.assertEqual(accepted.status_code, 200)
        self.assertEqual(accepted.json['origin_address'], ORDER['origin_address'])
        self.assertNotIn('pin', accepted.json)
        other = self.verified_driver('otro@example.com')
        self.assertEqual(self.post(other, f'/api/shipments/{sid}/accept').status_code, 409)

        seen = client.get('/api/shipments/' + sid).json
        self.assertTrue(seen['driver']['verified'])
        self.assertEqual(seen['driver']['plate'], 'AB123CD')
        self.assertEqual(self.post(driver, f'/api/shipments/{sid}/deliver', json={'pin': pin}).status_code, 400)  # not picked up
        self.assertEqual(self.post(driver, f'/api/shipments/{sid}/pickup').status_code, 200)
        self.assertEqual(self.post(driver, f'/api/shipments/{sid}/deliver', json={'pin': '9999' if pin != '9999' else '0000'}).status_code, 400)
        done = self.post(driver, f'/api/shipments/{sid}/deliver', json={'pin': pin})
        self.assertEqual(done.json['status'], 'delivered')

        self.assertEqual(self.post(client, f'/api/shipments/{sid}/rate', json={'stars': 5, 'review': 'Impecable'}).status_code, 200)
        self.assertEqual(self.post(client, f'/api/shipments/{sid}/rate', json={'stars': 5}).status_code, 400)
        me = driver.get('/api/driver/me').json['driver']
        self.assertEqual((me['rating'], me['trips']), (5.0, 1))
        tracked = self.app.test_client().get('/api/track/' + code.lower()).json
        self.assertEqual([e['status'] for e in tracked['events']], ['pending_payment', 'paid', 'assigned', 'in_transit', 'delivered'])
        self.assertNotIn('origin_address', tracked)

    def test_privacy_and_permissions(self):
        client = self.client_for('cliente@example.com')
        sid = self.post(client, '/api/shipments', json=ORDER).json['id']
        stranger = self.client_for('otro@example.com')
        self.assertEqual(stranger.get('/api/shipments/' + sid).status_code, 404)
        self.assertEqual(self.post(stranger, f'/api/shipments/{sid}/cancel').status_code, 404)
        self.assertEqual(stranger.get('/api/driver/jobs').status_code, 403)  # not a verified driver
        self.assertEqual(self.apply(stranger).status_code, 201)
        self.assertEqual(stranger.get('/api/driver/jobs').status_code, 403)  # pending review
        self.assertEqual(stranger.get('/api/admin/drivers').status_code, 403)
        admin = self.admin()
        docs = admin.get('/api/admin/drivers').json['items'][0]['docs']
        self.assertTrue(docs['insurance'].endswith('.pdf'))
        doc = admin.get('/api/admin/docs/' + docs['selfie'])
        self.assertEqual(doc.status_code, 200)
        doc.close()
        self.assertEqual(stranger.get('/api/admin/docs/' + docs['selfie']).status_code, 403)
        self.assertEqual(self.post(admin, '/api/admin/drivers/x', json={'action': 'reject'}).status_code, 400)  # reason required

    def test_driver_documents_validated(self):
        d = self.client_for('chofer@example.com')
        self.assertEqual(self.apply(d, cuil='20123456789' if not valid_cuil('20123456789') else '1').status_code, 400)
        self.assertEqual(self.apply(d, license_expiry=(date.today() - timedelta(days=1)).isoformat()).status_code, 400)
        self.assertEqual(self.apply(d, plate='XX').status_code, 400)
        bad = {'dni_front': (io.BytesIO(b'%PDF-1.4 not allowed for DNI'), 'dni.pdf')}
        self.assertEqual(self.apply(d, **bad).status_code, 400)
        self.assertEqual(list((Path(self.temp.name) / 'docs').iterdir()), [])

    def test_cancel_paid_creates_refund(self):
        client = self.client_for('cliente@example.com')
        sid = self.post(client, '/api/shipments', json=ORDER).json['id']
        self.post(client, f'/api/shipments/{sid}/demo-pay', json={'method': 'mercadopago'})
        r = self.post(client, f'/api/shipments/{sid}/cancel')
        self.assertTrue(r.json['refund'])
        admin = self.admin()
        self.assertEqual(len(admin.get('/api/admin/shipments?refunds=1').json['items']), 1)
        self.assertEqual(self.post(admin, f'/api/admin/shipments/{sid}/refunded').status_code, 200)
        self.assertEqual(admin.get('/api/admin/shipments?refunds=1').json['items'], [])

    def test_mercadopago_checkout_and_webhook(self):
        app = create_app({**self.config, 'MP_ACCESS_TOKEN': 'TEST-token', 'PUBLIC_URL': 'https://fleta.example', 'MP_WEBHOOK_SECRET': 'whsec'})
        calls = []
        payments = {}

        def fake_mp(method, path, body=None):
            calls.append((method, path, body))
            if path == '/checkout/preferences':
                return {'init_point': 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=1'}
            return payments[path.rsplit('/', 1)[1]]
        app.mp_request = fake_mp
        self.app = app
        client = self.client_for('cliente@example.com')
        sid = self.post(client, '/api/shipments', json=ORDER).json['id']
        price = client.get('/api/shipments/' + sid).json['price']

        r = self.post(client, f'/api/shipments/{sid}/pay', json={'method': 'card'})
        self.assertTrue(r.json['url'].startswith('https://www.mercadopago.com.ar/'))
        pref = calls[-1][2]
        self.assertEqual((pref['external_reference'], pref['items'][0]['unit_price']), (sid, price))
        self.assertIn({'id': 'ticket'}, pref['payment_methods']['excluded_payment_types'])
        self.assertEqual(self.post(client, f'/api/shipments/{sid}/demo-pay').status_code, 404)  # no fake payments with real MP

        hook = app.test_client()
        self.assertEqual(hook.post('/api/payments/webhook?type=payment&data.id=1').status_code, 401)  # unsigned

        def signed(pid):
            ts, rid = '1700000000', 'req-1'
            v1 = hmac.new(b'whsec', f'id:{pid};request-id:{rid};ts:{ts};'.encode(), hashlib.sha256).hexdigest()
            return hook.post(f'/api/payments/webhook?type=payment&data.id={pid}', json={'type': 'payment', 'data': {'id': pid}},
                             headers={'x-signature': f'ts={ts},v1={v1}', 'x-request-id': rid})

        payments['111'] = {'id': 111, 'status': 'approved', 'currency_id': 'ARS', 'transaction_amount': price - 1000,
                           'external_reference': sid, 'payment_type_id': 'credit_card'}
        self.assertEqual(signed('111').status_code, 200)
        self.assertEqual(client.get('/api/shipments/' + sid).json['status'], 'pending_payment')  # underpaid: ignored
        payments['222'] = {**payments['111'], 'id': 222, 'transaction_amount': price}
        self.assertEqual(signed('222').status_code, 200)
        s = client.get('/api/shipments/' + sid).json
        self.assertEqual((s['status'], s['payment_method']), ('paid', 'card'))
        self.assertEqual(signed('222').status_code, 200)  # duplicate notification is harmless
        self.assertEqual(len(client.get('/api/shipments/' + sid).json['events']), 2)

    def test_frontend_and_health(self):
        c = self.app.test_client()
        html = c.get('/').data.decode()
        for asset in re.findall(r'(?:src|href)="(/[^"]+)"', html):
            self.assertEqual(c.get(asset).status_code, 200, asset)
        self.assertEqual(c.get('/pago/' + 'a' * 32).status_code, 200)
        self.assertTrue(c.get('/healthz').json['ok'])
        self.assertIn("script-src 'self'", c.get('/').headers['Content-Security-Policy'])


if __name__ == '__main__':
    unittest.main()
