<?php
// Usage: php bin/make-token.php "label"
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$label = $argv[1] ?? 'claude-skill';
if ($label === '--viewer') { $label = 'viewer'; }
$viewer = in_array('--viewer', $argv, true);
$token = ($viewer ? 'imgv_' : 'imgr_') . bin2hex(random_bytes(24));
$hash  = hash('sha256', $token);

echo "\nToken (shown only once):\n  $token\n\n";
echo "Add to config.php under security." . ($viewer ? "viewer_tokens" : "tokens") . ":\n\n";
echo "    '" . addslashes($label) . "' => [\n";
echo "        'hash' => '$hash',\n";
echo "        'enabled' => true,\n";
echo "    ],\n\n";
