'use strict';
// Fletá frontend: no build step, no inline scripts (strict CSP), DOM built with el() so user text is never parsed as HTML.

const state = { csrf: '', user: null, catalog: null, demo: false, quote: null, afterLogin: null };
const $view = document.getElementById('view');
const money = n => new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 }).format(n);
const fmtDate = iso => new Date(iso + 'T12:00:00').toLocaleDateString('es-AR', { weekday: 'short', day: 'numeric', month: 'short' });
const fmtTime = ts => new Date(ts * 1000).toLocaleString('es-AR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const todayIso = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };

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
  const digits = String(phone).replace(/\D/g, '');
  const full = digits.startsWith('54') ? digits : '549' + digits;
  return 'https://wa.me/' + full + '?text=' + encodeURIComponent(text);
}
const mapsLink = (address, city) => 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(address + ', ' + city);

function citySelect(name, value) {
  const groups = {};
  for (const c of state.catalog.cities) (groups[c.area] ||= []).push(c);
  return el('select', { name, required: true },
    el('option', { value: '' }, 'Elegí una localidad'),
    Object.entries(groups).map(([area, cities]) => el('optgroup', { label: area },
      cities.map(c => el('option', { value: c.id, selected: c.id === value }, c.label)))));
}

function statusBadge(s) {
  const cls = { delivered: 'ok', cancelled: 'bad', pending_payment: 'warn' }[s.status] || '';
  return el('span', { class: 'badge ' + cls }, s.status_label);
}

function needLogin(next) {
  state.afterLogin = next || location.hash;
  location.hash = '#/ingresar';
}

// ---------- navigation ----------
function renderNav() {
  const route = location.hash.split('/')[1] || '';
  const link = (href, text) => el('a', { href, class: ('#/' + route) === href ? 'on' : '' }, text);
  const nav = document.getElementById('nav');
  nav.replaceChildren(...[
    link('#/', 'Cotizar'), link('#/seguimiento', 'Seguimiento'),
    state.user && link('#/envios', 'Mis envíos'), link('#/chofer', 'Soy chofer'),
    state.user && state.user.admin && link('#/admin', 'Admin'),
    state.user
      ? el('button', { onclick: logout }, 'Salir')
      : link('#/ingresar', 'Ingresar')].filter(Boolean));
}

async function logout() {
  await busy(null, async () => {
    const r = await api('/api/auth/logout', { method: 'POST' });
    state.csrf = r.csrf; state.user = null; location.hash = '#/'; toast('Cerraste sesión.');
  });
}

