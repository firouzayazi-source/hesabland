<?php
/**
 * ⛔ پایشِ گواهیِ SSL — چون با HSTS، گواهیِ منقضی یعنی سایتِ بسته.
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «امنیت مهم‌تره؛ ضمنِ اینکه پیامِ اخطارِ مربوط
 * بساز که وقتی قطع شد سریع درست کنیم.» HSTS مرورگر را مجبور می‌کند سایت را فقط با
 * https باز کند و راهِ «با وجودِ هشدار ادامه بده» را هم می‌بندد؛ پس اگر تمدیدِ
 * خودکارِ certbot روزی بی‌صدا بیفتد، روزِ انقضا **هیچ‌کس** نمی‌تواند وارد شود —
 * حتی خودِ مدیر برای دیدنِ پنل.
 *
 * ⛔ پس سنجش **از بیرونِ پنل** است و هشدار **از بیرونِ مرورگر** می‌رسد:
 *    - cron هر ۶ ساعت (`deploy/ssl-check.php --run`) همان گواهی‌ای را می‌خواند که
 *      nginx **واقعاً تحویل می‌دهد** (اتصالِ TLS به همین سرور با SNIِ دامنه)، نه
 *      فایلِ روی دیسک — گواهیِ تمدیدشده‌ای که nginx هنوز reloadش نکرده هم خرابی است.
 *    - certbot از ۳۰ روز مانده تمدید می‌کند؛ پس «۱۴ روز مانده» (`WARN_DAYS`) یعنی
 *      تمدید دو هفته است شکست می‌خورد — هنوز وقت هست، ولی کسی باید بداند.
 *    - هشدار سه راه دارد و هیچ‌کدام به بازشدنِ سایت بند نیست: ایمیل به مدیران،
 *      اعلانِ گوشی (`Notify` ← `push-send.php`) و ردیفی در «خطاهای برنامه». متنِ هر
 *      سه دستورِ رفع را دارد (`FIX_CMD`).
 * ⛔ هرگز هنگامِ باز شدنِ صفحه به شبکه نمی‌رود؛ پنل فقط `state()` (آخرین نتیجه‌ی
 *    cron از `var/ssl-state.json`) را می‌خواند.
 */

require_once __DIR__ . '/db.php';

final class SslWatch
{
    /** certbot از ۳۰ روز مانده تمدید می‌کند؛ ۱۴ یعنی دو هفته است نشده. */
    public const WARN_DAYS = 14;
    /** سه روز مانده: دیگر «هشدار» نیست. */
    public const CRIT_DAYS = 3;

    public const FIX_CMD = 'sudo ./hesabland ssl';

    public const STATES = ['ok' => 'سالم', 'warn' => 'نزدیکِ انقضا', 'critical' => 'خراب'];

    /** فایلِ منبعِ ردیفِ «خطاهای برنامه» — همان که `resolveFile()` با آن می‌بندد. */
    public const ERR_FILE = 'includes/ssl_watch.php';

    /** @var null|callable(string $to, string $subject, string $text): bool */
    public static $mailForTests = null;

    public static function stateFile(): string
    {
        return dirname(__DIR__) . '/var/ssl-state.json';
    }

    /** آخرین نتیجه‌ی cron، یا `null` اگر هنوز اجرا نشده. */
    public static function state(): ?array
    {
        $f = self::stateFile();
        if (!is_readable($f)) { return null; }
        $d = json_decode((string)@file_get_contents($f), true);
        return is_array($d) && isset($d['state']) ? $d : null;
    }

