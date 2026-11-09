<?php
declare(strict_types=1);

/**
 * Prompt content filter (sexual / explicit content). Configured under "moderation" in config.php.
 * Library only: loaded by index.php and bin/check-prompt.php.
 */
if (!defined('RELAY_APP') && PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Lowercases and undoes common obfuscation: zero-width chars, accents, full-width and look-alike letters. */
function mod_normalize(string $s, array $opt): string
{
    if (!preg_match('//u', $s)) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8'); // drop invalid byte sequences
    }
    $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}\x{180E}]/u', '', $s) ?? $s;
    if (!empty($opt['homoglyphs'])) {
        if (class_exists('Normalizer')) {
            $s = Normalizer::normalize($s, Normalizer::FORM_KD) ?: $s; // full-width, ligatures, accents -> base chars
        }
        // Full-width ASCII (U+FF01-FF5E) without needing the intl extension.
        $s = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', fn($m) => chr(mb_ord($m[0]) - 0xFEE0), $s) ?? $s;
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s; // combining accents / strike-through / underline marks
        $s = strtr($s, mod_accent_map()); // precomposed accents (works without the intl extension)
        $s = strtr($s, [
            // Cyrillic
            'а' => 'a', 'в' => 'b', 'с' => 'c', 'е' => 'e', 'ё' => 'e', 'н' => 'h', 'і' => 'i', 'ї' => 'i', 'ј' => 'j',
            'к' => 'k', 'м' => 'm', 'о' => 'o', 'р' => 'p', 'ѕ' => 's', 'т' => 't', 'у' => 'y', 'х' => 'x',
            'А' => 'a', 'В' => 'b', 'С' => 'c', 'Е' => 'e', 'Н' => 'h', 'І' => 'i', 'К' => 'k', 'М' => 'm',
            'О' => 'o', 'Р' => 'p', 'Ѕ' => 's', 'Т' => 't', 'Х' => 'x', 'У' => 'y',
            // Greek
            'ο' => 'o', 'α' => 'a', 'ε' => 'e', 'ι' => 'i', 'ν' => 'v', 'ρ' => 'p', 'τ' => 't', 'υ' => 'u', 'κ' => 'k',
            'Ο' => 'o', 'Α' => 'a', 'Ε' => 'e', 'Ι' => 'i', 'Ν' => 'n', 'Ρ' => 'p', 'Τ' => 't', 'Χ' => 'x', 'Β' => 'b',
            'ı' => 'i', 'ß' => 'ss',
        ]);
    }
    return mb_strtolower($s);
}

/** Precomposed Latin accents -> base letters, so the filter does not depend on the intl extension. */
function mod_accent_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach ([
            'a' => 'àáâãäåāăąǎ', 'c' => 'çćĉċč', 'd' => 'ďđ', 'e' => 'èéêëēĕėęě', 'g' => 'ĝğġģ', 'h' => 'ĥħ',
            'i' => 'ìíîïĩīĭįǐ', 'j' => 'ĵ', 'k' => 'ķ', 'l' => 'ĺļľŀł', 'n' => 'ñńņňŉ', 'o' => 'òóôõöøōŏőǒ',
            'r' => 'ŕŗř', 's' => 'śŝşš', 't' => 'ţťŧ', 'u' => 'ùúûüũūŭůűųǔ', 'w' => 'ŵ', 'y' => 'ýÿŷ', 'z' => 'źżž',
        ] as $base => $chars) {
            foreach (mb_str_split($chars) as $ch) {
                $map[$ch] = $base;
                $map[mb_strtoupper($ch)] = $base;
            }
        }
        $map['æ'] = 'ae';
        $map['œ'] = 'oe';
    }
    return $map;
}