const routes = {
  '': viewHome, 'ingresar': () => viewAuth('login'), 'registro': () => viewAuth('register'),
  'envios': viewMyShipments, 'envio': viewShipment, 'seguimiento': viewTrack, 'chofer': viewDriver, 'admin': viewAdmin,
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
function viewHome() {
  const q = state.quote;
  const form = el('form', { class: 'stack card', novalidate: true },
    el('h2', {}, 'Cotizá tu envío'),
    el('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'Servicio' },
      el('label', {}, el('input', { type: 'radio', name: 'service', value: 'flete', checked: !q || q.service === 'flete' }),
        el('strong', {}, 'Flete exclusivo'), el('small', {}, 'Un vehículo solo para vos: mudanzas, muebles, electrodomésticos.')),
      el('label', {}, el('input', { type: 'radio', name: 'service', value: 'bultos', checked: q && q.service === 'bultos' }),
        el('strong', {}, 'Envío de bultos'), el('small', {}, `Cajas y paquetes hasta ${state.catalog.bultos_max_kg} kg. Más barato.`))),
    el('div', { class: 'grid' },
      el('label', {}, 'Origen', citySelect('origin', q && q.origin)),
      el('label', {}, 'Destino', citySelect('destination', q && q.destination))),
    el('div', { class: 'grid' },
      el('label', { 'data-flete': '' }, 'Vehículo',
        el('select', { name: 'vehicle' }, state.catalog.vehicles.map(v =>
          el('option', { value: v.id, selected: q ? q.vehicle === v.id : v.id === 'utilitario' }, `${v.label} · hasta ${v.max_kg.toLocaleString('es-AR')} kg`)))),
      el('label', {}, 'Peso aproximado (kg)', el('input', { name: 'kg', type: 'number', min: 1, max: 4000, inputmode: 'numeric', value: q ? q.kg : 50, required: true })),
      el('label', { 'data-flete': '' }, el('span', {}, 'Ayudantes para cargar ', el('small', {}, `(${money(state.catalog.helper_price)} c/u)`)),
        el('select', { name: 'helpers' }, [0, 1, 2, 3].map(n => el('option', { value: n, selected: q && q.helpers === n }, n === 0 ? 'Sin ayudantes' : `${n} ayudante${n > 1 ? 's' : ''}`))))),
    el('div', { class: 'grid' },
      el('label', {}, el('span', {}, 'Valor declarado ', el('small', {}, '(opcional)')),
        el('input', { name: 'declared_value', type: 'number', min: 0, inputmode: 'numeric', placeholder: 'Ej. 300000', value: q && q.declared_value || '' })),
      el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'insurance', checked: q && q.insurance > 0 }), 'Asegurar la carga (1,5 % del valor)')),
    el('button', { class: 'btn', type: 'submit' }, 'Cotizar'));
  const result = el('div');
  const toggle = () => {
    const flete = form.service.value === 'flete';
    form.querySelectorAll('[data-flete]').forEach(n => { n.hidden = !flete; });
  };
  form.addEventListener('change', e => { toggle(); if (e.target.name !== 'insurance' || form.declared_value.value) result.replaceChildren(); });
  form.addEventListener('submit', e => {
    e.preventDefault();
    const btn = form.querySelector('button[type=submit]');
    busy(btn, async () => {
      const body = {
        service: form.service.value, origin: form.origin.value, destination: form.destination.value,
        vehicle: form.vehicle.value, kg: Number(form.kg.value), helpers: Number(form.helpers.value),
        declared_value: Number(form.declared_value.value || 0), insurance: form.insurance.checked,
      };
      state.quote = await api('/api/quote', { method: 'POST', body });
      result.replaceChildren(quoteCard(state.quote));
      result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
  toggle();
  if (q) result.append(quoteCard(q));
  $view.replaceChildren(
    el('section', { class: 'hero' },
      el('h1', {}, 'Fletes y envíos, en tu barrio o a 2.000 km'),
      el('p', {}, 'Mové lo que necesites dentro de CABA y el Gran Buenos Aires o mandalo a cualquier ciudad del país. Precio cerrado antes de pedir, choferes con identidad y documentación verificadas, y pago protegido con Mercado Pago o tarjeta.'),
      el('ul', { class: 'pills' }, el('li', {}, '✔ Choferes verificados'), el('li', {}, '🔒 Pago protegido'), el('li', {}, '📍 Código de seguimiento'), el('li', {}, '🔑 Entrega con código'))),
    el('div', { class: 'two' }, form, el('div', {}, result, howItWorks())));
}

function howItWorks() {
  return el('div', { class: 'card' }, el('h3', {}, '¿Cómo funciona?'),
    el('ol', { class: 'steps' },
      el('li', {}, 'Cotizá y pedí con fecha y franja horaria.'),
      el('li', {}, 'Pagá con Mercado Pago o tarjeta. El dinero queda retenido hasta la entrega.'),
      el('li', {}, 'Un chofer verificado toma el viaje y te contacta por WhatsApp.'),
      el('li', {}, 'Quien recibe le dicta el código de entrega al chofer para cerrar el viaje.')));
}

function quoteCard(q) {
  const lines = q.breakdown.filter(([, v]) => v > 0);
  return el('div', { class: 'card' },
    el('div', { class: 'row between' }, el('span', { class: 'badge' }, q.long_distance ? 'Larga distancia' : 'Corta distancia'), el('span', { class: 'muted' }, `≈ ${q.distance_km.toLocaleString('es-AR')} km`)),
    el('p', { class: 'price' }, money(q.price)),
    el('table', { class: 'table' }, el('tbody', {}, lines.map(([k, v]) => el('tr', {}, el('td', {}, k), el('td', {}, money(v)))))),
    el('p', { class: 'muted' }, 'Precio final, con IVA. Sin sorpresas: el chofer no puede cobrarte extra.'),
    el('button', { class: 'btn', type: 'button', onclick: () => state.user ? orderForm(q) : needLogin('#/') }, state.user ? 'Pedir este envío' : 'Ingresá para pedir'));
}

function orderForm(q) {
  const form = el('form', { class: 'stack card', novalidate: true },
    el('h2', {}, 'Datos del envío'),
    el('p', { class: 'muted' }, `${labelOf(q.origin)} → ${labelOf(q.destination)} · ${money(q.price)}`),
    el('div', { class: 'grid' },
      el('label', {}, 'Fecha de retiro', el('input', { type: 'date', name: 'pickup_date', min: todayIso(), required: true })),
      el('label', {}, 'Franja horaria', el('select', { name: 'slot' }, Object.entries(state.catalog.slots).map(([k, v]) => el('option', { value: k }, v))))),
    el('label', {}, 'Dirección de retiro', el('input', { name: 'origin_address', maxlength: 150, autocomplete: 'street-address', placeholder: 'Calle, número, piso/depto', required: true })),
    el('label', {}, 'Dirección de entrega', el('input', { name: 'dest_address', maxlength: 150, placeholder: 'Calle, número, piso/depto', required: true })),
    el('label', {}, '¿Qué hay que llevar?', el('textarea', { name: 'description', maxlength: 1000, placeholder: 'Ej. heladera, 6 cajas medianas y un colchón de 2 plazas. Hay escalera en el retiro.', required: true })),
    el('div', { class: 'grid' },
      el('label', {}, 'Quién recibe', el('input', { name: 'receiver_name', maxlength: 80, placeholder: 'Nombre y apellido', required: true })),
      el('label', {}, 'Celular de quien recibe', el('input', { name: 'receiver_phone', type: 'tel', inputmode: 'tel', placeholder: '11 2345 6789', required: true }))),
    el('div', { class: 'row' }, el('button', { class: 'btn', type: 'submit' }, 'Confirmar y pagar'), el('button', { class: 'btn ghost', type: 'button', onclick: viewHome }, 'Volver')));
  form.addEventListener('submit', e => {
    e.preventDefault();
    busy(form.querySelector('[type=submit]'), async () => {
      const body = { ...q, insurance: q.insurance > 0 };
      for (const k of ['pickup_date', 'slot', 'origin_address', 'dest_address', 'description', 'receiver_name', 'receiver_phone']) body[k] = form[k].value;
      const r = await api('/api/shipments', { method: 'POST', body });
      state.quote = null;
      location.hash = '#/envio/' + r.id;
    });
  });
  $view.replaceChildren(form);
  form.pickup_date.focus();
}

const labelOf = id => (state.catalog.cities.find(c => c.id === id) || {}).label || id;

// ---------- auth ----------
function viewAuth(mode) {
  const register = mode === 'register';
  const form = el('form', { class: 'stack card', style: null },
    el('h2', {}, register ? 'Creá tu cuenta' : 'Ingresá'),
    register && el('label', {}, 'Nombre y apellido', el('input', { name: 'name', autocomplete: 'name', required: true, maxlength: 80 })),
    register && el('label', {}, 'Celular (WhatsApp)', el('input', { name: 'phone', type: 'tel', autocomplete: 'tel', inputmode: 'tel', placeholder: '11 2345 6789', required: true })),
    el('label', {}, 'Correo', el('input', { name: 'email', type: 'email', autocomplete: 'email', required: true })),
    el('label', {}, 'Contraseña', el('input', { name: 'password', type: 'password', autocomplete: register ? 'new-password' : 'current-password', minlength: 10, required: true }),
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
      const next = state.afterLogin || '#/envios'; state.afterLogin = null;
      toast(register ? '¡Bienvenido/a a Fletá!' : 'Hola de nuevo.');
      if (location.hash === next) router(); else location.hash = next;
    });
  });
  $view.replaceChildren(el('div', { class: 'two' }, form, howItWorks()));
}

// ---------- client shipments ----------
async function viewMyShipments() {
  if (!state.user) return needLogin();
  const { items } = await api('/api/shipments');
  $view.replaceChildren(el('h1', {}, 'Mis envíos'),
    items.length ? el('div', { class: 'list' }, items.map(s => el('a', { class: 'card item', href: '#/envio/' + s.id, style: null },
      el('div', { class: 'row between' }, el('span', { class: 'route' }, `${s.origin_label} → ${s.destination_label}`), statusBadge(s)),
      el('span', { class: 'muted' }, `${s.code} · ${s.service_label} · ${fmtDate(s.pickup_date)} · ${money(s.price)}`))))
      : el('div', { class: 'card' }, el('p', {}, 'Todavía no pediste envíos.'), el('a', { class: 'btn', href: '#/' }, 'Cotizar ahora')));
}

async function viewShipment(id) {
  if (!state.user) return needLogin();
  const s = await api('/api/shipments/' + encodeURIComponent(id));
  const blocks = [];
  blocks.push(el('div', { class: 'card' },
    el('div', { class: 'row between' }, el('h1', {}, s.code), statusBadge(s)),
    el('p', { class: 'route' }, `${s.origin_label} → ${s.destination_label}`),
    el('p', { class: 'muted' }, `${s.service_label} · ${s.vehicle_label} · ${s.kg} kg · ≈ ${s.distance_km} km · ${fmtDate(s.pickup_date)}, ${s.slot_label}`),
    el('p', {}, el('strong', {}, 'Retiro: '), s.origin_address, el('br'), el('strong', {}, 'Entrega: '), s.dest_address, el('br'),
      el('strong', {}, 'Recibe: '), `${s.receiver_name} (${s.receiver_phone})`),
    el('p', {}, el('strong', {}, 'Carga: '), s.description),
    s.price !== undefined && el('p', { class: 'price' }, money(s.price))));

  if (s.mine && s.status === 'pending_payment') blocks.push(payCard(s));
  if (s.pin) blocks.push(el('div', { class: 'card' }, el('h3', {}, 'Código de entrega'), el('p', { class: 'pin' }, s.pin),
    el('p', { class: 'muted' }, 'Pasáselo solo a quien recibe. El chofer lo pide al entregar: sin este código no puede cerrar el viaje ni cobrar.')));
  if (s.driver) blocks.push(driverCard(s));
  if (s.refund_status === 'pending') blocks.push(el('div', { class: 'card note' }, 'Cancelaste un envío pagado. Estamos procesando la devolución a tu medio de pago.'));
  if (s.refund_status === 'done') blocks.push(el('div', { class: 'card note ok' }, 'La devolución ya fue procesada.'));
  if (s.mine && s.status === 'delivered' && !s.rating) blocks.push(rateCard(s));
  if (s.rating) blocks.push(el('div', { class: 'card' }, el('h3', {}, 'Tu calificación'), el('p', {}, '★'.repeat(s.rating) + '☆'.repeat(5 - s.rating)), s.review && el('p', {}, s.review)));
  blocks.push(el('div', { class: 'card' }, el('h3', {}, 'Seguimiento'),
    el('ol', { class: 'timeline' }, (s.events || []).map(ev => el('li', {}, el('span', {}, statusText(ev.status)), el('small', { class: 'muted' }, fmtTime(ev.at)))))));
  if (s.mine && ['pending_payment', 'paid', 'assigned'].includes(s.status)) {
    blocks.push(el('button', { class: 'btn danger', onclick: e => {
      const warn = s.status === 'pending_payment' ? '¿Cancelar este pedido?' : '¿Cancelar el envío? Te devolvemos el pago completo a tu medio de pago.';
      if (confirm(warn)) busy(e.target, async () => { await api(`/api/shipments/${s.id}/cancel`, { method: 'POST' }); toast('Envío cancelado.'); router(); });
    } }, 'Cancelar envío'));
  }
  $view.replaceChildren(...blocks);
}

const STATUS_TEXT = { pending_payment: 'Pedido creado', paid: 'Pago acreditado', assigned: 'Chofer asignado', in_transit: 'Carga retirada, en viaje', delivered: 'Entregado', cancelled: 'Cancelado' };
const statusText = s => STATUS_TEXT[s] || s;

function payCard(s) {
  const pay = method => e => busy(e.target, async () => {
    const r = await api(`/api/shipments/${s.id}/pay`, { method: 'POST', body: { method } });
    if (r.url) { location.href = r.url; return; }
    if (r.demo && confirm('Modo demo: Mercado Pago no está configurado. ¿Simular un pago aprobado?')) {
      await api(`/api/shipments/${s.id}/demo-pay`, { method: 'POST', body: { method } });
      toast('Pago simulado acreditado.'); router();
    }
  });
  return el('div', { class: 'card' }, el('h3', {}, 'Pagá para publicar el viaje'),
    el('p', { class: 'muted' }, 'El viaje se publica para los choferes cuando se acredita el pago. Si nadie lo toma o lo cancelás antes del retiro, te devolvemos todo.'),
    el('div', { class: 'row' },
      el('button', { class: 'btn mp', onclick: pay('mercadopago') }, 'Pagar con Mercado Pago'),
      el('button', { class: 'btn', onclick: pay('card') }, 'Tarjeta de crédito o débito')),
    el('p', { class: 'muted' }, 'Hasta 12 cuotas con tarjeta. Pagás en el sitio seguro de Mercado Pago.'),
    state.demo && el('p', { class: 'note' }, 'Modo demo activo: los pagos se simulan.'));
}

function driverCard(s) {
  const d = s.driver;
  return el('div', { class: 'card' }, el('h3', {}, 'Tu chofer'),
    el('div', { class: 'row' }, el('strong', {}, d.name), d.verified ? el('span', { class: 'badge ok' }, '✔ Verificado') : el('span', { class: 'badge bad' }, 'No verificado')),
    el('p', { class: 'muted' }, `${d.vehicle} · ${d.vehicle_model} · Patente ${d.plate}`),
    el('p', {}, d.rating ? `★ ${d.rating} (${d.rating_count} viajes calificados)` : 'Todavía sin calificaciones'),
    el('p', { class: 'muted' }, 'Antes de cargar, revisá que la patente coincida.'),
    ['assigned', 'in_transit'].includes(s.status) && el('a', { class: 'btn wa', href: waLink(d.phone, `Hola ${d.name}, te escribo por el envío ${s.code} de Fletá.`), target: '_blank', rel: 'noopener' }, 'Escribir por WhatsApp'));
}

function rateCard(s) {
  let stars = 0;
  const buttons = [1, 2, 3, 4, 5].map(n => el('button', { type: 'button', 'aria-label': `${n} estrellas`, onclick: () => { stars = n; buttons.forEach((b, i) => b.classList.toggle('on', i < n)); } }, '★'));
  const review = el('textarea', { maxlength: 500, placeholder: '¿Cómo fue el viaje? (opcional)' });
  return el('div', { class: 'card stack' }, el('h3', {}, 'Calificá al chofer'), el('div', { class: 'stars' }, buttons), review,
    el('button', { class: 'btn', onclick: e => busy(e.target, async () => {
      await api(`/api/shipments/${s.id}/rate`, { method: 'POST', body: { stars, review: review.value } });
      toast('¡Gracias por calificar!'); router();
    }) }, 'Enviar calificación'));
}

// ---------- public tracking ----------
function viewTrack(code) {
  const out = el('div');
  const form = el('form', { class: 'card stack' }, el('h1', {}, 'Seguí tu envío'),
    el('label', {}, 'Código de seguimiento', el('input', { name: 'code', placeholder: 'FL-ABC123', value: code || '', autocapitalize: 'characters', required: true })),
    el('button', { class: 'btn', type: 'submit' }, 'Buscar'));
  const search = () => busy(form.querySelector('button'), async () => {
    const t = await api('/api/track/' + encodeURIComponent(form.code.value.trim()));
    out.replaceChildren(el('div', { class: 'card' },
      el('div', { class: 'row between' }, el('h2', {}, t.code), statusBadge(t)),
      el('p', { class: 'route' }, `${t.origin_label} → ${t.destination_label}`), el('p', { class: 'muted' }, 'Retiro: ' + fmtDate(t.pickup_date)),
      el('ol', { class: 'timeline' }, t.events.map(ev => el('li', {}, statusText(ev.status), el('small', { class: 'muted' }, fmtTime(ev.at)))))));
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
  return el('div', { class: 'card' }, el('h2', {}, 'Manejá con Fletá'),
    el('p', {}, 'Tomá viajes cerca de tu zona o de larga distancia, cobrá seguro y elegí cuándo trabajar.'),
    el('h3', {}, 'Para verificarte necesitamos'),
    el('ul', {}, Object.values(state.catalog.docs).map(d => el('li', {}, d))),
    el('p', { class: 'muted' }, 'Tus documentos solo los ve el equipo de verificación. Les borramos los metadatos (ubicación, dispositivo) al subirlos.'));
}

function applyForm(prev) {
  const file = (name, label, pdf) => el('label', {}, label, el('input', { type: 'file', name, accept: pdf ? 'image/*,application/pdf' : 'image/*', required: true }));
  const form = el('form', { class: 'card stack', novalidate: true },
    el('h2', {}, 'Verificación de chofer'),
    el('div', { class: 'grid' },
      el('label', {}, 'CUIL', el('input', { name: 'cuil', inputmode: 'numeric', placeholder: '20-12345678-9', required: true })),
      el('label', {}, 'Ciudad base', citySelect('base_city', prev && prev.base_city))),
    el('div', { class: 'grid' },
      el('label', {}, 'Vehículo', el('select', { name: 'vehicle' }, state.catalog.vehicles.map(v => el('option', { value: v.id, selected: prev && prev.vehicle === v.id }, v.label)))),
      el('label', {}, 'Marca y modelo', el('input', { name: 'vehicle_model', placeholder: 'Renault Kangoo 2019', value: prev && prev.vehicle_model || '', required: true })),
      el('label', {}, 'Patente', el('input', { name: 'plate', placeholder: 'AB123CD', autocapitalize: 'characters', value: prev && prev.plate || '', required: true }))),
    el('div', { class: 'grid' },
      el('label', {}, 'Vencimiento de la licencia', el('input', { type: 'date', name: 'license_expiry', min: todayIso(), required: true })),
      el('label', {}, 'Vencimiento del seguro', el('input', { type: 'date', name: 'insurance_expiry', min: todayIso(), required: true }))),
    el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'long_distance', value: '1', checked: prev && prev.long_distance }), 'También hago viajes de larga distancia'),
    el('h3', {}, 'Documentos'),
    el('div', { class: 'grid' }, Object.entries(state.catalog.docs).map(([k, label]) => file(k, label, ['insurance', 'background'].includes(k)))),
    el('p', { class: 'muted' }, 'Fotos nítidas, sin reflejos y con los 4 bordes visibles. El certificado de antecedentes se tramita online en argentina.gob.ar (Registro Nacional de Reincidencia).'),
    el('label', { class: 'check' }, el('input', { type: 'checkbox', name: 'consent', required: true }), 'Declaro que los datos son verdaderos y autorizo su verificación.'),
    el('button', { class: 'btn', type: 'submit' }, 'Enviar documentación'));
  form.addEventListener('submit', e => {
    e.preventDefault();
    if (!form.consent.checked) return toast('Tenés que aceptar la declaración para continuar.');
    busy(form.querySelector('[type=submit]'), async () => {
      const fd = new FormData(form); fd.delete('consent');
      await api('/api/driver/apply', { method: 'POST', form: fd });
      toast('¡Listo! Revisamos tu documentación y te avisamos.'); router();
    });
  });
  return form;
}

