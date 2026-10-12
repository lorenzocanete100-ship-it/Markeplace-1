'use strict';

// Misma regla que el servidor: los avisos no llevan precios ni montos.
const PRICE_WORDS = /\$|\b(precios?|tarifas?|pesos|usd|d[oó]lar(es)?)\b/i;
const VIEWS = ['viajes', 'publicar', 'mis-viajes', 'perfil', 'perfil-publico', 'admin', 'entrar'];
const PRIVATE_VIEWS = ['publicar', 'mis-viajes', 'perfil', 'admin'];
const REASONS = { precio: 'Cobra un precio o parece un negocio', datos: 'Teléfono o datos falsos', ofensivo: 'Ofensivo o peligroso', otro: 'Otro motivo' };

const state = { csrf: '', today: '', user: null, googleClientId: '', trips: [], mine: [], tripsLoaded: false, next: 'viajes' };
const $ = (selector, root = document) => root.querySelector(selector);

async function api(action, { body, query = '', retried = false } = {}) {
  const options = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
  if (body) {
    options.method = 'POST';
    options.headers['Content-Type'] = 'application/json';
    options.headers['X-CSRF-Token'] = state.csrf;
    options.body = JSON.stringify(body);
  }
  let response;
  try {
    response = await fetch(`api.php?a=${action}${query}`, options);
  } catch {
    throw new Error('No hay conexión. Revisá internet e intentá de nuevo.');
  }
  const data = await response.json().catch(() => ({}));
  // Si la sesión venció, pedimos un token nuevo y reintentamos una sola vez.
  if (response.status === 403 && data.csrf && body && !retried) {
    await loadSession();
    return api(action, { body, query, retried: true });
  }
  if (!response.ok) throw new Error(data.error || 'No se pudo completar la operación. Intentá nuevamente.');
  return data;
}

async function loadSession() {
  const session = await api('session');
  state.csrf = session.csrf;
  state.today = session.today;
  state.user = session.user;
  state.googleClientId = session.googleClientId || '';
}