/** Leetspeak decoding. "1" is ambiguous (i or l) so two variants come back. */
function mod_leet(string $s): array
{
    $map = ['0' => 'o', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '+' => 't',
        '(' => 'c', '€' => 'e', '£' => 'e', '¡' => 'i'];
    $s = strtr($s, $map);
    return [strtr($s, ['1' => 'i', '!' => 'i', '|' => 'i']), strtr($s, ['1' => 'l', '!' => 'i', '|' => 'l'])];
}

/** Non-alphanumerics -> spaces, and runs of single letters ("p o r n", "p.o.r.n") joined into one word. */
function mod_words(string $s, bool $joinSeparated, bool $splitEdgeArticles = false): string
{
    $s = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s);
    if (!$joinSeparated) {
        return $s;
    }
    // A run of single letters becomes one word. With $splitEdgeArticles a leading/trailing "a" or "i" is kept
    // apart, so "a n u d e woman" yields "a nude woman" instead of the unrecognisable "anude woman".
    $flush = function (string $run) use ($splitEdgeArticles): array {
        if (mb_strlen($run) < 3) {
            return mb_str_split($run);
        }
        $pre = $post = [];
        if ($splitEdgeArticles) {
            if (mb_strlen($run) > 3 && in_array(mb_substr($run, 0, 1), ['a', 'i'], true)) {
                $pre[] = mb_substr($run, 0, 1);
                $run = mb_substr($run, 1);
            }
            if (mb_strlen($run) > 3 && in_array(mb_substr($run, -1), ['a', 'i'], true)) {
                $post[] = mb_substr($run, -1);
                $run = mb_substr($run, 0, -1);
            }
        }
        return array_merge($pre, [$run], $post);
    };
    $out = [];
    $run = '';
    foreach (explode(' ', $s) as $tok) {
        if (mb_strlen($tok) === 1 && preg_match('/^\p{L}$/u', $tok)) {
            $run .= $tok;
            continue;
        }
        if ($run !== '') {
            array_push($out, ...$flush($run));
            $run = '';
        }
        $out[] = $tok;
    }
    if ($run !== '') {
        array_push($out, ...$flush($run));
    }
    return implode(' ', $out);
}

function mod_keyword_regex(string $kw, array $opt): ?string
{
    $kw = trim(mb_strtolower($kw));
    if ($kw === '') {
        return null;
    }
    $parts = [];
    foreach (preg_split('/\s+/u', $kw) as $word) {
        $letters = '';
        foreach (mb_str_split($word) as $ch) {
            $q = preg_quote($ch, '/');
            $letters .= !empty($opt['elongation']) && preg_match('/^\p{L}$/u', $ch) ? $q . '+' : $q; // "pooorn"
        }
        $parts[] = $letters;
    }
    $suffix = !empty($opt['suffixes']) ? '(?:s|es|ed|ing|er|ers)?' : '';
    return '/(?<![\p{L}\p{N}])' . implode('\s+', $parts) . $suffix . '(?![\p{L}\p{N}])/u';
}

/**
 * Checks a prompt. Returns null when it is fine, or ['rule' => ..., 'variant' => ...] for the first hit.
 * The caller decides what to tell the client; the rule is for the operator's log only.
 */
