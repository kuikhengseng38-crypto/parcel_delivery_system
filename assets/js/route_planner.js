/* Multi-stop delivery route planner. Uses OSRM for road distance and ETA. */
'use strict';

(() => {
    const data = window.RoutePlannerData || {};
    const endpoint = (App.baseUrl || '') + '/api/geocode_address.php';
    const saveEndpoint = (App.baseUrl || '') + '/api/save_route_plan.php';
    let map, routeLine, startMarker, stopMarkers = [];
    let origin = data.origin || null;
    let plannedStops = [];
    let metrics = { distance: 0, duration: 0 };

    const $ = id => document.getElementById(id);
    const escapeHtml = value => String(value).replace(/[&<>'"]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;' }[c]));

    function distance(a, b) {
        const r = 6371, dLat = (b.lat-a.lat)*Math.PI/180, dLng = (b.lng-a.lng)*Math.PI/180;
        const h = Math.sin(dLat/2)**2 + Math.cos(a.lat*Math.PI/180)*Math.cos(b.lat*Math.PI/180)*Math.sin(dLng/2)**2;
        return r * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1-h));
    }
    function formatDuration(seconds) {
        const minutes = Math.max(1, Math.round(seconds / 60));
        return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes/60)}h ${minutes%60}m`;
    }
    function redrawStops() {
        const list = $('routeStopList');
        $('plannerStopCount').textContent = `${plannedStops.length} stop${plannedStops.length === 1 ? '' : 's'}`;
        list.innerHTML = plannedStops.map((stop, i) => `<li><span class="stop-number">${i+1}</span><div><strong>${escapeHtml(stop.tracking_number)}</strong><small>${escapeHtml(stop.recipient_name)} · ${escapeHtml(stop.recipient_address)}</small></div></li>`).join('');
    }
    function resetLayers() {
        if (routeLine) { map.removeLayer(routeLine); routeLine = null; }
        stopMarkers.forEach(marker => map.removeLayer(marker)); stopMarkers = [];
        if (startMarker) map.removeLayer(startMarker);
        if (origin) startMarker = L.marker([origin.lat, origin.lng]).bindPopup('Your current location').addTo(map);
    }
    function drawRoute(geometry) {
        resetLayers();
        plannedStops.forEach((stop, i) => {
            const icon = L.divIcon({ className: 'planner-stop-marker', html: `<span>${i+1}</span>`, iconSize: [28, 28], iconAnchor: [14, 14] });
            stopMarkers.push(L.marker([stop.latitude, stop.longitude], { icon }).bindPopup(`<strong>${escapeHtml(stop.tracking_number)}</strong><br>${escapeHtml(stop.recipient_name)}`).addTo(map));
        });
        const points = geometry ? geometry.coordinates.map(([lng, lat]) => [lat, lng]) : [origin, ...plannedStops.map(s => ({lat:s.latitude,lng:s.longitude}))].map(p => [p.lat,p.lng]);
        routeLine = L.polyline(points, { color:'#2563eb', weight:5, opacity:.9, dashArray: geometry ? null : '8 10' }).addTo(map);
        map.fitBounds(routeLine.getBounds(), { padding: [35, 35] });
    }
    async function resolveStops() {
        const parcels = data.parcels || [];
        const results = [];
        for (const parcel of parcels) {
            if (Number.isFinite(Number(parcel.latitude)) && Number.isFinite(Number(parcel.longitude)) && parcel.latitude !== null && parcel.longitude !== null) {
                results.push({...parcel, latitude:Number(parcel.latitude), longitude:Number(parcel.longitude)}); continue;
            }
            const result = await ajax(`${endpoint}?q=${encodeURIComponent(parcel.recipient_address)}`, { withCsrf:false });
            if (result.success) results.push({...parcel, latitude:Number(result.lat), longitude:Number(result.lng)});
        }
        return results;
    }
    function nearestFirst(stops) {
        const remaining = [...stops], ordered = [], point = { ...origin };
        while (remaining.length) {
            let pick = 0;
            for (let i = 1; i < remaining.length; i++) if (distance(point, remaining[i]) < distance(point, remaining[pick])) pick = i;
            const next = remaining.splice(pick, 1)[0]; ordered.push(next); point.lat = next.latitude; point.lng = next.longitude;
        }
        return ordered;
    }
    async function optimise() {
        if (!origin) { $('plannerSummary').textContent = 'Turn on GPS and wait for a location update before planning a route.'; return; }
        const button = $('optimiseRouteBtn'); button.disabled = true; button.textContent = 'Calculating…';
        try {
            const stops = await resolveStops();
            if (!stops.length) throw new Error('No active delivery addresses could be resolved.');
            plannedStops = nearestFirst(stops); redrawStops();
            const coords = [origin, ...plannedStops.map(s => ({lat:s.latitude,lng:s.longitude}))].map(p => `${p.lng},${p.lat}`).join(';');
            const response = await fetch(`https://router.project-osrm.org/route/v1/driving/${coords}?overview=full&geometries=geojson`);
            const result = await response.json(); const route = result.routes && result.routes[0];
            if (!route) throw new Error('Road route is unavailable; a direct route is displayed.');
            metrics = { distance: route.distance, duration: route.duration }; drawRoute(route.geometry);
            $('plannerSummary').textContent = `${(route.distance/1000).toFixed(1)} km · approximately ${formatDuration(route.duration)} · ${plannedStops.length} stops`;
            $('saveRouteBtn').disabled = false;
        } catch (error) {
            if (plannedStops.length) { drawRoute(null); $('saveRouteBtn').disabled = false; }
            $('plannerSummary').textContent = error.message || 'Could not calculate the route.';
        } finally { button.disabled = false; button.textContent = 'Optimise route'; }
    }
    async function save() {
        if (!plannedStops.length) return;
        const button = $('saveRouteBtn'); button.disabled = true; button.textContent = 'Saving…';
        const result = await ajax(saveEndpoint, { method:'POST', data: {
            origin_latitude: origin.lat, origin_longitude: origin.lng,
            total_distance_m: Math.round(metrics.distance), total_duration_s: Math.round(metrics.duration),
            stops: plannedStops.map(stop => ({ parcel_id: stop.id, latitude: stop.latitude, longitude: stop.longitude }))
        }});
        if (result.success) { window.location.reload(); return; }
        button.disabled = false; button.textContent = 'Save to history';
        $('plannerSummary').textContent = result.message || 'Could not save route.';
    }
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof L === 'undefined') return;
        const center = origin || {lat:5.3992, lng:100.3628};
        map = L.map('routePlannerMap').setView([center.lat, center.lng], origin ? 13 : 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19, attribution:'© OpenStreetMap contributors' }).addTo(map);
        if (origin) startMarker = L.marker([origin.lat, origin.lng]).bindPopup('Your current location').addTo(map);
        $('optimiseRouteBtn').addEventListener('click', optimise); $('saveRouteBtn').addEventListener('click', save);
    });
})();
