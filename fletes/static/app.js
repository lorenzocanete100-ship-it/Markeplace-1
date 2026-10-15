'use strict';
// Bellandi frontend: no build step, no inline scripts (strict CSP), DOM built with el() so user text is never parsed as HTML.

const state = { csrf: '', user: null, catalog: null, demo: false, quote: null, draft: null, afterLogin: null };
const $view = document.getElementById('view');
const money = n => new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 }).format(n);
const fmtDate = iso => new Date(iso + 'T12:00:00').toLocaleDateString('es-AR', { weekday: 'short', day: 'numeric', month: 'short' });
const fmtTime = ts => new Date(ts * 1000).toLocaleString('es-AR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const todayIso = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };
const km = n => `≈ ${n.toLocaleString('es-AR')} km`;

function el(tag, attrs, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else if (k === 'class') node.className = v;
    else if (v === true) node.setAttribute(k, '');
    else node.setAttribute(k, v);
  }
  for (const c of children.flat()) if (c !== null && c !== undefined && c !== false) node.append(c instanceof Node ? c : String(c));
  return node;
}

function toast(message) {
  const t = document.getElementById('toast');
  t.textContent = message; t.classList.add('show');
  clearTimeout(toast.timer); toast.timer = setTimeout(() => t.classList.remove('show'), 3800);
}

// In-page confirm/prompt: native dialogs are blocked in embedded previews and look out of place on phones.
function ask(message, { input = false, ok = 'Aceptar', cancel = 'Cancelar', placeholder = '' } = {}) {
  return new Promise(resolve => {
    const field = input && el('textarea', { maxlength: 300, placeholder, 'aria-label': message });
    const dialog = el('dialog', { class: 'ask' });
    const close = value => { dialog.close(); dialog.remove(); resolve(value); };
    dialog.append(el('form', { class: 'stack', method: 'dialog', onsubmit: e => { e.preventDefault(); close(input ? field.value.trim() : true); } },
      el('p', {}, message), field,
      el('div', { class: 'row' }, el('button', { class: 'btn', type: 'submit' }, ok), el('button', { class: 'btn ghost', type: 'button', onclick: () => close(null) }, cancel))));
    dialog.addEventListener('cancel', e => { e.preventDefault(); close(null); });
    document.body.append(dialog);
    dialog.showModal();
    (field || dialog.querySelector('button')).focus();
  });
}

async function api(path, { method = 'GET', body, form } = {}) {
  const opts = { method, credentials: 'same-origin', headers: {} };
  if (method !== 'GET') opts.headers['X-CSRF-Token'] = state.csrf;
  if (form) opts.body = form;
  else if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  let res;
  try { res = await fetch(path, opts); } catch { throw new Error('Sin conexión. Revisá internet e intentá de nuevo.'); }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) { const e = new Error(data.error || 'Algo salió mal.'); e.status = res.status; throw e; }
  return data;
}

async function busy(button, fn) {
  if (button) button.disabled = true;
  try { return await fn(); } catch (e) { toast(e.message); } finally { if (button) button.disabled = false; }
}

function waLink(phone, text) {
  // 10 digits = Argentine mobile without country code; anything else is assumed to include it (+598, +56…).
  const digits = String(phone).replace(/\D/g, '');
  return 'https://wa.me/' + (digits.length === 10 ? '549' + digits : digits) + '?text=' + encodeURIComponent(text);
}
const mapsLink = (address, city) => 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(address + ', ' + city);
const ext = (href, cls, text) => el('a', { class: cls, href, target: '_blank', rel: 'noopener' }, text);

function citySelect(name, value, { argentinaOnly = false } = {}) {
  const groups = {};
  for (const c of state.catalog.cities) {
    if (argentinaOnly && c.country !== 'AR') continue;
    (groups[c.region === 'Internacional' ? 'Internacional · ' + c.area : c.area] ||= []).push(c);
  }
  return el('select', { name, id: name, required: true },
    el('option', { value: '' }, 'Elegí una localidad'),
    Object.entries(groups).map(([area, cities]) => el('optgroup', { label: area },
      cities.map(c => el('option', { value: c.id, selected: c.id === value }, c.label)))));
}

function statusBadge(s) {
  const cls = { delivered: 'ok', cancelled: 'bad', pending_payment: 'warn' }[s.status] || '';
  return el('span', { class: 'badge ' + cls }, s.status_label);
}
const scopeBadge = s => el('span', { class: 'badge scope-' + s.scope }, s.scope_label);

function needLogin(next) {
  state.afterLogin = next || location.hash;
  location.hash = '#/ingresar';
}

// Floors, elevators and extras in one readable line.
function moveExtras(m) {
  const floor = (f, lift) => f === 0 ? 'planta baja' : `piso ${f}${lift ? ' con ascensor' : ' por escalera'}`;
  return [
    `Origen: ${floor(m.origin_floor, m.origin_elevator)}`, `Destino: ${floor(m.dest_floor, m.dest_elevator)}`,
    `${m.helpers} ayudante${m.helpers === 1 ? '' : 's'}`, m.packing && 'Con embalaje', m.assembly && 'Desarme y armado', m.shared && 'Camión compartido',
  ].filter(Boolean).join(' · ');
}

// ---------- navigation ----------
function renderNav() {
  const route = location.hash.split('/')[1] || '';
  const link = (href, text) => el('a', { href, class: ('#/' + route) === href ? 'on' : '' }, text);
  document.getElementById('nav').replaceChildren(...[
    link('#/', 'Cotizar'), link('#/seguimiento', 'Seguimiento'),
    state.user && link('#/mudanzas', 'Mis mudanzas'), link('#/chofer', 'Soy chofer'),
    state.user && state.user.admin && link('#/admin', 'Admin'),
    state.user ? el('button', { onclick: logout }, 'Salir') : link('#/ingresar', 'Ingresar')].filter(Boolean));
}

async function logout() {
  await busy(null, async () => {
    const r = await api('/api/auth/logout', { method: 'POST' });
    state.csrf = r.csrf; state.user = null; location.hash = '#/'; toast('Cerraste sesión.');
  });
}

