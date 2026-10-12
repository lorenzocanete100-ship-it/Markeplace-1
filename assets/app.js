'use strict';

// Misma regla que el servidor: los avisos no llevan precios ni montos.
const PRICE_WORDS = /\$|\b(precios?|tarifas?|pesos|usd|d[oó]lar(es)?)\b/i;
const VIEWS = ['viajes', 'publicar', 'mis-viajes', 'perfil', 'perfil-publico', 'admin', 'entrar'];
const PRIVATE_VIEWS = ['publicar', 'mis-viajes', 'perfil', 'admin'];
const MIN_RATINGS = 3;        // opiniones que necesita un conductor para el filtro "4 estrellas o más"
const NEW_ACCOUNT_DAYS = 7;   // en Calificaciones se marcan las hechas con cuentas más nuevas que esto
// Qué hacía en el viaje la persona calificada.
const ROLE_TEXT = { driver: 'como conductor', rider: 'como acompañante' };
const REASONS = { precio: 'Cobra un precio o parece un negocio', datos: 'Teléfono o datos falsos', ofensivo: 'Ofensivo o peligroso', otro: 'Otro motivo' };

const state = {
  csrf: '', today: '', user: null, googleClientId: '', sessionLoaded: false, fixingSetup: false,
  trips: [], mine: [], tripsLoaded: false, next: 'viajes', returnTo: '',
};
const $ = (selector, root = document) => root.querySelector(selector);

async function api(action, { body, query = '', retried = false } = {}) {
  const options = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
  if (body) {
    options.method = 'POST';
    options.headers['X-CSRF-Token'] = state.csrf;
    options.headers['Content-Type'] = 'application/json';
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
  state.sessionLoaded = true;
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
  $('.driver .avatar-slot', card).replaceWith(avatar(trip.driver_photo, trip.driver, 'sm'));
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
  if (!trip.mine) {
    for (const link of [$('.phone', card), $('.btn-call', card), $('.btn-wa', card)]) {
      link.addEventListener('click', () => rememberContact(trip));
    }
  }

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
    && (!wellRated || (t.driver_ratings >= MIN_RATINGS && t.driver_rating >= 4)));
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

function signedIn(result) {
  state.csrf = result.csrf;
  state.user = result.user;
  state.mine = [];
  updateAuthUI();
  toast(result.new ? `¡Bienvenido/a, ${result.user.name}!` : `Hola, ${result.user.name}.`);
  go(result.new && state.next === 'mis-viajes' ? 'perfil' : state.next);
}

/* Ingreso con Google (el único): el botón lo dibuja Google y el servidor comprueba el comprobante que entrega. */
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
  const guest = !state.user;
  const configured = Boolean(state.googleClientId);
  // Sin el ID de cliente nadie puede entrar: se muestra el formulario para que el dueño lo cargue. Si ya hay
  // uno pero está mal, el dueño lo corrige desde "¿Sos el dueño…?". Si no se pudo cargar la sesión, no se sabe.
  const setup = $('#setup-form');
  setup.hidden = !guest || !state.sessionLoaded || (configured && !state.fixingSetup);
  $('#setup-title').textContent = configured ? 'Cambiar el ID de cliente de Google' : 'Falta configurar el ingreso';
  $('#setup-text').textContent = configured
    ? 'Si el botón de Google no funciona, puede que el ID de cliente esté mal copiado. Cargá el correcto junto con el código de administración.'
    : 'Todavía nadie puede entrar: el sitio necesita el ID de cliente de Google. Si sos el dueño, cargalo acá junto con el código de administración.';
  $('#setup-link').hidden = !guest || !configured || !setup.hidden;
  $('#google-off').hidden = !guest || state.sessionLoaded;
  box.hidden = !guest || !configured;
  if (box.hidden) return;
  try {
    await loadGoogleScript();
  } catch {
    box.hidden = true;
    $('#google-off').hidden = false;
    return;
  }
  if (state.googleReadyFor !== state.googleClientId) {
    window.google.accounts.id.initialize({ client_id: state.googleClientId, callback: googleSignIn, ux_mode: 'popup', context: 'signin' });
    state.googleReadyFor = state.googleClientId;
  }
  // Google dibuja el botón con un ancho fijo: el del espacio libre dentro de la tarjeta (sin el relleno).
  const style = getComputedStyle(box);
  const inner = box.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
  window.google.accounts.id.renderButton($('#google-button'), {
    type: 'standard', theme: 'outline', size: 'large', text: 'continue_with', shape: 'pill', locale: 'es',
    width: Math.floor(Math.max(200, Math.min(360, inner || 300))),
  });
}

