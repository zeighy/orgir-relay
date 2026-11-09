<?php
declare(strict_types=1);

/**
 * OpenRouter image generation relay.
 *
 * Routes (all except /health require "Authorization: Bearer <token>"):
 *   GET  /health            liveness check (no auth)
 *   GET  /howto             short usage guide (no auth)
 *   GET  /models            models/options the caller may use
 *   POST /generate          generate image(s); with "async": true returns 202 + job id immediately
 *   GET  /jobs/{id}         status/result of an async job (poll after check_back_seconds)
 *   GET  /images/{file}     download a saved image
 *   POST /speech            text to speech (mp3) via OpenRouter, e.g. elevenlabs/eleven-v4; "async": true returns 202 + job id
 *   GET  /audio/{file}      download a saved speech file
 */

define('RELAY_APP', true);
// Anything an included file prints by mistake (a stray BOM or whitespace, a wrong file copied over it) must not
// leak into responses: swallow it, and say so in the log instead.
ob_start();
$config = require __DIR__ . '/config.php';
require __DIR__ . '/moderation.php';
$strayOutput = (string)ob_get_clean();
if ($strayOutput !== '' || !function_exists('moderation_check')) {
    log_line($config, 'DEPLOY_WARN config.php/moderation.php printed ' . strlen($strayOutput) . ' unexpected bytes'
        . (function_exists('moderation_check') ? '' : ' and moderation.php did not define its functions (wrong file deployed?)')
        . ': ' . json_encode(mb_substr($strayOutput, 0, 60)));
}