const routes = {
  '': viewHome, 'ingresar': () => viewAuth('login'), 'registro': () => viewAuth('register'),
  'mudanzas': viewMyMoves, 'mudanza': viewMove, 'seguimiento': viewTrack, 'chofer': viewDriver,
};

async function router() {
  const [, name = '', arg] = location.hash.split('/');
  renderNav();
  $view.replaceChildren(el('p', { class: 'muted center' }, 'Cargando…'));
  try { await (routes[name] || viewHome)(arg); } catch (e) {
    if (e.status === 401) return needLogin();
    $view.replaceChildren(el('div', { class: 'card note bad' }, e.message));
  }
  $view.focus({ preventScroll: true });
}

// ---------- home: quote + order ----------
const EXAMPLES = {
  amba: ['palermo-caba', 'quilmes-gba-sur', 'Palermo → Quilmes'],
  nacional: ['caballito-caba', 'cordoba-cordoba', 'CABA → Córdoba'],
  internacional: ['caballito-caba', 'montevideo-uruguay', 'CABA → Montevideo'],
};

function viewHome() {
  const d = state.draft || { size: 'two', origin_floor: 0, dest_floor: 0 };
  const sizes = state.catalog.sizes;
  let helpersTouched = d.helpers !== undefined;
  const form = el('form', { class: 'stack card', novalidate: true },
    el('h2', {}, 'Cotizá tu mudanza'),
    el('fieldset', { class: 'sizes' }, el('legend', {}, '¿Qué tenés que mudar?'),
      sizes.map(s => el('label', {}, el('input', { type: 'radio', name: 'size', value: s.id, checked: d.size === s.id }),
        el('strong', {}, s.label), el('small', {}, s.hint), el('small', { class: 'm3' }, `≈ ${s.m3} m³`)))),
    el('div', { class: 'grid' },
      el('label', {}, 'Desde', citySelect('origin', d.origin)),
      el('label', {}, 'Hasta', citySelect('destination', d.destination))),
    el('div', { class: 'grid' },
      el('fieldset', { class: 'floor' }, el('legend', {}, 'Piso en origen'),
        el('input', { type: 'number', name: 'origin_floor', min: 0, max: 40, inputmode: 'numeric', value: d.origin_floor, 'aria-label': 'Piso en origen (0 = planta baja)' }),
        el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'origin_elevator', checked: d.origin_elevator }), 'Hay ascensor')),
      el('fieldset', { class: 'floor' }, el('legend', {}, 'Piso en destino'),
        el('input', { type: 'number', name: 'dest_floor', min: 0, max: 40, inputmode: 'numeric', value: d.dest_floor, 'aria-label': 'Piso en destino (0 = planta baja)' }),
        el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'dest_elevator', checked: d.dest_elevator }), 'Hay ascensor'))),
    el('label', {}, el('span', {}, 'Ayudantes para cargar y descargar ', el('small', {}, `(${money(state.catalog.helper_price)} c/u en Buenos Aires)`)),
      el('select', { name: 'helpers', id: 'helpers' }, Array.from({ length: state.catalog.max_helpers + 1 }, (_, n) =>
        el('option', { value: n }, n === 0 ? 'Sin ayudantes (cargo yo)' : `${n} ayudante${n > 1 ? 's' : ''}`)))),
    el('fieldset', { class: 'extras' }, el('legend', {}, 'Servicios extra'),
      el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'packing', checked: d.packing }), el('span', {}, 'Embalaje ', el('small', {}, 'cajas, film y papel; embalamos por vos'))),
      el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'assembly', checked: d.assembly }), el('span', {}, 'Desarme y armado de muebles ', el('small', {}, 'camas, placares, mesas'))),
      el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'shared', checked: d.shared }),
        el('span', {}, `Camión compartido (${Math.round(state.catalog.shared_discount * 100)} % menos) `, el('small', {}, `viajes de más de ${state.catalog.shared_min_km} km; fecha flexible ±3 días`)))),
    el('div', { class: 'grid' },
      el('label', {}, el('span', {}, 'Valor de lo que mudás ', el('small', {}, '(opcional)')),
        el('input', { name: 'declared_value', id: 'declared_value', type: 'number', min: 0, inputmode: 'numeric', placeholder: 'Ej. 3000000', value: d.declared_value || '' })),
      el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'insurance', checked: d.insurance }), 'Asegurar la mudanza (1,5 % del valor)')),
    el('button', { class: 'btn', type: 'submit' }, 'Cotizar'));
  form.helpers.value = helpersTouched ? d.helpers : sizes.find(s => s.id === d.size).helpers;
  const result = el('div');
  const read = () => ({
    size: form.size.value, origin: form.origin.value, destination: form.destination.value,
    origin_floor: Number(form.origin_floor.value || 0), dest_floor: Number(form.dest_floor.value || 0),
    origin_elevator: form.origin_elevator.checked, dest_elevator: form.dest_elevator.checked,
    helpers: Number(form.helpers.value), packing: form.packing.checked, assembly: form.assembly.checked, shared: form.shared.checked,
    declared_value: Number(form.declared_value.value || 0), insurance: form.insurance.checked,
  });
  const submit = () => busy(form.querySelector('[type=submit]'), async () => {
    state.draft = read();
    state.quote = await api('/api/quote', { method: 'POST', body: state.draft });
    result.replaceChildren(...quoteCards(state.quote, () => { form.shared.checked = true; submit(); }));
    result.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  form.addEventListener('change', e => {
    if (e.target.name === 'helpers') helpersTouched = true;
    if (e.target.name === 'size' && !helpersTouched) form.helpers.value = sizes.find(s => s.id === form.size.value).helpers;
    result.replaceChildren();
  });
  form.addEventListener('submit', e => { e.preventDefault(); submit(); });
  if (state.quote) result.append(...quoteCards(state.quote, () => { form.shared.checked = true; submit(); }));

  const example = scope => () => {
    const [o, dst] = EXAMPLES[scope];
    form.origin.value = o; form.destination.value = dst; result.replaceChildren(); form.origin.focus();
  };
  $view.replaceChildren(
    el('section', { class: 'hero' },
      el('h1', {}, 'Mudanzas en Buenos Aires, a todo el país y a países limítrofes'),
      el('p', {}, 'Precio cerrado antes de pedir, choferes con identidad y documentación verificadas, y pago protegido con Mercado Pago o tarjeta. Para mudanzas al exterior nos ocupamos de la aduana.'),
      el('div', { class: 'scopes' }, [
        ['amba', 'Dentro de Buenos Aires', 'CABA y todo el Gran Buenos Aires, en el día.'],
        ['nacional', 'A todo el país', '100 ciudades, con camión exclusivo o compartido.'],
        ['internacional', 'Países limítrofes', 'Uruguay, Chile, Paraguay, Bolivia y Brasil, con gestión aduanera.'],
      ].map(([k, title, text]) => el('button', { type: 'button', class: 'scope scope-' + k, onclick: example(k) },
        el('strong', {}, title), el('span', {}, text), el('small', {}, 'Ej. ' + EXAMPLES[k][2]))))),
    el('div', { class: 'two' }, form, el('div', {}, result, howItWorks())));
}

function howItWorks() {
  return el('div', { class: 'card' }, el('h3', {}, '¿Cómo funciona?'),
    el('ol', { class: 'steps' },
      el('li', {}, 'Cotizá y elegí la fecha. El precio no cambia el día de la mudanza.'),
      el('li', {}, 'Pagá con Mercado Pago o tarjeta. El dinero queda retenido hasta que la mudanza llega.'),
      el('li', {}, 'Un chofer verificado toma la mudanza y te escribe por WhatsApp.'),
      el('li', {}, 'Quien recibe en destino le dicta el código de entrega al chofer para cerrar el viaje.')));
}

function customsCard(countryName) {
  return el('div', { class: 'card note' }, el('h3', {}, `Mudanza a ${countryName || 'otro país'}: qué vas a necesitar`),
    el('ul', {},
      el('li', {}, 'DNI o pasaporte vigente de quien se muda.'),
      el('li', {}, 'Inventario detallado de lo que viaja. Lo armás al pedir la mudanza y nosotros lo presentamos en la aduana.'),
      el('li', {}, 'Según el país, constancia de residencia o de cambio de domicilio para ingresar el menaje sin pagar impuestos.'),
      el('li', {}, 'No pueden viajar alimentos frescos, plantas, armas ni productos inflamables.')),
    el('p', { class: 'muted' }, 'El despachante de aduana te confirma la lista final antes del viaje. La gestión ya está incluida en el precio.'));
}

function quoteCards(q, onShared) {
  const lines = q.breakdown.filter(([, v]) => v > 0);
  const country = q.scope === 'internacional' && state.catalog.cities.find(c => (c.id === q.origin || c.id === q.destination) && c.country !== 'AR');
  const cards = [el('div', { class: 'card' },
    el('div', { class: 'row between' }, scopeBadge(q), el('span', { class: 'muted' }, km(q.distance_km))),
    q.crossing && el('p', { class: 'muted' }, 'Cruce de frontera: ' + q.crossing),
    el('p', { class: 'price' }, money(q.price)),
    el('table', { class: 'table' }, el('tbody', {}, lines.map(([k, v]) => el('tr', {}, el('td', {}, k), el('td', {}, money(v)))))),
    el('p', { class: 'muted' }, 'Precio final con IVA. El chofer no puede cobrarte nada extra el día de la mudanza.'),
    q.shared_available && !q.shared && el('button', { class: 'btn ghost', type: 'button', onclick: onShared },
      `Ahorrá ${Math.round(state.catalog.shared_discount * 100)} % del transporte con camión compartido`),
    el('button', { class: 'btn', type: 'button', onclick: () => state.user ? orderForm(q) : needLogin('#/') }, state.user ? 'Pedir esta mudanza' : 'Ingresá para pedir'))];
  if (country) cards.push(customsCard(state.catalog.countries[country.country]));
  return cards;
}

function orderForm(q) {
  const intl = q.scope === 'internacional';
  const form = el('form', { class: 'stack card', novalidate: true },
    el('h2', {}, 'Datos de la mudanza'),
    el('p', { class: 'muted' }, `${labelOf(q.origin)} → ${labelOf(q.destination)} · ${money(q.price)}`),
    el('div', { class: 'grid' },
      el('label', {}, q.shared ? 'Fecha aproximada (±3 días)' : 'Fecha de la mudanza', el('input', { type: 'date', name: 'pickup_date', id: 'pickup_date', min: todayIso(), required: true })),
      el('label', {}, 'Franja horaria de carga', el('select', { name: 'slot', id: 'slot' }, Object.entries(state.catalog.slots).map(([k, v]) => el('option', { value: k }, v))))),
    el('label', {}, 'Dirección de origen', el('input', { name: 'origin_address', id: 'origin_address', maxlength: 150, autocomplete: 'street-address', placeholder: 'Calle, número, piso/depto', required: true })),
    el('label', {}, 'Dirección de destino', el('input', { name: 'dest_address', id: 'dest_address', maxlength: 150, placeholder: 'Calle, número, piso/depto', required: true })),
    el('label', {}, intl ? 'Inventario detallado (lo presentamos en la aduana)' : '¿Qué hay que llevar?',
      el('textarea', { name: 'inventory', id: 'inventory', maxlength: 4000, rows: intl ? 7 : 4, required: true,
        placeholder: intl ? 'Una línea por cosa, con cantidad. Ej.:\n1 heladera Whirlpool\n1 cama 2 plazas con colchón\n12 cajas de ropa y libros\n1 bicicleta' : 'Ej. heladera, lavarropas, cama de 2 plazas, 10 cajas. El sillón no entra por el ascensor.' })),
    el('div', { class: 'grid' },
      el('label', {}, 'Quién recibe en destino', el('input', { name: 'receiver_name', id: 'receiver_name', maxlength: 80, placeholder: 'Nombre y apellido (puede ser tuyo)', required: true })),
      el('label', {}, 'Celular de quien recibe', el('input', { name: 'receiver_phone', id: 'receiver_phone', type: 'tel', inputmode: 'tel', placeholder: intl ? '+598 99 123 456' : '11 2345 6789', required: true }))),
    el('div', { class: 'row' }, el('button', { class: 'btn', type: 'submit' }, 'Confirmar y pagar'), el('button', { class: 'btn ghost', type: 'button', onclick: viewHome }, 'Volver')));
  form.addEventListener('submit', e => {
    e.preventDefault();
    busy(form.querySelector('[type=submit]'), async () => {
      const body = { ...state.draft };
      for (const k of ['pickup_date', 'slot', 'origin_address', 'dest_address', 'inventory', 'receiver_name', 'receiver_phone']) body[k] = form[k].value;
      const r = await api('/api/moves', { method: 'POST', body });
      state.quote = null; state.draft = null;
      location.hash = '#/mudanza/' + r.id;
    });
  });
  $view.replaceChildren(form);
  form.pickup_date.focus();
}

const labelOf = id => (state.catalog.cities.find(c => c.id === id) || {}).label || id;

// ---------- auth ----------
function viewAuth(mode) {
  const register = mode === 'register';
  const form = el('form', { class: 'stack card' },
    el('h2', {}, register ? 'Creá tu cuenta' : 'Ingresá'),
    register && el('label', {}, 'Nombre y apellido', el('input', { name: 'name', id: 'name', autocomplete: 'name', required: true, maxlength: 80 })),
    register && el('label', {}, 'Celular (WhatsApp)', el('input', { name: 'phone', id: 'phone', type: 'tel', autocomplete: 'tel', inputmode: 'tel', placeholder: '11 2345 6789', required: true })),
    el('label', {}, 'Correo', el('input', { name: 'email', id: 'email', type: 'email', autocomplete: 'email', required: true })),
    el('label', {}, 'Contraseña', el('input', { name: 'password', id: 'password', type: 'password', autocomplete: register ? 'new-password' : 'current-password', minlength: 10, required: true }),
      register && el('small', {}, 'Mínimo 10 caracteres.')),
    el('button', { class: 'btn', type: 'submit' }, register ? 'Crear cuenta' : 'Ingresar'),
    el('p', { class: 'muted' }, register ? '¿Ya tenés cuenta? ' : '¿Primera vez? ', el('a', { href: register ? '#/ingresar' : '#/registro' }, register ? 'Ingresá' : 'Creá tu cuenta')));
  form.addEventListener('submit', e => {
    e.preventDefault();
    busy(form.querySelector('[type=submit]'), async () => {
      const body = { email: form.email.value, password: form.password.value };
      if (register) { body.name = form.name.value; body.phone = form.phone.value; }
      const r = await api('/api/auth/' + mode, { method: 'POST', body });
      state.csrf = r.csrf; state.user = r.user;
      const next = state.afterLogin || '#/mudanzas'; state.afterLogin = null;
      toast(register ? '¡Bienvenido/a a Bellandi!' : 'Hola de nuevo.');
      if (location.hash === next) router(); else location.hash = next;
    });
  });
  $view.replaceChildren(el('div', { class: 'two' }, form, howItWorks()));
}

// ---------- client moves ----------
async function viewMyMoves() {
  if (!state.user) return needLogin();
  const { items } = await api('/api/moves');
  $view.replaceChildren(el('h1', {}, 'Mis mudanzas'),
    items.length ? el('div', { class: 'list' }, items.map(m => el('a', { class: 'card item', href: '#/mudanza/' + m.id },
      el('div', { class: 'row between' }, el('span', { class: 'route' }, `${m.origin_label} → ${m.destination_label}`), statusBadge(m)),
      el('span', { class: 'muted' }, `${m.code} · ${m.size_label} · ${m.scope_label} · ${fmtDate(m.pickup_date)} · ${money(m.price)}`))))
      : el('div', { class: 'card' }, el('p', {}, 'Todavía no pediste mudanzas.'), el('a', { class: 'btn', href: '#/' }, 'Cotizar ahora')));
}

const STATUS_TEXT = { pending_payment: 'Pedido creado', paid: 'Pago acreditado', assigned: 'Chofer asignado', in_transit: 'Cargada, en viaje', delivered: 'Entregada', cancelled: 'Cancelada' };
const statusText = s => STATUS_TEXT[s] || s;
const timeline = events => el('ol', { class: 'timeline' }, events.map(ev => el('li', {}, el('span', {}, statusText(ev.status)), el('small', { class: 'muted' }, fmtTime(ev.at)))));

async function viewMove(id) {
  if (!state.user) return needLogin();
  const m = await api('/api/moves/' + encodeURIComponent(id));
  const blocks = [el('div', { class: 'card' },
    el('div', { class: 'row between' }, el('h1', {}, m.code), el('div', { class: 'row' }, scopeBadge(m), statusBadge(m))),
    el('p', { class: 'route' }, `${m.origin_label} → ${m.destination_label}`),
    el('p', { class: 'muted' }, `${m.size_label} (≈ ${m.m3} m³) · ${m.vehicle_label} · ${km(m.distance_km)} · ${fmtDate(m.pickup_date)}, ${m.slot_label}${m.shared ? ' (±3 días)' : ''}`),
    m.crossing && el('p', { class: 'muted' }, 'Cruce de frontera: ' + m.crossing),
    el('p', {}, moveExtras(m)),
    el('p', {}, el('strong', {}, 'Origen: '), m.origin_address, el('br'), el('strong', {}, 'Destino: '), m.dest_address, el('br'),
      el('strong', {}, 'Recibe: '), `${m.receiver_name} (${m.receiver_phone})`),
    el('details', {}, el('summary', {}, m.scope === 'internacional' ? 'Inventario para la aduana' : 'Qué hay que llevar'), el('p', { class: 'pre' }, m.inventory)),
    m.price !== undefined && el('p', { class: 'price' }, money(m.price)))];

  if (m.mine && m.status === 'pending_payment') blocks.push(payCard(m));
  if (m.pin) blocks.push(el('div', { class: 'card' }, el('h3', {}, 'Código de entrega'), el('p', { class: 'pin' }, m.pin),
    el('p', { class: 'muted' }, 'Pasáselo solo a quien recibe en destino. El chofer lo pide al descargar: sin este código no puede cerrar la mudanza ni cobrar.')));
  if (m.driver) blocks.push(driverCard(m));
  if (m.scope === 'internacional' && !['delivered', 'cancelled'].includes(m.status)) {
    const foreign = state.catalog.cities.find(c => (c.id === m.origin || c.id === m.destination) && c.country !== 'AR');
    blocks.push(customsCard(foreign && state.catalog.countries[foreign.country]));
  }
  if (m.refund_status === 'pending') blocks.push(el('div', { class: 'card note' }, 'Cancelaste una mudanza pagada. Estamos procesando la devolución a tu medio de pago.'));
  if (m.refund_status === 'done') blocks.push(el('div', { class: 'card note ok' }, 'La devolución ya fue procesada.'));
  if (m.mine && m.status === 'delivered' && !m.rating) blocks.push(rateCard(m));
  if (m.rating) blocks.push(el('div', { class: 'card' }, el('h3', {}, 'Tu calificación'), el('p', {}, '★'.repeat(m.rating) + '☆'.repeat(5 - m.rating)), m.review && el('p', {}, m.review)));
  blocks.push(el('div', { class: 'card' }, el('h3', {}, 'Seguimiento'), timeline(m.events || [])));
  if (m.mine && ['pending_payment', 'paid', 'assigned'].includes(m.status)) {
    blocks.push(el('button', { class: 'btn danger', onclick: async e => {
      const b = e.target;
      const warn = m.status === 'pending_payment' ? '¿Cancelar este pedido?' : '¿Cancelar la mudanza? Te devolvemos el pago completo a tu medio de pago.';
      if (await ask(warn, { ok: 'Sí, cancelar', cancel: 'No' })) busy(b, async () => { await api(`/api/moves/${m.id}/cancel`, { method: 'POST' }); toast('Mudanza cancelada.'); router(); });
    } }, 'Cancelar mudanza'));
  }
  $view.replaceChildren(...blocks);
}

function payCard(m) {
  const pay = method => e => busy(e.target, async () => {
    const r = await api(`/api/moves/${m.id}/pay`, { method: 'POST', body: { method } });
    if (r.url) { location.href = r.url; return; }
    if (r.demo && await ask(`Modo demo: Mercado Pago no está configurado. ¿Simular un pago aprobado de ${money(m.price)}${method === 'card' ? ' con tarjeta' : ''}?`, { ok: 'Aprobar pago' })) {
      await api(`/api/moves/${m.id}/demo-pay`, { method: 'POST', body: { method } });
      toast('Pago simulado acreditado.'); router();
    }
  });
  return el('div', { class: 'card' }, el('h3', {}, 'Pagá para reservar la mudanza'),
    el('p', { class: 'muted' }, 'La mudanza se publica para los choferes cuando se acredita el pago. Si nadie la toma o la cancelás antes de cargar, te devolvemos todo.'),
    el('div', { class: 'row' },
      el('button', { class: 'btn mp', onclick: pay('mercadopago') }, 'Pagar con Mercado Pago'),
      el('button', { class: 'btn', onclick: pay('card') }, 'Tarjeta de crédito o débito')),
    el('p', { class: 'muted' }, 'Hasta 12 cuotas con tarjeta. Pagás en el sitio seguro de Mercado Pago.'),
    state.demo && el('p', { class: 'note' }, 'Modo demo activo: los pagos se simulan.'));
}

function driverCard(m) {
  const d = m.driver;
  return el('div', { class: 'card' }, el('h3', {}, 'Tu chofer'),
    el('div', { class: 'row' }, el('strong', {}, d.name),
      d.verified ? el('span', { class: 'badge ok' }, '✔ Verificado') : el('span', { class: 'badge bad' }, 'No verificado'),
      d.international && el('span', { class: 'badge' }, 'Habilitado internacional')),
    el('p', { class: 'muted' }, `${d.vehicle} · ${d.vehicle_model} · Patente ${d.plate}`),
    el('p', {}, d.rating ? `★ ${d.rating} (${d.rating_count} mudanzas calificadas)` : 'Todavía sin calificaciones'),
    el('p', { class: 'muted' }, 'Antes de cargar, revisá que la patente del camión coincida.'),
    ['assigned', 'in_transit'].includes(m.status) && ext(waLink(d.phone, `Hola ${d.name}, te escribo por la mudanza ${m.code} de Bellandi.`), 'btn wa', 'Escribir por WhatsApp'));
}

function rateCard(m) {
  let stars = 0;
  const buttons = [1, 2, 3, 4, 5].map(n => el('button', { type: 'button', 'aria-label': `${n} estrellas`, onclick: () => { stars = n; buttons.forEach((b, i) => b.classList.toggle('on', i < n)); } }, '★'));
  const review = el('textarea', { maxlength: 500, placeholder: '¿Cómo fue la mudanza? (opcional)' });
  return el('div', { class: 'card stack' }, el('h3', {}, 'Calificá al chofer'), el('div', { class: 'stars' }, buttons), review,
    el('button', { class: 'btn', onclick: e => busy(e.target, async () => {
      await api(`/api/moves/${m.id}/rate`, { method: 'POST', body: { stars, review: review.value } });
      toast('¡Gracias por calificar!'); router();
    }) }, 'Enviar calificación'));
}

// ---------- public tracking ----------
function viewTrack(code) {
  const out = el('div');
  const form = el('form', { class: 'card stack' }, el('h1', {}, 'Seguí tu mudanza'),
    el('label', {}, 'Código de seguimiento', el('input', { name: 'code', id: 'code', placeholder: 'MD-ABC123', value: code || '', autocapitalize: 'characters', required: true })),
    el('button', { class: 'btn', type: 'submit' }, 'Buscar'));
  const search = () => busy(form.querySelector('button'), async () => {
    const t = await api('/api/track/' + encodeURIComponent(form.code.value.trim()));
    out.replaceChildren(el('div', { class: 'card' },
      el('div', { class: 'row between' }, el('h2', {}, t.code), statusBadge(t)),
      el('p', { class: 'route' }, `${t.origin_label} → ${t.destination_label}`),
      el('p', { class: 'muted' }, `${t.scope_label} · Carga: ${fmtDate(t.pickup_date)}${t.crossing ? ' · Cruce: ' + t.crossing : ''}`),
      timeline(t.events)));
  });
  form.addEventListener('submit', e => { e.preventDefault(); search(); });
  $view.replaceChildren(form, out);
  if (code) search();
}

// ---------- driver ----------
async function viewDriver() {
  if (!state.user) {
    $view.replaceChildren(el('div', { class: 'two' }, driverPitch(), el('div', { class: 'card' }, el('h3', {}, 'Empezá hoy'),
      el('p', {}, 'Creá una cuenta y cargá tu documentación. La revisamos en 24 a 48 h hábiles.'),
      el('button', { class: 'btn', onclick: () => needLogin('#/chofer') }, 'Ingresar o crear cuenta'))));
    return;
  }
  const { driver } = await api('/api/driver/me');
  if (!driver) return $view.replaceChildren(el('div', { class: 'two' }, applyForm(), driverPitch()));
  if (driver.status === 'pending') return $view.replaceChildren(el('div', { class: 'card note' }, el('h2', {}, 'Documentación en revisión'),
    el('p', {}, 'Estamos verificando tu identidad, licencia, seguro, vehículo y antecedentes. Te avisamos por correo cuando esté lista (24 a 48 h hábiles).')));
  if (driver.status === 'rejected') return $view.replaceChildren(el('div', { class: 'card note bad' }, el('h2', {}, 'Hay que corregir algo'), el('p', {}, driver.review_note)), applyForm(driver));
  if (driver.status === 'suspended') return $view.replaceChildren(el('div', { class: 'card note bad' }, el('h2', {}, 'Cuenta suspendida'), el('p', {}, driver.review_note)));
  return driverDashboard(driver);
}

function driverPitch() {
  return el('div', { class: 'card' }, el('h2', {}, 'Hacé mudanzas con Bellandi'),
    el('p', {}, 'Tomá mudanzas en tu zona, de larga distancia o a países limítrofes. Cobrás seguro y elegís cuándo trabajar.'),
    el('h3', {}, 'Para verificarte necesitamos'),
    el('ul', {}, Object.values(state.catalog.docs).map(d => el('li', {}, d))),
    el('p', {}, el('strong', {}, 'Para mudanzas internacionales, además: '), Object.values(state.catalog.intl_docs).join(' y ') + '.'),
    el('p', { class: 'muted' }, 'Tus documentos solo los ve el equipo de verificación. Les borramos los metadatos (ubicación, dispositivo) al subirlos.'));
}

function applyForm(prev) {
  const file = (name, label, pdf) => el('label', {}, label, el('input', { type: 'file', name, id: name, accept: pdf ? 'image/*,application/pdf' : 'image/*' }));
  const intlFiles = el('div', { class: 'grid', hidden: !(prev && prev.international) }, Object.entries(state.catalog.intl_docs).map(([k, label]) => file(k, label, true)));
  const form = el('form', { class: 'card stack', novalidate: true },
    el('h2', {}, 'Verificación de chofer'),
    el('div', { class: 'grid' },
      el('label', {}, 'CUIL', el('input', { name: 'cuil', id: 'cuil', inputmode: 'numeric', placeholder: '20-12345678-9', required: true })),
      el('label', {}, 'Ciudad base', citySelect('base_city', prev && prev.base_city, { argentinaOnly: true }))),
    el('div', { class: 'grid' },
      el('label', {}, 'Vehículo', el('select', { name: 'vehicle', id: 'vehicle' }, state.catalog.vehicles.map(v => el('option', { value: v.id, selected: prev && prev.vehicle === v.id }, `${v.label} · hasta ${v.m3} m³`)))),
      el('label', {}, 'Marca y modelo', el('input', { name: 'vehicle_model', id: 'vehicle_model', placeholder: 'Mercedes-Benz Accelo 2019', value: prev && prev.vehicle_model || '', required: true })),
      el('label', {}, 'Patente', el('input', { name: 'plate', id: 'plate', placeholder: 'AB123CD', autocapitalize: 'characters', value: prev && prev.plate || '', required: true }))),
    el('div', { class: 'grid' },
      el('label', {}, 'Vencimiento de la licencia', el('input', { type: 'date', name: 'license_expiry', id: 'license_expiry', min: todayIso(), required: true })),
      el('label', {}, 'Vencimiento del seguro', el('input', { type: 'date', name: 'insurance_expiry', id: 'insurance_expiry', min: todayIso(), required: true }))),
    el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'long_distance', value: '1', checked: prev && prev.long_distance }), 'Hago mudanzas a otras provincias'),
    el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'international', value: '1', checked: prev && prev.international,
      onchange: e => { intlFiles.hidden = !e.target.checked; } }), 'Hago mudanzas a países limítrofes'),
    el('h3', {}, 'Documentos'),
    el('div', { class: 'grid' }, Object.entries(state.catalog.docs).map(([k, label]) => file(k, label, ['insurance', 'background'].includes(k)))),
    intlFiles,
    el('p', { class: 'muted' }, 'Fotos nítidas, sin reflejos y con los 4 bordes visibles. El certificado de antecedentes se tramita online en argentina.gob.ar (Registro Nacional de Reincidencia).'),
    el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'consent', required: true }), 'Declaro que los datos son verdaderos y autorizo su verificación.'),
    el('button', { class: 'btn', type: 'submit' }, 'Enviar documentación'));
  form.addEventListener('submit', e => {
    e.preventDefault();
    if (!form.consent.checked) return toast('Tenés que aceptar la declaración para continuar.');
    busy(form.querySelector('[type=submit]'), async () => {
      const fd = new FormData(form); fd.delete('consent');
      if (!form.international.checked) for (const k of Object.keys(state.catalog.intl_docs)) fd.delete(k);
      await api('/api/driver/apply', { method: 'POST', form: fd });
      toast('¡Listo! Revisamos tu documentación y te avisamos.'); router();
    });
  });
  return form;
}

