'use strict';

// Misma regla que el servidor: los avisos no llevan precios ni montos.
const PRICE_WORDS = /\$|\b(precios?|tarifas?|pesos|usd|d[oó]lar(es)?)\b/i;
const VIEWS = ['viajes', 'publicar', 'mis-viajes', 'perfil', 'perfil-publico', 'admin', 'entrar'];
const PRIVATE_VIEWS = ['publicar', 'mis-viajes', 'perfil', 'admin'];
const REJECT_REASONS = {
  ilegible: 'Las fotos no se ven bien',
  datos: 'El DNI no coincide con el nombre de la cuenta',
  selfie: 'La selfie no muestra la cara junto al DNI',
  otro: 'Otro motivo',
};
const REASONS = { precio: 'Cobra un precio o parece un negocio', datos: 'Teléfono o datos falsos', ofensivo: 'Ofensivo o peligroso', otro: 'Otro motivo' };

const state = { csrf: '', today: '', user: null, trips: [], mine: [], tripsLoaded: false, next: 'viajes' };
const $ = (selector, root = document) => root.querySelector(selector);

async function api(action, { body, form, query = '', retried = false } = {}) {
  const options = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
  if (body || form) {
    options.method = 'POST';
    options.headers['X-CSRF-Token'] = state.csrf;
    if (form) {
      // Con fotos: el navegador arma el envío multipart solo.
      options.body = form;
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
  }
  let response;
  try {
    response = await fetch(`api.php?a=${action}${query}`, options);
  } catch {
    throw new Error('No hay conexión. Revisá internet e intentá de nuevo.');
  }
  const data = await response.json().catch(() => ({}));
  // Si la sesión venció, pedimos un token nuevo y reintentamos una sola vez.
  if (response.status === 403 && data.csrf && (body || form) && !retried) {
    await loadSession();
    return api(action, { body, form, query, retried: true });
  }
  if (!response.ok) throw new Error(data.error || 'No se pudo completar la operación. Intentá nuevamente.');
  return data;
}

async function loadSession() {
  const session = await api('session');
  state.csrf = session.csrf;
  state.today = session.today;
  state.user = session.user;
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
  $('.driver .verified', card).hidden = !trip.driver_verified;
  const tel = `tel:+${trip.phone_digits}`;
  $('.phone', card).href = tel;
  $('.phone span', card).textContent = trip.phone;
  $('.btn-call', card).href = tel;
  let message = `Hola ${trip.driver}, vi en Asiento Vacío tu viaje de ${trip.origin} a ${trip.destination} `
    + `del ${longDay.format(utcDate(trip.day)).replace(',', '')} a las ${trip.hour}. ¿Te queda algún asiento libre?`;
  // Un acompañante verificado manda su perfil, así el conductor ve la insignia.
  if (state.user?.verified && !trip.mine) message += ` Mi perfil verificado: ${profileUrl(state.user.id)}`;
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
  const verifiedOnly = filters.get('verified') === 'on';
  const list = state.trips.filter((t) => (!from || normalize(t.origin).includes(from))
    && (!to || normalize(t.destination).includes(to))
    && (!day || t.day === day)
    && (!verifiedOnly || t.driver_verified));
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
  if (!form.driver.value) form.driver.value = state.user.name;
  applyVerifiedName(form);
  if (!form.phone.value) {
    if (!state.mine.length) await loadMine();
    // Reusamos los datos del último viaje para no tener que escribirlos de nuevo.
    const last = [...state.mine].sort((a, b) => b.created - a.created)[0];
    if (last) {
      form.driver.value = last.driver;
      applyVerifiedName(form);
      form.phone.value = last.phone;
      if (!form.vehicle.value) form.vehicle.value = last.vehicle;
    }
  }
}

function publishData(form) {
  const field = (name) => form[name].value.trim();
  return {
    origin: field('origin'), destination: field('destination'), day: field('day'), hour: field('hour'),
    seats: Number(form.seats.value), driver: field('driver'), phone: field('phone'),
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
    state.csrf = result.csrf;
    state.user = result.user;
    state.mine = [];
    form.reset();
    updateAuthUI();
    toast(form.id === 'register' ? `¡Bienvenido/a, ${result.user.name}!` : `Hola, ${result.user.name}.`);
    go(form.id === 'register' && state.next === 'mis-viajes' ? 'perfil' : state.next);
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
const admin = { trips: [], users: [] };
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
  let overview, reports, trips, users, verifications;
  try {
    [overview, reports, trips, users, verifications] = await Promise.all(
      ['admin_overview', 'admin_reports', 'admin_trips', 'admin_users', 'admin_verifications'].map((a) => api(a)));
  } catch (error) {
    toast(error.message);
    return;
  }
  $('#st-upcoming').textContent = overview.upcoming;
  $('#st-week').textContent = overview.week;
  $('#st-drivers').textContent = overview.drivers;
  $('#st-reports').textContent = overview.reports;
  $('#admin-tabs [data-tab="reports"]').textContent = overview.reports ? `Denuncias (${overview.reports})` : 'Denuncias';
  $('#admin-tabs [data-tab="verifications"]').textContent = overview.verifications ? `Verificaciones (${overview.verifications})` : 'Verificaciones';
  admin.trips = trips.items;
  admin.users = users.items;
  renderReports(reports.items);
  renderVerifications(verifications.items);
  renderAdminTrips();
  renderUsers();
}

function selectAdminTab(name) {
  document.querySelectorAll('#admin-tabs [data-tab]').forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.tab === name)));
  for (const pane of ['reports', 'verifications', 'trips', 'users']) $(`#pane-${pane}`).hidden = pane !== name;
}

