<?php
/**
 * ⛔ ارسالِ مستقیم به سامانه‌ی مودیان (`BizMoadianLive`, migration_biz_moadian).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «پیش‌ساخت برای مشتریان» — آماده و خاموش.
 *
 * ⚠ سامانه‌ی واقعی در تست نیست (`Outbound`). پس پروتکل با یک **سامانه‌ی ساختگی** سنجیده
 *   می‌شود که هر چیز را مستقل باز می‌کند: کلیدِ محتوا با `openssl pkeyutl` (OAEP-SHA256)،
 *   متن با AES-GCM و AADِ سرآیند، امضا با گواهیِ `x5c`. اگر کدِ ما چیزی بفرستد که
 *   OpenSSL نخواند، همین‌جا می‌شکند.
 *
 *   ۱. JOSE: JWS با گواهیِ x5c، OAEP-SHA256 و JWE که OpenSSL باز می‌کند.
 *   ۲. تنظیمات: گواهیِ کلیدِ دیگر رد، کلید رمزشده، روشن کردن بی‌شناسه‌ی حافظه رد.
 *   ۳. ارسال: توکن با نانس، شماره‌ی مالیاتی با شناسه‌ی واقعی، دوباره‌فرستادن رد، استعلام.
 *   ۴. برگشت از فروش به اصلِ ثبت‌شده؛ ابطال در حسابداری ⇒ ابطالیه (ins=3، irtaxid).
 *   ۵. خطای سامانه ثبت و قابلِ ارسالِ دوباره؛ بی‌سامانه‌ی ساختگی، سدِ تست.
 *   ۶. کلید هرگز در خروجیِ کاربر نیست و از فایل نوشته نمی‌شود.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/user_import.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_acc.php';
require_once __DIR__ . '/../includes/biz_moadian.php';

const MPREFIX = '__bmd_';
const MEM = 'A11B22';

/** کلید و گواهیِ خودامضای آزمایشی */
$mk = function (string $cn): array {
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $cn], $k, ['digest_alg' => 'sha256']);
    $x = openssl_csr_sign($csr, null, $k, 30, ['digest_alg' => 'sha256']);
    openssl_pkey_export($k, $pem); openssl_x509_export($x, $crt);
    return ['key' => $pem, 'cert' => $crt, 'pub' => openssl_pkey_get_details($k)['key']];
};
$tmp = sys_get_temp_dir() . '/md_' . bin2hex(random_bytes(4));
@mkdir($tmp);
/** کلیدِ محتوا را **مستقل** با خطِ فرمانِ OpenSSL باز می‌کند (OAEP، SHA-256 برای هش و MGF1). */
$oaepDecrypt = function (string $enc, string $privPem) use ($tmp): ?string {
    file_put_contents("{$tmp}/k.pem", $privPem); file_put_contents("{$tmp}/in.bin", $enc);
    $cmd = 'openssl pkeyutl -decrypt -inkey ' . escapeshellarg("{$tmp}/k.pem") . ' -in ' . escapeshellarg("{$tmp}/in.bin")
         . ' -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256 2>/dev/null';
    $out = shell_exec($cmd);
    return is_string($out) && $out !== '' ? $out : null;
};
$jweOpen = function (string $jwe, string $privPem) use ($oaepDecrypt): ?string {
    $p = explode('.', $jwe);
    if (count($p) !== 5) { return null; }
    $cek = $oaepDecrypt(BizMoadianLive::b64uDecode($p[1]), $privPem);
    if ($cek === null) { return null; }
    $plain = openssl_decrypt(BizMoadianLive::b64uDecode($p[3]), 'aes-256-gcm', $cek, OPENSSL_RAW_DATA,
        BizMoadianLive::b64uDecode($p[2]), BizMoadianLive::b64uDecode($p[4]), $p[0]);
    return $plain === false ? null : $plain;
};
/** JWS را با گواهیِ داخلِ خودش (x5c) می‌سنجد ⇒ [header, payload] یا null */
$jwsOpen = function (string $jws): ?array {
    $p = explode('.', $jws);
    if (count($p) !== 3) { return null; }
    $h = json_decode(BizMoadianLive::b64uDecode($p[0]), true);
    $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split((string)($h['x5c'][0] ?? ''), 64, "\n") . "-----END CERTIFICATE-----\n";
    $ok = openssl_verify($p[0] . '.' . $p[1], BizMoadianLive::b64uDecode($p[2]), openssl_pkey_get_public($cert), OPENSSL_ALGO_SHA256);
    return $ok === 1 ? [$h, json_decode(BizMoadianLive::b64uDecode($p[1]), true)] : null;
};