async function driverDashboard(driver, tab = 'jobs') {
  const head = el('div', { class: 'card' },
    el('div', { class: 'row between' }, el('h1', {}, 'Panel de chofer'),
      el('div', { class: 'row' }, el('span', { class: 'badge ok' }, '✔ Verificado'), driver.international && el('span', { class: 'badge' }, 'Internacional'))),
    el('div', { class: 'stats' },
      el('div', {}, el('strong', {}, money(driver.earned)), 'cobrado'),
      el('div', {}, el('strong', {}, driver.trips), 'mudanzas entregadas'),
      el('div', {}, el('strong', {}, driver.rating ? '★ ' + driver.rating : '—'), `${driver.rating_count} calificaciones`)),
    driver.problems.map(p => el('p', { class: 'note bad' }, p)));
  const tabs = el('div', { class: 'tabs' },
    el('button', { class: tab === 'jobs' ? 'on' : '', onclick: () => driverDashboard(driver, 'jobs') }, 'Mudanzas disponibles'),
    el('button', { class: tab === 'trips' ? 'on' : '', onclick: () => driverDashboard(driver, 'trips') }, 'Mis mudanzas'));
  const body = el('div', { class: 'list' }, el('p', { class: 'muted' }, 'Cargando…'));
  $view.replaceChildren(head, tabs, body);
  const refresh = () => driverDashboard(driver, tab);
  if (tab === 'jobs') {
    const { items } = await api('/api/driver/jobs');
    body.replaceChildren(...(items.length ? items.map(j => el('div', { class: 'card item' },
      el('div', { class: 'row between' }, el('span', { class: 'route' }, `${j.origin_label} → ${j.destination_label}`), el('strong', {}, money(j.payout))),
      el('div', { class: 'row' }, scopeBadge(j), el('span', { class: 'muted' }, `${fmtDate(j.pickup_date)}, ${j.slot_label}${j.shared ? ' (±3 días)' : ''}`)),
      el('span', {}, `${j.size_label} (≈ ${j.m3} m³) · ${j.vehicle_label} · ${km(j.distance_km)} · a ${j.km_from_base} km de tu base`),
      j.crossing && el('span', { class: 'muted' }, 'Cruce: ' + j.crossing),
      el('span', { class: 'muted' }, moveExtras(j)),
      el('div', {}, el('button', { class: 'btn', onclick: e => busy(e.target, async () => {
        await api(`/api/moves/${j.id}/accept`, { method: 'POST' }); toast('¡Mudanza tomada! Escribile al cliente.'); driverDashboard(driver, 'trips');
      }) }, 'Tomar mudanza'))))
      : [el('div', { class: 'card' }, 'No hay mudanzas disponibles para tu vehículo y zona ahora. Volvé a mirar más tarde.')]));
  } else {
    const { items } = await api('/api/driver/trips');
    body.replaceChildren(...(items.length ? items.map(t => tripCard(t, refresh)) : [el('div', { class: 'card' }, 'Todavía no tomaste mudanzas.')]));
  }
}

