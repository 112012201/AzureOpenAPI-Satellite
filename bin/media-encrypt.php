<?php
/**
 * Encrypt the film and the music in place, then move the plaintext out of the web root.
 *
 *   php bin/media-encrypt.php --dry-run
 *   php bin/media-encrypt.php
 *
 * What it does, per file listed in conf/media.php:
 *
 *   1. reads  assets/film/<name>  or  assets/music/<name>
 *   2. writes <file>.enc  (AES-256-CBC, the IV from the manifest)
 *   3. decrypts the .enc again and compares sha256 with the source -- a bad IV or a
 *      truncated write fails here, not in a visitor's browser
 *   4. moves the plaintext to the backup directory (default /www/wwwroot/satbak/media),
 *      so the web root holds ciphertext only
 *
 * Re-running is safe: a file with no plaintext left is skipped, and an existing .enc is
 * only rewritten when --force is passed.
 *
 * Nothing here is reachable over HTTP: it is a CLI tool, and the backup directory sits
 * outside the document root.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../bootstrap.php';

$options = array('dry-run' => false, 'force' => false, 'backup' => '/www/wwwroot/satbak/media');
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $options['dry-run'] = true;
    } elseif ($arg === '--force') {
        $options['force'] = true;
    } elseif (strpos($arg, '--backup=') === 0) {
        $options['backup'] = substr($arg, 9);
    } else {
        fwrite(STDERR, "unknown option: $arg\n");
        exit(2);
    }
}

$manifest = \SatApi\Support\Media::manifest();
$key = \SatApi\Support\Media::keyBytes();
if ($key === '') {
    fwrite(STDERR, "conf/media.php has no usable key\n");
    exit(1);
}

$root = dirname(__DIR__);
$backup = rtrim((string) $options['backup'], '/');
if (!is_dir($backup) && !$options['dry-run'] && !mkdir($backup, 0700, true) && !is_dir($backup)) {
    fwrite(STDERR, "cannot create backup directory $backup\n");
    exit(1);
}

$rows = array();
$failed = 0;
foreach ($manifest['files'] as $name => $entry) {
    $relative = strpos($name, '.mp4') !== false ? 'assets/film/' . $name : 'assets/music/' . $name;
    $source = $root . '/' . $relative;
    $target = $source . '.enc';
    $iv = (string) hex2bin($entry['iv']);

    $row = array('file' => $relative, 'action' => '', 'bytes' => 0);

    if (!is_file($source)) {
        $row['action'] = is_file($target) ? 'already encrypted' : 'MISSING';
        if ($row['action'] === 'MISSING') {
            $failed++;
        }
        $rows[] = $row;
        continue;
    }

    $plain = (string) file_get_contents($source);
    $row['bytes'] = strlen($plain);

    if (is_file($target) && !$options['force']) {
        $row['action'] = 'encrypted, plaintext still here (use --force to refresh)';
        $rows[] = $row;
        continue;
    }

    if ($options['dry-run']) {
        $row['action'] = 'would encrypt + move to ' . $backup;
        $rows[] = $row;
        continue;
    }

    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        $row['action'] = 'ENCRYPT FAILED';
        $failed++;
        $rows[] = $row;
        continue;
    }

    $tmp = $target . '.tmp';
    if (file_put_contents($tmp, $cipher) !== strlen($cipher) || !rename($tmp, $target)) {
        @unlink($tmp);
        $row['action'] = 'WRITE FAILED';
        $failed++;
        $rows[] = $row;
        continue;
    }

    /* Prove the pair before the plaintext leaves: same bytes out of the same cipher. */
    $round = openssl_decrypt((string) file_get_contents($target), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($round === false || !hash_equals(hash('sha256', $plain), hash('sha256', (string) $round))) {
        $row['action'] = 'ROUND-TRIP FAILED (plaintext kept)';
        $failed++;
        $rows[] = $row;
        continue;
    }

    $moved = $backup . '/' . basename($name);
    if (!rename($source, $moved)) {
        $row['action'] = 'encrypted, but could not move the plaintext out of the web root';
        $failed++;
        $rows[] = $row;
        continue;
    }

    $row['action'] = 'encrypted (' . strlen($cipher) . 'B) + moved';
    $rows[] = $row;
}

$width = 0;
foreach ($rows as $row) {
    $width = max($width, strlen($row['file']));
}
foreach ($rows as $row) {
    printf("%-{$width}s  %9s  %s\n", $row['file'], number_format($row['bytes']), $row['action']);
}
printf("\n%d file(s), %d failed%s\n", count($rows), $failed, $options['dry-run'] ? ' (dry run)' : '');
exit($failed === 0 ? 0 : 1);