/* Fechas */
const utcDate = (iso) => {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d, 12));
};
const shortDay = new Intl.DateTimeFormat('es-AR', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
const longDay = new Intl.DateTimeFormat('es-AR', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
const capitalize = (text) => text.charAt(0).toUpperCase() + text.slice(1);

function addDays(iso, days) {
  const date = utcDate(iso);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function formatDay(iso) {
  if (iso === state.today) return 'Hoy';
  if (iso === addDays(state.today, 1)) return 'Mañana';
  return capitalize(shortDay.format(utcDate(iso)).replace(/[.,]/g, ''));
}

const normalize = (text) => String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
const seatsText = (n) => (n === 1 ? '1 asiento libre' : `${n} asientos libres`);

function toast(message) {
  const el = $('#toast');
  el.textContent = message;
  el.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => { el.hidden = true; }, 3800);
}

// Confirmación dibujada en la página: dentro de otras apps el confirm() del navegador no aparece.
function ask(question, ok) {
  const dialog = $('#ask-dlg');
  $('#ask-text').textContent = question;
  $('#ask-ok').textContent = ok;
  dialog.returnValue = '';
  dialog.showModal();
  return new Promise((resolve) => {
    dialog.addEventListener('close', () => resolve(dialog.returnValue === 'yes'), { once: true });
  });
}

function showError(form, message) {
  const el = $('.form-error', form);
  el.textContent = message || '';
  el.hidden = !message;
}

async function busy(form, task) {
  const button = $('button[type="submit"]', form);
  button.disabled = true;
  showError(form, '');
  try {
    await task();
  } catch (error) {
    showError(form, error.message);
  } finally {
    button.disabled = false;
  }
}

/* Tarjeta de viaje */
function tripCard(trip, { manage = false } = {}) {
  const card = $('#trip-tpl').content.firstElementChild.cloneNode(true);
  const past = trip.day < state.today;
  $('.day', card).textContent = formatDay(trip.day);
  $('.hour', card).textContent = `${trip.hour} h`;
  $('.seats span', card).textContent = seatsText(trip.seats);
  $('.from', card).textContent = trip.origin;
  $('.to', card).textContent = trip.destination;
  if (trip.vehicle) $('.vehicle span', card).textContent = trip.vehicle;
  else $('.vehicle', card).hidden = true;
  if (trip.notes) $('.notes', card).textContent = trip.notes;
  else $('.notes', card).hidden = true;

  $('.driver strong', card).textContent = trip.driver;
  $('.driver-link', card).href = `#perfil-${trip.driver_id}`;
  $('.driver .rating', card).replaceWith(ratingBadge(trip.driver_rating, trip.driver_ratings));
  const tel = `tel:+${trip.phone_digits}`;
  $('.phone', card).href = tel;
  $('.phone span', card).textContent = trip.phone;
  $('.btn-call', card).href = tel;
  let message = `Hola ${trip.driver}, vi en Asiento Vacío tu viaje de ${trip.origin} a ${trip.destination} `
    + `del ${longDay.format(utcDate(trip.day)).replace(',', '')} a las ${trip.hour}. ¿Te queda algún asiento libre?`;
  // Con cuenta, el mensaje lleva el perfil del acompañante, así el conductor ve sus calificaciones.
  if (state.user && !trip.mine) message += ` Mi perfil en Asiento Vacío: ${profileUrl(state.user.id)}`;
  $('.btn-wa', card).href = `https://wa.me/${trip.phone_digits}?text=${encodeURIComponent(message)}`;

  const remove = $('.delete', card);
  if (manage) {
    const owner = $('.owner', card);
    owner.hidden = false;
    const status = $('.status', owner);
    if (past) {
      status.textContent = 'Este viaje ya pasó y no aparece en la lista.';
      status.classList.add('past');
      card.classList.add('is-past');
      $('.stepper', owner).hidden = true;
      $('.toggle', owner).hidden = true;
    } else {
      const full = trip.status === 'full';
      status.textContent = full ? 'Completo: no aparece en la lista.' : 'Publicado: se ve en la lista de viajes.';
      status.classList.toggle('full', full);
      card.classList.toggle('is-full', full);
      $('.n', owner).textContent = seatsText(trip.seats);
      $('.less', owner).disabled = trip.seats <= 1;
      $('.more', owner).disabled = trip.seats >= 8;
      $('.less', owner).addEventListener('click', () => updateTrip(trip, { seats: trip.seats - 1 }));
      $('.more', owner).addEventListener('click', () => updateTrip(trip, { seats: trip.seats + 1 }));
      const toggle = $('.toggle', owner);
      toggle.textContent = full ? 'Volver a publicar' : 'Marcar completo';
      toggle.addEventListener('click', () => updateTrip(trip, { status: full ? 'active' : 'full' }));
    }
    remove.hidden = false;
  } else if (trip.canDelete && state.user?.admin) {
    remove.hidden = false;
    remove.textContent = 'Borrar viaje (moderación)';
  }
  remove.addEventListener('click', () => deleteTrip(trip));
  const report = $('.report', card);
  report.hidden = manage || trip.mine || Boolean(state.user?.admin);
  report.addEventListener('click', () => openReport(trip));
  return card;
}

/* Lista pública */
async function loadTrips() {
  if (!state.tripsLoaded) $('#count').textContent = 'Cargando viajes…';
  try {
    state.trips = (await api('trips')).items;
    state.tripsLoaded = true;
  } catch (error) {
    $('#count').textContent = error.message;
    return;
  }
  const places = [...new Set(state.trips.flatMap((t) => [t.origin, t.destination]))].sort((a, b) => a.localeCompare(b, 'es'));
  $('#places').replaceChildren(...places.map((place) => Object.assign(document.createElement('option'), { value: place })));
  renderTrips();
}

function renderTrips() {
  const filters = new FormData($('#search'));
  const from = normalize(filters.get('from'));
  const to = normalize(filters.get('to'));
  const day = filters.get('day');
  const wellRated = filters.get('rated') === 'on';
  const list = state.trips.filter((t) => (!from || normalize(t.origin).includes(from))
    && (!to || normalize(t.destination).includes(to))
    && (!day || t.day === day)
    && (!wellRated || (t.driver_ratings > 0 && t.driver_rating >= 4)));
  $('#trips').replaceChildren(...list.map((t) => tripCard(t)));
  $('#empty').hidden = list.length > 0;
  $('#empty strong').textContent = state.trips.length ? 'No hay viajes que coincidan.' : 'Todavía no hay viajes publicados.';
  $('#count').textContent = list.length === 1 ? '1 viaje disponible' : list.length ? `${list.length} viajes disponibles` : '';
}

/* Mis viajes */
async function loadMine() {
  try {
    state.mine = (await api('trips', { query: '&mine=1' })).items;
  } catch (error) {
    toast(error.message);
    return;
  }
  $('#mine').replaceChildren(...state.mine.map((t) => tripCard(t, { manage: true })));
  $('#mine-empty').hidden = state.mine.length > 0;
}

async function updateTrip(trip, changes) {
  try {
    await api('trip_update', { body: { id: trip.id, ...changes } });
    if (changes.status === 'full') toast('Listo: el viaje quedó como completo.');
    if (changes.status === 'active') toast('Listo: el viaje se ve de nuevo en la lista.');
  } catch (error) {
    toast(error.message);
  }
  await loadMine();
}

async function deleteTrip(trip) {
  if (!(await ask(`¿Borrar el viaje de ${trip.origin} a ${trip.destination}? No se puede deshacer.`, 'Borrar viaje'))) return;
  try {
    await api('trip_delete', { body: { id: trip.id } });
    toast('Viaje borrado.');
  } catch (error) {
    toast(error.message);
  }
  state.tripsLoaded = false;
  route();
}

/* Publicar */
async function preparePublish() {
  const form = $('#publish');
  form.day.min = state.today;
  form.day.max = addDays(state.today, 180);
  // Las calificaciones son de la cuenta, así que el viaje sale con el nombre de la cuenta.
  form.driver.value = state.user.name;
  if (!form.phone.value) {
    if (!state.mine.length) await loadMine();
    // Reusamos los datos del último viaje para no tener que escribirlos de nuevo.
    const last = [...state.mine].sort((a, b) => b.created - a.created)[0];
    if (last) {
      form.phone.value = last.phone;
      if (!form.vehicle.value) form.vehicle.value = last.vehicle;
    }
  }
}

function publishData(form) {
  const field = (name) => form[name].value.trim();
  return {
    origin: field('origin'), destination: field('destination'), day: field('day'), hour: field('hour'),
    seats: Number(form.seats.value), phone: field('phone'),
    vehicle: field('vehicle'), notes: field('notes'),
    confirmPrivate: form.confirmPrivate.checked, confirmPhone: form.confirmPhone.checked,
  };
}

async function publish(event) {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.checkValidity()) {
    showError(form, 'Completá los campos que faltan y marcá las dos confirmaciones.');
    form.reportValidity();
    return;
  }
  const data = publishData(form);
  if ([data.origin, data.destination, data.vehicle, data.notes].some((text) => PRICE_WORDS.test(text))) {
    showError(form, 'No pongas precios ni montos. En Asiento Vacío solo se comparten los gastos de combustible y alguna comida.');
    return;
  }
  await busy(form, async () => {
    await api('trip_create', { body: data });
    form.reset();
    state.tripsLoaded = false;
    toast('¡Listo! Tu viaje ya está publicado.');
    go('mis-viajes');
  });
}

/* Cuenta */
function updateAuthUI() {
  document.querySelectorAll('.only-user').forEach((el) => { el.hidden = !state.user; });
  document.querySelectorAll('.only-guest').forEach((el) => { el.hidden = Boolean(state.user); });
  document.querySelectorAll('.only-admin').forEach((el) => { el.hidden = !state.user?.admin; });
}

function selectTab(name) {
  for (const tab of ['login', 'register']) {
    $(`#tab-${tab}`).setAttribute('aria-selected', String(tab === name));
    $(`#${tab}`).hidden = tab !== name;
  }
}

async function authenticate(event) {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.checkValidity()) {
    form.reportValidity();
    return;
  }
  const body = Object.fromEntries(new FormData(form));
  await busy(form, async () => {
    const result = await api(form.id, { body });
    form.reset();
    signedIn(result, form.id === 'register');
  });
}