function tripCard(t, refresh) {
  const act = (action, body) => e => busy(e.target, async () => { await api(`/api/moves/${t.id}/${action}`, { method: 'POST', body }); refresh(); });
  const pin = el('input', { inputmode: 'numeric', maxlength: 4, placeholder: 'Código', 'aria-label': 'Código de entrega' });
  const open = ['assigned', 'in_transit'].includes(t.status);
  return el('div', { class: 'card item' },
    el('div', { class: 'row between' }, el('span', { class: 'route' }, `${t.code} · ${t.origin_label} → ${t.destination_label}`), statusBadge(t)),
    el('span', { class: 'muted' }, `${fmtDate(t.pickup_date)}, ${t.slot_label} · ${t.size_label} (≈ ${t.m3} m³) · cobrás ${money(t.payout)}`),
    t.crossing && el('span', { class: 'muted' }, 'Cruce: ' + t.crossing),
    el('span', {}, moveExtras(t)),
    el('p', {}, el('strong', {}, 'Origen: '), ext(mapsLink(t.origin_address, t.origin_label), null, t.origin_address), el('br'),
      el('strong', {}, 'Destino: '), ext(mapsLink(t.dest_address, t.destination_label), null, t.dest_address), el('br'),
      el('strong', {}, 'Cliente: '), t.client.name, ' · ', el('strong', {}, 'Recibe: '), t.receiver_name),
    el('details', {}, el('summary', {}, t.scope === 'internacional' ? 'Inventario para la aduana' : 'Qué hay que llevar'), el('p', { class: 'pre' }, t.inventory)),
    open && el('div', { class: 'row' },
      ext(waLink(t.client.phone, `Hola ${t.client.name}, soy tu chofer de Bellandi para la mudanza ${t.code}.`), 'btn wa', 'WhatsApp cliente'),
      ext(waLink(t.receiver_phone, `Hola ${t.receiver_name}, soy el chofer de Bellandi de la mudanza ${t.code}.`), 'btn ghost', 'WhatsApp quien recibe')),
    t.status === 'assigned' && el('div', { class: 'row' },
      el('button', { class: 'btn', onclick: act('pickup') }, 'Ya cargué la mudanza'),
      el('button', { class: 'btn ghost', onclick: async e => { const b = e.target; if (await ask('¿Liberar la mudanza? Vuelve a quedar disponible para otros choferes.', { ok: 'Liberar' })) act('release')({ target: b }); } }, 'Liberar')),
    t.status === 'in_transit' && el('div', { class: 'row' }, pin,
      el('button', { class: 'btn', onclick: e => act('deliver', { pin: pin.value.trim() })(e) }, 'Confirmar entrega')));
}