function moderation_check(array $cfg, string $prompt): ?array
{
    $opt = $cfg['normalize'] ?? [];
    $opt += ['leet' => true, 'homoglyphs' => true, 'separators' => true, 'elongation' => true, 'suffixes' => true];

    $allow = array_filter(array_map(fn($a) => mb_strtolower(trim((string)$a)), (array)($cfg['allow'] ?? [])));
    $strip = function (string $t) use ($allow): string {
        foreach ($allow as $a) { // harmless phrases such as "breast cancer" are removed before matching
            $t = str_replace($a, ' ', $t);
        }
        return $t;
    };

    $base = mod_normalize($prompt, $opt);
    $texts = ['plain' => $base];
    if (!empty($opt['leet'])) {
        [$texts['leet-i'], $texts['leet-l']] = mod_leet($base);
    }

    $words = [];
    foreach ($texts as $name => $t) {
        $words[$name] = mod_words($strip($t), !empty($opt['separators']));
        if (!empty($opt['separators'])) {
            $words[$name . '+edge'] = mod_words($strip($t), true, true);
        }
    }

    foreach ((array)($cfg['keywords'] ?? []) as $kw) {
        $re = mod_keyword_regex((string)$kw, $opt);
        if ($re === null) {
            continue;
        }
        foreach ($words as $name => $w) {
            if (preg_match($re, $w) === 1) {
                return ['rule' => 'keyword:' . $kw, 'variant' => $name];
            }
        }
    }

    // Strict keywords: substring of the text with everything but letters/digits removed ("p-o-r-n", "po rn").
    // Catches heavy spacing tricks but will also hit inside longer words, so keep this list short.
    foreach ((array)($cfg['strict_keywords'] ?? []) as $kw) {
        $needle = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string)$kw)) ?? '';
        if ($needle === '') {
            continue;
        }
        foreach ($texts as $name => $t) {
            $squashed = preg_replace('/[^\p{L}\p{N}]+/u', '', $strip($t)) ?? '';
            if (str_contains($squashed, $needle)) {
                return ['rule' => 'strict:' . $kw, 'variant' => $name . '+squashed'];
            }
        }
    }

    // Custom regexes run on the raw prompt and on every normalised form.
    $targets = ['original' => $strip($prompt)] + array_map($strip, $texts);
    foreach ($words as $name => $w) {
        $targets[$name . '+words'] = $w;
    }
    foreach ((array)($cfg['regex'] ?? []) as $re) {
        $re = (string)$re;
        if (@preg_match($re, '') === false) {
            continue; // invalid pattern: reported by moderation_validate(), never fatal at request time
        }
        foreach ($targets as $name => $t) {
            if (preg_match($re, $t) === 1) {
                return ['rule' => 'regex:' . $re, 'variant' => $name];
            }
        }
    }
    return null;
}

/** Returns human-readable problems in the moderation config (bad regexes, empty bank). */
function moderation_validate(array $cfg): array
{
    $problems = [];
    foreach ((array)($cfg['regex'] ?? []) as $re) {
        if (@preg_match((string)$re, '') === false) {
            $problems[] = 'invalid regex: ' . $re;
        }
    }
    if (!($cfg['keywords'] ?? []) && !($cfg['strict_keywords'] ?? []) && !($cfg['regex'] ?? [])
        && empty($cfg['second_opinion']['enabled'])) {
        $problems[] = 'moderation is enabled but no keywords or regexes are configured';
    }
    return $problems;
}

/**
 * Optional second opinion from a Jev decision model (OpenRouter Decisions API, POST /api/alpha/decisions).
 * Jev answers a yes/no ("noul") question with the probability of yes; at or above the threshold the prompt is "not safe".
 * Returns ['verdict' => 'safe'|'not_safe'|'error', 'p' => ?float, 'cost' => ?float, 'error' => ?string].
 */
function moderation_second_opinion(array $config, array $so, string $prompt): array
{
    $or = $config['openrouter'];
    $instructions = (string)($so['question'] ?? 'The text in `prompt` asks for an image containing sexual or sexually explicit '
        . 'content: nudity, sexual acts, erotica, fetish material, or sexualised depictions of anyone.');
    $payload = [
        'model' => (string)($so['model'] ?? 'typesafe/jev-1.13'),
        'state' => ['prompt' => $prompt],
        'questions' => ['explicit' => ['type' => 'noul', 'instructions' => $instructions]],
    ];
    $url = (string)($so['url'] ?? (preg_replace('#/v1/?$#', '', rtrim((string)$or['base_url'], '/')) . '/alpha/decisions'));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $or['api_key'], 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(1, (int)($so['timeout'] ?? 10)),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => (bool)($or['verify_ssl'] ?? true),
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;
    $p = $json['answers']['explicit']['noul'] ?? null;
    $cost = is_numeric($json['usage']['cost'] ?? null) ? (float)$json['usage']['cost'] : null;
    if ($status !== 200 || !is_numeric($p) || $p < 0 || $p > 1) {
        return ['verdict' => 'error', 'p' => null, 'cost' => $cost,
            'error' => $err !== '' ? $err : 'HTTP ' . $status . ' ' . substr((string)$raw, 0, 200)];
    }
    $threshold = (float)($so['threshold'] ?? 0.5);
    return ['verdict' => $p >= $threshold ? 'not_safe' : 'safe', 'p' => (float)$p, 'cost' => $cost, 'error' => null];
}