    /**
     * گواهی‌ای که سرور برای `$host` تحویل می‌دهد.
     *
     * ⛔ دو اتصال: اولی بی‌اعتبارسنجی، تا گواهیِ **منقضی** هم خوانده شود و تاریخش
     *    گفته شود (نه فقط «اتصال نشد»)؛ دومی با اعتبارسنجیِ کامل، چون گواهیِ
     *    بی‌زنجیره یا خودامضا برای مرورگر همان‌قدر بسته است که منقضی.
     */
    public static function probe(string $host, string $ip = '127.0.0.1', int $port = 443, int $timeout = 8, string $cafile = ''): array
    {
        $out = ['reached' => false, 'valid_to' => null, 'names' => [], 'issuer' => '', 'trusted' => false, 'error' => ''];
        $base = ['peer_name' => $host, 'SNI_enabled' => true];

        $ctx = stream_context_create(['ssl' => $base + [
            'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]]);
        $s = @stream_socket_client("ssl://{$ip}:{$port}", $eno, $estr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            $out['error'] = trim((string)$estr) !== '' ? (string)$estr : 'اتصال برقرار نشد';
            return $out;
        }
        $params = stream_context_get_params($s);
        fclose($s);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (!is_array($info)) {
            $out['error'] = 'گواهی خوانده نشد';
            return $out;
        }
        $out['reached']  = true;
        $out['valid_to'] = isset($info['validTo_time_t']) ? (int)$info['validTo_time_t'] : null;
        $out['issuer']   = (string)($info['issuer']['O'] ?? $info['issuer']['CN'] ?? '');
        $names = [];
        if (!empty($info['subject']['CN'])) { $names[] = strtolower((string)$info['subject']['CN']); }
        foreach (explode(',', (string)($info['extensions']['subjectAltName'] ?? '')) as $san) {
            $san = trim($san);
            if (stripos($san, 'DNS:') === 0) { $names[] = strtolower(substr($san, 4)); }
        }
        $out['names'] = array_values(array_unique($names));

        $strict = $base + ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];
        if ($cafile !== '') { $strict['cafile'] = $cafile; }
        $s2 = @stream_socket_client("ssl://{$ip}:{$port}", $eno, $estr, $timeout, STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $strict]));
        if ($s2) { $out['trusted'] = true; fclose($s2); }
        return $out;
    }

    public static function nameMatches(string $host, array $names): bool
    {
        $host = strtolower($host);
        foreach ($names as $n) {
            if ($n === $host) { return true; }
            // `*.example.com` فقط یک برچسب را می‌پوشاند (`a.example.com`، نه `example.com`)
            if (strncmp($n, '*.', 2) === 0) {
                $dot = strpos($host, '.');
                if ($dot !== false && substr($host, $dot + 1) === substr($n, 2)) { return true; }
            }
        }
        return false;
    }

    /**
     * داوری — تابعِ خالص، تا هر مرز بی‌شبکه آزموده شود.
     *
     * @return array{state:string, days:?int, reason:string, valid_to:?int}
     */
    public static function assess(array $p, string $host, int $now): array
    {
        $vt   = $p['valid_to'] ?? null;
        $days = $vt === null ? null : (int)floor(($vt - $now) / 86400);
        $r = fn(string $state, string $reason) => ['state' => $state, 'days' => $days, 'reason' => $reason, 'valid_to' => $vt];

        if (empty($p['reached'])) {
            return $r('critical', 'اتصالِ https به سایت برقرار نشد (' . ($p['error'] ?? '') . ')');
        }
        if ($vt === null || $vt <= $now) {
            return $r('critical', 'گواهیِ امنیتیِ سایت منقضی شده است');
        }
        if (!self::nameMatches($host, $p['names'] ?? [])) {
            return $r('critical', 'گواهیِ تحویل‌شده مالِ ' . $host . ' نیست');
        }
        if (empty($p['trusted'])) {
            return $r('critical', 'مرورگرها این گواهی را معتبر نمی‌دانند (زنجیره یا صادرکننده)');
        }
        if ($days <= self::CRIT_DAYS) {
            return $r('critical', 'گواهیِ امنیتیِ سایت تا ' . self::fa($days) . ' روزِ دیگر منقضی می‌شود');
        }
        if ($days <= self::WARN_DAYS) {
            return $r('warn', 'گواهیِ امنیتیِ سایت تا ' . self::fa($days) . ' روزِ دیگر منقضی می‌شود و تمدیدِ خودکار انجام نشده');
        }
        return $r('ok', 'گواهی سالم است — ' . self::fa($days) . ' روز مانده');
    }

    /**
     * ⛔ متنِ ردیفِ «خطاهای برنامه» در طولِ عمرِ یک گواهی ثابت است (تاریخِ انقضا، نه
     *    «چند روز مانده») — وگرنه هر روز یک ردیفِ تازه می‌ساخت و شمارشش بی‌معنا می‌شد.
     */
    public static function errorMessage(array $a, string $host): string
    {
        $when = $a['valid_to'] ? ' (انقضا: ' . self::date((int)$a['valid_to']) . ')' : '';
        $kind = $a['state'] === 'warn' ? 'گواهیِ SSL نزدیکِ انقضاست' : 'گواهیِ SSL خراب است';
        return $kind . ' — ' . $host . $when . '. رفع روی سرور: cd /opt/hesab/app سپس ' . self::FIX_CMD;
    }

