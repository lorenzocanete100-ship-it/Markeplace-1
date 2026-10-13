(() => {
  const list = document.getElementById('list');
  const usd = (n) => 'USD ' + Math.round(n).toLocaleString('es-AR');
  const el = (tag, text) => { const e = document.createElement(tag); if (text != null) e.textContent = text; return e; };
  let csrf = '';
  const ready = fetch('/api/config', { credentials: 'same-origin' }).then((r) => r.json()).then((c) => { csrf = c.csrf; });

  // Galería de obras
  const works = document.getElementById('works');
  const form = document.getElementById('upload');
  const photo = document.getElementById('workPhoto');
  const err = document.getElementById('uploadError');
  const showErr = (m) => { err.textContent = m; err.hidden = !m; };

  photo.addEventListener('change', () => {
    const f = photo.files[0];
    document.getElementById('workPhotoLabel').textContent = f ? '📷 ' + f.name : '📷 Elegí una foto de la obra';
  });

  function loadWorks() {
    fetch('/api/works', { credentials: 'same-origin' })
      .then((r) => r.json())
      .then(({ items }) => {
        works.textContent = '';
        if (!items.length) { works.append(el('p', 'Todavía no subiste fotos. La sección no se muestra en la página hasta que haya al menos una.')); return; }
        items.forEach((w) => {
          const fig = el('figure');
          const img = el('img');
          img.src = '/api/works/photos/' + w.photo;
          img.alt = w.caption || 'Obra';
          fig.append(img);
          if (w.caption) fig.append(el('figcaption', w.caption));
          const del = el('button', 'Borrar');
          del.type = 'button';
          del.className = 'btn btn-ghost btn-sm del';
          del.addEventListener('click', async () => {
            if (del.dataset.armed !== '1') { del.dataset.armed = '1'; del.textContent = 'Tocá de nuevo para borrar'; return; }
            del.disabled = true;
            await ready;
            const r = await fetch('/admin/api/works/' + w.id, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } });
            if (r.ok) loadWorks(); else { del.disabled = false; del.textContent = 'No se pudo borrar'; }
          });
          fig.append(del);
          works.append(fig);
        });
      })
      .catch(() => { works.textContent = 'No se pudieron cargar las fotos.'; });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    showErr('');
    if (!photo.files[0]) return showErr('Elegí una foto.');
    const btn = document.getElementById('uploadBtn');
    btn.disabled = true; btn.textContent = 'Subiendo…';
    try {
      await ready;
      const r = await fetch('/admin/api/works', { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } });
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(data.error || 'No se pudo subir la foto.');
      form.reset();
      document.getElementById('workPhotoLabel').textContent = '📷 Elegí una foto de la obra';
      loadWorks();
    } catch (ex) {
      showErr(ex.message);
    } finally {
      btn.disabled = false; btn.textContent = 'Subir foto';
    }
  });

  loadWorks();

  // Solicitudes
  fetch('/admin/api/quotes', { credentials: 'same-origin' })
    .then((r) => r.json())
    .then(({ items }) => {
      list.textContent = '';
      if (!items || !items.length) { list.append(el('p', 'Todavía no hay solicitudes.')); return; }
      items.forEach((q) => {
        const card = el('article');
        card.className = 'card';
        card.append(el('h3', q.serviceName + ' · ' + q.m2 + ' m²'));
        card.append(el('p', 'Ref #' + q.id.slice(0, 8).toUpperCase() + ' · ' + new Date(q.created * 1000).toLocaleString('es-AR')));
        card.append(el('p', 'Estimado: ' + (q.estimate != null ? usd(q.estimate) : 'a evaluar') + (q.service !== 'humedad' ? (q.mode === 'mano_obra' ? ' (sin materiales)' : ' (con materiales)') : '')));
        card.append(el('p', q.name + ' · ' + q.location));
        const wa = el('a', 'WhatsApp ' + q.phone);
        wa.href = 'https://wa.me/' + q.phone;
        wa.target = '_blank';
        wa.rel = 'noopener';
        card.append(wa);
        if (q.notes) card.append(el('p', q.notes));
        if (q.photos.length) {
          const thumbs = el('div');
          thumbs.className = 'thumbs';
          q.photos.forEach((key) => {
            const a = el('a');
            a.href = '/admin/photos/' + key;
            a.target = '_blank';
            const img = el('img');
            img.src = '/admin/photos/' + key;
            img.alt = 'Foto de la obra';
            a.append(img);
            thumbs.append(a);
          });
          card.append(thumbs);
        }
        list.append(card);
      });
    })
    .catch(() => { list.textContent = 'No se pudieron cargar las solicitudes.'; });
})();
