<?php
/**
 * Redirect Tracer — single-file PHP tool for debugging click chains
 * in programmatic marketing.
 *
 * Just upload this file to your server and open it in the browser.
 * No dependencies beyond PHP with curl extension.
 *
 * Handles:
 *  - HTTP 3xx redirects (full chain)
 *  - meta-refresh
 *  - JS redirect heuristics (window.location, location.href, etc.)
 *  - Cookie tracking per hop
 *  - Macro detection in final URL
 *  - Device UA simulation
 */

// ==================== CONFIG ====================
ini_set('max_execution_time', 60);
ini_set('memory_limit', '128M');

$USER_AGENTS = [
    'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'mobile_ios' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    'mobile_android' => 'Mozilla/5.0 (Linux; Android 13; SM-S908B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
];

$MAX_HOPS = 25;

// ==================== TRACER CORE ====================

/**
 * Parse Set-Cookie headers from response
 */
function parseSetCookies($headers) {
    $cookies = [];
    foreach ($headers as $h) {
        if (stripos($h, 'set-cookie:') === 0) {
            $val = trim(substr($h, 11));
            $parts = explode(';', $val);
            $kv = explode('=', $parts[0], 2);
            if (count($kv) === 2) {
                $cookies[] = ['name' => trim($kv[0]), 'value' => trim($kv[1])];
            }
        }
    }
    return $cookies;
}

/**
 * Extract headers as associative array, keeping only the interesting ones
 */
function extractHeaders($headerBlock) {
    $lines = explode("\r\n", $headerBlock);
    $result = [];
    $wanted = ['location', 'server', 'content-type', 'x-powered-by', 'via',
               'cf-ray', 'x-amz-cf-id', 'refresh', 'x-cache'];
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) continue;
        list($k, $v) = explode(':', $line, 2);
        $k = strtolower(trim($k));
        if (in_array($k, $wanted)) {
            $result[$k] = trim($v);
        }
    }
    return $result;
}

/**
 * Extract raw Set-Cookie lines (can appear multiple times)
 */
function extractSetCookieLines($headerBlock) {
    $lines = explode("\r\n", $headerBlock);
    $out = [];
    foreach ($lines as $line) {
        if (stripos($line, 'set-cookie:') === 0) {
            $out[] = $line;
        }
    }
    return $out;
}

/**
 * Detect meta-refresh in HTML
 */
function detectMetaRefresh($html, $baseUrl) {
    if (preg_match('/<meta[^>]+http-equiv=["\']?refresh["\']?[^>]+content=["\']?\s*\d+\s*;\s*url=([^"\'>\s]+)/i', $html, $m)) {
        return resolveUrl(trim($m[1], '"\' '), $baseUrl);
    }
    return null;
}

/**
 * Detect common JS redirect patterns
 */
function detectJsRedirect($html, $baseUrl) {
    $patterns = [
        '/window\.location(?:\.href)?\s*=\s*["\']([^"\']+)["\']/i',
        '/window\.location\.replace\s*\(\s*["\']([^"\']+)["\']/i',
        '/window\.location\.assign\s*\(\s*["\']([^"\']+)["\']/i',
        '/location(?:\.href)?\s*=\s*["\']([^"\']+)["\']/i',
        '/document\.location(?:\.href)?\s*=\s*["\']([^"\']+)["\']/i',
        '/top\.location(?:\.href)?\s*=\s*["\']([^"\']+)["\']/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $html, $m)) {
            return resolveUrl($m[1], $baseUrl);
        }
    }
    return null;
}

/**
 * Resolve relative URL against base
 */
function resolveUrl($url, $base) {
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    if (strpos($url, '//') === 0) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $url;
    }
    $parts = parse_url($base);
    if (!$parts) return $url;
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    if (strpos($url, '/') === 0) {
        return $scheme . '://' . $host . $url;
    }
    $path = isset($parts['path']) ? dirname($parts['path']) : '/';
    return $scheme . '://' . $host . rtrim($path, '/') . '/' . $url;
}

/**
 * Detect unresolved tracking macros in URL
 */