async function googleSignIn(response) {
  const error = $('#google-error');
  error.hidden = true;
  try {
    signedIn(await api('google', { body: { credential: response.credential } }));
  } catch (problem) {
    error.textContent = problem.message;
    error.hidden = false;
  }
}

async function setupGoogle(event) {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.checkValidity()) {
    showError(form, 'Completá el código y el ID de cliente.');
    return;
  }
  await busy(form, async () => {
    const result = await api('setup', { body: { code: form.code.value, googleClientId: form.googleClientId.value.trim() } });
    state.googleClientId = result.googleClientId;
    state.fixingSetup = false;
    form.reset();
    // Después de entrar, se sigue con la administración (se activa con el mismo código).
    state.next = 'admin';
    toast('Listo: ya se puede entrar con Google. Entrá con tu cuenta para activar la administración.');
    renderGoogle();
  });
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

function selectAdminTab(name, focus = false) {
  document.querySelectorAll('#admin-tabs [data-tab]').forEach((tab) => {
    const selected = tab.dataset.tab === name;
    tab.setAttribute('aria-selected', String(selected));
    tab.tabIndex = selected ? 0 : -1;
    if (selected && focus) tab.focus();
  });
  for (const pane of ['reports', 'ratings', 'trips', 'users', 'settings']) $(`#pane-${pane}`).hidden = pane !== name;
}

