"""Fletá: fletes y envíos entre particulares, en AMBA y a todo el país.

Run with Gunicorn: gunicorn 'app:create_app()'
"""
import hashlib
import hmac
import io
import json
import math
import os
import re
import secrets
import sqlite3
import time
import urllib.error
import urllib.request
import uuid
from contextlib import closing
from datetime import date, timedelta
from pathlib import Path

from flask import Flask, g, jsonify, request, send_from_directory, session
from PIL import Image, ImageOps, UnidentifiedImageError
from werkzeug.exceptions import HTTPException
from werkzeug.middleware.proxy_fix import ProxyFix
from werkzeug.security import check_password_hash, generate_password_hash

from cities import CITIES

Image.MAX_IMAGE_PIXELS = 20000000

# rank: a driver with a vehicle of rank N can take jobs that need rank <= N.
VEHICLES = {
    'moto': {'label': 'Moto (sobres y cajas chicas)', 'rank': 0, 'base': 6000, 'km': 300, 'max_kg': 15},
    'auto': {'label': 'Auto', 'rank': 1, 'base': 12000, 'km': 550, 'max_kg': 150},
    'utilitario': {'label': 'Utilitario (tipo Kangoo)', 'rank': 2, 'base': 22000, 'km': 950, 'max_kg': 600},
    'camioneta': {'label': 'Camioneta / furgón', 'rank': 3, 'base': 38000, 'km': 1400, 'max_kg': 1500},
    'camion': {'label': 'Camión de mudanza', 'rank': 4, 'base': 75000, 'km': 2300, 'max_kg': 4000},
}
SERVICES = {'flete': 'Flete exclusivo', 'bultos': 'Envío de bultos'}
SLOTS = {'am': 'Mañana (8 a 13 h)', 'pm': 'Tarde (13 a 19 h)'}
HELPER_PRICE = 18000
MOTO_MAX_KM = 80
BULTOS_MAX_KG = 300
ROAD_FACTOR = 1.3
DRIVER_DOCS = {
    'dni_front': 'DNI (frente)', 'dni_back': 'DNI (dorso)', 'selfie': 'Selfie sosteniendo el DNI',
    'license': 'Licencia de conducir', 'vehicle_card': 'Cédula del vehículo',
    'insurance': 'Póliza de seguro vigente', 'background': 'Certificado de antecedentes penales',
}
PDF_DOCS = {'insurance', 'background'}
STATUS = {
    'pending_payment': 'Pendiente de pago', 'paid': 'Pagado · buscando chofer', 'assigned': 'Chofer asignado',
    'in_transit': 'En viaje', 'delivered': 'Entregado', 'cancelled': 'Cancelado',
}
ACTIVE_TRIPS_MAX = 3
CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'


