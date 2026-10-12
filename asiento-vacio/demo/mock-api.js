'use strict';
// Servidor de mentira para probar Asiento Vacío sin hosting. Atiende los mismos
// pedidos que api.php y con las mismas reglas, pero guarda todo en este navegador.
(() => {
  const KEY = 'asiento-vacio-prueba-4';
  // Versiones anteriores de la prueba (algunas con fotos de DNI o contraseñas): se borran del navegador.
  try {
    ['asiento-vacio-prueba', 'asiento-vacio-prueba-2', 'asiento-vacio-prueba-3'].forEach((old) => localStorage.removeItem(old));
  } catch {
    // Sin almacenamiento no hay nada que borrar.
  }
  const ADMIN_CODE = 'PRUEBA2026';
  // Cuentas con las que deja entrar el botón de Google simulado.
  const GOOGLE_ACCOUNTS = [
    ['nueva', 'Una cuenta nueva (la tuya)'],
    ['ej-marta', 'Marta, conductora de ejemplo'],
    ['ej-lucia', 'Lucía, acompañante de ejemplo'],
    ['ej-diego', 'Diego, conductor de ejemplo'],
  ];
  const RATING_ROLES = ['driver', 'rider'];
  const PRICE_WORDS = /\$|\b(precios?|tarifas?|pesos|usd|d[oó]lar(es)?)\b/i;
  const REPORT_REASONS = ['precio', 'datos', 'ofensivo', 'otro'];
  const SUSPEND_DAYS = [1, 7, 30, 0];
  const SUSPEND_REASONS = {
    precio: 'Cobró un precio por el viaje o lo usó como negocio.',
    datos: 'Publicó datos falsos.',
    conducta: 'Tuvo una conducta ofensiva o peligrosa.',
    denuncias: 'Recibió varias denuncias.',
    otro: 'No cumplió las reglas del sitio.',
  };
  const GOOGLE_CLIENT_ID = /^[0-9]{5,30}-[a-z0-9]{8,64}\.apps\.googleusercontent\.com$/;
  const MAX_COMMENT = 300;
  const AR = { timeZone: 'America/Argentina/Buenos_Aires' };
  const untilText = (seconds) => {
    const date = new Date(seconds * 1000);
    const day = new Intl.DateTimeFormat('es-AR', { ...AR, day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
    const time = new Intl.DateTimeFormat('es-AR', { ...AR, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(date);
    return ` hasta el ${day} a las ${time}`;
  };
  const realFetch = window.fetch.bind(window);

  const now = () => Math.floor(Date.now() / 1000);
  const today = () => new Intl.DateTimeFormat('en-CA', AR).format(new Date());
  const addDays = (iso, days) => {
    const date = new Date(`${iso}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
  };
  const newId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
  const clean = (v) => (typeof v === 'string' ? v.replace(/\s+/g, ' ').trim() : '');
  const multiline = (v) => (typeof v === 'string' ? v.replace(/[ \t]+/g, ' ').trim() : '');
  const between = (v, min, max) => [...v].length >= min && [...v].length <= max;

  // Fotos de ejemplo dibujadas acá mismo (una silueta sobre un fondo de color).
  function drawnPhoto(background, skin, shirt) {
    const canvas = Object.assign(document.createElement('canvas'), { width: 160, height: 160 });
    const ctx = canvas.getContext('2d');
    if (!ctx) return '';
    ctx.fillStyle = background;
    ctx.fillRect(0, 0, 160, 160);
    ctx.fillStyle = shirt;
    ctx.beginPath();
    ctx.ellipse(80, 168, 62, 48, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = skin;
    ctx.beginPath();
    ctx.arc(80, 66, 32, 0, Math.PI * 2);
    ctx.fill();
    return canvas.toDataURL('image/jpeg', 0.85);
  }

  // En la prueba no hay fotos reales de Google: se dibuja una de ejemplo.
  const googlePhoto = () => drawnPhoto('#d9f1e4', '#d9a37f', '#0b6e4f');

  function seed() {
    const day = today();
    const ts = now();
    const user = (id, name, email, ago) => ({
      id, email, name, google_sub: `prueba-${id}`, google_picture: '', photo: '', photo_off: 0, google_link_ok: 0,
      is_admin: 0, blocked: 0, blocked_until: 0, blocked_reason: '', created: ts - ago * 86400,
    });
    const trip = (owner, origin, destination, inDays, hour, seats, driver, phone, vehicle, notes) => ({
      id: newId(), owner, origin, destination, day: addDays(day, inDays), hour, seats, driver, phone,
      phone_digits: phone.replace(/\D/g, ''), vehicle, notes, status: 'active', created: ts - (inDays + 1) * 5400,
    });
    const rating = (rater, rated, role, stars, comment, ago) => ({
      id: newId(), rater, rated, role, stars, comment, created: ts - ago * 86400, updated: ts - ago * 86400,
    });
    return {
      settings: { googleClientId: '1234567890-pruebaasiento.apps.googleusercontent.com' },
      users: [
        { ...user('ej-marta', 'Marta', 'marta@ejemplo.com', 20), google_picture: 'prueba', photo: drawnPhoto('#cfe0fb', '#e8b48f', '#1f55c7') },
        user('ej-diego', 'Diego', 'diego@ejemplo.com', 9),
        // Cuenta de una versión anterior, con contraseña: para ver cómo la administración la deja pasar a Google.
        { ...user('ej-rosa', 'Rosa', 'rosa@ejemplo.com', 3), google_sub: '' },
        { ...user('ej-lucia', 'Lucía', 'lucia@ejemplo.com', 15), google_picture: 'prueba', photo: drawnPhoto('#fde7c2', '#c98e6b', '#b42318') },
        user('ej-pablo', 'Pablo', 'pablo@ejemplo.com', 12),
      ],
      trips: [
        trip('ej-marta', 'Rosario', 'Buenos Aires', 0, '19:00', 2, 'Marta', '54 9 11 0000 1111', 'Fiat Cronos gris', 'Aviso de ejemplo.'),
        trip('ej-marta', 'González Catán', 'Córdoba', 1, '06:30', 3, 'Marta', '54 9 11 0000 1111', 'Fiat Cronos gris', 'Aviso de ejemplo. Salgo desde la plaza; hay lugar para un bolso por persona.'),
        trip('ej-diego', 'La Plata', 'Mar del Plata', 2, '08:00', 2, 'Diego', '54 9 221 000 2222', 'VW Gol blanco', 'Aviso de ejemplo. Paramos a desayunar en Dolores.'),
        trip('ej-rosa', 'Posadas', 'Encarnación (Paraguay)', 3, '09:15', 1, 'Rosa', '595 981 000 333', '', 'Aviso de ejemplo. Cruzo por el puente San Roque González: llevá tu documento.'),
        trip('ej-diego', 'Mar del Plata', 'La Plata', 5, '17:30', 3, 'Diego', '54 9 221 000 2222', 'VW Gol blanco', 'Aviso de ejemplo.'),
      ],
      ratings: [
        rating('ej-lucia', 'ej-marta', 'driver', 5, 'Súper puntual y muy amable. (Opinión de ejemplo)', 10),
        rating('ej-pablo', 'ej-marta', 'driver', 5, 'Viaje tranquilo, maneja con cuidado. (Opinión de ejemplo)', 6),
        rating('ej-diego', 'ej-marta', 'driver', 4, '', 4),
        rating('ej-lucia', 'ej-diego', 'driver', 4, 'Buena onda, paramos a desayunar. (Opinión de ejemplo)', 5),
        rating('ej-pablo', 'ej-diego', 'driver', 2, 'Llegó 40 minutos tarde y no avisó. (Opinión de ejemplo)', 2),
        rating('ej-marta', 'ej-lucia', 'rider', 5, 'Muy buena compañera de viaje. (Opinión de ejemplo)', 8),
        rating('ej-diego', 'ej-pablo', 'rider', 5, 'Puntual en el punto de encuentro. (Opinión de ejemplo)', 3),
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

  const reply = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
  class Failure extends Error {
    constructor(message, status = 400) {
      super(message);
      this.status = status;
    }
  }
  const fail = (message, status) => { throw new Failure(message, status); };

  const userById = (id) => db.users.find((u) => u.id === id) || null;
  const ownerOf = (trip) => userById(trip.owner) || {};
  const byDayHour = (a, b) => (a.day + a.hour).localeCompare(b.day + b.hour);
  const publicUser = (u) => (u ? {
    id: u.id, email: u.email, name: u.name, created: u.created, admin: u.is_admin === 1, google: Boolean(u.google_sub),
    photo: u.photo || null, googlePicture: Boolean(u.google_picture),
  } : null);
  const suspended = (account) => fail(`Tu cuenta está suspendida${account.blocked_until ? untilText(account.blocked_until) : ''}.`
    + (account.blocked_reason ? ` Motivo: ${account.blocked_reason}` : ''), 403);
  // Calificaciones que cuentan (no las de cuentas suspendidas): todas, o solo como conductor o como acompañante.
  const ratingsOf = (id, role = '') => db.ratings.filter((r) => r.rated === id && userById(r.rater)?.blocked !== 1 && (!role || r.role === role));
  const average = (list) => (list.length ? Math.round((list.reduce((sum, r) => sum + r.stars, 0) / list.length) * 10) / 10 : null);
  const pair = (list) => ({ rating: average(list), count: list.length });
  // La foto sale sola de la cuenta de Google, salvo que la hayan quitado.
  const googlePhotoFor = (account) => {
    if (!account.photo && !account.photo_off && account.google_picture) account.photo = googlePhoto();
  };

  async function handle(method, action, params, input) {
    const mine = params.get('mine') === '1';
    // Las suspensiones con plazo se levantan solas cuando se cumple la fecha.
    db.users.filter((u) => u.blocked === 1 && u.blocked_until && u.blocked_until <= now())
      .forEach((u) => Object.assign(u, { blocked: 0, blocked_until: 0, blocked_reason: '' }));
    let user = db.uid ? userById(db.uid) : null;
    if (user && user.blocked === 1) {
      db.uid = null;
      user = null;
    }
    const isAdmin = Boolean(user && user.is_admin === 1);
    const serialize = (trip) => {
      const { owner, ...rest } = trip;
      const own = Boolean(user && owner === user.id);
      const list = ratingsOf(owner, 'driver');
      return { ...rest, driver: ownerOf(trip).name, driver_id: owner, driver_photo: ownerOf(trip).photo || null, driver_rating: average(list), driver_ratings: list.length,
        mine: own, canDelete: own || isAdmin };
    };
    const requireUser = () => user || fail('Iniciá sesión.', 401);
    const ownTrip = () => {
      if (!user) fail('Iniciá sesión.', 401);
      const trip = db.trips.find((t) => t.id === input.id && t.status !== 'deleted');
      if (!trip) fail('Viaje no encontrado.', 404);
      if (trip.owner !== user.id && !(action === 'trip_delete' && isAdmin)) fail('No podés modificar este viaje.', 403);
      return trip;
    };
    const signIn = (account, isNew = false) => {
      db.uid = account.id;
      return { csrf: 'prueba', user: publicUser(account), new: isNew };
    };
    if (action.startsWith('admin_') && action !== 'admin_claim' && !isAdmin) {
      fail(user ? 'Esta sección es solo para administración.' : 'Iniciá sesión.', user ? 403 : 401);
    }

    switch (action) {
      case 'session':
        return { csrf: 'prueba', today: today(), user: publicUser(user), googleClientId: db.settings.googleClientId };

      case 'logout':
        db.uid = null;
        return { ok: true, csrf: 'prueba' };

      case 'login':
      case 'register':
        return fail('Ahora se entra solo con la cuenta de Google.', 410);

      case 'setup': {
        if (String(input.code || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase() !== ADMIN_CODE) fail('El código no es correcto.', 403);
        const clientId = clean(input.googleClientId);
        if (!GOOGLE_CLIENT_ID.test(clientId)) fail('Ese no parece un ID de cliente de Google: tiene que terminar en .apps.googleusercontent.com.');
        db.settings.googleClientId = clientId;
        return { googleClientId: clientId };
      }

      case 'google': {
        // En la prueba el botón de Google es simulado: deja entrar con una cuenta nueva o con una de ejemplo.
        if (!db.settings.googleClientId) fail('El ingreso con Google no está activado.', 404);
        const who = /^prueba:([\w-]+)$/.exec(String(input.credential || ''))?.[1];
        if (!GOOGLE_ACCOUNTS.some(([id]) => id === who)) fail('No pudimos confirmar tu cuenta de Google. Probá de nuevo.', 401);
        let account = who === 'nueva' ? db.users.find((u) => u.google_sub === 'prueba-google') : userById(who);
        if (account) {
          if (account.blocked === 1) suspended(account);
          googlePhotoFor(account);
          return signIn(account);
        }
        account = { id: newId(), email: 'google.prueba@ejemplo.com', name: 'Cuenta de Google (prueba)', google_sub: 'prueba-google',
          google_picture: 'prueba', photo: '', photo_off: 0, google_link_ok: 0, is_admin: 0, blocked: 0, blocked_until: 0, blocked_reason: '', created: now() };
        googlePhotoFor(account);
        db.users.push(account);
        return signIn(account, true);
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
        for (const k of ['origin', 'destination', 'day', 'hour', 'phone', 'vehicle']) v[k] = clean(input[k]);
        v.notes = multiline(input.notes);
        v.driver = user.name;
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

      case 'profile': {
        const profile = userById(params.get('id') || '');
        if (!profile || profile.blocked === 1) fail('Perfil no encontrado.', 404);
        const list = ratingsOf(profile.id);
        const reviews = [...list].sort((a, b) => b.updated - a.updated).slice(0, 50).map((r) => ({
          stars: r.stars, comment: r.comment, role: r.role, updated: r.updated, rater_id: r.rater, rater_name: userById(r.rater).name,
          rater_photo: userById(r.rater).photo || null,
        }));
        const own = user ? db.ratings.find((r) => r.rater === user.id && r.rated === profile.id) : null;
        const trips = db.trips.filter((t) => t.owner === profile.id && t.status === 'active' && t.day >= today()).sort(byDayHour);
        return {
          profile: {
            id: profile.id, name: profile.name, created: profile.created, photo: profile.photo || null, rating: average(list), ratings: list.length,
            asDriver: pair(ratingsOf(profile.id, 'driver')), asRider: pair(ratingsOf(profile.id, 'rider')),
          },
          reviews,
          mine: own ? { stars: own.stars, comment: own.comment, role: own.role } : null,
          trips: trips.map(serialize),
        };
      }

      case 'rate': {
        const me = requireUser();
        const target = userById(input.user);
        if (!target || target.blocked === 1) fail('Perfil no encontrado.', 404);
        if (target.id === me.id) fail('No podés calificarte a vos mismo.');
        if (!Number.isInteger(input.stars) || input.stars < 1 || input.stars > 5) fail('Elegí de 1 a 5 estrellas.');
        if (!RATING_ROLES.includes(input.role)) fail('Elegí si esta persona manejaba o si viajó como acompañante.');
        const comment = clean(input.comment);
        if ([...comment].length > MAX_COMMENT) fail(`El comentario puede tener hasta ${MAX_COMMENT} letras.`);
        const existing = db.ratings.find((r) => r.rater === me.id && r.rated === target.id);
        if (existing) {
          Object.assign(existing, { stars: input.stars, role: input.role, comment, updated: now() });
        } else {
          db.ratings.push({ id: newId(), rater: me.id, rated: target.id, role: input.role, stars: input.stars, comment, created: now(), updated: now() });
        }
        return { ok: true };
      }

      case 'photo_google': {
        const me = requireUser();
        if (!me.google_picture) fail('Tu cuenta de Google no tiene foto.', 404);
        me.photo_off = 0;
        me.photo = googlePhoto();
        return { user: publicUser(me) };
      }

      case 'photo_delete': {
        const me = requireUser();
        Object.assign(me, { photo: '', photo_off: 1 });
        return { user: publicUser(me) };
      }

      case 'profile_name': {
        const me = requireUser();
        const name = clean(input.name);
        if (!between(name, 2, 80)) fail('Ingresá tu nombre (2 a 80 caracteres).');
        me.name = name;
        return { user: publicUser(me) };
      }

      case 'rate_delete': {
        const me = requireUser();
        db.ratings = db.ratings.filter((r) => !(r.rater === me.id && r.rated === input.user));
        return { ok: true };
      }

      case 'admin_claim': {
        if (!user) fail('Iniciá sesión.', 401);
        // Solo cuentan los administradores que pueden entrar (con Google).
        if (db.users.some((u) => u.is_admin === 1 && u.google_sub)) fail('La administración ya está activada. Pedile acceso a un administrador.', 409);
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
              destination: t.destination, day: t.day, hour: t.hour, driver: ownerOf(t).name, phone: t.phone,
              trip_status: t.status, owner_email: ownerOf(t).email,
            };
          }),
        };

      case 'admin_ratings':
        return {
          items: [...db.ratings].sort((a, b) => b.updated - a.updated).map((r) => {
            const rater = userById(r.rater);
            const rated = userById(r.rated);
            return {
              id: r.id, stars: r.stars, comment: r.comment, role: r.role, updated: r.updated,
              rater_id: rater.id, rater_name: rater.name, rater_email: rater.email,
              rater_created: rater.created, rater_google: rater.google_sub ? 1 : 0,
              rated_id: rated.id, rated_name: rated.name, rated_email: rated.email,
            };
          }),
        };

      case 'admin_rating': {
        if (input.action !== 'delete') fail('Acción no válida.');
        const before = db.ratings.length;
        db.ratings = db.ratings.filter((r) => r.id !== input.id);
        if (db.ratings.length === before) fail('Esa calificación ya no existe.', 404);
        return { ok: true };
      }

      case 'admin_trips':
        return {
          items: [...db.trips].sort((a, b) => b.created - a.created).map((t) => ({
            ...t, driver: ownerOf(t).name, owner_email: ownerOf(t).email, owner_blocked: ownerOf(t).blocked,
            reports: db.reports.filter((r) => r.trip === t.id && r.status === 'open').length,
          })),
        };

      case 'admin_users':
        return {
          items: [...db.users].sort((a, b) => b.created - a.created).map((u) => {
            const list = ratingsOf(u.id);
            return {
              id: u.id, email: u.email, name: u.name, created: u.created, is_admin: u.is_admin, blocked: u.blocked,
              blocked_until: u.blocked_until || 0, blocked_reason: u.blocked_reason || '', google: u.google_sub ? 1 : 0,
              google_link_ok: u.google_link_ok || 0,
              trips: db.trips.filter((t) => t.owner === u.id && t.status !== 'deleted').length,
              trips_total: db.trips.filter((t) => t.owner === u.id).length,
              user_rating: average(list), user_ratings: list.length, photo: u.photo || null,
              driver_rating: average(ratingsOf(u.id, 'driver')), driver_ratings: ratingsOf(u.id, 'driver').length,
              rider_rating: average(ratingsOf(u.id, 'rider')), rider_ratings: ratingsOf(u.id, 'rider').length,
            };
          }),
        };

      case 'admin_settings':
        if (method === 'POST') {
          const clientId = clean(input.googleClientId);
          if (!clientId) fail('Sin el ID de cliente de Google nadie podría entrar. Si cambiaste de proyecto en Google, pegá el ID nuevo.');
          if (!GOOGLE_CLIENT_ID.test(clientId)) {
            fail('Ese no parece un ID de cliente de Google: tiene que terminar en .apps.googleusercontent.com.');
          }
          db.settings.googleClientId = clientId;
          return { ok: true };
        }
        return { googleClientId: db.settings.googleClientId };

      case 'admin_user': {
        const target = userById(input.id);
        if (!target) fail('Cuenta no encontrada.', 404);
        if (target.id === user.id && ['block', 'remove_admin'].includes(input.action)) fail('No podés hacerte eso a vos mismo.');
        if (input.action === 'block' && target.is_admin === 1) fail('Primero quitale la administración.');
        if (input.action === 'remove_photo') {
          Object.assign(target, { photo: '', photo_off: 1 });
          return { ok: true };
        }
        if (input.action === 'allow_google') {
          if (target.google_sub) fail('Esa cuenta ya entra con Google.');
          target.google_link_ok = 1;
          return { ok: true };
        }
        if (input.action === 'block') {
          const reason = SUSPEND_REASONS[input.reason];
          if (!SUSPEND_DAYS.includes(input.days) || !reason) fail('Elegí cuánto dura la suspensión y el motivo.');
          Object.assign(target, { blocked: 1, blocked_until: input.days ? now() + input.days * 86400 : 0, blocked_reason: reason });
          return { ok: true };
        }
        const changes = {
          unblock: { blocked: 0, blocked_until: 0, blocked_reason: '' }, make_admin: { is_admin: 1 }, remove_admin: { is_admin: 0 },
        }[input.action];
        if (!changes) fail('Acción no válida.');
        Object.assign(target, changes);
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
      const data = await handle(options.method === 'POST' ? 'POST' : 'GET', params.get('a') || '', params, input || {});
      save();
      return reply(data, options.method === 'POST' && ['trip_create', 'report'].includes(params.get('a')) ? 201 : 200);
    } catch (error) {
      if (error instanceof Failure) return reply({ error: error.message }, error.status);
      console.error(error);
      return reply({ error: 'No se pudo completar la operación. Intentá nuevamente.' }, 503);
    }
  };

  // Dentro de Claude no se puede cargar el botón real de Google: este lo reemplaza y deja elegir con qué
  // cuenta de ejemplo entrar, para probar las calificaciones entre conductores y acompañantes.
  // En la página de verdad aparece el botón oficial y cada uno entra con su propia cuenta.
  window.google = {
    accounts: {
      id: {
        initialize(config) {
          this.callback = config.callback;
        },
        renderButton(target) {
          const select = Object.assign(document.createElement('select'), { id: 'google-prueba-cuenta' });
          GOOGLE_ACCOUNTS.forEach(([value, text]) => select.append(new Option(text, value)));
          const label = Object.assign(document.createElement('label'), { className: 'google-prueba', textContent: 'Entrar como' });
          label.append(select);
          const button = Object.assign(document.createElement('button'), {
            type: 'button', className: 'btn btn-ghost', id: 'google-prueba', textContent: 'Continuar con Google (prueba)',
          });
          button.addEventListener('click', () => this.callback({ credential: `prueba:${select.value}` }));
          target.replaceChildren(label, button);
        },
        disableAutoSelect() {},
      },
    },
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
