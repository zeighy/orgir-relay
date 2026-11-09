<?php
// Admin tool for images the image check rejected and the relay kept as evidence (encrypted, never served over HTTP).
//
//   php bin/quarantine.php list                 recent items: id, time, token label, ip, size
//   php bin/quarantine.php show <id>            full record (who/when/prompt/hash) - does not touch the image
//   php bin/quarantine.php export <id> <file>   decrypt the image to <file> (created 0600). Handle with care.
//   php bin/quarantine.php delete <id>          permanently delete the image and its record
//   php bin/quarantine.php purge                delete items older than quarantine.retention_days
//
// The id is the "File id" shown in the withheld box on /history.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RELAY_APP', true);
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../moderation.php';

$cmd = $argv[1] ?? '';
$dir = quarantine_dir($config);
$idArg = static function () use ($argv): string {
    $id = $argv[2] ?? '';
    if (!preg_match('/^q[a-f0-9]{20}$/', $id)) {
        fwrite(STDERR, "invalid or missing id (expected like q0123456789abcdef0123)\n");
        exit(2);
    }
    return $id;
};
$meta = static fn(string $id): ?array => is_file("$dir/$id.json") ? json_decode((string)file_get_contents("$dir/$id.json"), true) : null;

switch ($cmd) {
    case 'list':
        $files = glob("$dir/q*.json") ?: [];
        rsort($files);
        foreach ($files as $f) {
            $m = json_decode((string)file_get_contents($f), true) ?: [];
            printf("%s  %s  token=%-14s ip=%-15s %6.1f KB  %s\n", $m['id'] ?? basename($f, '.json'), $m['time'] ?? '?',
                $m['label'] ?? '?', $m['ip'] ?? '?', ($m['bytes'] ?? 0) / 1024, $m['mime'] ?? '');
        }
        echo count($files) . " item(s)\n";
        break;

    case 'show':
        $id = $idArg();
        $m = $meta($id);
        if (!$m) { fwrite(STDERR, "not found\n"); exit(1); }
        echo json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        echo 'protection: ' . ($m['protection'] ?? 'encrypted') . "\n";
        echo 'encrypted file: ' . (is_file("$dir/$id.rq") ? "$dir/$id.rq" : 'MISSING') . "\n";
        break;

    case 'export':
        $id = $idArg();
        $out = $argv[3] ?? '';
        $m = $meta($id);
        if (!$m || !is_file("$dir/$id.rq") || $out === '') { fwrite(STDERR, "usage: export <id> <output file> (id must exist)\n"); exit(2); }
        if (file_exists($out)) { fwrite(STDERR, "refusing to overwrite $out\n"); exit(2); }
        $file = "$dir/$id.rq";
        $relock = false;
        if (!is_readable($file)) { // plaintext fallback files are stored with mode 000
            $relock = @chmod($file, 0400); // works for the file's owner or root
            if (!is_readable($file)) { fwrite(STDERR, "no read access to $file. Re-run as root: sudo php bin/quarantine.php export $id <file>\n"); exit(1); }
        }
        $blob = (string)file_get_contents($file);
        if ($relock) { @chmod($file, 0000); }
        $key = quarantine_key($config);
        $plain = quarantine_decrypt($key, $blob);
        if ($plain === null) { fwrite(STDERR, "could not decrypt (wrong/missing key, or unsupported cipher on this PHP)\n"); exit(1); }
        if (hash('sha256', $plain) !== ($m['sha256'] ?? '')) { fwrite(STDERR, "WARNING: hash does not match the record; file may be damaged\n"); }
        file_put_contents($out, $plain);
        @chmod($out, 0600);
        echo "wrote $out (" . strlen($plain) . " bytes, " . ($m['mime'] ?? '?') . "). Delete it when you are done.\n";
        break;

    case 'delete':
        $id = $idArg();
        $n = 0;
        foreach (["$dir/$id.rq", "$dir/$id.json"] as $f) { if (is_file($f) && unlink($f)) { $n++; } }
        echo $n ? "deleted $id\n" : "nothing to delete\n";
        break;

    case 'purge':
        echo 'removed ' . quarantine_cleanup($config, true) . " expired file(s)\n";
        break;

    default:
        fwrite(STDERR, "usage: php bin/quarantine.php list | show <id> | export <id> <file> | delete <id> | purge\n");
        exit(2);
}