function detectMacros($url) {
    $patterns = [
        '/\{[a-z_]+\}/i',           // {click_id}
        '/\[[A-Z_]+\]/',             // [CACHEBUSTER]
        '/__[A-Z_]+__/',             // __CLICK_ID__
        '/%%[A-Z_]+%%/',             // %%CLICK_ID%%
        '/\$\{[a-z_]+\}/i',          // ${click_id}
    ];
    $found = [];
    foreach ($patterns as $p) {
        if (preg_match_all($p, $url, $m)) {
            $found = array_merge($found, $m[0]);
        }
    }
    return array_values(array_unique($found));
}

/**
 * Perform a single HTTP request and return details
 */
function fetchOne($url, $userAgent, $cookieJar, $timeout = 10) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate',
            'Upgrade-Insecure-Requests: 1',
        ],
    ]);

    $t0 = microtime(true);
    $response = curl_exec($ch);
    $elapsed = (int)((microtime(true) - $t0) * 1000);

    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['error' => $err, 'elapsed' => $elapsed];
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    $headerBlock = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    // If multiple header blocks (shouldn't happen with FOLLOWLOCATION=false,
    // but just in case), take the last one
    $headerBlocks = preg_split('/\r\n\r\n/', trim($headerBlock));
    $lastHeaderBlock = end($headerBlocks);

    $headers = extractHeaders($lastHeaderBlock);
    $setCookieLines = extractSetCookieLines($lastHeaderBlock);

    curl_close($ch);

    return [
        'status' => $status,
        'url' => $effectiveUrl,
        'headers' => $headers,
        'set_cookie_count' => count($setCookieLines),
        'set_cookies' => parseSetCookies($setCookieLines),
        'body' => $body,
        'elapsed' => $elapsed,
        'error' => null,
    ];
}

/**
 * Full redirect trace following HTTP, meta-refresh and JS heuristics
 */
function traceUrl($startUrl, $device, $timeout) {
    global $USER_AGENTS, $MAX_HOPS;

    $ua = $USER_AGENTS[$device] ?? $USER_AGENTS['desktop'];
    $cookieJar = tempnam(sys_get_temp_dir(), 'rtcj_');

    $hops = [];
    $currentUrl = $startUrl;
    $startTime = microtime(true);
    $errors = [];

    for ($i = 0; $i < $MAX_HOPS; $i++) {
        $result = fetchOne($currentUrl, $ua, $cookieJar, $timeout);
        $timeFromStart = (int)((microtime(true) - $startTime) * 1000);

        if ($result['error']) {
            $errors[] = "hop {$i}: " . $result['error'];
            break;
        }

        $hop = [
            'index' => $i,
            'url' => $currentUrl,
            'status' => $result['status'],
            'headers' => $result['headers'],
            'set_cookie_count' => $result['set_cookie_count'],
            'time_from_start_ms' => $timeFromStart,
            'elapsed_ms' => $result['elapsed'],
            'redirect_type' => 'final',
            'next_url' => null,
        ];

        $status = $result['status'];
        $nextUrl = null;
        $redirectType = 'final';

        // HTTP 3xx redirect
        if ($status >= 300 && $status < 400 && !empty($result['headers']['location'])) {
            $nextUrl = resolveUrl($result['headers']['location'], $currentUrl);
            $redirectType = 'http_' . $status;
        }
        // Meta-refresh
        elseif ($status === 200) {
            $metaUrl = detectMetaRefresh($result['body'], $currentUrl);
            if ($metaUrl) {
                $nextUrl = $metaUrl;
                $redirectType = 'meta_refresh';
            } else {
                // JS redirect heuristic
                $jsUrl = detectJsRedirect($result['body'], $currentUrl);
                if ($jsUrl && $jsUrl !== $currentUrl) {
                    $nextUrl = $jsUrl;
                    $redirectType = 'js_heuristic';
                }
            }
        }

        $hop['redirect_type'] = $redirectType;
        $hop['next_url'] = $nextUrl;
        $hops[] = $hop;

        if (!$nextUrl) break;
        $currentUrl = $nextUrl;
    }

    if (count($hops) >= $MAX_HOPS) {
        $errors[] = "Max hops ({$MAX_HOPS}) reached — possible redirect loop";
    }

    @unlink($cookieJar);

    $finalUrl = end($hops)['url'] ?? $startUrl;
    $totalMs = (int)((microtime(true) - $startTime) * 1000);

    return [
        'initial_url' => $startUrl,
        'final_url' => $finalUrl,
        'total_hops' => count($hops),
        'total_time_ms' => $totalMs,
        'hops' => $hops,
        'macros_unresolved' => detectMacros($finalUrl),
        'errors' => $errors,
    ];
}