$shop = $mk('shop'); $org = $mk('tax-org'); $other = $mk('other');

T::group('۱ — JOSE');
$jws = BizMoadianLive::jws(BizMoadianLive::jwsHeader($shop['cert'], '2026-10-16T10:00:00Z'), ['a' => 'ب'], $shop['key']);
$o = $jwsOpen($jws);
T::ok($o !== null && $o[1] === ['a' => 'ب'], 'JWS با گواهیِ x5c سنجیده می‌شود');
T::same(['RS256', 'jose', ['sigT'], '2026-10-16T10:00:00Z'], [$o[0]['alg'] ?? null, $o[0]['typ'] ?? null, $o[0]['crit'] ?? null, $o[0]['sigT'] ?? null], 'سرآیند: RS256، typ jose، sigT بحرانی');
$bad = explode('.', $jws); $bad[1] = BizMoadianLive::b64u('{"a":"x"}');
T::same(null, $jwsOpen(implode('.', $bad)), '⛔ متنِ دست‌خورده امضا را می‌شکند');
$cek = random_bytes(32);
T::same($cek, $oaepDecrypt(BizMoadianLive::oaepSha256Encrypt($cek, $org['pub']), $org['key']), '⛔ OAEP-SHA256ِ ما را OpenSSL باز می‌کند');
T::same(null, $oaepDecrypt(BizMoadianLive::oaepSha256Encrypt($cek, $org['pub']), $other['key']), 'و با کلیدِ دیگر نه');
$plain = json_encode(['متن' => 'صورتحساب ۱۲۳'], JSON_UNESCAPED_UNICODE);
$jwe = BizMoadianLive::jwe($plain, $org['pub'], 'kid-1');
T::same($plain, $jweOpen($jwe, $org['key']), '⛔ JWE (RSA-OAEP-256 + A256GCM) با OpenSSL باز می‌شود');
T::same(['alg' => 'RSA-OAEP-256', 'enc' => 'A256GCM', 'kid' => 'kid-1'], json_decode(BizMoadianLive::b64uDecode(explode('.', $jwe)[0]), true), 'سرآیندِ JWE');
$t = explode('.', $jwe); $t[0] = BizMoadianLive::b64u('{"alg":"RSA-OAEP-256","enc":"A256GCM","kid":"x"}');
T::same(null, $jweOpen(implode('.', $t), $org['key']), '⛔ سرآیندِ دست‌خورده (AAD) رمز را باطل می‌کند');

try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('مودیان', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage()); exit(T::report()); }
if (!Biz::available() || !Biz::accReady() || !BizMoadianLive::ready()) {
    T::blocked('مودیان', 'جدول‌ها نیست — اول: bash deploy/migrate.sh --apply'); exit(T::report());
}
// ⛔ رمزنگاریِ نصب برای همین فرایند روشن (کلیدِ آزمایشی؛ config دست نمی‌خورد)
$rk = new ReflectionClass('Crypto');
foreach (['key' => random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), 'ready' => true] as $prop => $val) {
    $rp = $rk->getProperty($prop);
    if (PHP_VERSION_ID < 80100) { $rp->setAccessible(true); }
    $rp->setValue(null, $val);
}

$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . MPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('DELETE FROM biz_moadian_sends WHERE user_id = :u')->execute(['u' => $id]);
        foreach (['biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
        $pdo->prepare('DELETE FROM biz_invoices WHERE user_id = :u AND ref_invoice_id IS NOT NULL')->execute(['u' => $id]);
        foreach (['biz_invoices', 'biz_products', 'biz_categories', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) { try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); } catch (PDOException $e) { } }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . MPREFIX . "%'");
};
$wipe();
createUserAccount($pdo, 'فروشگاهِ مودیان', MPREFIX . 'a', MPREFIX . 'a@example.com', 'Moad12345');
$st = $pdo->prepare('SELECT id FROM users WHERE username = :u'); $st->execute(['u' => MPREFIX . 'a']);
$u = (int)$st->fetchColumn();
Biz::setType($u, 'business');
$acc = (int)BizCash::list($u)[0]['id'];
Biz::saveSettings($u, ['shop_name' => 'فروشگاهِ آزمون', 'economic_code' => '14000000001']);

