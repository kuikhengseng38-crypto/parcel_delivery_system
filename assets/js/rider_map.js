/**
 * rider_map.js — Rider Delivery Route Map
 *
 * Renders a simple route map for the active parcel:
 * - current rider location
 * - destination marker
 * - route polyline
 * - distance / ETA labels
 */

'use strict';

const RiderRouteMap = (() => {
    const DEFAULT_CENTER = { lat: 5.3992, lng: 100.3628 };
    const GEOCODE_URL = App.baseUrl + '/api/geocode_address.php';
    const RouteURL = 'https://router.project-osrm.org/route/v1/driving/';

    let map = null;
    let currentMarker = null;
    let destinationMarker = null;
    let routeLine = null;
    let destinationPoint = null;
    let currentPoint = null;
    let routeParcel = null;
    let focusResetTimer = null;

    const ROUTE_STYLE = {
        color: '#2563eb',
        weight: 5,
        opacity: 0.9,
        lineCap: 'round',
        lineJoin: 'round',
    };

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function createDotIcon(color, label, accent = false) {
        return L.divIcon({
            className: '',
            html: `
                <div style="position:relative;width:${accent ? 22 : 18}px;height:${accent ? 22 : 18}px;">
                    <div style="width:${accent ? 22 : 18}px;height:${accent ? 22 : 18}px;border-radius:50%;background:${color};border:${accent ? 4 : 3}px solid #fff;box-shadow:0 4px 12px rgba(15,23,42,0.22);display:flex;align-items:center;justify-content:center;${accent ? 'transform:scale(1.05);' : ''}">
                        <span style="width:${accent ? 8 : 6}px;height:${accent ? 8 : 6}px;border-radius:50%;background:#fff;display:block;"></span>
                    </div>
                    <div style="position:absolute;top:22px;left:50%;transform:translateX(-50%);font-size:10px;font-weight:700;color:#334155;white-space:nowrap;background:rgba(255,255,255,0.92);padding:1px 5px;border-radius:9999px;border:1px solid #cbd5e1;">${escHtml(label)}</div>
                </div>
            `,
            iconSize: [accent ? 22 : 18, accent ? 38 : 34],
            iconAnchor: [accent ? 11 : 9, accent ? 11 : 9],
            popupAnchor: [0, -12],
        });
    }

    function setMetric(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function setStatusLabel(text) {
        const el = document.getElementById('routeEtaLabel');
        if (el) el.textContent = text;
    }

    function init(containerId) {
        if (typeof L === 'undefined') {
            setStatusLabel('ETA: map unavailable');
            setMetric('routeDistanceLabel', 'Map library missing');
            setMetric('routeTravelLabel', 'Map library missing');
            return;
        }

        const payload = window.RiderRouteData || {};
        routeParcel = payload.routeParcel || null;
        currentPoint = payload.currentLocation ? {
            lat: Number(payload.currentLocation.lat),
            lng: Number(payload.currentLocation.lng),
        } : null;

        // Always create the map so at least the base tiles + the rider's
        // own position (if known) are visible, even when there is no
        // active parcel to route to yet.
        map = L.map(containerId, {
            zoomControl: true,
            attributionControl: true,
        }).setView(
            currentPoint ? [currentPoint.lat, currentPoint.lng] : [DEFAULT_CENTER.lat, DEFAULT_CENTER.lng],
            currentPoint ? 14 : 12
        );

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);

        // Draw the rider's own marker immediately — this must NOT depend
        // on whether the destination address can be geocoded. Previously
        // this only happened inside the geocode ".then()" branch, so any
        // geocoding failure (network issue, address not found, etc.) meant
        // the rider icon never appeared at all.
        if (currentPoint) {
            drawCurrentLocation(currentPoint);
        }

        if (!routeParcel) {
            setStatusLabel('ETA: —');
            setMetric('routeDistanceLabel', '—');
            setMetric('routeTravelLabel', '—');
            document.addEventListener('rider:location-updated', onLocationUpdated);
            return;
        }

        setStatusLabel('ETA: calculating...');
        resolveDestination(routeParcel)
            .then(point => {
                destinationPoint = point;
                drawDestination();
                if (currentPoint) {
                    drawCurrentLocation(currentPoint);
                    buildRoute(currentPoint, destinationPoint);
                } else {
                    map.setView([destinationPoint.lat, destinationPoint.lng], 14);
                    setStatusLabel('ETA: waiting for GPS');
                    setMetric('routeDistanceLabel', 'Waiting for GPS');
                    setMetric('routeTravelLabel', 'Waiting for GPS');
                }
            })
            .catch(err => {
                console.warn('[RiderRouteMap] Destination geocode failed:', err);
                const message = err && err.message ? err.message : 'Address lookup failed';
                setStatusLabel(`ETA: ${message}`);
                setMetric('routeDistanceLabel', message);
                setMetric('routeTravelLabel', message);

                // Even though we couldn't resolve the destination, make sure
                // the rider's own location stays visible and centered so the
                // map isn't left looking "empty".
                if (currentPoint) {
                    map.setView([currentPoint.lat, currentPoint.lng], 14);
                }
            });

        document.addEventListener('rider:location-updated', onLocationUpdated);
    }

    function onLocationUpdated(event) {
        if (!map || !destinationPoint || !event.detail) return;

        const nextPoint = {
            lat: Number(event.detail.latitude),
            lng: Number(event.detail.longitude),
        };

        if (!Number.isFinite(nextPoint.lat) || !Number.isFinite(nextPoint.lng)) return;

        currentPoint = nextPoint;
        drawCurrentLocation(nextPoint);
        buildRoute(nextPoint, destinationPoint);
    }

    function resolveDestination(parcel) {
        const lat = Number(parcel.latitude);
        const lng = Number(parcel.longitude);
        if (Number.isFinite(lat) && Number.isFinite(lng) && parcel.latitude !== null && parcel.longitude !== null) {
            return Promise.resolve({ lat, lng });
        }

        return geocodeDestination(parcel.recipient_address);
    }

    function geocodeDestination(address) {
        return ajax(GEOCODE_URL + '?q=' + encodeURIComponent(address), {
            method: 'GET',
            withCsrf: false,
        }).then(res => {
            if (!res.success) {
                throw new Error(res.message || 'Address lookup failed');
            }

            return {
                lat: parseFloat(res.lat),
                lng: parseFloat(res.lng),
            };
        });
    }

    function drawCurrentLocation(point) {
        if (!map) return;

        const icon = createDotIcon('#2563eb', 'Rider', true);
        if (currentMarker) {
            currentMarker.setLatLng([point.lat, point.lng]);
        } else {
            currentMarker = L.marker([point.lat, point.lng], { icon })
                .bindPopup(`<strong>Your location</strong><br>Live rider position`)
                .addTo(map);
        }
    }

    function drawDestination() {
        if (!map || !destinationPoint || !routeParcel) return;

        const icon = createDotIcon('#0f172a', 'Drop');
        if (destinationMarker) {
            destinationMarker.setLatLng([destinationPoint.lat, destinationPoint.lng]);
        } else {
            destinationMarker = L.marker([destinationPoint.lat, destinationPoint.lng], { icon })
                .bindPopup(`
                    <div style="padding:0.1rem 0;min-width:180px;">
                        <strong>${escHtml(routeParcel.recipient_name)}</strong><br>
                        <span>${escHtml(routeParcel.recipient_address)}</span><br>
                        <span style="color:#64748b;">${escHtml(routeParcel.tracking_number)}</span>
                    </div>
                `)
                .addTo(map);
        }
    }

    function buildRoute(start, end) {
        if (!map || !start || !end) return;

        const routePath = `${start.lng},${start.lat};${end.lng},${end.lat}`;
        const url = `${RouteURL}${routePath}?overview=full&geometries=geojson&steps=false`;

        fetch(url)
            .then(res => res.json())
            .then(data => {
                const route = data.routes && data.routes[0];
                if (!route) {
                    throw new Error('No route returned');
                }

                const coords = route.geometry.coordinates.map(([lng, lat]) => [lat, lng]);

                if (routeLine) {
                    routeLine.setLatLngs(coords);
                    routeLine.setStyle(ROUTE_STYLE);
                } else {
                    routeLine = L.polyline(coords, ROUTE_STYLE).addTo(map);
                }

                const bounds = L.latLngBounds([start.lat, start.lng], [end.lat, end.lng]);
                map.fitBounds(bounds, { padding: [36, 36] });

                const distanceKm = (route.distance / 1000).toFixed(1);
                const minutes = Math.max(1, Math.round(route.duration / 60));
                const etaText = minutes < 60 ? `${minutes} min` : `${Math.round(minutes / 60)} h ${minutes % 60} min`;

                setMetric('routeDistanceLabel', `${distanceKm} km`);
                setMetric('routeTravelLabel', etaText);
                setStatusLabel(`ETA: ${etaText}`);
            })
            .catch(err => {
                console.warn('[RiderRouteMap] Route failed:', err);

                // OSRM couldn't be reached / returned no route — still draw a
                // straight dashed line between rider and destination so the
                // user sees *something* instead of a blank map, and fall
                // back to a straight-line distance estimate.
                drawFallbackRoute(start, end);

                const straightKm = haversineKm(start, end);
                setMetric('routeDistanceLabel', `~${straightKm.toFixed(1)} km (direct)`);
                setMetric('routeTravelLabel', 'Route unavailable');
                setStatusLabel('ETA: route unavailable');
            });
    }

    function haversineKm(a, b) {
        const R = 6371;
        const dLat = (b.lat - a.lat) * Math.PI / 180;
        const dLng = (b.lng - a.lng) * Math.PI / 180;
        const lat1 = a.lat * Math.PI / 180;
        const lat2 = b.lat * Math.PI / 180;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    function drawFallbackRoute(start, end) {
        if (!map || !start || !end) return;

        const coords = [
            [start.lat, start.lng],
            [end.lat, end.lng],
        ];

        if (routeLine) {
            routeLine.setLatLngs(coords);
            routeLine.setStyle({ color: '#2563eb', weight: 4, dashArray: '8 10', opacity: 0.9 });
        } else {
            routeLine = L.polyline(coords, {
                color: '#2563eb',
                weight: 4,
                dashArray: '8 10',
                opacity: 0.9,
                lineCap: 'round',
                lineJoin: 'round',
            }).addTo(map);
        }

        const bounds = L.latLngBounds(coords);
        map.fitBounds(bounds, { padding: [36, 36] });
    }

    async function ensureRouteVisible() {
        if (!map || !routeParcel) return false;

        if (!destinationPoint) {
            return resolveDestination(routeParcel)
                .then(point => {
                    destinationPoint = point;
                    drawDestination();

                    if (currentPoint) {
                        drawCurrentLocation(currentPoint);
                        if (routeLine) {
                            map.fitBounds(routeLine.getBounds(), { padding: [36, 36] });
                        } else {
                            drawFallbackRoute(currentPoint, destinationPoint);
                        }
                    } else {
                        map.setView([destinationPoint.lat, destinationPoint.lng], 15);
                    }

                    return true;
                })
                .catch(err => {
                    console.warn('[RiderRouteMap] Route bootstrap failed:', err);
                    return false;
                });
        }

        drawDestination();

        if (currentPoint) {
            drawCurrentLocation(currentPoint);
            if (!routeLine) {
                drawFallbackRoute(currentPoint, destinationPoint);
            }
            return true;
        }

        map.setView([destinationPoint.lat, destinationPoint.lng], 15);
        return true;
    }

    async function focusRoute() {
        if (!map) return false;

        await ensureRouteVisible();

        if (focusResetTimer) {
            clearTimeout(focusResetTimer);
            focusResetTimer = null;
        }

        if (routeLine) {
            const bounds = routeLine.getBounds();
            if (destinationMarker) {
                bounds.extend(destinationMarker.getLatLng());
            }
            if (currentMarker) {
                bounds.extend(currentMarker.getLatLng());
            }
            map.fitBounds(bounds, { padding: [36, 36] });
            routeLine.setStyle({ weight: 7, opacity: 1 });

            focusResetTimer = setTimeout(() => {
                if (routeLine) {
                    routeLine.setStyle(ROUTE_STYLE);
                }
            }, 1200);

            if (destinationMarker) {
                destinationMarker.openPopup();
            }
            return true;
        }

        if (currentPoint && destinationPoint) {
            const bounds = L.latLngBounds([
                [currentPoint.lat, currentPoint.lng],
                [destinationPoint.lat, destinationPoint.lng],
            ]);
            map.fitBounds(bounds, { padding: [36, 36] });
            return true;
        }

        if (destinationPoint) {
            map.setView([destinationPoint.lat, destinationPoint.lng], 15);
            if (destinationMarker) {
                destinationMarker.openPopup();
            }
            return true;
        }

        if (currentPoint) {
            map.setView([currentPoint.lat, currentPoint.lng], 15);
            if (currentMarker) {
                currentMarker.openPopup();
            }
            return true;
        }

        return ensureRouteVisible();
    }

    return { init, focusRoute };
})();

window.RiderRouteMap = RiderRouteMap;