// ==================== ROUTING ====================

// Handle trace API call
if (isset($_POST['action']) && $_POST['action'] === 'trace') {
    header('Content-Type: application/json');
    $url = $_POST['url'] ?? '';
    $device = $_POST['device'] ?? 'desktop';
    $timeout = (int)($_POST['timeout'] ?? 10);

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['error' => 'Invalid URL']);
        exit;
    }

    try {
        $result = traceUrl($url, $device, $timeout);
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Otherwise serve HTML UI below
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>REDIRECT TRACER // programmatic debug</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;700;800&family=Fraunces:ital,opsz,wght@0,9..144,600;0,9..144,800;1,9..144,700&display=swap" rel="stylesheet">
<style>
    :root {
        --bg: #0a0b0d;
        --bg-2: #111317;
        --bg-3: #171a1f;
        --line: #262a31;
        --line-2: #1d2026;
        --ink: #e6e8eb;
        --ink-2: #8a919c;
        --ink-3: #555a63;
        --accent: #d4ff3a;
        --accent-2: #ff6b35;
        --ok: #4ade80;
        --warn: #fbbf24;
        --err: #f87171;
        --blue: #60a5fa;
        --purple: #c084fc;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
        background: var(--bg);
        color: var(--ink);
        font-family: 'JetBrains Mono', ui-monospace, monospace;
        font-size: 13px;
        line-height: 1.55;
        min-height: 100vh;
        -webkit-font-smoothing: antialiased;
    }
    body {
        background-image:
            radial-gradient(circle at 10% -10%, rgba(212,255,58,0.06), transparent 50%),
            radial-gradient(circle at 110% 110%, rgba(255,107,53,0.05), transparent 50%);
    }
    .wrap {
        max-width: 1400px;
        margin: 0 auto;
        padding: 32px 28px 80px;
    }
    header {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        border-bottom: 1px solid var(--line);
        padding-bottom: 20px;
        margin-bottom: 28px;
    }
    .brand { display: flex; align-items: baseline; gap: 14px; }
    .brand-icon {
        font-family: 'Fraunces', serif;
        font-style: italic;
        font-weight: 800;
        font-size: 38px;
        color: var(--accent);
        line-height: 1;
        letter-spacing: -0.03em;
    }
    .brand-title {
        font-weight: 800;
        font-size: 14px;
        letter-spacing: 0.18em;
        text-transform: uppercase;
    }
    .brand-sub {
        color: var(--ink-3);
        font-size: 11px;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        display: block;
        margin-top: 3px;
    }
    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 11px;
        color: var(--ink-2);
        letter-spacing: 0.1em;
        text-transform: uppercase;
    }
    .status-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: var(--ok);
        box-shadow: 0 0 8px var(--ok);
        animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    .panel {
        background: var(--bg-2);
        border: 1px solid var(--line);
        border-radius: 4px;
        overflow: hidden;
    }
    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        background: var(--bg-3);
        border-bottom: 1px solid var(--line);
        font-size: 11px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--ink-2);
    }
    .panel-header .dots { display: flex; gap: 6px; }
    .panel-header .dots span {
        width: 10px; height: 10px; border-radius: 50%;
        background: var(--line);
    }
    .panel-header .dots span:nth-child(1) { background: var(--err); opacity: 0.5; }
    .panel-header .dots span:nth-child(2) { background: var(--warn); opacity: 0.5; }
    .panel-header .dots span:nth-child(3) { background: var(--ok); opacity: 0.5; }
    .input-panel { margin-bottom: 24px; }
    .input-body {
        padding: 20px;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 14px;
        align-items: start;
    }
    .url-field { position: relative; }
    .url-prompt {
        position: absolute;
        left: 14px; top: 50%;
        transform: translateY(-50%);
        color: var(--accent);
        font-weight: 700;
        pointer-events: none;
        font-size: 13px;
    }
    .url-input {
        width: 100%;
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 3px;
        padding: 14px 14px 14px 34px;
        color: var(--ink);
        font-family: inherit;
        font-size: 13px;
        outline: none;
        transition: border-color 0.15s;
    }
    .url-input:focus { border-color: var(--accent); }
    .url-input::placeholder { color: var(--ink-3); }
    .opts {
        display: flex;
        gap: 16px;
        margin-top: 14px;
        flex-wrap: wrap;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--ink-2);
    }
    .opt { display: flex; align-items: center; gap: 8px; }
    .opt select {
        background: var(--bg);
        border: 1px solid var(--line);
        color: var(--ink);
        padding: 6px 10px;
        font-family: inherit;
        font-size: 11px;
        border-radius: 2px;
        cursor: pointer;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .btn-run {
        background: var(--accent);
        color: #000;
        border: none;
        padding: 14px 28px;
        font-family: inherit;
        font-weight: 700;
        font-size: 12px;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        cursor: pointer;
        border-radius: 3px;
        transition: transform 0.1s, background 0.15s;
        height: fit-content;
    }
    .btn-run:hover { background: #e8ff5c; }
    .btn-run:active { transform: scale(0.98); }
    .btn-run:disabled { background: var(--line); color: var(--ink-3); cursor: wait; }
    .stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 0;
        background: var(--bg-2);
        border: 1px solid var(--line);
        border-radius: 4px;
        margin-bottom: 24px;
        overflow: hidden;
    }
    .stat { padding: 18px 20px; border-right: 1px solid var(--line); }
    .stat:last-child { border-right: none; }
    .stat-label {
        font-size: 10px;
        color: var(--ink-3);
        text-transform: uppercase;
        letter-spacing: 0.15em;
        margin-bottom: 6px;
    }
    .stat-value {
        font-family: 'Fraunces', serif;
        font-weight: 600;
        font-size: 28px;
        letter-spacing: -0.02em;
        line-height: 1;
    }
    .stat-value.accent { color: var(--accent); }
    .stat-value.warn { color: var(--warn); }
    .stat-value.err { color: var(--err); }
    .hops-panel { margin-bottom: 20px; }
    .hop {
        display: grid;
        grid-template-columns: 52px 90px 1fr auto;
        gap: 14px;
        padding: 14px 20px;
        border-bottom: 1px solid var(--line-2);
        align-items: start;
        transition: background 0.1s;
        cursor: pointer;
        position: relative;
    }
    .hop:last-child { border-bottom: none; }
    .hop:hover { background: var(--bg-3); }
    .hop::before {
        content: '';
        position: absolute;
        left: 40px;
        top: 38px;
        bottom: -14px;
        width: 1px;
        background: var(--line);
    }
    .hop:last-child::before { display: none; }
    .hop-idx {
        font-weight: 700;
        color: var(--ink-3);
        font-size: 11px;
        padding-top: 3px;
    }
    .hop-badge {
        position: relative;
        z-index: 1;
        background: var(--bg-2);
        border: 1px solid var(--line);
        padding: 3px 0;
        text-align: center;
        font-size: 11px;
        font-weight: 700;
        border-radius: 2px;
        letter-spacing: 0.05em;
    }
    .hop-badge.s2xx { border-color: var(--ok); color: var(--ok); }
    .hop-badge.s3xx { border-color: var(--accent); color: var(--accent); }
    .hop-badge.s4xx { border-color: var(--warn); color: var(--warn); }
    .hop-badge.s5xx { border-color: var(--err); color: var(--err); }
    .hop-badge.js   { border-color: var(--purple); color: var(--purple); }
    .hop-badge.meta { border-color: var(--blue); color: var(--blue); }
    .hop-url {
        font-size: 12px;
        word-break: break-all;
        color: var(--ink);
        padding-top: 3px;
    }
    .hop-meta {
        display: flex;
        gap: 8px;
        margin-top: 6px;
        flex-wrap: wrap;
    }
    .meta-tag {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding: 2px 7px;
        border-radius: 2px;
        background: var(--bg-3);
        color: var(--ink-2);
        border: 1px solid var(--line-2);
    }
    .meta-tag.cookie { color: var(--accent-2); border-color: rgba(255,107,53,0.3); }
    .meta-tag.domain { color: var(--blue); }
    .meta-tag.redirect-js { color: var(--purple); border-color: rgba(192,132,252,0.3); }
    .meta-tag.redirect-meta { color: var(--blue); border-color: rgba(96,165,250,0.3); }
    .hop-time {
        font-size: 11px;
        color: var(--ink-3);
        white-space: nowrap;
        padding-top: 4px;
        text-align: right;
    }
    .hop-time strong {
        color: var(--ink);
        font-weight: 500;
    }
    .hop-details {
        grid-column: 1 / -1;
        margin-top: 10px;
        padding: 12px;
        background: var(--bg);
        border: 1px solid var(--line-2);
        border-radius: 2px;
        font-size: 11px;
        display: none;
    }
    .hop.expanded .hop-details { display: block; }
    .hop-details .row {
        display: grid;
        grid-template-columns: 110px 1fr;
        gap: 10px;
        padding: 3px 0;
        border-bottom: 1px dashed var(--line-2);
    }
    .hop-details .row:last-child { border-bottom: none; }
    .hop-details .k { color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.08em; font-size: 10px; }
    .hop-details .v { word-break: break-all; color: var(--ink); }
    .final-url-box {
        background: linear-gradient(135deg, rgba(212,255,58,0.08), rgba(212,255,58,0.02));
        border: 1px solid rgba(212,255,58,0.25);
        border-radius: 4px;
        padding: 20px 24px;
        margin-bottom: 24px;
    }
    .final-label {
        font-size: 10px;
        color: var(--accent);
        letter-spacing: 0.2em;
        text-transform: uppercase;
        margin-bottom: 8px;
        font-weight: 700;
    }
    .final-url {
        font-size: 14px;
        color: var(--ink);
        word-break: break-all;
        line-height: 1.5;
    }
    .macro-warn {
        margin-top: 12px;
        padding: 10px 14px;
        background: rgba(251,191,36,0.08);
        border: 1px solid rgba(251,191,36,0.3);
        border-radius: 3px;
        font-size: 11px;
        color: var(--warn);
    }
    .macro-warn strong { font-weight: 700; }
    .macro-warn code {
        background: rgba(251,191,36,0.15);
        padding: 1px 6px;
        border-radius: 2px;
        margin: 0 2px;
        font-family: inherit;
    }
    .empty { text-align: center; padding: 80px 20px; color: var(--ink-3); }
    .empty-glyph {
        font-family: 'Fraunces', serif;
        font-style: italic;
        font-size: 120px;
        color: var(--line);
        line-height: 1;
        margin-bottom: 20px;
    }
    .empty-text {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.2em;
    }
    .loading { padding: 40px; text-align: center; }
    .loading-bar {
        height: 2px;
        background: var(--line);
        border-radius: 2px;
        overflow: hidden;
        max-width: 300px;
        margin: 0 auto 16px;
    }
    .loading-bar::after {
        content: '';
        display: block;
        height: 100%;
        width: 30%;
        background: var(--accent);
        animation: slide 1.2s ease-in-out infinite;
    }
    @keyframes slide {
        0%   { transform: translateX(-100%); }
        100% { transform: translateX(400%); }
    }
    .loading-text {
        font-size: 11px;
        color: var(--ink-2);
        text-transform: uppercase;
        letter-spacing: 0.2em;
    }
    .error-box {
        background: rgba(248,113,113,0.08);
        border: 1px solid rgba(248,113,113,0.3);
        padding: 16px 20px;
        border-radius: 3px;
        color: var(--err);
        font-size: 12px;
        margin-bottom: 20px;
    }
    .note-banner {
        background: rgba(96,165,250,0.06);
        border: 1px solid rgba(96,165,250,0.2);
        padding: 10px 16px;
        border-radius: 3px;
        font-size: 11px;
        color: var(--blue);
        margin-bottom: 20px;
        letter-spacing: 0.03em;
    }
    footer {
        margin-top: 40px;
        padding-top: 20px;
        border-top: 1px solid var(--line);
        display: flex;
        justify-content: space-between;
        font-size: 10px;
        color: var(--ink-3);
        text-transform: uppercase;
        letter-spacing: 0.15em;
    }
    @media (max-width: 720px) {
        .input-body { grid-template-columns: 1fr; }
        .hop { grid-template-columns: 40px 80px 1fr; }
        .hop-time { grid-column: 2 / -1; text-align: left; padding-top: 0; }
    }
</style>
</head>
<body>
<div class="wrap">

<header>
    <div class="brand">
        <div class="brand-icon">↻</div>
        <div>
            <div class="brand-title">Redirect Tracer</div>
            <span class="brand-sub">programmatic · click-chain debug · php edition</span>
        </div>
    </div>
    <div class="status-pill">
        <span class="status-dot"></span>
        <span>php <?php echo PHP_VERSION; ?></span>
    </div>
</header>

<div class="note-banner">
    ℹ php edition captures http redirects + meta-refresh + common js patterns.
    for complex js redirects use the playwright version.
</div>

<div class="panel input-panel">
    <div class="panel-header">
        <span>// input</span>
        <div class="dots"><span></span><span></span><span></span></div>
    </div>
    <div class="input-body">
        <div>
            <div class="url-field">
                <span class="url-prompt">›</span>
                <input type="text" id="urlInput" class="url-input"
                    placeholder="https://tracker.example.com/click?campaign_id=123&aff_id=456...">
            </div>
            <div class="opts">
                <label class="opt">
                    device
                    <select id="device">
                        <option value="desktop">desktop</option>
                        <option value="mobile_ios">iOS</option>
                        <option value="mobile_android">Android</option>
                    </select>
                </label>
                <label class="opt">
                    timeout per hop
                    <select id="timeout">
                        <option value="5">5s</option>
                        <option value="10" selected>10s</option>
                        <option value="20">20s</option>
                    </select>
                </label>
            </div>
        </div>
        <button id="runBtn" class="btn-run">› trace</button>
    </div>
</div>

<div id="output">
    <div class="empty">
        <div class="empty-glyph">↻</div>
        <div class="empty-text">paste a tracker URL above</div>
    </div>
</div>

<footer>
    <span>redirect-tracer v1.0 · php</span>
    <span>single-file · no dependencies</span>
</footer>

</div>

<script>
const output = document.getElementById("output");
const runBtn = document.getElementById("runBtn");
const urlInput = document.getElementById("urlInput");

function statusClass(s, redirectType) {
    if (redirectType === 'js_heuristic') return "js";
    if (redirectType === 'meta_refresh') return "meta";
    if (!s) return "js";
    if (s >= 200 && s < 300) return "s2xx";
    if (s >= 300 && s < 400) return "s3xx";
    if (s >= 400 && s < 500) return "s4xx";
    if (s >= 500) return "s5xx";
    return "js";
}

function statusLabel(s, redirectType) {
    if (redirectType === 'js_heuristic') return "JS";
    if (redirectType === 'meta_refresh') return "META";
    return s || "—";
}

function domainOf(url) {
    try { return new URL(url).hostname; }
    catch (e) { return url; }
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    })[c]);
}

