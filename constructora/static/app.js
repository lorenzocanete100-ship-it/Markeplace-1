(() => {
  const $ = (s) => document.querySelector(s);
  const form = $('#quote');
  const photoInput = $('#photos');
  let cfg = { prices: { mano_obra: 400, con_materiales: 980 }, photoServices: ['refaccion', 'humedad'], maxPhotos: 6, csrf: '', whatsapp: '#' };
  let photos = [];

  const usd = (n) => 'USD ' + Math.round(n).toLocaleString('es-AR');

  fetch('/api/config', { credentials: 'same-origin' })
    .then((r) => r.json())
    .then((data) => {
      cfg = data;
      document.querySelectorAll('.js-wa').forEach((a) => { a.href = data.whatsapp; });
      update();
    })
    .catch(() => {});

  fetch('/api/works', { credentials: 'same-origin' })
    .then((r) => r.json())
    .then(({ items }) => {
      if (!items || !items.length) return;
      const box = $('#gallery');
      items.forEach((w) => {
        const fig = document.createElement('figure');
        const img = document.createElement('img');
        img.src = '/api/works/photos/' + w.photo;
        img.alt = w.caption || 'Obra de V+X Constructora';
        img.loading = 'lazy';
        fig.append(img);
        if (w.caption) {
          const cap = document.createElement('figcaption');
          cap.textContent = w.caption;
          fig.append(cap);
        }
        box.append(fig);
      });
      $('#obras').hidden = false;
      $('#obrasLink').hidden = false;
    })
    .catch(() => {});

  function values() {
    const fd = new FormData(form);
    return { service: fd.get('service'), mode: fd.get('mode'), m2: parseFloat(fd.get('m2')) };
  }

  function update() {
    const { service, mode, m2 } = values();
    const needsPhotos = cfg.photoServices.includes(service);
    $('#modeToggle').hidden = service === 'humedad';
    const tag = $('#photoTag');
    tag.textContent = needsPhotos ? 'necesarias' : 'opcional';
    tag.classList.toggle('req', needsPhotos);
    $('#photoHint').textContent = needsPhotos
      ? 'Para refacciones y humedad necesitamos ver fotos. Subilas acá o mandalas por WhatsApp después de enviar.'
      : 'Si tenés fotos del terreno o de los planos, subilas para afinar el presupuesto.';

    const amount = $('#amount');
    const note = $('#note');
    if (!(m2 > 0)) {
      amount.textContent = service === 'humedad' ? 'A evaluar' : 'USD 0';
      note.textContent = 'Ingresá los m² para calcular.';
      return;
    }
    if (service === 'humedad') {
      amount.textContent = 'A evaluar con fotos';
      note.textContent = m2 + ' m² afectados · el precio depende del origen de la humedad.';
      return;
    }
    const rate = cfg.prices[mode];
    amount.textContent = usd(m2 * rate);
    note.textContent = m2.toLocaleString('es-AR') + ' m² × ' + usd(rate) + ' (' + (mode === 'mano_obra' ? 'sin materiales' : 'con materiales') + ')'
      + (service === 'refaccion' ? ' · se ajusta al ver las fotos' : '');
  }

  function renderThumbs() {
    const box = $('#thumbs');
    box.querySelectorAll('img').forEach((img) => URL.revokeObjectURL(img.src));
    box.textContent = '';
    photos.forEach((file, i) => {
      const fig = document.createElement('figure');
      const img = document.createElement('img');
      img.src = URL.createObjectURL(file);
      img.alt = 'Foto ' + (i + 1);
      const del = document.createElement('button');
      del.type = 'button';
      del.textContent = '×';
      del.setAttribute('aria-label', 'Quitar foto ' + (i + 1));
      del.addEventListener('click', () => { photos.splice(i, 1); renderThumbs(); });
      fig.append(img, del);
      box.append(fig);
    });
  }

  function showError(msg) {
    const el = $('#error');
    el.textContent = msg;
    el.hidden = !msg;
  }

  form.addEventListener('input', update);
  form.addEventListener('change', update);

  photoInput.addEventListener('change', () => {
    const added = Array.from(photoInput.files || []).filter((f) => /^image\/(jpeg|png|webp)$/.test(f.type));
    photos = photos.concat(added).slice(0, cfg.maxPhotos);
    if (added.length && photos.length === cfg.maxPhotos) showError('Máximo ' + cfg.maxPhotos + ' fotos.');
    photoInput.value = '';
    renderThumbs();
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    showError('');
    const { m2 } = values();
    if (!(m2 >= 1)) return showError('Ingresá los metros cuadrados.');
    const fd = new FormData(form);
    fd.delete('photos');
    if (!String(fd.get('name')).trim() || String(fd.get('phone')).replace(/\D/g, '').length < 8 || !String(fd.get('location')).trim()) {
      return showError('Completá nombre, teléfono con código de área y ubicación de la obra.');
    }
    photos.forEach((f) => fd.append('photos', f, f.name));
    const btn = $('#submit');
    btn.disabled = true;
    btn.textContent = 'Enviando…';
    try {
      const r = await fetch('/api/quotes', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': cfg.csrf } });
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(data.error || 'No se pudo enviar. Probá por WhatsApp.');
      $('#ref').textContent = '#' + data.ref;
      $('#doneEstimate').textContent = data.estimate != null ? 'Presupuesto estimado: ' + usd(data.estimate) : 'Te pasamos el precio después de evaluar las fotos.';
      $('#doneWa').href = data.whatsapp;
      $('#doneWa').textContent = data.photos === 0 && cfg.photoServices.includes(values().service) ? 'Enviar fotos por WhatsApp' : 'Continuar por WhatsApp';
      form.hidden = true;
      $('#done').hidden = false;
      $('#done').scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (err) {
      showError(err.message);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Enviar solicitud de presupuesto';
    }
  });

  $('#again').addEventListener('click', () => {
    form.reset();
    photos = [];
    renderThumbs();
    update();
    $('#done').hidden = true;
    form.hidden = false;
  });

  update();
})();

(() => {
  // "Cotizar este modelo": carga los m² del modelo en el cotizador.
  document.querySelectorAll('.modelo-cta').forEach((a) => {
    a.addEventListener('click', () => {
      const form = document.querySelector('[name="m2"]').form;
      form.querySelector('[name="service"][value="llave_en_mano"]').checked = true;
      form.querySelector('[name="mode"][value="con_materiales"]').checked = true;
      form.querySelector('[name="m2"]').value = a.dataset.m2;
      form.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });
})();
