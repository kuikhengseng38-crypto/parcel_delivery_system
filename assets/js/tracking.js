/**
 * tracking.js — Rider GPS Tracking Module
 *
 * Uses the browser Geolocation API (watchPosition) to continuously
 * track the rider's position and POST updates to the server.
 * Also manages the online/offline toggle button.
 */

'use strict';

const Tracking = (() => {

    // ---------------------------------------------------------------
    // State
    // ---------------------------------------------------------------
    let watchId        = null;   // Returned by navigator.geolocation.watchPosition
    let isOnline       = false;
    let lastLat        = null;
    let lastLng        = null;
    let updateInterval = null;   // Fallback setInterval when watchPosition is slow
    let lastUpdateTime = null;

    const UPDATE_INTERVAL_MS  = 15000; // Push location every 15 seconds minimum
    const STALE_THRESHOLD_MS  = 30000; // Show "stale" warning after 30 seconds
    const API_URL             = App.baseUrl + '/api/update_location.php';
    const TOGGLE_URL          = App.baseUrl + '/api/toggle_online.php';

    // DOM references (populated in init())
    let btnToggle    = null;
    let toggleSwitch = null;
    let gpsStatusEl  = null;
    let lastUpdateEl = null;

    // ---------------------------------------------------------------
    // GPS Helpers
    // ---------------------------------------------------------------

    /**
     * Request geolocation watch — fires callback on each position fix.
     */
    function startWatch() {
        if (!navigator.geolocation) {
            setGpsStatus('error', 'GPS not supported');
            return;
        }

        setGpsStatus('acquiring', 'Acquiring GPS…');

        watchId = navigator.geolocation.watchPosition(
            onPositionSuccess,
            onPositionError,
            {
                enableHighAccuracy: true,
                timeout: 20000,
                maximumAge: 5000,
            }
        );
    }

    /**
     * Stop watching GPS.
     */
    function stopWatch() {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }
        if (updateInterval !== null) {
            clearInterval(updateInterval);
            updateInterval = null;
        }
        setGpsStatus('offline', 'Offline');
    }

    /**
     * Called each time the browser reports a new position.
     */
    function onPositionSuccess(position) {
        lastLat = position.coords.latitude;
        lastLng = position.coords.longitude;

        const accuracy = position.coords.accuracy;
        setGpsStatus('active', `GPS active · ±${Math.round(accuracy)}m`);
        document.dispatchEvent(new CustomEvent('rider:location-updated', {
            detail: {
                latitude: lastLat,
                longitude: lastLng,
                accuracy: accuracy,
            }
        }));

        // Rate-limit: only push update if enough time has elapsed
        const now = Date.now();
        if (!lastUpdateTime || now - lastUpdateTime >= UPDATE_INTERVAL_MS) {
            pushLocation(lastLat, lastLng, accuracy);
        }
    }

    /**
     * Called when geolocation produces an error.
     */
    function onPositionError(err) {
        const messages = {
            1: 'Location access denied. Please allow location in browser settings.',
            2: 'Position unavailable. Check your GPS signal.',
            3: 'Location request timed out. Retrying…',
        };

        const msg = messages[err.code] ?? `GPS error (${err.code})`;
        setGpsStatus('error', msg);
        console.warn('[Tracking] Geolocation error:', err);
    }

    // ---------------------------------------------------------------
    // Server Communication
    // ---------------------------------------------------------------

    /**
     * POST the current location to the server.
     */
    function pushLocation(lat, lng, accuracy) {
        if (!isOnline) return;

        ajax(API_URL, {
            method: 'POST',
            data: {
                latitude:  lat,
                longitude: lng,
                accuracy:  accuracy || null,
            },
        }).then(res => {
            if (res.success) {
                lastUpdateTime = Date.now();
                updateLastUpdateDisplay();
            } else {
                console.warn('[Tracking] Location push failed:', res.message);
            }
        });
    }

    /**
     * Tell the server the rider went online or offline.
     */
    function toggleOnlineStatus(goOnline) {
        return ajax(TOGGLE_URL, {
            method: 'POST',
            data: { online: goOnline ? 1 : 0 },
        });
    }

    // ---------------------------------------------------------------
    // UI Helpers
    // ---------------------------------------------------------------

    function setGpsStatus(state, label) {
        if (!gpsStatusEl) return;

        gpsStatusEl.className = `gps-status ${state}`;
        gpsStatusEl.innerHTML = `<span class="gps-pulse"></span>${escHtml(label)}`;
    }

    function updateLastUpdateDisplay() {
        if (!lastUpdateEl) return;
        if (!lastUpdateTime) {
            lastUpdateEl.textContent = '—';
            return;
        }

        const seconds = Math.round((Date.now() - lastUpdateTime) / 1000);
        lastUpdateEl.textContent = seconds < 5 ? 'Just now' : `${seconds}s ago`;
    }

    function setToggleUI(online) {
        if (!btnToggle) return;

        btnToggle.classList.toggle('is-online',  online);
        btnToggle.classList.toggle('is-offline', !online);

        if (toggleSwitch) {
            toggleSwitch.classList.toggle('active', online);
        }

        const label = btnToggle.querySelector('.toggle-label');
        if (label) label.textContent = online ? 'Online' : 'Offline';
    }

    // ---------------------------------------------------------------
    // Online / Offline Toggle Handler
    // ---------------------------------------------------------------

    function handleToggleClick() {
        const goOnline = !isOnline;

        btnToggle.disabled = true;

        toggleOnlineStatus(goOnline).then(res => {
            btnToggle.disabled = false;

            if (!res.success) {
                showToast(res.message || 'Could not update status.', 'error');
                return;
            }

            isOnline = goOnline;
            setToggleUI(isOnline);

            if (isOnline) {
                startWatch();
                showToast('You are now Online. GPS tracking active.', 'success', 'Status Updated');
            } else {
                stopWatch();
                showToast('You are now Offline.', 'info', 'Status Updated');
            }

            // Sync top-bar toggle too (if present)
            syncTopBarToggle();

        }).catch(() => {
            btnToggle.disabled = false;
            showToast('Network error. Please try again.', 'error');
        });
    }

    function syncTopBarToggle() {
        const wrap = document.getElementById('topBarOnlineToggle');
        if (!wrap) return;
        wrap.innerHTML = renderToggleButton();
        // Re-bind events on the newly rendered top bar toggle
        initToggleButton(wrap.querySelector('.online-toggle'));
    }

    function renderToggleButton() {
        const onlineClass = isOnline ? 'is-online' : 'is-offline';
        const activeClass = isOnline ? 'active'    : '';
        const label       = isOnline ? 'Online'    : 'Offline';

        return `
          <button class="online-toggle ${onlineClass}" id="onlineToggleBtn" type="button">
            <span class="toggle-switch ${activeClass}">
              <span class="toggle-knob"></span>
            </span>
            <span class="toggle-label">${label}</span>
          </button>`;
    }

    function initToggleButton(btn) {
        if (!btn) return;
        btn.addEventListener('click', handleToggleClick);
    }

    // ---------------------------------------------------------------
    // Stale-location Warning (refreshes the "last update" display)
    // ---------------------------------------------------------------
    function startDisplayRefresh() {
        setInterval(updateLastUpdateDisplay, 5000);
    }

    // ---------------------------------------------------------------
    // Public init()
    // ---------------------------------------------------------------

    /**
     * Initialise the tracking module.
     *
     * @param {object} options
     * @param {boolean} [options.currentlyOnline=false]  Server-side online status
     */
    function init({ currentlyOnline = false } = {}) {
        isOnline = currentlyOnline;

        // Resolve DOM elements
        btnToggle    = document.getElementById('onlineToggleBtn');
        toggleSwitch = document.querySelector('#onlineToggleBtn .toggle-switch');
        gpsStatusEl  = document.getElementById('gpsStatus');
        lastUpdateEl = document.getElementById('lastUpdateTime');

        // Set initial UI state
        setToggleUI(isOnline);

        // Bind toggle buttons (both dashboard and top bar)
        document.querySelectorAll('.online-toggle').forEach(btn => {
            initToggleButton(btn);
        });

        // If rider was already online (e.g. page refresh), resume tracking
        if (isOnline) {
            startWatch();
        } else {
            setGpsStatus('offline', 'Offline');
        }

        // Populate top-bar toggle area
        syncTopBarToggle();

        startDisplayRefresh();
    }

    // Expose public API
    return { init, pushLocation, startWatch, stopWatch };

})();