function renderHop(hop, prevHop) {
    const cls = statusClass(hop.status, hop.redirect_type);
    const label = statusLabel(hop.status, hop.redirect_type);
    const domain = domainOf(hop.url);
    const prevDomain = prevHop ? domainOf(prevHop.url) : null;
    const crossDomain = prevDomain && prevDomain !== domain;

    const tags = [];
    if (crossDomain) tags.push(`<span class="meta-tag domain">→ ${escapeHtml(domain)}</span>`);
    if (hop.redirect_type === 'js_heuristic')
        tags.push(`<span class="meta-tag redirect-js">js redirect</span>`);
    if (hop.redirect_type === 'meta_refresh')
        tags.push(`<span class="meta-tag redirect-meta">meta-refresh</span>`);
    if (hop.redirect_type && hop.redirect_type.startsWith('http_'))
        tags.push(`<span class="meta-tag">${escapeHtml(hop.redirect_type.replace('_', ' '))}</span>`);
    if (hop.set_cookie_count > 0)
        tags.push(`<span class="meta-tag cookie">+${hop.set_cookie_count} cookie${hop.set_cookie_count>1?'s':''}</span>`);
    if (hop.headers && hop.headers.server)
        tags.push(`<span class="meta-tag">${escapeHtml(hop.headers.server)}</span>`);

    const hopDelta = prevHop ? hop.time_from_start_ms - prevHop.time_from_start_ms : hop.time_from_start_ms;

    const headersHtml = Object.entries(hop.headers || {})
        .map(([k,v]) => `<div class="row"><div class="k">${escapeHtml(k)}</div><div class="v">${escapeHtml(v)}</div></div>`)
        .join('');

    return `
        <div class="hop" onclick="this.classList.toggle('expanded')">
            <div class="hop-idx">${String(hop.index).padStart(2,'0')}</div>
            <div class="hop-badge ${cls}">${label}</div>
            <div>
                <div class="hop-url">${escapeHtml(hop.url)}</div>
                <div class="hop-meta">${tags.join('')}</div>
            </div>
            <div class="hop-time">
                <strong>+${hopDelta}ms</strong><br>
                <span style="opacity:0.6">t=${hop.time_from_start_ms}ms</span>
            </div>
            <div class="hop-details">
                <div class="row"><div class="k">redirect</div><div class="v">${escapeHtml(hop.redirect_type)}</div></div>
                <div class="row"><div class="k">elapsed</div><div class="v">${hop.elapsed_ms}ms</div></div>
                ${headersHtml}
            </div>
        </div>
    `;
}

