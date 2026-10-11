'use strict';

// Misma regla que el servidor: los avisos no llevan precios ni montos.
const PRICE_WORDS = /\$|\b(precios?|tarifas?|pesos|usd|d[oó]lar(es)?)\b/i;
const VIEWS = ['viajes', 'publicar', 'mis-viajes', 'entrar'];
const PRIVATE_VIEWS = ['publicar', 'mis-viajes'];

const state = { csrf: '', today: '', user: null, trips: [], mine: [], tripsLoaded: false, next: 'viajes' };
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
  if (response.status === 403 && body && !retried) {
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
  const tel = `tel:+${trip.phone_digits}`;
  $('.phone', card).href = tel;
  $('.phone span', card).textContent = trip.phone;
  $('.btn-call', card).href = tel;
  const message = `Hola ${trip.driver}, vi en Asiento Vacío tu viaje de ${trip.origin} a ${trip.destination} `
    + `del ${longDay.format(utcDate(trip.day)).replace(',', '')} a las ${trip.hour}. ¿Te queda algún asiento libre?`;
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
  const list = state.trips.filter((t) => (!from || normalize(t.origin).includes(from))
    && (!to || normalize(t.destination).includes(to))
    && (!day || t.day === day));
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
  if (!confirm(`¿Borrar el viaje de ${trip.origin} a ${trip.destination}? No se puede deshacer.`)) return;
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
  if (!form.phone.value) {
    if (!state.mine.length) await loadMine();
    // Reusamos los datos del último viaje para no tener que escribirlos de nuevo.
    const last = [...state.mine].sort((a, b) => b.created - a.created)[0];
    if (last) {
      form.driver.value = last.driver;
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
    go(form.id === 'register' && state.next === 'mis-viajes' ? 'publicar' : state.next);
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

/* Navegación */
function go(view) {
  if (location.hash === `#${view}`) route();
  else location.hash = view;
}

function route() {
  let view = location.hash.slice(1);
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
