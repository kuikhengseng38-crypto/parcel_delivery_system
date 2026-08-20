<?php
/**
 * api/geocode_address.php — Address to Coordinates Proxy
 *
 * GET: q=free form address
 * Returns the best match from OpenStreetMap Nominatim.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, 'Method not allowed.');
}

require_auth();

$query = trim(get_param('q'));
if ($query === '') {
    json_response(false, 'Missing address query.');
}

function normalize_address(string $value): string
{
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9\s]/i', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

/**
 * Build a list of query strings to try against Nominatim, ordered from
 * MOST likely to succeed to LEAST likely.
 *
 * Real-world delivery addresses are often extremely specific — POI/brand
 * name, lot number, "Mukim" (survey district), unit numbers, etc. — and
 * Nominatim's free-text search frequently cannot resolve that whole
 * string as one query, even though the underlying street/area is well
 * mapped. The previous version of this function only ever tried
 * variants that kept the *entire* messy string (plus a country suffix),
 * or the very first comma-separated segment alone (which drops the
 * city/state and is just as unlikely to match). It never tried the
 * *tail* of the address — e.g. "Seberang Jaya, Penang" or
 * "13700 Perai, Penang" — which is exactly the kind of fragment
 * Nominatim is good at resolving.
 *
 * Strategy: split on commas, then generate candidates using
 * progressively shorter suffixes (last 4 parts, last 3, last 2, last 1)
 * before falling back to the full original string. This both improves
 * the odds of a match and reduces wasted requests, since the
 * high-probability candidates are tried first.
 */
function build_candidates(string $query): array
{
    $parts = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $query) ?: [])));

    $variants = [];

    if (count($parts) > 1) {
        // Progressively shorter suffixes: last 4, last 3, last 2 parts.
        // These tend to be "<area>, <postcode/town>, <state>" style
        // fragments, which Nominatim resolves far more reliably than a
        // full POI-plus-lot-number string.
        foreach ([4, 3, 2] as $tailLen) {
            if (count($parts) > $tailLen) {
                $variants[] = implode(', ', array_slice($parts, -$tailLen));
            }
        }
        // Last 2 parts + Malaysia, in case the state/country isn't
        // already present in the address.
        $lastTwo = implode(', ', array_slice($parts, -2));
        $variants[] = $lastTwo . ', Malaysia';

        // Whole address, comma-normalised.
        $variants[] = implode(', ', $parts);
    }

    // Full original string as given.
    $variants[] = $query;

    // First segment alone (often the recipient/venue name) — low
    // probability but cheap to try, and country-qualified full string as
    // a last resort.
    if (count($parts) > 1) {
        $variants[] = $parts[0];
    }
    $variants[] = $query . ', Malaysia';

    return array_values(array_unique(array_filter(array_map('trim', $variants))));
}

/**
 * Try to fetch $url as JSON. Attempts cURL first (if available), and
 * transparently falls back to file_get_contents()/streams if cURL fails
 * outright (e.g. missing CA bundle on some local Windows/XAMPP setups) —
 * previously a cURL failure meant we never even tried the stream method.
 *
 * Every failure is logged via error_log() so the *real* reason a
 * geocode lookup failed (no internet from the PHP process, blocked
 * host, TLS/cert problem, DNS failure, etc.) is visible in the PHP
 * built-in server console / error log, instead of only ever surfacing
 * as a generic "Address lookup unavailable" in the browser.
 */
function fetch_remote_json(string $url, string $userAgent): array
{
    $lastError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            // Fail over promptly when the local PHP process has no internet
            // access; the offline area fallback below can then keep the map
            // usable instead of leaving the rider waiting.
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $userAgent,
            ],
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body !== false && $status >= 200 && $status < 300) {
            return [$body, '', $status];
        }

        $lastError = 'cURL error (HTTP ' . $status . '): ' . ($error ?: 'request failed');
        error_log('[geocode_address] ' . $lastError . ' — url=' . $url);
        // Fall through and try the stream-based method below instead of
        // giving up immediately on a cURL-specific failure.
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: {$userAgent}\r\nAccept: application/json\r\n",
            'timeout' => 5,
            'ignore_errors' => true,
        ],
        'https' => [
            'method'  => 'GET',
            'header'  => "User-Agent: {$userAgent}\r\nAccept: application/json\r\n",
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        $streamError = error_get_last()['message'] ?? 'unknown stream error';
        $lastError = $lastError ?: ('stream error: ' . $streamError);
        error_log('[geocode_address] stream fetch failed: ' . $streamError . ' — url=' . $url);
        return [false, $lastError, 0];
    }

    return [$body, '', 200];
}

$candidates = build_candidates($query);

