<?php
/**
 * 最小化 SpaceTrack 登录验证（只读）：确认 cookie jar 是否真的被写入。
 * 只登录一次，不做多次重试，避免触发登录频率限制。
 */
$user = (string) getenv('ST_USER');
$pass = (string) getenv('ST_PASS');
if ($user === '' || $pass === '') { fwrite(STDERR, "no creds\n"); exit(2); }

$jar = sys_get_temp_dir() . '/st-verify-' . getmypid() . '.jar';
@unlink($jar);

$ch = curl_init('https://www.space-track.org/ajaxauth/login');
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('identity' => $user, 'password' => $pass)),
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    ),
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
));
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

printf("login http=%d\n", $code);
printf("curl error=%s\n", $err === '' ? '(none)' : $err);
printf("body len=%d body=%s\n", strlen((string) $body), substr((string) $body, 0, 200));
printf("cookie jar exists=%s size=%s\n", is_file($jar) ? 'yes' : 'NO', is_file($jar) ? filesize($jar) : '-');
if (is_file($jar)) {
    $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($jar));
    foreach (array_slice(array_filter($lines), 0, 8) as $l) {
        // mask the value
        $parts = preg_split('/\t/', $l);
        if (count($parts) >= 7) {
            $parts[6] = substr($parts[6], 0, 4) . '***';
        }
        echo "  jar: ", implode("\t", $parts), "\n";
    }
}

if ($code !== 200) {
    echo "login failed; not querying\n";
    @unlink($jar);
    exit(1);
}

$ch = curl_init('https://www.space-track.org/basicspacedata/query/class/gp/NORAD_CAT_ID/28054,28912/format/json');
curl_setopt_array($ch, array(
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => array('User-Agent: AzureOpenAPI-for-Satellite/3.3.0+ra (+https://satellite.api.authskin.xyz)'),
));
$q = curl_exec($ch);
$qc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$rows = json_decode((string) $q, true);
printf("query http=%d rows=%s\n", $qc, is_array($rows) ? count($rows) : 'n/a');
if (is_array($rows)) {
    foreach (array_slice($rows, 0, 3) as $r) {
        printf("  %-8s %-24s epoch=%s type=%s decay=%s\n",
            (string) ($r['NORAD_CAT_ID'] ?? '?'),
            substr((string) ($r['OBJECT_NAME'] ?? '?'), 0, 24),
            (string) ($r['EPOCH'] ?? '?'),
            (string) ($r['OBJECT_TYPE'] ?? '?'),
            (string) ($r['DECAY_DATE'] ?? 'null'));
    }
} else {
    printf("  body head: %s\n", substr((string) $q, 0, 300));
}

@unlink($jar);
