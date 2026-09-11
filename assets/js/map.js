(() => {
  'use strict';

  const styleBase = 'https://tiles.openfreemap.org/styles/';
  const selectedStyle = ['liberty','positron','bright','dark','fiord','3d'].includes(window.MAVREI.mapStyle) ? window.MAVREI.mapStyle : 'liberty';
  const map = new maplibregl.Map({
    container: 'map',
    style: styleBase + selectedStyle,
    center: [-87.56, 37.98],
    zoom: 11,
    pitch: selectedStyle === '3d' ? 45 : 0,
    cooperativeGestures: true
  });
  map.addControl(new maplibregl.NavigationControl(), 'bottom-right');
  map.addControl(new maplibregl.FullscreenControl(), 'bottom-right');

  let properties = [];
  const markers = new Map();
  const activeFilters = new Set(['rented', 'available', 'coming_soon', 'sold', 'apartment', 'airbnb', 'headquarters']);
  const list = document.querySelector('#property-list');
  const search = document.querySelector('#property-search');

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  const markerKey = (p) => p.property_type === 'house' ? p.status : p.property_type;
  const markerPalette = (key) => ({
    rented: {fill:'#294861', border:'#7891a5'},
    available: {fill:'#1d8f5a', border:'#79c7a0'},
    coming_soon: {fill:'#e28a16', border:'#f3c170'},
    sold: {fill:'#737983', border:'#b9bec6'},
    apartment: {fill:'#c89b3c', border:'#ead18f'},
    airbnb: {fill:'#bd001c', border:'#ef7888'},
    headquarters: {fill:'#111318', border:'#89919c'}
  })[key] || {fill:'#111318', border:'#89919c'};

  function iconSvg(type, status) {
    if (type === 'headquarters') return '<svg viewBox="0 0 24 24"><path d="M4 21V5h11v16M8 9h3m-3 4h3m-3 4h3M15 8h5l-2 3 2 3h-5M2 21h20"/></svg>';
    if (type === 'apartment') return '<svg viewBox="0 0 24 24"><path d="M5 21V4h10v5h4v12M9 8h2m-2 4h2m-2 4h2m4-3h1m-1 4h1M3 21h18"/></svg>';
    if (type === 'airbnb') return '<svg viewBox="0 0 24 24"><path d="M4 12v8m16-8v8M4 16h16M6 16v-5h5c2 0 3 1 3 3v2M4 10V7h4a3 3 0 0 1 3 3"/></svg>';
    if (status === 'coming_soon') return '<svg viewBox="0 0 24 24"><path d="M4 15a8 8 0 0 1 16 0M8 15V9m8 6V9M3 15h18v3H3z"/></svg>';
    if (status === 'sold') return '<svg viewBox="0 0 24 24"><path d="M3 11 12 4l9 7v9h-6v-6H9v6H3z"/><path d="m8 12 2 2 5-5"/></svg>';
    return '<svg viewBox="0 0 24 24"><path d="M3 11 12 4l9 7v9h-6v-6H9v6H3z"/></svg>';
  }

  function renderLegendMarkers() {
    document.querySelectorAll('.legend-marker[data-marker-key]').forEach(el => {
      const key = el.dataset.markerKey;
      const type = ['apartment','airbnb','headquarters'].includes(key) ? key : 'house';
      const palette = markerPalette(key);
      el.style.setProperty('--marker-color', palette.fill);
      el.style.setProperty('--marker-border', palette.border);
      el.innerHTML = iconSvg(type, key);
    });
  }

  function makeMarker(property) {
    const key = markerKey(property);
    const el = document.createElement('button');
    el.className = 'property-marker';
    const palette = markerPalette(key);
    el.style.setProperty('--marker-color', palette.fill);
    el.style.setProperty('--marker-border', palette.border);
    el.innerHTML = `<span class="marker-face">${iconSvg(property.property_type, property.status)}</span>`;
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
    const label = p.property_type === 'headquarters' ? 'Headquarters' : p.status.replace('_', ' ');
    const detailLink = p.property_type === 'headquarters' ? '' : `<a href="${escapeHtml(p.detail_url)}">View property</a>`;
    return `<article class="map-popup">${image}<div><span class="status-pill ${escapeHtml(markerKey(p))}">${escapeHtml(label)}</span><h2>${escapeHtml(p.title)}</h2><p>${escapeHtml(p.city)}, ${escapeHtml(p.state)}</p>${detailLink}</div></article>`;
  }

  function isVisible(p) {
    const key = markerKey(p);
    const query = search.value.trim().toLowerCase();
    const matchesQuery = !query || [p.title, p.address_line1, p.city, p.status, p.property_type].some(v => String(v || '').toLowerCase().includes(query));
    return activeFilters.has(key) && matchesQuery;
  }

  function render() {
    const visible = properties.filter(isVisible);
    const listed = visible.filter(p => p.property_type !== 'headquarters');
    markers.forEach(({marker, element}, id) => {
      const show = visible.some(p => p.id === id);
      element.hidden = !show;
      element.style.display = show ? '' : 'none';
    });
    list.innerHTML = listed.length ? listed.map(cardHtml).join('') : '<p class="empty">No properties match those filters.</p>';
    document.querySelector('#result-count').textContent = `${listed.length} shown`;
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
  renderLegendMarkers();

  fetch(window.MAVREI.apiUrl, {headers:{Accept:'application/json'}})
    .then(response => { if (!response.ok) throw new Error('Unable to load properties'); return response.json(); })
    .then(data => {
      properties = data.properties;
      properties.forEach(makeMarker);
      const portfolioProperties = properties.filter(p => p.property_type !== 'headquarters');
      document.querySelector('#stat-total').textContent = portfolioProperties.length;
      document.querySelector('#stat-current').textContent = portfolioProperties.filter(p => p.status !== 'sold').length;
      document.querySelector('#stat-sold').textContent = portfolioProperties.filter(p => p.status === 'sold').length;
      if (properties.length) {
        const bounds = new maplibregl.LngLatBounds();
        properties.forEach(p => bounds.extend([p.longitude, p.latitude]));
        map.fitBounds(bounds, {padding:45, maxZoom:13});
      }
      render();
    })
    .catch(error => { list.innerHTML = `<p class="error">${escapeHtml(error.message)}.</p>`; });
})();