    public static function mailText(array $a, string $host): string
    {
        return "سلام،\n\n" . $a['reason'] . ".\nسایت: https://{$host}/"
            . ($a['valid_to'] ? "\nتاریخِ انقضا: " . self::date((int)$a['valid_to']) : '')
            . "\n\nچون HSTS روشن است، اگر گواهی منقضی شود هیچ‌کس نمی‌تواند سایت را باز کند.\n"
            . "رفع روی سرور (هر خط یک دستور):\n\ncd /opt/hesab/app\n\n" . self::FIX_CMD . "\n\n"
            . "اگر تمدید نشد، خروجیِ همان دستور علت را می‌گوید.\n";
    }

    /**
     * کارِ cron: سنجش، ثبتِ نتیجه، و اگر لازم بود هشدار.
     *
     * ⛔ ایمیل روزی یک بار برای هر وضعیت — ولی بدتر شدن («نزدیکِ انقضا» ← «خراب»)
     *    همان لحظه دوباره می‌فرستد. اعلانِ گوشی با `dedup_key`ِ روزانه، همان الگوی
     *    `generateAdminErrors()`. سالم شدن ردیفِ «خطاهای برنامه» را می‌بندد.
     */
    public static function run(string $host, array $probe, ?int $now = null): array
    {
        $now  = $now ?? time();
        $a    = self::assess($probe, $host, $now);
        $prev = self::state() ?? [];
        $today = date('Y-m-d', $now);

        $mailed = ['on' => (string)($prev['mailed_on'] ?? ''), 'state' => (string)($prev['mailed_state'] ?? '')];
        $sent = 0; $notified = 0;

        if ($a['state'] === 'ok') {
            if (class_exists('AppErrors')) { AppErrors::resolveFile(self::ERR_FILE); }
            $mailed = ['on' => '', 'state' => ''];
        } else {
            if (class_exists('AppErrors')) {
                AppErrors::record($a['state'] === 'warn' ? 'warning' : 'fatal', self::errorMessage($a, $host),
                    __FILE__, 0, ['event' => 'ssl.' . $a['state']]);
            }
            $worse = self::rank($a['state']) > self::rank($mailed['state']);
            $admins = self::admins();
            if ($mailed['on'] !== $today || $worse) {
                $subject = ($a['state'] === 'warn' ? '⚠ گواهیِ SSL نزدیکِ انقضا — ' : '⛔ گواهیِ SSL خراب — ') . $host;
                foreach ($admins as $ad) {
                    if ($ad['email'] !== '' && self::mail($ad['email'], $subject, self::mailText($a, $host))) { $sent++; }
                }
                $mailed = ['on' => $today, 'state' => $a['state']];
            }
            if (class_exists('Notify')) {
                foreach ($admins as $ad) {
                    if (Notify::push($ad['id'], 'ssl', $a['state'] === 'warn' ? 'گواهیِ SSL نزدیکِ انقضاست' : 'گواهیِ SSL خراب است',
                        $a['reason'] . '. رفع روی سرور: ' . self::FIX_CMD, 'admin/errors.php', 'ssl:' . $a['state'] . ':' . $today)) {
                        $notified++;
                    }
                }
            }
        }

        $state = $a + ['host' => $host, 'checked_at' => $now, 'issuer' => (string)($probe['issuer'] ?? ''),
                       'mailed_on' => $mailed['on'], 'mailed_state' => $mailed['state']];
        $dir = dirname(self::stateFile());
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents(self::stateFile(), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod(self::stateFile(), 0644);

        return $state + ['sent' => $sent, 'notified' => $notified];
    }

    /** @return list<array{id:int, email:string}> */
    private static function admins(): array
    {
        try {
            $rows = Database::getConnection()->query(
                "SELECT id, COALESCE(email, '') AS email FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
        return array_map(fn($r) => ['id' => (int)$r['id'], 'email' => (string)$r['email']], $rows);
    }

    private static function mail(string $to, string $subject, string $text): bool
    {
        if (self::$mailForTests !== null) { return (bool)(self::$mailForTests)($to, $subject, $text); }
        if (!class_exists('Mailer') || !Mailer::isConfigured()) { return false; }
        try {
            return Mailer::send($to, $subject, nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')), $text);
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function rank(string $s): int
    {
        return ['warn' => 1, 'critical' => 2][$s] ?? 0;
    }

    private static function fa(?int $n): string
    {
        $s = (string)(int)$n;
        return function_exists('toPersianDigits') ? toPersianDigits($s) : $s;
    }

    private static function date(int $ts): string
    {
        $g = date('Y-m-d', $ts);
        return function_exists('toJalali') ? toJalali($g) : $g;
    }
}