T::group('۲ — تنظیمات');
$r = BizMoadianLive::saveConfig($u, ['moadian_env' => 'sandbox', 'moadian_on' => 1, 'moadian_key' => $shop['key'], 'moadian_cert' => $shop['cert']]);
T::ok(!$r['ok'] && str_contains($r['message'], 'حافظه'), '⛔ روشن کردن بی‌شناسه‌ی حافظه رد', $r['message']);
Biz::saveAccounting($u, ['vat_rate' => '10', 'moadian_memory_id' => MEM, 'moadian_sstid' => '1234567890123']);
$r = BizMoadianLive::saveConfig($u, ['moadian_env' => 'sandbox', 'moadian_on' => 1, 'moadian_key' => $shop['key'], 'moadian_cert' => $other['cert']]);
T::ok(!$r['ok'] && str_contains($r['message'], 'مالِ این کلید نیست'), '⛔ گواهیِ کلیدِ دیگر رد', $r['message']);
$r = BizMoadianLive::saveConfig($u, ['moadian_env' => 'staging!', 'moadian_on' => 1]);
T::ok(!$r['ok'], 'محیطِ ناشناخته رد');
$r = BizMoadianLive::saveConfig($u, ['moadian_env' => 'sandbox', 'moadian_on' => 1, 'moadian_key' => $shop['key'], 'moadian_cert' => $shop['cert']]);
T::ok($r['ok'], 'کلید و گواهیِ درست ذخیره و روشن شد', $r['message']);
$raw = (string)$pdo->query('SELECT moadian_key FROM biz_settings WHERE user_id = ' . $u)->fetchColumn();
T::ok(str_starts_with($raw, Crypto::PREFIX) && !str_contains($raw, 'PRIVATE KEY'), '⛔ کلیدِ خصوصی فقط رمزشده در دیتابیس');
$cfg = BizMoadianLive::config($u);
T::ok($cfg['on'] && $cfg['has_key'] && $cfg['env'] === 'sandbox' && $cfg['memory_id'] === MEM, 'تنظیمات خوانده شد');
$r = BizMoadianLive::saveConfig($u, ['moadian_env' => 'sandbox', 'moadian_on' => 1, 'moadian_key' => '', 'moadian_cert' => '']);
T::ok($r['ok'] && BizMoadianLive::config($u)['has_key'], 'کلیدِ خالی = همان قبلی');