async function driverDashboard(driver, tab = 'jobs') {
  const head = el('div', { class: 'card' },
    el('div', { class: 'row between' }, el('h1', {}, 'Panel de chofer'), el('span', { class: 'badge ok' }, '✔ Verificado')),
    el('div', { class: 'stats' },
      el('div', {}, el('strong', {}, money(driver.earned)), 'cobrado'),
      el('div', {}, el('strong', {}, driver.trips), 'viajes entregados'),
      el('div', {}, el('strong', {}, driver.rating ? '★ ' + driver.rating : '—'), `${driver.rating_count} calificaciones`)),
    driver.problems.map(p => el('p', { class: 'note bad' }, p)));
  const tabs = el('div', { class: 'tabs' },
    el('button', { class: tab === 'jobs' ? 'on' : '', onclick: () => driverDashboard(driver, 'jobs') }, 'Viajes disponibles'),
    el('button', { class: tab === 'trips' ? 'on' : '', onclick: () => driverDashboard(driver, 'trips') }, 'Mis viajes'));
  const body = el('div', { class: 'list' }, el('p', { class: 'muted' }, 'Cargando…'));
  $view.replaceChildren(head, tabs, body);
  const refresh = () => driverDashboard(driver, tab);
  if (tab === 'jobs') {
    const { items } = await api('/api/driver/jobs');
    body.replaceChildren(...(items.length ? items.map(j => el('div', { class: 'card item' },
      el('div', { class: 'row between' }, el('span', { class: 'route' }, `${j.origin_label} → ${j.destination_label}`), el('strong', {}, money(j.payout))),
      el('span', { class: 'muted' }, `${fmtDate(j.pickup_date)}, ${j.slot_label} · ${j.service_label} · ${j.vehicle_label} · ${j.kg} kg · ≈ ${j.distance_km} km${j.helpers ? ` · ${j.helpers} ayudante(s)` : ''}`),
      el('span', { class: 'muted' }, `A ${j.km_from_base} km de tu base`),
      el('p', {}, j.description),
      el('div', {}, el('button', { class: 'btn', onclick: e => busy(e.target, async () => {
        await api(`/api/shipments/${j.id}/accept`, { method: 'POST' }); toast('¡Viaje tomado! Contactá al cliente.'); driverDashboard(driver, 'trips');
      }) }, 'Tomar viaje'))))
      : [el('div', { class: 'card' }, 'No hay viajes disponibles para tu vehículo y zona ahora. Volvé a mirar más tarde.')]));
  } else {
    const { items } = await api('/api/driver/trips');
    body.replaceChildren(...(items.length ? items.map(t => tripCard(t, refresh)) : [el('div', { class: 'card' }, 'Todavía no tomaste viajes.')]));
  }
}