def distance_km(a, b):
    """Great-circle distance times a road factor. Replace with a routing API for exact km."""
    lat1, lon1, lat2, lon2 = map(math.radians, (a['lat'], a['lon'], b['lat'], b['lon']))
    h = math.sin((lat2 - lat1) / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin((lon2 - lon1) / 2) ** 2
    return max(3, round(2 * 6371 * math.asin(math.sqrt(h)) * ROAD_FACTOR))


def min_vehicle_for(kg):
    return next(k for k, v in VEHICLES.items() if v['max_kg'] >= kg and k != 'moto') if kg <= VEHICLES['camion']['max_kg'] else None


def valid_cuil(value):
    if not re.fullmatch(r'(20|23|24|27|30|33|34)\d{9}', value):
        return False
    total = sum(int(d) * w for d, w in zip(value[:10], (5, 4, 3, 2, 7, 6, 5, 4, 3, 2)))
    check = 11 - total % 11
    check = 0 if check == 11 else check
    return check != 10 and check == int(value[10])


def valid_plate(value):
    return bool(re.fullmatch(r'[A-Z]{3}\d{3}|[A-Z]{2}\d{3}[A-Z]{2}|A?\d{3}[A-Z]{3}', value))


def parse_date(value):
    try:
        return date.fromisoformat(str(value))
    except ValueError:
        return None


def create_app(test_config=None):
    app = Flask(__name__, static_folder='static', static_url_path='/static')
    app.config.update(
        SECRET_KEY=os.environ.get('SECRET_KEY'),
        DATA_DIR=os.environ.get('DATA_DIR', str(Path(__file__).parent / 'data')),
        SESSION_COOKIE_HTTPONLY=True, SESSION_COOKIE_SAMESITE='Lax',
        SESSION_COOKIE_SECURE=os.environ.get('APP_ENV') == 'production',
        PERMANENT_SESSION_LIFETIME=timedelta(days=7),
        MAX_CONTENT_LENGTH=40 * 1024 * 1024, MAX_FORM_PARTS=40,
        ADMIN_EMAIL=os.environ.get('ADMIN_EMAIL', '').strip().lower(),
        PUBLIC_URL=os.environ.get('PUBLIC_URL', '').rstrip('/'),
        MP_ACCESS_TOKEN=os.environ.get('MP_ACCESS_TOKEN', ''),
        MP_WEBHOOK_SECRET=os.environ.get('MP_WEBHOOK_SECRET', ''),
        PRICE_FACTOR=float(os.environ.get('PRICE_FACTOR', '1')),
        PLATFORM_FEE_PCT=float(os.environ.get('PLATFORM_FEE_PCT', '15')),
        DEMO_PAYMENTS=os.environ.get('APP_ENV') != 'production',
    )
    if test_config:
        app.config.update(test_config)
    if not app.config['SECRET_KEY'] or len(app.config['SECRET_KEY']) < 32:
        raise RuntimeError('Configurá SECRET_KEY con al menos 32 caracteres aleatorios.')
    if os.environ.get('RENDER') == 'true':
        app.wsgi_app = ProxyFix(app.wsgi_app, x_for=1, x_proto=1)
    data = Path(app.config['DATA_DIR'])
    docs_dir = data / 'docs'
    docs_dir.mkdir(parents=True, exist_ok=True)

    def connect():
        con = sqlite3.connect(data / 'fletes.db', timeout=20)
        con.row_factory = sqlite3.Row
        con.execute('PRAGMA foreign_keys=ON')
        con.execute('PRAGMA busy_timeout=20000')
        return con

    with closing(connect()) as con, con:
        con.execute('PRAGMA journal_mode=WAL')
        con.executescript('''
        CREATE TABLE IF NOT EXISTS users (
          id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
          phone TEXT NOT NULL, password TEXT NOT NULL, created INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS drivers (
          user_id TEXT PRIMARY KEY REFERENCES users(id), status TEXT NOT NULL,
          cuil TEXT NOT NULL, vehicle TEXT NOT NULL, vehicle_model TEXT NOT NULL, plate TEXT NOT NULL,
          base_city TEXT NOT NULL, long_distance INTEGER NOT NULL,
          license_expiry TEXT NOT NULL, insurance_expiry TEXT NOT NULL,
          docs TEXT NOT NULL, review_note TEXT NOT NULL DEFAULT '',
          submitted INTEGER NOT NULL, reviewed INTEGER,
          rating_sum INTEGER NOT NULL DEFAULT 0, rating_count INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS shipments (
          id TEXT PRIMARY KEY, code TEXT NOT NULL UNIQUE, client TEXT NOT NULL REFERENCES users(id),
          driver TEXT REFERENCES users(id), service TEXT NOT NULL, vehicle TEXT NOT NULL,
          origin TEXT NOT NULL, origin_address TEXT NOT NULL, destination TEXT NOT NULL, dest_address TEXT NOT NULL,
          pickup_date TEXT NOT NULL, slot TEXT NOT NULL, kg INTEGER NOT NULL, helpers INTEGER NOT NULL,
          description TEXT NOT NULL, receiver_name TEXT NOT NULL, receiver_phone TEXT NOT NULL,
          declared_value INTEGER NOT NULL, insurance INTEGER NOT NULL,
          distance_km INTEGER NOT NULL, price INTEGER NOT NULL, payout INTEGER NOT NULL,
          pin TEXT NOT NULL, status TEXT NOT NULL, payment_id TEXT, payment_method TEXT,
          refund_status TEXT NOT NULL DEFAULT '', rating INTEGER, review TEXT,
          created INTEGER NOT NULL, updated INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS idx_ship_client ON shipments(client, created);
        CREATE INDEX IF NOT EXISTS idx_ship_driver ON shipments(driver, created);
        CREATE INDEX IF NOT EXISTS idx_ship_status ON shipments(status, pickup_date);
        CREATE TABLE IF NOT EXISTS events (
          shipment TEXT NOT NULL REFERENCES shipments(id), status TEXT NOT NULL, at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS idx_events ON events(shipment, at);
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
        return db().execute('SELECT id,email,name,phone FROM users WHERE id=?', (uid,)).fetchone() if uid else None

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

    def set_status(con, sid, status, extra='', params=()):
        now = int(time.time())
        con.execute(f'UPDATE shipments SET status=?, updated=?{extra} WHERE id=?', (status, now, *params, sid))
        con.execute('INSERT INTO events VALUES (?,?,?)', (sid, status, now))

    # ---------- pricing ----------
    def quote(payload):
        """Return (quote dict, None) or (None, error message)."""
        origin, dest = CITIES.get(str(payload.get('origin'))), CITIES.get(str(payload.get('destination')))
        service = payload.get('service')
        if not origin or not dest:
            return None, 'Elegí origen y destino de la lista.'
        if service not in SERVICES:
            return None, 'Elegí el tipo de servicio.'
        try:
            kg = int(payload.get('kg') or 0)
            helpers = int(payload.get('helpers') or 0)
            declared = int(payload.get('declared_value') or 0)
        except (TypeError, ValueError):
            return None, 'Revisá peso, ayudantes y valor declarado.'
        insurance_on = bool(payload.get('insurance'))
        if not 1 <= kg <= 4000 or not 0 <= helpers <= 3 or not 0 <= declared <= 50000000:
            return None, 'Revisá peso (1 a 4.000 kg), ayudantes (0 a 3) y valor declarado.'
        km = distance_km(origin, dest) if origin is not dest else 5
        if service == 'bultos':
            if kg > BULTOS_MAX_KG:
                return None, f'Para más de {BULTOS_MAX_KG} kg pedí un flete exclusivo.'
            vehicle, helpers = min_vehicle_for(kg), 0
            transport = 7000 + kg * (120 + 0.5 * km)
        else:
            vehicle = payload.get('vehicle')
            if vehicle not in VEHICLES:
                return None, 'Elegí el vehículo.'
            v = VEHICLES[vehicle]
            if kg > v['max_kg']:
                return None, f'{v["label"]}: hasta {v["max_kg"]} kg. Elegí un vehículo más grande.'
            if vehicle == 'moto' and km > MOTO_MAX_KM:
                return None, f'Las motos hacen viajes de hasta {MOTO_MAX_KM} km.'
            # Long trips are cheaper per km: the first 100 km at full rate, the rest at 65 %.
            transport = v['base'] + v['km'] * (min(km, 100) + max(km - 100, 0) * 0.65)
        factor = app.config['PRICE_FACTOR']
        transport = round(transport * factor / 100) * 100
        helpers_cost = round(helpers * HELPER_PRICE * factor / 100) * 100
        insurance = max(1500, round(declared * 0.015)) if insurance_on and declared else 0
        price = transport + helpers_cost + insurance
        payout = round((transport + helpers_cost) * (1 - app.config['PLATFORM_FEE_PCT'] / 100))
        long_distance = not (origin['region'] == dest['region'] == 'AMBA') and km > 60
        return {'origin': origin['id'], 'destination': dest['id'], 'service': service, 'vehicle': vehicle,
                'kg': kg, 'helpers': helpers, 'declared_value': declared, 'insurance': insurance,
                'distance_km': km, 'price': price, 'payout': payout, 'long_distance': long_distance,
                'breakdown': [['Transporte', transport], ['Ayudantes', helpers_cost], ['Seguro de la carga', insurance]]}, None

    # ---------- serialization ----------
    def driver_public(uid):
        row = db().execute('SELECT d.*, u.name, u.phone FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.user_id=?', (uid,)).fetchone()
        if not row:
            return None
        return {'name': row['name'], 'phone': row['phone'], 'vehicle': VEHICLES[row['vehicle']]['label'],
                'vehicle_model': row['vehicle_model'], 'plate': row['plate'], 'verified': row['status'] == 'approved',
                'rating': round(row['rating_sum'] / row['rating_count'], 1) if row['rating_count'] else None,
                'rating_count': row['rating_count']}

    def shipment_json(row, viewer, admin=False):
        s = dict(row)
        is_client = viewer and s['client'] == viewer['id']
        is_driver = viewer and s['driver'] == viewer['id']
        out = {k: s[k] for k in ('id', 'code', 'service', 'vehicle', 'origin', 'destination', 'pickup_date', 'slot', 'kg',
                                 'helpers', 'description', 'distance_km', 'status', 'created')}
        out.update(origin_label=CITIES[s['origin']]['label'], destination_label=CITIES[s['destination']]['label'],
                   status_label=STATUS[s['status']], slot_label=SLOTS[s['slot']], vehicle_label=VEHICLES[s['vehicle']]['label'],
                   service_label=SERVICES[s['service']])
        if is_client or admin:
            out.update(price=s['price'], insurance=s['insurance'], declared_value=s['declared_value'],
                       payment_method=s['payment_method'], refund_status=s['refund_status'],
                       rating=s['rating'], review=s['review'], mine=bool(is_client))
            if s['status'] not in ('delivered', 'cancelled'):
                out['pin'] = s['pin']
        if is_driver or admin:
            out['payout'] = s['payout']
        if is_client or is_driver or admin:
            out.update(origin_address=s['origin_address'], dest_address=s['dest_address'],
                       receiver_name=s['receiver_name'], receiver_phone=s['receiver_phone'])
            out['events'] = [dict(e) for e in db().execute('SELECT status, at FROM events WHERE shipment=? ORDER BY at', (s['id'],))]
        if s['driver'] and (is_client or admin):
            out['driver'] = driver_public(s['driver'])
        if is_driver or admin:
            c = db().execute('SELECT name, phone FROM users WHERE id=?', (s['client'],)).fetchone()
            out['client'] = dict(c)
        return out

    # ---------- middleware ----------
    @app.before_request
    def protect():
        if request.path == '/api/payments/webhook':
            return None
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
        response.headers['Content-Security-Policy'] = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
        if request.path.startswith('/api/'):
            response.headers['Cache-Control'] = 'no-store'
        return response

    @app.errorhandler(Exception)
    def error_handler(error):
        if isinstance(error, HTTPException):
            return fail('Archivo demasiado grande.' if error.code == 413 else 'Solicitud no válida.', error.code)
        app.logger.exception('Request failed')
        return fail('No se pudo completar la operación. Intentá nuevamente.', 503)

    # ---------- pages ----------
    @app.get('/healthz')
    def health():
        db().execute('SELECT 1').fetchone()
        return jsonify(ok=True)

    @app.get('/')
    @app.get('/pago/<sid>')
    def index(sid=None):
        return send_from_directory(app.static_folder, 'index.html')

    @app.get('/favicon.svg')
    def favicon():
        return send_from_directory(app.static_folder, 'favicon.svg')

    # ---------- auth ----------
    @app.get('/api/session')
    def current_session():
        if 'csrf' not in session:
            session['csrf'] = secrets.token_urlsafe(32)
        u = user()
        info = None
        if u:
            d = db().execute('SELECT status FROM drivers WHERE user_id=?', (u['id'],)).fetchone()
            info = {**dict(u), 'admin': is_admin(u), 'driver_status': d['status'] if d else None}
        return jsonify(csrf=session['csrf'], user=info, demo_payments=app.config['DEMO_PAYMENTS'] and not app.config['MP_ACCESS_TOKEN'])

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
            phone = re.sub(r'\D', '', str(payload.get('phone', '')))
            if not 2 <= len(name) <= 80:
                return fail('Ingresá tu nombre y apellido.')
            if not re.fullmatch(r'\d{10,15}', phone):
                return fail('Ingresá un celular válido con código de área (ej. 11 2345 6789).')
            if email == app.config['ADMIN_EMAIL']:
                return fail('Esta dirección requiere alta desde la administración.', 403)
            uid = uuid.uuid4().hex
            try:
                with db() as con:
                    con.execute('INSERT INTO users VALUES (?,?,?,?,?,?)',
                                (uid, email, name, phone, generate_password_hash(password, method='scrypt'), int(time.time())))
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
        return current_session()

    # ---------- catalog & quotes ----------
    @app.get('/api/catalog')
    def catalog():
        return jsonify(
            cities=sorted(({k: c[k] for k in ('id', 'label', 'area', 'region')} for c in CITIES.values()),
                          key=lambda c: (c['region'] != 'AMBA', c['area'], c['label'])),
            vehicles=[{'id': k, 'label': v['label'], 'max_kg': v['max_kg']} for k, v in VEHICLES.items()],
            services=SERVICES, slots=SLOTS, docs=DRIVER_DOCS, helper_price=HELPER_PRICE,
            bultos_max_kg=BULTOS_MAX_KG, moto_max_km=MOTO_MAX_KM)

    @app.post('/api/quote')
    def get_quote():
        if not limit('quote:' + (request.remote_addr or '?'), 120, 600):
            return fail('Demasiadas cotizaciones. Esperá unos minutos.', 429)
        q, error = quote(request.get_json(silent=True) or {})
        return fail(error) if error else jsonify(q)

    # ---------- shipments (client) ----------
    @app.post('/api/shipments')
    def create_shipment():
        u = user()
        if not u:
            return fail('Iniciá sesión para pedir el flete.', 401)
        if not limit('ship:' + u['id'], 20, 3600):
            return fail('Llegaste al límite de pedidos por hora.', 429)
        p = request.get_json(silent=True) or {}
        q, error = quote(p)
        if error:
            return fail(error)
        text = {k: str(p.get(k, '')).strip() for k in ('origin_address', 'dest_address', 'description', 'receiver_name')}
        receiver_phone = re.sub(r'\D', '', str(p.get('receiver_phone', '')))
        pickup = parse_date(p.get('pickup_date'))
        if not pickup or not date.today() <= pickup <= date.today() + timedelta(days=90):
            return fail('Elegí una fecha de retiro entre hoy y los próximos 90 días.')
        if p.get('slot') not in SLOTS:
            return fail('Elegí la franja horaria.')
        if not (5 <= len(text['origin_address']) <= 150 and 5 <= len(text['dest_address']) <= 150):
            return fail('Completá las direcciones de retiro y entrega (calle y número).')
        if not 5 <= len(text['description']) <= 1000:
            return fail('Contanos qué hay que llevar (mínimo 5 caracteres).')
        if not 2 <= len(text['receiver_name']) <= 80 or not re.fullmatch(r'\d{10,15}', receiver_phone):
            return fail('Completá nombre y celular de quien recibe.')
        sid = uuid.uuid4().hex
        now = int(time.time())
        with db() as con:
            for _ in range(5):
                code = 'FL-' + ''.join(secrets.choice(CODE_ALPHABET) for _ in range(6))
                if not con.execute('SELECT 1 FROM shipments WHERE code=?', (code,)).fetchone():
                    break
            con.execute('''INSERT INTO shipments (id,code,client,driver,service,vehicle,origin,origin_address,destination,dest_address,
                pickup_date,slot,kg,helpers,description,receiver_name,receiver_phone,declared_value,insurance,distance_km,price,payout,
                pin,status,created,updated) VALUES (?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)''',
                        (sid, code, u['id'], q['service'], q['vehicle'], q['origin'], text['origin_address'], q['destination'],
                         text['dest_address'], pickup.isoformat(), p['slot'], q['kg'], q['helpers'], text['description'],
                         text['receiver_name'], receiver_phone, q['declared_value'], q['insurance'], q['distance_km'],
                         q['price'], q['payout'], f'{secrets.randbelow(10000):04d}', 'pending_payment', now, now))
            con.execute('INSERT INTO events VALUES (?,?,?)', (sid, 'pending_payment', now))
        return jsonify(id=sid, code=code), 201

    @app.get('/api/shipments')
    def my_shipments():
        u = user()
        if not u:
            return fail('Iniciá sesión.', 401)
        rows = db().execute('SELECT * FROM shipments WHERE client=? ORDER BY created DESC LIMIT 200', (u['id'],)).fetchall()
        return jsonify(items=[shipment_json(r, u) for r in rows])

    def load_shipment(sid):
        return db().execute('SELECT * FROM shipments WHERE id=?', (sid,)).fetchone()

    @app.get('/api/shipments/<sid>')
    def shipment_detail(sid):
        u = user()
        row = load_shipment(sid)
        if not u or not row or u['id'] not in (row['client'], row['driver']) and not is_admin(u):
            return fail('Envío no encontrado.', 404)
        return jsonify(shipment_json(row, u, is_admin(u)))

    @app.get('/api/track/<code>')
    def track(code):
        if not limit('track:' + (request.remote_addr or '?'), 60, 600):
            return fail('Demasiadas consultas. Esperá unos minutos.', 429)
        row = db().execute('SELECT * FROM shipments WHERE code=?', (code.strip().upper(),)).fetchone()
        if not row:
            return fail('No encontramos un envío con ese código.', 404)
        events = db().execute('SELECT status, at FROM events WHERE shipment=? ORDER BY at', (row['id'],)).fetchall()
        return jsonify(code=row['code'], status=row['status'], status_label=STATUS[row['status']],
                       origin_label=CITIES[row['origin']]['label'], destination_label=CITIES[row['destination']]['label'],
                       pickup_date=row['pickup_date'], events=[dict(e) for e in events])

    @app.post('/api/shipments/<sid>/cancel')
    def cancel(sid):
        u = user()
        row = load_shipment(sid)
        if not u or not row or row['client'] != u['id']:
            return fail('Envío no encontrado.', 404)
        if row['status'] not in ('pending_payment', 'paid', 'assigned'):
            return fail('Este envío ya no se puede cancelar.')
        refund = 'pending' if row['status'] != 'pending_payment' else ''
        with db() as con:
            set_status(con, sid, 'cancelled', ', refund_status=?', (refund,))
        return jsonify(ok=True, refund=bool(refund))

    @app.post('/api/shipments/<sid>/rate')
    def rate(sid):
        u = user()
        row = load_shipment(sid)
        if not u or not row or row['client'] != u['id']:
            return fail('Envío no encontrado.', 404)
        if row['status'] != 'delivered' or row['rating']:
            return fail('Podés calificar una vez, cuando el envío se entregó.')
        p = request.get_json(silent=True) or {}
        stars, review = p.get('stars'), str(p.get('review', '')).strip()[:500]
        if stars not in (1, 2, 3, 4, 5):
            return fail('Elegí de 1 a 5 estrellas.')
        with db() as con:
            con.execute('UPDATE shipments SET rating=?, review=? WHERE id=?', (stars, review, sid))
            con.execute('UPDATE drivers SET rating_sum=rating_sum+?, rating_count=rating_count+1 WHERE user_id=?', (stars, row['driver']))
        return jsonify(ok=True)

    # ---------- payments (Mercado Pago Checkout Pro: wallet, credit and debit cards) ----------
    def mp_request(method, path, body=None):
        headers = {'Authorization': 'Bearer ' + app.config['MP_ACCESS_TOKEN'], 'Content-Type': 'application/json'}
        if method == 'POST':
            headers['X-Idempotency-Key'] = uuid.uuid4().hex
        req = urllib.request.Request('https://api.mercadopago.com' + path, method=method, headers=headers,
                                     data=json.dumps(body).encode() if body is not None else None)
        with urllib.request.urlopen(req, timeout=15) as response:
            return json.loads(response.read())

    app.mp_request = mp_request  # tests replace this

    def apply_payment(payment):
        """Mark the shipment paid if Mercado Pago says this payment is approved for the full amount."""
        sid = str(payment.get('external_reference', ''))
        row = load_shipment(sid)
        if not row or payment.get('status') != 'approved' or payment.get('currency_id') != 'ARS':
            return row
        if float(payment.get('transaction_amount') or 0) + 0.01 < row['price']:
            app.logger.warning('Payment %s amount below price for %s', payment.get('id'), sid)
            return row
        method = 'card' if payment.get('payment_type_id') in ('credit_card', 'debit_card', 'prepaid_card') else 'mercadopago'
        with db() as con:
            if row['status'] == 'pending_payment':
                set_status(con, sid, 'paid', ', payment_id=?, payment_method=?', (str(payment.get('id')), method))
            elif row['status'] == 'cancelled' and not row['payment_id']:
                # Paid after cancelling: keep a record so the admin refunds it.
                con.execute("UPDATE shipments SET payment_id=?, payment_method=?, refund_status='pending' WHERE id=?",
                            (str(payment.get('id')), method, sid))
        return load_shipment(sid)

    @app.post('/api/shipments/<sid>/pay')
    def pay(sid):
        u = user()
        row = load_shipment(sid)
        if not u or not row or row['client'] != u['id']:
            return fail('Envío no encontrado.', 404)
        if row['status'] != 'pending_payment':
            return fail('Este envío ya fue pagado o cancelado.')
        method = (request.get_json(silent=True) or {}).get('method')
        if method not in ('mercadopago', 'card'):
            return fail('Elegí cómo pagar.')
        if not app.config['MP_ACCESS_TOKEN'] or not app.config['PUBLIC_URL']:
            if app.config['DEMO_PAYMENTS']:
                return jsonify(demo=True)
            return fail('Los pagos no están configurados. Avisale al administrador.', 503)
        base = app.config['PUBLIC_URL']
        preference = {
            'items': [{'id': row['code'], 'title': f'{SERVICES[row["service"]]} {row["code"]}', 'quantity': 1,
                       'currency_id': 'ARS', 'unit_price': row['price']}],
            'payer': {'name': u['name'], 'email': u['email']},
            'external_reference': sid,
            'back_urls': {k: f'{base}/pago/{sid}' for k in ('success', 'pending', 'failure')},
            'auto_return': 'approved',
            'notification_url': f'{base}/api/payments/webhook',
            'statement_descriptor': 'FLETA',
            'payment_methods': {'installments': 12},
        }
        if method == 'card':
            preference['payment_methods']['excluded_payment_types'] = [{'id': t} for t in ('ticket', 'atm', 'bank_transfer', 'digital_currency')]
        try:
            result = app.mp_request('POST', '/checkout/preferences', preference)
        except (urllib.error.URLError, TimeoutError, ValueError):
            app.logger.exception('Mercado Pago preference failed')
            return fail('Mercado Pago no respondió. Probá de nuevo en unos minutos.', 502)
        return jsonify(url=result['init_point'])

    @app.post('/api/shipments/<sid>/confirm-payment')
    def confirm_payment(sid):
        """Called when the buyer returns from Mercado Pago; the webhook does the same in the background."""
        u = user()
        row = load_shipment(sid)
        if not u or not row or row['client'] != u['id']:
            return fail('Envío no encontrado.', 404)
        payment_id = str((request.get_json(silent=True) or {}).get('payment_id', ''))
        if row['status'] == 'pending_payment' and re.fullmatch(r'\d{1,20}', payment_id) and app.config['MP_ACCESS_TOKEN']:
            try:
                payment = app.mp_request('GET', '/v1/payments/' + payment_id)
                if str(payment.get('external_reference')) == sid:
                    row = apply_payment(payment)
            except (urllib.error.URLError, TimeoutError, ValueError):
                app.logger.exception('Mercado Pago lookup failed')
        return jsonify(status=row['status'], status_label=STATUS[row['status']])

    @app.post('/api/shipments/<sid>/demo-pay')
    def demo_pay(sid):
        u = user()
        row = load_shipment(sid)
        if not app.config['DEMO_PAYMENTS'] or app.config['MP_ACCESS_TOKEN']:
            return fail('No disponible.', 404)
        if not u or not row or row['client'] != u['id'] or row['status'] != 'pending_payment':
            return fail('Envío no encontrado.', 404)
        method = (request.get_json(silent=True) or {}).get('method')
        with db() as con:
            set_status(con, sid, 'paid', ', payment_id=?, payment_method=?', ('demo-' + uuid.uuid4().hex[:8], 'card' if method == 'card' else 'mercadopago'))
        return jsonify(ok=True)

    @app.post('/api/payments/webhook')
    def webhook():
        if not limit('webhook:' + (request.remote_addr or '?'), 300, 60):
            return fail('Demasiadas solicitudes.', 429)
        body = request.get_json(silent=True) or {}
        kind = request.args.get('type') or request.args.get('topic') or body.get('type')
        data_id = str(request.args.get('data.id') or (body.get('data') or {}).get('id') or request.args.get('id') or '')
        secret = app.config['MP_WEBHOOK_SECRET']
        if secret:
            parts = dict(p.split('=', 1) for p in request.headers.get('x-signature', '').split(',') if '=' in p)
            manifest = f'id:{data_id.lower()};request-id:{request.headers.get("x-request-id", "")};ts:{parts.get("ts", "")};'
            expected = hmac.new(secret.encode(), manifest.encode(), hashlib.sha256).hexdigest()
            if not secrets.compare_digest(expected, parts.get('v1', '')):
                return fail('Firma inválida.', 401)
        if kind == 'payment' and re.fullmatch(r'\d{1,20}', data_id) and app.config['MP_ACCESS_TOKEN']:
            # Never trust the notification body: always ask Mercado Pago for the payment.
            try:
                apply_payment(app.mp_request('GET', '/v1/payments/' + data_id))
            except (urllib.error.URLError, TimeoutError, ValueError):
                app.logger.exception('Mercado Pago lookup failed')
                return fail('Reintentar.', 502)
        return jsonify(ok=True)

    # ---------- drivers ----------
    def save_doc(storage, kind):
        raw = storage.read(8000001)
        if len(raw) > 8000000:
            raise ValueError(f'{DRIVER_DOCS[kind]}: el archivo supera 8 MB.')
        if raw.startswith(b'%PDF-') and kind in PDF_DOCS:
            content, ext = raw, '.pdf'
        else:
            try:
                with Image.open(io.BytesIO(raw)) as img:
                    if img.format not in ('JPEG', 'PNG', 'WEBP'):
                        raise ValueError
                    img.load()
                    # Re-encoding strips EXIF (GPS, device) and any payload hidden in the file.
                    clean = ImageOps.exif_transpose(img).convert('RGB')
                    clean.thumbnail((2200, 2200))
                    out = io.BytesIO()
                    clean.save(out, 'JPEG', quality=88)
                    content, ext = out.getvalue(), '.jpg'
            except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError, Image.DecompressionBombWarning):
                allowed = 'JPG, PNG, WebP o PDF' if kind in PDF_DOCS else 'JPG, PNG o WebP'
                raise ValueError(f'{DRIVER_DOCS[kind]}: subí un archivo {allowed} válido.')
        key = uuid.uuid4().hex + ext
        (docs_dir / key).write_bytes(content)
        return key

    def driver_row(uid):
        return db().execute('SELECT * FROM drivers WHERE user_id=?', (uid,)).fetchone()

    def driver_problems(d):
        """Reasons an approved driver cannot take jobs today."""
        today = date.today().isoformat()
        problems = []
        if d['license_expiry'] < today:
            problems.append('Tu licencia está vencida. Volvé a enviar la documentación.')
        if d['insurance_expiry'] < today:
            problems.append('Tu seguro está vencido. Volvé a enviar la documentación.')
        return problems

    @app.get('/api/driver/me')
    def driver_me():
        u = user()
        if not u:
            return fail('Iniciá sesión.', 401)
        d = driver_row(u['id'])
        if not d:
            return jsonify(driver=None)
        out = {k: d[k] for k in ('status', 'vehicle', 'vehicle_model', 'plate', 'base_city', 'long_distance',
                                 'license_expiry', 'insurance_expiry', 'review_note', 'rating_count')}
        out['rating'] = round(d['rating_sum'] / d['rating_count'], 1) if d['rating_count'] else None
        out['problems'] = driver_problems(d) if d['status'] == 'approved' else []
        out['base_city_label'] = CITIES[d['base_city']]['label']
        earned = db().execute("SELECT COALESCE(SUM(payout),0) s, COUNT(*) n FROM shipments WHERE driver=? AND status='delivered'", (u['id'],)).fetchone()
        out['earned'], out['trips'] = earned['s'], earned['n']
        return jsonify(driver=out)

    @app.post('/api/driver/apply')
    def driver_apply():
        u = user()
        if not u:
            return fail('Iniciá sesión.', 401)
        if not limit('apply:' + u['id'], 5, 3600):
            return fail('Demasiados envíos de documentación. Esperá una hora.', 429)
        current = driver_row(u['id'])
        if current and current['status'] == 'suspended':
            return fail('Tu cuenta de chofer está suspendida. Escribinos para revisarla.', 403)
        f = request.form
        cuil = re.sub(r'\D', '', f.get('cuil', ''))
        plate = re.sub(r'[\s-]', '', f.get('plate', '')).upper()
        vehicle, model, base_city = f.get('vehicle', ''), f.get('vehicle_model', '').strip(), f.get('base_city', '')
        lic, ins = parse_date(f.get('license_expiry')), parse_date(f.get('insurance_expiry'))
        if not valid_cuil(cuil):
            return fail('El CUIL no es válido. Revisá los 11 números.')
        if vehicle not in VEHICLES or not 2 <= len(model) <= 60:
            return fail('Indicá tipo, marca y modelo del vehículo.')
        if not valid_plate(plate):
            return fail('La patente no es válida (ej. AB123CD o ABC123).')
        if base_city not in CITIES:
            return fail('Elegí tu ciudad base.')
        if not lic or not ins or lic <= date.today() or ins <= date.today():
            return fail('La licencia y el seguro tienen que estar vigentes.')
        missing = [label for kind, label in DRIVER_DOCS.items() if not request.files.get(kind)]
        if missing:
            return fail('Falta subir: ' + ', '.join(missing) + '.')
        keys = {}
        try:
            for kind in DRIVER_DOCS:
                keys[kind] = save_doc(request.files[kind], kind)
            with db() as con:
                con.execute('''INSERT INTO drivers (user_id,status,cuil,vehicle,vehicle_model,plate,base_city,long_distance,
                    license_expiry,insurance_expiry,docs,review_note,submitted) VALUES (?,?,?,?,?,?,?,?,?,?,?,'',?)
                    ON CONFLICT(user_id) DO UPDATE SET status=excluded.status, cuil=excluded.cuil, vehicle=excluded.vehicle,
                    vehicle_model=excluded.vehicle_model, plate=excluded.plate, base_city=excluded.base_city,
                    long_distance=excluded.long_distance, license_expiry=excluded.license_expiry,
                    insurance_expiry=excluded.insurance_expiry, docs=excluded.docs, review_note='',
                    submitted=excluded.submitted, reviewed=NULL''',
                            (u['id'], 'pending', cuil, vehicle, model, plate, base_city, 1 if f.get('long_distance') == '1' else 0,
                             lic.isoformat(), ins.isoformat(), json.dumps(keys), int(time.time())))
        except ValueError as error:
            for key in keys.values():
                (docs_dir / key).unlink(missing_ok=True)
            return fail(str(error))
        except Exception:
            for key in keys.values():
                (docs_dir / key).unlink(missing_ok=True)
            raise
        if current:
            for key in json.loads(current['docs']).values():
                (docs_dir / key).unlink(missing_ok=True)
        return jsonify(ok=True, status='pending'), 201

    def approved_driver(u):
        d = driver_row(u['id']) if u else None
        return d if d and d['status'] == 'approved' else None

    @app.get('/api/driver/jobs')
    def jobs():
        u = user()
        d = approved_driver(u)
        if not d:
            return fail('Tu cuenta de chofer todavía no está verificada.', 403)
        rank = VEHICLES[d['vehicle']]['rank']
        rows = db().execute("SELECT * FROM shipments WHERE status='paid' AND driver IS NULL AND pickup_date>=? ORDER BY pickup_date LIMIT 300",
                            (date.today().isoformat(),)).fetchall()
        base = CITIES[d['base_city']]
        items = []
        for r in rows:
            if VEHICLES[r['vehicle']]['rank'] > rank or r['client'] == u['id']:
                continue
            origin = CITIES[r['origin']]
            if not d['long_distance'] and (distance_km(base, origin) > 80 or r['distance_km'] > 80):
                continue
            job = shipment_json(r, None)
            job['payout'] = r['payout']
            job['km_from_base'] = distance_km(base, origin) if base is not origin else 0
            items.append(job)
        items.sort(key=lambda j: (j['pickup_date'], j['km_from_base']))
        return jsonify(items=items[:100], problems=driver_problems(d))

    @app.get('/api/driver/trips')
    def trips():
        u = user()
        if not u:
            return fail('Iniciá sesión.', 401)
        rows = db().execute('SELECT * FROM shipments WHERE driver=? ORDER BY pickup_date DESC LIMIT 200', (u['id'],)).fetchall()
        return jsonify(items=[shipment_json(r, u) for r in rows])

    @app.post('/api/shipments/<sid>/<action>')
    def driver_action(sid, action):
        u = user()
        row = load_shipment(sid)
        if action not in ('accept', 'release', 'pickup', 'deliver') or not row:
            return fail('Envío no encontrado.', 404)
        d = approved_driver(u)
        if not d:
            return fail('Tu cuenta de chofer todavía no está verificada.', 403)
        if action == 'accept':
            problems = driver_problems(d)
            if problems:
                return fail(problems[0], 403)
            if VEHICLES[row['vehicle']]['rank'] > VEHICLES[d['vehicle']]['rank'] or row['client'] == u['id']:
                return fail('Este viaje no es compatible con tu vehículo.', 403)
            active = db().execute("SELECT COUNT(*) n FROM shipments WHERE driver=? AND status IN ('assigned','in_transit')", (u['id'],)).fetchone()['n']
            if active >= ACTIVE_TRIPS_MAX:
                return fail(f'Podés tener hasta {ACTIVE_TRIPS_MAX} viajes activos.')
            with db() as con:
                # The status/driver check inside the UPDATE makes accepting atomic: only one driver wins.
                taken = con.execute("UPDATE shipments SET driver=?, status='assigned', updated=? WHERE id=? AND status='paid' AND driver IS NULL",
                                    (u['id'], int(time.time()), sid)).rowcount
                if taken:
                    con.execute('INSERT INTO events VALUES (?,?,?)', (sid, 'assigned', int(time.time())))
            if not taken:
                return fail('Otro chofer ya tomó este viaje.', 409)
            return jsonify(shipment_json(load_shipment(sid), u))
        if row['driver'] != u['id']:
            return fail('Este viaje no es tuyo.', 403)
        if action == 'release':
            if row['status'] != 'assigned':
                return fail('Solo podés liberar un viaje antes de retirar la carga.')
            with db() as con:
                set_status(con, sid, 'paid', ', driver=NULL')
        elif action == 'pickup':
            if row['status'] != 'assigned':
                return fail('El viaje no está listo para retirar.')
            with db() as con:
                set_status(con, sid, 'in_transit')
        else:
            if row['status'] != 'in_transit':
                return fail('Primero marcá que retiraste la carga.')
            if not limit('pin:' + sid, 5, 3600):
                return fail('Demasiados intentos con el código. Esperá una hora o contactá a soporte.', 429)
            pin = str((request.get_json(silent=True) or {}).get('pin', ''))
            if not secrets.compare_digest(pin, row['pin']):
                return fail('El código de entrega no coincide. Pedíselo a quien recibe.')
            with db() as con:
                set_status(con, sid, 'delivered')
        return jsonify(shipment_json(load_shipment(sid), u))

    # ---------- admin ----------
    def admin_or_none():
        u = user()
        return u if is_admin(u) else None

    @app.get('/api/admin/drivers')
    def admin_drivers():
        if not admin_or_none():
            return fail('No autorizado.', 403)
        status = request.args.get('status', 'pending')
        rows = db().execute('SELECT d.*, u.name, u.email, u.phone FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.status=? ORDER BY d.submitted LIMIT 200',
                            (status,)).fetchall()
        items = []
        for r in rows:
            item = dict(r)
            item['docs'] = json.loads(item['docs'])
            item['base_city_label'] = CITIES[item['base_city']]['label']
            item['vehicle_label'] = VEHICLES[item['vehicle']]['label']
            items.append(item)
        return jsonify(items=items, doc_labels=DRIVER_DOCS)

    @app.get('/api/admin/docs/<key>')
    def admin_doc(key):
        if not admin_or_none():
            return fail('No autorizado.', 403)
        if not re.fullmatch(r'[a-f0-9]{32}\.(jpg|pdf)', key):
            return fail('Documento no encontrado.', 404)
        response = send_from_directory(docs_dir, key, mimetype='application/pdf' if key.endswith('.pdf') else 'image/jpeg', max_age=0)
        response.headers['Cache-Control'] = 'private, no-store'
        return response

    @app.post('/api/admin/drivers/<uid>')
    def admin_review(uid):
        if not admin_or_none():
            return fail('No autorizado.', 403)
        p = request.get_json(silent=True) or {}
        status = {'approve': 'approved', 'reject': 'rejected', 'suspend': 'suspended'}.get(p.get('action'))
        note = str(p.get('note', '')).strip()[:300]
        if not status:
            return fail('Acción no válida.')
        if status != 'approved' and not note:
            return fail('Escribí el motivo para que el chofer sepa qué corregir.')
        with db() as con:
            if not con.execute('UPDATE drivers SET status=?, review_note=?, reviewed=? WHERE user_id=?', (status, note, int(time.time()), uid)).rowcount:
                return fail('Chofer no encontrado.', 404)
        return jsonify(ok=True)

    @app.get('/api/admin/shipments')
    def admin_shipments():
        u = admin_or_none()
        if not u:
            return fail('No autorizado.', 403)
        only_refunds = request.args.get('refunds') == '1'
        rows = db().execute("SELECT * FROM shipments WHERE refund_status='pending' ORDER BY updated DESC LIMIT 200" if only_refunds
                            else 'SELECT * FROM shipments ORDER BY created DESC LIMIT 200').fetchall()
        return jsonify(items=[shipment_json(r, u, admin=True) for r in rows])

    @app.post('/api/admin/shipments/<sid>/refunded')
    def admin_refunded(sid):
        if not admin_or_none():
            return fail('No autorizado.', 403)
        with db() as con:
            if not con.execute("UPDATE shipments SET refund_status='done' WHERE id=? AND refund_status='pending'", (sid,)).rowcount:
                return fail('No hay devolución pendiente.', 404)
        return jsonify(ok=True)

    return app


if __name__ == '__main__':
    create_app().run(host='127.0.0.1', port=int(os.environ.get('PORT', '5000')))
