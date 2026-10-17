"""Konexa Constructora: web con cotizador automático. Run with Gunicorn: gunicorn 'app:create_app()'."""
import io
import json
import os
import re
import secrets
import sqlite3
import time
import uuid
from pathlib import Path
from urllib.parse import urlencode

from flask import Flask, Response, g, jsonify, request, send_from_directory, session
from PIL import Image, ImageOps, UnidentifiedImageError
from werkzeug.exceptions import HTTPException
from werkzeug.middleware.proxy_fix import ProxyFix

# Precios en dólares por m2.
PRICES = {'mano_obra': 400, 'con_materiales': 980}
SERVICES = {
    'llave_en_mano': 'Construcción llave en mano',
    'refaccion': 'Refacción',
    'humedad': 'Tratamiento de humedad',
}
# Servicios que se evalúan con fotos antes de dar el precio final.
PHOTO_SERVICES = ('refaccion', 'humedad')
MAX_PHOTOS = 6
MAX_WORKS = 60
Image.MAX_IMAGE_PIXELS = 20000000


def estimate(service, m2, mode):
    """Presupuesto estimado en USD, o None si el servicio requiere evaluar fotos."""
    if service not in SERVICES or mode not in PRICES:
        raise ValueError('servicio o modalidad no válidos')
    if not 1 <= m2 <= 100000:
        raise ValueError('m2 fuera de rango')
    if service == 'humedad':
        return None
    return round(m2 * PRICES[mode], 2)