function signedIn(result, isNew) {
  state.csrf = result.csrf;
  state.user = result.user;
  state.mine = [];
  updateAuthUI();
  toast(isNew ? `¡Bienvenido/a, ${result.user.name}!` : `Hola, ${result.user.name}.`);
  go(isNew && state.next === 'mis-viajes' ? 'perfil' : state.next);
}

/* Ingreso con Google: el botón lo dibuja Google y el servidor comprueba el comprobante que entrega. */
let googleScript = null;
function loadGoogleScript() {
  if (window.google?.accounts?.id) return Promise.resolve();
  googleScript ??= new Promise((resolve, reject) => {
    const script = Object.assign(document.createElement('script'), { src: 'https://accounts.google.com/gsi/client', async: true });
    script.addEventListener('load', resolve);
    script.addEventListener('error', () => {
      googleScript = null;
      reject(new Error('No se pudo cargar el botón de Google.'));
    });
    document.head.append(script);
  });
  return googleScript;
}

async function renderGoogle() {
  const box = $('#google-box');
  box.hidden = !state.googleClientId || Boolean(state.user);
  if (box.hidden) return;
  try {
    await loadGoogleScript();
  } catch {
    box.hidden = true;
    return;
  }
  if (state.googleReadyFor !== state.googleClientId) {
    window.google.accounts.id.initialize({ client_id: state.googleClientId, callback: googleSignIn, ux_mode: 'popup', context: 'signin' });
    state.googleReadyFor = state.googleClientId;
  }
  const target = $('#google-button');
  window.google.accounts.id.renderButton(target, {
    type: 'standard', theme: 'outline', size: 'large', text: 'continue_with', shape: 'pill', locale: 'es',
    width: Math.max(200, Math.min(360, box.clientWidth || 320)),
  });
}