// ---------- admin ----------
async function viewAdmin(tab = 'pending') {
  if (!state.user || !state.user.admin) return $view.replaceChildren(el('div', { class: 'card note bad' }, 'No autorizado.'));
  const tabs = el('div', { class: 'tabs' }, [['pending', 'Choferes por verificar'], ['approved', 'Verificados'], ['refunds', 'Devoluciones'], ['all', 'Mudanzas']]
    .map(([k, label]) => el('button', { class: tab === k ? 'on' : '', onclick: () => { location.hash = '#/admin/' + k; } }, label)));
  const body = el('div', { class: 'list' });
  $view.replaceChildren(el('h1', {}, 'Administración'), tabs, body);
  if (tab === 'refunds' || tab === 'all') {
    const { items } = await api('/api/admin/moves' + (tab === 'refunds' ? '?refunds=1' : ''));
    body.replaceChildren(...(items.length ? items.map(m => el('div', { class: 'card item' },
      el('div', { class: 'row between' }, el('a', { class: 'route', href: '#/mudanza/' + m.id }, `${m.code} · ${m.origin_label} → ${m.destination_label}`), statusBadge(m)),
      el('span', { class: 'muted' }, `${m.size_label} · ${m.scope_label} · ${money(m.price)} · ${m.payment_method || 'sin pago'} · cliente ${m.client.name} (${m.client.phone})`),
      m.refund_status === 'pending' && el('div', { class: 'row' }, el('span', { class: 'badge warn' }, 'Devolver dinero'),
        el('button', { class: 'btn ghost', onclick: async e => { const b = e.target; if (await ask('¿Ya hiciste la devolución desde Mercado Pago?', { ok: 'Sí, ya la hice' })) busy(b, async () => {
          await api(`/api/admin/moves/${m.id}/refunded`, { method: 'POST' }); viewAdmin(tab);
        }); } }, 'Marcar devuelto')))) : [el('div', { class: 'card' }, 'Nada por acá.')]));
    return;
  }
  const { items } = await api('/api/admin/drivers?status=' + tab);
  const review = (uid, action) => async e => {
    const b = e.target;
    const note = action === 'approve' ? '' : await ask(action === 'reject' ? 'Motivo del rechazo (lo ve el chofer):' : 'Motivo de la suspensión:',
      { input: true, ok: action === 'reject' ? 'Rechazar' : 'Suspender', placeholder: 'Ej. la foto del DNI está borrosa' });
    if (note === null) return;
    busy(b, async () => { await api('/api/admin/drivers/' + uid, { method: 'POST', body: { action, note } }); toast('Guardado.'); viewAdmin(tab); });
  };
  body.replaceChildren(...(items.length ? items.map(d => el('div', { class: 'card item' },
    el('div', { class: 'row between' }, el('strong', {}, d.name), el('span', { class: 'muted' }, d.email + ' · ' + d.phone)),
    el('span', {}, `CUIL ${d.cuil} · ${d.vehicle_label} ${d.vehicle_model} · Patente ${d.plate} · Base ${d.base_city_label}`),
    el('div', { class: 'row' }, d.long_distance ? el('span', { class: 'badge' }, 'Otras provincias') : el('span', { class: 'badge' }, 'Solo su zona'),
      d.international && el('span', { class: 'badge scope-internacional' }, 'Internacional')),
    el('span', { class: 'muted' }, `Licencia vence ${d.license_expiry} · Seguro vence ${d.insurance_expiry}`),
    el('div', { class: 'docs' }, d.documents.map(doc => el('a', { class: 'doc', href: doc.url, target: '_blank', rel: 'noopener' },
      doc.pdf ? el('span', { class: 'pdf' }, 'PDF') : el('img', { src: doc.url, alt: doc.label, loading: 'lazy' }), el('small', {}, doc.label)))),
    el('p', { class: 'muted' }, 'Compará la selfie con la foto del DNI, que nombre y CUIL coincidan en todos los documentos, que la patente de la cédula sea la declarada y que el certificado de antecedentes sea reciente y sin registros.' +
      (d.international ? ' Para internacional, revisá que el permiso esté vigente y que la póliza cubra los países limítrofes.' : '')),
    el('div', { class: 'row' },
      tab === 'pending' && el('button', { class: 'btn', onclick: review(d.user_id, 'approve') }, 'Aprobar'),
      tab === 'pending' && el('button', { class: 'btn ghost', onclick: review(d.user_id, 'reject') }, 'Rechazar'),
      el('button', { class: 'btn danger', onclick: review(d.user_id, 'suspend') }, 'Suspender')))) : [el('div', { class: 'card' }, 'No hay choferes en esta lista.')]));
}