/** Sniffs PNG / JPEG / GIF / WebP from the file signature. */
function mod_image_mime(string $bin): ?string
{
    return match (true) {
        str_starts_with($bin, "\x89PNG\r\n\x1a\n") => 'image/png',
        str_starts_with($bin, "\xFF\xD8\xFF") => 'image/jpeg',
        str_starts_with($bin, 'GIF87a'), str_starts_with($bin, 'GIF89a') => 'image/gif',
        str_starts_with($bin, 'RIFF') && substr($bin, 8, 4) === 'WEBP' => 'image/webp',
        default => null,
    };
}

/**
 * Shrinks an image to at most $maxBytes (JPEG) so the decision model accepts it. Returns [bytes, mime] or null
 * when it cannot be done (no GD/Imagick, unreadable image).
 */
function mod_shrink_image(string $bin, int $maxBytes): ?array
{
    $side = 1024;
    $quality = 80;
    for ($i = 0; $i < 7; $i++) {
        $out = null;
        if (function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($bin);
            if ($im === false) {
                return null;
            }
            $w = imagesx($im);
            $h = imagesy($im);
            $scale = min(1.0, $side / max($w, $h));
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten transparency onto white
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagejpeg($dst, null, $quality);
            $out = (string)ob_get_clean();
        } elseif (class_exists('Imagick')) {
            try {
                $im = new Imagick();
                $im->readImageBlob($bin);
                $im->setIteratorIndex(0);
                $im->setImageBackgroundColor('white');
                $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $im->thumbnailImage($side, $side, true);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality($quality);
                $out = $im->getImageBlob();
            } catch (Throwable $e) {
                return null;
            }
        } else {
            return null;
        }
        if ($out !== '' && strlen($out) <= $maxBytes) {
            return [$out, 'image/jpeg'];
        }
        $side = (int)($side * 0.75);
        $quality = max(40, $quality - 10);
    }
    return null;
}

/**
 * Asks a multimodal decision model (default cloudflare/clef-flash, via OpenRouter's Decisions API) whether ONE image
 * is sexual/explicit. $image is a data: URL. Returns ['verdict' => safe|not_safe|error|unchecked, 'p', 'cost', 'error'].
 * "unchecked" means the image was not a data URL (e.g. an https link), so there were no bytes to inspect.
 */
function moderation_check_image(array $config, array $ic, string $image): array
{
    $fail = fn(string $why, ?float $cost = null): array => ['verdict' => 'error', 'p' => null, 'cost' => $cost, 'error' => $why];
    if (!preg_match('#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', $image, $m)) {
        return ['verdict' => 'unchecked', 'p' => null, 'cost' => null, 'error' => 'not an uploaded/inline image'];
    }
    $bin = base64_decode($m[1], true);
    if ($bin === false || ($mime = mod_image_mime($bin)) === null) {
        return $fail('unreadable image');
    }
    $max = max(20000, (int)($ic['max_check_bytes'] ?? 180000)); // the model's input window is small
    $prepared = mod_prepare_image($bin, $mime, $max);
    if ($prepared === null) {
        return $fail('image too large to inspect and no GD/Imagick available to shrink it');
    }
    return mod_decisions_images($config, $ic, [$prepared], mod_image_question($ic, false));
}