async function googleSignIn(response) {
  const error = $('#google-error');
  error.hidden = true;
  try {
    signedIn(await api('google', { body: { credential: response.credential } }), false);
  } catch (problem) {
    error.textContent = problem.message;
    error.hidden = false;
  }
}

async function logout() {
  try {
    const result = await api('logout', { body: {} });
    state.csrf = result.csrf;
  } catch (error) {
    toast(error.message);
    return;
  }
  state.user = null;
  state.mine = [];
  $('#publish').reset();
  window.google?.accounts?.id?.disableAutoSelect();
  updateAuthUI();
  go('viajes');
}

/* Denuncias */
function openReport(trip) {
  const form = $('#report-form');
  form.reset();
  showError(form, '');
  form.dataset.trip = trip.id;
  $('#report-trip').textContent = `${trip.origin} → ${trip.destination} · ${formatDay(trip.day)} ${trip.hour} h · ${trip.driver}`;
  $('#report-dlg').showModal();
}

async function sendReport(event) {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.checkValidity()) {
    showError(form, 'Elegí un motivo.');
    return;
  }
  await busy(form, async () => {
    await api('report', { body: { trip: form.dataset.trip, reason: form.reason.value, details: form.details.value } });
    $('#report-dlg').close();
    toast('Gracias. La administración va a revisar el aviso.');
  });
}

/* Administrar */
const admin = { trips: [], users: [], ratings: [] };
const stamp = new Intl.DateTimeFormat('es-AR', { day: 'numeric', month: 'short', year: 'numeric' });
const when = (seconds) => stamp.format(new Date(seconds * 1000)).replace(/\./g, '');

function el(tag, props = {}, ...children) {
  const node = Object.assign(document.createElement(tag), props);
  node.append(...children.filter((child) => child));
  return node;
}
const badge = (text, kind = '') => el('span', { className: `badge ${kind}`.trim(), textContent: text });
const button = (text, onclick, kind = 'btn-ghost') => el('button', { type: 'button', className: `btn btn-sm ${kind}`, textContent: text, onclick });
const emptyRow = (text) => el('p', { className: 'empty-row', textContent: text });

async function adminAction(action, body, { question, ok, done } = {}) {
  if (question && !(await ask(question, ok))) return;
  try {
    await api(action, { body });
    if (done) toast(done);
  } catch (error) {
    toast(error.message);
  }
  state.tripsLoaded = false;
  await loadAdmin();
}

const deleteTripButton = (id) => button('Borrar viaje', () => adminAction('admin_trip', { id, action: 'delete' },
  { question: '¿Borrar este viaje? Deja de verse en la página.', ok: 'Borrar viaje', done: 'Viaje borrado.' }), 'btn-danger');

async function loadAdmin() {
  const isAdmin = Boolean(state.user?.admin);
  $('#claim').hidden = isAdmin;
  $('#admin-panel').hidden = !isAdmin;
  if (!isAdmin) return;
  let overview, reports, trips, users, ratings, settings;
  try {
    [overview, reports, trips, users, ratings, settings] = await Promise.all(
      ['admin_overview', 'admin_reports', 'admin_trips', 'admin_users', 'admin_ratings', 'admin_settings'].map((a) => api(a)));
  } catch (error) {
    toast(error.message);
    return;
  }
  $('#st-upcoming').textContent = overview.upcoming;
  $('#st-week').textContent = overview.week;
  $('#st-drivers').textContent = overview.drivers;
  $('#st-reports').textContent = overview.reports;
  $('#admin-tabs [data-tab="reports"]').textContent = overview.reports ? `Denuncias (${overview.reports})` : 'Denuncias';
  admin.trips = trips.items;
  admin.users = users.items;
  renderReports(reports.items);
  admin.ratings = ratings.items;
  renderRatings();
  renderSettings(settings);
  renderAdminTrips();
  renderUsers();
}

