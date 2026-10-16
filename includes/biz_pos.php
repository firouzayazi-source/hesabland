<?php
/**
 * ⛔ اتصال به کارت‌خوان — بی‌وابستگی به یک شرکت (migration_biz_pos).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** مبلغ مستقیم به کارت‌خوان برود؛ و چون محصول فروشی است،
 * «هر کسی ممکنه با دستگاهِ مختلفی کانکت بشه» — پس هر کارت‌خوان یک **راه‌انداز** دارد:
 *
 *   - `manual`  هر دستگاهی: مبلغ درشت نشان داده می‌شود، فروشنده روی دستگاه می‌زند و شماره‌ی
 *               پیگیریِ رسید را این‌جا می‌نویسد.
 *   - `bridge`  کارت‌خوانِ متصل به رایانه (PC-POS) با سرویسِ محلیِ خودِ شرکت روی همان رایانه:
 *               مرورگر درخواستِ پرداخت را به آن سرویس می‌فرستد (آدرس، بدنه و مسیرِ خواندنِ پاسخ
 *               همه تنظیمی‌اند) و پاسخِ خامش را برای این سرور می‌فرستد که همین‌جا خوانده شود.
 *   - `android` کارت‌خوانِ اندرویدی که همین اپ رویش است: اپِ پرداختِ خودِ دستگاه با Intent باز
 *               می‌شود (action، بسته و نامِ فیلدِ مبلغ تنظیمی‌اند) و شماره‌ی پیگیری نوشته می‌شود.
 *
 * ⛔ **سرور هرگز به کارت‌خوان وصل نمی‌شود** — دستگاه کنارِ صندوق است، نه کنارِ سرور. پس
 *    آدرسِ سرویسِ محلی فقط همین رایانه یا شبکه‌ی داخلی است (`bridgeUrlError()`): آدرسِ بیرونی یعنی
 *    مبلغِ فروش‌ها به سایتِ دیگری.
 * ⛔ هر تراکنش یک ردیف؛ هر تراکنشِ موفق فقط **یک** دریافت (`claim()` ← `status = used`) و یک
 *    شماره‌ی پیگیری دو بار پذیرفته نمی‌شود. پول همان دریافتِ عادی است (`BizPay::create()`)؛
 *    این‌جا هیچ پولی نوشته نمی‌شود.
 * ⚠ با دستگاهِ واقعی آزموده نشده؛ «دستی» همیشه کار می‌کند و پیش‌فرض است.
 */

require_once __DIR__ . '/db.php';

final class BizPos
{
    public const DRIVERS = [
        'manual'  => 'دستی — مبلغ را روی دستگاه بزنید و شماره‌ی پیگیری را این‌جا بنویسید (هر دستگاهی)',
        'bridge'  => 'سرویسِ محلیِ کارت‌خوان روی همین رایانه (PC-POS)',
        'android' => 'کارت‌خوانِ اندرویدی (اپِ پرداختِ خودِ دستگاه)',
    ];

    public const STATUSES = ['pending' => 'منتظرِ کارت‌خوان', 'success' => 'پرداخت شد', 'failed' => 'ناموفق', 'used' => 'ثبت شد'];

    /** کلیدهای تنظیمِ هر راه‌انداز و پیش‌فرضشان. */
    public const CONFIG = [
        'manual'  => [],
        'bridge'  => ['url' => '', 'method' => 'POST', 'body' => '{"amount": {amount_rial}, "ref": "{ref}"}', 'unit' => 'rial',
                      'ok_path' => '', 'ok_value' => '', 'rrn_path' => '', 'card_path' => '', 'msg_path' => ''],
        'android' => ['action' => '', 'package' => '', 'amount_extra' => 'amount', 'unit' => 'rial'],
    ];

    public const NAME_MAX = 60;

    public static function ready(): bool
    {
        return function_exists('tableHasColumn') && tableHasColumn('biz_pos_txns', 'rrn');
    }

    /* ---------------------------------------------------------- کارت‌خوان‌ها */