function tripCard(t, refresh) {
  const act = (action, body) => e => busy(e.target, async () => { await api(`/api/shipments/${t.id}/${action}`, { method: 'POST', body }); refresh(); });
  const pin = el('input', { inputmode: 'numeric', maxlength: 4, placeholder: 'Código', 'aria-label': 'Código de entrega' });
  const open = ['assigned', 'in_transit'].includes(t.status);
  return el('div', { class: 'card item' },
    el('div', { class: 'row between' }, el('span', { class: 'route' }, `${t.code} · ${t.origin_label} → ${t.destination_label}`), statusBadge(t)),
    el('span', { class: 'muted' }, `${fmtDate(t.pickup_date)}, ${t.slot_label} · ${t.kg} kg · cobrás ${money(t.payout)}`),
    el('p', {}, el('strong', {}, 'Retiro: '), el('a', { href: mapsLink(t.origin_address, t.origin_label), target: '_blank', rel: 'noopener' }, t.origin_address), el('br'),
      el('strong', {}, 'Entrega: '), el('a', { href: mapsLink(t.dest_address, t.destination_label), target: '_blank', rel: 'noopener' }, t.dest_address), el('br'),
      el('strong', {}, 'Cliente: '), t.client.name, ' · ', el('strong', {}, 'Recibe: '), t.receiver_name),
    el('p', {}, t.description),
    open && el('div', { class: 'row' },
      el('a', { class: 'btn wa', href: waLink(t.client.phone, `Hola ${t.client.name}, soy tu chofer de Fletá para el envío ${t.code}.`), target: '_blank', rel: 'noopener' }, 'WhatsApp cliente'),
      el('a', { class: 'btn ghost', href: waLink(t.receiver_phone, `Hola ${t.receiver_name}, soy el chofer de Fletá del envío ${t.code}.`), target: '_blank', rel: 'noopener' }, 'WhatsApp quien recibe')),
    t.status === 'assigned' && el('div', { class: 'row' },
      el('button', { class: 'btn', onclick: act('pickup') }, 'Ya retiré la carga'),
      el('button', { class: 'btn ghost', onclick: e => confirm('¿Liberar el viaje? Vuelve a quedar disponible para otros choferes.') && act('release')(e) }, 'Liberar viaje')),
    t.status === 'in_transit' && el('div', { class: 'row' }, pin,
      el('button', { class: 'btn', onclick: e => act('deliver', { pin: pin.value.trim() })(e) }, 'Confirmar entrega')));
}

