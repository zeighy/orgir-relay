<?php
// Usage: php bin/check-image.php image [image ...]   (png, jpg, webp or gif; up to 4)
// Sends the image(s) to the configured Clef model (moderation.image_check) via OpenRouter and prints the verdict.
// Uses your OpenRouter API key from config.php; costs a fraction of a cent. Works even if the feature is disabled.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RELAY_APP', true);
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../moderation.php';
$ic = $config['moderation']['image_check'] ?? [];
$files = array_slice($argv, 1);
if (!$files) {
    fwrite(STDERR, "usage: php bin/check-image.php image1 [image2 ... image4]\n");
    exit(2);
}
$urls = [];
foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "no such file: $file\n");
        exit(2);
    }
    $bin = (string)file_get_contents($file);
    $mime = mod_image_mime($bin);
    if ($mime === null) {
        fwrite(STDERR, "$file: not a PNG, JPEG, WebP or GIF image\n");
        exit(2);
    }
    $urls[] = 'data:' . $mime . ';base64,' . base64_encode($bin);
}
echo 'image check enabled in config: ' . (!empty($ic['enabled']) ? 'yes' : 'NO (testing anyway)') . "\n";
echo 'model: ' . ($ic['model'] ?? 'cloudflare/clef-flash') . ', ' . count($urls) . ' image(s), batch: '
    . (($ic['batch'] ?? true) ? 'on' : 'off') . "\n";
$r = moderation_check_images($config, $ic, $urls);
foreach ($r['entries'] as $e) {
    $who = isset($e['scope']) ? 'images ' . implode(',', $e['indexes']) . ' together' : $files[$e['index'] - 1];
    echo '  ' . $who . ': ' . $e['verdict'] . ($e['p'] !== null ? ' (p=' . round($e['p'], 3) . ', limit ' . ($ic['threshold'] ?? 0.5) . ')' : '')
        . (!empty($e['error']) ? ' error: ' . $e['error'] : '') . ($e['cost'] !== null ? ' cost $' . $e['cost'] : '') . "\n";
}
printf("total cost: \$%.6f%s\n", $r['cost'], $r['flagged'] ? '   flagged: ' . implode(', ', array_map(fn($i) => $files[$i - 1], $r['flagged'])) : '');
exit($r['flagged'] ? 1 : 0);