T::group('۳ — ارسال به سامانه‌ی ساختگی');
$srv = ['nonce' => 0, 'sent' => [], 'inv_reply' => null, 'inquiry' => 'SUCCESS', 'auth' => []];
BizMoadianLive::$httpForTests = function (string $m, string $url, array $headers, ?string $body) use (&$srv, $org, $jwsOpen, $jweOpen) {
    $path = (string)parse_url($url, PHP_URL_PATH); $q = (string)parse_url($url, PHP_URL_QUERY);
    $auth = '';
    foreach ($headers as $h) { if (str_starts_with($h, 'Authorization: Bearer ')) { $auth = substr($h, 22); } }
    if (str_ends_with($path, '/nonce')) { $srv['nonce']++; return ['code' => 200, 'body' => json_encode(['nonce' => 'N' . $srv['nonce']])]; }
    $tok = $auth !== '' ? $jwsOpen($auth) : null;
    if ($tok === null || ($tok[1]['nonce'] ?? '') !== 'N' . $srv['nonce'] || ($tok[1]['clientId'] ?? '') !== MEM) {
        return ['code' => 401, 'body' => json_encode(['message' => 'bad token'])];
    }
    $srv['auth'][] = $path;
    if (str_ends_with($path, '/server-information')) {
        $der = preg_replace('/-----[^-]+-----|\s+/', '', $org['pub']);
        return ['code' => 200, 'body' => json_encode(['publicKeys' => [['key' => $der, 'id' => 'org-kid', 'algorithm' => 'RSA', 'purpose' => 1]]])];
    }
    if (str_ends_with($path, '/fiscal-information')) { return ['code' => 200, 'body' => json_encode(['nameTrade' => 'فروشگاهِ آزمون'], JSON_UNESCAPED_UNICODE)]; }
    if (str_ends_with($path, '/invoice') && $m === 'POST') {
        $pk = json_decode((string)$body, true)[0] ?? [];
        $plain = $jweOpen((string)($pk['payload'] ?? ''), $org['key']);
        $sig = $plain !== null ? $jwsOpen($plain) : null;
        $ref = 'REF-' . (count($srv['sent']) + 1);
        $srv['sent'][] = ['packet_header' => $pk['header'] ?? null, 'kid' => json_decode(BizMoadianLive::b64uDecode(explode('.', (string)($pk['payload'] ?? ''))[0]), true)['kid'] ?? null,
                          'inv' => $sig[1] ?? null, 'ref' => $ref];
        if ($srv['inv_reply'] !== null) { $r = $srv['inv_reply']; $srv['inv_reply'] = null; return $r; }
        return ['code' => 200, 'body' => json_encode(['result' => [['uid' => $pk['header']['requestTraceId'] ?? '', 'referenceNumber' => $ref, 'errorCode' => null, 'errorDetail' => null]]])];
    }
    if (str_ends_with($path, '/inquiry-by-reference-id')) {
        preg_match_all('/referenceIds=([^&]+)/', $q, $mm);
        return ['code' => 200, 'body' => json_encode(array_map(fn($ref) => ['referenceNumber' => urldecode($ref), 'status' => $srv['inquiry'],
            'data' => ['error' => $srv['inquiry'] === 'FAILED' ? [['code' => 'E1', 'message' => 'کدِ کالا نامعتبر']] : [], 'warning' => []]], $mm[1]), JSON_UNESCAPED_UNICODE)];
    }
    return ['code' => 404, 'body' => '{}'];
};

$c = BizMoadianLive::check($u);
T::ok($c['ok'] && str_contains($c['message'], 'فروشگاهِ آزمون'), 'آزمونِ اتصال: توکن، کلیدِ سازمان و نامِ مودی', $c['message']);

BizProducts::save($u, ['type' => 'service', 'name' => 'شارژر', 'unit' => 'عدد', 'buy_price' => '50', 'sell_price' => '100']);
$doc = function (string $kind, array $lines) use ($u, $acc): int {
    $r = BizInvoices::saveDraft($u, $kind, ['lines' => $lines]);
    $i = BizInvoices::issue($u, (int)$r['id'], ['full' => true, 'account_id' => $acc]);
    return $i['ok'] ? (int)$r['id'] : 0;
};
$S = $doc('sale', [['item' => 'شارژر', 'qty' => '2', 'price' => '100']]);
$r = BizMoadianLive::send($u, $S, false, $u);
T::ok($r['ok'] && str_contains($r['message'], 'REF-1'), 'صورتحساب فرستاده شد با شماره‌ی پیگیری', $r['message']);
$sent = end($srv['sent']);
T::ok(is_array($sent['inv']), '⛔ سامانه JWE را باز و JWS را با گواهیِ فروشگاه سنجید');
T::same(['fiscalId' => MEM, 'kid' => 'org-kid'], ['fiscalId' => $sent['packet_header']['fiscalId'] ?? null, 'kid' => $sent['kid']], 'fiscalId = شناسه‌ی حافظه؛ kid = کلیدِ سازمان');
T::ok(preg_match('/^[0-9a-f-]{36}$/', (string)($sent['packet_header']['requestTraceId'] ?? '')) === 1, 'requestTraceId یک UUID است');
$tax = (string)($sent['inv']['header']['taxid'] ?? '');
T::ok(str_starts_with($tax, MEM) && strlen($tax) === 22 && $tax === BizMoadian::taxId(MEM, date('Y-m-d'), $S), '⛔ شماره‌ی مالیاتی با شناسه‌ی واقعیِ حافظه (نه XXXXXX)', $tax);
T::same([1, 2200, 2000, 200], [$sent['inv']['header']['ins'] ?? null, $sent['inv']['header']['tbill'] ?? null, $sent['inv']['header']['tadis'] ?? null, $sent['inv']['header']['tvam'] ?? null],
    'ins=۱، مبلغ‌ها به ریال با مالیات ۱۰٪');