/** The yes/no question for the image check; the batch form asks about ANY of several attached images. */
function mod_image_question(array $ic, bool $batch): string
{
    $q = (string)($ic['question'] ??
        'The image shows visible genitals, a visible anus, an exposed female nipple in a sexual context, or an explicit '
        . 'sex act or masturbation, or is pornographic or fetish content, or sexualises a child or anyone who looks like a '
        . 'minor. It does NOT count if the person is only wearing swimwear, underwear or lingerie, is topless without being '
        . 'sexual, or is shown in artistic or non-sexual nudity such as a museum sculpture, a classical painting or a '
        . 'well-known artwork with no visible genitals.');
    return $batch ? 'Several images are attached. Answer yes if ANY ONE of them matches the following description. ' . $q : $q;
}

/** Fits one image into $maxBytes: untouched when small enough, otherwise shrunk. Returns [bytes, mime] or null. */
function mod_prepare_image(string $bin, string $mime, int $maxBytes): ?array
{
    if (strlen($bin) <= $maxBytes && $mime !== 'image/gif') {
        return [$bin, $mime];
    }
    $small = mod_shrink_image($bin, $maxBytes);
    if ($small !== null) {
        return $small;
    }
    return strlen($bin) <= $maxBytes ? [$bin, $mime] : null; // a small GIF can still go as it is
}

/** One Decisions request with 1..n prepared images [[bytes, mime], ...] and a single yes/no question. */
function mod_decisions_images(array $config, array $ic, array $items, string $question): array
{
    $fail = fn(string $why, ?float $cost = null): array => ['verdict' => 'error', 'p' => null, 'cost' => $cost, 'error' => $why];
    $or = $config['openrouter'];
    $state = ['Reference image' . (count($items) > 1 ? 's' : '') . ' submitted with an image-generation request.'];
    foreach ($items as [$bytes, $mime]) {
        $state[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]];
    }
    $payload = [
        'model' => (string)($ic['model'] ?? 'cloudflare/clef-flash'),
        'state' => $state,
        'questions' => ['explicit' => ['type' => 'noul', 'instructions' => $question]],
    ];
    $url = (string)($ic['url'] ?? (preg_replace('#/v1/?$#', '', rtrim((string)$or['base_url'], '/')) . '/alpha/decisions'));
    $key = (string)($ic['api_key'] ?? $or['api_key']);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(1, (int)($ic['timeout'] ?? 20)),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => (bool)($or['verify_ssl'] ?? true),
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;
    $p = $json['answers']['explicit']['noul'] ?? null;
    $cost = is_numeric($json['usage']['cost'] ?? null) ? (float)$json['usage']['cost'] : null;
    if ($status !== 200 || !is_numeric($p) || $p < 0 || $p > 1) {
        return $fail($err !== '' ? $err : 'HTTP ' . $status . ' ' . substr((string)$raw, 0, 200), $cost);
    }
    return ['verdict' => (float)$p >= (float)($ic['threshold'] ?? 0.5) ? 'not_safe' : 'safe',
        'p' => (float)$p, 'cost' => $cost, 'error' => null];
}

/**
 * Checks all reference images of a request (data: URLs; anything else is "unchecked"), using ONE Decisions request
 * for several images when moderation.image_check.batch is on. If the batch is flagged, each image is then checked
 * on its own to find out which one(s) to quarantine.
 *
 * Returns ['entries' => [...], 'cost' => float, 'flagged' => [1-based indexes]]. Entries are ['index' => n] for a single
 * image or ['scope' => 'batch', 'indexes' => [...]] for the combined check, each with verdict, p, cost, error.
 */
