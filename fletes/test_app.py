import hashlib
import hmac
import io
import re
import sqlite3
import tempfile
import time
import unittest
import uuid
from contextlib import closing
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


ORDER = {'size': 'two', 'origin': 'palermo-caba', 'destination': 'quilmes-gba-sur', 'origin_floor': 3, 'origin_elevator': False,
         'dest_floor': 0, 'dest_elevator': False, 'packing': True, 'assembly': False, 'declared_value': 1000000, 'insurance': True,
         'pickup_date': TOMORROW, 'slot': 'am', 'origin_address': 'Av. Santa Fe 3200 5B', 'dest_address': 'Mitre 450',
         'inventory': 'Heladera, cama y 10 cajas', 'receiver_name': 'Ana Pérez', 'receiver_phone': '11 2345 6789'}
INTL = {**ORDER, 'destination': 'montevideo-uruguay', 'receiver_phone': '+598 99 123 456',
        'inventory': '1 heladera\n1 cama 2 plazas\n10 cajas de ropa\n1 bicicleta'}


class MudanzasTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.config = {'TESTING': True, 'SECRET_KEY': 'test-secret-not-for-production-12345678', 'DATA_DIR': self.temp.name,
                       'ADMIN_EMAIL': 'admin@example.com', 'MP_ACCESS_TOKEN': '', 'PUBLIC_URL': '', 'DEMO_PAYMENTS': True}
        self.app = create_app(self.config)
        self._admin = None

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
        if self._admin:
            return self._admin
        with closing(sqlite3.connect(Path(self.temp.name) / 'mudanzas.db')) as con, con:
            con.execute('INSERT INTO users VALUES (?,?,?,?,?,?)', (uuid.uuid4().hex, 'admin@example.com', 'Admin', '1100000000',
                                                                    generate_password_hash('a-test-password-123'), int(time.time())))
        c = self.app.test_client()
        token = c.get('/api/session').json['csrf']
        r = c.post('/api/auth/login', json={'email': 'admin@example.com', 'password': 'a-test-password-123'}, headers={'X-CSRF-Token': token})
        c.csrf = r.json['csrf']
        self.assertTrue(r.json['user']['admin'])
        self._admin = c
        return c

    def post(self, c, path, **kw):
        return c.post(path, headers={'X-CSRF-Token': c.csrf}, **kw)

    def quote(self, **kw):
        c = self.app.test_client()
        return c.post('/api/quote', json={**ORDER, **kw}, headers={'X-CSRF-Token': c.get('/api/session').json['csrf']})

    def apply(self, c, vehicle='camion', international=False, **overrides):
        data = {'cuil': make_cuil(), 'vehicle': vehicle, 'vehicle_model': 'Mercedes Accelo', 'plate': 'AB 123 CD', 'base_city': 'caballito-caba',
                'license_expiry': FUTURE, 'insurance_expiry': FUTURE, 'long_distance': '1'}
        for kind in ('dni_front', 'dni_back', 'selfie', 'license', 'vehicle_card'):
            data[kind] = (io.BytesIO(png()), kind + '.png')
        data['insurance'] = (io.BytesIO(b'%PDF-1.4 poliza'), 'poliza.pdf')
        data['background'] = (io.BytesIO(png()), 'antecedentes.png')
        if international:
            data['international'] = '1'
            data['intl_permit'] = (io.BytesIO(b'%PDF-1.4 permiso'), 'permiso.pdf')
            data['intl_insurance'] = (io.BytesIO(png()), 'carta-verde.png')
        data.update(overrides)
        return self.post(c, '/api/driver/apply', data=data)

    def verified_driver(self, email='chofer@example.com', vehicle='camion', international=False):
        d = self.client_for(email, 'Chofer')
        self.assertEqual(self.apply(d, vehicle, international).status_code, 201)
        admin = self.admin()
        uid = admin.get('/api/admin/drivers').json['items'][-1]['user_id']
        self.assertEqual(self.post(admin, '/api/admin/drivers/' + uid, json={'action': 'approve'}).status_code, 200)
        return d

    def test_validators(self):
        self.assertTrue(valid_cuil(make_cuil()))
        self.assertFalse(valid_cuil('20123456780' if not valid_cuil('20123456780') else '20123456781'))
        self.assertTrue(valid_plate('AB123CD') and valid_plate('ABC123'))
        self.assertFalse(valid_plate('12345'))

    def test_quotes_by_scope(self):
        catalog = self.app.test_client().get('/api/catalog').json
        self.assertGreater(len(catalog['cities']), 140)
        self.assertEqual(set(catalog['countries']), {'AR', 'UY', 'CL', 'PY', 'BO', 'BR'})
        local = self.quote().json
        self.assertEqual(local['scope'], 'amba')
        self.assertEqual(local['vehicle'], 'camion')
        lines = dict(local['breakdown'])
        self.assertGreater(lines['Escaleras sin ascensor'], 0)
        self.assertGreater(lines['Embalaje'], 0)
        self.assertEqual(local['insurance'], 15000)
        self.assertFalse(local['shared_available'])
        lift = self.quote(origin_elevator=True).json
        self.assertEqual(dict(lift['breakdown'])['Escaleras sin ascensor'], 0)

        national = self.quote(destination='cordoba-cordoba').json
        self.assertEqual(national['scope'], 'nacional')
        self.assertTrue(national['shared_available'])
        shared = self.quote(destination='cordoba-cordoba', shared=True).json
        self.assertTrue(shared['shared'])
        self.assertLess(shared['price'], national['price'])

        intl = self.quote(destination='montevideo-uruguay').json
        self.assertEqual(intl['scope'], 'internacional')
        self.assertIn('Fray Bentos', intl['crossing'])
        self.assertTrue(500 < intl['distance_km'] < 700)
        self.assertGreater(intl['customs_fee'], 0)
        self.assertLess(intl['payout'], intl['price'] - intl['customs_fee'])  # customs and insurance are not the driver's
        chile = self.quote(origin='mendoza-mendoza', destination='santiago-chile').json
        self.assertIn('Cristo Redentor', chile['crossing'])
        self.assertEqual(self.quote(origin='montevideo-uruguay', destination='santiago-chile').status_code, 400)
        self.assertEqual(self.quote(size='castle').status_code, 400)
        self.assertEqual(self.quote(origin_floor=99).status_code, 400)

    def test_full_flow_demo_payment(self):
        client = self.client_for('cliente@example.com', 'Cliente')
        self.assertEqual(self.app.test_client().post('/api/moves', json=ORDER).status_code, 403)
        r = self.post(client, '/api/moves', json=ORDER)
        self.assertEqual(r.status_code, 201, r.json)
        mid, code = r.json['id'], r.json['code']
        detail = client.get('/api/moves/' + mid).json
        pin = detail['pin']
        self.assertEqual((detail['status'], detail['size_label'], detail['packing']), ('pending_payment', '2 ambientes', True))

        driver = self.verified_driver()
        self.assertEqual(driver.get('/api/driver/jobs').json['items'], [])  # unpaid moves are not published
        self.assertEqual(self.post(client, f'/api/moves/{mid}/pay', json={'method': 'card'}).json, {'demo': True})
        self.assertEqual(self.post(client, f'/api/moves/{mid}/demo-pay', json={'method': 'card'}).status_code, 200)

        jobs = driver.get('/api/driver/jobs').json['items']
        self.assertEqual(len(jobs), 1)
        self.assertNotIn('origin_address', jobs[0])  # exact address only after accepting
        self.assertNotIn('pin', jobs[0])
        self.assertNotIn('inventory', jobs[0])
        small = self.verified_driver('chico@example.com', 'utilitario')
        self.assertEqual(small.get('/api/driver/jobs').json['items'], [])
        self.assertEqual(self.post(small, f'/api/moves/{mid}/accept').status_code, 403)

        accepted = self.post(driver, f'/api/moves/{mid}/accept')
        self.assertEqual(accepted.status_code, 200)
        self.assertEqual(accepted.json['origin_address'], ORDER['origin_address'])
        self.assertNotIn('pin', accepted.json)
        other = self.verified_driver('otro@example.com')
        self.assertEqual(self.post(other, f'/api/moves/{mid}/accept').status_code, 409)

        seen = client.get('/api/moves/' + mid).json
        self.assertTrue(seen['driver']['verified'])
        self.assertEqual(seen['driver']['plate'], 'AB123CD')
        self.assertEqual(self.post(driver, f'/api/moves/{mid}/deliver', json={'pin': pin}).status_code, 400)  # not loaded yet
        self.assertEqual(self.post(driver, f'/api/moves/{mid}/pickup').status_code, 200)
        self.assertEqual(self.post(driver, f'/api/moves/{mid}/deliver', json={'pin': '9999' if pin != '9999' else '0000'}).status_code, 400)
        done = self.post(driver, f'/api/moves/{mid}/deliver', json={'pin': pin})
        self.assertEqual(done.json['status'], 'delivered')

        self.assertEqual(self.post(client, f'/api/moves/{mid}/rate', json={'stars': 5, 'review': 'Impecable'}).status_code, 200)
        self.assertEqual(self.post(client, f'/api/moves/{mid}/rate', json={'stars': 5}).status_code, 400)
        me = driver.get('/api/driver/me').json['driver']
        self.assertEqual((me['rating'], me['trips']), (5.0, 1))
        tracked = self.app.test_client().get('/api/track/' + code.lower()).json
        self.assertEqual([e['status'] for e in tracked['events']], ['pending_payment', 'paid', 'assigned', 'in_transit', 'delivered'])
        self.assertNotIn('origin_address', tracked)

    def test_international_move_needs_inventory_and_intl_driver(self):
        client = self.client_for('cliente@example.com')
        self.assertEqual(self.post(client, '/api/moves', json={**INTL, 'inventory': 'cajas'}).status_code, 400)
        r = self.post(client, '/api/moves', json=INTL)
        self.assertEqual(r.status_code, 201, r.json)
        mid = r.json['id']
        self.post(client, f'/api/moves/{mid}/demo-pay', json={'method': 'mercadopago'})
        local_only = self.verified_driver('local@example.com')
        self.assertEqual(local_only.get('/api/driver/jobs').json['items'], [])
        self.assertEqual(self.post(local_only, f'/api/moves/{mid}/accept').status_code, 403)
        d = self.client_for('sinpermiso@example.com')
        self.assertEqual(self.post(d, '/api/driver/apply', data={
            'cuil': make_cuil(), 'vehicle': 'camion', 'vehicle_model': 'Iveco', 'plate': 'AB123CD', 'base_city': 'caballito-caba',
            'license_expiry': FUTURE, 'insurance_expiry': FUTURE, 'international': '1'}).status_code, 400)
        intl_driver = self.verified_driver('intl@example.com', international=True)
        jobs = intl_driver.get('/api/driver/jobs').json['items']
        self.assertEqual([j['scope'] for j in jobs], ['internacional'])
        self.assertEqual(self.post(intl_driver, f'/api/moves/{mid}/accept').status_code, 200)
        self.assertTrue(client.get('/api/moves/' + mid).json['driver']['international'])
        docs = [d['label'] for d in self.admin().get('/api/admin/drivers?status=approved').json['items'][-1]['documents']]
        self.assertIn('Permiso de transporte internacional de cargas', docs)

    def test_privacy_and_permissions(self):
        client = self.client_for('cliente@example.com')
        mid = self.post(client, '/api/moves', json=ORDER).json['id']
        stranger = self.client_for('otro@example.com')
        self.assertEqual(stranger.get('/api/moves/' + mid).status_code, 404)
        self.assertEqual(self.post(stranger, f'/api/moves/{mid}/cancel').status_code, 404)
        self.assertEqual(stranger.get('/api/driver/jobs').status_code, 403)  # not a verified driver
        self.assertEqual(self.apply(stranger).status_code, 201)
        self.assertEqual(stranger.get('/api/driver/jobs').status_code, 403)  # pending review
        self.assertEqual(stranger.get('/api/admin/drivers').status_code, 403)
        admin = self.admin()
        docs = {d['label']: d for d in admin.get('/api/admin/drivers').json['items'][0]['documents']}
        self.assertTrue(docs['Póliza de seguro vigente']['pdf'])
        selfie = docs['Selfie sosteniendo el DNI']['url']
        doc = admin.get(selfie)
        self.assertEqual(doc.status_code, 200)
        doc.close()
        self.assertEqual(stranger.get(selfie).status_code, 403)
        self.assertEqual(self.post(admin, '/api/admin/drivers/x', json={'action': 'reject'}).status_code, 400)  # reason required

    def test_driver_documents_validated(self):
        d = self.client_for('chofer@example.com')
        self.assertEqual(self.apply(d, cuil='20123456789' if not valid_cuil('20123456789') else '1').status_code, 400)
        self.assertEqual(self.apply(d, license_expiry=(date.today() - timedelta(days=1)).isoformat()).status_code, 400)
        self.assertEqual(self.apply(d, plate='XX').status_code, 400)
        self.assertEqual(self.apply(d, base_city='santiago-chile').status_code, 400)
        bad = {'dni_front': (io.BytesIO(b'%PDF-1.4 not allowed for DNI'), 'dni.pdf')}
        self.assertEqual(self.apply(d, **bad).status_code, 400)
        self.assertEqual(list((Path(self.temp.name) / 'docs').iterdir()), [])

    def test_cancel_paid_creates_refund(self):
        client = self.client_for('cliente@example.com')
        mid = self.post(client, '/api/moves', json=ORDER).json['id']
        self.post(client, f'/api/moves/{mid}/demo-pay', json={'method': 'mercadopago'})
        r = self.post(client, f'/api/moves/{mid}/cancel')
        self.assertTrue(r.json['refund'])
        admin = self.admin()
        self.assertEqual(len(admin.get('/api/admin/moves?refunds=1').json['items']), 1)
        self.assertEqual(self.post(admin, f'/api/admin/moves/{mid}/refunded').status_code, 200)
        self.assertEqual(admin.get('/api/admin/moves?refunds=1').json['items'], [])

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
        mid = self.post(client, '/api/moves', json=ORDER).json['id']
        price = client.get('/api/moves/' + mid).json['price']

        r = self.post(client, f'/api/moves/{mid}/pay', json={'method': 'card'})
        self.assertTrue(r.json['url'].startswith('https://www.mercadopago.com.ar/'))
        pref = calls[-1][2]
        self.assertEqual((pref['external_reference'], pref['items'][0]['unit_price']), (mid, price))
        self.assertIn({'id': 'ticket'}, pref['payment_methods']['excluded_payment_types'])
        self.assertEqual(self.post(client, f'/api/moves/{mid}/demo-pay').status_code, 404)  # no fake payments with real MP

        hook = app.test_client()
        self.assertEqual(hook.post('/api/payments/webhook?type=payment&data.id=1').status_code, 401)  # unsigned

        def signed(pid):
            ts, rid = '1700000000', 'req-1'
            v1 = hmac.new(b'whsec', f'id:{pid};request-id:{rid};ts:{ts};'.encode(), hashlib.sha256).hexdigest()
            return hook.post(f'/api/payments/webhook?type=payment&data.id={pid}', json={'type': 'payment', 'data': {'id': pid}},
                             headers={'x-signature': f'ts={ts},v1={v1}', 'x-request-id': rid})

        payments['111'] = {'id': 111, 'status': 'approved', 'currency_id': 'ARS', 'transaction_amount': price - 1000,
                           'external_reference': mid, 'payment_type_id': 'credit_card'}
        self.assertEqual(signed('111').status_code, 200)
        self.assertEqual(client.get('/api/moves/' + mid).json['status'], 'pending_payment')  # underpaid: ignored
        payments['222'] = {**payments['111'], 'id': 222, 'transaction_amount': price}
        self.assertEqual(signed('222').status_code, 200)
        m = client.get('/api/moves/' + mid).json
        self.assertEqual((m['status'], m['payment_method']), ('paid', 'card'))
        self.assertEqual(signed('222').status_code, 200)  # duplicate notification is harmless
        self.assertEqual(len(client.get('/api/moves/' + mid).json['events']), 2)

    def test_frontend_and_health(self):
        c = self.app.test_client()
        html = c.get('/').data.decode()
        for asset in re.findall(r'(?:src|href)="(/[^"]+)"', html):
            r = c.get(asset)
            self.assertEqual(r.status_code, 200, asset)
            r.close()
        self.assertEqual(c.get('/pago/' + 'a' * 32).status_code, 200)
        self.assertTrue(c.get('/healthz').json['ok'])
        self.assertIn("script-src 'self'", c.get('/').headers['Content-Security-Policy'])


if __name__ == '__main__':
    unittest.main()
