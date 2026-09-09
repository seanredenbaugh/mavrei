(() => {
  'use strict';

  const styleBase = 'https://tiles.openfreemap.org/styles/';
  const map = new maplibregl.Map({
    container: 'map',
    style: styleBase + 'liberty',
    center: [-87.56, 37.98],
    zoom: 11,
    cooperativeGestures: true
  });
  map.addControl(new maplibregl.NavigationControl(), 'bottom-right');
  map.addControl(new maplibregl.FullscreenControl(), 'bottom-right');

  let properties = [];
  const markers = new Map();
  const activeFilters = new Set(['rented', 'available', 'coming_soon', 'sold', 'apartment', 'airbnb']);
  const list = document.querySelector('#property-list');
  const search = document.querySelector('#property-search');

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  const markerKey = (p) => p.property_type === 'house' ? p.status : p.property_type;
  const markerColor = (key) => ({rented:'#294861', available:'#1d8f5a', coming_soon:'#e28a16', sold:'#737983', apartment:'#c89b3c', airbnb:'#bd001c'})[key] || '#111318';

  function iconSvg(type, status) {
    if (type === 'apartment') return '<svg viewBox="0 0 24 24"><path d="M5 21V4h10v5h4v12M9 8h2m-2 4h2m-2 4h2m4-3h1m-1 4h1M3 21h18"/></svg>';
    if (type === 'airbnb') return '<svg viewBox="0 0 24 24"><path d="M4 12v8m16-8v8M4 16h16M6 16v-5h5c2 0 3 1 3 3v2M4 10V7h4a3 3 0 0 1 3 3"/></svg>';
    if (status === 'coming_soon') return '<svg viewBox="0 0 24 24"><path d="M3 11 12 4l9 7v9h-6v-6H9v6H3z"/><path d="m15 5 4 4M17 3l4 4"/></svg>';
    return '<svg viewBox="0 0 24 24"><path d="M3 11 12 4l9 7v9h-6v-6H9v6H3z"/></svg>';
  }

  function makeMarker(property) {
    const key = markerKey(property);
    const el = document.createElement('button');
    el.className = 'property-marker';
    el.style.setProperty('--marker-color', markerColor(key));
    el.innerHTML = iconSvg(property.property_type, property.status);
    el.setAttribute('aria-label', property.title + ', ' + property.status.replace('_', ' '));
    const popup = new maplibregl.Popup({offset: 24, maxWidth: '310px'}).setHTML(popupHtml(property));
    const marker = new maplibregl.Marker({element: el, anchor: 'bottom'})
      .setLngLat([property.longitude, property.latitude])
      .setPopup(popup)
      .addTo(map);
    el.addEventListener('click', () => highlightCard(property.id));
    markers.set(property.id, {marker, element: el, key});
  }

  function popupHtml(p) {
    const image = p.featured_photo ? `<img class="popup-image" src="${escapeHtml(p.featured_photo)}" alt="">` : '';
    return `<article class="map-popup">${image}<div><span class="status-pill ${escapeHtml(markerKey(p))}">${escapeHtml(p.status.replace('_', ' '))}</span><h2>${escapeHtml(p.title)}</h2><p>${escapeHtml(p.city)}, ${escapeHtml(p.state)}</p><a href="${escapeHtml(p.detail_url)}">View property</a></div></article>`;
  }

  function isVisible(p) {
    const key = markerKey(p);
    const query = search.value.trim().toLowerCase();
    const matchesQuery = !query || [p.title, p.address_line1, p.city, p.status, p.property_type].some(v => String(v || '').toLowerCase().includes(query));
    return activeFilters.has(key) && matchesQuery;
  }

  function render() {
    const visible = properties.filter(isVisible);
    markers.forEach(({marker, element}, id) => {
      const show = visible.some(p => p.id === id);
      element.hidden = !show;
      element.style.display = show ? '' : 'none';
    });
    list.innerHTML = visible.length ? visible.map(cardHtml).join('') : '<p class="empty">No properties match those filters.</p>';
    document.querySelector('#result-count').textContent = `${visible.length} shown`;
    list.querySelectorAll('[data-property-id]').forEach(card => {
      card.addEventListener('click', event => {
        if (event.target.closest('a')) return;
        const p = properties.find(item => item.id === Number(card.dataset.propertyId));
        if (!p) return;
        map.flyTo({center:[p.longitude, p.latitude], zoom:15});
        markers.get(p.id).marker.togglePopup();
        highlightCard(p.id);
      });
    });
  }

  function cardHtml(p) {
    const key = markerKey(p);
    const photo = p.featured_photo ? `<img src="${escapeHtml(p.featured_photo)}" alt="">` : '<div class="photo-placeholder">No photo</div>';
    const facts = [p.bedrooms ? `${p.bedrooms} bd` : '', p.bathrooms ? `${p.bathrooms} ba` : '', p.square_feet ? `${Number(p.square_feet).toLocaleString()} ft²` : ''].filter(Boolean).join(' · ');
    return `<article class="property-card" data-property-id="${p.id}">${photo}<div class="property-card-body"><span class="status-pill ${key}">${escapeHtml(p.status.replace('_',' '))}</span><h2>${escapeHtml(p.title)}</h2><p>${escapeHtml(p.city)}, ${escapeHtml(p.state)} ${escapeHtml(p.postal_code)}</p>${facts ? `<small>${facts}</small>` : ''}<a href="${escapeHtml(p.detail_url)}">Details & photos</a></div></article>`;
  }

  function highlightCard(id) {
    list.querySelectorAll('.property-card').forEach(card => card.classList.toggle('selected', Number(card.dataset.propertyId) === id));
    list.querySelector(`[data-property-id="${id}"]`)?.scrollIntoView({behavior:'smooth', block:'nearest'});
  }

  document.querySelectorAll('[data-filter]').forEach(input => input.addEventListener('change', () => {
    input.checked ? activeFilters.add(input.dataset.filter) : activeFilters.delete(input.dataset.filter);
    render();
  }));
  search.addEventListener('input', render);
  document.querySelector('#map-style').addEventListener('change', event => map.setStyle(styleBase + event.target.value));

  fetch(window.MAVREI.apiUrl, {headers:{Accept:'application/json'}})
    .then(response => { if (!response.ok) throw new Error('Unable to load properties'); return response.json(); })
    .then(data => {
      properties = data.properties;
      properties.forEach(makeMarker);
      document.querySelector('#stat-total').textContent = properties.length;
      document.querySelector('#stat-current').textContent = properties.filter(p => p.status !== 'sold').length;
      document.querySelector('#stat-sold').textContent = properties.filter(p => p.status === 'sold').length;
      if (properties.length) {
        const bounds = new maplibregl.LngLatBounds();
        properties.forEach(p => bounds.extend([p.longitude, p.latitude]));
        map.fitBounds(bounds, {padding:70, maxZoom:12});
      }
      render();
    })
    .catch(error => { list.innerHTML = `<p class="error">${escapeHtml(error.message)}.</p>`; });
})();

