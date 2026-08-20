/**
 * Exact delivery-point picker for admin parcel forms.
 * Geocode the delivery address automatically, or click the map to refine it.
 */
'use strict';

const ParcelLocationPicker = (() => {
    const DEFAULT_CENTER = [5.3992, 100.3628]; // Butterworth, Penang
    const LOOKUP_DELAY = 650;
    // Resolve from the current admin page so this also works when the app is
    // installed under a subdirectory such as /parcel_delivery_system.
    const GEOCODE_URL = new URL('../api/geocode_address.php', window.location.href).href;

    function init(mapId) {
        if (typeof L === 'undefined') return;

        const mapElement = document.getElementById(mapId);
        const latitudeInput = document.getElementById('recipient_latitude');
        const longitudeInput = document.getElementById('recipient_longitude');
        const addressInput = document.getElementById('recipient_address');
        const locateButton = document.getElementById('locateRecipientAddress');
        const label = document.getElementById('recipientLocationLabel');
        const clearButton = document.getElementById('clearRecipientLocation');
        if (!mapElement || !latitudeInput || !longitudeInput) return;

        const initialLat = Number(latitudeInput.value);
        const initialLng = Number(longitudeInput.value);
        const hasInitialPoint = Number.isFinite(initialLat) && Number.isFinite(initialLng)
            && latitudeInput.value !== '' && longitudeInput.value !== '';
        const map = L.map(mapId).setView(hasInitialPoint ? [initialLat, initialLng] : DEFAULT_CENTER, hasInitialPoint ? 16 : 12);
        let marker = null;
        let lookupTimer = null;
        let lookupController = null;
        let lastLookedUpAddress = '';

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);

        function setPoint(lat, lng, panToPoint = true) {
            latitudeInput.value = Number(lat).toFixed(7);
            longitudeInput.value = Number(lng).toFixed(7);
            const point = [Number(lat), Number(lng)];

            if (marker) {
                marker.setLatLng(point);
            } else {
                marker = L.marker(point, { draggable: true }).addTo(map);
                marker.on('dragend', () => {
                    const next = marker.getLatLng();
                    setPoint(next.lat, next.lng, false);
                });
            }

            if (panToPoint) map.setView(point, Math.max(map.getZoom(), 16));
            if (label) label.textContent = `Selected: ${Number(lat).toFixed(7)}, ${Number(lng).toFixed(7)}`;
        }

        map.on('click', event => setPoint(event.latlng.lat, event.latlng.lng));

        async function geocodeAddress() {
            const address = addressInput ? addressInput.value.trim() : '';
            if (address.length < 5 || address === lastLookedUpAddress) return;

            if (lookupController) lookupController.abort();
            lookupController = new AbortController();
            if (label) label.textContent = 'Looking up address…';

            try {
                const response = await fetch(`${GEOCODE_URL}?q=${encodeURIComponent(address)}`, {
                    credentials: 'same-origin',
                    signal: lookupController.signal,
                });
                const result = await response.json().catch(() => ({}));

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Address was not found.');
                }

                const { lat, lng, display_name: displayName, is_approximate: isApproximate } = result;
                if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) {
                    throw new Error('Address lookup returned invalid coordinates.');
                }

                lastLookedUpAddress = address;
                setPoint(lat, lng);
                if (label) {
                    label.textContent = isApproximate
                        ? `Approximate location: ${displayName || address}. Adjust the pin if needed.`
                        : `Address found: ${displayName || address}`;
                }
            } catch (error) {
                if (error.name === 'AbortError') return;
                if (label) {
                    label.textContent = `${error.message || 'Address not found.'} Click the map to choose the delivery point.`;
                }
            }
        }

        if (addressInput) {
            addressInput.addEventListener('input', () => {
                clearTimeout(lookupTimer);
                lookupTimer = setTimeout(geocodeAddress, LOOKUP_DELAY);
            });
            addressInput.addEventListener('blur', () => {
                clearTimeout(lookupTimer);
                geocodeAddress();
            });
        }

        if (locateButton) {
            locateButton.addEventListener('click', geocodeAddress);
        }

        if (hasInitialPoint) setPoint(initialLat, initialLng, false);

        if (clearButton) {
            clearButton.addEventListener('click', () => {
                latitudeInput.value = '';
                longitudeInput.value = '';
                if (marker) {
                    map.removeLayer(marker);
                    marker = null;
                }
                if (label) label.textContent = 'No exact point selected.';
            });
        }
    }

    return { init };
})();

window.ParcelLocationPicker = ParcelLocationPicker;