function selectAdminTab(name) {
  document.querySelectorAll('#admin-tabs [data-tab]').forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.tab === name)));
  for (const pane of ['reports', 'ratings', 'trips', 'users', 'settings']) $(`#pane-${pane}`).hidden = pane !== name;
}

function showTripsOf(email) {
  $('#filter-trips').value = email;
  renderAdminTrips();
  selectAdminTab('trips');
}

function showUser(email) {
  $('#filter-users').value = email;
  $('#filter-role').value = '';
  renderUsers();
  selectAdminTab('users');
}

function renderReports(items) {
  const rows = items.map((r) => el('article', { className: 'item' },
    el('div', { className: 'badges' }, badge(REASONS[r.reason] || r.reason, 'bad'), badge(when(r.created), 'off')),
    el('p', { className: 'item-title', textContent: `${r.origin} → ${r.destination} · ${formatDay(r.day)} ${r.hour} h` }),
    el('p', { className: 'item-meta', textContent: `${r.driver} · ${r.phone} · ${r.owner_email}` }),
    r.details && el('p', { className: 'item-quote', textContent: r.details }),
    el('div', { className: 'item-actions' },
      deleteTripButton(r.trip),
      button('Ver conductor', () => showUser(r.owner_email)),
      button('Descartar denuncia', () => adminAction('admin_report', { id: r.id, action: 'dismiss' }, { done: 'Denuncia descartada.' })))));
  $('#admin-reports').replaceChildren(...(rows.length ? rows : [emptyRow('No hay denuncias pendientes.')]));
}

function tripBadges(trip) {
  const reports = Number(trip.reports);
  return [
    trip.status === 'deleted' ? badge('Borrado', 'off')
      : trip.day < state.today ? badge('Ya pasó', 'off')
        : trip.status === 'full' ? badge('Completo', 'warn') : badge('Publicado'),
    Number(trip.owner_blocked) === 1 && badge('Conductor suspendido', 'bad'),
    reports > 0 && badge(reports === 1 ? '1 denuncia' : `${reports} denuncias`, 'bad'),
  ];
}

function renderAdminTrips() {
  const q = normalize($('#filter-trips').value);
  const list = admin.trips.filter((t) => !q || normalize([t.origin, t.destination, t.driver, t.phone, t.owner_email].join(' ')).includes(q));
  const rows = list.slice(0, 200).map((t) => el('article', { className: 'item' },
    el('div', { className: 'badges' }, ...tripBadges(t)),
    el('p', { className: 'item-title', textContent: `${t.origin} → ${t.destination}` }),
    el('p', { className: 'item-meta', textContent: `${formatDay(t.day)} ${t.hour} h · ${seatsText(t.seats)} · publicado el ${when(t.created)}` }),
    el('p', { className: 'item-meta', textContent: `${t.driver} · ${t.phone} · ${t.owner_email}` }),
    t.notes && el('p', { className: 'item-quote', textContent: t.notes }),
    el('div', { className: 'item-actions' },
      t.status === 'deleted'
        ? button('Restaurar', () => adminAction('admin_trip', { id: t.id, action: 'restore' }, { done: 'Viaje restaurado.' }))
        : deleteTripButton(t.id),
      button('Ver conductor', () => showUser(t.owner_email)))));
  $('#admin-trips').replaceChildren(...(rows.length ? rows : [emptyRow('No hay viajes que coincidan.')]));
}

