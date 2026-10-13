<?php
/**
 * اعلان روی گوشی (Web Push) — بی‌کتابخانه، فقط openssl.
 *
 * خواسته‌ی مالکِ نصب: «نوتیف داخل اپ روی آیکون و گوشی بیاد مثل سایر اپ‌ها».
 * مرکزِ اعلانِ داخلِ اپ از قبل بود؛ این فایل همان اعلان‌ها را به **سیستمِ
 * اعلانِ گوشی** می‌رساند، حتی وقتی اپ بسته است. عددِ روی آیکون (badge) هم
 * از همین مسیر و از `navigator.setAppBadge()` می‌آید.
 *
 * ⛔ چرا بی‌کتابخانه: این پروژه Composer ندارد. کلِ پروتکل سه تکه است و هر
 *    سه با توابعِ استانداردِ PHP ساخته می‌شوند:
 *      ۱. **VAPID** (RFC 8292): یک JWT با امضای ES256 روی منحنیِ P-256.
 *      ۲. **رمزنگاریِ محتوا** (RFC 8291 + aes128gcm از RFC 8188): ECDH با
 *         کلیدِ مرورگر، HKDF، و AES-128-GCM.
 *      ۳. یک POST به آدرسِ سرویسِ پوشِ همان مرورگر (FCM، موزیلا، اپل).
 *    `tests/test_push.php` هر سه را با یک «سرویسِ پوشِ ساختگی» می‌سنجد که
 *    بدنه را **رمزگشایی** می‌کند و امضا را با کلیدِ عمومی **وارسی** —
 *    یعنی «فرستاده شد» با «درست فرستاده شد» یکی گرفته نمی‌شود.
 *
 * ⛔ کلیدِ خصوصیِ VAPID در `var/push/vapid.json` است، **نه** در دیتابیس: در
 *    دامپِ بکاپ نمی‌آید (همان استدلالِ `APP_ENCRYPTION_KEY`). هزینه‌اش نوشته
 *    می‌ماند: با از دست رفتنِ سرور کلید هم می‌رود، اشتراک‌های قبلی ۴۱۰
 *    می‌گیرند و پاک می‌شوند، و هر کاربر یک بار دوباره «روشن» را می‌زند.
 *    `var/` از وب بسته است (`deploy/nginx-var.sh`).
 *
 * ⚠ کلید فقط از مسیرِ **وب** ساخته می‌شود (`publicKey()`)، که با کاربرِ
 *   `hesab` اجرا می‌شود. cron با root اجرا می‌شود و اگر فایل را بسازد،
 *   مالکش root می‌شد و FPM دیگر نمی‌توانست بخواندش — همان دامِ
 *   `var/sessions`. cron فقط می‌خواند؛ بی‌کلید یعنی هنوز هیچ اشتراکی
 *   ساخته نشده، پس چیزی برای فرستادن هم نیست.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/outbound.php';

final class Push
{
    /** عمرِ پیام در سرویسِ پوش؛ گوشیِ خاموش تا یک روز بعد هم می‌گیردش. */
    public const TTL = 86400;

    /** اعلانِ کهنه‌تر از این فرستاده نمی‌شود — هیچ‌کس «سررسیدِ دیروز» را نمی‌خواهد. */
    public const BACKLOG_HOURS = 24;

    /** بعد از این تعداد شکستِ پیاپی، اشتراک مرده شمرده و پاک می‌شود. */
    public const MAX_FAILS = 10;

    /**
     * ⛔ فقط سرویس‌های پوشِ مرورگرها. آدرسِ اشتراک را مرورگر می‌دهد ولی از
     *    راهِ یک درخواستِ کاربر به ما می‌رسد؛ بی‌این فهرست، هر کسی می‌توانست
     *    سرور را وادار کند به هر آدرسی (از جمله شبکه‌ی داخلی) POST بزند.
     */
    public const HOSTS = [
        'fcm.googleapis.com',                 // کروم، اج روی اندروید، TWA، سامسونگ
        'android.googleapis.com',
        'updates.push.services.mozilla.com',  // فایرفاکس
        'push.services.mozilla.com',
        'web.push.apple.com',                 // سافاری / PWAِ آیفون (iOS 16.4+)
        'notify.windows.com',                 // اج روی ویندوز (زیردامنه‌ها)
    ];

    public static function available(): bool
    {
        return function_exists('openssl_pkey_derive') && function_exists('hash_hkdf')
            && in_array('aes-128-gcm', openssl_get_cipher_methods(), true)
            && function_exists('tableExists') && tableExists('push_subscriptions');
    }

    public static function keyFile(): string
    {
        return defined('PUSH_VAPID_FILE') ? (string)PUSH_VAPID_FILE : dirname(__DIR__) . '/var/push/vapid.json';
    }

    /* ---------- base64url ---------- */
    public static function b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
    public static function unb64u(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) { $s .= str_repeat('=', 4 - $pad); }
        return (string)base64_decode($s, true);
    }

    /**
     * کلیدهای VAPID. `$create` فقط از مسیرِ وب (بالای فایل توضیح داده شده).
     * @return array{public:string, pem:string}|null
     */
    public static function vapid(bool $create = false): ?array
    {
        $f = self::keyFile();
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (is_array($j) && !empty($j['public']) && !empty($j['pem'])) { return $j; }
            return null;   // خراب؛ عمداً بازنویسی نمی‌شود (اشتراک‌های قبلی به همان بندند)
        }
        if (!$create) { return null; }

        $key = self::newP256();
        if ($key === null) { return null; }
        [$pem, $pub] = $key;
        $dir = dirname($f);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return null; }
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['public' => self::b64u($pub), 'pem' => $pem])) === false) { return null; }
        @chmod($tmp, 0600);
        // ⚠ اتمی، و اگر درخواستِ هم‌زمانی زودتر ساخته باشد همان برنده است.
        if (is_file($f)) { @unlink($tmp); } else { @rename($tmp, $f); }
        return self::vapid(false);
    }

    /** کلیدِ عمومی برای `pushManager.subscribe()`؛ `''` اگر ممکن نیست. */
    public static function publicKey(): string
    {
        $v = self::vapid(true);
        return $v['public'] ?? '';
    }

    /** @return array{0:string,1:string}|null [PEMِ خصوصی، کلیدِ عمومیِ ۶۵ بایتیِ فشرده‌نشده] */
    private static function newP256(): ?array
    {
        $k = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($k === false) { return null; }
        $d = openssl_pkey_get_details($k);
        if (!$d || empty($d['ec']['x']) || empty($d['ec']['y'])) { return null; }
        $pem = '';
        if (!openssl_pkey_export($k, $pem)) { return null; }
        $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        return [$pem, $pub];
    }

    /** کلیدِ عمومیِ ۶۵ بایتی → PEMِ SubjectPublicKeyInfo (برای openssl). */
    public static function pubToPem(string $pub65): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $pub65;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /* ---------- اشتراک ---------- */

    /** ⛔ فقط https و فقط میزبان‌های `HOSTS`. */
    public static function endpointAllowed(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) { return false; }
        $host = strtolower((string)$p['host']);
        foreach (self::HOSTS as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) { return true; }
        }
        return false;
    }

    /** @return array{ok:bool, message:string} */
    public static function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, string $label = ''): array
    {
        if ($userId <= 0 || !self::available()) { return ['ok' => false, 'message' => 'اعلان روی گوشی روی این سرور فعال نیست.']; }
        if (strlen($endpoint) > 700 || !self::endpointAllowed($endpoint)) {
            return ['ok' => false, 'message' => 'آدرسِ اشتراکِ این مرورگر پذیرفته نشد.'];
        }
        $pub = self::unb64u($p256dh);
        $sec = self::unb64u($auth);
        if (strlen($pub) !== 65 || $pub[0] !== "\x04" || strlen($sec) !== 16) {
            return ['ok' => false, 'message' => 'کلیدِ اشتراکِ این مرورگر نامعتبر است.'];
        }
        try {
            $pdo = Database::getConnection();
            // ⛔ همان endpoint اگر مالِ کاربرِ دیگری بود (گوشیِ مشترک، ورود با
            //    حسابِ دیگر) به کاربرِ فعلی منتقل می‌شود — وگرنه اعلانِ دفترِ
            //    یک نفر روی گوشیِ کسی می‌رفت که دیگر واردِ آن حساب نیست.
            $pdo->prepare('
                INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, device_label)
                VALUES (:u, :e, :h, :p, :a, :l)
                ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh),
                    auth = VALUES(auth), device_label = VALUES(device_label), fail_count = 0
            ')->execute([
                'u' => $userId, 'e' => $endpoint, 'h' => hash('sha256', $endpoint),
                'p' => $p256dh, 'a' => $auth, 'l' => $label === '' ? null : mb_substr($label, 0, 120),
            ]);
            // ⛔ پس‌مانده‌ی قبلی فرستاده نمی‌شود: کسی که همین حالا «روشن» را
            //    زده نباید با ده اعلانِ هفته‌ی پیش بمباران شود.
            if (tableHasColumn('notifications', 'pushed_at')) {
                $pdo->prepare('UPDATE notifications SET pushed_at = NOW() WHERE user_id = :u AND pushed_at IS NULL')
                    ->execute(['u' => $userId]);
            }
            return ['ok' => true, 'message' => 'اعلان روی این دستگاه روشن شد.'];
        } catch (Throwable $e) {
            Log::error('push.subscribe', $e);
            return ['ok' => false, 'message' => 'ذخیره‌ی اشتراک ممکن نشد.'];
        }
    }

    public static function unsubscribe(int $userId, string $endpoint): bool
    {
        if ($userId <= 0 || !self::available()) { return false; }
        try {
            $st = Database::getConnection()->prepare(
                'DELETE FROM push_subscriptions WHERE user_id = :u AND endpoint_hash = :h'
            );
            $st->execute(['u' => $userId, 'h' => hash('sha256', $endpoint)]);
            return true;
        } catch (Throwable $e) { return false; }
    }

    public static function countFor(int $userId): int
    {
        if ($userId <= 0 || !self::available()) { return 0; }
        try {
            $st = Database::getConnection()->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :u');
            $st->execute(['u' => $userId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /* ---------- رمزنگاری (RFC 8291، aes128gcm) ---------- */

    /**
     * بدنه‌ی رمزشده. ⚠ کلیدِ موقت و salt هر بار تازه‌اند؛ `$fixed` فقط
     * برای بردارِ آزمونِ RFC است.
     * @param array{0:string,1:string}|null $fixed [PEMِ خصوصیِ موقت، salt]
     */
    public static function encrypt(string $payload, string $uaPub65, string $authSecret, ?array $fixed = null): ?string
    {
        if ($fixed !== null) {
            $asKey = openssl_pkey_get_private($fixed[0]);
            $d = $asKey ? openssl_pkey_get_details($asKey) : false;
            if (!$d) { return null; }
            $asPub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
            $salt = $fixed[1];
        } else {
            $k = self::newP256();
            if ($k === null) { return null; }
            $asKey = openssl_pkey_get_private($k[0]);
            $asPub = $k[1];
            $salt  = random_bytes(16);
        }
        $peer = openssl_pkey_get_public(self::pubToPem($uaPub65));
        if (!$asKey || !$peer) { return null; }
        $ecdh = openssl_pkey_derive($peer, $asKey);
        if ($ecdh === false || strlen($ecdh) !== 32) { return null; }

        $ikm   = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPub65 . $asPub, $authSecret);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        // 0x02 = «آخرین رکورد»، بی‌لایه‌ی پرکن.
        $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) { return null; }
        return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
    }

    /* ---------- VAPID ---------- */

    public static function vapidAuth(string $endpoint, ?array $vapid = null): ?string
    {
        $vapid = $vapid ?? self::vapid(false);
        if ($vapid === null) { return null; }
        $p = parse_url($endpoint);
        $aud = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        $sub = defined('APP_URL') && preg_match('#^https://#', (string)APP_URL) ? rtrim((string)APP_URL, '/') : 'mailto:admin@hesab.stland.ir';
        $h = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = self::b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
        $der = '';
        if (!openssl_sign($h . '.' . $c, $der, $vapid['pem'], OPENSSL_ALGO_SHA256)) { return null; }
        $raw = self::derToRaw($der);
        if ($raw === null) { return null; }
        return 'vapid t=' . $h . '.' . $c . '.' . self::b64u($raw) . ', k=' . $vapid['public'];
    }

    /** امضای DER (SEQUENCE{INTEGER r, INTEGER s}) → r‖s ِ ۶۴ بایتی که JWT می‌خواهد. */
    public static function derToRaw(string $der): ?string
    {
        $o = 0;
        if (ord($der[$o++] ?? "\0") !== 0x30) { return null; }
        $len = ord($der[$o++]);
        if ($len & 0x80) { $o += $len & 0x7f; }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$o++] ?? "\0") !== 0x02) { return null; }
            $l = ord($der[$o++]);
            $n = ltrim(substr($der, $o, $l), "\0");
            $o += $l;
            if (strlen($n) > 32) { return null; }
            $out .= str_pad($n, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /* ---------- فرستادن ---------- */

    /** @return int کدِ HTTP؛ ۰ یعنی به سرویس نرسید. */
    public static function sendRaw(string $endpoint, string $body, string $auth, int $ttl = self::TTL): int
    {
        // ⛔ اجرای تست‌ها: هیچ اعلانی به سرویسِ واقعیِ مرورگر نمی‌رود (فقط `push_sink`ِ محلی)
        if (Outbound::blockedFor($endpoint, 'اعلان') !== null) { return 0; }
        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . $ttl,
            'Urgency: normal',
            'Authorization: ' . $auth,
        ];
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
                // ⚠ همان درسِ backup-offsite: مسیرِ IPv6ِ این VPS مرده است.
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);   // ⛔ نه `curl_close()` — از PHP 8.0 بی‌اثر و در 8.5 منسوخ
            return $code;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => 8, 'ignore_errors' => true,
        ]]);
        $r = @file_get_contents($endpoint, false, $ctx);
        $hdrs = Outbound::responseHeaders(get_defined_vars());
        if ($r === false && !$hdrs) { return 0; }
        return preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($hdrs[0] ?? ''), $m) ? (int)$m[1] : 0;
    }

    /**
     * یک پیام به همه‌ی دستگاه‌های یک کاربر.
     * @param array{title:string, body?:string, url?:string, tag?:string, badge?:int} $msg
     * @return array{sent:int, gone:int, failed:int}
     */
    public static function sendToUser(int $userId, array $msg): array
    {
        $res = ['sent' => 0, 'gone' => 0, 'failed' => 0];
        if ($userId <= 0 || !self::available()) { return $res; }
        $vapid = self::vapid(false);
        if ($vapid === null) { return $res; }
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = :u');
        $st->execute(['u' => $userId]);
        $payload = json_encode([
            'title' => mb_substr((string)($msg['title'] ?? ''), 0, 120),
            'body'  => mb_substr((string)($msg['body'] ?? ''), 0, 300),
            'url'   => (string)($msg['url'] ?? ''),
            'tag'   => (string)($msg['tag'] ?? 'hesab'),
            'badge' => (int)($msg['badge'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($st->fetchAll() as $s) {
            $body = self::encrypt($payload, self::unb64u($s['p256dh']), self::unb64u($s['auth']));
            $auth = self::vapidAuth($s['endpoint'], $vapid);
            if ($body === null || $auth === null) { $res['failed']++; continue; }
            $code = self::sendRaw($s['endpoint'], $body, $auth);
            if ($code >= 200 && $code < 300) {
                $res['sent']++;
                $pdo->prepare('UPDATE push_subscriptions SET last_ok_at = NOW(), fail_count = 0 WHERE id = :i')->execute(['i' => $s['id']]);
            } elseif ($code === 404 || $code === 410) {
                // ⛔ سرویس می‌گوید این اشتراک دیگر نیست (کاربر اجازه را پس
                //    گرفت، اپ پاک شد) — پاک می‌شود، وگرنه هر دقیقه دوباره.
                $res['gone']++;
                $pdo->prepare('DELETE FROM push_subscriptions WHERE id = :i')->execute(['i' => $s['id']]);
            } else {
                $res['failed']++;
                $pdo->prepare('UPDATE push_subscriptions SET fail_count = fail_count + 1 WHERE id = :i')->execute(['i' => $s['id']]);
                $pdo->prepare('DELETE FROM push_subscriptions WHERE id = :i AND fail_count >= :m')
                    ->execute(['i' => $s['id'], 'm' => self::MAX_FAILS]);
            }
        }
        return $res;
    }

    /**
     * ⛔ کارِ cron: اعلان‌های تازه‌ی نخوانده‌ی فرستاده‌نشده، کاربر به کاربر.
     *
     * - فقط `pushed_at IS NULL` و `read_at IS NULL` و تازه‌تر از
     *   `BACKLOG_HOURS` — اعلانی که کاربر همین حالا داخلِ اپ دیده، دوباره
     *   روی گوشی‌اش نمی‌آید.
     * - **یک** پیام برای هر کاربر در هر دور، نه یکی به‌ازای هر اعلان:
     *   صبحِ سه سررسید یعنی یک اعلانِ «۳ اعلانِ تازه»، نه سه زنگ.
     * - `pushed_at` بعد از تلاش زده می‌شود حتی اگر هیچ دستگاهی نگرفت؛ وگرنه
     *   اشتراکِ مرده هر دقیقه همان اعلان را دوباره امتحان می‌کرد. شکستِ
     *   **شبکه** (هیچ دستگاهی نرسید و هیچ‌کدام «رفته» نبود) استثناست و
     *   دورِ بعد دوباره امتحان می‌شود — تا سقفِ همان ۲۴ ساعت.
     *
     * @return array{users:int, sent:int, gone:int, failed:int}
     */
    public static function flushPending(): array
    {
        $out = ['users' => 0, 'sent' => 0, 'gone' => 0, 'failed' => 0];
        if (!self::available() || self::vapid(false) === null || !tableHasColumn('notifications', 'pushed_at')) { return $out; }
        $pdo = Database::getConnection();
        $rows = $pdo->query('
            SELECT n.id, n.user_id, n.title, n.body, n.link, n.kind
            FROM notifications n
            WHERE n.pushed_at IS NULL AND n.read_at IS NULL
              AND n.created_at >= NOW() - INTERVAL ' . (int)self::BACKLOG_HOURS . ' HOUR
              AND EXISTS (SELECT 1 FROM push_subscriptions p WHERE p.user_id = n.user_id)
            ORDER BY n.user_id, n.id DESC
            LIMIT 2000
        ')->fetchAll();
        $by = [];
        foreach ($rows as $r) { $by[(int)$r['user_id']][] = $r; }

        foreach ($by as $uid => $list) {
            $out['users']++;
            $unread = 0;
            try {
                $c = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL');
                $c->execute(['u' => $uid]);
                $unread = (int)$c->fetchColumn();
            } catch (Throwable $e) {}
            $first = $list[0];
            $n = count($list);
            $msg = $n === 1
                ? ['title' => (string)$first['title'], 'body' => (string)($first['body'] ?? ''), 'url' => (string)($first['link'] ?? ''), 'tag' => 'n' . $first['id']]
                : ['title' => (string)$first['title'],
                   'body'  => '+ ' . toPersianDigits((string)($n - 1)) . ' اعلانِ تازه‌ی دیگر',
                   'url'   => 'notifications.php', 'tag' => 'batch'];
            $msg['badge'] = $unread;
            $r = self::sendToUser($uid, $msg);
            $out['sent'] += $r['sent']; $out['gone'] += $r['gone']; $out['failed'] += $r['failed'];
            if ($r['sent'] === 0 && $r['gone'] === 0 && $r['failed'] > 0) { continue; }   // شبکه — دورِ بعد
            $ids = array_map(fn($x) => (int)$x['id'], $list);
            $pdo->exec('UPDATE notifications SET pushed_at = NOW() WHERE id IN (' . implode(',', $ids) . ')');
        }
        return $out;
    }

    /**
     * ⛔ تولیدِ روزانه برای کسی که اپ را باز نکرده.
     *
     * `Notify::generateFor()` تا امروز فقط با **بازدیدِ صفحه** اجرا می‌شد، پس
     * «چکِ امروز سررسید است» فقط وقتی ساخته می‌شد که کاربر خودش اپ را باز
     * کند — دقیقاً وقتی که دیگر یادآوری لازم ندارد. برای دارندگانِ اشتراکِ
     * پوش، cron روزی یک بار (از ساعتِ `HOUR_FROM` به بعد، نه نیمه‌شب) همان
     * کار را می‌کند. درستی از `dedup_key` می‌آید؛ نشانه فقط برای سرعت است.
     */
    public const HOUR_FROM = 8;

    public static function dailyGenerate(): int
    {
        if (!self::available() || (int)date('G') < self::HOUR_FROM) { return 0; }
        $mark = dirname(self::keyFile()) . '/generated-' . date('Y-m-d');
        if (is_file($mark)) { return 0; }
        require_once __DIR__ . '/functions.php';
        require_once __DIR__ . '/notify.php';
        $made = 0;
        foreach (Database::getConnection()->query(
            'SELECT DISTINCT p.user_id FROM push_subscriptions p JOIN users u ON u.id = p.user_id WHERE u.is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            try { $made += Notify::generateFor((int)$uid, true); } catch (Throwable $e) { Log::error('push.generate', $e); }
        }
        $dir = dirname($mark);
        if (is_dir($dir)) {
            foreach (glob($dir . '/generated-*') ?: [] as $old) { @unlink($old); }
            @touch($mark);
        }
        return $made;
    }
}