function showTripsOf(email) {
  $('#filter-trips').value = email;
  renderAdminTrips();
  selectAdminTab('trips');
}

function showUser(email) {
  $('#filter-users').value = email;
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
    Number(trip.owner_blocked) === 1 && badge('Conductor bloqueado', 'bad'),
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

function renderUsers() {
  const q = normalize($('#filter-users').value);
  const list = admin.users.filter((u) => !q || normalize(`${u.name} ${u.email}`).includes(q));
  const rows = list.map((u) => {
    const self = u.id === state.user.id;
    const isAdmin = Number(u.is_admin) === 1;
    const blocked = Number(u.blocked) === 1;
    const verified = Number(u.verified) === 1;
    const trips = Number(u.trips);
    return el('article', { className: 'item' },
      el('div', { className: 'badges' }, isAdmin && badge('Administración'), verified && badge('Verificado', 'verified'),
        blocked && badge('Bloqueado', 'bad'), self && badge('Vos', 'off')),
      el('p', { className: 'item-title', textContent: u.name }),
      el('p', { className: 'item-meta', textContent: `${u.email} · desde el ${when(u.created)} · ${trips === 1 ? '1 viaje' : `${trips} viajes`}` }),
      el('div', { className: 'item-actions' },
        button('Ver viajes', () => showTripsOf(u.email)),
        !self && !isAdmin && (blocked
          ? button('Desbloquear', () => adminAction('admin_user', { id: u.id, action: 'unblock' }, { done: 'Cuenta desbloqueada.' }))
          : button('Bloquear', () => adminAction('admin_user', { id: u.id, action: 'block' },
            { question: `¿Bloquear a ${u.name}? Sus viajes dejan de verse y no va a poder entrar.`, ok: 'Bloquear', done: 'Cuenta bloqueada.' }), 'btn-danger')),
        !self && !blocked && (isAdmin
          ? button('Quitar administración', () => adminAction('admin_user', { id: u.id, action: 'remove_admin' }, { done: 'Ya no es administrador.' }))
          : button('Hacer administrador', () => adminAction('admin_user', { id: u.id, action: 'make_admin' },
            { question: `¿Darle acceso de administración a ${u.name}?`, ok: 'Dar acceso', done: 'Ahora es administrador.' }))),
        verified && button('Quitar verificación', () => adminAction('admin_user', { id: u.id, action: 'unverify' },
          { question: `¿Quitarle la insignia de perfil verificado a ${u.name}?`, ok: 'Quitar verificación', done: 'Ya no figura como verificado.' }))));
  });
  $('#admin-users').replaceChildren(...(rows.length ? rows : [emptyRow('No hay conductores que coincidan.')]));
}

function renderVerifications(items) {
  const rows = items.map((v) => {
    const reason = el('select', { ariaLabel: 'Motivo del rechazo' },
      el('option', { value: '', textContent: 'Motivo para rechazar…' }),
      ...Object.entries(REJECT_REASONS).map(([value, text]) => el('option', { value, textContent: text })));
    const photos = el('div', { className: 'photos' }, ...v.files.map((file, i) => {
      const img = el('img', { alt: ['DNI de frente', 'DNI de dorso', 'Selfie con el DNI'][i] });
      img.addEventListener('click', () => img.classList.toggle('zoom'));
      loadPhoto(img, file);
      return el('figure', {}, img, el('figcaption', { textContent: img.alt }));
    }));
    return el('article', { className: 'item' },
      el('div', { className: 'badges' }, badge(`Enviada el ${when(v.created)}`, 'off')),
      el('p', { className: 'item-title', textContent: v.name }),
      el('p', { className: 'item-meta', textContent: `${v.email} · en Asiento Vacío desde el ${when(v.member_since)}` }),
      photos,
      el('div', { className: 'decide' },
        button('Aprobar', () => adminAction('admin_verification', { id: v.id, action: 'approve' },
          { question: `¿Aprobar a ${v.name}? Las fotos se borran y su perfil muestra la insignia.`, ok: 'Aprobar', done: 'Perfil verificado. Las fotos se borraron.' }), ''),
        reason,
        button('Rechazar', () => {
          if (!reason.value) {
            toast('Elegí el motivo del rechazo.');
            reason.focus();
            return;
          }
          adminAction('admin_verification', { id: v.id, action: 'reject', reason: reason.value }, { done: 'Verificación rechazada. Las fotos se borraron.' });
        }, 'btn-danger')));
  });
  $('#admin-verifications').replaceChildren(...(rows.length ? rows : [emptyRow('No hay verificaciones pendientes.')]));
}

// Las fotos se piden con la sesión y se muestran desde memoria: nunca tienen una dirección pública.
async function loadPhoto(img, file) {
  try {
    const response = await fetch(`api.php?a=admin_photo&file=${encodeURIComponent(file)}`, { credentials: 'same-origin' });
    if (response.ok) img.src = URL.createObjectURL(await response.blob());
  } catch {
    img.alt += ' (no se pudo cargar)';
  }
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

function applyVerifiedName(form) {
  // Con el perfil verificado, los viajes salen con el nombre que se verificó.
  const verified = Boolean(state.user?.verified);
  form.driver.readOnly = verified;
  if (verified) form.driver.value = state.user.name;
  form.driver.title = verified ? 'Tu perfil está verificado: se usa el nombre de tu cuenta.' : '';
}

async function loadProfile() {
  let data;
  try {
    data = await api('verification');
  } catch (error) {
    toast(error.message);
    return;
  }
  state.user = data.user;
  const verified = data.user.verified;
  const pending = data.request?.status === 'pending';
  $('#me-name').textContent = data.user.name;
  $('#me-badge').hidden = !verified;
  $('#me-meta').textContent = `${data.user.email} · ${memberSince(data.user.created)}`;
  $('#verify-done').hidden = !verified;
  $('#me-link').value = profileUrl(data.user.id);
  $('#verify-pending').hidden = verified || !pending;
  $('#verify-form').hidden = verified || pending;
  const rejected = !verified && data.request?.status === 'rejected';
  $('#verify-rejected').hidden = !rejected;
  $('#verify-rejected').textContent = rejected ? `No pudimos verificar tu perfil: ${data.request.reason} Podés intentar de nuevo.` : '';
}

async function sendVerification(event) {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.checkValidity()) {
    showError(form, 'Elegí las tres fotos y aceptá que la administración las vea.');
    return;
  }
  await busy(form, async () => {
    await api('verify_request', { form: new FormData(form) });
    form.reset();
    toast('Listo: tus fotos quedaron en revisión.');
    await loadProfile();
  });
}

async function cancelVerification() {
  if (!(await ask('¿Cancelar la verificación? Tus fotos se borran.', 'Cancelar y borrar'))) return;
  try {
    await api('verify_cancel', { body: {} });
    toast('Listo: tus fotos se borraron.');
  } catch (error) {
    toast(error.message);
  }
  await loadProfile();
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
  let data;
  try {
    data = await api('profile', { query: `&id=${encodeURIComponent(id)}` });
  } catch (error) {
    $('#pp-name').textContent = error.message;
    $('#pp-verified').hidden = true;
    $('#pp-unverified').hidden = true;
    $('#pp-meta').textContent = '';
    $('#pp-trips').replaceChildren();
    $('#pp-empty').hidden = true;
    return;
  }
  $('#pp-name').textContent = data.profile.name;
  $('#pp-verified').hidden = !data.profile.verified;
  $('#pp-unverified').hidden = data.profile.verified;
  $('#pp-meta').textContent = memberSince(data.profile.created)
    + (data.profile.verified ? ` · Verificado el ${when(data.profile.verifiedAt)}` : '');
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
  $('#verify-form').addEventListener('submit', sendVerification);
  $('#verify-cancel').addEventListener('click', cancelVerification);
  $('#copy-link').addEventListener('click', copyProfileLink);
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