function moderation_check_images(array $config, array $ic, array $urls): array
{
    $res = ['entries' => [], 'cost' => 0.0, 'flagged' => []];
    $add = function (array $e) use (&$res): void {
        $res['cost'] += (float)($e['cost'] ?? 0);
        $res['entries'][] = $e;
    };
    $data = [];
    foreach ($urls as $i => $u) {
        if (preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $u)) {
            $data[$i + 1] = $u;
        } else {
            $add(['index' => $i + 1, 'verdict' => 'unchecked', 'p' => null, 'cost' => null, 'error' => 'not an uploaded/inline image']);
        }
    }
    // A link can't be inspected: when links are refused anyway, don't spend anything on the other images.
    if ($res['entries'] && ($ic['url_images'] ?? 'reject') === 'reject') {
        return $res;
    }

    $single = function (int $idx) use (&$data, $config, $ic, $add, &$res): array {
        $r = moderation_check_image($config, $ic, $data[$idx]);
        $add(['index' => $idx] + $r);
        if ($r['verdict'] === 'not_safe') {
            $res['flagged'][] = $idx;
        }
        return $r;
    };

    if (($ic['batch'] ?? true) && count($data) >= 2) {
        // The model's input window is shared, so each image gets an equal share of the byte budget.
        $each = max(15000, intdiv((int)($ic['max_batch_bytes'] ?? 180000), count($data)));
        $items = [];
        foreach ($data as $idx => $u) {
            $bin = preg_match('#,(.+)$#s', $u, $m) ? base64_decode($m[1], true) : false;
            $mime = $bin === false ? null : mod_image_mime($bin);
            $p = $mime === null ? null : mod_prepare_image($bin, $mime, $each);
            if ($p === null) {
                $items = null; // can't fit them all into one request: check them one by one instead
                break;
            }
            $items[] = $p;
        }
        if ($items !== null) {
            $b = mod_decisions_images($config, $ic, $items, mod_image_question($ic, true));
            $add(['scope' => 'batch', 'indexes' => array_keys($data)] + $b);
            if ($b['verdict'] !== 'not_safe') {
                return $res; // safe, or the check failed (the caller applies on_error)
            }
            // Flagged as a set: find which image(s) did it, for the record and the quarantine.
            foreach (array_keys($data) as $idx) {
                $single($idx);
            }
            if (!$res['flagged']) {
                $res['flagged'] = array_keys($data); // none stood out alone: keep the whole set as evidence
            }
            return $res;
        }
    }

    foreach (array_keys($data) as $idx) {
        if ($single($idx)['verdict'] === 'not_safe') {
            break; // one flagged image is enough to refuse the request
        }
    }
    return $res;
}

// ------------------------------------------------------------------ quarantine (evidence for rejected images)
//
// An image the check rejects is kept encrypted under a random id, outside any web-served path and never shown in
// /history. Each file has a plaintext sidecar (<id>.json) with who sent it and when, so an admin can find it by id
// and retrieve it on the server with bin/quarantine.php. Nothing here is reachable over HTTP.

function quarantine_dir(array $config): string
{
    return dirname($config['output']['storage_dir']) . '/quarantine';
}

/** 32-byte key from moderation.image_check.quarantine.key, or an auto-generated key file next to the data. */
function quarantine_key(array $config): ?string
{
    $q = $config['moderation']['image_check']['quarantine'] ?? [];
    $secret = (string)($q['key'] ?? '');
    if ($secret === '') {
        $dir = quarantine_dir($config);
        $file = $dir . '/.key';
        if (!is_file($file)) {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return null;
            }
            if (@file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX) === false) {
                return null;
            }
            @chmod($file, 0600);
        }
        $secret = trim((string)@file_get_contents($file));
        if ($secret === '') {
            return null;
        }
    }
    return hash('sha256', 'relay-quarantine|' . $secret, true);
}

/** Encrypts with AES-256-GCM (OpenSSL) or XSalsa20-Poly1305 (libsodium). Returns null if neither is available. */
function quarantine_encrypt(string $key, string $plain, string $cipher = 'auto'): ?string
{
    if ($cipher === 'none') {
        return null; // forced plaintext fallback
    }
    if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $ct === false ? null : "RQ1\x01" . $iv . $tag . $ct;
    }
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return "RQ1\x02" . $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
    }
    return null;
}