function renderResult(data) {
    if (data.error) {
        output.innerHTML = `<div class="error-box">✗ ${escapeHtml(data.error)}</div>`;
        return;
    }

    const hops = data.hops || [];
    const redirectCount = hops.filter(h => h.redirect_type !== 'final').length;
    const domainsVisited = new Set(hops.map(h => domainOf(h.url))).size;
    const totalCookies = hops.reduce((a,h) => a + (h.set_cookie_count||0), 0);

    let errorHtml = '';
    if (data.errors && data.errors.length) {
        errorHtml = `<div class="error-box">⚠ ${data.errors.map(escapeHtml).join(' · ')}</div>`;
    }

    let macroHtml = '';
    if (data.macros_unresolved && data.macros_unresolved.length) {
        const codes = data.macros_unresolved.map(m => `<code>${escapeHtml(m)}</code>`).join('');
        macroHtml = `<div class="macro-warn"><strong>⚠ unresolved macros in final URL:</strong> ${codes}</div>`;
    }

    const hopsHtml = hops.map((h, i) => renderHop(h, i > 0 ? hops[i-1] : null)).join('');

    output.innerHTML = `
        ${errorHtml}
        <div class="stats">
            <div class="stat">
                <div class="stat-label">hops</div>
                <div class="stat-value accent">${hops.length}</div>
            </div>
            <div class="stat">
                <div class="stat-label">redirects</div>
                <div class="stat-value">${redirectCount}</div>
            </div>
            <div class="stat">
                <div class="stat-label">domains</div>
                <div class="stat-value">${domainsVisited}</div>
            </div>
            <div class="stat">
                <div class="stat-label">cookies</div>
                <div class="stat-value ${totalCookies > 10 ? 'warn' : ''}">${totalCookies}</div>
            </div>
            <div class="stat">
                <div class="stat-label">total time</div>
                <div class="stat-value ${data.total_time_ms > 3000 ? 'warn' : ''}">${data.total_time_ms}<span style="font-size:14px;color:var(--ink-3)">ms</span></div>
            </div>
        </div>
        <div class="final-url-box">
            <div class="final-label">final destination</div>
            <div class="final-url">${escapeHtml(data.final_url)}</div>
            ${macroHtml}
        </div>
        <div class="panel hops-panel">
            <div class="panel-header">
                <span>// hop trace (${hops.length})</span>
                <span style="text-transform:none;color:var(--ink-3)">click row for headers</span>
            </div>
            <div>${hopsHtml || '<div class="empty"><div class="empty-text">no hops captured</div></div>'}</div>
        </div>
    `;
}

async function runTrace() {
    const url = urlInput.value.trim();
    if (!url) return;

    runBtn.disabled = true;
    runBtn.textContent = "› running...";
    output.innerHTML = `
        <div class="panel">
            <div class="panel-header"><span>// tracing</span></div>
            <div class="loading">
                <div class="loading-bar"></div>
                <div class="loading-text">following redirect chain</div>
            </div>
        </div>
    `;

    try {
        const formData = new FormData();
        formData.append("action", "trace");
        formData.append("url", url);
        formData.append("device", document.getElementById("device").value);
        formData.append("timeout", document.getElementById("timeout").value);

        const r = await fetch(window.location.href, {
            method: "POST",
            body: formData,
        });
        if (!r.ok) throw new Error("HTTP " + r.status);
        const data = await r.json();
        renderResult(data);
    } catch (e) {
        output.innerHTML = `<div class="error-box">✗ request failed: ${escapeHtml(e.message)}</div>`;
    } finally {
        runBtn.disabled = false;
        runBtn.textContent = "› trace";
    }
}

runBtn.addEventListener("click", runTrace);
urlInput.addEventListener("keydown", e => {
    if (e.key === "Enter") runTrace();
});
</script>
</body>
</html>
