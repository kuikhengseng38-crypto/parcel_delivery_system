/* Admin report map for GPS paths saved at delivery completion. */
'use strict';
(() => {
    document.addEventListener('DOMContentLoaded', () => {
        const routes = window.DeliveryRouteReportData || [];
        const container = document.getElementById('deliveryRouteReportMap');
        if (!container || !routes.length || typeof L === 'undefined') return;
        const map = L.map(container).setView([5.3992, 100.3628], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19, attribution:'© OpenStreetMap contributors' }).addTo(map);
        let line, start, finish;
        const caption = document.getElementById('deliveryRouteMapCaption');
        function showRoute(id) {
            const route = routes.find(item => Number(item.id) === Number(id)); if (!route) return;
            [line, start, finish].forEach(layer => { if (layer) map.removeLayer(layer); });
            const points = route.points.map(point => [Number(point.lat), Number(point.lng)]).filter(point => Number.isFinite(point[0]) && Number.isFinite(point[1]));
            if (points.length) {
                line = L.polyline(points, {color:'#2563eb', weight:5, opacity:.9}).addTo(map);
                start = L.circleMarker(points[0], {radius:7, color:'#fff', weight:3, fillColor:'#2563eb', fillOpacity:1}).bindPopup('Route start').addTo(map);
                finish = L.circleMarker(points[points.length-1], {radius:7, color:'#fff', weight:3, fillColor:'#16a34a', fillOpacity:1}).bindPopup('Delivery completed').addTo(map);
                map.fitBounds(line.getBounds(), {padding:[35,35]});
            } else map.setView([5.3992, 100.3628], 11);
            caption.textContent = `${route.rider_name}'s delivery route · ${points.length ? points.length + ' GPS points' : 'No GPS points were recorded'}`;
        }
        document.querySelectorAll('.delivery-route-item').forEach(button => button.addEventListener('click', () => {
            document.querySelectorAll('.delivery-route-item').forEach(item => item.classList.remove('active'));
            button.classList.add('active'); showRoute(button.dataset.routeId);
        }));
        showRoute(routes[0].id);
    });
})();
