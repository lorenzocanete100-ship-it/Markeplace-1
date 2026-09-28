"""Plaza Cerca: standalone marketplace. Run with Gunicorn; no ChatGPT services."""
import io
import json
import math
import os
import re
import secrets
import sqlite3
import time
import uuid
from datetime import timedelta
from pathlib import Path
from urllib.parse import urlencode

from flask import Flask, g, jsonify, request, session, send_from_directory
from PIL import Image, ImageOps, UnidentifiedImageError
from werkzeug.exceptions import HTTPException
from werkzeug.middleware.proxy_fix import ProxyFix
from werkzeug.security import check_password_hash, generate_password_hash

CATEGORIES = ['Hogar y muebles', 'Tecnología', 'Herramientas', 'Vehículos', 'Ropa y accesorios', 'Deportes', 'Otros']
Image.MAX_IMAGE_PIXELS = 20000000


def create_app(test_config=None):
    app = Flask(__name__, static_folder='static', static_url_path='/static')
    app.config.update(
        SECRET_KEY=os.environ.get('SECRET_KEY'),
        DATA_DIR=os.environ.get('DATA_DIR', str(Path(__file__).parent / 'data')),
        SESSION_COOKIE_HTTPONLY=True, SESSION_COOKIE_SAMESITE='Lax',
        SESSION_COOKIE_SECURE=os.environ.get('APP_ENV') == 'production',
        PERMANENT_SESSION_LIFETIME=timedelta(days=7),
        MAX_CONTENT_LENGTH=16 * 1024 * 1024, MAX_FORM_PARTS=30,
        ADMIN_EMAIL=os.environ.get('ADMIN_EMAIL', '').strip().lower(),
    )
    if test_config:
        app.config.update(test_config)
    if not app.config['SECRET_KEY'] or len(app.config['SECRET_KEY']) < 32:
        raise RuntimeError('Configurá SECRET_KEY con al menos 32 caracteres aleatorios.')
    if os.environ.get('RENDER') == 'true':
        app.wsgi_app = ProxyFix(app.wsgi_app, x_for=1, x_proto=1)
    data = Path(app.config['DATA_DIR'])
    data.mkdir(parents=True, exist_ok=True)
    uploads = data / 'uploads'
    uploads.mkdir(exist_ok=True)

    def connect():
        con = sqlite3.connect(data / 'market.db', timeout=20)
        con.row_factory = sqlite3.Row
        con.execute('PRAGMA foreign_keys=ON')
        con.execute('PRAGMA busy_timeout=20000')
        return con

    with connect() as con:
        con.execute('PRAGMA journal_mode=WAL')
        con.executescript('''
        CREATE TABLE IF NOT EXISTS users (
          id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE,
          name TEXT NOT NULL, password TEXT NOT NULL, created INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS listings (
          id TEXT PRIMARY KEY, owner TEXT NOT NULL REFERENCES users(id),
          title TEXT NOT NULL, description TEXT NOT NULL, price INTEGER NOT NULL,
          category TEXT NOT NULL, condition TEXT NOT NULL, location TEXT NOT NULL,
          seller TEXT NOT NULL, phone TEXT NOT NULL, photos TEXT NOT NULL,
          status TEXT NOT NULL DEFAULT 'active', created INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS idx_listings_owner ON listings(owner);
        CREATE INDEX IF NOT EXISTS idx_listings_status_created ON listings(status, created);
        CREATE TABLE IF NOT EXISTS limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires INTEGER NOT NULL);
        ''')

    def db():
        if 'db' not in g:
            g.db = connect()
        return g.db

    @app.teardown_appcontext
    def close_db(error=None):
        if 'db' in g:
            g.db.close()

    def fail(message, status=400):
        return jsonify(error=message), status

    def user():
        uid = session.get('uid')
        return db().execute('SELECT id,email,name FROM users WHERE id=?', (uid,)).fetchone() if uid else None

    def is_admin(u):
        return bool(u and app.config['ADMIN_EMAIL'] and u['email'] == app.config['ADMIN_EMAIL'])

    def limit(key, maximum, window):
        now = int(time.time())
        conn = db()
        with conn:
            conn.execute('DELETE FROM limits WHERE expires < ?', (now,))
            conn.execute('INSERT INTO limits(key,hits,expires) VALUES (?,1,?) ON CONFLICT(key) DO UPDATE SET hits=hits+1', (key, now + window))
            row = conn.execute('SELECT hits FROM limits WHERE key=?', (key,)).fetchone()
        return row['hits'] <= maximum

    @app.before_request
    def protect():
        if request.method in ('POST', 'PATCH', 'DELETE', 'PUT') and request.path.startswith('/api/'):
            token = session.get('csrf')
            supplied = request.headers.get('X-CSRF-Token', '')
            if not token or not secrets.compare_digest(token, supplied):
                return fail('La sesión venció. Recargá la página e intentá nuevamente.', 403)

    @app.after_request
    def headers(response):
        response.headers['X-Content-Type-Options'] = 'nosniff'
        response.headers['X-Frame-Options'] = 'DENY'
        response.headers['Referrer-Policy'] = 'strict-origin-when-cross-origin'
        response.headers['Content-Security-Policy'] = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' blob: data:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
        if request.path.startswith('/api/'):
            response.headers['Cache-Control'] = 'no-store'
        return response

    @app.errorhandler(Exception)
    def error_handler(error):
        if isinstance(error, HTTPException):
            return fail('Archivo demasiado grande.' if error.code == 413 else 'Solicitud no válida.', error.code)
        app.logger.exception('Request failed')
        return fail('No se pudo completar la operación. Intentá nuevamente.', 503)

    @app.get('/healthz')
    def health():
        db().execute('SELECT 1').fetchone()
        return jsonify(ok=True)

    @app.get('/')
    def index():
        return send_from_directory(app.static_folder, 'index.html')

    @app.get('/favicon.svg')
    def favicon():
        return send_from_directory(app.static_folder, 'favicon.svg')

    @app.get('/api/session')
    def current_session():
        if 'csrf' not in session:
            session['csrf'] = secrets.token_urlsafe(32)
        u = user()
        return jsonify(csrf=session['csrf'], user=({**dict(u), 'admin': is_admin(u)} if u else None))

    @app.post('/api/auth/<action>')
    def auth(action):
        if action == 'logout':
            session.clear()
            session['csrf'] = secrets.token_urlsafe(32)
            return jsonify(ok=True, csrf=session['csrf'])
        if action not in ('login', 'register'):
            return fail('Acción no válida.', 404)
        if not limit('auth:' + (request.remote_addr or '?'), 25, 900):
            return fail('Demasiados intentos. Esperá 15 minutos.', 429)
        payload = request.get_json(silent=True) or {}
        email = str(payload.get('email', '')).strip().lower()
        password = str(payload.get('password', ''))
        if not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+', email) or len(email) > 254 or not 10 <= len(password) <= 128:
            return fail('Revisá el correo y usá una contraseña de 10 a 128 caracteres.')
        if action == 'register':
            name = str(payload.get('name', '')).strip()
            if not 2 <= len(name) <= 80:
                return fail('Ingresá tu nombre (2 a 80 caracteres).')
            # The administrator must be created from the hosting console, never by public registration.
            if email == app.config['ADMIN_EMAIL']:
                return fail('Esta dirección requiere alta desde la administración.', 403)
            uid = uuid.uuid4().hex
            hashed = generate_password_hash(password, method='scrypt')
            try:
                with db() as con:
                    con.execute('INSERT INTO users VALUES (?,?,?,?,?)', (uid,email,name,hashed,int(time.time())))
            except sqlite3.IntegrityError:
                return fail('No se pudo crear la cuenta con ese correo.', 409)
        else:
            row = db().execute('SELECT * FROM users WHERE email=?', (email,)).fetchone()
            if not row or not check_password_hash(row['password'], password):
                return fail('Correo o contraseña incorrectos.', 401)
            uid = row['id']
        session.clear()
        session.permanent = True
        session['uid'] = uid
        session['csrf'] = secrets.token_urlsafe(32)
        u = user()
        return jsonify(csrf=session['csrf'], user={**dict(u), 'admin': is_admin(u)})

    @app.get('/api/listings')
    def list_items():
        u = user()
        mine = request.args.get('mine') == '1'
        if mine and not u:
            return fail('Iniciá sesión.', 401)
        rows = db().execute("SELECT * FROM listings WHERE owner=? AND status!='deleted' ORDER BY created DESC LIMIT 300" if mine else "SELECT * FROM listings WHERE status='active' ORDER BY created DESC LIMIT 300", (u['id'],) if mine else ()).fetchall()
        result = []
        for row in rows:
            item = dict(row)
            item['mine'] = bool(u and item['owner'] == u['id'])
            item['canDelete'] = item['mine'] or is_admin(u)
            item.pop('owner'); item.pop('phone')
            item['photos'] = json.loads(item['photos'])
            result.append(item)
        return jsonify(items=result)

    @app.post('/api/listings')
    def publish():
        u = user()
        if not u:
            return fail('Iniciá sesión para publicar.', 401)
        if not limit('publish:' + u['id'], 20, 3600):
            return fail('Llegaste al límite de publicaciones por hora.', 429)
        f = request.form
        values = {k: f.get(k, '').strip() for k in ('title','description','category','condition','location','seller','phone')}
        values['phone'] = re.sub(r'\D', '', values['phone'])
        try:
            price = float(f.get('price', ''))
        except ValueError:
            return fail('Ingresá un precio válido.')
        if not math.isfinite(price) or not 0 <= price <= 1000000000:
            return fail('Ingresá un precio válido.')
        if not 3 <= len(values['title']) <= 100 or not 10 <= len(values['description']) <= 2500 or not 1 <= len(values['location']) <= 100 or not 1 <= len(values['seller']) <= 80 or not re.fullmatch(r'\d{10,15}', values['phone']) or values['category'] not in CATEGORIES or values['condition'] not in ('Nuevo','Usado'):
            return fail('Revisá los datos de la publicación.')
        files = request.files.getlist('photos')
        if not 1 <= len(files) <= 3:
            return fail('Agregá de 1 a 3 fotos.')
        processed = []
        try:
            for f in files:
                raw = f.read(5000001)
                if len(raw) > 5000000:
                    return fail('Cada foto debe pesar como máximo 5 MB.')
                with Image.open(io.BytesIO(raw)) as img:
                    if img.format not in ('JPEG','PNG','WEBP'):
                        return fail('Usá fotos JPG, PNG o WebP.')
                    img.load()
                    clean = ImageOps.exif_transpose(img).convert('RGB')
                    clean.thumbnail((1800,1800))
                    output = io.BytesIO()
                    clean.save(output, 'JPEG', quality=85)
                    processed.append(output.getvalue())
        except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError, Image.DecompressionBombWarning):
            return fail('Una de las imágenes no es válida o es demasiado grande.')
        keys = []
        try:
            for content in processed:
                key = uuid.uuid4().hex + '.jpg'
                (uploads / key).write_bytes(content)
                keys.append(key)
            lid = uuid.uuid4().hex
            with db() as con:
                con.execute('INSERT INTO listings VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', (lid,u['id'],values['title'],values['description'],round(price*100),values['category'],values['condition'],values['location'],values['seller'],values['phone'],json.dumps(keys),'active',int(time.time())))
        except Exception:
            for key in keys:
                (uploads / key).unlink(missing_ok=True)
            raise
        return jsonify(id=lid), 201

    @app.get('/api/photos/<key>')
    def photo(key):
        if not re.fullmatch(r'[a-f0-9]{32}\.jpg', key):
            return fail('Foto no encontrada.',404)
        return send_from_directory(uploads, key, mimetype='image/jpeg', max_age=86400)

    @app.route('/api/listings/<lid>', methods=['GET','PATCH','DELETE'])
    def item(lid):
        row = db().execute('SELECT * FROM listings WHERE id=? AND status!=?', (lid,'deleted')).fetchone()
        if not row:
            return fail('Publicación no encontrada.',404)
        if request.method == 'GET':
            if row['status'] != 'active':
                return fail('Este producto ya no está disponible.',404)
            return jsonify(url='https://wa.me/' + row['phone'] + '?' + urlencode({'text':'Hola, me interesa ' + row['title'] + '. ¿Sigue disponible?'}))
        u = user()
        if not u:
            return fail('Iniciá sesión.',401)
        if row['owner'] != u['id'] and not (request.method == 'DELETE' and is_admin(u)):
            return fail('No podés modificar esta publicación.',403)
        if request.method == 'DELETE':
            status = 'deleted'
        else:
            status = (request.get_json(silent=True) or {}).get('status')
            if status not in ('active','sold'):
                return fail('Estado no válido.')
        with db() as con:
            con.execute('UPDATE listings SET status=? WHERE id=?', (status,lid))
        if status == 'deleted':
            for key in json.loads(row['photos']):
                (uploads / key).unlink(missing_ok=True)
        return jsonify(ok=True)

    return app


if __name__ == '__main__':
    create_app().run(host='127.0.0.1', port=int(os.environ.get('PORT', '5000')))