T::ok(is_array($sent['inv']['header'] ?? null) && !array_key_exists('irtaxid', $sent['inv']['header']), 'فیلدِ خالی (irtaxid) فرستاده نمی‌شود');
$row = BizMoadianLive::last($u, $S);
T::same(['sent', 'REF-1', $tax], [$row['status'], $row['reference'], $row['taxid']], 'تلاش ثبت شد: فرستاده، شماره‌ی پیگیری، taxid');
$r = BizMoadianLive::send($u, $S);
T::ok(!$r['ok'] && str_contains($r['message'], 'استعلام'), '⛔ تا نتیجه نیامده دوباره فرستاده نمی‌شود', $r['message']);
$n = count($srv['sent']);
$q = BizMoadianLive::inquire($u);
T::ok($q['ok'] && $q['done'] === 1 && BizMoadianLive::last($u, $S)['status'] === 'success', 'استعلام ⇒ ثبت شد', $q['message']);
$r = BizMoadianLive::send($u, $S);
T::ok(!$r['ok'] && count($srv['sent']) === $n, '⛔ صورتحسابِ ثبت‌شده دوباره نمی‌رود');

T::group('۴ — برگشت و ابطال');
$inv = BizInvoices::get($u, $S);
$ret = BizInvoices::createReturn($u, $S, [(int)$inv['lines'][0]['id'] => '1'], ['full' => true, 'account_id' => $acc]);
T::ok($ret['ok'], 'برگشت از فروش', $ret['message'] ?? '');
$r = BizMoadianLive::send($u, (int)$ret['id']);
$sentR = end($srv['sent']);
T::ok($r['ok'] && ($sentR['inv']['header']['ins'] ?? null) === 4 && ($sentR['inv']['header']['irtaxid'] ?? null) === $tax,
    '⛔ برگشت: ins=۴ و irtaxid = شماره‌ی مالیاتیِ ثبت‌شده‌ی اصل', json_encode($sentR['inv']['header'] ?? null));
// فاکتوری که اصلش ثبت نشده برگشتش فرستاده نمی‌شود
$S2 = $doc('sale', [['item' => 'شارژر', 'qty' => '1', 'price' => '100']]);
$inv2 = BizInvoices::get($u, $S2);
$ret2 = BizInvoices::createReturn($u, $S2, [(int)$inv2['lines'][0]['id'] => '1'], ['full' => true, 'account_id' => $acc]);
$r = BizMoadianLive::send($u, (int)$ret2['id']);
T::ok(!$r['ok'] && str_contains($r['message'], 'اصل'), '⛔ برگشتِ فاکتورِ ثبت‌نشده فرستاده نمی‌شود', $r['message']);

// ابطال در حسابداری
$S3 = $doc('sale', [['item' => 'شارژر', 'qty' => '3', 'price' => '100']]);
BizMoadianLive::send($u, $S3); BizMoadianLive::inquire($u);
T::same('success', BizMoadianLive::last($u, $S3)['status'], 'S3 ثبت شد');
T::ok(!BizMoadianLive::needsCancel($u, $S3), 'سندِ معتبر ابطالیه نمی‌خواهد');
$r = BizMoadianLive::send($u, $S3, true);
T::ok(!$r['ok'], 'ابطالیه‌ی سندی که در حسابداری باطل نشده رد (صورتحساب ساخته نمی‌شود)');
$v = BizInvoices::void($u, $S3);
T::ok($v['ok'], 'S3 در حسابداری باطل شد', $v['message']);
T::ok(BizMoadianLive::needsCancel($u, $S3) && in_array($S3, array_column(BizMoadianLive::pendingCancels($u), 'id')), '⛔ باطل‌شده‌ی ثبت‌شده در فهرستِ ابطالیه');
$r = BizMoadianLive::send($u, $S3, true);
$sentC = end($srv['sent']);
$tax3 = (string)BizMoadianLive::last($u, $S3, 1)['taxid'];
T::ok($r['ok'] && ($sentC['inv']['header']['ins'] ?? null) === 3 && ($sentC['inv']['header']['irtaxid'] ?? null) === $tax3
    && ($sentC['inv']['header']['taxid'] ?? '') !== $tax3, '⛔ ابطالیه: ins=۳، irtaxid = اصل، شماره‌ی مالیاتیِ تازه', json_encode($sentC['inv']['header'] ?? null));
