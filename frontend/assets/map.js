/**
 * E2T Members Map - Leaflet.js Integration
 * Zeigt Mitglieder auf einer interaktiven Karte
 */

document.addEventListener('DOMContentLoaded', function () {
  const mapElement = document.getElementById('e2t-members-map');
  const dataElement = document.getElementById('e2t-map-data');

  if (!mapElement || !dataElement) {
    console.log('E2T Map: Container nicht gefunden');
    return;
  }

  try {
    const data = JSON.parse(dataElement.textContent);
    console.log('E2T Map: Daten geladen', data);
    console.log('E2T Map: Marker count:', data.markers.length);
    console.log('E2T Map: Filter fields:', data.filterFields);

    // ======================================
    // 1. Karte initialisieren
    // ======================================
    const mapSettings = data.mapSettings;
    console.log('E2T Map: Settings -', mapSettings);
    const map = L.map('e2t-members-map').setView(mapSettings.center, mapSettings.zoom);
    console.log('E2T Map: Karte initialisiert');

    // Speichere Map-Instanz global für Toggle-Funktionalität
    window.e2tMapInstance = map;

    // Kartenstil wählen
    let tileLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors',
      maxZoom: 19,
    });

    if (mapSettings.style === 'dark') {
      tileLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '© OpenStreetMap contributors © CartoDB',
        maxZoom: 19,
      });
    }

    tileLayer.addTo(map);

    // ======================================
    // 2. Marker und Filtering vorbereiten
    // ======================================
    const markers = data.markers;
    const filterFields = data.filterFields;
    let markerClusterGroup = L.markerClusterGroup({
      maxClusterRadius: 80,
      disableClusteringAtZoom: 13,
    });

    // Speichere alle Layer für Filtering
    const allMarkers = {};
    const filterOptions = {};

    // ======================================
    // 3. Filterliste mit Optionen füllen
    // ======================================
    const filterFieldsArray = Array.isArray(filterFields) ? filterFields : [];
    console.log('E2T Map: filterFieldsArray:', filterFieldsArray);

    filterFieldsArray.forEach((field) => {
      filterOptions[field.id] = new Set();
    });

    markers.forEach((marker) => {
      filterFieldsArray.forEach((field) => {
        const fieldId = field.id;
        if (marker.filters[fieldId]) {
          marker.filters[fieldId].forEach((val) => {
            if (val && val.trim() !== '') {
              filterOptions[fieldId].add(val);
            }
          });
        }
      });
    });

    // Sortiere Optionen und fülle Select-Felder (aber nicht Textfelder)
    document.querySelectorAll('.e2t-map-filter-select').forEach((select) => {
      const fieldId = select.getAttribute('data-field');
      const options = Array.from(filterOptions[fieldId] || []).sort();

      options.forEach((option) => {
        const opt = document.createElement('option');
        opt.value = option;
        opt.textContent = option;
        select.appendChild(opt);
      });

      // Filter-Event
      select.addEventListener('change', applyFilters);
    });

    // Event-Listener für Textfelder (PLZ, etc.) - Live-Filtering während Eingabe
    document.querySelectorAll('.e2t-map-filter-text').forEach((input) => {
      input.addEventListener('input', applyFilters);
      input.addEventListener('change', applyFilters);
    });

    // ======================================
    // 4. Marker erstellen und zur Karte hinzufügen
    // ======================================
    markers.forEach((markerData) => {
      const icon = L.divIcon({
        className: 'e2t-marker',
        html: `<div class="e2t-marker-icon" title="${markerData.name}">📍</div>`,
        iconSize: [32, 32],
        iconAnchor: [16, 32],
        popupAnchor: [0, -32],
      });

      const marker = L.marker([markerData.lat, markerData.lng], { icon });

      // Popup-Inhalt
      const popupContent = createPopupContent(markerData);
      marker.bindPopup(popupContent, {
        maxWidth: 300,
        className: 'e2t-leaflet-popup',
      });

      marker.data = markerData; // Speichere Daten für Filtering
      allMarkers[markerData.id] = marker;
      markerClusterGroup.addLayer(marker);
    });

    map.addLayer(markerClusterGroup);

    // ======================================
    // 5. Filter anwenden
    // ======================================
    function applyFilters() {
      const selectedFilters = {};

      // Sammle Select-Filter
      document.querySelectorAll('.e2t-map-filter-select').forEach((select) => {
        const fieldId = select.getAttribute('data-field');
        const value = select.value;
        if (value) {
          selectedFilters[fieldId] = value;
        }
      });

      // Sammle Textfeld-Filter
      document.querySelectorAll('.e2t-map-filter-text').forEach((input) => {
        const fieldId = input.getAttribute('data-field');
        const value = input.value.trim();
        if (value) {
          selectedFilters[fieldId] = value;
        }
      });

      console.log('E2T Map: Filter angewendet', selectedFilters);

      // Durchsuche alle Marker und zeige/verstecke basierend auf Filtern
      markerClusterGroup.clearLayers();

      markers.forEach((markerData) => {
        let matches = true;

        // Prüfe alle aktiven Filter
        for (const fieldId in selectedFilters) {
          const selectedValue = selectedFilters[fieldId].toLowerCase();
          const markerValues = markerData.filters[fieldId] || [];

          // Prüfe ob der Wert im Marker vorhanden ist (mit Partial Matching für Textfelder)
          const valueFound = markerValues.some((v) => {
            if (!v) return false;
            const vStr = String(v).toLowerCase();
            // Für Textfelder: Partial Match (z.B. "45219" matched "45219" oder "45")
            // Für Dropdowns: Exact Match
            return vStr.includes(selectedValue) || vStr === selectedValue;
          });

          if (!valueFound) {
            matches = false;
            break;
          }
        }

        if (matches && allMarkers[markerData.id]) {
          markerClusterGroup.addLayer(allMarkers[markerData.id]);
        }
      });
    }

    // ======================================
    // 6. Reset-Button
    // ======================================
    document.getElementById('e2t-map-reset')?.addEventListener('click', function () {
      document.querySelectorAll('.e2t-map-filter-select').forEach((select) => {
        select.value = '';
      });
      document.querySelectorAll('.e2t-map-filter-text').forEach((input) => {
        input.value = '';
      });
      applyFilters();
    });

    // ======================================
    // 7. Popup-Inhalt erstellen
    // ======================================
    function createPopupContent(markerData) {
      let html = '<div class="e2t-marker-popup">';

      // Profilbild
      if (markerData.image) {
        html += `<img src="${markerData.image}" alt="Profilbild" class="e2t-popup-image">`;
      }

      html += '<div class="e2t-popup-content">';

      // Name
      html += `<h4 class="e2t-popup-name">${markerData.name}</h4>`;

      // Stadt
      html += `<p class="e2t-popup-city"><strong>${markerData.city}</strong></p>`;

      // Filterwerte anzeigen (Stadt, Methoden, etc.)
      html += '<div class="e2t-popup-details">';

      for (const fieldId in markerData.filters) {
        const values = markerData.filters[fieldId];
        if (values && values.length > 0 && values[0]) {
          const displayValues = values.filter(v => v && v.trim() !== '').join(', ');
          if (displayValues) {
            html += `<p><strong>${fieldId}:</strong> ${displayValues}</p>`;
          }
        }
      }

      html += '</div></div></div>';

      return html;
    }

    console.log('E2T Map: Erfolgreich initialisiert mit ' + markers.length + ' Markern');
  } catch (error) {
    console.error('E2T Map: Fehler', error);
  }
});

