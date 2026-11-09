<?php
// Usage: php bin/check-prompt.php [--second-opinion] "prompt text"
// Tests the moderation rules in config.php. Offline unless --second-opinion is given (then it calls the
// configured Jev model on OpenRouter with your API key, which costs a fraction of a cent).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RELAY_APP', true);
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../moderation.php';
$cfg = $config['moderation'] ?? [];
foreach (moderation_validate($cfg) as $p) {
    fwrite(STDERR, "config problem: $p\n");
}
echo 'moderation enabled in config: ' . (!empty($cfg['enabled']) ? 'yes' : 'NO (rules below are tested anyway)') . "\n";
$args = array_slice($argv, 1);
$withSo = in_array('--second-opinion', $args, true);
$prompt = implode(' ', array_values(array_diff($args, ['--second-opinion'])));
if ($prompt === '') {
    fwrite(STDERR, "usage: php bin/check-prompt.php \"prompt text\"\n");
    exit(2);
}
$hit = moderation_check($cfg, $prompt);
if ($hit === null && $withSo) {
    $s = moderation_second_opinion($config, $cfg['second_opinion'] ?? [], $prompt);
    echo 'second opinion: ' . $s['verdict'] . ($s['p'] !== null ? ' (p=' . round($s['p'], 3) . ', threshold '
        . ($cfg['second_opinion']['threshold'] ?? 0.5) . ')' : '') . ($s['error'] ? ' error: ' . $s['error'] : '')
        . ($s['cost'] !== null ? ' cost $' . $s['cost'] : '') . "\n";
    exit($s['verdict'] === 'not_safe' ? 1 : 0);
}
if ($hit === null) {
    echo "PASS (keyword rules)\n";
    exit(0);
}
echo "REJECT  rule={$hit['rule']}  matched via={$hit['variant']}\n";
exit(1);
