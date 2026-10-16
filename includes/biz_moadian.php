<?php
/**
 * ⛔ ارسالِ مستقیمِ صورتحساب به سامانه‌ی مودیان — API نسخه‌ی ۲ (`requestsmanager/api/v2`).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵، بعد از مقایسه با «قیاس»):** «پیش‌ساخت برای
 * مشتریان» — در محصول آماده، و **خاموش** تا هر فروشگاه کلید و گواهیِ خودش را بگذارد.
 *
 * صورتحساب همان `BizMoadian::payload()` است (یک سازنده، نه دومی). این‌جا فقط پروتکل:
 *   ۱. توکن: `GET /nonce` ⇒ JWS (RS256) روی `{nonce, clientId}` با کلیدِ خصوصیِ فروشگاه،
 *      سرآیندِ `x5c` (گواهی)، `sigT` و `crit: [sigT]`.
 *   ۲. کلیدِ عمومیِ سازمان: `GET /server-information` (RSA).
 *   ۳. هر صورتحساب: JWS روی `{header, body, payments}`، بعد JWE با RSA-OAEP-256 +
 *      A256GCM برای کلیدِ سازمان؛ `POST /invoice` با `requestTraceId` و `fiscalId`.
 *   ۴. وضعیت: `GET /inquiry-by-reference-id`.
 *
 * ⛔ **هرگز بی‌صدا:** هر تلاش یک ردیفِ `biz_moadian_sends` با پاسخِ خودِ سامانه.
 * ⛔ کلیدِ خصوصی فقط رمزشده (`Crypto`)؛ بی‌رمزنگاریِ نصب ذخیره نمی‌شود؛ در خروجیِ
 *    کاربر نیست (`USER_DATA_SECRET_COLUMNS`).
 * ⛔ تست‌ها هرگز به سامانه‌ی واقعی نمی‌روند (`Outbound::blockedFor()`)؛ رمزنگاری با
 *    کلیدِ آزمایشی و OpenSSL سنجیده می‌شود (`test_store_moadian_live`).
 * ⚠ با سامانه‌ی واقعی آزموده نشده است: نخستین ارسالِ هر فروشگاه در محیطِ آزمایشی
 *   (`sandbox`) است و صفحه همین را می‌گوید.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/outbound.php';

final class BizMoadianLive
{
    public const ENVS = [
        'sandbox' => ['label' => 'آزمایشی (sandbox)', 'url' => 'https://sandboxrc.tax.gov.ir/requestsmanager/api/v2'],
        'live'    => ['label' => 'اصلی',               'url' => 'https://tp.tax.gov.ir/requestsmanager/api/v2'],
    ];

    /** وضعیتِ هر تلاش — `sent` تا استعلام، بعد یکی از سه پایانی. */
    public const STATUSES = [
        'sent'    => 'فرستاده شد — منتظرِ نتیجه',
        'success' => 'ثبت شد',
        'failed'  => 'رد شد',
        'error'   => 'نرسید',
    ];

    /** نوعِ موضوعِ صورتحسابِ ابطالی. */
    public const SUBJECT_CANCEL = 3;

    /**
     * ⛔ سریالِ صورتحسابِ ابطالی جدا از اصل: شماره‌ی مالیاتی (taxid) یکتاست و اصل
     *    همان شناسه‌ی سند را دارد. ۲^۳۶ هنوز در ده رقمِ هگزِ taxid جا می‌شود.
     */
    public const CANCEL_SERIAL_OFFSET = 68719476736;

    /** فقط برای تست: جایگزینِ HTTP (`fn(string $method, string $url, array $headers, ?string $body): array{code:int, body:string}`). */
    public static ?Closure $httpForTests = null;

    public static function ready(): bool
    {
        return function_exists('tableHasColumn') && tableHasColumn('biz_settings', 'moadian_key') && tableHasColumn('biz_moadian_sends', 'id');
    }

    /* ---------------------------------------------------------- تنظیمات */

    /** @return array{on:bool, env:string, has_key:bool, cert:string, memory_id:string} */
    public static function config(int $userId): array
    {
        $st = Database::getConnection()->prepare('SELECT moadian_on, moadian_env, moadian_key IS NOT NULL AS has_key, moadian_cert, moadian_memory_id FROM biz_settings WHERE user_id = :u');
        $st->execute(['u' => $userId]);
        $r = $st->fetch() ?: [];
        return ['on' => (int)($r['moadian_on'] ?? 0) === 1, 'env' => isset(self::ENVS[$r['moadian_env'] ?? '']) ? (string)$r['moadian_env'] : 'sandbox',
                'has_key' => (int)($r['has_key'] ?? 0) === 1, 'cert' => (string)($r['moadian_cert'] ?? ''), 'memory_id' => (string)($r['moadian_memory_id'] ?? '')];
    }

    /** کلیدِ خصوصیِ بازشده، یا خالی. ⛔ هرگز به صفحه یا لاگ نمی‌رود. */
    private static function privateKey(int $userId): string
    {
        $st = Database::getConnection()->prepare('SELECT moadian_key FROM biz_settings WHERE user_id = :u');
        $st->execute(['u' => $userId]);
        return (string)(Crypto::decrypt((string)($st->fetchColumn() ?: '')) ?? '');
    }

    /**
     * ⛔ تنها نویسنده‌ی تنظیماتِ ارسال. کلید و گواهی با هم سنجیده می‌شوند (گواهیِ مالِ
     *    همین کلید)؛ کلیدِ خالی = دست نزن (همان قاعده‌ی رمزِ ایمیل)، `clear_key` = پاک.
     * @return array{ok:bool, message:string}
     */
    public static function saveConfig(int $userId, array $in): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است (migration_biz_moadian).']; }
        $env = (string)($in['moadian_env'] ?? 'sandbox');
        if (!isset(self::ENVS[$env])) { return ['ok' => false, 'message' => 'محیطِ ارسال نامعتبر است.']; }
        $on   = !empty($in['moadian_on']);
        $key  = trim((string)($in['moadian_key'] ?? ''));
        $cert = trim((string)($in['moadian_cert'] ?? ''));
        $cur  = self::config($userId);
        $set = ['moadian_on = :on', 'moadian_env = :env']; $par = ['u' => $userId, 'on' => $on ? 1 : 0, 'env' => $env];

        if (!empty($in['clear_key'])) {
            $set[] = 'moadian_key = NULL'; $set[] = 'moadian_cert = NULL'; $set[] = 'moadian_on = 0';
        } else {
            if ($key !== '' || $cert !== '') {
                $certPem = $cert !== '' ? self::pem($cert, 'CERTIFICATE') : $cur['cert'];
                $keyPem  = $key !== '' ? self::pem($key, 'PRIVATE KEY') : self::privateKey($userId);
                if ($keyPem === '' || $certPem === '') { return ['ok' => false, 'message' => 'کلیدِ خصوصی و گواهی هر دو لازم‌اند.']; }
                $pk = @openssl_pkey_get_private($keyPem);
                if ($pk === false) { return ['ok' => false, 'message' => 'کلیدِ خصوصی خوانده نشد (PEM بی‌رمز، از همان فایلِ ‎.key).']; }
                $det = openssl_pkey_get_details($pk);
                if (($det['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA) { return ['ok' => false, 'message' => 'کلید باید RSA باشد.']; }
                $x = @openssl_x509_read($certPem);
                if ($x === false) { return ['ok' => false, 'message' => 'گواهی خوانده نشد (فایلِ ‎.crt یا ‎.cer که سازمان داده).']; }
                if (!openssl_x509_check_private_key($x, $pk)) { return ['ok' => false, 'message' => '⛔ این گواهی مالِ این کلید نیست.']; }
                if ($key !== '') {
                    if (!Crypto::available()) { return ['ok' => false, 'message' => 'رمزنگاریِ نصب روشن نیست؛ کلیدِ خصوصی بی‌رمز ذخیره نمی‌شود — مدیرِ نصب.']; }
                    $set[] = 'moadian_key = :k'; $par['k'] = Crypto::encrypt($keyPem);
                }
                if ($cert !== '') { $set[] = 'moadian_cert = :c'; $par['c'] = $certPem; }
            }
            if ($on && !$cur['has_key'] && $key === '') { return ['ok' => false, 'message' => 'برای روشن کردن، اول کلید و گواهی را بگذارید.']; }
            if ($on && $cur['memory_id'] === '') { return ['ok' => false, 'message' => 'اول «شناسه‌ی یکتای حافظه‌ی مالیاتی» را بالاتر ثبت کنید.']; }
        }
        Database::getConnection()->prepare('INSERT INTO biz_settings (user_id) VALUES (:u) ON DUPLICATE KEY UPDATE user_id = user_id')->execute(['u' => $userId]);
        Database::getConnection()->prepare('UPDATE biz_settings SET ' . implode(', ', $set) . ' WHERE user_id = :u')->execute($par);
        if (class_exists('Biz')) { Biz::forgetSettings($userId); }
        if (!empty($in['clear_key'])) { return ['ok' => true, 'message' => 'کلید و گواهیِ مودیان پاک شد و ارسال خاموش است.']; }
        return ['ok' => true, 'message' => $on ? 'ارسالِ مستقیم به مودیان (' . self::ENVS[$env]['label'] . ') روشن است.' : 'تنظیماتِ مودیان ذخیره شد؛ ارسالِ مستقیم خاموش است.'];
    }

    /** متنِ PEM یا base64ِ خام ⇒ PEMِ درست با برچسب. */
    public static function pem(string $text, string $label): string
    {
        $text = trim($text);
        if (str_contains($text, '-----BEGIN')) { return $text . "\n"; }
        $b = preg_replace('/\s+/', '', $text);
        return "-----BEGIN {$label}-----\n" . chunk_split((string)$b, 64, "\n") . "-----END {$label}-----\n";
    }

    /* ---------------------------------------------------------- JOSE */

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    private static function json(array $v): string
    {
        return (string)json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** سرآیندِ JWSِ سامانه: RS256، گواهیِ DER در `x5c`، زمانِ امضا (`sigT`) بحرانی. */
    public static function jwsHeader(string $certPem, ?string $sigT = null): array
    {
        $der = preg_replace('/-----[^-]+-----|\s+/', '', $certPem);
        return ['alg' => 'RS256', 'typ' => 'jose', 'x5c' => [(string)$der],
                'sigT' => $sigT ?? gmdate('Y-m-d\TH:i:s\Z'), 'crit' => ['sigT'], 'cty' => 'text/plain'];
    }

    /** JWSِ فشرده (RS256). */
    public static function jws(array $header, array $payload, string $privateKeyPem): string
    {
        $input = self::b64u(self::json($header)) . '.' . self::b64u(self::json($payload));
        $sig = '';
        if (!openssl_sign($input, $sig, $privateKeyPem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('امضای صورتحساب انجام نشد: ' . (string)openssl_error_string());
        }
        return $input . '.' . self::b64u($sig);
    }

    /**
     * ⛔ RSA-OAEP با SHA-256 (هم هش هم MGF1). `openssl_public_encrypt` فقط OAEPِ SHA-1
     *    دارد، پس کدگذاریِ OAEP این‌جا (RFC 8017، ۷.۱.۱) و RSAِ بی‌لایه از OpenSSL.
     */
    public static function oaepSha256Encrypt(string $data, string $publicKeyPem): string
    {
        $pub = openssl_pkey_get_public($publicKeyPem);
        if ($pub === false) { throw new RuntimeException('کلیدِ عمومیِ سازمان خوانده نشد.'); }
        $k = intdiv((int)openssl_pkey_get_details($pub)['bits'] + 7, 8);
        $hLen = 32;
        if (strlen($data) > $k - 2 * $hLen - 2) { throw new RuntimeException('داده برای OAEP بلند است.'); }
        $lHash = hash('sha256', '', true);
        $db = $lHash . str_repeat("\0", $k - strlen($data) - 2 * $hLen - 2) . "\x01" . $data;
        $seed = random_bytes($hLen);
        $maskedDb = $db ^ self::mgf1($seed, $k - $hLen - 1);
        $maskedSeed = $seed ^ self::mgf1($maskedDb, $hLen);
        $em = "\0" . $maskedSeed . $maskedDb;
        $out = '';
        if (!openssl_public_encrypt($em, $out, $pub, OPENSSL_NO_PADDING)) {
            throw new RuntimeException('رمزگذاریِ کلید انجام نشد: ' . (string)openssl_error_string());
        }
        return $out;
    }

    private static function mgf1(string $seed, int $len): string
    {
        $t = '';
        for ($c = 0; strlen($t) < $len; $c++) { $t .= hash('sha256', $seed . pack('N', $c), true); }
        return substr($t, 0, $len);
    }

    /** JWEِ فشرده: RSA-OAEP-256 برای کلیدِ محتوا، A256GCM برای متن؛ AAD = سرآیندِ کدشده. */
    public static function jwe(string $plain, string $publicKeyPem, string $kid): string
    {
        $h = self::b64u(self::json(['alg' => 'RSA-OAEP-256', 'enc' => 'A256GCM', 'kid' => $kid]));
        $cek = random_bytes(32);
        $iv  = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, $iv, $tag, $h, 16);
        if ($ct === false) { throw new RuntimeException('رمزگذاریِ صورتحساب انجام نشد.'); }
        return $h . '.' . self::b64u(self::oaepSha256Encrypt($cek, $publicKeyPem)) . '.' . self::b64u($iv) . '.' . self::b64u($ct) . '.' . self::b64u($tag);
    }

    /* ---------------------------------------------------------- HTTP */

    /** @return array{code:int, body:string, json:?array, error:string} */
    private static function http(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (self::$httpForTests !== null && PHP_SAPI === 'cli') {
            $r = (self::$httpForTests)($method, $url, $headers, $body);
            return ['code' => (int)$r['code'], 'body' => (string)$r['body'], 'json' => json_decode((string)$r['body'], true, 512, JSON_BIGINT_AS_STRING) ?: null, 'error' => ''];
        }
        if (($e = Outbound::blockedFor($url, 'صورتحسابِ مودیان')) !== null) { return ['code' => 0, 'body' => '', 'json' => null, 'error' => $e]; }
        $ch = curl_init($url);
        $h = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
                                CURLOPT_HTTPHEADER => $h, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false,
                                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $res === false ? curl_error($ch) : '';
        unset($ch);
        $res = (string)$res;
        return ['code' => $code, 'body' => $res, 'json' => json_decode($res, true, 512, JSON_BIGINT_AS_STRING) ?: null, 'error' => $err];
    }

    /** پیامِ خطای خوانا از یک پاسخ — بی‌هیچ داده‌ی حساس (کلید هرگز در پاسخ نیست). */
    private static function httpError(array $r): string
    {
        if ($r['error'] !== '') { return 'به سامانه وصل نشد: ' . $r['error']; }
        $j = $r['json'] ?? [];
        $m = $j['message'] ?? $j['title'] ?? ($j['errors'][0]['message'] ?? $j['errors'][0]['msg'] ?? null);
        return 'پاسخِ ' . $r['code'] . ' از سامانه' . ($m !== null ? ': ' . mb_substr((string)(is_scalar($m) ? $m : json_encode($m, JSON_UNESCAPED_UNICODE)), 0, 300) : '');
    }

    /* ---------------------------------------------------------- جلسه */

    /**
     * توکن و کلیدِ عمومیِ سازمان برای یک فروشگاه.
     * @return array{ok:bool, message?:string, token?:string, key?:string, kid?:string, base?:string, cfg?:array, pk?:string}
     */
    private static function session(int $userId): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'ارسالِ مستقیم راه نیفتاده است (migration_biz_moadian).']; }
        $cfg = self::config($userId);
        if (!$cfg['on']) { return ['ok' => false, 'message' => 'ارسالِ مستقیم به مودیان خاموش است (تنظیماتِ فروشگاه ← مالیات).']; }
        $pk = self::privateKey($userId);
        if ($pk === '' || $cfg['cert'] === '' || $cfg['memory_id'] === '') { return ['ok' => false, 'message' => 'کلید، گواهی یا شناسه‌ی حافظه ثبت نشده است.']; }
        $base = self::ENVS[$cfg['env']]['url'];
        $n = self::http('GET', $base . '/nonce?timeToLive=60');
        $nonce = (string)($n['json']['nonce'] ?? '');
        if ($n['code'] !== 200 || $nonce === '') { return ['ok' => false, 'message' => 'نانس گرفته نشد — ' . self::httpError($n)]; }
        $token = self::jws(self::jwsHeader($cfg['cert']), ['nonce' => $nonce, 'clientId' => $cfg['memory_id']], $pk);
        $s = self::http('GET', $base . '/server-information', ['Authorization: Bearer ' . $token]);
        $key = null;
        foreach ((array)($s['json']['publicKeys'] ?? []) as $k) {
            if (strtoupper((string)($k['algorithm'] ?? '')) === 'RSA' && !empty($k['key'])) { $key = $k; break; }
        }
        if ($s['code'] !== 200 || $key === null) { return ['ok' => false, 'message' => 'کلیدِ سازمان گرفته نشد — ' . self::httpError($s)]; }
        return ['ok' => true, 'token' => $token, 'key' => self::pem((string)$key['key'], 'PUBLIC KEY'), 'kid' => (string)($key['id'] ?? ''),
                'base' => $base, 'cfg' => $cfg, 'pk' => $pk];
    }

    /**
     * آزمونِ اتصال: توکن، کلیدِ سازمان، و اطلاعاتِ حافظه‌ی مالیاتی (نامِ مودی).
     * @return array{ok:bool, message:string}
     */
    public static function check(int $userId): array
    {
        $s = self::session($userId);
        if (!$s['ok']) { return ['ok' => false, 'message' => $s['message']]; }
        $f = self::http('GET', $s['base'] . '/fiscal-information?memoryId=' . rawurlencode($s['cfg']['memory_id']), ['Authorization: Bearer ' . $s['token']]);
        if ($f['code'] !== 200) { return ['ok' => false, 'message' => 'توکن و کلید درست‌اند ولی اطلاعاتِ حافظه نیامد — ' . self::httpError($f)]; }
        $name = (string)($f['json']['nameTrade'] ?? $f['json']['name'] ?? '');
        return ['ok' => true, 'message' => 'اتصال به سامانه‌ی مودیان (' . self::ENVS[$s['cfg']['env']]['label'] . ') درست است' . ($name !== '' ? ' — «' . $name . '»' : '') . '.'];
    }

    /* ---------------------------------------------------------- ارسال */

    /** آخرین تلاشِ یک سند (یا null). */
    public static function last(int $userId, int $invoiceId, ?int $subject = null): ?array
    {
        if (!self::ready()) { return null; }
        $st = Database::getConnection()->prepare('SELECT * FROM biz_moadian_sends WHERE user_id = :u AND invoice_id = :i'
            . ($subject !== null ? ' AND subject = :s' : '') . ' ORDER BY id DESC LIMIT 1');
        $st->execute(['u' => $userId, 'i' => $invoiceId] + ($subject !== null ? ['s' => $subject] : []));
        return $st->fetch() ?: null;
    }

    /** آخرین وضعیتِ هر سند — برای فهرستِ صفحه، یک کوئری. @return array<int,array> */
    public static function lastFor(int $userId, array $invoiceIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $invoiceIds)));
        if (!self::ready() || !$ids) { return []; }
        $in = implode(',', $ids);   // ⚠ intval شده — امن
        $st = Database::getConnection()->prepare("SELECT s.* FROM biz_moadian_sends s
            JOIN (SELECT invoice_id, MAX(id) AS id FROM biz_moadian_sends WHERE user_id = :u AND invoice_id IN ({$in}) GROUP BY invoice_id) m ON m.id = s.id");
        $st->execute(['u' => $userId]);
        $out = [];
        foreach ($st->fetchAll() as $r) { $out[(int)$r['invoice_id']] = $r; }
        return $out;
    }

    /** سندی که در سامانه ثبت شده (اصل یا برگشت) و ابطالیه‌ی فرستاده/ثبت‌شده ندارد؟ */
    public static function needsCancel(int $userId, int $invoiceId): bool
    {
        if (!self::ready()) { return false; }
        // ⛔ فقط سندی که در حسابداری **باطل** شده؛ سندِ معتبر ابطالیه نمی‌خواهد
        $v = Database::getConnection()->prepare("SELECT 1 FROM biz_invoices WHERE id = :i AND user_id = :u AND status = 'void'");
        $v->execute(['i' => $invoiceId, 'u' => $userId]);
        if (!$v->fetchColumn()) { return false; }
        $st = Database::getConnection()->prepare('SELECT subject, status FROM biz_moadian_sends WHERE user_id = :u AND invoice_id = :i ORDER BY id');
        $st->execute(['u' => $userId, 'i' => $invoiceId]);
        $registered = false; $cancelled = false;
        foreach ($st->fetchAll() as $r) {
            if ((int)$r['subject'] === self::SUBJECT_CANCEL) { if (in_array($r['status'], ['sent', 'success'], true)) { $cancelled = true; } }
            elseif ($r['status'] === 'success') { $registered = true; }
        }
        return $registered && !$cancelled;
    }

    /**
     * سندهای **باطل‌شده در حسابداری** که در سامانه ثبت مانده‌اند — برای فهرستِ ابطالیه.
     * @return list<array{id:int, kind:string, number:mixed, inv_date:string, total:int}>
     */
    public static function pendingCancels(int $userId): array
    {
        if (!self::ready()) { return []; }
        $st = Database::getConnection()->prepare("SELECT DISTINCT i.id, i.kind, i.number, i.inv_date, i.total FROM biz_moadian_sends s
            JOIN biz_invoices i ON i.id = s.invoice_id AND i.user_id = s.user_id AND i.status = 'void'
            WHERE s.user_id = :u AND s.status = 'success' AND s.subject <> :c ORDER BY i.inv_date, i.id LIMIT 200");
        $st->execute(['u' => $userId, 'c' => self::SUBJECT_CANCEL]);
        return array_values(array_filter($st->fetchAll(), fn($r) => self::needsCancel($userId, (int)$r['id'])));
    }

    /**
     * ⛔ ارسالِ یک سند. `cancel` = صورتحسابِ ابطالیِ سندی که قبلاً **ثبت شده** (ins=3).
     *    سندی که ثبت شده دوباره فرستاده نمی‌شود؛ سندِ «فرستاده‌شده» تا روشن شدنِ
     *    نتیجه هم نه (دوبار ثبت در سامانه = دو صورتحساب).
     * @return array{ok:bool, message:string}
     */
    public static function send(int $userId, int $invoiceId, bool $cancel = false, ?int $actorId = null): array
    {
        $p = BizMoadian::payload($userId, $invoiceId, $cancel);
        if (!$p['ok']) { return ['ok' => false, 'message' => $p['message'] ?? 'صورتحساب ساخته نشد.']; }
        if ($p['problems']) { return ['ok' => false, 'message' => 'پیش از ارسال: ' . implode(' ', $p['problems'])]; }
        $inv = $p['invoice'];
        $orig = self::last($userId, $invoiceId, (int)$inv['header']['ins']);
        if ($cancel) {
            if (!$orig || $orig['status'] !== 'success') { return ['ok' => false, 'message' => 'فقط صورتحسابی که در سامانه ثبت شده ابطالیه می‌گیرد.']; }
            if (!self::needsCancel($userId, $invoiceId)) { return ['ok' => false, 'message' => 'ابطالیه فقط برای سندی است که در حسابداری باطل شده و ابطالیه‌اش هنوز نرفته.']; }
            $prev = self::last($userId, $invoiceId, self::SUBJECT_CANCEL);
            if ($prev && in_array($prev['status'], ['sent', 'success'], true)) { return ['ok' => false, 'message' => 'ابطالیه‌ی این سند قبلاً فرستاده شده است — نتیجه را استعلام کنید.']; }
        } elseif ($orig && in_array($orig['status'], ['sent', 'success'], true)) {
            return ['ok' => false, 'message' => $orig['status'] === 'success' ? 'این صورتحساب در سامانه ثبت شده است.' : 'این صورتحساب فرستاده شده — اول نتیجه را استعلام کنید.'];
        }

        $s = self::session($userId);
        if (!$s['ok']) { return ['ok' => false, 'message' => $s['message']]; }
        $memory = $s['cfg']['memory_id'];
        // شماره‌ی مالیاتیِ اصل را `payload()` با همین شناسه‌ی حافظه ساخته (`session()` بی‌آن جلو نمی‌رود)
        if ($cancel) {
            $date = date('Y-m-d', intdiv((int)$inv['header']['indatim'], 1000));
            $inv['header']['irtaxid'] = (string)$orig['taxid'];
            $inv['header']['ins'] = self::SUBJECT_CANCEL;
            $inv['header']['taxid'] = BizMoadian::taxId($memory, $date, $invoiceId + self::CANCEL_SERIAL_OFFSET);
        } else {
            if ($inv['header']['irtaxid'] !== null) {   // برگشت از فروش: شماره‌ی مالیاتیِ ثبت‌شده‌ی اصل
                $ref = BizInvoices::get($userId, $invoiceId)['ref_invoice_id'] ?? null;
                $refSent = $ref !== null ? self::last($userId, (int)$ref, 1) : null;
                if (!$refSent || $refSent['status'] !== 'success') { return ['ok' => false, 'message' => 'اول خودِ فاکتورِ فروشِ اصل باید در سامانه ثبت شده باشد.']; }
                $inv['header']['irtaxid'] = (string)$refSent['taxid'];
            }
        }
        $inv['header'] = array_filter($inv['header'], fn($v) => $v !== null);

        $uid = self::uuid();
        $signed = self::jws(self::jwsHeader($s['cfg']['cert']), $inv, $s['pk']);
        $packet = [['payload' => self::jwe($signed, $s['key'], $s['kid']), 'header' => ['requestTraceId' => $uid, 'fiscalId' => $memory]]];
        $r = self::http('POST', $s['base'] . '/invoice', ['Authorization: Bearer ' . $s['token']], self::json($packet));

        $res = $r['json']['result'][0] ?? null;
        $ref = is_array($res) ? (string)($res['referenceNumber'] ?? '') : '';
        $err = is_array($res) ? ($res['errorDetail'] ?? $res['errorCode'] ?? null) : null;
        $status = ($r['code'] === 200 && $ref !== '' && $err === null) ? 'sent' : 'error';
        $msg = $status === 'sent' ? null : ($err !== null ? (is_scalar($err) ? (string)$err : json_encode($err, JSON_UNESCAPED_UNICODE)) : self::httpError($r));
        Database::getConnection()->prepare(
            'INSERT INTO biz_moadian_sends (user_id, invoice_id, subject, env, taxid, uid, reference, status, message, created_by)
             VALUES (:u, :i, :s, :e, :t, :uid, :r, :st, :m, :by)'
        )->execute(['u' => $userId, 'i' => $invoiceId, 's' => (int)$inv['header']['ins'], 'e' => $s['cfg']['env'],
                    't' => (string)$inv['header']['taxid'], 'uid' => $uid, 'r' => $ref !== '' ? $ref : null, 'st' => $status,
                    'm' => $msg !== null ? mb_substr($msg, 0, 2000) : null, 'by' => $actorId]);
        return $status === 'sent'
            ? ['ok' => true, 'message' => ($cancel ? 'ابطالیه' : 'صورتحساب') . ' فرستاده شد (شماره‌ی پیگیری ' . $ref . '). نتیجه را چند دقیقه بعد استعلام کنید.']
            : ['ok' => false, 'message' => 'صورتحساب پذیرفته نشد — ' . (string)$msg];
    }

    /**
     * استعلامِ نتیجه‌ی تلاش‌های «فرستاده‌شده»ِ یک فروشگاه (یا یک سند).
     * @return array{ok:bool, message:string, done:int}
     */
    public static function inquire(int $userId, int $invoiceId = 0): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش راه نیفتاده است.', 'done' => 0]; }
        $st = Database::getConnection()->prepare("SELECT * FROM biz_moadian_sends WHERE user_id = :u AND status = 'sent' AND reference IS NOT NULL"
            . ($invoiceId > 0 ? ' AND invoice_id = :i' : '') . ' ORDER BY id LIMIT 50');
        $st->execute(['u' => $userId] + ($invoiceId > 0 ? ['i' => $invoiceId] : []));
        $rows = $st->fetchAll();
        if (!$rows) { return ['ok' => true, 'message' => 'صورتحسابِ منتظرِ نتیجه‌ای نیست.', 'done' => 0]; }
        $s = self::session($userId);
        if (!$s['ok']) { return ['ok' => false, 'message' => $s['message'], 'done' => 0]; }
        $q = implode('&', array_map(fn($r) => 'referenceIds=' . rawurlencode((string)$r['reference']), $rows));
        $r = self::http('GET', $s['base'] . '/inquiry-by-reference-id?' . $q, ['Authorization: Bearer ' . $s['token']]);
        if ($r['code'] !== 200 || !is_array($r['json'])) { return ['ok' => false, 'message' => 'استعلام انجام نشد — ' . self::httpError($r), 'done' => 0]; }
        $byRef = [];
        foreach ((array)($r['json']['result'] ?? $r['json']) as $x) {
            if (is_array($x) && isset($x['referenceNumber'])) { $byRef[(string)$x['referenceNumber']] = $x; }
        }
        $upd = Database::getConnection()->prepare('UPDATE biz_moadian_sends SET status = :s, message = :m, checked_at = NOW() WHERE id = :id AND user_id = :u');
        $done = 0;
        foreach ($rows as $row) {
            $x = $byRef[(string)$row['reference']] ?? null;
            if ($x === null) { continue; }
            $state = strtoupper((string)($x['status'] ?? ''));
            $new = $state === 'SUCCESS' ? 'success' : ($state === 'FAILED' ? 'failed' : 'sent');
            $errs = [];
            foreach (['error', 'warning'] as $kind) {
                foreach ((array)($x['data'][$kind] ?? []) as $e) {
                    $errs[] = ($kind === 'warning' ? 'هشدار: ' : '') . (is_array($e) ? trim(($e['code'] ?? '') . ' ' . ($e['message'] ?? $e['msg'] ?? '')) : (string)$e);
                }
            }
            $upd->execute(['s' => $new, 'm' => $errs ? mb_substr(implode(' | ', $errs), 0, 2000) : null, 'id' => (int)$row['id'], 'u' => $userId]);
            if ($new !== 'sent') { $done++; }
        }
        return ['ok' => true, 'message' => toPersianDigits((string)$done) . ' از ' . toPersianDigits((string)count($rows)) . ' صورتحساب نتیجه گرفت.', 'done' => $done];
    }

    /** شناسه‌ی پیگیریِ تصادفی (UUID نسخه‌ی ۴). */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
