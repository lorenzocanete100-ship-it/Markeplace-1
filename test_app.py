import io
import os
import re
import tempfile
import unittest
from pathlib import Path
from PIL import Image
from app import create_app


class MarketplaceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.config = {'TESTING':True,'SECRET_KEY':'test-secret-not-for-production-12345678','DATA_DIR':self.temp.name,'ADMIN_EMAIL':'owner@example.com'}
        self.app = create_app(self.config)
        self.client = self.app.test_client()
        self.csrf = self.client.get('/api/session').json['csrf']

    def tearDown(self):
        self.temp.cleanup()

    def register(self, client=None, email='seller@example.com'):
        client = client or self.client
        token = client.get('/api/session').json['csrf']
        r = client.post('/api/auth/register',json={'email':email,'password':'a-test-password-123','name':'Vendedor'},headers={'X-CSRF-Token':token})
        self.assertEqual(r.status_code,200,r.json)
        if client is self.client:
            self.csrf=r.json['csrf']
        return r.json['csrf']

    def publish(self, fake=False):
        output=io.BytesIO()
        Image.new('RGB',(40,30),'blue').save(output,'PNG')
        data={'title':'Mesa de madera','description':'Una mesa en buen estado.','price':'25000','category':'Hogar y muebles','condition':'Usado','location':'González Catán','seller':'Vendedor','phone':'5491100000000','photos':(io.BytesIO(b'not an image' if fake else output.getvalue()),'foto.png')}
        return self.client.post('/api/listings',data=data,headers={'X-CSRF-Token':self.csrf})

    def test_complete_marketplace_and_restart(self):
        self.register()
        r=self.publish(); self.assertEqual(r.status_code,201,r.json)
        lid=r.json['id']
        items=self.client.get('/api/listings').json['items']
        self.assertEqual(items[0]['price'],2500000)
        self.assertNotIn('phone',items[0])
        photo=items[0]['photos'][0]
        self.assertEqual(self.client.get('/api/photos/'+photo).status_code,200)
        self.assertTrue(self.client.get('/api/listings/'+lid).json['url'].startswith('https://wa.me/54911'))
        restarted=create_app(self.config).test_client()
        self.assertEqual(len(restarted.get('/api/listings').json['items']),1)
        self.assertEqual(restarted.get('/api/photos/'+photo).status_code,200)
        r=self.client.patch('/api/listings/'+lid,json={'status':'sold'},headers={'X-CSRF-Token':self.csrf})
        self.assertEqual(r.status_code,200)
        self.assertEqual(self.client.get('/api/listings').json['items'],[])
        self.assertEqual(len(self.client.get('/api/listings?mine=1').json['items']),1)
        self.assertEqual(self.client.get('/api/listings/'+lid).status_code,404)
        r=self.client.patch('/api/listings/'+lid,json={'status':'active'},headers={'X-CSRF-Token':self.csrf})
        self.assertEqual(r.status_code,200)
        self.assertEqual(self.client.delete('/api/listings/'+lid,headers={'X-CSRF-Token':self.csrf}).status_code,200)
        self.assertEqual(self.client.get('/api/photos/'+photo).status_code,404)

    def test_ownership(self):
        self.register(); lid=self.publish().json['id']
        other=self.app.test_client(); token=self.register(other,'other@example.com')
        for method in ['patch','delete']:
            r=getattr(other,method)('/api/listings/'+lid,json={'status':'sold'},headers={'X-CSRF-Token':token})
            self.assertEqual(r.status_code,403)
        self.assertEqual(self.client.get('/api/listings').json['items'][0]['status'],'active')

    def test_csrf_and_anonymous_writes(self):
        self.assertEqual(self.client.post('/api/auth/register',json={}).status_code,403)
        self.assertEqual(self.publish().status_code,401)
        self.assertEqual(self.client.get('/api/listings?mine=1').status_code,401)
        self.register()
        self.assertEqual(self.client.post('/api/listings').status_code,403)

    def test_fake_image_rejected(self):
        self.register()
        self.assertEqual(self.publish(fake=True).status_code,400)
        self.assertEqual(list((Path(self.temp.name)/'uploads').iterdir()),[])

    def test_login_logout_and_reserved_admin(self):
        reserved=self.client.post('/api/auth/register',json={'email':'owner@example.com','name':'Fake','password':'a-test-password-123'},headers={'X-CSRF-Token':self.csrf})
        self.assertEqual(reserved.status_code,403)
        self.register()
        r=self.client.post('/api/auth/logout',headers={'X-CSRF-Token':self.csrf})
        self.csrf=r.json['csrf']
        self.assertIsNone(self.client.get('/api/session').json['user'])
        body={'email':'seller@example.com','password':'wrong-password-123'}
        self.assertEqual(self.client.post('/api/auth/login',json=body,headers={'X-CSRF-Token':self.csrf}).status_code,401)
        body['password']='a-test-password-123'
        self.assertEqual(self.client.post('/api/auth/login',json=body,headers={'X-CSRF-Token':self.csrf}).status_code,200)

    def test_built_frontend_and_health(self):
        response=self.client.get('/')
        self.assertEqual(response.status_code,200)
        html=response.data.decode()
        assets=re.findall(r'(?:src|href)="([^"]+)"',html)
        for asset in assets:
            self.assertEqual(self.client.get(asset).status_code,200,asset)
        self.assertTrue(self.client.get('/healthz').json['ok'])
        self.assertEqual(self.client.get('/favicon.svg').status_code,200)


if __name__=='__main__':
    unittest.main()