def create_app(test_config=None):
    app = Flask(__name__, static_folder='static', static_url_path='/static')
    app.config.update(
        SECRET_KEY=os.environ.get('SECRET_KEY'),
        DATA_DIR=os.environ.get('DATA_DIR', str(Path(__file__).parent / 'data')),
        WHATSAPP_NUMBER=re.sub(r'\D', '', os.environ.get('WHATSAPP_NUMBER', '5491100000000')),
        ADMIN_PASSWORD=os.environ.get('ADMIN_PASSWORD', ''),
        SESSION_COOKIE_HTTPONLY=True, SESSION_COOKIE_SAMESITE='Lax',
        SESSION_COOKIE_SECURE=os.environ.get('APP_ENV') == 'production',
        MAX_CONTENT_LENGTH=40 * 1024 * 1024, MAX_FORM_PARTS=30,
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
        con = sqlite3.connect(data / 'constructora.db', timeout=20)
        con.row_factory = sqlite3.Row
        con.execute('PRAGMA busy_timeout=20000')
        return con

    con = connect()
    with con:
        con.execute('PRAGMA journal_mode=WAL')
        con.executescript('''
        CREATE TABLE IF NOT EXISTS quotes (
          id TEXT PRIMARY KEY, service TEXT NOT NULL, mode TEXT NOT NULL,
          m2 REAL NOT NULL, estimate REAL, name TEXT NOT NULL, phone TEXT NOT NULL,
          location TEXT NOT NULL, notes TEXT NOT NULL, photos TEXT NOT NULL,
          created INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS works (
          id TEXT PRIMARY KEY, photo TEXT NOT NULL, caption TEXT NOT NULL, created INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires INTEGER NOT NULL);
        ''')
    con.close()

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

    def limit(key, maximum, window):
        now = int(time.time())
        conn = db()
        with conn:
            conn.execute('DELETE FROM limits WHERE expires < ?', (now,))
            conn.execute('INSERT INTO limits(key,hits,expires) VALUES (?,1,?) ON CONFLICT(key) DO UPDATE SET hits=hits+1', (key, now + window))
            row = conn.execute('SELECT hits FROM limits WHERE key=?', (key,)).fetchone()
        return row['hits'] <= maximum

    def clean_photo(file):
        """Valida una foto subida y la devuelve como JPEG sin metadatos; ValueError con el motivo si no sirve."""
        raw = file.read(8000001)
        if len(raw) > 8000000:
            raise ValueError('Cada foto debe pesar como máximo 8 MB.')
        try:
            with Image.open(io.BytesIO(raw)) as img:
                fmt = img.format
                img.load()
                clean = ImageOps.exif_transpose(img).convert('RGB')
        except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError, Image.DecompressionBombWarning):
            raise ValueError('Una de las imágenes no es válida o es demasiado grande.')
        if fmt not in ('JPEG', 'PNG', 'WEBP', 'MPO'):
            raise ValueError('Usá fotos JPG, PNG o WebP.')
        clean.thumbnail((1800, 1800))
        output = io.BytesIO()
        clean.save(output, 'JPEG', quality=85)
        return output.getvalue()

    def whatsapp(text):
        return 'https://wa.me/' + app.config['WHATSAPP_NUMBER'] + '?' + urlencode({'text': text})

    def admin_ok():
        auth = request.authorization
        password = app.config['ADMIN_PASSWORD']
        return bool(password and auth and auth.password and secrets.compare_digest(auth.password, password))

    @app.before_request
    def protect():
        if request.method in ('POST', 'DELETE') and request.path.startswith(('/api/', '/admin/')):
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
        if request.path.startswith('/api/') or request.path.startswith('/admin'):
            response.headers['Cache-Control'] = 'no-store'
        return response

    @app.errorhandler(Exception)
    def error_handler(error):
        if isinstance(error, HTTPException):
            return fail('Las fotos son demasiado pesadas.' if error.code == 413 else 'Solicitud no válida.', error.code)
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

    @app.get('/api/config')
    def config():
        if 'csrf' not in session:
            session['csrf'] = secrets.token_urlsafe(32)
        return jsonify(csrf=session['csrf'], prices=PRICES, services=SERVICES,
                       photoServices=list(PHOTO_SERVICES), maxPhotos=MAX_PHOTOS,
                       whatsapp=whatsapp('Hola Konexa Constructora, quiero pedir un presupuesto.'))

    @app.post('/api/quotes')
    def create_quote():
        if not limit('quote:' + (request.remote_addr or '?'), 15, 3600):
            return fail('Recibimos muchas solicitudes. Escribinos por WhatsApp.', 429)
        f = request.form
        values = {k: f.get(k, '').strip() for k in ('service', 'mode', 'name', 'phone', 'location', 'notes')}
        values['phone'] = re.sub(r'\D', '', values['phone'])
        try:
            m2 = float(f.get('m2', ''))
            total = estimate(values['service'], m2, values['mode'])
        except ValueError:
            return fail('Revisá el servicio y los metros cuadrados (1 a 100.000 m²).')
        if not 2 <= len(values['name']) <= 80 or not re.fullmatch(r'\d{8,15}', values['phone']) or not 2 <= len(values['location']) <= 120 or len(values['notes']) > 2000:
            return fail('Completá nombre, teléfono (con código de área) y ubicación de la obra.')
        files = [f for f in request.files.getlist('photos') if f.filename]
        if len(files) > MAX_PHOTOS:
            return fail(f'Podés subir hasta {MAX_PHOTOS} fotos.')
        try:
            processed = [clean_photo(file) for file in files]
        except ValueError as e:
            return fail(str(e))
        keys = []
        try:
            for content in processed:
                key = uuid.uuid4().hex + '.jpg'
                (uploads / key).write_bytes(content)
                keys.append(key)
            qid = uuid.uuid4().hex
            with db() as con:
                con.execute('INSERT INTO quotes VALUES (?,?,?,?,?,?,?,?,?,?,?)', (qid, values['service'], values['mode'], m2, total, values['name'], values['phone'], values['location'], values['notes'], json.dumps(keys), int(time.time())))
        except Exception:
            for key in keys:
                (uploads / key).unlink(missing_ok=True)
            raise
        ref = qid[:8].upper()
        price = f'USD {total:,.0f}'.replace(',', '.') if total is not None else 'a evaluar con fotos'
        text = (f'Hola Konexa Constructora, envié la solicitud #{ref}.\n'
                f'Servicio: {SERVICES[values["service"]]}\nSuperficie: {m2:g} m²\n'
                f'Estimado: {price}\nObra en: {values["location"]}')
        if values['service'] in PHOTO_SERVICES and not keys:
            text += '\nLes paso las fotos por acá.'
        return jsonify(id=qid, ref=ref, estimate=total, photos=len(keys), whatsapp=whatsapp(text)), 201

    @app.get('/api/works')
    def works():
        rows = db().execute('SELECT id,photo,caption FROM works ORDER BY created DESC').fetchall()
        return jsonify(items=[dict(r) for r in rows])

    @app.get('/api/works/photos/<key>')
    def work_photo(key):
        if not re.fullmatch(r'[a-f0-9]{32}\.jpg', key) or not db().execute('SELECT 1 FROM works WHERE photo=?', (key,)).fetchone():
            return fail('Foto no encontrada.', 404)
        return send_from_directory(uploads, key, mimetype='image/jpeg', max_age=86400)

    @app.post('/admin/api/works')
    def add_work():
        if not admin_ok():
            return fail('Acceso restringido.', 401)
        caption = request.form.get('caption', '').strip()
        file = request.files.get('photo')
        if not file or not file.filename or len(caption) > 120:
            return fail('Elegí una foto y escribí un título de hasta 120 caracteres.')
        if db().execute('SELECT COUNT(*) FROM works').fetchone()[0] >= MAX_WORKS:
            return fail(f'La galería admite hasta {MAX_WORKS} fotos. Borrá alguna para subir otra.')
        try:
            content = clean_photo(file)
        except ValueError as e:
            return fail(str(e))
        key = uuid.uuid4().hex + '.jpg'
        (uploads / key).write_bytes(content)
        wid = uuid.uuid4().hex
        try:
            with db() as con:
                con.execute('INSERT INTO works VALUES (?,?,?,?)', (wid, key, caption, int(time.time())))
        except Exception:
            (uploads / key).unlink(missing_ok=True)
            raise
        return jsonify(id=wid, photo=key, caption=caption), 201

    @app.delete('/admin/api/works/<wid>')
    def delete_work(wid):
        if not admin_ok():
            return fail('Acceso restringido.', 401)
        row = db().execute('SELECT photo FROM works WHERE id=?', (wid,)).fetchone()
        if not row:
            return fail('Foto no encontrada.', 404)
        with db() as con:
            con.execute('DELETE FROM works WHERE id=?', (wid,))
        (uploads / row['photo']).unlink(missing_ok=True)
        return jsonify(ok=True)

    @app.get('/admin')
    def admin():
        if not admin_ok():
            return Response('Acceso restringido.', 401, {'WWW-Authenticate': 'Basic realm="Konexa admin"'})
        return send_from_directory(app.static_folder, 'admin.html')

    @app.get('/admin/api/quotes')
    def admin_quotes():
        if not admin_ok():
            return fail('Acceso restringido.', 401)
        rows = db().execute('SELECT * FROM quotes ORDER BY created DESC LIMIT 500').fetchall()
        items = []
        for row in rows:
            item = dict(row)
            item['photos'] = json.loads(item['photos'])
            item['serviceName'] = SERVICES.get(item['service'], item['service'])
            items.append(item)
        return jsonify(items=items)

    @app.get('/admin/photos/<key>')
    def photo(key):
        if not admin_ok():
            return fail('Acceso restringido.', 401)
        if not re.fullmatch(r'[a-f0-9]{32}\.jpg', key):
            return fail('Foto no encontrada.', 404)
        return send_from_directory(uploads, key, mimetype='image/jpeg')

    return app


if __name__ == '__main__':
    create_app().run(host='127.0.0.1', port=int(os.environ.get('PORT', '5000')))
