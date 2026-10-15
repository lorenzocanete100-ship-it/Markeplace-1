'use strict';
// Demo backend: answers the same /api/* routes as app.py, inside the browser, so the real frontend
// can be tried without a server. Rules mirror app.py; CONFIG is injected by demo/build.py from app.py/cities.py.
(function () {
  const C = window.FLETA_CONFIG;
  const STORE = 'fleta-mudanzas-v1';
  const PASS = 'demo1234567';
  const now = () => Math.floor(Date.now() / 1000);
  const id = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
  const iso = d => { const x = new Date(d); x.setMinutes(x.getMinutes() - x.getTimezoneOffset()); return x.toISOString().slice(0, 10); };
  const today = () => iso(Date.now());
  const plusDays = n => iso(Date.now() + n * 86400000);
  const int = (v, dflt = 0) => (v === undefined || v === null || v === '') ? dflt : (Number.isInteger(Number(v)) ? Number(v) : null);

  // ---------- distances (same formulas as app.py) ----------
  function leg(a, b) {
    const r = Math.PI / 180, lat1 = a[0] * r, lat2 = b[0] * r;
    const h = Math.sin((lat2 - lat1) / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin((b[1] - a[1]) * r / 2) ** 2;
    return 2 * 6371 * Math.asin(Math.sqrt(h)) * C.road_factor;
  }
  const distanceKm = (a, b) => Math.max(3, Math.round(leg([a.lat, a.lon], [b.lat, b.lon])));
  function route(a, b) {
    const foreign = a.country !== 'AR' ? a.country : b.country;
    if (foreign === 'AR') return [a === b ? 5 : distanceKm(a, b), null];
    const [kmTotal, crossing] = C.countries[foreign].crossings
      .map(([name, lat, lon, extra]) => [leg([a.lat, a.lon], [lat, lon]) + leg([lat, lon], [b.lat, b.lon]) + extra, name])
      .sort((x, y) => x[0] - y[0])[0];
    return [Math.round(kmTotal), crossing];
  }
  function validCuil(v) {
    if (!/^(20|23|24|27|30|33|34)\d{9}$/.test(v)) return false;
    const w = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    let check = 11 - w.reduce((s, x, i) => s + x * +v[i], 0) % 11;
    if (check === 11) check = 0;
    return check !== 10 && check === +v[10];
  }
  const validPlate = v => /^([A-Z]{3}\d{3}|[A-Z]{2}\d{3}[A-Z]{2})$/.test(v);

  // ---------- sample documents (drawn, clearly marked as examples) ----------
  function sampleDoc(hue) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200"><rect width="320" height="200" rx="14" fill="hsl(${hue},45%,88%)"/><rect x="18" y="40" width="80" height="100" rx="8" fill="hsl(${hue},30%,70%)"/><rect x="115" y="50" width="170" height="12" rx="6" fill="hsl(${hue},25%,60%)"/><rect x="115" y="75" width="130" height="12" rx="6" fill="hsl(${hue},25%,60%)"/><rect x="115" y="100" width="150" height="12" rx="6" fill="hsl(${hue},25%,60%)"/><text x="160" y="180" text-anchor="middle" font-family="sans-serif" font-size="22" font-weight="800" fill="hsl(${hue},40%,25%)">EJEMPLO</text></svg>`;
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  }
  const sampleDocs = intl => Object.keys({ ...C.docs, ...(intl ? C.intl_docs : {}) }).map((k, i) => ({ kind: k, url: sampleDoc(150 + i * 25), pdf: false }));

  // ---------- pricing ----------
  function quote(p) {
    const origin = C.cities[p.origin], dest = C.cities[p.destination], size = C.sizes[p.size];
    if (!origin || !dest) return [null, 'Elegí origen y destino de la lista.'];
    if (origin.country !== 'AR' && dest.country !== 'AR') return [null, 'La mudanza tiene que salir de Argentina o llegar a Argentina.'];
    if (!size) return [null, 'Elegí el tamaño de la mudanza.'];
    const helpers = int(p.helpers, size.helpers), floors = [int(p.origin_floor), int(p.dest_floor)], declared = int(p.declared_value);
    if (helpers === null || helpers < 0 || helpers > C.max_helpers) return [null, `Elegí de 0 a ${C.max_helpers} ayudantes.`];
    if (floors.some(f => f === null || f < 0 || f > C.max_floor)) return [null, 'Revisá los pisos de origen y destino (0 = planta baja).'];
    if (declared === null || declared < 0 || declared > 100000000) return [null, 'Revisá el valor declarado.'];
    const [km, crossing] = route(origin, dest);
    const scope = crossing ? 'internacional' : (origin.region === 'AMBA' && dest.region === 'AMBA' ? 'amba' : 'nacional');
    const sharedAvailable = scope !== 'amba' && km >= C.shared_min_km;
    const shared = !!p.shared && sharedAvailable;
    const v = C.vehicles[size.vehicle];
    const r = n => Math.round(n * C.price_factor / 100) * 100;
    let transport = v.base + v.km * (Math.min(km, 100) + Math.max(km - 100, 0) * 0.65);
    if (shared) transport *= 1 - C.shared_discount;
    const elevators = [!!p.origin_elevator, !!p.dest_elevator];
    const stairFloors = floors.reduce((t, f, i) => t + (elevators[i] ? 0 : f), 0);
    const foreign = origin.country !== 'AR' ? origin : dest;
    const lines = [
      ['Transporte' + (shared ? ' compartido' : ''), r(transport)],
      ['Ayudantes', r(helpers * C.helper_price * (scope === 'amba' ? 1 : 2))],
      ['Escaleras sin ascensor', r(stairFloors * C.stair_price * Math.max(1, size.m3 / 10))],
      ['Embalaje', p.packing ? r(size.packing) : 0],
      ['Desarme y armado de muebles', p.assembly ? r(size.assembly) : 0],
      ['Gestión aduanera y cruce de frontera', crossing ? r(C.countries[foreign.country].customs_fee) : 0],
      ['Seguro de la carga', p.insurance && declared ? Math.max(1500, Math.round(declared * 0.015)) : 0],
    ];
    const insurance = lines[6][1], customs = lines[5][1];
    const price = lines.reduce((t, [, n]) => t + n, 0);
    return [{ origin: origin.id, destination: dest.id, size: p.size, vehicle: size.vehicle, m3: size.m3, scope, scope_label: C.scopes[scope], crossing,
      distance_km: km, helpers, origin_floor: floors[0], dest_floor: floors[1], origin_elevator: elevators[0], dest_elevator: elevators[1],
      packing: !!p.packing, assembly: !!p.assembly, shared, shared_available: sharedAvailable, declared_value: declared, insurance, customs_fee: customs,
      price, payout: Math.round((price - insurance - customs) * (1 - C.fee_pct / 100)), breakdown: lines }, null];
  }

  // ---------- state ----------
  function createMove(store, u, p, status = 'pending_payment') {
    const [q] = quote(p);
    const code = 'MD-' + Array.from({ length: 6 }, () => C.code_alphabet[Math.floor(Math.random() * C.code_alphabet.length)]).join('');
    const m = { ...q, id: id(), code, client: u.id, driver: null, pickup_date: p.pickup_date, slot: p.slot,
      origin_address: p.origin_address.trim(), dest_address: p.dest_address.trim(), inventory: p.inventory.trim(),
      receiver_name: p.receiver_name.trim(), receiver_phone: String(p.receiver_phone).replace(/\D/g, ''),
      pin: String(Math.floor(Math.random() * 10000)).padStart(4, '0'), status: 'pending_payment', payment_method: null,
      refund_status: '', rating: null, review: null, created: now(), updated: now() };
    delete m.breakdown; delete m.shared_available; delete m.scope_label;
    store.moves.push(m);
    store.events.push({ move: m.id, status: 'pending_payment', at: now() });
    if (status === 'paid') { m.status = 'paid'; m.payment_method = 'mercadopago'; store.events.push({ move: m.id, status: 'paid', at: now() }); }
    return m;
  }
  function seed() {
    const store = { users: [], drivers: {}, moves: [], events: [], session: null, pinTries: {}, passwords: {} };
    const user = (email, name, phone) => { const u = { id: id(), email, name, phone }; store.users.push(u); return u; };
    user('admin@fleta.demo', 'Administración', '1140000000');
    user('laura@fleta.demo', 'Laura Gómez', '1155554444');
    const diego = user('diego@fleta.demo', 'Diego Fernández', '1161112222');
    const carlos = user('carlos@fleta.demo', 'Carlos Ruiz', '1167778888');
    const martin = user('martin@fleta.demo', 'Martín Sosa', '1133339999');
    const driver = (u, status, extra) => {
      store.drivers[u.id] = Object.assign({ user_id: u.id, status, cuil: '20283456780', vehicle: 'camion_grande', vehicle_model: 'Mercedes-Benz Atego 2019',
        plate: 'AE123FG', base_city: 'caballito-caba', long_distance: 1, international: 1, license_expiry: plusDays(400), insurance_expiry: plusDays(200),
        documents: sampleDocs(true), review_note: '', submitted: now() - 7200, rating_sum: 58, rating_count: 12 }, extra);
    };
    driver(carlos, 'approved', {});
    driver(martin, 'pending', { cuil: '20352223334', vehicle: 'camioneta', vehicle_model: 'Renault Master 2018', plate: 'AC456HJ',
      base_city: 'moron-gba-oeste', long_distance: 0, international: 0, documents: sampleDocs(false), rating_sum: 0, rating_count: 0 });
    // Paid moves from another client, waiting for a driver: one of each kind.
    const base = { helpers: undefined, packing: false, assembly: false, shared: false, declared_value: 0, insurance: false, slot: 'am', receiver_name: 'Sofía Fernández' };
    createMove(store, diego, { ...base, size: 'two', origin: 'moron-gba-oeste', destination: 'tigre-gba-norte', origin_floor: 2, dest_floor: 0,
      pickup_date: plusDays(2), origin_address: 'Rivadavia 18200 2B', dest_address: 'Av. Cazón 1200', receiver_phone: '1145556666',
      inventory: 'Living completo, cama de 2 plazas, heladera, lavarropas y 15 cajas.' }, 'paid');
    createMove(store, diego, { ...base, size: 'mono', origin: 'caballito-caba', destination: 'mar-del-plata-buenos-aires', origin_floor: 5, origin_elevator: true,
      dest_floor: 1, shared: true, pickup_date: plusDays(5), origin_address: 'Rosario 650 5C', dest_address: 'Av. Colón 2450 1A', receiver_phone: '2235551234',
      inventory: 'Cama, placard desarmado, heladera chica, escritorio y 8 cajas.' }, 'paid');
    createMove(store, diego, { ...base, size: 'three', origin: 'palermo-caba', destination: 'montevideo-uruguay', origin_floor: 0, dest_floor: 0, packing: true,
      pickup_date: plusDays(10), origin_address: 'Gorriti 4800', dest_address: 'Bulevar Artigas 1100', receiver_phone: '59899123456',
      inventory: '1 heladera\n1 lavarropas\n2 camas (2 plazas y 1 plaza)\n1 mesa con 6 sillas\n1 sillón de 3 cuerpos\n25 cajas de ropa, libros y vajilla' }, 'paid');
    return store;
  }
  let db;
  try { db = JSON.parse(localStorage.getItem(STORE)); } catch { db = null; }
  if (!db || !db.users) db = seed();
  const save = () => { try { localStorage.setItem(STORE, JSON.stringify(db)); } catch { /* demo still works in memory */ } };
  save();

  const currentUser = () => db.users.find(u => u.id === db.session) || null;
  const isAdmin = u => !!u && u.email === 'admin@fleta.demo';
  const city = cid => C.cities[cid];
  const find = mid => db.moves.find(m => m.id === mid);
  const eventsOf = mid => db.events.filter(e => e.move === mid).map(({ status, at }) => ({ status, at }));
  function setStatus(m, status, extra = {}) {
    Object.assign(m, extra, { status, updated: now() });
    db.events.push({ move: m.id, status, at: now() });
  }
  function driverPublic(uid) {
    const d = db.drivers[uid], u = db.users.find(x => x.id === uid);
    return { name: u.name, phone: u.phone, vehicle: C.vehicles[d.vehicle].label, vehicle_model: d.vehicle_model, plate: d.plate,
      verified: d.status === 'approved', international: !!d.international,
      rating: d.rating_count ? Math.round(d.rating_sum / d.rating_count * 10) / 10 : null, rating_count: d.rating_count };
  }
  function moveJson(m, viewer, admin = false) {
    const isClient = viewer && m.client === viewer.id, isDriver = viewer && m.driver === viewer.id;
    const out = {};
    for (const k of ['id', 'code', 'size', 'vehicle', 'scope', 'crossing', 'origin', 'destination', 'pickup_date', 'slot', 'helpers',
      'origin_floor', 'dest_floor', 'distance_km', 'status', 'created', 'origin_elevator', 'dest_elevator', 'packing', 'assembly', 'shared']) out[k] = m[k];
    Object.assign(out, { origin_label: city(m.origin).label, destination_label: city(m.destination).label, status_label: C.status[m.status],
      slot_label: C.slots[m.slot], vehicle_label: C.vehicles[m.vehicle].label, size_label: C.sizes[m.size].label, scope_label: C.scopes[m.scope], m3: C.sizes[m.size].m3 });
    if (isClient || admin) {
      Object.assign(out, { price: m.price, insurance: m.insurance, customs_fee: m.customs_fee, declared_value: m.declared_value, payment_method: m.payment_method,
        refund_status: m.refund_status, rating: m.rating, review: m.review, mine: !!isClient });
      if (!['delivered', 'cancelled'].includes(m.status)) out.pin = m.pin;
    }
    if (isDriver || admin) out.payout = m.payout;
    if (isClient || isDriver || admin) {
      Object.assign(out, { origin_address: m.origin_address, dest_address: m.dest_address, inventory: m.inventory, receiver_name: m.receiver_name, receiver_phone: m.receiver_phone });
      out.events = eventsOf(m.id);
    }
    if (m.driver && (isClient || admin)) out.driver = driverPublic(m.driver);
    if (isDriver || admin) { const c = db.users.find(u => u.id === m.client); out.client = { name: c.name, phone: c.phone }; }
    return out;
  }
  function driverProblems(d) {
    const p = [];
    if (d.license_expiry < today()) p.push('Tu licencia está vencida. Volvé a enviar la documentación.');
    if (d.insurance_expiry < today()) p.push('Tu seguro está vencido. Volvé a enviar la documentación.');
    return p;
  }
  function canTake(d, m) {
    if (C.vehicles[m.vehicle].rank > C.vehicles[d.vehicle].rank) return false;
    if (m.scope === 'internacional') return !!d.international;
    if (!d.long_distance) return distanceKm(city(d.base_city), city(m.origin)) <= 80 && m.distance_km <= 80;
    return true;
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
  const need = cond => { if (!cond) throw new Fail('Mudanza no encontrada.', 404); };
  const routes = [];
  const on = (method, pattern, fn) => routes.push([method, new RegExp('^' + pattern + '$'), fn]);
  const sessionInfo = () => {
    const u = currentUser();
    return { csrf: 'demo', demo_payments: true, user: u && { ...u, admin: isAdmin(u), driver_status: db.drivers[u.id] ? db.drivers[u.id].status : null } };
  };

  on('GET', '/api/session', sessionInfo);
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
      db.passwords[u.id] = body.password;
    } else if (!u || (db.passwords[u.id] || PASS) !== body.password) throw new Fail('Correo o contraseña incorrectos.', 401);
    db.session = u.id;
    return sessionInfo();
  });
  on('GET', '/api/catalog', () => ({
    cities: C.city_list, vehicles: Object.entries(C.vehicles).map(([k, v]) => ({ id: k, label: v.label, m3: v.m3 })),
    sizes: Object.entries(C.sizes).map(([k, s]) => ({ id: k, label: s.label, hint: s.hint, m3: s.m3, helpers: s.helpers })),
    countries: Object.fromEntries(Object.entries(C.countries).map(([k, c]) => [k, c.name])),
    slots: C.slots, docs: C.docs, intl_docs: C.intl_docs, helper_price: C.helper_price, max_helpers: C.max_helpers,
    shared_discount: C.shared_discount, shared_min_km: C.shared_min_km }));
  on('POST', '/api/quote', body => { const [q, e] = quote(body); if (e) throw new Fail(e); return q; });

  on('POST', '/api/moves', body => {
    const u = currentUser(); if (!u) throw new Fail('Iniciá sesión para pedir la mudanza.', 401);
    const [q, e] = quote(body); if (e) throw new Fail(e);
    const d = body.pickup_date;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d || '') || d < today() || d > plusDays(180)) throw new Fail('Elegí una fecha de mudanza entre hoy y los próximos 6 meses.');
    if (!C.slots[body.slot]) throw new Fail('Elegí la franja horaria.');
    const len = k => String(body[k] || '').trim().length;
    if (len('origin_address') < 5 || len('dest_address') < 5) throw new Fail('Completá las direcciones de origen y destino (calle y número).');
    const intl = q.scope === 'internacional';
    if (len('inventory') < (intl ? 30 : 5)) throw new Fail(intl ? 'Para cruzar la frontera necesitamos el inventario detallado (mínimo 30 caracteres).' : 'Contanos qué hay que llevar (mínimo 5 caracteres).');
    if (len('receiver_name') < 2 || !/^\d{8,15}$/.test(String(body.receiver_phone || '').replace(/\D/g, ''))) throw new Fail('Completá nombre y celular de quien recibe en destino.');
    const m = createMove(db, u, body);
    return { id: m.id, code: m.code };
  });
  on('GET', '/api/moves', () => {
    const u = currentUser(); if (!u) throw new Fail('Iniciá sesión.', 401);
    return { items: db.moves.filter(m => m.client === u.id).sort((a, b) => b.created - a.created).map(m => moveJson(m, u)) };
  });
  on('GET', '/api/moves/([a-f0-9]+)', (_, [mid]) => {
    const u = currentUser(), m = find(mid);
    need(u && m && (u.id === m.client || u.id === m.driver || isAdmin(u)));
    return moveJson(m, u, isAdmin(u));
  });
  on('GET', '/api/track/([^/]+)', (_, [code]) => {
    const m = db.moves.find(x => x.code === decodeURIComponent(code).trim().toUpperCase());
    if (!m) throw new Fail('No encontramos una mudanza con ese código.', 404);
    return { code: m.code, status: m.status, status_label: C.status[m.status], scope_label: C.scopes[m.scope], origin_label: city(m.origin).label,
      destination_label: city(m.destination).label, crossing: m.crossing, pickup_date: m.pickup_date, events: eventsOf(m.id) };
  });
  const own = mid => { const u = currentUser(), m = find(mid); need(u && m && m.client === u.id); return [u, m]; };
  on('POST', '/api/moves/([a-f0-9]+)/cancel', (_, [mid]) => {
    const [, m] = own(mid);
    if (!['pending_payment', 'paid', 'assigned'].includes(m.status)) throw new Fail('Esta mudanza ya no se puede cancelar.');
    const refund = m.status !== 'pending_payment' ? 'pending' : '';
    setStatus(m, 'cancelled', { refund_status: refund });
    return { ok: true, refund: !!refund };
  });
  on('POST', '/api/moves/([a-f0-9]+)/rate', (body, [mid]) => {
    const [, m] = own(mid);
    if (m.status !== 'delivered' || m.rating) throw new Fail('Podés calificar una vez, cuando la mudanza terminó.');
    if (![1, 2, 3, 4, 5].includes(body.stars)) throw new Fail('Elegí de 1 a 5 estrellas.');
    m.rating = body.stars; m.review = String(body.review || '').trim().slice(0, 500);
    const d = db.drivers[m.driver]; d.rating_sum += body.stars; d.rating_count += 1;
    return { ok: true };
  });
  on('POST', '/api/moves/([a-f0-9]+)/pay', (body, [mid]) => {
    const [, m] = own(mid);
    if (m.status !== 'pending_payment') throw new Fail('Esta mudanza ya fue pagada o cancelada.');
    if (!['mercadopago', 'card'].includes(body.method)) throw new Fail('Elegí cómo pagar.');
    return { demo: true };
  });
  on('POST', '/api/moves/([a-f0-9]+)/demo-pay', (body, [mid]) => {
    const [, m] = own(mid);
    need(m.status === 'pending_payment');
    setStatus(m, 'paid', { payment_method: body.method === 'card' ? 'card' : 'mercadopago' });
    return { ok: true };
  });

  on('GET', '/api/driver/me', () => {
    const u = currentUser(); if (!u) throw new Fail('Iniciá sesión.', 401);
    const d = db.drivers[u.id]; if (!d) return { driver: null };
    const done = db.moves.filter(m => m.driver === u.id && m.status === 'delivered');
    return { driver: { status: d.status, vehicle: d.vehicle, vehicle_model: d.vehicle_model, plate: d.plate, base_city: d.base_city,
      long_distance: d.long_distance, international: d.international, license_expiry: d.license_expiry, insurance_expiry: d.insurance_expiry,
      review_note: d.review_note, rating_count: d.rating_count, rating: d.rating_count ? Math.round(d.rating_sum / d.rating_count * 10) / 10 : null,
      problems: d.status === 'approved' ? driverProblems(d) : [], base_city_label: city(d.base_city).label,
      earned: done.reduce((t, m) => t + m.payout, 0), trips: done.length } };
  });
  on('POST', '/api/driver/apply', async (form) => {
    const u = currentUser(); if (!u) throw new Fail('Iniciá sesión.', 401);
    const prev = db.drivers[u.id];
    if (prev && prev.status === 'suspended') throw new Fail('Tu cuenta de chofer está suspendida. Escribinos para revisarla.', 403);
    const f = k => String(form.get(k) || '');
    const cuil = f('cuil').replace(/\D/g, ''), plate = f('plate').replace(/[\s-]/g, '').toUpperCase();
    const international = f('international') === '1';
    if (!validCuil(cuil)) throw new Fail('El CUIL no es válido. Revisá los 11 números.');
    if (!C.vehicles[f('vehicle')] || f('vehicle_model').trim().length < 2) throw new Fail('Indicá tipo, marca y modelo del vehículo.');
    if (!validPlate(plate)) throw new Fail('La patente no es válida (ej. AB123CD o ABC123).');
    if (!C.cities[f('base_city')] || C.cities[f('base_city')].country !== 'AR') throw new Fail('Elegí tu ciudad base en Argentina.');
    if (!(f('license_expiry') > today()) || !(f('insurance_expiry') > today())) throw new Fail('La licencia y el seguro tienen que estar vigentes.');
    const required = { ...C.docs, ...(international ? C.intl_docs : {}) };
    const missing = Object.entries(required).filter(([k]) => !(form.get(k) instanceof File) || !form.get(k).size).map(([, l]) => l);
    if (missing.length) throw new Fail('Falta subir: ' + missing.join(', ') + '.');
    const documents = [];
    for (const k of Object.keys(required)) {
      const file = form.get(k);
      if (file.type === 'application/pdf' && !C.pdf_docs.includes(k)) throw new Fail(`${required[k]}: subí un archivo JPG, PNG o WebP válido.`);
      const doc = await shrink(file);
      if (!doc) throw new Fail(`${required[k]}: subí un archivo de imagen válido.`);
      documents.push({ kind: k, ...doc });
    }
    db.drivers[u.id] = { user_id: u.id, status: 'pending', cuil, vehicle: f('vehicle'), vehicle_model: f('vehicle_model').trim(), plate, base_city: f('base_city'),
      long_distance: international || f('long_distance') === '1' ? 1 : 0, international: international ? 1 : 0,
      license_expiry: f('license_expiry'), insurance_expiry: f('insurance_expiry'), documents, review_note: '', submitted: now(),
      rating_sum: prev ? prev.rating_sum : 0, rating_count: prev ? prev.rating_count : 0 };
    return { ok: true, status: 'pending' };
  });
  const approved = () => { const u = currentUser(), d = u && db.drivers[u.id]; if (!d || d.status !== 'approved') throw new Fail('Tu cuenta de chofer todavía no está verificada.', 403); return [u, d]; };
  on('GET', '/api/driver/jobs', () => {
    const [u, d] = approved();
    const base = city(d.base_city);
    const items = db.moves.filter(m => m.status === 'paid' && !m.driver && m.pickup_date >= today() && m.client !== u.id && canTake(d, m))
      .map(m => ({ ...moveJson(m, null), payout: m.payout, km_from_base: base === city(m.origin) ? 0 : distanceKm(base, city(m.origin)) }))
      .sort((a, b) => a.pickup_date.localeCompare(b.pickup_date) || a.km_from_base - b.km_from_base);
    return { items, problems: driverProblems(d) };
  });
  on('GET', '/api/driver/trips', () => {
    const u = currentUser(); if (!u) throw new Fail('Iniciá sesión.', 401);
    return { items: db.moves.filter(m => m.driver === u.id).sort((a, b) => b.pickup_date.localeCompare(a.pickup_date)).map(m => moveJson(m, u)) };
  });
  on('POST', '/api/moves/([a-f0-9]+)/(accept|release|pickup|deliver)', (body, [mid, action]) => {
    const m = find(mid); need(m);
    const [u, d] = approved();
    if (action === 'accept') {
      const p = driverProblems(d); if (p.length) throw new Fail(p[0], 403);
      if (m.client === u.id || !canTake(d, m)) throw new Fail('Esta mudanza no es compatible con tu vehículo o tu cobertura.', 403);
      if (db.moves.filter(x => x.driver === u.id && ['assigned', 'in_transit'].includes(x.status)).length >= C.active_trips_max) throw new Fail(`Podés tener hasta ${C.active_trips_max} mudanzas activas.`);
      if (m.status !== 'paid' || m.driver) throw new Fail('Otro chofer ya tomó esta mudanza.', 409);
      setStatus(m, 'assigned', { driver: u.id });
      return moveJson(m, u);
    }
    if (m.driver !== u.id) throw new Fail('Esta mudanza no es tuya.', 403);
    if (action === 'release') { if (m.status !== 'assigned') throw new Fail('Solo podés liberar una mudanza antes de cargar.'); setStatus(m, 'paid', { driver: null }); }
    else if (action === 'pickup') { if (m.status !== 'assigned') throw new Fail('La mudanza no está lista para cargar.'); setStatus(m, 'in_transit'); }
    else {
      if (m.status !== 'in_transit') throw new Fail('Primero marcá que cargaste la mudanza.');
      db.pinTries[mid] = (db.pinTries[mid] || 0) + 1;
      if (db.pinTries[mid] > 5) throw new Fail('Demasiados intentos con el código. Esperá una hora o contactá a soporte.', 429);
      if (String(body.pin || '') !== m.pin) throw new Fail('El código de entrega no coincide. Pedíselo a quien recibe.');
      setStatus(m, 'delivered');
    }
    return moveJson(m, u);
  });

  const admin = () => { const u = currentUser(); if (!isAdmin(u)) throw new Fail('No autorizado.', 403); return u; };
  const allDocs = { ...C.docs, ...C.intl_docs };
  on('GET', '/api/admin/drivers', (_, __, query) => {
    admin();
    const status = query.get('status') || 'pending';
    return { items: Object.values(db.drivers).filter(d => d.status === status).sort((a, b) => a.submitted - b.submitted).map(d => {
      const u = db.users.find(x => x.id === d.user_id);
      return { ...d, name: u.name, email: u.email, phone: u.phone, base_city_label: city(d.base_city).label, vehicle_label: C.vehicles[d.vehicle].label,
        documents: d.documents.map(x => ({ label: allDocs[x.kind], url: x.url || '#', pdf: x.pdf })) };
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
  on('GET', '/api/admin/moves', (_, __, query) => {
    const u = admin();
    const rows = query.get('refunds') === '1' ? db.moves.filter(m => m.refund_status === 'pending') : db.moves;
    return { items: [...rows].sort((a, b) => b.created - a.created).map(m => moveJson(m, u, true)) };
  });
  on('POST', '/api/admin/moves/([a-f0-9]+)/refunded', (_, [mid]) => {
    admin(); const m = find(mid);
    if (!m || m.refund_status !== 'pending') throw new Fail('No hay devolución pendiente.', 404);
    m.refund_status = 'done';
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
        return reply(data, method === 'POST' && /\/api\/(moves|driver\/apply)$/.test(url.pathname) ? 201 : 200);
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
