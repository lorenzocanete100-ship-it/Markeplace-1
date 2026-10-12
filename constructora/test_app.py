import io
import tempfile
import unittest
from PIL import Image
from app import create_app, estimate


class ConstructoraTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.app = create_app({'TESTING': True, 'SECRET_KEY': 'test-secret-not-for-production-12345678',
                               'DATA_DIR': self.temp.name, 'ADMIN_PASSWORD': 'admin-pass', 'WHATSAPP_NUMBER': '5491122223333'})
        self.client = self.app.test_client()
        self.csrf = self.client.get('/api/config').json['csrf']

    def tearDown(self):
        self.temp.cleanup()

    def photo(self):
        out = io.BytesIO()
        Image.new('RGB', (40, 30), 'gray').save(out, 'PNG')
        return (io.BytesIO(out.getvalue()), 'obra.png')

    def send(self, **extra):
        data = {'service': 'llave_en_mano', 'mode': 'con_materiales', 'm2': '100', 'name': 'Juan Pérez',
                'phone': '+54 9 11 4444-5555', 'location': 'Ramos Mejía', 'notes': ''}
        data.update(extra)
        return self.client.post('/api/quotes', data=data, headers={'X-CSRF-Token': self.csrf})

    def test_estimate_prices(self):
        self.assertEqual(estimate('llave_en_mano', 100, 'con_materiales'), 98000)
        self.assertEqual(estimate('llave_en_mano', 100, 'mano_obra'), 40000)
        self.assertEqual(estimate('refaccion', 25, 'mano_obra'), 10000)
        self.assertIsNone(estimate('humedad', 10, 'con_materiales'))
        with self.assertRaises(ValueError):
            estimate('llave_en_mano', 0, 'mano_obra')

    def test_quote_with_photo_and_admin(self):
        r = self.send(service='refaccion', mode='mano_obra', m2='30', photos=self.photo())
        self.assertEqual(r.status_code, 201, r.json)
        self.assertEqual(r.json['estimate'], 12000)
        self.assertEqual(r.json['photos'], 1)
        self.assertTrue(r.json['whatsapp'].startswith('https://wa.me/5491122223333?'))
        self.assertEqual(self.client.get('/admin').status_code, 401)
        auth = {'Authorization': 'Basic ' + __import__('base64').b64encode(b'admin:admin-pass').decode()}
        items = self.client.get('/admin/api/quotes', headers=auth).json['items']
        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]['phone'], '5491144445555')
        key = items[0]['photos'][0]
        self.assertEqual(self.client.get('/admin/photos/' + key, headers=auth).status_code, 200)
        self.assertEqual(self.client.get('/admin/photos/' + key).status_code, 401)

    def test_humedad_without_photos_points_to_whatsapp(self):
        r = self.send(service='humedad', m2='12')
        self.assertEqual(r.status_code, 201, r.json)
        self.assertIsNone(r.json['estimate'])
        self.assertIn('fotos', r.json['whatsapp'])

    def test_validation_and_csrf(self):
        self.assertEqual(self.send(m2='0').status_code, 400)
        self.assertEqual(self.send(service='otro').status_code, 400)
        self.assertEqual(self.send(phone='12').status_code, 400)
        self.assertEqual(self.send(photos=(io.BytesIO(b'no es imagen'), 'x.png')).status_code, 400)
        r = self.client.post('/api/quotes', data={'service': 'llave_en_mano'})
        self.assertEqual(r.status_code, 403)

    def test_pages(self):
        self.assertIn(b'escribano', self.client.get('/').data)
        self.assertEqual(self.client.get('/healthz').json, {'ok': True})


if __name__ == '__main__':
    unittest.main()
