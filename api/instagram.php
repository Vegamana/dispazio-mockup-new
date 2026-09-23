<?php
/**
 * Instagram Graph API proxy for the "Latest From Instagram" section.
 *
 * Frontend calls:  GET /api/instagram.php
 * Returns:         { "posts": [ { "image", "caption", "permalink", "media_type" }, ... ] }
 *                  The access token is never included in this response.
 *
 * Setup: copy config.example.php to config.php (same folder) and fill in
 * your real INSTAGRAM_ACCESS_TOKEN there. See api/README.md for the full
 * walkthrough (where to get a token, how to test this file, deployment).
 *
 * Behavior:
 *  - Serves a cached response (api/cache/instagram.json) for up to
 *    INSTAGRAM_CACHE_TTL_SECONDS, so we don't call Instagram on every visit.
 *  - If the cache is stale, fetches fresh data and re-caches it.
 *  - If Instagram is unreachable, the token is invalid, or nothing usable
 *    comes back, we serve the last good cache regardless of its age rather
 *    than fail — and only return an empty/errored response if there has
 *    never been a successful fetch at all. The frontend keeps its own
 *    existing static posts on screen in that case.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
// Server governs real freshness via the cache below; this just stops a
// browser from re-requesting the same response within a few minutes.
header('Cache-Control: public, max-age=300');

// ── Config ───────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    require $configFile; // defines the INSTAGRAM_* constants below, if not already set via env
}

// An environment variable of the same name always wins over config.php, if
// your Hostinger plan supports setting one.
$ACCESS_TOKEN = getenv('INSTAGRAM_ACCESS_TOKEN') ?: (defined('INSTAGRAM_ACCESS_TOKEN') ? INSTAGRAM_ACCESS_TOKEN : '');
$USER_ID      = getenv('INSTAGRAM_USER_ID') ?: (defined('INSTAGRAM_USER_ID') ? INSTAGRAM_USER_ID : 'me');
$POST_LIMIT   = defined('INSTAGRAM_POST_LIMIT') ? (int) INSTAGRAM_POST_LIMIT : 6;
$CACHE_TTL    = defined('INSTAGRAM_CACHE_TTL_SECONDS') ? (int) INSTAGRAM_CACHE_TTL_SECONDS : 1500; // 25 min

$cacheDir  = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/instagram.json';

// ── Helpers ──────────────────────────────────────────────────────────────

/** Emit the JSON response and stop. */
function respond(array $posts, bool $error = false): void
{
    $payload = ['posts' => $posts];
    if ($error) {
        $payload['error'] = true;
    }
    echo json_encode($payload);
    exit;
}

/** Read the cache file, if it exists and is well-formed. */
function readCache(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['fetched_at'], $data['posts']) || !is_array($data['posts'])) {
        return null;
    }
    return $data;
}

/** Write a fresh cache file. Failures here are non-fatal — we still return the live data. */
function writeCache(string $dir, string $file, array $posts): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($file, json_encode(['fetched_at' => time(), 'posts' => $posts]));
}

// ── 1) Serve from cache if it's still fresh ─────────────────────────────
$cached = readCache($cacheFile);
if ($cached !== null && (time() - (int) $cached['fetched_at']) < $CACHE_TTL) {
    respond($cached['posts']);
}

// ── 2) No token configured yet: serve stale cache if we have one, else empty ──
if ($ACCESS_TOKEN === '') {
    if ($cached !== null) {
        respond($cached['posts']);
    }
    respond([], true);
}

// ── 3) Fetch fresh data from the Instagram Graph API ────────────────────
$fields = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp';
$requestLimit = max(1, $POST_LIMIT) + 4; // pad a little; some items may lack a usable image
$url = sprintf(
    'https://graph.instagram.com/%s/media?fields=%s&limit=%d&access_token=%s',
    rawurlencode($USER_ID),
    $fields,
    $requestLimit,
    rawurlencode($ACCESS_TOKEN)
);

$raw = null;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($result !== false && $curlErr === '' && $httpCode === 200) {
        $raw = $result;
    }
} elseif (ini_get('allow_url_fopen')) {
    $context = stream_context_create(['http' => ['timeout' => 8]]);
    $result  = @file_get_contents($url, false, $context);
    if ($result !== false) {
        $raw = $result;
    }
}

$decoded = ($raw !== null) ? json_decode($raw, true) : null;

if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
    // Instagram unreachable, token invalid/expired, or rate-limited.
    // Serve the last known-good cache no matter how old, rather than an
    // empty section — the frontend falls back to its static posts if
    // there's no cache at all yet.
    if ($cached !== null) {
        respond($cached['posts']);
    }
    respond([], true);
}

// ── 4) Normalize into the shape the frontend expects ────────────────────
$posts = [];
foreach ($decoded['data'] as $item) {
    if (!isset($item['permalink'])) {
        continue;
    }
    $mediaType = $item['media_type'] ?? 'IMAGE';

    // VIDEO's media_url points at the video file itself, not an image, so
    // the card preview needs thumbnail_url there. IMAGE and CAROUSEL_ALBUM
    // already have a still image as media_url (the carousel's cover photo).
    if ($mediaType === 'VIDEO') {
        $image = $item['thumbnail_url'] ?? $item['media_url'] ?? '';
    } else {
        $image = $item['media_url'] ?? $item['thumbnail_url'] ?? '';
    }
    if ($image === '') {
        continue; // nothing usable to show for this item
    }

    $posts[] = [
        'image'      => $image,
        'caption'    => isset($item['caption']) ? mb_substr((string) $item['caption'], 0, 200) : '',
        'permalink'  => $item['permalink'],
        'media_type' => $mediaType,
    ];

    if (count($posts) >= $POST_LIMIT) {
        break;
    }
}

if (empty($posts)) {
    if ($cached !== null) {
        respond($cached['posts']);
    }
    respond([], true);
}

writeCache($cacheDir, $cacheFile, $posts);
respond($posts);