// Pestañas con teclado: flechas, Inicio y Fin.
function moveAdminTab(event) {
  const tabs = [...document.querySelectorAll('#admin-tabs [data-tab]')];
  const current = tabs.indexOf(document.activeElement);
  const next = { ArrowRight: current + 1, ArrowLeft: current - 1, Home: 0, End: tabs.length - 1 }[event.key];
  if (current < 0 || next === undefined) return;
  event.preventDefault();
  selectAdminTab(tabs[(next + tabs.length) % tabs.length].dataset.tab, true);
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

const lowRated = (average, count) => Number(count) > 0 && Number(average) < 3;
const ROLE_FILTERS = {
  drivers: (u) => Number(u.trips_total) > 0,
  riders: (u) => Number(u.trips_total) === 0,
  suspended: (u) => Number(u.blocked) === 1,
  lowrated: (u) => lowRated(u.driver_rating, u.driver_ratings) || lowRated(u.rider_rating, u.rider_ratings),
};

// Promedios de un usuario como conductor y como acompañante (solo los que tiene).
function userRatings(u) {
  const parts = [['Conductor', u.driver_rating, u.driver_ratings], ['Acompañante', u.rider_rating, u.rider_ratings]]
    .filter(([, , count]) => Number(count) > 0)
    .flatMap(([label, average, count], i) => [i ? ' · ' : '', `${label} `, ratingBadge(Number(average), Number(count))]);
  return parts.length ? parts : [ratingBadge(null, 0)];
}
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
    const google = Number(u.google) === 1;
    const linkOk = Number(u.google_link_ok) === 1;
    return el('article', { className: 'item' },
      el('div', { className: 'badges' },
        badge(Number(u.trips_total) > 0 ? 'Conductor' : 'Acompañante', 'off'),
        isAdmin && badge('Administración'), google ? badge('Google', 'off') : badge('Cuenta vieja, con contraseña', 'warn'),
        !google && linkOk && badge('Puede pasar a Google'),
        blocked && suspendedBadge(u), self && badge('Vos', 'off')),
      el('p', { className: 'item-title' }, avatar(u.photo, u.name, 'xs'), u.name),
      el('p', { className: 'item-meta' }, `${u.email} · desde el ${when(u.created)} · ${trips === 1 ? '1 viaje' : `${trips} viajes`} · `,
        ...userRatings(u)),
      blocked && u.blocked_reason && el('p', { className: 'item-reason', textContent: `Motivo: ${u.blocked_reason}` }),
      el('div', { className: 'item-actions' },
        button('Ver viajes', () => showTripsOf(u.email)),
        u.photo && button('Quitar foto', () => adminAction('admin_user', { id: u.id, action: 'remove_photo' },
          { question: `¿Quitar la foto de perfil de ${u.name}?`, ok: 'Quitar foto', done: 'Foto quitada.' })),
        // Las cuentas de antes, con contraseña, pasan a Google al entrar; si el correo no es de Gmail, hace falta este permiso.
        !google && !linkOk && button('Dejar entrar con Google', () => adminAction('admin_user', { id: u.id, action: 'allow_google' },
          { question: `¿Dejar que entre con la cuenta de Google de ${u.email}? Hacelo solo si sabés que ese correo es de ${u.name}.`,
            ok: 'Permitir', done: 'Listo: la próxima vez puede entrar con Google.' })),
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
    el('div', { className: 'badges' }, Number(r.stars) <= 2 && badge('Baja', 'bad'),
      // Ayuda a ver calificaciones de cuentas inventadas: las hechas con cuentas recién creadas.
      r.rater_created > r.updated - NEW_ACCOUNT_DAYS * 86400 && badge('Cuenta nueva', 'warn'),
      Number(r.rater_google) === 1 && badge('Google'), badge(when(r.updated), 'off')),
    el('p', { className: 'item-title' }, starsText(Number(r.stars)), ` ${r.rater_name} calificó a ${r.rated_name} ${ROLE_TEXT[r.role] || ''}`.trimEnd()),
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
    : 'Falta el ID de cliente de Google: sin él nadie puede entrar.';
}

// Ayuda para conseguir el ID de cliente, con las dos direcciones del sitio (con y sin www).
// En la versión de prueba la dirección no es la del sitio y no hay .site-origins.
function fillGoogleHelp() {
  document.querySelectorAll('.google-help').forEach((slot) => slot.replaceWith($('#google-help-tpl').content.cloneNode(true)));
  const host = location.hostname.replace(/^www\./, '');
  const origins = location.protocol === 'https:' && host.includes('.') ? [`https://${host}`, `https://www.${host}`] : [location.origin];
  document.querySelectorAll('.site-origins').forEach((node) => {
    node.replaceChildren(...origins.flatMap((origin, i) => [i ? ' y ' : '', el('code', { textContent: origin })]), '.');
  });
}

async function saveSettings(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const googleClientId = form.googleClientId.value.trim();
  await busy(form, async () => {
    await api('admin_settings', { body: { googleClientId } });
    state.googleClientId = googleClientId;
    toast('Listo: guardaste el ID de cliente de Google.');
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
  const label = `${decimal.format(average)} de 5 estrellas · ${ratingsText(count)}`;
  const node = el('span', { className: 'rating', title: label },
    starIcon(), decimal.format(average), el('span', { className: 'count', textContent: `(${count})` }));
  node.setAttribute('role', 'img');
  node.setAttribute('aria-label', label);
  return node;
}

function starsText(stars) {
  const node = el('span', { className: 'stars', textContent: '★'.repeat(stars) }, el('span', { className: 'off', textContent: '★'.repeat(5 - stars) }));
  node.setAttribute('role', 'img');
  node.setAttribute('aria-label', `${stars} de 5 estrellas`);
  return node;
}

// Las dos reputaciones de una persona: como conductor y como acompañante.
function roleRatings(target, profile, emptyText) {
  target.replaceChildren(...[['Como conductor', profile.asDriver], ['Como acompañante', profile.asRider]].map(([label, pair]) => {
    const summary = el('span');
    ratingSummary(summary, pair.rating, pair.count, emptyText);
    return el('p', { className: 'rating-line' }, el('span', { className: 'rating-role', textContent: label }), summary);
  }));
}

function ratingSummary(target, average, count, emptyText) {
  if (!count) {
    target.replaceChildren(emptyText);
    return;
  }
  // Las estrellas dibujadas están redondeadas: el lector de pantalla oye el promedio exacto.
  const node = el('span', {}, starsText(Math.round(average)), ` ${decimal.format(average)} · ${ratingsText(count)}`);
  node.setAttribute('role', 'img');
  node.setAttribute('aria-label', `${decimal.format(average)} de 5 estrellas · ${ratingsText(count)}`);
  target.replaceChildren(node);
}

// Foto de perfil, o un círculo con la inicial si la persona no subió una.
function avatar(url, name, size = '') {
  const className = `avatar ${size}`.trim();
  if (url) return el('img', { className, src: url, alt: '', loading: 'lazy', decoding: 'async' });
  const node = el('span', { className: `${className} initials`, textContent: (name || '?').trim().charAt(0).toUpperCase() });
  node.setAttribute('aria-hidden', 'true');
  return node;
}

function reviewItem(review) {
  return el('article', { className: 'review' },
    el('div', { className: 'review-head' },
      el('span', { className: 'who' }, avatar(review.rater_photo, review.rater_name, 'xs'),
        el('a', { href: `#perfil-${review.rater_id}`, textContent: review.rater_name })),
      el('span', { className: 'review-date', textContent: when(review.updated) })),
    el('p', { className: 'review-rating' }, starsText(Number(review.stars)),
      ROLE_TEXT[review.role] && el('span', { className: 'muted', textContent: ` · ${ROLE_TEXT[review.role]}` })),
    review.comment && el('p', { textContent: review.comment }));
}

/* Conductores contactados: quedan en este navegador para poder calificarlos después del viaje,
   cuando el viaje ya no aparece en la lista. */
const CONTACTS_KEY = 'asiento-vacio-contactados';
function readContacts() {
  try {
    const list = JSON.parse(localStorage.getItem(CONTACTS_KEY) || '[]');
    return Array.isArray(list) ? list.filter((c) => /^[\w-]{1,64}$/.test(c?.id) && typeof c.name === 'string'
      && typeof c.trip === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(c.day)) : [];
  } catch {
    return [];
  }
}

function rememberContact(trip) {
  const contact = { id: trip.driver_id, name: trip.driver, trip: `${trip.origin} → ${trip.destination}`, day: trip.day };
  const list = [contact, ...readContacts().filter((c) => c.id !== contact.id)].slice(0, 20);
  try {
    localStorage.setItem(CONTACTS_KEY, JSON.stringify(list));
  } catch {
    // Sin almacenamiento (modo privado): no se guarda la lista.
  }
}

function renderContacts() {
  const list = readContacts().filter((c) => c.id !== state.user.id);
  $('#contacted-box').hidden = !list.length;
  $('#contacted').replaceChildren(...list.map((c) => el('li', {},
    el('a', { href: `#perfil-${c.id}`, textContent: c.name }),
    el('span', { className: 'muted', textContent: ` · ${c.trip} · ${formatDay(c.day)}` }))));
}

// La foto sale de la cuenta de Google: se puede quitar y volver a mostrar.
function renderMyPhoto() {
  const me = state.user;
  $('#me-photo').replaceChildren(avatar(me.photo, me.name, 'lg'));
  $('#photo-google').hidden = Boolean(me.photo) || !me.googlePicture;
  $('#photo-delete').hidden = !me.photo;
}

async function photoAction(action, done) {
  const buttons = document.querySelectorAll('.photo-actions button');
  buttons.forEach((node) => { node.disabled = true; });
  try {
    const result = await api(action, { body: {} });
    state.user = result.user;
    state.tripsLoaded = false;
    renderMyPhoto();
    toast(done);
  } catch (error) {
    toast(error.message);
  } finally {
    buttons.forEach((node) => { node.disabled = false; });
  }
}

async function deletePhoto() {
  if (!(await ask('¿Quitar tu foto de perfil? No se va a volver a poner sola cuando entres con Google.', 'Quitar foto'))) return;
  await photoAction('photo_delete', 'Listo: quitaste tu foto.');
}

async function loadProfile() {
  const me = state.user;
  renderMyPhoto();
  $('#me-name').textContent = me.name;
  $('#me-google').hidden = !me.google;
  $('#me-meta').textContent = `${me.email} · ${memberSince(me.created)}`;
  $('#me-link').value = profileUrl(me.id);
  $('#name-form').elements.namedItem('name').value = me.name;
  renderContacts();
  let data;
  try {
    data = await api('profile', { query: `&id=${encodeURIComponent(me.id)}` });
  } catch (error) {
    toast(error.message);
    return;
  }
  // La foto puede haber cambiado desde otro lado (por ejemplo, la quitó la administración).
  state.user.photo = data.profile.photo;
  renderMyPhoto();
  roleRatings($('#me-rating'), data.profile, 'todavía sin calificaciones');
  $('#me-reviews').replaceChildren(...data.reviews.map(reviewItem));
}

async function saveName(event) {
  event.preventDefault();
  const form = event.currentTarget;
  await busy(form, async () => {
    const result = await api('profile_name', { body: { name: form.elements.namedItem('name').value.trim() } });
    state.user = result.user;
    state.tripsLoaded = false;
    toast('Listo: cambiaste tu nombre.');
    loadProfile();
  });
}

async function sendRating(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const choice = new FormData(form);
  const role = choice.get('role');
  const stars = Number(choice.get('stars'));
  if (!ROLE_TEXT[role]) {
    showError(form, 'Elegí si manejaba o si viajó como acompañante.');
    return;
  }
  if (!stars) {
    showError(form, 'Elegí de 1 a 5 estrellas.');
    return;
  }
  await busy(form, async () => {
    await api('rate', { body: { user: form.dataset.user, role, stars, comment: form.comment.value.trim() } });
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

let profileRequest = 0;
async function loadPublicProfile(id) {
  const form = $('#rate-form');
  const ticket = ++profileRequest;
  if (form.dataset.user !== id) {
    // Otro perfil: no se deja a la vista (ni para calificar) el anterior mientras carga.
    form.hidden = true;
    form.dataset.user = '';
    $('#pp-name').textContent = 'Cargando…';
    for (const node of ['#pp-photo', '#pp-rating', '#pp-reviews', '#pp-trips']) $(node).replaceChildren();
    $('#pp-meta').textContent = '';
  }
  let data;
  try {
    data = await api('profile', { query: `&id=${encodeURIComponent(id)}` });
  } catch (error) {
    if (ticket !== profileRequest) return;
    $('#pp-name').textContent = error.message;
    for (const node of ['#pp-photo', '#pp-rating', '#pp-reviews', '#pp-trips']) $(node).replaceChildren();
    $('#pp-meta').textContent = '';
    form.hidden = true;
    $('#rate-login').hidden = true;
    $('#pp-empty').hidden = true;
    return;
  }
  if (ticket !== profileRequest) return;  // llegó tarde: ya se pidió otro perfil
  const { profile } = data;
  $('#pp-photo').replaceChildren(avatar(profile.photo, profile.name, 'lg'));
  $('#pp-name').textContent = profile.name;
  roleRatings($('#pp-rating'), profile, 'todavía sin calificaciones');
  $('#pp-meta').textContent = memberSince(profile.created);
  const canRate = Boolean(state.user) && state.user.id !== profile.id;
  form.hidden = !canRate;
  $('#rate-login').hidden = Boolean(state.user);
  if (canRate) {
    form.reset();
    showError(form, '');
    form.dataset.user = profile.id;
    $('#rate-name').textContent = profile.name;
    form.querySelectorAll('.rate-who').forEach((node) => { node.textContent = profile.name; });
    if (data.mine) {
      form.querySelector(`input[name="stars"][value="${data.mine.stars}"]`).checked = true;
      const role = form.querySelector(`input[name="role"][value="${data.mine.role}"]`);
      if (role) role.checked = true;
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
    state.next = state.returnTo || 'mis-viajes';
  }
  state.returnTo = '';
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
  $('#setup-form').addEventListener('submit', setupGoogle);
  $('#setup-show').addEventListener('click', () => {
    state.fixingSetup = true;
    renderGoogle();
    $('#setup-form [name=code]').focus();
  });
  $('#logout').addEventListener('click', logout);
  $('#report-form').addEventListener('submit', sendReport);
  $('#report-cancel').addEventListener('click', () => $('#report-dlg').close());
  $('#claim').addEventListener('submit', claim);
  $('#admin-tabs').addEventListener('click', (event) => {
    if (event.target.dataset.tab) selectAdminTab(event.target.dataset.tab);
  });
  $('#admin-tabs').addEventListener('keydown', moveAdminTab);
  $('#name-form').addEventListener('submit', saveName);
  $('#filter-trips').addEventListener('input', renderAdminTrips);
  $('#filter-users').addEventListener('input', renderUsers);
  $('#filter-role').addEventListener('change', renderUsers);
  $('#copy-link').addEventListener('click', copyProfileLink);
  // Después de entrar, se vuelve al perfil que se quería calificar.
  $('#rate-login a').addEventListener('click', () => { state.returnTo = location.hash.slice(1); });
  $('#photo-google').addEventListener('click', () => photoAction('photo_google', 'Listo: se ve de nuevo tu foto de Google.'));
  $('#photo-delete').addEventListener('click', deletePhoto);
  $('#rate-form').addEventListener('submit', sendRating);
  $('#rate-form').addEventListener('change', (event) => showError(event.currentTarget, ''));
  $('#rate-delete').addEventListener('click', deleteRating);
  $('#filter-ratings').addEventListener('change', renderRatings);
  $('#settings-form').addEventListener('submit', saveSettings);
  fillGoogleHelp();
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
