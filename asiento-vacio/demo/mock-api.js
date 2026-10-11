'use strict';
// Servidor de mentira para probar Asiento Vacío sin hosting. Atiende los mismos
// pedidos que api.php y con las mismas reglas, pero guarda todo en este navegador.
(() => {
  const KEY = 'asiento-vacio-prueba';
  const ADMIN_CODE = 'PRUEBA2026';
  const PRICE_WORDS = /\$|\b(precios?|tarifas?|pesos|usd|d[oó]lar(es)?)\b/i;
  const REPORT_REASONS = ['precio', 'datos', 'ofensivo', 'otro'];
  const realFetch = window.fetch.bind(window);

  const now = () => Math.floor(Date.now() / 1000);
  const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Argentina/Buenos_Aires' }).format(new Date());
  const addDays = (iso, days) => {
    const date = new Date(`${iso}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
  };
  const newId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
  const clean = (v) => (typeof v === 'string' ? v.replace(/\s+/g, ' ').trim() : '');
  const multiline = (v) => (typeof v === 'string' ? v.replace(/[ \t]+/g, ' ').trim() : '');
  const between = (v, min, max) => [...v].length >= min && [...v].length <= max;

  function seed() {
    const day = today();
    const ts = now();
    const user = (id, name, email, ago) => ({ id, email, name, password: '', is_admin: 0, blocked: 0, created: ts - ago * 86400 });
    const trip = (owner, origin, destination, inDays, hour, seats, driver, phone, vehicle, notes) => ({
      id: newId(), owner, origin, destination, day: addDays(day, inDays), hour, seats, driver, phone,
      phone_digits: phone.replace(/\D/g, ''), vehicle, notes, status: 'active', created: ts - (inDays + 1) * 5400,
    });
    return {
      users: [user('ej-marta', 'Marta', 'marta@ejemplo.com', 20), user('ej-diego', 'Diego', 'diego@ejemplo.com', 9), user('ej-rosa', 'Rosa', 'rosa@ejemplo.com', 3)],
      trips: [
        trip('ej-marta', 'Rosario', 'Buenos Aires', 0, '19:00', 2, 'Marta', '54 9 11 0000 1111', 'Fiat Cronos gris', 'Aviso de ejemplo.'),
        trip('ej-marta', 'González Catán', 'Córdoba', 1, '06:30', 3, 'Marta', '54 9 11 0000 1111', 'Fiat Cronos gris', 'Aviso de ejemplo. Salgo desde la plaza; hay lugar para un bolso por persona.'),
        trip('ej-diego', 'La Plata', 'Mar del Plata', 2, '08:00', 2, 'Diego', '54 9 221 000 2222', 'VW Gol blanco', 'Aviso de ejemplo. Paramos a desayunar en Dolores.'),
        trip('ej-rosa', 'Posadas', 'Encarnación (Paraguay)', 3, '09:15', 1, 'Rosa', '595 981 000 333', '', 'Aviso de ejemplo. Cruzo por el puente San Roque González: llevá tu documento.'),
        trip('ej-diego', 'Mar del Plata', 'La Plata', 5, '17:30', 3, 'Diego', '54 9 221 000 2222', 'VW Gol blanco', 'Aviso de ejemplo.'),
      ],
      reports: [],
      uid: null,
    };
  }

  let db;
  try {
    db = JSON.parse(localStorage.getItem(KEY)) || seed();
  } catch {
    db = seed();
  }
  const save = () => {
    try {
      localStorage.setItem(KEY, JSON.stringify(db));
    } catch {
      // Sin almacenamiento la prueba igual funciona hasta cerrar la página.
    }
  };

  async function digest(text) {
    try {
      const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
      return Array.from(new Uint8Array(bytes), (b) => b.toString(16).padStart(2, '0')).join('');
    } catch {
      return `plain:${text}`;
    }
  }

  const reply = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
  class Failure extends Error {
    constructor(message, status = 400) {
      super(message);
      this.status = status;
    }
  }
  const fail = (message, status) => { throw new Failure(message, status); };

  const userById = (id) => db.users.find((u) => u.id === id) || null;
  const publicUser = (u) => (u ? { id: u.id, email: u.email, name: u.name, admin: u.is_admin === 1 } : null);
  const ownerOf = (trip) => userById(trip.owner) || {};
  const byDayHour = (a, b) => (a.day + a.hour).localeCompare(b.day + b.hour);

  async function handle(action, mine, input) {
    let user = db.uid ? userById(db.uid) : null;
    if (user && user.blocked === 1) {
      db.uid = null;
      user = null;
    }
    const isAdmin = Boolean(user && user.is_admin === 1);
    const serialize = (trip) => {
      const { owner, ...rest } = trip;
      const own = Boolean(user && owner === user.id);
      return { ...rest, mine: own, canDelete: own || isAdmin };
    };
    const ownTrip = () => {
      if (!user) fail('Iniciá sesión.', 401);
      const trip = db.trips.find((t) => t.id === input.id && t.status !== 'deleted');
      if (!trip) fail('Viaje no encontrado.', 404);
      if (trip.owner !== user.id && !(action === 'trip_delete' && isAdmin)) fail('No podés modificar este viaje.', 403);
      return trip;
    };
    if (action.startsWith('admin_') && action !== 'admin_claim' && !isAdmin) {
      fail(user ? 'Esta sección es solo para administración.' : 'Iniciá sesión.', user ? 403 : 401);
    }

    switch (action) {
      case 'session':
        return { csrf: 'prueba', today: today(), user: publicUser(user) };

      case 'logout':
        db.uid = null;
        return { ok: true, csrf: 'prueba' };

      case 'login':
      case 'register': {
        const email = clean(input.email).toLowerCase();
        const password = typeof input.password === 'string' ? input.password : '';
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || !between(password, 10, 128)) {
          fail('Revisá el correo y usá una contraseña de 10 a 128 caracteres.');
        }
        let account = db.users.find((u) => u.email === email);
        if (action === 'register') {
          const name = clean(input.name);
          if (!between(name, 2, 80)) fail('Ingresá tu nombre (2 a 80 caracteres).');
          if (account) fail('Ya existe una cuenta con ese correo. Probá entrar.', 409);
          account = { id: newId(), email, name, password: await digest(password), is_admin: 0, blocked: 0, created: now() };
          db.users.push(account);
        } else {
          if (!account || account.password !== await digest(password)) fail('Correo o contraseña incorrectos.', 401);
          if (account.blocked === 1) fail('Tu cuenta está suspendida.', 403);
        }
        db.uid = account.id;
        return { csrf: 'prueba', user: publicUser(account) };
      }

      case 'trips': {
        if (mine) {
          if (!user) fail('Iniciá sesión.', 401);
          const t = today();
          const list = db.trips.filter((x) => x.owner === user.id && x.status !== 'deleted')
            .sort((a, b) => Number(a.day < t) - Number(b.day < t) || byDayHour(a, b));
          return { items: list.map(serialize) };
        }
        const list = db.trips.filter((x) => x.status === 'active' && x.day >= today() && ownerOf(x).blocked !== 1).sort(byDayHour);
        return { items: list.map(serialize) };
      }

      case 'trip_create': {
        if (!user) fail('Iniciá sesión para publicar un viaje.', 401);
        const v = {};
        for (const k of ['origin', 'destination', 'day', 'hour', 'driver', 'phone', 'vehicle']) v[k] = clean(input[k]);
        v.notes = multiline(input.notes);
        if (input.confirmPrivate !== true || input.confirmPhone !== true) {
          fail('Confirmá que sos un particular que comparte gastos y que aceptás mostrar tu teléfono.');
        }
        if (!between(v.origin, 2, 80) || !between(v.destination, 2, 80)) fail('Indicá desde dónde salís y hacia dónde vas.');
        const parsed = new Date(`${v.day}T12:00:00Z`);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(v.day) || Number.isNaN(parsed.getTime()) || parsed.toISOString().slice(0, 10) !== v.day) {
          fail('Indicá una fecha válida.');
        }
        if (v.day < today() || v.day > addDays(today(), 180)) fail('La fecha tiene que ser de hoy en adelante (hasta 6 meses).');
        if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(v.hour)) fail('Indicá una hora de salida válida.');
        const seats = Number(input.seats);
        if (!Number.isInteger(seats) || seats < 1 || seats > 8) fail('Podés ofrecer de 1 a 8 asientos.');
        if (!between(v.driver, 2, 60)) fail('Ingresá tu nombre como conductor.');
        const digits = v.phone.replace(/\D/g, '');
        if (!/^[0-9+()\-. ]{8,25}$/.test(v.phone) || digits.length < 10 || digits.length > 15) {
          fail('Ingresá un teléfono con código de país y de área, por ejemplo 54 9 11 2345 6789.');
        }
        if ([...v.vehicle].length > 60 || [...v.notes].length > 600) fail('El vehículo o las notas son demasiado largos.');
        if ([v.origin, v.destination, v.vehicle, v.notes].some((text) => PRICE_WORDS.test(text))) {
          fail('No pongas precios ni montos. En Asiento Vacío solo se comparten los gastos de combustible y alguna comida.');
        }
        const trip = { id: newId(), owner: user.id, ...v, seats, phone_digits: digits, status: 'active', created: now() };
        db.trips.push(trip);
        return { id: trip.id };
      }

      case 'trip_update': {
        const trip = ownTrip();
        const status = input.status ?? trip.status;
        const seats = input.seats ?? trip.seats;
        if (!['active', 'full'].includes(status) || !Number.isInteger(seats) || seats < 1 || seats > 8) fail('Datos no válidos.');
        Object.assign(trip, { status, seats });
        return { ok: true };
      }

      case 'trip_delete':
      case 'admin_trip': {
        const trip = action === 'trip_delete' ? ownTrip() : db.trips.find((t) => t.id === input.id);
        if (!trip) fail('Viaje no encontrado.', 404);
        if (action === 'admin_trip' && input.action === 'restore' && trip.status === 'deleted') {
          trip.status = 'active';
        } else if (action === 'trip_delete' || input.action === 'delete') {
          trip.status = 'deleted';
          db.reports.filter((r) => r.trip === trip.id).forEach((r) => { r.status = 'closed'; });
        } else {
          fail('Acción no válida.');
        }
        return { ok: true };
      }

      case 'report': {
        const details = multiline(input.details);
        if (!REPORT_REASONS.includes(input.reason) || [...details].length > 500) fail('Elegí un motivo para la denuncia.');
        const trip = db.trips.find((t) => t.id === input.trip && t.status !== 'deleted');
        if (!trip) fail('Viaje no encontrado.', 404);
        db.reports.push({ id: newId(), trip: trip.id, reason: input.reason, details, status: 'open', created: now() });
        return { ok: true };
      }

      case 'admin_claim': {
        if (!user) fail('Iniciá sesión.', 401);
        if (db.users.some((u) => u.is_admin === 1)) fail('La administración ya está activada. Pedile acceso a un administrador.', 409);
        if (String(input.code || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase() !== ADMIN_CODE) fail('El código no es correcto.', 403);
        user.is_admin = 1;
        return { user: publicUser(user) };
      }

      case 'admin_overview': {
        const weekAgo = now() - 7 * 86400;
        return {
          upcoming: db.trips.filter((t) => t.status === 'active' && t.day >= today() && ownerOf(t).blocked !== 1).length,
          week: db.trips.filter((t) => t.created >= weekAgo).length,
          drivers: db.users.length,
          reports: db.reports.filter((r) => r.status === 'open').length,
        };
      }

      case 'admin_reports':
        return {
          items: db.reports.filter((r) => r.status === 'open').sort((a, b) => b.created - a.created).map((r) => {
            const t = db.trips.find((x) => x.id === r.trip);
            return {
              id: r.id, reason: r.reason, details: r.details, created: r.created, trip: t.id, origin: t.origin,
              destination: t.destination, day: t.day, hour: t.hour, driver: t.driver, phone: t.phone,
              trip_status: t.status, owner_email: ownerOf(t).email,
            };
          }),
        };

      case 'admin_trips':
        return {
          items: [...db.trips].sort((a, b) => b.created - a.created).map((t) => ({
            ...t, owner_email: ownerOf(t).email, owner_blocked: ownerOf(t).blocked,
            reports: db.reports.filter((r) => r.trip === t.id && r.status === 'open').length,
          })),
        };

      case 'admin_users':
        return {
          items: [...db.users].sort((a, b) => b.created - a.created).map((u) => ({
            id: u.id, email: u.email, name: u.name, created: u.created, is_admin: u.is_admin, blocked: u.blocked,
            trips: db.trips.filter((t) => t.owner === u.id && t.status !== 'deleted').length,
          })),
        };

      case 'admin_user': {
        const target = userById(input.id);
        if (!target) fail('Cuenta no encontrada.', 404);
        if (target.id === user.id && ['block', 'remove_admin'].includes(input.action)) fail('No podés hacerte eso a vos mismo.');
        if (input.action === 'block' && target.is_admin === 1) fail('Primero quitale la administración.');
        const changes = { block: ['blocked', 1], unblock: ['blocked', 0], make_admin: ['is_admin', 1], remove_admin: ['is_admin', 0] }[input.action];
        if (!changes) fail('Acción no válida.');
        target[changes[0]] = changes[1];
        return { ok: true };
      }

      case 'admin_report': {
        if (input.action !== 'dismiss') fail('Acción no válida.');
        const report = db.reports.find((r) => r.id === input.id);
        if (report) report.status = 'closed';
        return { ok: true };
      }

      default:
        return fail('Acción no válida.', 404);
    }
  }

  window.fetch = async (resource, options = {}) => {
    const url = typeof resource === 'string' ? resource : resource.url;
    if (!url.startsWith('api.php')) return realFetch(resource, options);
    const params = new URL(url, 'https://prueba.local/').searchParams;
    let input = {};
    try {
      input = options.body ? JSON.parse(options.body) : {};
    } catch {
      input = {};
    }
    await new Promise((resolve) => setTimeout(resolve, 150));
    try {
      const data = await handle(params.get('a') || '', params.get('mine') === '1', input || {});
      save();
      return reply(data, options.method === 'POST' && ['trip_create', 'report'].includes(params.get('a')) ? 201 : 200);
    } catch (error) {
      if (error instanceof Failure) return reply({ error: error.message }, error.status);
      console.error(error);
      return reply({ error: 'No se pudo completar la operación. Intentá nuevamente.' }, 503);
    }
  };

  document.addEventListener('click', async (event) => {
    if (event.target.id !== 'demo-reset') return;
    if (!(await window.ask('¿Borrar todo lo que cargaste y volver a los viajes de ejemplo?', 'Empezar de cero'))) return;
    db = seed();
    save();
    location.hash = 'viajes';
    location.reload();
  });
})();