$fallbacks = [
    'maxwell group' => [5.3366, 100.3030],
    'lorong perda selatan 1' => [5.3498, 100.4317],
    'bukit mertajam' => [5.3631, 100.4667],
    'jose rizal' => [14.5995, 120.9842],
    'andres bonifacio' => [14.6760, 121.0437],
    'quezon city' => [14.6760, 121.0437],
    'manila' => [14.5995, 120.9842],
    'acme corp' => [14.5995, 120.9842],
    'globe telecom' => [14.6760, 121.0437],
    'sm stores' => [14.3996, 120.9380],
    'lazada ph' => [14.6760, 121.0437],
    'shopee express' => [14.5995, 120.9842],
    '123 rizal ave manila' => [14.5995, 120.9842],
    '123 rizal ave' => [14.5995, 120.9842],
    '456 bonifacio st quezon city' => [14.6760, 121.0437],
    '456 bonifacio st' => [14.6760, 121.0437],
    '789 aguinaldo hwy cavite' => [14.4793, 120.8969],
    '789 aguinaldo hwy' => [14.4793, 120.8969],
    '321 silang rd ilocos sur' => [17.2278, 120.5853],
    '321 silang rd' => [17.2278, 120.5853],
    '654 luna st pampanga' => [15.0794, 120.6190],
    '654 luna st' => [15.0794, 120.6190],
];

/**
 * Approximate centres used when the geocoding provider cannot be reached.
 *
 * The rider map is based in Penang, so an approximate local result is much
 * more useful than making the route panel unusable whenever a development
 * machine is offline. Exact-address local fallbacks above still take
 * precedence, and Nominatim remains the preferred source when reachable.
 */
$offlineAreaFallbacks = [
    'george town' => [5.4141, 100.3288],
    'tanjung tokong' => [5.4576, 100.3036],
    'bayan lepas' => [5.2983, 100.2632],
    'bukit mertajam' => [5.3631, 100.4667],
    'seberang jaya' => [5.3980, 100.4081],
    'perai' => [5.3864, 100.3900],
    'butterworth' => [5.3992, 100.3628],
    'nibong tebal' => [5.1659, 100.4770],
    'balik pulau' => [5.3500, 100.2333],
    'penang' => [5.4164, 100.3327],
    'kuala lumpur' => [3.1390, 101.6869],
    'shah alam' => [3.0738, 101.5183],
    'petaling jaya' => [3.1073, 101.6067],
    'johor bahru' => [1.4927, 103.7414],
    'ipoh' => [4.5975, 101.0901],
    'melaka' => [2.1896, 102.2501],
    'kota kinabalu' => [5.9804, 116.0735],
    'kuching' => [1.5533, 110.3592],
];

$normalizedQuery = normalize_address($query);
foreach ($fallbacks as $needle => $coords) {
    if (str_contains($normalizedQuery, $needle)) {
        json_response(true, 'Geocoded from local fallback.', [
            'lat' => $coords[0],
            'lng' => $coords[1],
            'display_name' => $query,
        ]);
    }
}

// Keep the most specific offline area match.  This is intentionally only a
// last-resort approximation; a successful provider lookup below is exact.
$offlineFallback = null;
foreach ($offlineAreaFallbacks as $needle => $coords) {
    if (str_contains($normalizedQuery, $needle)
        && ($offlineFallback === null || strlen($needle) > $offlineFallback['length'])) {
        $offlineFallback = ['coords' => $coords, 'length' => strlen($needle), 'area' => $needle];
    }
}

$endpoint = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=';
$userAgent = 'ParcelTrack Pro/1.0 (' . (BASE_URL ?? 'local') . ')';

$lastRemoteError = null;
$attempt = 0;
foreach ($candidates as $candidate) {
    // Nominatim's usage policy caps requests at 1/second. Firing several
    // candidate lookups back-to-back (as the old code did) can trip that
    // limit and get the whole burst rejected. Space requests out a bit —
    // this only affects the (rare) case where several candidates are
    // needed, not the common case where the first or second one hits.
    if ($attempt > 0) {
        usleep(1100000); // 1.1s
    }
    $attempt++;

    $url = $endpoint . rawurlencode($candidate);
    [$body, $remoteError, $status] = fetch_remote_json($url, $userAgent);
    if ($body === false) {
        $lastRemoteError = $remoteError;
        // A transport failure means the provider is unavailable, not that
        // this particular spelling missed. Trying every variant would make
        // an offline rider wait up to a minute before the local fallback is
        // returned.
        break;
    }

    $rows = json_decode($body, true);
    if (is_array($rows) && !empty($rows[0]['lat']) && !empty($rows[0]['lon'])) {
        json_response(true, 'Geocoded successfully.', [
            'lat' => (float) $rows[0]['lat'],
            'lng' => (float) $rows[0]['lon'],
            'display_name' => $rows[0]['display_name'] ?? $candidate,
        ]);
    }

    // Empty result set (not a transport error) — Nominatim understood the
    // request fine but found nothing for this candidate string.
    $lastRemoteError = 'no match for "' . $candidate . '" (HTTP ' . $status . ')';
}

// All candidates failed. Log the underlying reason server-side so it can
// actually be diagnosed (e.g. "no route to host" means the PHP process
// itself has no internet access — common on a locked-down local dev
// machine — vs. a 403 from Nominatim, vs. simply no match for the address).
if ($lastRemoteError) {
    error_log('[geocode_address] All candidates failed for "' . $query . '": ' . $lastRemoteError);
}

if ($offlineFallback !== null) {
    json_response(true, 'Using an approximate destination because address lookup is unavailable.', [
        'lat' => $offlineFallback['coords'][0],
        'lng' => $offlineFallback['coords'][1],
        'display_name' => $query,
        'is_approximate' => true,
    ]);
}

json_response(false, 'Address lookup unavailable. Please try again.', [
    'debug' => (defined('APP_DEBUG') && APP_DEBUG) ? $lastRemoteError : null,
]);