// ---------- boot ----------
async function returnFromPayment() {
  // Mercado Pago sends the buyer back to /pago/<id>?payment_id=...&status=...
  const match = location.pathname.match(/^\/pago\/([a-f0-9]{32})$/);
  if (!match) return;
  const params = new URLSearchParams(location.search);
  const id = match[1];
  history.replaceState(null, '', '/#/mudanza/' + id);
  if (params.get('status') === 'rejected' || params.get('collection_status') === 'rejected') { toast('El pago fue rechazado. Probá con otro medio.'); return; }
  try {
    const r = await api(`/api/moves/${id}/confirm-payment`, { method: 'POST', body: { payment_id: params.get('payment_id') || params.get('collection_id') || '' } });
    toast(r.status === 'paid' ? '¡Pago acreditado! Ya estamos buscando chofer.' : 'Estamos esperando la confirmación del pago.');
  } catch (e) { toast(e.message); }
}

(async function boot() {
  try {
    const [session, catalog] = await Promise.all([api('/api/session'), api('/api/catalog')]);
    Object.assign(state, { csrf: session.csrf, user: session.user, demo: session.demo_payments, catalog });
  } catch (e) {
    $view.replaceChildren(el('div', { class: 'card note bad' }, e.message));
    return;
  }
  // Admin tabs live in the hash: #/admin/<tab>
  routes.admin = arg => viewAdmin(arg || 'pending');
  // The demo bar switches accounts without reloading the page.
  window.addEventListener('fleta:session', async () => {
    const s = await api('/api/session');
    Object.assign(state, { csrf: s.csrf, user: s.user, quote: null, draft: null });
    router();
  });
  await returnFromPayment();
  window.addEventListener('hashchange', router);
  router();
})();