// ---------- admin ----------
async function viewAdmin(tab = 'pending') {
  if (!state.user || !state.user.admin) return $view.replaceChildren(el('div', { class: 'card note bad' }, 'No autorizado.'));
  const tabs = el('div', { class: 'tabs' }, [['pending', 'Choferes por verificar'], ['approved', 'Verificados'], ['refunds', 'Devoluciones'], ['all', 'Envíos']]
    .map(([k, label]) => el('button', { class: tab === k ? 'on' : '', onclick: () => { location.hash = '#/admin/' + k; } }, label)));
  const body = el('div', { class: 'list' });
  $view.replaceChildren(el('h1', {}, 'Administración'), tabs, body);
  if (tab === 'refunds' || tab === 'all') {
    const { items } = await api('/api/admin/shipments' + (tab === 'refunds' ? '?refunds=1' : ''));
    body.replaceChildren(...(items.length ? items.map(s => el('div', { class: 'card item' },
      el('div', { class: 'row between' }, el('a', { class: 'route', href: '#/envio/' + s.id }, `${s.code} · ${s.origin_label} → ${s.destination_label}`), statusBadge(s)),
      el('span', { class: 'muted' }, `${money(s.price)} · ${s.payment_method || 'sin pago'} · cliente ${s.client.name} (${s.client.phone})`),
      s.refund_status === 'pending' && el('div', { class: 'row' }, el('span', { class: 'badge warn' }, 'Devolver dinero'),
        el('button', { class: 'btn ghost', onclick: e => confirm('¿Ya hiciste la devolución desde Mercado Pago?') && busy(e.target, async () => {
          await api(`/api/admin/shipments/${s.id}/refunded`, { method: 'POST' }); viewAdmin(tab);
        }) }, 'Marcar devuelto')))) : [el('div', { class: 'card' }, 'Nada por acá.')]));
    return;
  }
  const { items, doc_labels } = await api('/api/admin/drivers?status=' + tab);
  const review = (uid, action) => e => {
    const note = action === 'approve' ? '' : prompt(action === 'reject' ? 'Motivo del rechazo (lo ve el chofer):' : 'Motivo de la suspensión:');
    if (note === null) return;
    busy(e.target, async () => { await api('/api/admin/drivers/' + uid, { method: 'POST', body: { action, note } }); toast('Guardado.'); viewAdmin(tab); });
  };
  body.replaceChildren(...(items.length ? items.map(d => el('div', { class: 'card item' },
    el('div', { class: 'row between' }, el('strong', {}, d.name), el('span', { class: 'muted' }, d.email + ' · ' + d.phone)),
    el('span', {}, `CUIL ${d.cuil} · ${d.vehicle_label} ${d.vehicle_model} · Patente ${d.plate} · Base ${d.base_city_label}${d.long_distance ? ' · larga distancia' : ''}`),
    el('span', { class: 'muted' }, `Licencia vence ${d.license_expiry} · Seguro vence ${d.insurance_expiry}`),
    el('div', { class: 'docs' }, Object.entries(d.docs).map(([k, key]) => el('a', { class: 'btn ghost', href: '/api/admin/docs/' + key, target: '_blank', rel: 'noopener' }, doc_labels[k]))),
    el('p', { class: 'muted' }, 'Compará la selfie con la foto del DNI, que nombre y CUIL coincidan en todos los documentos, que la patente de la cédula sea la declarada y que el certificado de antecedentes sea reciente y sin registros.'),
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
  history.replaceState(null, '', '/#/envio/' + id);
  if (params.get('status') === 'rejected' || params.get('collection_status') === 'rejected') { toast('El pago fue rechazado. Probá con otro medio.'); return; }
  try {
    const r = await api(`/api/shipments/${id}/confirm-payment`, { method: 'POST', body: { payment_id: params.get('payment_id') || params.get('collection_id') || '' } });
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
  await returnFromPayment();
  window.addEventListener('hashchange', router);
  router();
})();