T::ok(!BizMoadianLive::needsCancel($u, $S3), 'بعد از فرستادنِ ابطالیه دیگر در فهرست نیست');
$r = BizMoadianLive::send($u, $S3, true);
T::ok(!$r['ok'], '⛔ ابطالیه دو بار نمی‌رود');

T::group('۵ — خطا و سد');
$S4 = $doc('sale', [['item' => 'شارژر', 'qty' => '1', 'price' => '100']]);
$srv['inv_reply'] = ['code' => 200, 'body' => json_encode(['result' => [['uid' => 'x', 'referenceNumber' => null, 'errorCode' => 'INV-17', 'errorDetail' => 'تاریخِ صورتحساب نامعتبر']]], JSON_UNESCAPED_UNICODE)];
$r = BizMoadianLive::send($u, $S4);
$l4 = BizMoadianLive::last($u, $S4);
T::ok(!$r['ok'] && $l4['status'] === 'error' && str_contains((string)$l4['message'], 'تاریخِ صورتحساب نامعتبر'), '⛔ خطای سامانه با متنِ خودش ثبت می‌شود', $r['message']);
$r = BizMoadianLive::send($u, $S4);
T::ok($r['ok'], 'بعد از «نرسید» دوباره فرستادنی است', $r['message']);
$srv['inquiry'] = 'FAILED';
BizMoadianLive::inquire($u, $S4);
$l4 = BizMoadianLive::last($u, $S4);
T::ok($l4['status'] === 'failed' && str_contains((string)$l4['message'], 'کدِ کالا نامعتبر'), 'استعلامِ «رد شد» با دلیلِ سامانه', (string)$l4['message']);
BizMoadianLive::$httpForTests = null;
$r = BizMoadianLive::check($u);
T::ok(!$r['ok'] && str_contains($r['message'], 'HESAB_NO_SEND'), '⛔ بی‌سامانه‌ی ساختگی، تست هرگز به سامانه‌ی واقعی نمی‌رود', $r['message']);
BizMoadianLive::saveConfig($u, ['moadian_env' => 'sandbox', 'moadian_on' => 0]);
$r = BizMoadianLive::send($u, $S2);
T::ok(!$r['ok'] && str_contains($r['message'], 'خاموش'), 'ارسالِ خاموش هیچ کاری نمی‌کند', $r['message']);

T::group('۶ — کلید در بکاپ نیست');
$ex = exportUserData($u);
$bs = $ex['tables']['biz_settings'][0] ?? [];
T::ok($bs !== [] && !array_key_exists('moadian_key', $bs) && !array_key_exists('moadian_cert', $bs) && array_key_exists('moadian_memory_id', $bs),
    '⛔ خروجیِ کاربر کلید و گواهی ندارد (شناسه‌ی حافظه دارد)');
T::ok(str_contains((string)file_get_contents(__DIR__ . '/../includes/user_import.php'), 'foreach (USER_DATA_SECRET_COLUMNS as $c) { unset($write[$c]); }'),
    '⛔ و از فایل نوشته نمی‌شود');
$r = BizMoadianLive::saveConfig($u, ['clear_key' => 1, 'moadian_env' => 'sandbox']);
T::ok($r['ok'] && !BizMoadianLive::config($u)['has_key'] && !BizMoadianLive::config($u)['on'], 'پاک کردنِ کلید ارسال را هم خاموش می‌کند');

array_map('unlink', glob("{$tmp}/*") ?: []); @rmdir($tmp);
$wipe();
exit(T::report());