// Turn uncaught errors (e.g. a missing PHP extension) into a logged JSON 500 instead of an empty response.
set_exception_handler(function (Throwable $e) use ($config): void {
    log_line($config, 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    $extra = !empty($config['debug']) ? ['detail' => $e->getMessage()] : [];
    fail(500, 'server_error', 'Internal server error.', $extra);
});

// ------------------------------------------------------------------ helpers

function respond(int $status, array $body): never
{
    pending_clear();
    if (isset($GLOBALS['relay_job_ctx'])) { // running as a detached async job: store the result instead
        job_finish($status, $body);
        exit;
    }
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(int $status, string $code, string $message, array $extra = []): never
{
    respond($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra]);
}

function log_line(array $config, string $msg): void
{
    $file = $config['log_file'] ?? null;
    if (!$file) {
        return;
    }
    @mkdir(dirname($file), 0775, true);
    @file_put_contents($file, date('c') . ' ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m)) {
        return $m[1];
    }
    return null;
}

/** Returns [label, tokenConfig] or terminates with 401/403. */
function authenticate(array $config): array
{
    $sec = $config['security'];

    if (!empty($sec['require_https'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        if (!$https) {
            fail(400, 'https_required', 'HTTPS is required.');
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($sec['ip_allowlist']) && !in_array($ip, $sec['ip_allowlist'], true)) {
        log_line($config, "DENY ip=$ip reason=ip_not_allowed");
        fail(403, 'forbidden', 'Access denied.');
    }

    $token = bearer_token();
    $matched = null;
    if ($token !== null) {
        $hash = hash('sha256', $token);
        // Compare against every entry (no early exit) to avoid timing leaks.
        foreach ($sec['tokens'] as $label => $t) {
            if (hash_equals((string)($t['hash'] ?? ''), $hash)) {
                $matched = [$label, $t];
            }
        }
    }

    $denied = $matched === null
        || !filter_var($matched[1]['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)
        || (!empty($matched[1]['expires']) && strtotime((string)$matched[1]['expires']) < time());

    if ($denied) {
        usleep(max(0, (int)$sec['failed_auth_delay_ms']) * 1000);
        log_line($config, "DENY ip=$ip reason=bad_token");
        header('WWW-Authenticate: Bearer');
        fail(401, 'unauthorized', 'Missing, invalid, or expired token.');
    }

    return $matched;
}

function rate_limit(array $config, string $label, array $tokenCfg): void
{
    $sec = $config['security'];
    $limit = (int)($tokenCfg['rate_limit'] ?? $sec['default_rate_limit']);
    if ($limit <= 0) {
        return;
    }
    $dir = $sec['rate_limit_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return; // fail open on infrastructure problems, but log it
    }
    $fh = fopen($dir . '/' . hash('sha256', $label) . '.json', 'c+');
    if (!$fh) {
        return;
    }
    flock($fh, LOCK_EX);
    $times = json_decode(stream_get_contents($fh) ?: '[]', true) ?: [];
    $now = microtime(true);
    $times = array_values(array_filter($times, fn($t) => $t > $now - 60));
    if (count($times) >= $limit) {
        $retry = (int)ceil(60 - ($now - $times[0]));
        flock($fh, LOCK_UN);
        fclose($fh);
        header('Retry-After: ' . $retry);
        fail(429, 'rate_limited', "Rate limit of $limit requests/minute exceeded.", ['retry_after' => $retry]);
    }
    $times[] = $now;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($times));
    flock($fh, LOCK_UN);
    fclose($fh);
}

function allowed_models(array $config, array $tokenCfg): array
{
    $global = $config['images']['allowed_models'];
    if ($global) { // local models are always allowed alongside the OpenRouter whitelist
        $global = array_values(array_unique(array_merge($global, local_models($config))));
    }
    $perToken = $tokenCfg['allowed_models'] ?? [];
    if ($global && $perToken) {
        return array_values(array_intersect($global, $perToken));
    }
    return $perToken ?: $global;
}

/**
 * URL path the app is mounted under ("" at the web root, "/relay" in a subdirectory).
 * PHP's built-in server sets SCRIPT_NAME to the request path when the docroot has no
 * index.php, so it is only trusted when it actually names this script.
 */
function base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (basename($script) !== basename(__FILE__)) {
        return '';
    }
    return rtrim(dirname($script), '/');
}

function base_url(array $config): string
{
    $b = rtrim((string)$config['output']['public_base_url'], '/');
    if ($b !== '') {
        return $b;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir = base_path();
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

function cleanup_old_images(array $config): void
{
    $hours = (int)$config['output']['retention_hours'];
    $dir = $config['output']['storage_dir'];
    if ($hours <= 0 || !is_dir($dir) || random_int(1, 20) !== 1) {
        return;
    }
    foreach (glob($dir . '/*.*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - $hours * 3600) {
            @unlink($f);
        }
    }
}

/**
 * Local OpenAI-compatible image servers (mlx-serve). Each entry of local_images.endpoints has its own base_url,
 * api_key and models; any other local_images key (timeout, sizes, quality, ...) is a shared default that an
 * endpoint can override. A legacy config with base_url/models directly under local_images counts as one endpoint.
 */
function local_endpoints(array $config): array
{
    $l = $config['local_images'] ?? [];
    if (empty($l['enabled'])) {
        return [];
    }
    $eps = $l['endpoints'] ?? (isset($l['base_url']) ? [['base_url' => $l['base_url'], 'api_key' => $l['api_key'] ?? '', 'models' => $l['models'] ?? []]] : []);
    $shared = array_diff_key($l, array_flip(['enabled', 'endpoints', 'base_url', 'api_key', 'models']));
    $out = [];
    foreach ($eps as $ep) {
        if (!empty($ep['base_url']) && empty($ep['disabled'])) {
            $out[] = $ep + $shared;
        }
    }
    return $out;
}

/** The endpoint serving $model (first match wins), or null when it goes to OpenRouter. */
function local_endpoint(array $config, string $model): ?array
{
    foreach (local_endpoints($config) as $ep) {
        if (in_array($model, (array)($ep['models'] ?? []), true)) {
            return $ep;
        }
    }
    return null;
}

function local_models(array $config): array
{
    $m = [];
    foreach (local_endpoints($config) as $ep) {
        $m = array_merge($m, array_values((array)($ep['models'] ?? [])));
    }
    return array_values(array_unique($m));
}

/** Per local model: quality levels, reference-image support and image cap (for clients and the test page). */
function local_model_info(array $config, array $tokenCfg): array
{
    $allowed = allowed_models($config, $tokenCfg);
    $info = [];
    foreach (local_endpoints($config) as $ep) {
        foreach ((array)($ep['models'] ?? []) as $m) {
            if ($allowed && !in_array($m, $allowed, true)) {
                continue;
            }
            $info[$m] = [
                'quality' => array_keys((array)($ep['quality'] ?? [])),
                'default_quality' => $ep['default_quality'] ?? null,
                'input_images' => !empty($ep['input_images']),
                'max_images_per_request' => min((int)($ep['max_images_per_request'] ?? PHP_INT_MAX), (int)$config['images']['max_images_per_request']),
                'check_back_seconds' => check_back_seconds($config, $ep),
            ];
        }
    }
    return $info;
}

/** POST {endpoint base_url}/images/generations. Returns [status, json, raw] like openrouter_post(). */
function local_images_post(array $ep, array $payload): array
{
    $headers = ['Content-Type: application/json'];
    if (!empty($ep['api_key'])) {
        $headers[] = 'Authorization: Bearer ' . $ep['api_key'];
    }
    $ch = curl_init(rtrim($ep['base_url'], '/') . '/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)($ep['timeout'] ?? 600),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => (bool)($ep['verify_ssl'] ?? true),
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return $raw === false ? [0, null, $err ?: 'curl error'] : [$status, json_decode($raw, true), $raw];
}

/** Extra tries after an upstream timeout / transient error, per image (0 = never retry). */
function upstream_retries(array $config): int
{
    return max(0, (int)($config['openrouter']['retries'] ?? 2));
}

/** Extra tries for a local endpoint: off by default, because a retry queues a second (slow) job on the GPU. */
function local_retries(array $ep): int
{
    return max(0, (int)($ep['retries'] ?? 0));
}

/** Longest a request waits, per image, for a busy local server (HTTP 429) before giving up; 0 = don't wait. */
function local_busy_wait(array $ep): int
{
    return max(0, (int)($ep['busy_wait_seconds'] ?? 0));
}

/**
 * Worst-case seconds one generation request can legitimately run (all images, all retries).
 * Pass the local endpoint when the model is served by one; otherwise the OpenRouter settings apply (unchanged).
 */
function max_job_seconds(array $config, int $n, ?array $ep = null): int
{
    $r = $ep !== null ? local_retries($ep) : upstream_retries($config);
    $timeout = $ep !== null ? (int)($ep['timeout'] ?? 600) : (int)($config['openrouter']['timeout'] ?? 120);
    $per = $timeout * ($r + 1) + 3 * $r * ($r + 1) / 2 + ($ep !== null ? local_busy_wait($ep) : 0);
    return (int)($per * $n);
}

/** POSTs to an OpenRouter API path ("/chat/completions", "/images"). Returns [status, json, raw]. */
function openrouter_post(array $config, string $path, array $payload): array
{
    $or = $config['openrouter'];
    $headers = [
        'Authorization: Bearer ' . $or['api_key'],
        'Content-Type: application/json',
    ];
    if (!empty($or['site_url'])) {
        $headers[] = 'HTTP-Referer: ' . $or['site_url'];
    }
    if (!empty($or['app_name'])) {
        $headers[] = 'X-Title: ' . $or['app_name'];
    }

    $ch = curl_init(rtrim($or['base_url'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)$or['timeout'],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => (bool)$or['verify_ssl'],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return [0, null, $err ?: 'curl error'];
    }
    return [$status, json_decode($raw, true), $raw];
}

/** POSTs JSON to OpenRouter and returns the body untouched (for binary answers such as audio). Returns [status, raw, contentType, curlError]. */
function openrouter_post_binary(array $config, string $path, array $payload): array
{
    $or = $config['openrouter'];
    $headers = ['Authorization: Bearer ' . $or['api_key'], 'Content-Type: application/json'];
    if (!empty($or['site_url'])) {
        $headers[] = 'HTTP-Referer: ' . $or['site_url'];
    }
    if (!empty($or['app_name'])) {
        $headers[] = 'X-Title: ' . $or['app_name'];
    }
    $ch = curl_init(rtrim($or['base_url'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)$or['timeout'],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => (bool)$or['verify_ssl'],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $err = curl_error($ch);
    curl_close($ch);
    return $raw === false ? [0, '', '', $err ?: 'curl error'] : [$status, (string)$raw, $type, ''];
}

function cleanup_old_audio(array $config): void
{
    $hours = (int)$config['output']['retention_hours'];
    $dir = $config['output']['audio_dir'] ?? '';
    if ($hours <= 0 || !is_dir($dir) || random_int(1, 20) !== 1) {
        return;
    }
    foreach (glob($dir . '/*.mp3') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - $hours * 3600) {
            @unlink($f);
        }
    }
}

const INPUT_IMAGE_MIME = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

/** Parses php.ini size values such as "2M" into bytes. */
function ini_bytes(string $key): int
{
    $v = trim((string)ini_get($key));
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n,
    } ?: PHP_INT_MAX;
}

/** Detects PNG/JPEG/GIF/WebP from the file signature (no fileinfo extension needed). */
function image_mime(string $bin): ?string
{
    return match (true) {
        str_starts_with($bin, "\x89PNG\r\n\x1a\n") => 'image/png',
        str_starts_with($bin, "\xFF\xD8\xFF") => 'image/jpeg',
        str_starts_with($bin, 'GIF87a'), str_starts_with($bin, 'GIF89a') => 'image/gif',
        str_starts_with($bin, 'RIFF') && substr($bin, 8, 4) === 'WEBP' => 'image/webp',
        default => null,
    };
}

/** Validates raw image bytes and returns a data URL, or terminates with 422. */
function image_bytes_to_data_url(array $config, string $bin, string $what): string
{
    $max = (int)$config['images']['max_input_image_bytes'];
    if ($bin === '') {
        fail(422, 'invalid_input_images', "$what is empty.");
    }
    if (strlen($bin) > $max) {
        fail(413, 'input_image_too_large', "$what exceeds $max bytes.");
    }
    $mime = image_mime($bin);
    if ($mime === null) {
        fail(422, 'invalid_input_images', "$what is not a PNG, JPEG, WebP or GIF image.");
    }
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/**
 * Turns one JSON input_images entry into something OpenRouter accepts.
 * Accepts: data URL, raw base64, https URL, or a relay image (file name or /images/ URL).
 */
function resolve_input_image(array $config, mixed $u, int $i): string
{
    $what = "input_images[$i]";
    if (!is_string($u) || $u === '') {
        fail(422, 'invalid_input_images', "$what must be a non-empty string.");
    }
    // An image previously generated by this relay: "<name>.png" or ".../images/<name>.png".
    if (preg_match('#(?:^|/images/)([a-f0-9]{32}\.(?:png|jpg|webp|gif))$#', $u, $m)
        && (!str_contains($u, '/') || str_starts_with($u, base_url($config) . '/images/'))) {
        $file = $config['output']['storage_dir'] . '/' . $m[1];
        if (!is_file($file)) {
            fail(422, 'invalid_input_images', "$what refers to an image that no longer exists.");
        }
        return image_bytes_to_data_url($config, (string)file_get_contents($file), $what);
    }
    if (preg_match('#^data:[^;,]*;base64,(.*)$#is', $u, $m)) {
        $bin = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($bin === false) {
            fail(422, 'invalid_input_images', "$what has invalid base64 data.");
        }
        return image_bytes_to_data_url($config, $bin, $what);
    }
    if (preg_match('#^https://#i', $u)) {
        if (empty($config['images']['allow_input_image_urls'])) {
            fail(422, 'invalid_input_images', "$what: https URLs are disabled; upload the file or send base64.");
        }
        if (filter_var($u, FILTER_VALIDATE_URL) === false) {
            fail(422, 'invalid_input_images', "$what is not a valid URL.");
        }
        return $u;
    }
    // Raw base64 without a data: prefix.
    $bin = base64_decode(preg_replace('/\s+/', '', $u), true);
    if ($bin === false) {
        fail(422, 'invalid_input_images', "$what must be an upload, data URL, base64, https URL, or relay image name.");
    }
    return image_bytes_to_data_url($config, $bin, $what);
}

/** Collects multipart uploads (any file field, single or array) as data URLs. */
function uploaded_images(array $config): array
{
    $codes = [
        UPLOAD_ERR_INI_SIZE => [413, 'exceeds the server upload_max_filesize'],
        UPLOAD_ERR_FORM_SIZE => [413, 'exceeds the form size limit'],
        UPLOAD_ERR_PARTIAL => [400, 'was only partially uploaded'],
    ];
    $out = [];
    foreach ($_FILES as $field => $f) {
        // Normalise "image" and "image[]" shapes into a flat list.
        $names = (array)$f['name'];
        $tmps = (array)$f['tmp_name'];
        $errs = (array)$f['error'];
        foreach (array_keys($names) as $k) {
            $what = "Upload \"$field\"" . (is_array($f['name']) ? "[$k]" : '');
            if ($errs[$k] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($errs[$k] !== UPLOAD_ERR_OK) {
                [$st, $why] = $codes[$errs[$k]] ?? [500, 'failed on the server'];
                fail($st, 'upload_error', "$what $why.");
            }
            if (!is_uploaded_file($tmps[$k])) {
                fail(400, 'upload_error', "$what is not a valid upload.");
            }
            $out[] = image_bytes_to_data_url($config, (string)file_get_contents($tmps[$k]), $what);
        }
    }
    return $out;
}

// ------------------------------------------------------------------ async jobs

function jobs_dir(array $config): string
{
    return dirname($config['output']['storage_dir']) . '/jobs';
}

function check_back_seconds(array $config, ?array $ep = null): int
{
    return max(1, (int)($ep['check_back_seconds'] ?? $config['jobs']['check_back_seconds'] ?? 30));
}

function job_write(string $file, array $data): void
{
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
        rename($tmp, $file);
    }
}

/** Called from respond() while a detached job runs: records the final outcome. */
function job_finish(int $status, array $body): void
{
    $job = $GLOBALS['relay_job_ctx'];
    unset($GLOBALS['relay_job_ctx']);
    job_write($job['file'], [
        'label' => $job['label'],
        'created' => $job['created'],
        'finished' => time(),
        'status' => ($body['ok'] ?? false) ? 'done' : (($body['error']['code'] ?? '') === 'request_rejected' ? 'rejected' : 'failed'),
        'http_status' => $status,
        'result' => $body,
    ]);
}

/** Sends the 202 response, closes the connection, and lets the script keep running. */
function detach_with_response(int $status, array $body, int $retryAfter): void
{
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    ignore_user_abort(true);
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('Retry-After: ' . $retryAfter);
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}

function cleanup_old_jobs(array $config): void
{
    $dir = jobs_dir($config);
    $hours = max(1, (int)$config['output']['retention_hours']);
    if (!is_dir($dir) || random_int(1, 20) !== 1) {
        return;
    }
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        if (filemtime($f) < time() - $hours * 3600) {
            @unlink($f);
        }
    }
}

// ------------------------------------------------------------------ history (unadvertised viewer)

function history_dir(array $config): string
{
    return dirname($config['output']['storage_dir']) . '/history';
}

function history_enabled(array $config): bool
{
    return !empty($config['history']['enabled'] ?? true);
}

/** Saves bytes under a content-hash name (deduplicated). Returns the blob file name. */
function history_store_blob(array $config, string $bin, string $ext): ?string
{
    $dir = history_dir($config) . '/blobs';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $name = substr(hash('sha256', $bin), 0, 32) . '.' . $ext;
    $file = $dir . '/' . $name;
    if (is_file($file)) {
        @touch($file); // still in use: keep it out of the retention sweep
        return $name;
    }
    return file_put_contents($file, $bin) === strlen($bin) ? $name : null;
}

/** Describes one reference image (data URL -> stored blob, https URL -> kept as a link). */
function history_ref(array $config, string $url): ?array
{
    if (preg_match('#^data:image/([a-z0-9.+-]+);base64,(.+)$#is', $url, $m)) {
        $ext = match (strtolower($m[1])) {
            'jpeg', 'jpg' => 'jpg', 'webp' => 'webp', 'gif' => 'gif', default => 'png',
        };
        $bin = base64_decode($m[2], true);
        $blob = $bin === false ? null : history_store_blob($config, $bin, $ext);
        return $blob ? ['blob' => $blob] : null;
    }
    return preg_match('#^https://#i', $url) ? ['url' => $url] : null;
}

/** Appends a history entry. Never allowed to break a generation. */
function history_record(array $config, array $entry): void
{
    if (!history_enabled($config)) {
        return;
    }
    try {
        $dir = history_dir($config) . '/entries';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $id = bin2hex(random_bytes(8));
        $entry = ['id' => $id, 'time' => gmdate('c')] + $entry;
        file_put_contents($dir . '/' . gmdate('YmdHis') . '_' . $id . '.json',
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        history_cleanup($config);
    } catch (Throwable $e) {
        log_line($config, 'HISTORY_FAIL ' . $e->getMessage());
    }
}

function history_cleanup(array $config): void
{
    $days = (int)($config['history']['retention_days'] ?? 30);
    if ($days <= 0 || random_int(1, 20) !== 1) {
        return;
    }
    $cut = time() - $days * 86400;
    foreach (['entries', 'blobs'] as $sub) {
        foreach (glob(history_dir($config) . "/$sub/*.*") ?: [] as $f) {
            if (is_file($f) && filemtime($f) < $cut) {
                @unlink($f);
            }
        }
    }
}

const HISTORY_COOKIE = 'relay_view';

function viewer_token_ok(array $config, ?string $token): bool
{
    if ($token === null || $token === '') {
        return false;
    }
    $hash = hash('sha256', $token);
    $ok = false;
    foreach (($config['security']['viewer_tokens'] ?? []) as $t) {
        if (hash_equals((string)($t['hash'] ?? ''), $hash)
            && filter_var($t['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)
            && (empty($t['expires']) || strtotime((string)$t['expires']) >= time())) {
            $ok = true;
        }
    }
    return $ok;
}

function turnstile_enabled(array $config): bool
{
    return !empty($config['turnstile']['site_key'] ?? '') && !empty($config['turnstile']['secret_key'] ?? '');
}

/** Verifies a Cloudflare Turnstile response server-side. Fails closed on any error. */
function turnstile_verify(array $config, string $response): bool
{
    if ($response === '' || strlen($response) > 2048) {
        return false;
    }
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => $config['turnstile']['secret_key'],
            'response' => $response,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) && ($j['success'] ?? false) === true;
}

function history_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function history_html(string $title, string $body, int $status = 200, string $script = '', bool $turnstile = false): never
{
    $nonce = base64_encode(random_bytes(16));
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' https: data:; media-src 'self'; style-src 'unsafe-inline'; form-action 'self'"
        . ($turnstile ? "; script-src https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com" : ($script !== '' ? "; script-src 'nonce-$nonce'" : '')));
    header('X-Robots-Tag: noindex, nofollow');
    $t = htmlspecialchars($title, ENT_QUOTES);
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
        . "<meta name=\"robots\" content=\"noindex\"><title>$t</title><style>"
        . ':root{--bg:#fff;--fg:#1c1c1e;--mut:#6b6b70;--card:#f4f4f6;--line:#dcdce0;--acc:#2f6fdb}'
        . '@media(prefers-color-scheme:dark){:root{--bg:#141416;--fg:#ececf0;--mut:#9a9aa2;--card:#1e1e22;--line:#32323a;--acc:#6ea0ff}}'
        . 'body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,sans-serif}'
        . 'main{max-width:960px;margin:0 auto;padding:16px}h1{font-size:20px;margin:8px 0 4px}'
        . '.mut{color:var(--mut);font-size:13px}.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px;margin:12px 0}'
        . '.meta{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:13px;color:var(--mut)}'
        . '.prompt{white-space:pre-wrap;word-break:break-word;margin:8px 0}'
        . '.imgs{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}.imgs figure{margin:0}'
        . '.imgs img{max-width:220px;max-height:220px;border-radius:6px;border:1px solid var(--line);display:block;background:#8883}'
        . 'pre{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:10px;overflow:auto;font-size:13px}code{font:13px ui-monospace,monospace}h2{font-size:17px;margin:22px 0 4px}'
        . '.wait{color:var(--acc);font-weight:600}.card.pending{border-style:dashed}'
        . '.bad{color:#d33;font-weight:600}.err{border-left:3px solid #d33;padding:2px 10px;margin:6px 0;font-size:13px;white-space:pre-wrap;word-break:break-word}'
        . '.imgs figcaption{font-size:12px;color:var(--mut)}a{color:var(--acc)}'
        . 'input,button{font:inherit;padding:8px 10px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:var(--fg)}'
        . 'button{cursor:pointer}.nav{display:flex;gap:12px;align-items:center;margin:12px 0}'
        . '.lb{position:fixed;inset:0;background:rgba(0,0,0,.88);display:none;flex-direction:column;align-items:center;justify-content:center;z-index:10;padding:12px;box-sizing:border-box}'
        . '.lb.on{display:flex}.lb img{max-width:100%;max-height:calc(100% - 44px);object-fit:contain;border-radius:4px}'
        . '.lb .bar{color:#eee;font-size:13px;margin-top:8px;display:flex;gap:16px;align-items:center}.lb .bar a{color:#9cf}'
        . '.lb button{background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:6px;padding:4px 12px;cursor:pointer}'
        . 'audio{display:block;margin-top:8px;width:100%;max-width:420px}'
        . '.imgs a{cursor:zoom-in}.withheld{width:260px;border:1px dashed #d33;border-radius:6px;padding:8px;font-size:12px;background:var(--card)}'
        . '.withheld summary{cursor:pointer;font-weight:600;color:#d33}.withheld details div{margin-top:6px;word-break:break-all}'
        . '</style></head><body><main>' . $body . '</main>'
        . ($script !== '' ? '<script nonce="' . $nonce . '">' . $script . '</script>' : '') . '</body></html>';
    exit;
}

/** One-line description of the second-opinion result stored with an entry ('' when it did not run). */
function history_screening(array $e): string
{
    $sc = $e['screening'] ?? null;
    if (!is_array($sc)) {
        return '';
    }
    $verdict = (string)($sc['verdict'] ?? '');
    $label = ['safe' => 'safe', 'not_safe' => 'NOT SAFE', 'error' => 'check failed'][$verdict] ?? $verdict;
    $txt = 'Jev: ' . $label;
    if (isset($sc['p'])) {
        $txt .= ' (p=' . number_format((float)$sc['p'], 3) . ', limit ' . number_format((float)($sc['threshold'] ?? 0.5), 2) . ')';
    } elseif (!empty($sc['error'])) {
        $txt .= ': ' . $sc['error'];
    }
    return $txt;
}

/** Second-opinion results for reference images ("Clef ref 1: safe (p=0.02)"), one string per image. */
function history_image_screening(array $e): array
{
    $out = [];
    foreach ((array)($e['screening_images'] ?? []) as $sc) {
        $verdict = (string)($sc['verdict'] ?? '');
        $label = ['safe' => 'safe', 'not_safe' => 'NOT SAFE', 'error' => 'check failed', 'unchecked' => 'not checked (link)'][$verdict] ?? $verdict;
        $name = preg_match('#/([^/]+)$#', (string)($sc['model'] ?? ''), $m) ? ucfirst(preg_replace('/-.*/', '', $m[1])) : 'Image check';
        $refs = isset($sc['indexes']) ? 'refs ' . implode(',', (array)$sc['indexes']) . ' together' : 'ref ' . (int)($sc['index'] ?? 0);
        $txt = $name . ' ' . $refs . ': ' . $label;
        if (isset($sc['p'])) {
            $txt .= ' (p=' . number_format((float)$sc['p'], 3) . ', limit ' . number_format((float)($sc['threshold'] ?? 0.5), 2) . ')';
        } elseif (!empty($sc['error'])) {
            $txt .= ': ' . $sc['error'];
        }
        $out[] = [$verdict === 'safe', $txt];
    }
    return $out;
}

function history_cost(array $e): string
{
    return isset($e['cost']) && $e['cost'] !== null ? '$' . number_format((float)$e['cost'], 4) : 'n/a';
}

/** Handles /history* . Unauthenticated callers see a plain 404 except for the login form. */
function history_handle(array $config, string $method, string $path): never
{
    $base = base_path();
    $self = $base . '/history';
    $h = fn(string $x): string => htmlspecialchars($x, ENT_QUOTES);

    $bearer = bearer_token();
    $cookie = $_COOKIE[HISTORY_COOKIE] ?? null;
    $authed = viewer_token_ok($config, $bearer) || viewer_token_ok($config, is_string($cookie) ? $cookie : null);

    if (!history_enabled($config) || empty($config['security']['viewer_tokens'] ?? [])) {
        fail(404, 'not_found', 'Unknown endpoint.');
    }

    // Login / logout form post.
    if ($path === '/history' && $method === 'POST') {
        if (!empty($_POST['logout'])) {
            setcookie(HISTORY_COOKIE, '', ['expires' => 1, 'path' => $self, 'httponly' => true, 'samesite' => 'Strict',
                'secure' => history_is_https()]);
            header('Location: ' . $self);
            http_response_code(303);
            exit;
        }
        $tok = is_string($_POST['token'] ?? null) ? trim($_POST['token']) : '';
        if (turnstile_enabled($config)) {
            $cf = is_string($_POST['cf-turnstile-response'] ?? null) ? $_POST['cf-turnstile-response'] : '';
            if (!turnstile_verify($config, $cf)) {
                usleep(max(0, (int)$config['security']['failed_auth_delay_ms']) * 1000);
                log_line($config, 'DENY ip=' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' reason=turnstile_failed');
                history_html('Sign in', '<h1>Sign in</h1><p class="mut">Captcha check failed. Please try again.</p>'
                    . history_login_form($self, $config), 401, '', true);
            }
        }
        if (viewer_token_ok($config, $tok)) {
            setcookie(HISTORY_COOKIE, $tok, ['expires' => time() + 7 * 86400, 'path' => $self, 'httponly' => true,
                'samesite' => 'Strict', 'secure' => history_is_https()]);
            header('Location: ' . $self);
            http_response_code(303);
            exit;
        }
        usleep(max(0, (int)$config['security']['failed_auth_delay_ms']) * 1000);
        log_line($config, 'DENY ip=' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' reason=bad_viewer_token');
        history_html('Sign in', '<h1>Sign in</h1><p class="mut">Invalid token.</p>' . history_login_form($self, $config), 401, '', turnstile_enabled($config));
    }

    if ($method !== 'GET' && $method !== 'HEAD') {
        fail(404, 'not_found', 'Unknown endpoint.');
    }
    if (!$authed) {
        if ($path === '/history') {
            history_html('Sign in', '<h1>Sign in</h1>' . history_login_form($self, $config), 401, '', turnstile_enabled($config));
        }
        fail(404, 'not_found', 'Unknown endpoint.'); // do not reveal image/ref paths
    }

    // Stored images: /history/img/<blob>
    if (preg_match('#^/history/img/([a-f0-9]{32}\.(?:png|jpg|webp|gif))$#', $path, $m)) {
        $file = history_dir($config) . '/blobs/' . $m[1];
        if (!is_file($file)) {
            fail(404, 'not_found', 'Image expired.');
        }
        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        header('Content-Type: ' . $types[pathinfo($file, PATHINFO_EXTENSION)]);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    // Stored speech: /history/audio/<blob>
    if (preg_match('#^/history/audio/([a-f0-9]{32}\.mp3)$#', $path, $m)) {
        $file = history_dir($config) . '/blobs/' . $m[1];
        if (!is_file($file)) {
            fail(404, 'not_found', 'Audio expired.');
        }
        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    if ($path !== '/history') {
        fail(404, 'not_found', 'Unknown endpoint.');
    }

    $size = max(1, min(100, (int)($_GET['limit'] ?? ($config['history']['page_size'] ?? 25))));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $files = glob(history_dir($config) . '/entries/*.json') ?: [];
    rsort($files); // names start with a UTC timestamp -> newest first
    $total = count($files);
    $totalCost = 0.0;
    foreach ($files as $f) {
        $e = json_decode((string)file_get_contents($f), true);
        $totalCost += is_array($e) ? (float)($e['cost'] ?? 0) : 0;
    }
    $slice = array_slice($files, ($page - 1) * $size, $size);
    $entries = [];
    foreach ($slice as $f) {
        $e = json_decode((string)file_get_contents($f), true);
        if (is_array($e)) {
            $entries[] = $e;
        }
    }

    $pending = $page === 1 ? pending_list($config) : [];
    $withUrls = function (array $e) use ($self) {
        foreach (['images', 'refs', 'audio'] as $k) {
            foreach ($e[$k] ?? [] as $i => $it) {
                if (isset($it['blob'])) {
                    $e[$k][$i]['url'] = $self . ($k === 'audio' ? '/audio/' : '/img/') . $it['blob'];
                }
            }
        }
        return $e;
    };

    if (($_GET['format'] ?? '') === 'json') {
        respond(200, ['ok' => true, 'pending' => array_map($withUrls, $pending), 'total' => $total, 'total_cost' => round($totalCost, 6), 'page' => $page,
            'page_size' => $size, 'entries' => array_map($withUrls, $entries)]);
    }

    $fig = function (array $it, string $cap) use ($self, $h): string {
        $src = isset($it['blob']) ? $self . '/img/' . $it['blob'] : ($it['url'] ?? '');
        if ($src === '') {
            return '';
        }
        return '<figure><a href="' . $h($src) . '"><img loading="lazy" src="' . $h($src) . '" alt="' . $h($cap) . '"></a>'
            . '<figcaption>' . $h($cap) . '</figcaption></figure>';
    };

    $out = '<h1>Generation history</h1><div class="meta"><span>' . $total . ' entries</span><span>total cost $'
        . number_format($totalCost, 4) . '</span></div>'
        . '<form method="post" action="' . $h($self) . '" style="margin-top:8px"><input type="hidden" name="logout" value="1"><button>Sign out</button></form>';
    if ($pending) {
        $out .= '<h2>In progress (' . count($pending) . ')</h2>';
        foreach ($pending as $e) {
            $out .= '<div class="card pending"><div class="meta"><span class="wait">&#9203; '
                . (($e['phase'] ?? 'rendering') === 'screening' ? 'checking request' : 'waiting for render') . ' &middot; '
                . (int)$e['elapsed'] . 's</span><span>' . $h((string)($e['model'] ?? '')) . '</span><span>by '
                . $h((string)($e['label'] ?? '')) . '</span>'
                . ((int)($e['n'] ?? 1) > 1 ? '<span>' . (int)$e['n'] . ' images</span>' : '')
                . (!empty($e['aspect_ratio']) ? '<span>' . $h((string)$e['aspect_ratio']) . '</span>' : '')
                . '<span>' . $h((string)($e['mode'] ?? '')) . '</span>'
                . (history_screening($e) !== '' ? '<span>' . $h(history_screening($e)) . '</span>' : '')
                . implode('', array_map(fn($t) => '<span>' . $h($t[1]) . '</span>', history_image_screening($e))) . '</div>'
                . '<div class="prompt">' . $h((string)($e['prompt'] ?? '')) . '</div><div class="imgs">';
            foreach ($e['refs'] ?? [] as $i => $it) {
                $out .= $fig($it, 'reference ' . ($i + 1));
            }
            $out .= '</div></div>';
        }
        $out .= '<h2>Completed</h2>';
    }
    if (!$entries) {
        $out .= '<p class="mut">Nothing here yet.</p>';
    }
    foreach ($entries as $e) {
        $out .= '<div class="card"><div class="meta"><span>' . $h((string)($e['time'] ?? '')) . '</span><span>'
            . $h((string)($e['model'] ?? '')) . '</span><span>cost ' . $h(history_cost($e)) . '</span><span>by '
            . $h((string)($e['label'] ?? '')) . '</span>'
            . (!empty($e['aspect_ratio']) ? '<span>' . $h((string)$e['aspect_ratio']) . '</span>' : '')
            . (($e['kind'] ?? '') === 'speech' ? '<span>speech &middot; voice ' . $h((string)($e['voice'] ?? '')) . '</span>' : '')
            . (history_screening($e) !== '' ? '<span class="' . (($e['screening']['verdict'] ?? '') === 'safe' ? '' : 'bad')
                . '">' . $h(history_screening($e)) . '</span>' : '')
            . implode('', array_map(fn($t) => '<span' . ($t[0] ? '' : ' class="bad"') . '>' . $h($t[1]) . '</span>', history_image_screening($e)))
            . (($e['status'] ?? 'ok') !== 'ok' ? '<span class="bad">' . (($e['status'] ?? '') === 'rejected' ? 'REJECTED' : 'FAILED') . ': ' . $h((string)($e['error']['code'] ?? 'error')) . '</span>' : '')
            . (!empty($e['error']['upstream_refusal']) ? '<span class="bad">UPSTREAM REFUSAL</span>' : '')
            . '</div>'
            . '<div class="prompt">' . $h((string)($e['prompt'] ?? '')) . '</div>'
            . (!empty($e['error']) ? '<div class="err">' . $h((string)($e['error']['message'] ?? ''))
                . (!empty($e['error']['model_text']) ? '<br>Model said: ' . $h((string)$e['error']['model_text']) : '') . '</div>' : '')
            . '<div class="imgs">';
        foreach ($e['quarantined'] ?? [] as $q) {
            $out .= '<figure class="withheld"><details><summary>&#128683; Image withheld</summary><div>'
                . 'Not displayed: rejected by the image check as likely harmful content, so it is kept encrypted for review.'
                . '<br>File id: <code>' . $h((string)($q['id'] ?? '')) . '</code>'
                . '<br>Reference ' . (int)($q['index'] ?? 0) . ' &middot; ' . $h((string)($q['mime'] ?? '')) . ' &middot; '
                . number_format(((int)($q['bytes'] ?? 0)) / 1024, 1) . ' KB'
                . '<br>sha256: <code>' . $h(substr((string)($q['sha256'] ?? ''), 0, 16)) . '&hellip;</code>'
                . '<br>Sent with token: <b>' . $h((string)($e['label'] ?? '')) . '</b>'
                . (isset($q['protection']) && $q['protection'] !== 'encrypted' ? '<br>Stored: <b>' . $h((string)$q['protection']) . '</b>' : '')
                . '<br>An admin can retrieve it on the server: <code>php bin/quarantine.php show ' . $h((string)($q['id'] ?? '')) . '</code>'
                . '</div></details><figcaption>reference ' . (int)($q['index'] ?? 0) . ' (withheld)</figcaption></figure>';
        }
        foreach ($e['refs'] ?? [] as $i => $it) {
            $out .= $fig($it, 'reference ' . ($i + 1));
        }
        foreach ($e['images'] ?? [] as $i => $it) {
            $out .= $fig($it, 'result ' . ($i + 1));
        }
        $out .= '</div>';
        foreach ($e['audio'] ?? [] as $it) {
            if (isset($it['blob'])) {
                $out .= '<audio controls preload="none" src="' . $h($self . '/audio/' . $it['blob']) . '"></audio>';
            }
        }
        $out .= '</div>';
    }
    $pages = max(1, (int)ceil($total / $size));
    $out .= '<div class="nav">'
        . ($page > 1 ? '<a href="' . $h($self . '?page=' . ($page - 1) . '&limit=' . $size) . '">&larr; Newer</a>' : '')
        . '<span class="mut">Page ' . $page . ' of ' . $pages . '</span>'
        . ($page < $pages ? '<a href="' . $h($self . '?page=' . ($page + 1) . '&limit=' . $size) . '">Older &rarr;</a>' : '')
        . '</div>';
    history_html('Generation history', $out, 200, <<<'JS'
(function () {
  var lbOpen = function () { return !!document.querySelector('.lb.on'); };
  if (document.querySelector('.card.pending')) {
    setInterval(function () { if (!lbOpen() && !document.hidden) location.reload(); }, 15000);
  }
  var links = Array.prototype.slice.call(document.querySelectorAll('.imgs a'));
  if (!links.length) return;
  var lb = document.createElement('div');
  lb.className = 'lb';
  lb.setAttribute('role', 'dialog');
  lb.setAttribute('aria-modal', 'true');
  lb.innerHTML = '<img alt=""><div class="bar"><button type="button" data-a="prev" aria-label="Previous">&larr;</button>' +
    '<span class="cap"></span><a target="_blank" rel="noopener">Open original</a>' +
    '<button type="button" data-a="next" aria-label="Next">&rarr;</button><button type="button" data-a="close">Close</button></div>';
  document.body.appendChild(lb);
  var img = lb.querySelector('img'), cap = lb.querySelector('.cap'), orig = lb.querySelector('.bar a'), cur = 0;
  function show(i) {
    cur = (i + links.length) % links.length;
    var a = links[cur], fc = a.parentNode.querySelector('figcaption');
    img.src = a.getAttribute('href');
    orig.href = a.getAttribute('href');
    cap.textContent = fc ? fc.textContent : '';
    lb.classList.add('on');
  }
  function hide() { lb.classList.remove('on'); img.removeAttribute('src'); }
  links.forEach(function (a, i) {
    a.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.button) return; // keep "open in new tab" working
      e.preventDefault();
      show(i);
    });
  });
  lb.addEventListener('click', function (e) {
    var act = e.target.getAttribute && e.target.getAttribute('data-a');
    if (act === 'prev') show(cur - 1);
    else if (act === 'next') show(cur + 1);
    else if (act === 'close' || e.target === lb || e.target === img) hide();
  });
  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('on')) return;
    if (e.key === 'Escape') hide();
    else if (e.key === 'ArrowLeft') show(cur - 1);
    else if (e.key === 'ArrowRight') show(cur + 1);
  });
})();
JS);
}

function history_login_form(string $self, array $config = []): string
{
    $widget = turnstile_enabled($config)
        ? '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars((string)$config['turnstile']['site_key'], ENT_QUOTES) . '"></div>'
          . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><br>'
        : '';
    return '<form method="post" action="' . htmlspecialchars($self, ENT_QUOTES) . '" class="card">'
        . '<label>Viewer token<br><input type="password" name="token" autocomplete="current-password" autofocus size="40"></label> '
        . $widget . '<button>Sign in</button></form>';
}


function howto_page(array $config): never
{
    $u = htmlspecialchars(base_url($config), ENT_QUOTES);
    $def = htmlspecialchars((string)$config['images']['default_model'], ENT_QUOTES);
    $maxN = (int)$config['images']['max_images_per_request'];
    $maxIn = (int)$config['images']['max_input_images'];
    $speechHtml = '';
    if (!empty($config['speech']['enabled'])) {
        $sp = $config['speech'];
        $sModel = htmlspecialchars((string)$sp['default_model'], ENT_QUOTES);
        $sVoice = htmlspecialchars((string)$sp['default_voice'], ENT_QUOTES);
        $sDefLen = (int)($sp['max_input_length'][$sp['default_model']] ?? $sp['max_input_length']['default'] ?? 5000);
        $sAsync = (int)($sp['async_timeout'] ?? 900);
        $speechHtml = <<<SPEECH
<h2>Speech (text to speech)</h2>
<pre>curl -X POST $u/speech -H "Authorization: Bearer \$TOKEN" -H "Content-Type: application/json" \
  -d '{"input":"[cheerfully] Welcome! [whispering] Let me show you a secret.","voice":"$sVoice","async":true}'</pre>
<p>The result is always an mp3. Fields (JSON body; all optional except <code>input</code>):</p>
<ul>
<li><code>input</code>: the text to speak, up to $sDefLen characters for the default model (the limit varies per model; <code>/models</code> lists them under <code>speech</code>). Eleven v4 understands audio tags such as <code>[whispering]</code>, <code>[cheerfully]</code> or <code>[laughs]</code>, and they count toward the length.</li>
<li><code>model</code>: one from <code>/models</code> (<code>speech.models</code>); omitted = <code>$sModel</code>.</li>
<li><code>voice</code>: a premade voice name such as <code>george</code>, <code>sarah</code>, <code>adam</code>, <code>alice</code>, <code>bella</code>, <code>brian</code>, <code>charlie</code>, <code>daniel</code>, <code>jessica</code>, <code>roger</code> or <code>will</code>, or an ElevenLabs voice ID; omitted = <code>$sVoice</code>.</li>
<li><code>async</code>: <code>true</code> to get a job id back at once (recommended for long texts). The request to the provider may then run up to $sAsync seconds.</li>
<li><code>response_mode</code>: <code>url</code> (default), <code>base64</code> or <code>both</code>.</li>
<li><code>speed</code>: playback speed (0.7&ndash;1.2) on models that support it; the Eleven v4 models reject anything but the default, so leave it out.</li>
<li><code>elevenlabs</code>: optional object with <code>seed</code>, <code>language_code</code>, <code>previous_text</code>, <code>next_text</code> or <code>voice_settings</code> (<code>stability</code>, <code>similarity_boost</code>, <code>style</code>, <code>use_speaker_boost</code>).</li>
</ul>
<p>Get the result like an image: with <code>async</code>, poll <code>/jobs/&lt;job_id&gt;</code> until <code>done</code>; the reply (or the direct reply without <code>async</code>) has <code>audio.url</code>, which you download with the same header. Audio is kept for 24 hours.</p>
<pre>curl -o out.mp3 $u/audio/&lt;file&gt; -H "Authorization: Bearer \$TOKEN"</pre>

SPEECH;
    }
    $body = <<<HTML
<h1>Image relay: how to generate images and speech</h1>
<p class="mut">Every call except this page needs your API token: <code>Authorization: Bearer &lt;token&gt;</code>.</p>

<h2>1. See what's available</h2>
<pre>curl $u/models -H "Authorization: Bearer \$TOKEN"</pre>
<p>Returns the models your token may use (<code>models</code>), the default (<code>$def</code>), allowed aspect ratios, the max images per request ($maxN), the max reference images ($maxIn) and accepted image types.</p>

<h2>2. Generate</h2>
<pre>curl -X POST $u/generate -H "Authorization: Bearer \$TOKEN" \
  -F "prompt=A watercolor fox in a snowy forest" \
  -F "model=$def" -F "aspect_ratio=16:9" \
  -F "async=1"</pre>
<p>Fields (all optional except <code>prompt</code>):</p>
<ul>
<li><code>model</code>: one from <code>/models</code>; omitted = the default.</li>
<li><code>aspect_ratio</code>: e.g. <code>1:1</code>, <code>16:9</code>.</li>
<li><code>n</code>: number of images (1&ndash;$maxN).</li>
<li><code>async</code>: <code>1</code>/<code>true</code> to get a job id back at once (recommended, see below).</li>
<li><code>response_mode</code>: <code>url</code> (default), <code>base64</code> or <code>both</code>.</li>
</ul>
<p>A JSON body works too (<code>Content-Type: application/json</code>), with the same field names.</p>

<h2>3. Reference images (edits / variations)</h2>
<p>Up to $maxIn images (PNG, JPEG, WebP, GIF). Add file uploads:</p>
<pre>-F "image[]=@photo.png" -F "image[]=@style.jpg"</pre>
<p>or, in JSON or form fields, <code>input_images</code> entries: a <code>data:image/...;base64,</code> URL, raw base64, an <code>https://</code> URL, or the file name of an image the relay generated earlier (valid 24 hours).</p>

<h2>4. Get the result</h2>
<p>With <code>async</code>, the reply is <code>202</code> with <code>job_id</code> and <code>check_back_seconds</code>. Wait that long, then poll:</p>
<pre>curl $u/jobs/&lt;job_id&gt; -H "Authorization: Bearer \$TOKEN"</pre>
<p><code>status</code> is <code>screening</code> (the request is still being checked), <code>running</code> (accepted, generating), <code>done</code>, <code>rejected</code> (not accepted; no reason is given) or <code>failed</code>. When <code>done</code>, <code>images[].url</code> points to the picture; download it with the same header:</p>
<pre>curl -o out.png $u/images/&lt;file&gt; -H "Authorization: Bearer \$TOKEN"</pre>
<p>Without <code>async</code> the same result comes back directly, but generation can take minutes and proxies may time out, so prefer async. Images are kept for 24 hours.</p>

$speechHtml<h2>Errors</h2>
<p>Failures return <code>{"ok":false,"error":{"code":...,"message":...}}</code>. Common codes: <code>unauthorized</code>, <code>model_not_allowed</code>, <code>rate_limited</code> (wait <code>retry_after</code> seconds), <code>invalid_input_images</code>, <code>no_image</code> (no image was produced; rephrase and retry), <code>input_too_long</code> / <code>invalid_voice</code> (speech) and <code>upstream_error</code> (the provider failed or timed out; resubmit once).</p>
HTML;
    history_html('How to generate images and speech', $body);
}

// ------------------------------------------------------------------ pending renders (shown on /history)

function pending_dir(array $config): string
{
    return history_dir($config) . '/pending';
}

/** Marks a request as waiting for OpenRouter; removed automatically when the request ends. */
function pending_start(array $config, array $entry): void
{
    if (!history_enabled($config)) {
        return;
    }
    try {
        $dir = pending_dir($config);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . '/' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($file, json_encode(['created' => time(), 'pid' => getmypid()] + $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $GLOBALS['relay_pending'] = $file;
        // Fatal errors / time limits skip respond(); make sure the marker never gets stuck.
        register_shutdown_function('pending_clear');
    } catch (Throwable $e) {
        // visibility only: never break a generation over it
    }
}

/** Moves the current request's pending marker to another phase ("screening" -> "rendering"). */
function pending_phase(string $phase, ?array $refs = null): void
{
    $f = $GLOBALS['relay_pending'] ?? null;
    $e = $f && is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (is_array($e)) {
        $e['phase'] = $phase;
        if ($refs !== null) {
            $e['refs'] = $refs;
        }
        file_put_contents($f, json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}

function pending_clear(): void
{
    if (isset($GLOBALS['relay_pending'])) {
        @unlink($GLOBALS['relay_pending']);
        unset($GLOBALS['relay_pending']);
    }
}

/** Currently running requests, oldest first. Markers left by a crashed worker are dropped. */
function pending_list(array $config): array
{
    $out = [];
    $files = glob(pending_dir($config) . '/*.json') ?: [];
    sort($files);
    foreach ($files as $f) {
        $e = json_decode((string)@file_get_contents($f), true);
        if (!is_array($e)) {
            continue;
        }
        // The worker that owned this marker is gone (server reload/kill skips shutdown handlers): nothing is rendering.
        if (!empty($e['pid']) && is_dir('/proc/self') && !is_dir('/proc/' . (int)$e['pid'])) {
            @unlink($f);
            continue;
        }
        $age = time() - (int)($e['created'] ?? 0);
        $pendingEp = isset($e['model']) && is_string($e['model']) ? local_endpoint($config, $e['model']) : null;
        if ($age > max_job_seconds($config, max(1, (int)($e['n'] ?? 1)), $pendingEp) + 300) {
            @unlink($f);
            continue;
        }
        $e['elapsed'] = $age;
        $out[] = $e;
    }
    return $out;
}

// ------------------------------------------------------------------ routing

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = base_path();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim(preg_replace('#^/index\.php#', '', $path), '/');

if ($path === '/health' && ($method === 'GET' || $method === 'HEAD')) {
    respond(200, ['ok' => true, 'status' => 'up']);
}

if ($path === '/howto' && ($method === 'GET' || $method === 'HEAD')) {
    howto_page($config);
}

// Debug page: rendered as-is (its own proxy calls come back through here as /live_test_page_99?a=...), no API token.
if ($path === '/live_test_page_99' && ($method === 'GET' || $method === 'HEAD' || $method === 'POST')) {
    require __DIR__ . '/test.php';
    exit;
}

// Same for the speech variant.
if ($path === '/live_test_page_89' && ($method === 'GET' || $method === 'HEAD' || $method === 'POST')) {
    require __DIR__ . '/test_speech.php';
    exit;
}

if ($path === '/history' || str_starts_with($path, '/history/')) {
    history_handle($config, $method, $path); // separate viewer tokens; never uses API tokens
}

[$label, $tokenCfg] = authenticate($config);
if ($method === 'POST') { // only generation is limited; polling jobs / fetching results must never be starved
    rate_limit($config, $label, $tokenCfg);
}

// ---- GET /models
if ($path === '/models' && $method === 'GET') {
    $img = $config['images'];
    respond(200, [
        'ok' => true,
        'default_model' => $img['default_model'],
        'models' => allowed_models($config, $tokenCfg) ?: 'any',
        'local_quality' => array_keys((array)($config['local_images']['quality'] ?? [])),
        'local_model_info' => (object)local_model_info($config, $tokenCfg),
        'local_models' => array_values(array_filter(local_models($config), fn($m) => !($a = allowed_models($config, $tokenCfg)) || in_array($m, $a, true))),
        'aspect_ratios' => $img['allowed_aspect_ratios'],
        'max_images_per_request' => $img['max_images_per_request'],
        'max_prompt_length' => $img['max_prompt_length'],
        'response_modes' => ['url', 'base64', 'both'],
        'accepts_input_images' => (bool)$img['allow_input_images'],
        'max_input_images' => $img['max_input_images'],
        'max_input_image_bytes' => min((int)$img['max_input_image_bytes'], ini_bytes('upload_max_filesize')),
        'input_image_types' => INPUT_IMAGE_MIME,
    ] + (!empty($config['speech']['enabled']) ? ['speech' => [
        'default_model' => $config['speech']['default_model'],
        'default_voice' => $config['speech']['default_voice'],
        'models' => $config['speech']['allowed_models'] ?: 'any',
        'max_input_length' => $config['speech']['max_input_length'],
        'response_modes' => ['url', 'base64', 'both'],
        'format' => 'mp3',
    ]] : []));
}

// ---- GET /jobs/{id}
if ($method === 'GET' && preg_match('#^/jobs/([a-f0-9]{32})$#', $path, $m)) {
    $file = jobs_dir($config) . '/' . $m[1] . '.json';
    $job = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($job) || ($job['label'] ?? null) !== $label) {
        fail(404, 'job_not_found', 'Job not found or expired.');
    }
    $wait = isset($job['check_back']) ? max(1, (int)$job['check_back']) : check_back_seconds($config);
    $age = time() - (int)$job['created'];
    if (in_array($job['status'] ?? '', ['running', 'screening'], true)) {
        $limit = (int)($job['max_seconds'] ?? max_job_seconds($config, (int)$config['images']['max_images_per_request'])) + 120;
        if ($age > $limit) { // worker died without recording a result
            fail(500, 'job_lost', 'The job did not finish and was abandoned.');
        }
        header('Retry-After: ' . $wait);
        respond(200, ['ok' => true, 'job_id' => $m[1], 'status' => $job['status'], 'elapsed_seconds' => $age,
            'check_back_seconds' => $wait]);
    }
    respond(200, ['job_id' => $m[1], 'status' => $job['status'], 'http_status' => $job['http_status']]
        + (array)$job['result']);
}

// ---- GET /images/{file}
if ($method === 'GET' && preg_match('#^/images/([a-f0-9]{32}\.(?:png|jpg|webp|gif))$#', $path, $m)) {
    $file = $config['output']['storage_dir'] . '/' . $m[1];
    if (!is_file($file)) {
        fail(404, 'not_found', 'Image not found or expired.');
    }
    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    header('Content-Type: ' . $types[pathinfo($file, PATHINFO_EXTENSION)]);
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

// ---- POST /generate
if ($path === '/generate' && $method === 'POST') {
    $img = $config['images'];
    $out = $config['output'];

    $ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    $uploads = [];
    if (str_starts_with($ctype, 'multipart/form-data')) {
        // PHP silently drops the whole body when it exceeds post_max_size.
        if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            fail(413, 'request_too_large', 'Request exceeds the server post_max_size ('
                . ini_get('post_max_size') . ').');
        }
        $body = $_POST;
        if (isset($body['n']) && is_string($body['n']) && ctype_digit($body['n'])) {
            $body['n'] = (int)$body['n'];
        }
        foreach (['model', 'aspect_ratio', 'response_mode', 'quality'] as $field) {
            if (($body[$field] ?? null) === '') {
                unset($body[$field]); // empty form field = use the default
            }
        }
        if ($_FILES && !$img['allow_input_images']) {
            fail(422, 'invalid_input_images', 'Input images are not allowed.');
        }
        $uploads = uploaded_images($config);
    } else {
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body)) {
            fail(400, 'invalid_json', 'Request body must be a JSON object (or use multipart/form-data).');
        }
    }

    foreach (['prompt', 'model', 'aspect_ratio', 'response_mode'] as $field) {
        if (isset($body[$field]) && !is_string($body[$field])) {
            fail(422, 'invalid_' . $field, '"' . $field . '" must be a string.');
        }
    }
    if (isset($body['n']) && !is_int($body['n'])) {
        fail(422, 'invalid_n', '"n" must be an integer.');
    }

    $prompt = trim((string)($body['prompt'] ?? ''));
    if ($prompt === '') {
        fail(422, 'missing_prompt', '"prompt" is required.');
    }
    if (mb_strlen($prompt) > $img['max_prompt_length']) {
        fail(422, 'prompt_too_long', 'Prompt exceeds ' . $img['max_prompt_length'] . ' characters.');
    }

    $model = (string)($body['model'] ?? $img['default_model']);
    $mod = $config['moderation'] ?? [];
    $async = filter_var($body['async'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $screenCost = null; // what the screening calls cost, added to this request's history entry
    $screen = null;     // Jev's verdict for this prompt (null when the second opinion did not run)
    $imageScreens = []; // Clef's verdicts for the reference images
    $modActive = !empty($mod['enabled']) && ($tokenCfg['moderation'] ?? true) !== false;
    // Async + a slow check (Jev / Clef) enabled: answer with a job id at once and run ALL checks inside the job, so
    // the client never waits for them and a rejection (which says nothing about the cause) arrives via /jobs/{id}.
    $deferScreen = $async && $modActive && (!empty($mod['second_opinion']['enabled']) || !empty($mod['image_check']['enabled']));
    $screenReject = function (string $code, string $message) use ($deferScreen, $mod): never {
        if ($deferScreen) {
            fail(422, 'request_rejected', (string)($mod['async_message'] ?? 'The request could not be accepted.'));
        }
        fail(422, $code, $message);
    };
    $screenPrompt = function () use (&$screen, &$screenCost, $config, $mod, $modActive, $label, $model, $prompt, $screenReject): void {
    if ($modActive) {
        $hit = moderation_check($mod, $prompt);
        $so = $mod['second_opinion'] ?? [];
        if ($hit === null && !empty($so['enabled'])) { // only paid for when the free keyword check passed
            $s = moderation_second_opinion($config, $so, $prompt);
            $screenCost = $s['cost'];
            $screen = ['model' => (string)($so['model'] ?? 'typesafe/jev-1.13'), 'verdict' => $s['verdict'], 'p' => $s['p'],
                'threshold' => (float)($so['threshold'] ?? 0.5), 'cost' => $s['cost']]
                + ($s['error'] ? ['error' => mb_substr((string)$s['error'], 0, 200)] : []);
            log_line($config, "SCREEN label=$label model=" . ($so['model'] ?? 'typesafe/jev-1.13') . " verdict={$s['verdict']}"
                . ($s['p'] !== null ? ' p=' . round($s['p'], 3) : '') . ($s['error'] ? ' error=' . json_encode($s['error']) : ''));
            if ($s['verdict'] === 'not_safe') {
                $hit = ['rule' => 'second-opinion', 'variant' => 'p=' . round((float)$s['p'], 3)];
            } elseif ($s['verdict'] === 'error' && ($so['on_error'] ?? 'allow') === 'reject') {
                $hit = ['rule' => 'second-opinion-unavailable', 'variant' => 'fail-closed'];
            }
        }
        if ($hit !== null) {
            // The client only learns that it was rejected; the matched rule stays in the operator's log/history.
            log_line($config, "MODERATION label=$label model=$model rule=" . json_encode($hit['rule'], JSON_UNESCAPED_UNICODE)
                . " via={$hit['variant']}");
            history_record($config, [
                'status' => 'rejected',
                'label' => $label,
                'model' => $model,
                'prompt' => $prompt,
                'cost' => $screenCost ?? 0,
                'screening' => $screen,
                'refs' => [],
                'images' => [],
                'error' => ['code' => 'prompt_rejected', 'message' => 'Matched rule ' . $hit['rule'] . ' (' . $hit['variant'] . ')'],
            ]);
            $screenReject('prompt_rejected', (string)($mod['message'] ?? 'The prompt was rejected by the content policy.'));
        }
    }
    };
    if (!$deferScreen) {
        $screenPrompt();
    }
    $allowed = allowed_models($config, $tokenCfg);
    if ($allowed && !in_array($model, $allowed, true)) {
        $why = isset($body['model'])
            ? 'Model not allowed.'
            : 'Default model "' . $model . '" is not allowed for this token; pass "model" explicitly.';
        fail(422, 'model_not_allowed', $why, ['allowed' => $allowed]);
    }

    $n = (int)($body['n'] ?? 1);
    if ($n < 1 || $n > $img['max_images_per_request']) {
        fail(422, 'invalid_n', '"n" must be between 1 and ' . $img['max_images_per_request'] . '.');
    }

    $aspect = $body['aspect_ratio'] ?? $img['default_aspect_ratio'];
    if ($aspect !== null && !in_array($aspect, $img['allowed_aspect_ratios'], true)) {
        fail(422, 'invalid_aspect_ratio', 'Unsupported aspect ratio.', ['allowed' => $img['allowed_aspect_ratios']]);
    }

    $mode = (string)($body['response_mode'] ?? $out['default_mode']);
    if (!in_array($mode, ['url', 'base64', 'both'], true)) {
        fail(422, 'invalid_response_mode', 'response_mode must be url, base64 or both.');
    }

    $localEp = local_endpoint($config, $model);
    $isLocal = $localEp !== null;
    if ($isLocal && (!empty($body['input_images']) || $uploads) && empty($localEp['input_images'])) {
        fail(422, 'invalid_input_images', 'Reference images are not supported by this local model.');
    }

    if ($isLocal && $n > (int)($localEp['max_images_per_request'] ?? $n)) {
        fail(422, 'invalid_n', '"n" must be between 1 and ' . (int)$localEp['max_images_per_request'] . ' for this model.');
    }

    $steps = null;
    if ($isLocal) { // "quality" only applies to local models; OpenRouter models ignore it
        $levels = (array)($localEp['quality'] ?? []);
        $q = (string)($body['quality'] ?? $localEp['default_quality'] ?? '');
        if (isset($body['quality']) && !isset($levels[$q])) {
            fail(422, 'invalid_quality', '"quality" must be one of: ' . implode(', ', array_keys($levels)) . '.', ['allowed' => array_keys($levels)]);
        }
        $steps = isset($levels[$q]) ? (int)$levels[$q] : null;
    }

    // Message content: text plus optional reference images (for edits/variations).
    $content = [['type' => 'text', 'text' => $prompt]];
    $inputs = $body['input_images'] ?? [];
    if (is_string($inputs)) {
        $inputs = [$inputs]; // single value (e.g. one multipart text field)
    }
    if (!is_array($inputs) || !array_is_list($inputs)) {
        fail(422, 'invalid_input_images', '"input_images" must be an array.');
    }
    if ($inputs || $uploads) {
        if (!$img['allow_input_images']) {
            fail(422, 'invalid_input_images', 'Input images are not allowed.');
        }
        if (count($inputs) + count($uploads) > $img['max_input_images']) {
            fail(422, 'invalid_input_images', 'At most ' . $img['max_input_images'] . ' input images are allowed.');
        }
        foreach ($uploads as $u) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $u]];
        }
        foreach ($inputs as $i => $u) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => resolve_input_image($config, $u, $i)]];
        }
    }

    $payload = [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $content]],
        'modalities' => ['image', 'text'],
    ];
    if ($aspect !== null) {
        $payload['image_config'] = ['aspect_ratio' => $aspect];
    }

    // Same request for OpenRouter's /images endpoint, which some image-only models
    // (e.g. OpenAI gpt-image, Meta) require instead of chat/completions.
    $imagesPayload = ['model' => $model, 'prompt' => $prompt];
    if ($aspect !== null) {
        $imagesPayload['aspect_ratio'] = $aspect;
    }
    $refs = array_values(array_filter($content, fn($c) => $c['type'] === 'image_url'));
    if ($isLocal) { // local servers take base64 only, so catch https links before anything is accepted or paid for
        foreach ($refs as $r) {
            if (!str_starts_with((string)$r['image_url']['url'], 'data:image/')) {
                fail(422, 'invalid_input_images', 'Local models accept uploads, base64 or relay images, not URLs.');
            }
        }
    }
    if ($refs) {
        $imagesPayload['input_references'] = $refs;
    }

    // Optional image screening (Clef): every reference image is inspected before anything is generated.
    $ic = $mod['image_check'] ?? [];
    $screenImages = function () use (&$screen, &$screenCost, &$imageScreens, $config, $mod, $ic, $modActive, $label, $model, $prompt, $refs, $screenReject): void {
    if ($refs && $modActive && !empty($ic['enabled'])) {
        $chk = moderation_check_images($config, $ic, array_map(fn($r) => (string)$r['image_url']['url'], $refs));
        $screenCost = ($screenCost ?? 0.0) + $chk['cost'];
        $worst = null; // highest probability among the "not safe" results
        foreach ($chk['entries'] as $e) {
            $imageScreens[] = ['model' => (string)($ic['model'] ?? 'cloudflare/clef-flash'), 'threshold' => (float)($ic['threshold'] ?? 0.5)]
                + array_filter($e, fn($v) => $v !== null);
            $where = isset($e['scope']) ? 'batch=' . implode(',', $e['indexes']) : 'image=' . $e['index'];
            log_line($config, "IMAGE_SCREEN label=$label $where model=" . ($ic['model'] ?? 'cloudflare/clef-flash')
                . " verdict={$e['verdict']}" . ($e['p'] !== null ? ' p=' . round($e['p'], 3) : '')
                . (!empty($e['error']) ? ' error=' . json_encode($e['error']) : ''));
            if ($e['verdict'] === 'not_safe') {
                $worst = max((float)$worst, (float)$e['p']);
            }
        }
        $verdicts = array_column($chk['entries'], 'verdict');
        $imgHit = null;
        if (in_array('not_safe', $verdicts, true)) {
            $imgHit = ['rule' => 'image-check', 'variant' => 'image' . (count($chk['flagged']) > 1 ? 's ' : ' ')
                . implode(',', $chk['flagged']) . ' p=' . round((float)$worst, 3)];
        } elseif (in_array('unchecked', $verdicts, true) && ($ic['url_images'] ?? 'reject') === 'reject') {
            $imgHit = ['rule' => 'image-unchecked', 'variant' => 'a reference is a link, not an upload'];
        } elseif (in_array('error', $verdicts, true) && ($ic['on_error'] ?? 'allow') === 'reject') {
            $imgHit = ['rule' => 'image-check-unavailable', 'variant' => 'fail-closed'];
        }
        if ($imgHit) {
            log_line($config, "MODERATION label=$label model=$model rule=" . json_encode($imgHit['rule']) . " via={$imgHit['variant']}");
            // Evidence: keep the flagged image(s) encrypted and out of sight (see quarantine_* in moderation.php).
            $quarantined = [];
            if ($chk['flagged'] && ($ic['quarantine']['enabled'] ?? true)) {
                foreach ($chk['flagged'] as $fi) {
                    $q = quarantine_store_data_url($config, (string)$refs[$fi - 1]['image_url']['url'], [
                        'label' => $label, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'model' => $model,
                        'image_index' => $fi, 'prompt' => mb_substr($prompt, 0, 500),
                    ]);
                    if ($q !== null) {
                        $quarantined[] = ['index' => $fi] + $q;
                        log_line($config, "QUARANTINE label=$label id={$q['id']} sha256={$q['sha256']} bytes={$q['bytes']}");
                    } else {
                        log_line($config, "QUARANTINE_FAIL label=$label image=$fi (image not kept)");
                    }
                }
            }
            history_record($config, [
                'status' => 'rejected',
                'label' => $label,
                'model' => $model,
                'prompt' => $prompt,
                'cost' => $screenCost ?? 0,
                'screening' => $screen,
                'screening_images' => $imageScreens,
                // Never displayed in /history: the image is withheld and only its quarantine id is shown.
                'quarantined' => $quarantined,
                'refs' => [],
                'images' => [],
                'error' => ['code' => 'image_rejected', 'message' => 'Matched rule ' . $imgHit['rule'] . ' (' . $imgHit['variant'] . ')'],
            ]);
            $screenReject('image_rejected', (string)($ic['message'] ?? 'A reference image was rejected by the content policy.'));
        }
    }
    };
    if (!$deferScreen) {
        $screenImages();
    }

    // Check storage before paying for generation, so a misconfigured server fails for free.
    $dir = $out['storage_dir'];
    if ($mode !== 'base64') {
        if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) || !is_writable($dir)) {
            log_line($config, "STORAGE_ERROR dir=$dir");
            fail(500, 'storage_error', 'Image storage directory is not writable. Fix permissions, or use response_mode "base64".');
        }
        @file_put_contents(dirname($dir) . '/.htaccess', "Require all denied\n");
        cleanup_old_images($config);
    }

    pending_start($config, [
        'label' => $label,
        'model' => $model,
        'prompt' => $prompt,
        'aspect_ratio' => $aspect,
        'n' => $n,
        'mode' => $async ? 'async' : 'sync',
        'phase' => $deferScreen ? 'screening' : 'rendering',
        'screening' => $screen,
        'screening_images' => $imageScreens,
        // Reference images are only copied after screening has passed, so a rejected image is never stored.
        'refs' => $deferScreen ? [] : array_values(array_filter(array_map(
            fn($c) => $c['type'] === 'image_url' ? history_ref($config, (string)$c['image_url']['url']) : null,
            $content))),
    ]);

    // Async: answer now with a job id, then keep generating after the connection closes.
    if ($async) {
        $jobsDir = jobs_dir($config);
        if (!is_dir($jobsDir) && !@mkdir($jobsDir, 0775, true) && !is_dir($jobsDir)) {
            fail(500, 'storage_error', 'Job storage directory is not writable.');
        }
        cleanup_old_jobs($config);
        $jobId = bin2hex(random_bytes(16));
        $jobFile = $jobsDir . '/' . $jobId . '.json';
        $created = time();
        $jobMax = max_job_seconds($config, $n, $localEp) + ($deferScreen ? 180 : 0);
        $wait = check_back_seconds($config, $localEp);
        job_write($jobFile, ['label' => $label, 'created' => $created, 'status' => $deferScreen ? 'screening' : 'running']
            + ($isLocal ? ['max_seconds' => $jobMax, 'check_back' => $wait] : []));
        log_line($config, "ACCEPT label=$label model=$model mode=async job=$jobId took=" . round(microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 2) . 's');
        detach_with_response(202, [
            'ok' => true,
            'job_id' => $jobId,
            'status' => $deferScreen ? 'screening' : 'running',
            'check_back_seconds' => $wait,
            'poll_url' => base_url($config) . '/jobs/' . $jobId,
            'note' => ($deferScreen ? 'The request is being checked.' : 'Generation is running.')
                . " Check back in $wait seconds: GET /jobs/$jobId with the same Authorization header.",
        ], $wait);
        $GLOBALS['relay_job_ctx'] = ['file' => $jobFile, 'label' => $label, 'created' => $created];
        set_time_limit(max_job_seconds($config, $n, $localEp) + 60 + ($deferScreen ? 180 : 0)); // + time for the screening calls
        register_shutdown_function(function () use ($config): void {
            if (isset($GLOBALS['relay_job_ctx'])) { // fatal error / time limit before a result was stored
                log_line($config, 'JOB_ABORT label=' . $GLOBALS['relay_job_ctx']['label']);
                job_finish(500, ['ok' => false, 'error' => ['code' => 'server_error',
                    'message' => 'The job crashed or timed out on the server.']]);
            }
        });
        if ($deferScreen) {
            $screenPrompt();
            $screenImages();
            // Both passed: the request is accepted and from here on it is an ordinary render.
            job_write($jobFile, ['label' => $label, 'created' => $created, 'status' => 'running']
                + ($isLocal ? ['max_seconds' => $jobMax, 'check_back' => $wait] : []));
            pending_phase('rendering', array_values(array_filter(array_map(
                fn($c) => $c['type'] === 'image_url' ? history_ref($config, (string)$c['image_url']['url']) : null,
                $content))));
        }
    }

    // Most models return one image per call; issue n calls to satisfy "n".
    $results = [];
    $texts = [];
    $refusals = [];
    $usage = [];
    // OpenRouter answers 404 (not billed) when a model can't serve the request as sent; adapt and
    // retry, and keep the adjusted route for the remaining images:
    //  - image-only models (e.g. grok-imagine) reject modalities [image, text] -> retry with [image]
    //  - some models (e.g. OpenAI gpt-image, Meta) only work on the /images endpoint -> switch to it
    $useImagesApi = false;
    $lc = $localEp ?? [];
    $localPayload = ['model' => $model, 'prompt' => $prompt, 'n' => 1, 'response_format' => 'b64_json',
        'size' => ($aspect !== null ? ($lc['sizes'][$aspect] ?? null) : null) ?? ($lc['default_size'] ?? '1024x1024')];
    if ($steps !== null) {
        $localPayload['steps'] = $steps;
    }
    // Reference images (endpoints with 'input_images' => true): raw base64 as "image" (one) or "images" (several).
    $localRefs = [];
    if ($isLocal) {
        foreach ($content as $c) {
            if ($c['type'] !== 'image_url') {
                continue;
            }
            if (preg_match('#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', (string)$c['image_url']['url'], $m)) {
                $localRefs[] = $m[1];
            }
        }
        // With references the server sizes the output from them; only force a size when the client asked for an aspect ratio.
        if ($localRefs && $aspect === null && ($localEp['omit_size_with_refs'] ?? true)) {
            unset($localPayload['size']);
        }
        if (count($localRefs) === 1) {
            $localPayload['image'] = $localRefs[0];
        } elseif ($localRefs) {
            $localPayload['images'] = $localRefs;
        }
    }
    $histImages = [];
    // Records this request in the history (success or failure); cost is whatever OpenRouter billed so far.
    $recordHistory = function (string $outcome, ?array $error = null) use ($config, $label, $model, $prompt, $aspect, $n, $content, &$usage, &$histImages, $async, $screenCost, $screen, $imageScreens): void {
        $costs = array_filter(array_column($usage, 'cost'), 'is_numeric');
        if ($screenCost !== null) {
            $costs[] = $screenCost; // the screening call is billed too
        }
        history_record($config, [
            'status' => $outcome,
            'label' => $label,
            'model' => $model,
            'prompt' => $prompt,
            'aspect_ratio' => $aspect,
            'n' => $n,
            'mode' => $async ? 'async' : 'sync',
            'cost' => $costs ? array_sum($costs) : null,
            'screening' => $screen,
            'screening_images' => $imageScreens,
            'refs' => array_values(array_filter(array_map(
                fn($c) => $c['type'] === 'image_url' ? history_ref($config, (string)$c['image_url']['url']) : null,
                $content))),
            'images' => $histImages,
        ] + ($error ? ['error' => $error] : []));
    };
    $maxRetries = $isLocal ? local_retries($localEp) : upstream_retries($config);
    for ($i = 0; $i < $n; $i++) {
        $retries = 0;
        $busyWaited = 0;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            [$status, $json, $raw] = $isLocal
                ? local_images_post($localEp, $localPayload)
                : ($useImagesApi
                    ? openrouter_post($config, '/images', $imagesPayload)
                    : openrouter_post($config, '/chat/completions', $payload));
            // A busy local server (HTTP 429; e.g. the ComfyUI middleware renders one job at a time): wait the pause it
            // asks for and resubmit, up to busy_wait_seconds in total. Not a retry: it does not use up 'retries'.
            if ($isLocal && $status === 429 && $busyWaited < local_busy_wait($localEp)) {
                $ask = is_array($json) ? (int)($json['retry_after'] ?? 0) : 0;
                $pause = (int)min(max($ask > 0 ? $ask : 30, 5), 120, max(1, local_busy_wait($localEp) - $busyWaited));
                log_line($config, "LOCAL_BUSY label=$label model=$model waited=$busyWaited/" . local_busy_wait($localEp) . "s pause={$pause}s");
                sleep($pause);
                $busyWaited += $pause;
                $attempt--;
                continue;
            }
            // Timeouts (status 0) and transient upstream errors: try again before giving up.
            if (in_array($status, [0, 408, 500, 502, 503, 504, 520, 522, 524, 529], true) && $retries < $maxRetries) {
                $retries++;
                log_line($config, "UPSTREAM_RETRY label=$label model=$model status=$status retry=$retries/$maxRetries body="
                    . substr((string)$raw, 0, 200));
                sleep(3 * $retries);
                $attempt--; // a retry must not use up the 404-adaptation attempts
                continue;
            }
            if ($status !== 404 || $useImagesApi || $isLocal) {
                break;
            }
            $why = (string)($json['error']['message'] ?? '');
            if (stripos($why, '/images') !== false || stripos($why, 'images endpoint') !== false) {
                $useImagesApi = true;
            } elseif ($payload['modalities'] === ['image', 'text']) {
                $payload['modalities'] = ['image'];
            } else {
                break;
            }
        }
        if ($status !== 200 || !is_array($json)) {
            log_line($config, "UPSTREAM label=$label model=$model mode=" . ($async ? 'async' : 'sync') . " status=$status body=" . substr((string)$raw, 0, 500));
            // OpenRouter's own explanation (e.g. a content refusal) is for the operator only: log + history, never the client.
            $extra = [];
            $why = $json['error']['message'] ?? null;
            $upstreamMsg = is_string($why) && $why !== '' ? mb_substr($why, 0, 300) : null;
            if ($results) { // return partial success rather than lose paid-for images
                break;
            }
            if ($isLocal && $status === 429) { // still busy after waiting: tell the client to try again later
                $recordHistory('failed', ['code' => 'upstream_busy', 'http_status' => 429,
                    'message' => 'The local image server stayed busy for ' . $busyWaited . ' s.']);
                fail(503, 'upstream_busy', 'The local image server is busy with another job. Try again later.', ['retryable' => true]);
            }
            $extra['retryable'] = $status === 0 || $status >= 500;
            $recordHistory('failed', ['code' => 'upstream_error', 'http_status' => $status,
                'message' => $upstreamMsg ?? ($status === 0 ? 'timed out' : 'HTTP ' . $status)]);
            fail($status === 429 ? 429 : 502, 'upstream_error', $status === 0
                ? ($isLocal
                    ? (stripos((string)$raw, 'timed out') !== false || stripos((string)$raw, 'timeout') !== false
                        ? 'The local image server did not respond in time (timed out after ' . ($maxRetries + 1) . ' attempt(s)).'
                        : 'The local image server dropped the connection or could not be reached; try again later.')
                    : 'OpenRouter did not respond in time (timed out after ' . ($maxRetries + 1) . ' attempt(s)).')
                : ($isLocal ? 'The local image server' : 'OpenRouter') . ' request failed (HTTP ' . $status . ').', $extra);
        }
        if ($useImagesApi || $isLocal) {
            foreach ($json['data'] ?? [] as $d) {
                if (!empty($d['b64_json'])) {
                    $results[] = 'data:' . ($d['media_type'] ?? 'image/png') . ';base64,' . $d['b64_json'];
                } elseif (!empty($d['url'])) {
                    $results[] = $d['url'];
                }
            }
            if (isset($json['usage'])) {
                $usage[] = $json['usage'];
            }
            continue;
        }
        $msg = $json['choices'][0]['message'] ?? [];
        if (!empty($msg['refusal']) && is_string($msg['refusal'])) {
            $refusals[] = $msg['refusal'];
        }
        if (($json['choices'][0]['finish_reason'] ?? '') === 'content_filter') {
            $refusals[] = '(finish_reason: content_filter)';
        }
        if (!empty($msg['content']) && is_string($msg['content'])) {
            $texts[] = $msg['content'];
        }
        foreach ($msg['images'] ?? [] as $im) {
            $url = $im['image_url']['url'] ?? null;
            if ($url) {
                $results[] = $url;
            }
        }
        if (isset($json['usage'])) {
            $usage[] = $json['usage'];
        }
    }

    if (!$results) {
        // Upstream refusals / text-only answers are for the operator only; the client just learns there was no image.
        $modelText = implode("\n", array_merge($refusals, $texts));
        log_line($config, "NO_IMAGE label=$label model=$model mode=" . ($async ? 'async' : 'sync')
            . ($refusals ? ' upstream_refusal=1' : '') . ' text=' . json_encode(
            mb_substr($modelText, 0, 300), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $recordHistory('failed', ['code' => 'no_image', 'message' => $refusals || $texts
                ? 'Upstream refused or returned text only; the client was told "no image".' : 'The model returned no image.',
            'upstream_refusal' => (bool)($refusals || $texts),
            'model_text' => mb_substr($modelText, 0, 2000)]);
        fail(502, 'no_image', 'The model returned no image.');
    }


    $images = [];
    foreach ($results as $dataUrl) {
        $item = [];
        if (preg_match('#^data:image/([a-z0-9.+-]+);base64,(.+)$#is', $dataUrl, $m)) {
            $ext = match (strtolower($m[1])) {
                'jpeg', 'jpg' => 'jpg', 'webp' => 'webp', 'gif' => 'gif', default => 'png',
            };
            $bin = base64_decode($m[2], true);
            if ($bin === false) {
                continue;
            }
            $item['mime_type'] = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
            if ($blob = history_enabled($config) ? history_store_blob($config, $bin, $ext) : null) {
                $histImages[] = ['blob' => $blob];
            }
            if ($mode !== 'url') {
                $item['base64'] = $m[2];
            }
            if ($mode !== 'base64') {
                $name = bin2hex(random_bytes(16)) . '.' . $ext;
                if (file_put_contents($dir . '/' . $name, $bin) === strlen($bin)) {
                    $item['file'] = $name;
                    $item['url'] = base_url($config) . '/images/' . $name;
                } else {
                    log_line($config, "STORE_FAIL label=$label file=$name");
                    if ($mode === 'url') { // no link and no inline data: fall back to base64
                        $item['base64'] = $m[2];
                    }
                    $item['storage_error'] = 'Image could not be saved; returned inline instead.';
                }
            }
        } else {
            $item['url'] = $dataUrl; // upstream returned a hosted URL
            $histImages[] = ['url' => $dataUrl];
        }
        $images[] = $item;
    }

    log_line($config, "OK label=$label model=$model images=" . count($images) . ' mode=' . ($async ? 'async' : 'sync')
        . ' took=' . round(microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 1) . 's');
    $recordHistory('ok');
    respond(200, [
        'ok' => true,
        'model' => $model,
        'images' => $images,
        'text' => implode("\n", $texts),
        'usage' => $usage,
        'note' => $mode === 'base64' ? null : 'Image URLs require the same Authorization header and expire after '
            . $out['retention_hours'] . 'h.',
    ]);
}

// ---- GET /audio/{file}
if ($method === 'GET' && preg_match('#^/audio/([a-f0-9]{32}\.mp3)$#', $path, $m)) {
    $file = ($config['output']['audio_dir'] ?? '') . '/' . $m[1];
    if (!is_file($file)) {
        fail(404, 'not_found', 'Audio not found or expired.');
    }
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

// ---- POST /speech
if ($path === '/speech' && $method === 'POST') {
    $sp = $config['speech'] ?? [];
    if (empty($sp['enabled'])) {
        fail(404, 'not_found', 'Unknown endpoint.');
    }
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        fail(400, 'invalid_json', 'Request body must be a JSON object.');
    }
    foreach (['input', 'model', 'voice', 'response_mode'] as $field) {
        if (isset($body[$field]) && !is_string($body[$field])) {
            fail(422, 'invalid_' . $field, '"' . $field . '" must be a string.');
        }
    }
    $input = trim((string)($body['input'] ?? $body['text'] ?? ''));
    if ($input === '') {
        fail(422, 'missing_input', '"input" is required.');
    }
    $model = (string)($body['model'] ?? $sp['default_model']);
    $voice = trim((string)($body['voice'] ?? $sp['default_voice']));
    if ($voice === '' || !preg_match('/^[\w .-]{1,64}$/u', $voice)) {
        fail(422, 'invalid_voice', '"voice" must be a voice name or ID.');
    }
    $mode = (string)($body['response_mode'] ?? $config['output']['default_mode']);
    if (!in_array($mode, ['url', 'base64', 'both'], true)) {
        fail(422, 'invalid_response_mode', '"response_mode" must be url, base64 or both.');
    }
    $limits = $sp['max_input_length'] ?? [];
    $maxLen = (int)($limits[$model] ?? $limits['default'] ?? 5000);
    if (mb_strlen($input) > $maxLen) {
        fail(422, 'input_too_long', "Input exceeds $maxLen characters for $model.");
    }
    $allowed = array_values(array_filter(
        allowed_models(['images' => ['allowed_models' => $sp['allowed_models'] ?? []]], $tokenCfg)
    ));
    if ($allowed && !in_array($model, $allowed, true)) {
        fail(403, 'model_not_allowed', 'This model is not available to this token.');
    }

    $histBase = ['kind' => 'speech', 'label' => $label, 'model' => $model, 'voice' => $voice, 'prompt' => $input,
        'chars' => mb_strlen($input), 'cost' => null];
    $mod = $config['moderation'] ?? [];
    if (!empty($mod['enabled']) && !empty($sp['moderation']) && ($tokenCfg['moderation'] ?? true) !== false) {
        $hit = moderation_check($mod, $input);
        if ($hit !== null) {
            log_line($config, "MODERATION label=$label model=$model kind=speech rule=" . json_encode($hit['rule'], JSON_UNESCAPED_UNICODE) . " via={$hit['variant']}");
            history_record($config, ['status' => 'rejected', 'mode' => 'sync', 'audio' => [],
                'error' => ['code' => 'prompt_rejected', 'message' => 'Matched rule ' . $hit['rule'] . ' (' . $hit['variant'] . ')']] + $histBase);
            fail(422, 'prompt_rejected', (string)($mod['message'] ?? 'The prompt was rejected by the content policy.'));
        }
    }

    $payload = ['model' => $model, 'input' => $input, 'voice' => $voice, 'response_format' => 'mp3'];
    if (isset($body['speed'])) {
        if (!is_numeric($body['speed'])) {
            fail(422, 'invalid_speed', '"speed" must be a number.');
        }
        $payload['speed'] = (float)$body['speed']; // v4 models reject non-default values
    }
    if (isset($body['elevenlabs'])) {
        if (!is_array($body['elevenlabs'])) {
            fail(422, 'invalid_elevenlabs', '"elevenlabs" must be an object.');
        }
        $opts = array_intersect_key($body['elevenlabs'], array_flip($sp['allowed_elevenlabs_options'] ?? []));
        if ($opts) {
            $payload['provider'] = ['options' => ['elevenlabs' => $opts]];
        }
    }

    // Speech can take longer than a client wants to hold a connection open: async requests get their own, longer timeout.
    $async = filter_var($body['async'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $upConfig = $config;
    $upConfig['openrouter']['timeout'] = (int)($async ? ($sp['async_timeout'] ?? 900) : ($sp['timeout'] ?? 120));
    $histBase['mode'] = $async ? 'async' : 'sync';
    if ($async) {
        $jobsDir = jobs_dir($config);
        if (!is_dir($jobsDir) && !@mkdir($jobsDir, 0775, true) && !is_dir($jobsDir)) {
            fail(500, 'storage_error', 'Job storage directory is not writable.');
        }
        cleanup_old_jobs($config);
        $jobId = bin2hex(random_bytes(16));
        $jobFile = $jobsDir . '/' . $jobId . '.json';
        $created = time();
        $maxSeconds = max_job_seconds($upConfig, 1);
        job_write($jobFile, ['label' => $label, 'created' => $created, 'status' => 'running', 'max_seconds' => $maxSeconds]);
        log_line($config, "ACCEPT label=$label model=$model kind=speech mode=async job=$jobId");
        $wait = check_back_seconds($config);
        detach_with_response(202, [
            'ok' => true,
            'job_id' => $jobId,
            'status' => 'running',
            'check_back_seconds' => $wait,
            'poll_url' => base_url($config) . '/jobs/' . $jobId,
            'note' => "Speech is being generated. Check back in $wait seconds: GET /jobs/$jobId with the same Authorization header.",
        ], $wait);
        $GLOBALS['relay_job_ctx'] = ['file' => $jobFile, 'label' => $label, 'created' => $created];
        set_time_limit($maxSeconds + 60);
        register_shutdown_function(function () use ($config): void {
            if (isset($GLOBALS['relay_job_ctx'])) { // fatal error / time limit before a result was stored
                log_line($config, 'JOB_ABORT label=' . $GLOBALS['relay_job_ctx']['label']);
                job_finish(500, ['ok' => false, 'error' => ['code' => 'server_error',
                    'message' => 'The job crashed or timed out on the server.']]);
            }
        });
    } else {
        set_time_limit(max_job_seconds($upConfig, 1) + 30);
    }

    pending_start($config, ['label' => $label, 'model' => $model, 'prompt' => $input, 'mode' => $histBase['mode'],
        'phase' => 'rendering', 'kind' => 'speech', 'voice' => $voice]);

    $maxRetries = upstream_retries($config);
    $retries = 0;
    while (true) {
        [$status, $raw, $ctype, $err] = openrouter_post_binary($upConfig, '/audio/speech', $payload);
        if (in_array($status, [0, 408, 500, 502, 503, 504, 520, 522, 524, 529], true) && $retries < $maxRetries) {
            $retries++;
            log_line($config, "UPSTREAM_RETRY label=$label model=$model kind=speech status=$status retry=$retries/$maxRetries");
            sleep(3 * $retries);
            continue;
        }
        break;
    }
    if ($status !== 200 || $raw === '' || str_contains($ctype, 'json')) {
        $j = json_decode($raw, true);
        $why = is_array($j) ? ($j['error']['message'] ?? '') : '';
        log_line($config, "UPSTREAM label=$label model=$model kind=speech status=$status body=" . substr($raw !== '' ? $raw : $err, 0, 500));
        $extra = ['retryable' => $status === 0 || $status >= 500];
        history_record($config, ['status' => 'failed', 'audio' => [], 'error' => ['code' => 'upstream_error', 'http_status' => $status,
            'message' => is_string($why) && $why !== '' ? mb_substr($why, 0, 300) : ($status === 0 ? 'timed out' : 'HTTP ' . $status)]] + $histBase);
        if (in_array($status, [400, 422], true) && is_string($why) && $why !== '') {
            $extra['detail'] = mb_substr($why, 0, 300); // bad voice / option: safe and useful to the caller
        }
        fail($status === 429 ? 429 : 502, 'upstream_error', $status === 0
            ? 'OpenRouter did not respond in time (timed out after ' . ($retries + 1) . ' attempt(s)).' : 'OpenRouter request failed (HTTP ' . $status . ').', $extra);
    }

    $blob = history_enabled($config) ? history_store_blob($config, $raw, 'mp3') : null;
    history_record($config, ['status' => 'ok', 'audio' => $blob ? [['blob' => $blob, 'bytes' => strlen($raw)]] : []] + $histBase);
    $item = ['mime_type' => 'audio/mpeg', 'bytes' => strlen($raw)];
    if ($mode !== 'url') {
        $item['base64'] = base64_encode($raw);
    }
    if ($mode !== 'base64') {
        $dir = $config['output']['audio_dir'];
        @mkdir($dir, 0775, true);
        cleanup_old_audio($config);
        $name = bin2hex(random_bytes(16)) . '.mp3';
        if (file_put_contents($dir . '/' . $name, $raw) === strlen($raw)) {
            $item['file'] = $name;
            $item['url'] = base_url($config) . '/audio/' . $name;
        } else {
            log_line($config, "STORE_FAIL label=$label file=$name");
            $item['base64'] ??= base64_encode($raw);
            $item['storage_error'] = 'Audio could not be saved; returned inline instead.';
        }
    }
    log_line($config, "OK label=$label model=$model kind=speech mode=" . ($async ? 'async' : 'sync') . ' chars=' . mb_strlen($input) . ' bytes=' . strlen($raw)
        . ' took=' . round(microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 1) . 's');
    respond(200, [
        'ok' => true,
        'model' => $model,
        'voice' => $voice,
        'audio' => $item,
        'note' => $mode === 'base64' ? null : 'Audio URLs require the same Authorization header and expire after '
            . $config['output']['retention_hours'] . 'h.',
    ]);
}

fail(404, 'not_found', 'Unknown endpoint.');
