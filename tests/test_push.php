<?php
/**
 * اعلان روی گوشی (Web Push) — `includes/push.php`.
 *
 * ⛔ «فرستاده شد» با «درست فرستاده شد» یکی نیست. پس:
 *    ۱. رمزنگاری با **بردارِ آزمونِ خودِ RFC 8291** سنجیده می‌شود، بایت به بایت.
 *    ۲. یک «سرویسِ پوشِ» ساختگی روی localhost درخواستِ واقعیِ سرور را
 *       می‌گیرد؛ تست بدنه را با کلیدِ خصوصیِ «مرورگر» **رمزگشایی** و امضای
 *       VAPID را با کلیدِ عمومی **وارسی** می‌کند — همان کاری که FCM می‌کند.
 *    ۳. رفتارِ cron: یک بار، نه هر دقیقه؛ اعلانِ خوانده‌شده نه؛ اشتراکِ
 *       ۴۱۰ پاک؛ پس‌مانده‌ی پیش از «روشن کردن» نه.
 * ⚠ بخشِ ۱ بی‌دیتابیس است؛ بقیه دیتابیس می‌خواهد (`T::blocked`).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
$root = realpath(__DIR__ . '/..');
$tmpKey = sys_get_temp_dir() . '/pushtest_' . getmypid() . '/vapid.json';
define('PUSH_VAPID_FILE', $tmpKey);
require_once $root . '/includes/push.php';

// ---------------------------------------------------------------
T::group('۱ — رمزنگاری با بردارِ آزمونِ RFC 8291 (بخشِ ۵)');
// ---------------------------------------------------------------
$d     = Push::unb64u('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$asPub = Push::unb64u('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$der   = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $asPub;
$asPem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
$out = Push::encrypt('When I grow up, I want to be a watermelon',
    Push::unb64u('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
    Push::unb64u('BTBZMqHH6r4Tts7J_aSIgg'), [$asPem, Push::unb64u('DGv6ra1nlYgDCS1FRnbzlw')]);
T::same('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
    Push::b64u((string)$out), 'بدنه‌ی رمزشده بایت‌به‌بایت همان خروجیِ RFC است');

T::group('۲ — فهرستِ مجازِ سرویس‌های پوش');
foreach ([
    ['https://fcm.googleapis.com/fcm/send/abc', true],
    ['https://updates.push.services.mozilla.com/wpush/v2/x', true],
    ['https://web.push.apple.com/QGx', true],
    ['https://db5p.notify.windows.com/w/?token=1', true],
    ['http://fcm.googleapis.com/fcm/send/abc', false],
    ['https://fcm.googleapis.com.evil.example/x', false],
    ['https://evil.example/fcm.googleapis.com', false],
    ['https://127.0.0.1/x', false],
] as [$u, $want]) {
    T::same($want, Push::endpointAllowed($u), ($want ? 'پذیرفته: ' : 'رد: ') . $u);
}

// ---------- ابزارهای «مرورگرِ» ساختگی ----------
$newUa = static function (): array {
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $dd = openssl_pkey_get_details($k);
    $pub = "\x04" . str_pad($dd['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($dd['ec']['y'], 32, "\0", STR_PAD_LEFT);
    return [$k, $pub, random_bytes(16)];
};
$decrypt = static function (string $body, $uaPriv, string $uaPub, string $auth): ?string {
    $salt = substr($body, 0, 16);
    $idl = ord($body[20]);
    $as = substr($body, 21, $idl);
    $ct = substr($body, 21 + $idl);
    $ecdh = openssl_pkey_derive(openssl_pkey_get_public(Push::pubToPem($as)), $uaPriv);
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPub . $as, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $pt = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($ct, -16));
    return $pt === false ? null : rtrim($pt, "\x02");
};
$rawToDer = static function (string $raw): string {
    $int = static function (string $n): string {
        $n = ltrim($n, "\0");
        if ($n === '' || (ord($n[0]) & 0x80)) { $n = "\0" . $n; }
        return "\x02" . chr(strlen($n)) . $n;
    };
    $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
    return "\x30" . chr(strlen($seq)) . $seq;
};

T::group('۳ — امضای VAPID (JWT/ES256) با کلیدِ عمومی وارسی می‌شود');
$vap = Push::vapid(true);
T::ok(is_array($vap) && strlen(Push::unb64u($vap['public'])) === 65, 'کلیدِ VAPID ساخته شد (۶۵ بایت)');
T::same(substr(sprintf('%o', fileperms($tmpKey)), -4), '0600', 'فایلِ کلیدِ خصوصی فقط برای مالکش خواندنی است');
$authHdr = (string)Push::vapidAuth('https://fcm.googleapis.com/fcm/send/abc');
T::ok(preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $authHdr, $jm) === 1, 'سرآیندِ Authorization شکلِ «vapid t=…, k=…» دارد');
$claims = json_decode(Push::unb64u($jm[2] ?? ''), true);
T::same('https://fcm.googleapis.com', $claims['aud'] ?? null, 'aud همان مبدأِ سرویسِ پوش است');
T::ok(($claims['exp'] ?? 0) > time() && ($claims['exp'] ?? 0) <= time() + 86400, 'exp کمتر از ۲۴ ساعت است (سقفِ RFC 8292)');
$sigOk = openssl_verify(($jm[1] ?? '') . '.' . ($jm[2] ?? ''), $rawToDer(Push::unb64u($jm[3] ?? '')),
    Push::pubToPem(Push::unb64u($jm[4] ?? '')), OPENSSL_ALGO_SHA256);
T::same(1, $sigOk, 'امضا با کلیدِ عمومیِ همان سرآیند وارسی می‌شود');

// ---------------------------------------------------------------
T::group('۴ — cron: فرستادنِ واقعی به یک سرویسِ پوشِ ساختگی');
// ---------------------------------------------------------------
$cleanupFiles = function () use ($tmpKey) {
    foreach (glob(dirname($tmpKey) . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir(dirname($tmpKey));
};
if (!file_exists($root . '/config/config.php')) { T::blocked('Web Push', 'config/config.php وجود ندارد'); $cleanupFiles(); exit(T::report()); }
require_once $root . '/includes/functions.php';
require_once $root . '/includes/notify.php';
require_once $root . '/includes/user_data.php';
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('Web Push', 'اتصال به دیتابیس برقرار نشد'); $cleanupFiles(); exit(T::report()); }
if (!Push::available() || !tableHasColumn('notifications', 'pushed_at')) { T::blocked('Web Push', 'migration_push اجرا نشده'); $cleanupFiles(); exit(T::report()); }

$sink = tempnam(sys_get_temp_dir(), 'pushsink');
$serverPid = 0;
$uid = 0;
$purge = function () use ($pdo) {
    $st = $pdo->query("SELECT id FROM users WHERE username = 'push_u'");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try { deleteUserAccount((int)$id); } catch (Throwable $e) {}
        $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
    }
};
try {
    $purge();
    $pdo->exec("INSERT INTO users (full_name, username, password_hash, role, is_active) VALUES ('کاربر پوش', 'push_u', 'x', 'user', 1)");
    $uid = (int)$pdo->lastInsertId();

    $port = 0;
    for ($p = 8911; $p <= 8929; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('Web Push', 'پورت آزاد پیدا نشد'); throw new RuntimeException('skip'); }
    $serverPid = (int)trim((string)shell_exec(sprintf('PUSH_SINK_FILE=%s php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
        escapeshellarg($sink), $port, escapeshellarg(__DIR__ . '/push_sink.php'))));
    for ($i = 0; $i < 40; $i++) { usleep(120000); $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($s) { fclose($s); break; } }

    [$uaPriv, $uaPub, $uaAuth] = $newUa();
    // ⚠ مستقیم درج می‌شود: `subscribe()` عمداً فقط https و میزبان‌های
    //   شناخته‌شده را می‌پذیرد، و این سرویسِ ساختگی روی http است.
    $addSub = function (string $path, string $pub, string $auth) use ($pdo, $uid, $port) {
        $ep = "http://127.0.0.1:{$port}{$path}";
        $pdo->prepare('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth) VALUES (:u,:e,:h,:p,:a)')
            ->execute(['u' => $uid, 'e' => $ep, 'h' => hash('sha256', $ep), 'p' => Push::b64u($pub), 'a' => Push::b64u($auth)]);
    };
    $addSub('/ok/1', $uaPub, $uaAuth);

    Notify::push($uid, 'due', 'چک ۵ میلیونی امروز سررسید است', 'بانک ملت', 'due.php', 'pt:1');
    $r = Push::flushPending();
    $recs = array_values(array_filter(array_map(fn($l) => json_decode($l, true), file($sink, FILE_IGNORE_NEW_LINES) ?: [])));
    T::same(1, count($recs), 'یک درخواست به سرویسِ پوش رسید');
    $rec = $recs[0] ?? ['headers' => [], 'body' => ''];
    $h = array_change_key_case($rec['headers'] ?? [], CASE_LOWER);
    T::same('aes128gcm', $h['content-encoding'] ?? null, 'Content-Encoding: aes128gcm');
    T::ok((int)($h['ttl'] ?? 0) > 0, 'سرآیندِ TTL هست');
    $plain = $decrypt(base64_decode($rec['body']), $uaPriv, $uaPub, $uaAuth);
    $msg = json_decode((string)$plain, true);
    T::same('چک ۵ میلیونی امروز سررسید است', $msg['title'] ?? null, 'بدنه با کلیدِ «مرورگر» رمزگشایی شد و عنوانِ اعلان همان است');
    T::same('due.php', $msg['url'] ?? null, '… و لینکِ اعلان همراهش است');
    T::same(1, $msg['badge'] ?? null, '… و عددِ نخوانده برای آیکون (badge)');
    preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', (string)($h['authorization'] ?? ''), $jm2);
    T::same(1, openssl_verify(($jm2[1] ?? '') . '.' . ($jm2[2] ?? ''), $rawToDer(Push::unb64u($jm2[3] ?? '')),
        Push::pubToPem(Push::unb64u($vap['public'])), OPENSSL_ALGO_SHA256), 'امضای VAPIDِ درخواستِ واقعی با کلیدِ عمومیِ نصب وارسی می‌شود');

    file_put_contents($sink, '');
    Push::flushPending();
    T::same(0, filesize($sink), 'دورِ دوم چیزی نمی‌فرستد (pushed_at نگهبانِ «یک بار» است)');

    Notify::push($uid, 'due', 'خوانده‌شده', '', '', 'pt:2');
    $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND dedup_key = 'pt:2'")->execute(['u' => $uid]);
    Push::flushPending();
    T::same(0, filesize($sink), 'اعلانی که کاربر همین حالا داخلِ اپ دیده روی گوشی نمی‌آید');

    Notify::push($uid, 'due', 'الف', '', '', 'pt:3');
    Notify::push($uid, 'due', 'ب', '', '', 'pt:4');
    Notify::push($uid, 'due', 'ج', '', '', 'pt:5');
    Push::flushPending();
    $recs = array_values(array_filter(array_map(fn($l) => json_decode($l, true), file($sink, FILE_IGNORE_NEW_LINES) ?: [])));
    T::same(1, count($recs), 'سه اعلانِ هم‌زمان = **یک** پیام، نه سه زنگ');
    $m3 = json_decode((string)$decrypt(base64_decode($recs[0]['body'] ?? ''), $uaPriv, $uaPub, $uaAuth), true);
    T::ok(str_contains((string)($m3['body'] ?? ''), '۲'), '… که می‌گوید «+ ۲ اعلانِ دیگر»', (string)($m3['body'] ?? ''));

    // ---- ۴۱۰: اشتراکِ منقضی پاک می‌شود ----
    [, $p2, $a2] = $newUa();
    $addSub('/gone/1', $p2, $a2);
    file_put_contents($sink, '');
    $r = Push::sendToUser($uid, ['title' => 'x']);
    T::same(1, $r['gone'], 'پاسخِ ۴۱۰ «رفته» شمرده می‌شود');
    $st = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :u AND endpoint LIKE '%/gone/%'");
    $st->execute(['u' => $uid]);
    T::same(0, (int)$st->fetchColumn(), '… و اشتراکش پاک شد (وگرنه هر دقیقه دوباره امتحان می‌شد)');

    // ---- شکستِ شبکه: pushed_at زده نمی‌شود، دورِ بعد دوباره ----
    $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = :u")->execute(['u' => $uid]);
    $addSub('/fail/1', $uaPub, $uaAuth);
    Notify::push($uid, 'due', 'بعداً', '', '', 'pt:6');
    Push::flushPending();
    $st = $pdo->prepare("SELECT pushed_at FROM notifications WHERE user_id = :u AND dedup_key = 'pt:6'");
    $st->execute(['u' => $uid]);
    T::same(null, $st->fetchColumn(), 'سرویسِ پوش ۵۰۰ داد: اعلان «فرستاده» علامت نمی‌خورد و دورِ بعد دوباره می‌رود');

    // ---- subscribe(): پس‌مانده فرستاده نمی‌شود، میزبانِ ناشناخته رد ----
    [, $p3, $a3] = $newUa();
    $bad = Push::subscribe($uid, 'https://evil.example/x', Push::b64u($p3), Push::b64u($a3));
    T::same(false, $bad['ok'], 'subscribe(): میزبانِ ناشناخته رد می‌شود (نه SSRF)');
    $bad = Push::subscribe($uid, 'https://fcm.googleapis.com/fcm/send/zz', 'AAAA', Push::b64u($a3));
    T::same(false, $bad['ok'], 'subscribe(): کلیدِ خراب رد می‌شود');
    $good = Push::subscribe($uid, 'https://fcm.googleapis.com/fcm/send/pt-' . getmypid(), Push::b64u($p3), Push::b64u($a3));
    T::same(true, $good['ok'], 'subscribe(): اشتراکِ درست ذخیره می‌شود');
    $st = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND pushed_at IS NULL');
    $st->execute(['u' => $uid]);
    T::same(0, (int)$st->fetchColumn(), '… و اعلان‌های قبلی «فرستاده» علامت می‌خورند (بمبارانِ پس‌مانده نه)');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'skip') { throw $e; }
} finally {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); }
    @unlink($sink);
    $purge();
    $cleanupFiles();
}

// ---------------------------------------------------------------
T::group('۵ — در کرومیوم: سرویس‌ورکر اعلان را روی «صفحه‌ی گوشی» می‌نشاند');
// ---------------------------------------------------------------
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') { T::skip('سرویس‌ورکرِ اعلان', 'node نصب نیست'); exit(T::report()); }
$appPid = 0;
$jar = tempnam(sys_get_temp_dir(), 'pushjar');
$purgeB = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username = 'push_b'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try { deleteUserAccount((int)$id); } catch (Throwable $e) {}
        $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
    }
};
try {
    $purgeB();
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active) VALUES ('کاربر پوش', 'push_b', :p, 'user', 1)")
        ->execute(['p' => password_hash('Push#probe9', PASSWORD_DEFAULT)]);
    $port = 0;
    for ($p = 8891; $p <= 8909; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('سرویس‌ورکرِ اعلان', 'پورت آزاد پیدا نشد'); throw new RuntimeException('skip'); }
    $appPid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg(__DIR__ . '/csp_router.php'))));
    for ($i = 0; $i < 40; $i++) { usleep(150000); $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($s) { fclose($s); break; } }
    $req = function (string $path, ?array $post = null) use ($port, $jar): string {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 25]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $b = (string)curl_exec($ch); unset($ch); return $b;
    };
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $req('login.php'), $m);
    $pdo->exec("DELETE FROM login_attempts WHERE request_ip = '127.0.0.1'");
    $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => 'push_b', 'password' => 'Push#probe9']);
    $sess = '';
    foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    $prof = $req('profile.php');
    T::ok(str_contains($prof, 'id="pushCard"') && preg_match('/data-key="[\w-]{80,}"/', $prof) === 1, 'پروفایل کارتِ «اعلان روی گوشی» را با کلیدِ عمومی رندر می‌کند');
    T::ok(str_contains($prof, 'data-unread="'), 'زنگِ سرآیند عددِ نخوانده را برای آیکونِ اپ می‌دهد (data-unread)');

    $out = json_decode(trim((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/push_probe.js') . ' '
        . escapeshellarg("http://127.0.0.1:{$port}/") . ' ' . escapeshellarg($sess) . ' 2>/dev/null')), true);
    if (!is_array($out) || empty($out['ok'])) {
        $why = is_array($out) ? ($out['why'] ?? '?') : 'خروجیِ نامعتبر';
        if ($why === 'no_chromium') { T::skip('سرویس‌ورکرِ اعلان', 'کرومیوم نصب نیست'); }
        else { T::ok(false, 'probe اجرا شد', json_encode($out, JSON_UNESCAPED_UNICODE)); }
    } else {
        T::ok(!empty($out['card']['on']), 'روی مرورگرِ بی‌اشتراک دکمه‌ی «روشن کردن» دیده می‌شود', json_encode($out['card'], JSON_UNESCAPED_UNICODE));
        $n = $out['notes'][0] ?? [];
        T::same('چک امروز سررسید است', $n['title'] ?? null, 'رویدادِ push یک اعلانِ سیستمی با همان عنوان نشان داد');
        T::same('rtl', $n['dir'] ?? null, '… راست‌به‌چپ');
        T::ok(str_ends_with((string)($n['badge'] ?? ''), 'assets/icons/badge-96.png'), '… با آیکونِ تک‌رنگِ نوارِ وضعیت');
        T::ok(str_ends_with((string)($n['url'] ?? ''), '/due.php'), '… و تپ رویش به همان صفحه می‌رود');
        T::ok(count($out['notes2'] ?? []) >= 2, 'پیامِ خراب هم یک اعلانِ عادی می‌دهد، نه سکوت');
        T::same([], $out['errors'] ?? ['?'], 'هیچ خطای جاوااسکریپتی در مسیر نبود');
    }
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'skip') { throw $e; }
} finally {
    if ($appPid) { @exec("kill $appPid 2>/dev/null"); }
    @unlink($jar);
    $purgeB();
}

exit(T::report());
