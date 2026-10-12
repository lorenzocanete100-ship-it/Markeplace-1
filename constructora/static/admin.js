(() => {
  const list = document.getElementById('list');
  const usd = (n) => 'USD ' + Math.round(n).toLocaleString('es-AR');
  const el = (tag, text) => { const e = document.createElement(tag); if (text != null) e.textContent = text; return e; };

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
