(() => {
  'use strict';

  const landTypes = [
    ['orná půda', 'Orná půda', '#e9c46a'], ['chmelnice', 'Chmelnice', '#b7d66b'], ['vinice', 'Vinice', '#c08497'],
    ['zahrada', 'Zahrada', '#79b86a'], ['ovocný sad', 'Ovocný sad', '#8ccf8a'], ['trvalý travní porost', 'Trvalý travní porost', '#a8d08d'],
    ['lesní pozemek', 'Lesní pozemek', '#4f9363'], ['vodní plocha', 'Vodní plocha', '#6baed6'],
    ['zastavěná plocha a nádvoří', 'Zastavěná plocha a nádvoří', '#d98c70'], ['ostatní plocha', 'Ostatní plocha', '#b8b8b8']
  ];
  const byId = (id) => document.getElementById(id);
  const formatNumber = new Intl.NumberFormat('cs-CZ');
  let selectedParcel = null;
  let hoveredId = null;
  let mapReady = false;

  const readHash = () => {
    const values = new URLSearchParams(location.hash.slice(1));
    const center = (values.get('center') || '').split(',').map(Number);
    return center.length === 2 && center.every(Number.isFinite)
      ? { center, zoom: Number(values.get('zoom')) || 16 } : { center: [15.355, 50.4302], zoom: 16 };
  };
  const writeHash = () => {
    const center = map.getCenter();
    history.replaceState(null, '', `#center=${center.lng.toFixed(5)},${center.lat.toFixed(5)}&zoom=${map.getZoom().toFixed(2)}`);
  };
  const emptyFeatureCollection = () => ({ type: 'FeatureCollection', features: [] });
  const map = new maplibregl.Map({
    container: 'map',
    center: readHash().center,
    zoom: readHash().zoom,
    style: {
      version: 8,
      glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
      sources: {
        osm: { type: 'raster', tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}'], tileSize: 256, attribution: '© Esri, HERE, Garmin, OpenStreetMap contributors' },
        parcels: { type: 'vector', tiles: ['/tiles/{z}/{x}/{y}.pbf'], minzoom: 8, maxzoom: 15 },
        selected: { type: 'geojson', data: emptyFeatureCollection() }
      },
      layers: [
        { id: 'osm', type: 'raster', source: 'osm' },
        { id: 'ku-fill', type: 'fill', source: 'parcels', 'source-layer': 'ku', paint: { 'fill-color': '#78909c', 'fill-opacity': .04 } },
        { id: 'parcels-fill', type: 'fill', source: 'parcels', 'source-layer': 'parcels', paint: { 'fill-color': ['match', ['get', 'druh'], ...landTypes.flatMap((item) => [item[0], item[2]]), '#b8b8b8'], 'fill-opacity': ['case', ['boolean', ['feature-state', 'hover'], false], .55, .35] } },
        { id: 'parcels-line', type: 'line', source: 'parcels', 'source-layer': 'parcels', paint: { 'line-color': '#173f29', 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 1, 15, 2.5] } },
        { id: 'parcel-labels', type: 'symbol', source: 'parcels', 'source-layer': 'parcel_label', minzoom: 15, layout: { 'text-field': ['get', 'cislo'], 'text-font': ['Noto Sans Regular'], 'text-size': 10 }, paint: { 'text-color': '#294936', 'text-halo-color': '#fff', 'text-halo-width': 1 } },
        { id: 'selected-fill', type: 'fill', source: 'selected', paint: { 'fill-color': '#ffd43b', 'fill-opacity': .12 } },
        { id: 'selected-line', type: 'line', source: 'selected', paint: { 'line-color': '#e5a900', 'line-width': 3 } }
      ]
    }
  });

  const setText = (id, value) => { byId(id).textContent = value; };
  const setStatus = (value) => setText('map-status', value);
  const optionalLayers = {
    ortho: {
      source: { type: 'raster', tiles: ['https://ags.cuzk.cz/arcgis1/services/ORTOFOTO/MapServer/WMSServer?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&LAYERS=0&STYLES=&FORMAT=image/png&TRANSPARENT=true&CRS=EPSG:3857&BBOX={bbox-epsg-3857}&WIDTH=256&HEIGHT=256'], tileSize: 256, attribution: '© ČÚZK' },
      layer: { id: 'ortho', type: 'raster', paint: { 'raster-opacity': 1 } }
    },
    wms: {
      source: { type: 'raster', tiles: ['https://services.cuzk.cz/wms/local-km-wms.asp?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&LAYERS=parcelni-cisla,hranice-parcel&STYLES=&FORMAT=image/png&TRANSPARENT=true&CRS=EPSG:3857&BBOX={bbox-epsg-3857}&WIDTH=256&HEIGHT=256'], tileSize: 256, minzoom: 14, attribution: '© ČÚZK' },
      layer: { id: 'cadastral-wms', type: 'raster', minzoom: 14, paint: { 'raster-opacity': .72 } }
    }
  };
  const enableOptionalLayer = (name) => {
    const config = optionalLayers[name];
    if (!map.getSource(name)) {
      map.addSource(name, config.source);
      const before = name === 'ortho' ? 'ku-fill' : undefined;
      map.addLayer({ ...config.layer, source: name }, before);
    }
  };
  const loadJson = async (url) => {
    const response = await fetch(url);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.json();
  };
  const showError = (message) => { setText('search-message', message); };

  function setupFilters() {
    const target = byId('land-filters');
    landTypes.forEach(([code, label, color]) => {
      const wrapper = document.createElement('label');
      wrapper.className = 'check-label';
      const input = document.createElement('input');
      input.type = 'checkbox'; input.value = code; input.checked = true;
      input.addEventListener('change', updateFilter);
      const swatch = document.createElement('span');
      swatch.style.cssText = `width:10px;height:10px;background:${color};border:1px solid #789;`;
      wrapper.append(input, swatch, document.createTextNode(label));
      target.append(wrapper);
    });
  }
  function updateFilter() {
    const selected = [...document.querySelectorAll('#land-filters input:checked')].map((input) => input.value);
    map.setFilter('parcels-fill', selected.length ? ['in', ['get', 'druh'], ['literal', selected]] : ['==', ['get', 'druh'], '__none__']);
    map.setFilter('parcels-line', selected.length ? ['in', ['get', 'druh'], ['literal', selected]] : ['==', ['get', 'druh'], '__none__']);
  }
  async function loadControls() {
    try {
      const [stats, kus] = await Promise.all([loadJson('/api/stats'), loadJson('/api/katastralni-uzemi')]);
      setText('stat-parcels', formatNumber.format(stats.parcely));
      setText('stat-ku', formatNumber.format(stats.katastralni_uzemi));
      setText('stat-municipalities', formatNumber.format(stats.obce));
      const select = byId('ku-select');
      kus.items.forEach((item) => { const option = document.createElement('option'); option.value = item.kod; option.textContent = item.nazev; select.append(option); });
    } catch (error) { setStatus('Data se nepodařilo načíst.'); }
  }
  async function showParcel(id, move = true) {
    try {
      const parcel = await loadJson(`/api/parcels/${encodeURIComponent(id)}`);
      selectedParcel = parcel;
      byId('detail-empty').hidden = true; byId('parcel-detail').hidden = false; byId('close-detail').hidden = false;
      setText('detail-number', `Parcela ${parcel.cislo}`);
      setText('detail-location', `${parcel.katastralni_uzemi.nazev} · ${parcel.obec}`);
      setText('detail-type', parcel.druh_pozemku.nazev || 'neuveden');
      setText('detail-area', `${formatNumber.format(parcel.vymera)} m²`);
      setText('detail-use', parcel.zpusob_vyuziti.nazev || 'neuveden');
      byId('cuzk-link').href = parcel.odkaz;
      map.getSource('selected').setData(parcel.geojson ? { type: 'Feature', id: parcel.id, properties: {}, geometry: parcel.geojson } : emptyFeatureCollection());
      history.replaceState(null, '', `?parcel=${encodeURIComponent(id)}${location.hash}`);
      if (move && parcel.bbox) map.fitBounds([[parcel.bbox.min_lon, parcel.bbox.min_lat], [parcel.bbox.max_lon, parcel.bbox.max_lat]], { padding: 80, maxZoom: 17 });
    } catch (error) { setStatus('Detail parcely se nepodařilo načíst.'); }
  }
  function closeParcel() {
    selectedParcel = null; byId('detail-empty').hidden = false; byId('parcel-detail').hidden = true; byId('close-detail').hidden = true;
    map.getSource('selected').setData(emptyFeatureCollection());
    history.replaceState(null, '', location.pathname + location.hash);
  }
  async function search(event) {
    event.preventDefault(); const text = byId('parcel-number').value.trim(); const ku = byId('ku-select').value;
    if (!text) { showError('Zadejte číslo parcely.'); return; }
    try {
      const data = await loadJson(`/api/parcels/search?text=${encodeURIComponent(text)}${ku ? `&ku=${encodeURIComponent(ku)}` : ''}`);
      const results = byId('search-results'); results.replaceChildren();
      if (!data.items.length) { showError('Parcela nebyla nalezena.'); return; }
      showError('');
      data.items.forEach((item) => { const li = document.createElement('li'); const button = document.createElement('button'); button.type = 'button'; button.textContent = `${item.cislo} · ${item.ku.nazev}`; button.addEventListener('click', () => showParcel(item.id)); li.append(button); results.append(li); });
    } catch (error) { showError('Vyhledávání se nepodařilo provést.'); }
  }
  function setBase(value) {
    if (value === 'ortho') enableOptionalLayer('ortho');
    map.setLayoutProperty('osm', 'visibility', value === 'osm' ? 'visible' : 'none');
    if (map.getLayer('ortho')) map.setLayoutProperty('ortho', 'visibility', value === 'ortho' ? 'visible' : 'none');
  }
  function exportGeoJson() {
    if (!selectedParcel || !selectedParcel.geojson) return;
    const blob = new Blob([JSON.stringify({ type: 'Feature', properties: { id: selectedParcel.id, cislo: selectedParcel.cislo }, geometry: selectedParcel.geojson })], { type: 'application/geo+json' });
    const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = `parcela-${selectedParcel.cislo.replace(/[^\w-]/g, '_')}.geojson`; link.click(); URL.revokeObjectURL(link.href);
  }
  map.on('load', () => { mapReady = true; setupFilters(); loadControls(); setStatus('Mapa načtená'); const id = new URLSearchParams(location.search).get('parcel'); if (id) showParcel(id, false); });
  map.on('error', (event) => {
    if (event.error) console.error(event.error);
    if (!mapReady) setStatus('Mapu se nepodařilo načíst. Zkontrolujte připojení.');
  });
  map.on('moveend', () => { writeHash(); byId('zoom-hint').hidden = map.getZoom() > 13; });
  map.on('mousemove', 'parcels-fill', (event) => { if (!event.features.length) return; if (hoveredId !== null) map.setFeatureState({ source: 'parcels', sourceLayer: 'parcels', id: hoveredId }, { hover: false }); hoveredId = event.features[0].id; map.setFeatureState({ source: 'parcels', sourceLayer: 'parcels', id: hoveredId }, { hover: true }); map.getCanvas().style.cursor = 'pointer'; });
  map.on('mouseleave', 'parcels-fill', () => { if (hoveredId !== null) map.setFeatureState({ source: 'parcels', sourceLayer: 'parcels', id: hoveredId }, { hover: false }); hoveredId = null; map.getCanvas().style.cursor = ''; });
  map.on('click', 'parcels-fill', (event) => { if (event.features[0]) showParcel(event.features[0].id); });
  byId('search-form').addEventListener('submit', search); byId('close-detail').addEventListener('click', closeParcel); byId('export-geojson').addEventListener('click', exportGeoJson);
  document.querySelectorAll('input[name="base"]').forEach((input) => input.addEventListener('change', () => setBase(input.value)));
  byId('cadastral-wms').addEventListener('change', (event) => {
    enableOptionalLayer('wms');
    map.setLayoutProperty('cadastral-wms', 'visibility', event.target.checked ? 'visible' : 'none');
  });
})();