    /** @return list<array> */
    public static function terminals(int $userId, bool $activeOnly = false): array
    {
        if (!self::ready()) { return []; }
        $st = Database::getConnection()->prepare('SELECT t.*, a.name AS account_name FROM biz_pos_terminals t
            JOIN biz_accounts a ON a.id = t.account_id AND a.user_id = t.user_id
            WHERE t.user_id = :u' . ($activeOnly ? ' AND t.is_active = 1' : '') . ' ORDER BY t.is_active DESC, t.id');
        $st->execute(['u' => $userId]);
        return array_map(fn($r) => $r + ['cfg' => self::cfg($r)], $st->fetchAll());
    }

    public static function get(int $userId, int $id): ?array
    {
        if (!self::ready()) { return null; }
        $st = Database::getConnection()->prepare('SELECT * FROM biz_pos_terminals WHERE id = :i AND user_id = :u');
        $st->execute(['i' => $id, 'u' => $userId]);
        $r = $st->fetch();
        return $r ? $r + ['cfg' => self::cfg($r)] : null;
    }

    private static function cfg(array $r): array
    {
        $def = self::CONFIG[$r['driver']] ?? [];
        $c = json_decode((string)($r['config'] ?? ''), true);
        return array_intersect_key(is_array($c) ? $c : [], $def) + $def;
    }

    /**
     * ⛔ آدرسِ سرویسِ محلی: فقط همین رایانه (`localhost`، `127.x`، `::1`) با http/https، یا شبکه‌ی
     *    داخلی (10.x، 172.16–31.x، 192.168.x) فقط با https (مرورگر http را از صفحه‌ی https نمی‌فرستد).
     */
    public static function bridgeUrlError(string $url): ?string
    {
        $u = parse_url($url);
        $scheme = strtolower((string)($u['scheme'] ?? ''));
        $host = strtolower(trim((string)($u['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($u['user']) || isset($u['pass'])) {
            return 'آدرسِ سرویس را کامل بنویسید، مثلاً http://127.0.0.1:8088/pay';
        }
        $loop = $host === 'localhost' || $host === '::1' || preg_match('/^127(\.\d{1,3}){3}$/', $host);
        $private = (bool)preg_match('/^(10\.\d{1,3}\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})$/', $host);
        if ($loop) { return null; }
        if ($private) { return $scheme === 'https' ? null : 'سرویسِ روی رایانه‌ی دیگرِ شبکه فقط با https (مرورگر http را از این صفحه نمی‌فرستد).'; }
        return '⛔ سرویسِ کارت‌خوان فقط روی همین رایانه یا شبکه‌ی داخلی — آدرسِ بیرونی پذیرفته نمی‌شود.';
    }

    /**
     * ⛔ تنها نویسنده‌ی کارت‌خوان‌ها. صندوق باید مالِ همین فروشگاه و از نوعِ «کارت‌خوان» یا «بانک» باشد.
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function saveTerminal(int $userId, array $in, int $id = 0): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'کارت‌خوان هنوز راه نیفتاده است (migration_biz_pos).']; }
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')) ?? '');
        $driver = (string)($in['driver'] ?? 'manual');
        $acc = (int)($in['account_id'] ?? 0);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) { return ['ok' => false, 'message' => 'نامِ کارت‌خوان را بنویسید.']; }
        if (!isset(self::DRIVERS[$driver])) { return ['ok' => false, 'message' => 'نوعِ اتصال معتبر نیست.']; }
        $st = Database::getConnection()->prepare("SELECT kind FROM biz_accounts WHERE id = :a AND user_id = :u AND is_active = 1");
        $st->execute(['a' => $acc, 'u' => $userId]);
        $kind = $st->fetchColumn();
        if ($kind === false || !in_array((string)$kind, ['pos', 'bank'], true)) { return ['ok' => false, 'message' => 'صندوقِ کارت‌خوان را انتخاب کنید (نوعِ «کارت‌خوان» یا «بانک»).']; }
        $cfg = [];
        foreach (self::CONFIG[$driver] as $k => $def) { $cfg[$k] = mb_substr(trim((string)($in['cfg'][$k] ?? $def)), 0, $k === 'body' ? 1000 : 200); }
        if ($driver === 'bridge') {
            if (($e = self::bridgeUrlError($cfg['url'])) !== null) { return ['ok' => false, 'message' => $e]; }
            if (!in_array(strtoupper($cfg['method']), ['POST', 'GET'], true)) { return ['ok' => false, 'message' => 'روشِ درخواست POST یا GET است.']; }
            $cfg['method'] = strtoupper($cfg['method']);
            if ($cfg['ok_path'] === '' || $cfg['rrn_path'] === '') { return ['ok' => false, 'message' => 'مسیرِ «موفق بود؟» و «شماره‌ی پیگیری» در پاسخ را بنویسید (مثلاً status و rrn).']; }
            foreach (['ok_path', 'rrn_path', 'card_path', 'msg_path'] as $k) {
                if ($cfg[$k] !== '' && !preg_match('~^[A-Za-z0-9_\-.]{1,80}$~', $cfg[$k])) { return ['ok' => false, 'message' => 'مسیرِ پاسخ فقط حرف و عدد و نقطه است (مثلاً data.rrn).']; }
            }
        }
        if ($driver === 'android') {
            foreach (['action', 'package', 'amount_extra'] as $k) {
                if (!preg_match('~^[A-Za-z0-9_.]{1,120}$~', $cfg[$k])) { return ['ok' => false, 'message' => 'action، نامِ بسته و نامِ فیلدِ مبلغ را از راهنمای دستگاه بنویسید (حرفِ لاتین، رقم و نقطه).']; }
            }
        }
        foreach (['unit'] as $k) { if (isset($cfg[$k]) && !in_array($cfg[$k], ['rial', 'toman'], true)) { $cfg[$k] = 'rial'; } }
        $pdo = Database::getConnection();
        $par = ['u' => $userId, 'n' => $name, 'a' => $acc, 'd' => $driver, 'c' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'on' => !array_key_exists('is_active', $in) || !empty($in['is_active']) ? 1 : 0];
        if ($id > 0) {
            if (!self::get($userId, $id)) { return ['ok' => false, 'message' => 'کارت‌خوان پیدا نشد.']; }
            $pdo->prepare('UPDATE biz_pos_terminals SET name = :n, account_id = :a, driver = :d, config = :c, is_active = :on WHERE id = :i AND user_id = :u')
                ->execute($par + ['i' => $id]);
        } else {
            $pdo->prepare('INSERT INTO biz_pos_terminals (user_id, name, account_id, driver, config, is_active) VALUES (:u, :n, :a, :d, :c, :on)')->execute($par);
            $id = (int)$pdo->lastInsertId();
        }
        return ['ok' => true, 'message' => 'کارت‌خوانِ «' . $name . '» ذخیره شد.', 'id' => $id];
    }

    /* ---------------------------------------------------------- تراکنش */

    /** مقدار در یک مسیرِ نقطه‌دار (`data.rrn`). */
    public static function pathGet(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $k) {
            if (!is_array($data) || !array_key_exists($k, $data)) { return null; }
            $data = $data[$k];
        }
        return $data;
    }

    /**
     * آغازِ یک پرداخت: ردیفِ «منتظر» و آنچه مرورگر برای این راه‌انداز لازم دارد.
     * @return array{ok:bool, message?:string, id?:int, amount?:int, driver?:string, client?:array}
     */
    public static function start(int $userId, int $terminalId, int $amount, ?int $actorId = null): array
    {
        $t = self::get($userId, $terminalId);
        if (!$t || (int)$t['is_active'] !== 1) { return ['ok' => false, 'message' => 'کارت‌خوان پیدا نشد.']; }
        if ($amount <= 0 || (class_exists('BizCommon') && ($e = BizCommon::moneyError($amount, 'مبلغ')) !== null)) { return ['ok' => false, 'message' => $e ?? 'مبلغ را بنویسید.']; }
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO biz_pos_txns (user_id, terminal_id, amount, created_by) VALUES (:u, :t, :a, :by)')
            ->execute(['u' => $userId, 't' => $terminalId, 'a' => $amount, 'by' => $actorId]);
        $id = (int)$pdo->lastInsertId();
        $c = $t['cfg'];
        $sent = ($c['unit'] ?? 'rial') === 'toman' ? $amount : $amount * 10;
        $client = ['driver' => $t['driver']];
        if ($t['driver'] === 'bridge') {
            // ⛔ بدنه الگوی کاربر است؛ جای‌گذاری فقط عدد و شناسه‌ی خودِ ما (هیچ متنِ ورودی)
            $client += ['url' => $c['url'], 'method' => $c['method'],
                        'body' => strtr((string)$c['body'], ['{amount_rial}' => (string)($amount * 10), '{amount}' => (string)$sent, '{ref}' => 'HL' . $id])];
        } elseif ($t['driver'] === 'android') {
            $client['intent'] = 'intent:#Intent;action=' . $c['action'] . ';package=' . $c['package'] . ';S.' . $c['amount_extra'] . '=' . $sent . ';end';
        }
        return ['ok' => true, 'id' => $id, 'amount' => $amount, 'driver' => $t['driver'], 'client' => $client];
    }

    /**
     * پایانِ یک پرداخت. `bridge`: `raw` = پاسخِ خامِ سرویس (همین‌جا با مسیرهای تنظیم‌شده خوانده
     * می‌شود — یک خواننده، نه دو). بقیه: `ok` و `rrn` (و چهار رقمِ آخرِ کارت) از فرم.
     * @return array{ok:bool, message:string, status?:string, rrn?:string}
     */
    public static function finish(int $userId, int $txnId, array $in): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT x.*, t.driver, t.config FROM biz_pos_txns x JOIN biz_pos_terminals t ON t.id = x.terminal_id AND t.user_id = x.user_id
                             WHERE x.id = :i AND x.user_id = :u");
        $st->execute(['i' => $txnId, 'u' => $userId]);
        $x = $st->fetch();
        if (!$x) { return ['ok' => false, 'message' => 'تراکنش پیدا نشد.']; }
        if ($x['status'] !== 'pending') { return ['ok' => false, 'message' => 'این تراکنش از قبل بسته شده است.']; }
        $c = self::cfg($x);
        if ($x['driver'] === 'bridge') {
            $raw = json_decode((string)($in['raw'] ?? ''), true, 32);
            $okv = $raw !== null ? self::pathGet($raw, $c['ok_path']) : null;
            $ok = $okv !== null && !is_array($okv) && (string)$okv === (string)$c['ok_value'];
            $rrn = $raw !== null ? self::pathGet($raw, $c['rrn_path']) : null;
            $card = $c['card_path'] !== '' && $raw !== null ? self::pathGet($raw, $c['card_path']) : null;
            $msg = $c['msg_path'] !== '' && $raw !== null ? self::pathGet($raw, $c['msg_path']) : null;
            if ($raw === null) { $ok = false; $msg = 'پاسخِ سرویسِ کارت‌خوان خوانده نشد.'; }
        } else {
            $ok = !empty($in['ok']);
            $rrn = $in['rrn'] ?? null; $card = $in['card'] ?? null; $msg = null;
        }
        $rrn  = is_scalar($rrn) ? preg_replace('/\D+/', '', toLatinDigits((string)$rrn)) : '';
        $card = is_scalar($card) ? mb_substr(preg_replace('/[^0-9*\-xX]+/', '', toLatinDigits((string)$card)), 0, 25) : '';
        $msg  = is_scalar($msg) ? mb_substr(trim((string)$msg), 0, 300) : null;
        if ($ok && (strlen($rrn) < 6 || strlen($rrn) > 20)) {
            if ($x['driver'] !== 'bridge') { return ['ok' => false, 'message' => 'شماره‌ی پیگیری (یا مرجعِ) رسیدِ کارت‌خوان را بنویسید (۶ تا ۲۰ رقم).']; }
            $ok = false; $msg = 'سرویس «موفق» گفت ولی شماره‌ی پیگیری نداد — دستی ثبت کنید.';
        }
        if ($ok) {
            $d = $pdo->prepare("SELECT COUNT(*) FROM biz_pos_txns WHERE user_id = :u AND rrn = :r AND status IN ('success','used') AND id <> :i");
            $d->execute(['u' => $userId, 'r' => $rrn, 'i' => $txnId]);
            if ((int)$d->fetchColumn() > 0) { return ['ok' => false, 'message' => '⛔ این شماره‌ی پیگیری قبلاً ثبت شده است — یک رسید دو بار ثبت نمی‌شود.']; }
        }
        $pdo->prepare("UPDATE biz_pos_txns SET status = :s, rrn = :r, card = :c, message = :m, done_at = NOW() WHERE id = :i AND user_id = :u AND status = 'pending'")
            ->execute(['s' => $ok ? 'success' : 'failed', 'r' => $rrn !== '' ? $rrn : null, 'c' => $card !== '' ? $card : null, 'm' => $msg, 'i' => $txnId, 'u' => $userId]);
        return $ok ? ['ok' => true, 'message' => 'پرداخت با کارت‌خوان انجام شد (پیگیری ' . toPersianDigits($rrn) . ').', 'status' => 'success', 'rrn' => $rrn]
                   : ['ok' => false, 'message' => 'پرداخت انجام نشد' . ($msg ? ': ' . $msg : '.'), 'status' => 'failed'];
    }

    /**
     * ⛔ رزروِ یک تراکنشِ موفق برای یک دریافت — **پیش از** `BizPay::create()`: فقط تراکنشِ موفق،
     *    با همان مبلغ و صندوقِ همان کارت‌خوان، و فقط یک بار (`success` ← `used` اتمی).
     * @return array{ok:bool, message:string}
     */
    public static function claim(int $userId, int $txnId, int $amount, int $accountId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT x.amount, x.status, t.account_id FROM biz_pos_txns x JOIN biz_pos_terminals t ON t.id = x.terminal_id WHERE x.id = :i AND x.user_id = :u');
        $st->execute(['i' => $txnId, 'u' => $userId]);
        $x = $st->fetch();
        if (!$x || $x['status'] !== 'success') { return ['ok' => false, 'message' => 'پرداختِ کارت‌خوانِ موفقی برای این دریافت نیست (یا قبلاً ثبت شده).']; }
        if ((int)$x['amount'] !== $amount) { return ['ok' => false, 'message' => 'مبلغِ دریافت (' . formatMoney($amount) . ') با مبلغِ کارت‌خوان (' . formatMoney((int)$x['amount']) . ') یکی نیست.']; }
        if ((int)$x['account_id'] !== $accountId) { return ['ok' => false, 'message' => 'صندوقِ دریافت با صندوقِ همین کارت‌خوان یکی نیست.']; }
        $u = $pdo->prepare("UPDATE biz_pos_txns SET status = 'used' WHERE id = :i AND user_id = :u AND status = 'success'");
        $u->execute(['i' => $txnId, 'u' => $userId]);
        return $u->rowCount() === 1 ? ['ok' => true, 'message' => ''] : ['ok' => false, 'message' => 'این پرداختِ کارت‌خوان همین حالا برای دریافتِ دیگری ثبت شد.'];
    }

    /** پیوندِ دریافتِ ساخته‌شده؛ یا برگرداندنِ رزرو اگر دریافت ساخته نشد. */
    public static function settle(int $userId, int $txnId, ?int $paymentId): void
    {
        $pdo = Database::getConnection();
        if ($paymentId !== null && $paymentId > 0) {
            $pdo->prepare("UPDATE biz_pos_txns SET payment_id = :p WHERE id = :i AND user_id = :u AND status = 'used'")->execute(['p' => $paymentId, 'i' => $txnId, 'u' => $userId]);
        } else {
            $pdo->prepare("UPDATE biz_pos_txns SET status = 'success' WHERE id = :i AND user_id = :u AND status = 'used' AND payment_id IS NULL")->execute(['i' => $txnId, 'u' => $userId]);
        }
    }

    /** تراکنش‌های اخیر — برای صفحه‌ی کارت‌خوان. */
    public static function recent(int $userId, int $limit = 30): array
    {
        if (!self::ready()) { return []; }
        $st = Database::getConnection()->prepare('SELECT x.*, t.name AS terminal FROM biz_pos_txns x JOIN biz_pos_terminals t ON t.id = x.terminal_id WHERE x.user_id = :u ORDER BY x.id DESC LIMIT :l');
        $st->bindValue('u', $userId, PDO::PARAM_INT); $st->bindValue('l', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}