function quarantine_decrypt(?string $key, string $blob): ?string
{
    if (!str_starts_with($blob, 'RQ1') || strlen($blob) < 5) {
        return null;
    }
    $kind = ord($blob[3]);
    $rest = substr($blob, 4);
    if ($kind === 0) { // plaintext fallback: protected by file permissions only
        return $rest;
    }
    if ($key === null) {
        return null;
    }
    if ($kind === 1 && function_exists('openssl_decrypt')) {
        $plain = openssl_decrypt(substr($rest, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($rest, 0, 12), substr($rest, 12, 16));
        return $plain === false ? null : $plain;
    }
    if ($kind === 2 && function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $plain = sodium_crypto_secretbox_open(substr($rest, $n), substr($rest, 0, $n), $key);
        return $plain === false ? null : $plain;
    }
    return null;
}

/**
 * Stores one data: URL image in quarantine. $meta is written to the plaintext sidecar (label, ip, model, prompt...).
 * Returns ['id','sha256','bytes','mime'] or null when it could not be stored (the rejection still happens).
 */
function quarantine_store_data_url(array $config, string $dataUrl, array $meta): ?array
{
    if (!preg_match('#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', $dataUrl, $m)) {
        return null;
    }
    $bin = base64_decode($m[1], true);
    if ($bin === false || $bin === '') {
        return null;
    }
    $qcfg = $config['moderation']['image_check']['quarantine'] ?? [];
    $key = quarantine_key($config);
    $blob = $key === null ? null : quarantine_encrypt($key, $bin, (string)($qcfg['cipher'] ?? 'auto'));
    $protection = 'encrypted';
    if ($blob === null) {
        // No usable cipher/key: fall back to the raw bytes, locked down with file permissions instead.
        if (!($qcfg['allow_plaintext'] ?? true)) {
            return null;
        }
        $blob = "RQ1\x00" . $bin;
        $protection = 'plaintext';
    }
    $dir = quarantine_dir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    $id = 'q' . bin2hex(random_bytes(10));
    $file = $dir . '/' . $id . '.rq';
    if (@file_put_contents($file, $blob) === false) {
        return null;
    }
    if ($protection === 'plaintext') {
        // Hand the file to root and remove all access. Changing the owner only works when PHP itself runs as root;
        // otherwise the owner stays the web user, with mode 000 (root and the admin tool can still read it).
        $root = function_exists('posix_geteuid') && posix_geteuid() === 0 && @chown($file, 'root') && @chgrp($file, 'root');
        @chmod($file, 0000);
        $protection = $root ? 'plaintext, owner root, mode 000' : 'plaintext, mode 000 (owner not changed: PHP is not root)';
    } else {
        @chmod($file, 0600);
    }
    $info = ['id' => $id, 'sha256' => hash('sha256', $bin), 'bytes' => strlen($bin),
        'mime' => mod_image_mime($bin) ?? 'application/octet-stream', 'protection' => $protection];
    @file_put_contents($dir . '/' . $id . '.json',
        json_encode(['time' => gmdate('c')] + $info + $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    quarantine_cleanup($config);
    return $info;
}

/** Deletes quarantined files older than retention_days (0 = keep forever). Runs on ~1 in 20 stores. */
function quarantine_cleanup(array $config, bool $force = false): int
{
    $days = (int)($config['moderation']['image_check']['quarantine']['retention_days'] ?? 90);
    if ($days <= 0 || (!$force && random_int(1, 20) !== 1)) {
        return 0;
    }
    $n = 0;
    foreach (glob(quarantine_dir($config) . '/q*.*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - $days * 86400 && @unlink($f)) {
            $n++;
        }
    }
    return $n;
}