const ROLE_FILTERS = {
  drivers: (u) => Number(u.trips_total) > 0,
  riders: (u) => Number(u.trips_total) === 0,
  suspended: (u) => Number(u.blocked) === 1,
  lowrated: (u) => Number(u.user_ratings) > 0 && Number(u.user_rating) < 3,
};
const until = new Intl.DateTimeFormat('es-AR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
const suspendedBadge = (u) => badge(Number(u.blocked_until)
  ? `Suspendido hasta el ${until.format(new Date(u.blocked_until * 1000)).replace(/\./g, '')}`
  : 'Suspendido sin fecha', 'bad');

function renderUsers() {
  const q = normalize($('#filter-users').value);
  const role = ROLE_FILTERS[$('#filter-role').value] || (() => true);
  const list = admin.users.filter((u) => (!q || normalize(`${u.name} ${u.email}`).includes(q)) && role(u));
  const rows = list.map((u) => {
    const self = u.id === state.user.id;
    const isAdmin = Number(u.is_admin) === 1;
    const blocked = Number(u.blocked) === 1;
    const trips = Number(u.trips);
    return el('article', { className: 'item' },
      el('div', { className: 'badges' },
        badge(Number(u.trips_total) > 0 ? 'Conductor' : 'Acompañante', 'off'),
        isAdmin && badge('Administración'), Number(u.google) === 1 && badge('Google', 'off'),
        blocked && suspendedBadge(u), self && badge('Vos', 'off')),
      el('p', { className: 'item-title', textContent: u.name }),
      el('p', { className: 'item-meta' }, `${u.email} · desde el ${when(u.created)} · ${trips === 1 ? '1 viaje' : `${trips} viajes`} · `,
        ratingBadge(u.user_rating === null ? null : Number(u.user_rating), Number(u.user_ratings))),
      blocked && u.blocked_reason && el('p', { className: 'item-reason', textContent: `Motivo: ${u.blocked_reason}` }),
      el('div', { className: 'item-actions' },
        button('Ver viajes', () => showTripsOf(u.email)),
        !self && !isAdmin && (blocked
          ? button('Levantar suspensión', () => adminAction('admin_user', { id: u.id, action: 'unblock' }, { done: 'Suspensión levantada.' }))
          : button('Suspender', () => openSuspend(u), 'btn-danger')),
        !self && !blocked && (isAdmin
          ? button('Quitar administración', () => adminAction('admin_user', { id: u.id, action: 'remove_admin' }, { done: 'Ya no es administrador.' }))
          : button('Hacer administrador', () => adminAction('admin_user', { id: u.id, action: 'make_admin' },
            { question: `¿Darle acceso de administración a ${u.name}?`, ok: 'Dar acceso', done: 'Ahora es administrador.' })))));
  });
  $('#admin-users').replaceChildren(...(rows.length ? rows : [emptyRow('No hay usuarios que coincidan.')]));
}

function openSuspend(u) {
  const dialog = $('#suspend-dlg');
  const form = $('#suspend-form');
  form.reset();
  $('#suspend-who').textContent = `${u.name} · ${u.email}`;
  dialog.returnValue = '';
  dialog.showModal();
  dialog.addEventListener('close', () => {
    if (dialog.returnValue !== 'yes') return;
    const days = Number(form.days.value);
    adminAction('admin_user', { id: u.id, action: 'block', days, reason: form.reason.value },
      { done: days ? `Cuenta suspendida por ${days === 1 ? '1 día' : `${days} días`}.` : 'Cuenta suspendida sin fecha.' });
  }, { once: true });
}

function renderRatings() {
  const lowOnly = $('#filter-ratings').value === 'low';
  const list = admin.ratings.filter((r) => !lowOnly || Number(r.stars) <= 2);
  const rows = list.map((r) => el('article', { className: 'item' },
    el('div', { className: 'badges' }, Number(r.stars) <= 2 && badge('Baja', 'bad'), badge(when(r.updated), 'off')),
    el('p', { className: 'item-title' }, starsText(Number(r.stars)), ` ${r.rater_name} calificó a ${r.rated_name}`),
    el('p', { className: 'item-meta', textContent: `${r.rater_email} → ${r.rated_email}` }),
    r.comment && el('p', { className: 'item-quote', textContent: r.comment }),
    el('div', { className: 'item-actions' },
      button('Ver perfil', () => go(`perfil-${r.rated_id}`)),
      button('Ver quién calificó', () => showUser(r.rater_email)),
      button('Borrar calificación', () => adminAction('admin_rating', { id: r.id, action: 'delete' },
        { question: `¿Borrar la calificación de ${r.rater_name} a ${r.rated_name}?`, ok: 'Borrar', done: 'Calificación borrada.' }), 'btn-danger'))));
  $('#admin-ratings').replaceChildren(...(rows.length ? rows : [emptyRow(lowOnly ? 'No hay calificaciones bajas.' : 'Todavía no hay calificaciones.')]));
}

function renderSettings(settings) {
  const form = $('#settings-form');
  form.googleClientId.value = settings.googleClientId;
  $('#google-status').textContent = settings.googleClientId
    ? 'Activado: en "Entrar" aparece el botón "Continuar con Google".'
    : 'Apagado: todavía no cargaste el ID de cliente de Google.';
  document.querySelectorAll('.site-origin').forEach((node) => { node.textContent = location.origin; });
}

async function saveSettings(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const googleClientId = form.googleClientId.value.trim();
  await busy(form, async () => {
    await api('admin_settings', { body: { googleClientId } });
    state.googleClientId = googleClientId;
    toast(googleClientId ? 'Listo: el ingreso con Google quedó activado.' : 'Listo: el ingreso con Google quedó apagado.');
    await loadAdmin();
  });
}

async function claim(event) {
  event.preventDefault();
  const form = event.currentTarget;
  await busy(form, async () => {
    const result = await api('admin_claim', { body: { code: form.code.value } });
    state.user = result.user;
    form.reset();
    updateAuthUI();
    toast('Listo: ahora sos administrador.');
    await loadAdmin();
  });
}

/* Perfil */
const profileUrl = (id) => `${location.href.split('#')[0]}#perfil-${id}`;
const memberSince = (seconds) => `En Asiento Vacío desde el ${when(seconds)}`;

const decimal = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const ratingsText = (count) => (count === 1 ? '1 calificación' : `${count} calificaciones`);

function starIcon() {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('class', 'icon');
  svg.setAttribute('aria-hidden', 'true');
  const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  use.setAttribute('href', '#i-star');
  svg.append(use);
  return svg;
}

// Promedio compacto para tarjetas y listas: ★ 4,8 (12).
function ratingBadge(average, count) {
  if (!count) return el('span', { className: 'rating none', textContent: 'Sin calificaciones' });
  return el('span', { className: 'rating', title: `${decimal.format(average)} de 5 estrellas · ${ratingsText(count)}` },
    starIcon(), decimal.format(average), el('span', { className: 'count', textContent: `(${count})` }));
}

function starsText(stars) {
  const node = el('span', { className: 'stars', textContent: '★'.repeat(stars) }, el('span', { className: 'off', textContent: '★'.repeat(5 - stars) }));
  node.setAttribute('role', 'img');
  node.setAttribute('aria-label', `${stars} de 5 estrellas`);
  return node;
}

function ratingSummary(target, average, count, emptyText) {
  target.replaceChildren(...(count
    ? [starsText(Math.round(average)), ` ${decimal.format(average)} · ${ratingsText(count)}`]
    : [emptyText]));
}

function reviewItem(review) {
  return el('article', { className: 'review' },
    el('div', { className: 'review-head' },
      el('a', { href: `#perfil-${review.rater_id}`, textContent: review.rater_name }),
      el('span', { className: 'review-date', textContent: when(review.updated) })),
    starsText(Number(review.stars)),
    review.comment && el('p', { textContent: review.comment }));
}

async function loadProfile() {
  const me = state.user;
  $('#me-name').textContent = me.name;
  $('#me-google').hidden = !me.google;
  $('#me-meta').textContent = `${me.email} · ${memberSince(me.created)}`;
  $('#me-link').value = profileUrl(me.id);
  let data;
  try {
    data = await api('profile', { query: `&id=${encodeURIComponent(me.id)}` });
  } catch (error) {
    toast(error.message);
    return;
  }
  ratingSummary($('#me-rating'), data.profile.rating, data.profile.ratings, 'Todavía no tenés calificaciones.');
  $('#me-reviews').replaceChildren(...data.reviews.map(reviewItem));
}

async function sendRating(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const stars = Number(new FormData(form).get('stars'));
  if (!stars) {
    showError(form, 'Elegí de 1 a 5 estrellas.');
    return;
  }
  await busy(form, async () => {
    await api('rate', { body: { user: form.dataset.user, stars, comment: form.comment.value.trim() } });
    state.tripsLoaded = false;
    toast('¡Gracias! Tu calificación quedó guardada.');
    await loadPublicProfile(form.dataset.user);
  });
}

async function deleteRating() {
  const form = $('#rate-form');
  if (!(await ask('¿Borrar tu calificación?', 'Borrar'))) return;
  try {
    await api('rate_delete', { body: { user: form.dataset.user } });
    toast('Tu calificación se borró.');
  } catch (error) {
    toast(error.message);
  }
  state.tripsLoaded = false;
  await loadPublicProfile(form.dataset.user);
}

async function copyProfileLink() {
  const input = $('#me-link');
  try {
    await navigator.clipboard.writeText(input.value);
    toast('Enlace copiado.');
  } catch {
    input.select();
    toast('Mantené apretado el enlace para copiarlo.');
  }
}

async function loadPublicProfile(id) {
  const form = $('#rate-form');
  let data;
  try {
    data = await api('profile', { query: `&id=${encodeURIComponent(id)}` });
  } catch (error) {
    $('#pp-name').textContent = error.message;
    for (const node of ['#pp-rating', '#pp-reviews', '#pp-trips']) $(node).replaceChildren();
    $('#pp-meta').textContent = '';
    form.hidden = true;
    $('#rate-login').hidden = true;
    $('#pp-empty').hidden = true;
    return;
  }
  const { profile } = data;
  $('#pp-name').textContent = profile.name;
  ratingSummary($('#pp-rating'), profile.rating, profile.ratings, 'Todavía no tiene calificaciones.');
  $('#pp-meta').textContent = memberSince(profile.created);
  const canRate = Boolean(state.user) && state.user.id !== profile.id;
  form.hidden = !canRate;
  $('#rate-login').hidden = Boolean(state.user);
  if (canRate) {
    form.reset();
    showError(form, '');
    form.dataset.user = profile.id;
    $('#rate-name').textContent = profile.name;
    if (data.mine) {
      form.querySelector(`input[name="stars"][value="${data.mine.stars}"]`).checked = true;
      form.comment.value = data.mine.comment;
    }
    $('#rate-delete').hidden = !data.mine;
  }
  $('#pp-reviews').replaceChildren(...(data.reviews.length
    ? data.reviews.map(reviewItem)
    : [el('p', { className: 'muted', textContent: 'Todavía no hay opiniones.' })]));
  $('#pp-trips').replaceChildren(...data.trips.map((t) => tripCard(t)));
  $('#pp-empty').hidden = data.trips.length > 0;
}

/* Navegación */
function go(view) {
  if (location.hash === `#${view}`) route();
  else location.hash = view;
}

function route() {
  let view = location.hash.slice(1);
  let profileId = '';
  if (view.startsWith('perfil-')) {
    profileId = view.slice('perfil-'.length);
    view = 'perfil-publico';
  }
  if (!VIEWS.includes(view)) view = 'viajes';
  if (PRIVATE_VIEWS.includes(view) && !state.user) {
    state.next = view;
    view = 'entrar';
  } else if (view === 'entrar') {
    if (state.user) view = 'viajes';
    state.next = 'mis-viajes';
  }
  if (view === 'entrar') renderGoogle();
  for (const name of VIEWS) $(`#view-${name}`).hidden = name !== view;
  document.querySelectorAll('[data-view]').forEach((link) => {
    if (link.dataset.view === view) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });
  if (view === 'viajes') loadTrips();
  if (view === 'mis-viajes') loadMine();
  if (view === 'publicar') preparePublish();
  if (view === 'admin') loadAdmin();
  if (view === 'perfil') loadProfile();
  if (view === 'perfil-publico') loadPublicProfile(profileId);
  window.scrollTo(0, 0);
}

async function init() {
  $('#search').addEventListener('input', renderTrips);
  $('#search').addEventListener('reset', () => setTimeout(renderTrips));
  $('#publish').addEventListener('submit', publish);
  $('#login').addEventListener('submit', authenticate);
  $('#register').addEventListener('submit', authenticate);
  $('#tab-login').addEventListener('click', () => selectTab('login'));
  $('#tab-register').addEventListener('click', () => selectTab('register'));
  $('#logout').addEventListener('click', logout);
  $('#report-form').addEventListener('submit', sendReport);
  $('#report-cancel').addEventListener('click', () => $('#report-dlg').close());
  $('#claim').addEventListener('submit', claim);
  $('#admin-tabs').addEventListener('click', (event) => {
    if (event.target.dataset.tab) selectAdminTab(event.target.dataset.tab);
  });
  $('#filter-trips').addEventListener('input', renderAdminTrips);
  $('#filter-users').addEventListener('input', renderUsers);
  $('#filter-role').addEventListener('change', renderUsers);
  $('#copy-link').addEventListener('click', copyProfileLink);
  $('#rate-form').addEventListener('submit', sendRating);
  $('#rate-form').addEventListener('change', (event) => showError(event.currentTarget, ''));
  $('#rate-delete').addEventListener('click', deleteRating);
  $('#filter-ratings').addEventListener('change', renderRatings);
  $('#settings-form').addEventListener('submit', saveSettings);
  window.addEventListener('hashchange', route);
  try {
    await loadSession();
  } catch (error) {
    toast(error.message);
    state.today = new Date().toISOString().slice(0, 10);
  }
  updateAuthUI();
  route();
}

init();
