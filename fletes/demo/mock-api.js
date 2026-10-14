'use strict';
// Demo backend: answers the same /api/* routes as app.py, inside the browser, so the real frontend
// can be tried without a server. Rules mirror app.py; CONFIG is injected by demo/build.py from app.py/cities.py.
(function () {
  const C = window.FLETA_CONFIG;
  const STORE = 'fleta-demo-v1';
  const PASS = 'demo1234567';
  const now = () => Math.floor(Date.now() / 1000);
  const id = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
  const iso = d => { const x = new Date(d); x.setMinutes(x.getMinutes() - x.getTimezoneOffset()); return x.toISOString().slice(0, 10); };
  const today = () => iso(Date.now());
  const plusDays = n => iso(Date.now() + n * 86400000);
  const round100 = n => Math.round(n / 100) * 100;

  function distanceKm(a, b) {
    const r = Math.PI / 180, lat1 = a.lat * r, lat2 = b.lat * r;
    const h = Math.sin((lat2 - lat1) / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin((b.lon - a.lon) * r / 2) ** 2;
    return Math.max(3, Math.round(2 * 6371 * Math.asin(Math.sqrt(h)) * C.road_factor));
  }
  function validCuil(v) {
    if (!/^(20|23|24|27|30|33|34)\d{9}$/.test(v)) return false;
    const w = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    let check = 11 - w.reduce((s, x, i) => s + x * +v[i], 0) % 11;
    if (check === 11) check = 0;
    return check !== 10 && check === +v[10];
  }
  const validPlate = v => /^([A-Z]{3}\d{3}|[A-Z]{2}\d{3}[A-Z]{2}|A?\d{3}[A-Z]{3})$/.test(v);

  // ---------- sample documents (drawn, clearly marked as examples) ----------
  function sampleDoc(hue) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200"><rect width="320" height="200" rx="14" fill="hsl(${hue},45%,88%)"/><rect x="18" y="40" width="80" height="100" rx="8" fill="hsl(${hue},30%,70%)"/><rect x="115" y="50" width="170" height="12" rx="6" fill="hsl(${hue},25%,60%)"/><rect x="115" y="75" width="130" height="12" rx="6" fill="hsl(${hue},25%,60%)"/><rect x="115" y="100" width="150" height="12" rx="6" fill="hsl(${hue},25%,60%)"/><text x="160" y="180" text-anchor="middle" font-family="sans-serif" font-size="22" font-weight="800" fill="hsl(${hue},40%,25%)">EJEMPLO</text></svg>`;
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  }
  function sampleDocs() {
    return Object.entries(C.docs).map(([k], i) => ({ kind: k, url: sampleDoc(150 + i * 25), pdf: false }));
  }

  // ---------- state ----------
  function seed() {
    const db = { users: [], drivers: {}, shipments: [], events: [], session: null, pinTries: {} };
    const user = (email, name, phone) => { const u = { id: id(), email, name, phone }; db.users.push(u); return u; };
    user('admin@fleta.demo', 'Administración', '1140000000');
    user('laura@fleta.demo', 'Laura Gómez', '1155554444');
    const diego = user('diego@fleta.demo', 'Diego Fernández', '1161112222');
    const carlos = user('carlos@fleta.demo', 'Carlos Ruiz', '1167778888');
    const martin = user('martin@fleta.demo', 'Martín Sosa', '1133339999');
    const driver = (u, status, extra) => {
      db.drivers[u.id] = Object.assign({ user_id: u.id, status, cuil: '20283456780', vehicle: 'camioneta', vehicle_model: 'Renault Master 2020',
        plate: 'AE123FG', base_city: 'caballito-caba', long_distance: 1, license_expiry: plusDays(400), insurance_expiry: plusDays(200),
        documents: sampleDocs(), review_note: '', submitted: now() - 7200, rating_sum: 58, rating_count: 12 }, extra);
    };
    driver(carlos, 'approved', {});
    driver(martin, 'pending', { cuil: '20352223334', vehicle: 'utilitario', vehicle_model: 'Fiat Fiorino 2018', plate: 'AC456HJ',
      base_city: 'moron-gba-oeste', long_distance: 0, rating_sum: 0, rating_count: 0 });
    // Two paid trips waiting for a driver, from another client.
    createShipment(db, diego, { service: 'flete', origin: 'moron-gba-oeste', destination: 'mar-del-plata-buenos-aires', vehicle: 'camioneta', kg: 600, helpers: 1,
      declared_value: 0, insurance: false, pickup_date: plusDays(2), slot: 'am', origin_address: 'Rivadavia 18200', dest_address: 'Av. Colón 2450 3A',
      description: 'Mudanza chica: cama, ropero, 10 cajas y una bicicleta.', receiver_name: 'Sofía Fernández', receiver_phone: '2235551234' }, 'paid');
    createShipment(db, diego, { service: 'bultos', origin: 'san-isidro-gba-norte', destination: 'palermo-caba', vehicle: '', kg: 25, helpers: 0,
      declared_value: 0, insurance: false, pickup_date: plusDays(1), slot: 'pm', origin_address: 'Belgrano 340', dest_address: 'Thames 1500',
      description: '3 cajas de libros.', receiver_name: 'Juan Pérez', receiver_phone: '1145556666' }, 'paid');
    return db;
  }
  let db;
  try { db = JSON.parse(localStorage.getItem(STORE)); } catch { db = null; }
  if (!db || !db.users) db = seed();
  const save = () => { try { localStorage.setItem(STORE, JSON.stringify(db)); } catch { /* demo still works in memory */ } };
  save();

  // ---------- domain ----------
  function quote(p) {
    const origin = C.cities[p.origin], dest = C.cities[p.destination];
    if (!origin || !dest) return [null, 'Elegí origen y destino de la lista.'];
    if (!C.services[p.service]) return [null, 'Elegí el tipo de servicio.'];
    const kg = parseInt(p.kg || 0, 10), declared = parseInt(p.declared_value || 0, 10);
    let helpers = parseInt(p.helpers || 0, 10);
    if ([kg, helpers, declared].some(Number.isNaN) || kg < 1 || kg > 4000 || helpers < 0 || helpers > 3 || declared < 0 || declared > 50000000)
      return [null, 'Revisá peso (1 a 4.000 kg), ayudantes (0 a 3) y valor declarado.'];
    const km = origin !== dest ? distanceKm(origin, dest) : 5;
    let vehicle, transport;
    if (p.service === 'bultos') {
      if (kg > C.bultos_max_kg) return [null, `Para más de ${C.bultos_max_kg} kg pedí un flete exclusivo.`];
      vehicle = Object.keys(C.vehicles).find(k => k !== 'moto' && C.vehicles[k].max_kg >= kg); helpers = 0;
      transport = 7000 + kg * (120 + 0.5 * km);
    } else {
      vehicle = p.vehicle; const v = C.vehicles[vehicle];
      if (!v) return [null, 'Elegí el vehículo.'];
      if (kg > v.max_kg) return [null, `${v.label}: hasta ${v.max_kg} kg. Elegí un vehículo más grande.`];
      if (vehicle === 'moto' && km > C.moto_max_km) return [null, `Las motos hacen viajes de hasta ${C.moto_max_km} km.`];
      transport = v.base + v.km * (Math.min(km, 100) + Math.max(km - 100, 0) * 0.65);
    }
    transport = round100(transport * C.price_factor);
    const helpersCost = round100(helpers * C.helper_price * C.price_factor);
    const insurance = p.insurance && declared ? Math.max(1500, Math.round(declared * 0.015)) : 0;
    return [{ origin: origin.id, destination: dest.id, service: p.service, vehicle, kg, helpers, declared_value: declared, insurance,
      distance_km: km, price: transport + helpersCost + insurance, payout: Math.round((transport + helpersCost) * (1 - C.fee_pct / 100)),
      long_distance: !(origin.region === 'AMBA' && dest.region === 'AMBA') && km > 60,
      breakdown: [['Transporte', transport], ['Ayudantes', helpersCost], ['Seguro de la carga', insurance]] }, null];
  }

  function createShipment(store, u, p, status = 'pending_payment') {
    const [q] = quote(p);
    const code = 'FL-' + Array.from({ length: 6 }, () => C.code_alphabet[Math.floor(Math.random() * C.code_alphabet.length)]).join('');
    const s = { id: id(), code, client: u.id, driver: null, ...q, pickup_date: p.pickup_date, slot: p.slot,
      origin_address: p.origin_address.trim(), dest_address: p.dest_address.trim(), description: p.description.trim(),
      receiver_name: p.receiver_name.trim(), receiver_phone: String(p.receiver_phone).replace(/\D/g, ''),
      pin: String(Math.floor(Math.random() * 10000)).padStart(4, '0'), status: 'pending_payment', payment_method: null,
      refund_status: '', rating: null, review: null, created: now(), updated: now() };
    store.shipments.push(s);
    store.events.push({ shipment: s.id, status: 'pending_payment', at: now() });
    if (status === 'paid') { s.status = 'paid'; s.payment_method = 'mercadopago'; store.events.push({ shipment: s.id, status: 'paid', at: now() }); }
    return s;
  }
  function setStatus(s, status, extra = {}) {
    Object.assign(s, extra, { status, updated: now() });
    db.events.push({ shipment: s.id, status, at: now() });
  }

  const currentUser = () => db.users.find(u => u.id === db.session) || null;
  const isAdmin = u => !!u && u.email === 'admin@fleta.demo';
  const city = cid => C.cities[cid];
  function driverPublic(uid) {
    const d = db.drivers[uid], u = db.users.find(x => x.id === uid);
    return { name: u.name, phone: u.phone, vehicle: C.vehicles[d.vehicle].label, vehicle_model: d.vehicle_model, plate: d.plate,
      verified: d.status === 'approved', rating: d.rating_count ? Math.round(d.rating_sum / d.rating_count * 10) / 10 : null, rating_count: d.rating_count };
  }
  function shipmentJson(s, viewer, admin = false) {
    const isClient = viewer && s.client === viewer.id, isDriver = viewer && s.driver === viewer.id;
    const out = {};
    for (const k of ['id', 'code', 'service', 'vehicle', 'origin', 'destination', 'pickup_date', 'slot', 'kg', 'helpers', 'description', 'distance_km', 'status', 'created']) out[k] = s[k];
    Object.assign(out, { origin_label: city(s.origin).label, destination_label: city(s.destination).label, status_label: C.status[s.status],
      slot_label: C.slots[s.slot], vehicle_label: C.vehicles[s.vehicle].label, service_label: C.services[s.service] });
    if (isClient || admin) {
      Object.assign(out, { price: s.price, insurance: s.insurance, declared_value: s.declared_value, payment_method: s.payment_method,
        refund_status: s.refund_status, rating: s.rating, review: s.review, mine: !!isClient });
      if (!['delivered', 'cancelled'].includes(s.status)) out.pin = s.pin;
    }
    if (isDriver || admin) out.payout = s.payout;
    if (isClient || isDriver || admin) {
      Object.assign(out, { origin_address: s.origin_address, dest_address: s.dest_address, receiver_name: s.receiver_name, receiver_phone: s.receiver_phone });
      out.events = db.events.filter(e => e.shipment === s.id).map(({ status, at }) => ({ status, at }));
    }
    if (s.driver && (isClient || admin)) out.driver = driverPublic(s.driver);
    if (isDriver || admin) { const c = db.users.find(u => u.id === s.client); out.client = { name: c.name, phone: c.phone }; }
    return out;
  }
  function driverProblems(d) {
    const p = [];
    if (d.license_expiry < today()) p.push('Tu licencia está vencida. Volvé a enviar la documentación.');
    if (d.insurance_expiry < today()) p.push('Tu seguro está vencido. Volvé a enviar la documentación.');
    return p;
  }
  function shrink(file) {
    // Store a small preview of each uploaded document so the admin can review it.
    return new Promise(resolve => {
      if (file.type === 'application/pdf') return resolve({ url: '', pdf: true });
      const reader = new FileReader();
      reader.onload = () => {
        const img = new Image();
        img.onload = () => {
          const scale = Math.min(1, 480 / Math.max(img.width, img.height));
          const canvas = document.createElement('canvas');
          canvas.width = Math.round(img.width * scale); canvas.height = Math.round(img.height * scale);
          canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
          resolve({ url: canvas.toDataURL('image/jpeg', 0.7), pdf: false });
        };
        img.onerror = () => resolve(null);
        img.src = reader.result;
      };
      reader.onerror = () => resolve(null);
      reader.readAsDataURL(file);
    });
  }

  // ---------- routes ----------
  class Fail extends Error { constructor(message, status = 400) { super(message); this.status = status; } }
  const need = cond => { if (!cond) throw new Fail('Envío no encontrado.', 404); };
  const routes = [];
  const on = (method, pattern, fn) => routes.push([method, new RegExp('^' + pattern + '$'), fn]);

  on('GET', '/api/session', () => {
    const u = currentUser();
    return { csrf: 'demo', demo_payments: true, user: u && { ...u, admin: isAdmin(u), driver_status: db.drivers[u.id] ? db.drivers[u.id].status : null } };
  });
  on('POST', '/api/auth/(login|register|logout)', (body, [action]) => {
    if (action === 'logout') { db.session = null; return { ok: true, csrf: 'demo' }; }
    const email = String(body.email || '').trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || String(body.password || '').length < 10) throw new Fail('Revisá el correo y usá una contraseña de 10 a 128 caracteres.');
    let u = db.users.find(x => x.email === email);
    if (action === 'register') {
      const name = String(body.name || '').trim(), phone = String(body.phone || '').replace(/\D/g, '');
      if (name.length < 2) throw new Fail('Ingresá tu nombre y apellido.');
      if (!/^\d{10,15}$/.test(phone)) throw new Fail('Ingresá un celular válido con código de área (ej. 11 2345 6789).');
      if (u) throw new Fail('No se pudo crear la cuenta con ese correo.', 409);
      u = { id: id(), email, name, phone }; db.users.push(u);
      (db.passwords ||= {})[u.id] = body.password;
    } else if (!u || ((db.passwords || {})[u.id] || PASS) !== body.password) throw new Fail('Correo o contraseña incorrectos.', 401);
    db.session = u.id;
    return routes[0][2]();
  });
  on('GET', '/api/catalog', () => ({
    cities: C.city_list, vehicles: Object.entries(C.vehicles).map(([k, v]) => ({ id: k, label: v.label, max_kg: v.max_kg })),
    services: C.services, slots: C.slots, docs: C.docs, helper_price: C.helper_price, bultos_max_kg: C.bultos_max_kg, moto_max_km: C.moto_max_km }));
  on('POST', '/api/quote', body => { const [q, e] = quote(body); if (e) throw new Fail(e); return q; });

  on('POST', '/api/shipments', body => {
    const u = currentUser(); if (!u) throw new Fail('Iniciá sesión para pedir el flete.', 401);
    const [, e] = quote(body); if (e) throw new Fail(e);
    const d = body.pickup_date;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d || '') || d < today() || d > plusDays(90)) throw new Fail('Elegí una fecha de retiro entre hoy y los próximos 90 días.');
    if (!C.slots[body.slot]) throw new Fail('Elegí la franja horaria.');
    const len = k => String(body[k] || '').trim().length;
    if (len('origin_address') < 5 || len('dest_address') < 5) throw new Fail('Completá las direcciones de retiro y entrega (calle y número).');
    if (len('description') < 5) throw new Fail('Contanos qué hay que llevar (mínimo 5 caracteres).');
    if (len('receiver_name') < 2 || !/^\d{10,15}$/.test(String(body.receiver_phone || '').replace(/\D/g, ''))) throw new Fail('Completá nombre y celular de quien recibe.');
    const s = createShipment(db, u, body);
    return { id: s.id, code: s.code };
  });
  on('GET', '/api/shipments', () => {
    const u = currentUser(); if (!u) throw new Fail('Iniciá sesión.', 401);
    return { items: db.shipments.filter(s => s.client === u.id).sort((a, b) => b.created - a.created).map(s => shipmentJson(s, u)) };
  });
  const find = sid => db.shipments.find(s => s.id === sid);
  on('GET', '/api/shipments/([a-f0-9]+)', (_, [sid]) => {
    const u = currentUser(), s = find(sid);
    need(u && s && (u.id === s.client || u.id === s.driver || isAdmin(u)));
    return shipmentJson(s, u, isAdmin(u));
  });
  on('GET', '/api/track/([^/]+)', (_, [code]) => {
    const s = db.shipments.find(x => x.code === decodeURIComponent(code).trim().toUpperCase());
    if (!s) throw new Fail('No encontramos un envío con ese código.', 404);
    return { code: s.code, status: s.status, status_label: C.status[s.status], origin_label: city(s.origin).label, destination_label: city(s.destination).label,
      pickup_date: s.pickup_date, events: db.events.filter(e => e.shipment === s.id).map(({ status, at }) => ({ status, at })) };
  });
  const own = sid => { const u = currentUser(), s = find(sid); need(u && s && s.client === u.id); return [u, s]; };
  on('POST', '/api/shipments/([a-f0-9]+)/cancel', (_, [sid]) => {
    const [, s] = own(sid);
    if (!['pending_payment', 'paid', 'assigned'].includes(s.status)) throw new Fail('Este envío ya no se puede cancelar.');
    const refund = s.status !== 'pending_payment' ? 'pending' : '';
    setStatus(s, 'cancelled', { refund_status: refund });
    return { ok: true, refund: !!refund };
  });
  on('POST', '/api/shipments/([a-f0-9]+)/rate', (body, [sid]) => {
    const [, s] = own(sid);
    if (s.status !== 'delivered' || s.rating) throw new Fail('Podés calificar una vez, cuando el envío se entregó.');
    if (![1, 2, 3, 4, 5].includes(body.stars)) throw new Fail('Elegí de 1 a 5 estrellas.');
    s.rating = body.stars; s.review = String(body.review || '').trim().slice(0, 500);
    const d = db.drivers[s.driver]; d.rating_sum += body.stars; d.rating_count += 1;
    return { ok: true };
  });
  on('POST', '/api/shipments/([a-f0-9]+)/pay', (body, [sid]) => {
    const [, s] = own(sid);
    if (s.status !== 'pending_payment') throw new Fail('Este envío ya fue pagado o cancelado.');
    if (!['mercadopago', 'card'].includes(body.method)) throw new Fail('Elegí cómo pagar.');
    return { demo: true };
  });
  on('POST', '/api/shipments/([a-f0-9]+)/demo-pay', (body, [sid]) => {
    const [, s] = own(sid);
    need(s.status === 'pending_payment');
    setStatus(s, 'paid', { payment_method: body.method === 'card' ? 'card' : 'mercadopago' });
    return { ok: true };
  });

  on('GET', '/api/driver/me', () => {
    const u = currentUser(); if (!u) throw new Fail('Iniciá sesión.', 401);
    const d = db.drivers[u.id]; if (!d) return { driver: null };
    const done = db.shipments.filter(s => s.driver === u.id && s.status === 'delivered');
    return { driver: { status: d.status, vehicle: d.vehicle, vehicle_model: d.vehicle_model, plate: d.plate, base_city: d.base_city, long_distance: d.long_distance,
      license_expiry: d.license_expiry, insurance_expiry: d.insurance_expiry, review_note: d.review_note, rating_count: d.rating_count,
      rating: d.rating_count ? Math.round(d.rating_sum / d.rating_count * 10) / 10 : null, problems: d.status === 'approved' ? driverProblems(d) : [],
      base_city_label: city(d.base_city).label, earned: done.reduce((t, s) => t + s.payout, 0), trips: done.length } };
  });
  on('POST', '/api/driver/apply', async (form) => {
    const u = currentUser(); if (!u) throw new Fail('Iniciá sesión.', 401);
    const prev = db.drivers[u.id];
    if (prev && prev.status === 'suspended') throw new Fail('Tu cuenta de chofer está suspendida. Escribinos para revisarla.', 403);
    const f = k => String(form.get(k) || '');
    const cuil = f('cuil').replace(/\D/g, ''), plate = f('plate').replace(/[\s-]/g, '').toUpperCase();
    if (!validCuil(cuil)) throw new Fail('El CUIL no es válido. Revisá los 11 números.');
    if (!C.vehicles[f('vehicle')] || f('vehicle_model').trim().length < 2) throw new Fail('Indicá tipo, marca y modelo del vehículo.');
    if (!validPlate(plate)) throw new Fail('La patente no es válida (ej. AB123CD o ABC123).');
    if (!C.cities[f('base_city')]) throw new Fail('Elegí tu ciudad base.');
    if (!(f('license_expiry') > today()) || !(f('insurance_expiry') > today())) throw new Fail('La licencia y el seguro tienen que estar vigentes.');
    const missing = Object.entries(C.docs).filter(([k]) => !(form.get(k) instanceof File) || !form.get(k).size).map(([, l]) => l);
    if (missing.length) throw new Fail('Falta subir: ' + missing.join(', ') + '.');
    const documents = [];
    for (const k of Object.keys(C.docs)) {
      const file = form.get(k);
      if (file.type === 'application/pdf' && !C.pdf_docs.includes(k)) throw new Fail(`${C.docs[k]}: subí un archivo JPG, PNG o WebP válido.`);
      const doc = await shrink(file);
      if (!doc) throw new Fail(`${C.docs[k]}: subí un archivo de imagen válido.`);
      documents.push({ kind: k, ...doc });
    }
    db.drivers[u.id] = { user_id: u.id, status: 'pending', cuil, vehicle: f('vehicle'), vehicle_model: f('vehicle_model').trim(), plate, base_city: f('base_city'),
      long_distance: f('long_distance') === '1' ? 1 : 0, license_expiry: f('license_expiry'), insurance_expiry: f('insurance_expiry'), documents,
      review_note: '', submitted: now(), rating_sum: prev ? prev.rating_sum : 0, rating_count: prev ? prev.rating_count : 0 };
    return { ok: true, status: 'pending' };
  });
  const approved = () => { const u = currentUser(), d = u && db.drivers[u.id]; if (!d || d.status !== 'approved') throw new Fail('Tu cuenta de chofer todavía no está verificada.', 403); return [u, d]; };
  on('GET', '/api/driver/jobs', () => {
    const [u, d] = approved();
    const rank = C.vehicles[d.vehicle].rank, base = city(d.base_city);
    const items = db.shipments.filter(s => s.status === 'paid' && !s.driver && s.pickup_date >= today() && C.vehicles[s.vehicle].rank <= rank && s.client !== u.id)
      .filter(s => d.long_distance || (distanceKm(base, city(s.origin)) <= 80 && s.distance_km <= 80))
      .map(s => ({ ...shipmentJson(s, null), payout: s.payout, km_from_base: base === city(s.origin) ? 0 : distanceKm(base, city(s.origin)) }))
      .sort((a, b) => a.pickup_date.localeCompare(b.pickup_date) || a.km_from_base - b.km_from_base);
    return { items, problems: driverProblems(d) };
  });
  on('GET', '/api/driver/trips', () => {
    const u = currentUser(); if (!u) throw new Fail('Iniciá sesión.', 401);
    return { items: db.shipments.filter(s => s.driver === u.id).sort((a, b) => b.pickup_date.localeCompare(a.pickup_date)).map(s => shipmentJson(s, u)) };
  });
  on('POST', '/api/shipments/([a-f0-9]+)/(accept|release|pickup|deliver)', (body, [sid, action]) => {
    const s = find(sid); need(s);
    const [u, d] = approved();
    if (action === 'accept') {
      const p = driverProblems(d); if (p.length) throw new Fail(p[0], 403);
      if (C.vehicles[s.vehicle].rank > C.vehicles[d.vehicle].rank || s.client === u.id) throw new Fail('Este viaje no es compatible con tu vehículo.', 403);
      if (db.shipments.filter(x => x.driver === u.id && ['assigned', 'in_transit'].includes(x.status)).length >= C.active_trips_max) throw new Fail(`Podés tener hasta ${C.active_trips_max} viajes activos.`);
      if (s.status !== 'paid' || s.driver) throw new Fail('Otro chofer ya tomó este viaje.', 409);
      setStatus(s, 'assigned', { driver: u.id });
      return shipmentJson(s, u);
    }
    if (s.driver !== u.id) throw new Fail('Este viaje no es tuyo.', 403);
    if (action === 'release') { if (s.status !== 'assigned') throw new Fail('Solo podés liberar un viaje antes de retirar la carga.'); setStatus(s, 'paid', { driver: null }); }
    else if (action === 'pickup') { if (s.status !== 'assigned') throw new Fail('El viaje no está listo para retirar.'); setStatus(s, 'in_transit'); }
    else {
      if (s.status !== 'in_transit') throw new Fail('Primero marcá que retiraste la carga.');
      db.pinTries[sid] = (db.pinTries[sid] || 0) + 1;
      if (db.pinTries[sid] > 5) throw new Fail('Demasiados intentos con el código. Esperá una hora o contactá a soporte.', 429);
      if (String(body.pin || '') !== s.pin) throw new Fail('El código de entrega no coincide. Pedíselo a quien recibe.');
      setStatus(s, 'delivered');
    }
    return shipmentJson(s, u);
  });

  const admin = () => { const u = currentUser(); if (!isAdmin(u)) throw new Fail('No autorizado.', 403); return u; };
  on('GET', '/api/admin/drivers', (_, __, query) => {
    admin();
    const status = query.get('status') || 'pending';
    return { items: Object.values(db.drivers).filter(d => d.status === status).sort((a, b) => a.submitted - b.submitted).map(d => {
      const u = db.users.find(x => x.id === d.user_id);
      return { ...d, name: u.name, email: u.email, phone: u.phone, base_city_label: city(d.base_city).label, vehicle_label: C.vehicles[d.vehicle].label,
        documents: d.documents.map(x => ({ label: C.docs[x.kind], url: x.url || '#', pdf: x.pdf })) };
    }) };
  });
  on('POST', '/api/admin/drivers/([a-f0-9]+)', (body, [uid]) => {
    admin();
    const status = { approve: 'approved', reject: 'rejected', suspend: 'suspended' }[body.action];
    const note = String(body.note || '').trim().slice(0, 300);
    if (!status) throw new Fail('Acción no válida.');
    if (status !== 'approved' && !note) throw new Fail('Escribí el motivo para que el chofer sepa qué corregir.');
    if (!db.drivers[uid]) throw new Fail('Chofer no encontrado.', 404);
    Object.assign(db.drivers[uid], { status, review_note: note, reviewed: now() });
    return { ok: true };
  });
  on('GET', '/api/admin/shipments', (_, __, query) => {
    const u = admin();
    const rows = query.get('refunds') === '1' ? db.shipments.filter(s => s.refund_status === 'pending') : db.shipments;
    return { items: [...rows].sort((a, b) => b.created - a.created).map(s => shipmentJson(s, u, true)) };
  });
  on('POST', '/api/admin/shipments/([a-f0-9]+)/refunded', (_, [sid]) => {
    admin(); const s = find(sid);
    if (!s || s.refund_status !== 'pending') throw new Fail('No hay devolución pendiente.', 404);
    s.refund_status = 'done';
    return { ok: true };
  });

  const realFetch = window.fetch.bind(window);
  window.fetch = async (input, opts = {}) => {
    const url = new URL(typeof input === 'string' ? input : input.url, location.href);
    if (!url.pathname.startsWith('/api/')) return realFetch(input, opts);
    const method = (opts.method || 'GET').toUpperCase();
    await new Promise(r => setTimeout(r, 120));  // feel like a network call
    const reply = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
    for (const [m, re, fn] of routes) {
      const match = method === m && url.pathname.match(re);
      if (!match) continue;
      try {
        const body = opts.body instanceof FormData ? opts.body : opts.body ? JSON.parse(opts.body) : {};
        const data = await fn(body, match.slice(1), url.searchParams);
        if (method !== 'GET') save();
        return reply(data, method === 'POST' && /\/api\/(shipments|driver\/apply)$/.test(url.pathname) ? 201 : 200);
      } catch (e) {
        if (e instanceof Fail) return reply({ error: e.message }, e.status);
        console.error(e);
        return reply({ error: 'No se pudo completar la operación. Intentá nuevamente.' }, 503);
      }
    }
    return reply({ error: 'Solicitud no válida.' }, 404);
  };

  // ---------- demo bar: switch between sample accounts ----------
  const ACCOUNTS = [['laura@fleta.demo', 'Cliente'], ['carlos@fleta.demo', 'Chofer verificado'], ['martin@fleta.demo', 'Chofer en revisión'], ['admin@fleta.demo', 'Admin']];
  function demoBar() {
    const bar = document.createElement('div');
    bar.className = 'demobar';
    const label = document.createElement('span');
    label.textContent = 'Modo prueba · Entrar como:';
    bar.append(label);
    const buttons = ACCOUNTS.map(([email, text]) => {
      const b = document.createElement('button');
      b.type = 'button'; b.textContent = text; b.dataset.email = email;
      b.addEventListener('click', () => {
        db.session = db.users.find(u => u.email === email).id; save(); paint();
        location.hash = email.startsWith('admin') ? '#/admin' : email.startsWith('laura') ? '#/' : '#/chofer';
        window.dispatchEvent(new Event('fleta:session'));
      });
      return b;
    });
    const reset = document.createElement('button');
    reset.type = 'button'; reset.className = 'reset'; reset.textContent = 'Reiniciar datos';
    reset.addEventListener('click', () => {
      db = seed(); save(); paint(); location.hash = '#/';
      window.dispatchEvent(new Event('fleta:session'));
    });
    const paint = () => buttons.forEach(b => b.classList.toggle('on', (currentUser() || {}).email === b.dataset.email));
    window.addEventListener('hashchange', paint);
    bar.append(...buttons, reset);
    document.body.prepend(bar);
    paint();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', demoBar); else demoBar();
})();
