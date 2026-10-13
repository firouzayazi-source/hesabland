<?php
/**
 * ⛔ عملیاتِ روزمره‌ی فروشگاه (migration_biz_ops) — چهار قابلیت که هیچ‌کدام
 *    دفترِ دومی نمی‌سازند و هر عددشان از همان اسنادِ موجود می‌آید:
 *
 * - `BizFx`        حسابِ ارزیِ طرف‌حساب (دلار، درهم…): نرخِ هر سند عکسِ همان لحظه است و
 *                  مانده‌ی ارزی و «تسعیر» (سود/زیانِ نرخ) فقط **گزارش**‌اند — مانده‌ی
 *                  تومانیِ دفتر (`BALANCE_SQL`) دست نمی‌خورد.
 * - `BizConsign`   کالای امانی: موجودی نیست تا خریده شود؛ خرید = فاکتورِ خریدِ واقعی از
 *                  امانت‌دهنده (`BizQuickBuy::run()`)، هرگز INSERTِ مستقیمِ انبار.
 * - `BizBankRec`   مغایرت‌گیری: صورت‌حسابِ بانک کنارِ اسناد؛ جفت کردن فقط برچسب است و هیچ
 *                  سندی نمی‌سازد یا عوض نمی‌کند.
 * - `BizRecurring` هزینه‌ی تکراری: یادآور و پیش‌فرضِ فرم؛ ثبت فقط با دستِ کاربر و از راهِ
 *                  `BizPay::createTx()` (همان سدهای دوره‌ی بسته، صندوق و سرفصل).
 *
 * ⛔ فقط `biz_*`؛ هیچ جدولِ شخصی (قاعده ۷۰). `user_id` روی هر کوئری.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/schedule.php';

final class BizFx
{
    /** ⛔ ارزهای حساب — زیرمجموعه‌ی `Rates::CODES` (نرخِ روز از همان می‌آید). */
    public const CODES = ['usd', 'aed', 'eur', 'usdt'];

    /** سقفِ نرخ (تومان برای یک واحد) — سدِ اشتباهِ تایپی، نه محدودیتِ بازار. */
    public const RATE_MAX = 100000000;

    public static function ready(): bool
    {
        return tableHasColumn('biz_parties', 'fx_code');
    }

    /** @return array<string,string> کد ⇒ نام */
    public static function codes(): array
    {
        require_once __DIR__ . '/rates.php';
        $out = [];
        foreach (self::CODES as $c) { $out[$c] = Rates::label($c); }
        return $out;
    }

    public static function label(?string $code): string
    {
        return $code !== null && in_array($code, self::CODES, true) ? self::codes()[$code] : 'تومان';
    }

    /** نرخِ امروز از نرخ‌های روز (`Rates`)؛ نبودش `null`. */
    public static function todayRate(string $code): ?int
    {
        if (!in_array($code, self::CODES, true)) { return null; }
        require_once __DIR__ . '/rates.php';
        $p = Rates::price($code);
        return $p !== null && $p > 0 ? $p : null;
    }

    /** نرخِ تایپ‌شده («۶۲٬۵۰۰»، «62500.5»، «۶۲۵۰۰٫۵») ⇒ عدد؛ نامعتبر `null`، خالی `0.0`. */
    public static function parseRate($raw): ?float
    {
        $t = trim(toLatinDigits((string)$raw));
        if ($t === '') { return 0.0; }
        $t = str_replace(['٬', ',', ' '], '', $t);
        $t = str_replace('٫', '.', $t);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $t)) { return null; }
        $v = (float)$t;
        return $v > 0 && $v <= self::RATE_MAX ? $v : null;
    }

    /** ارزِ حسابِ یک طرف‌حساب؛ تومانی `null`. */
    public static function partyCode(int $userId, int $partyId): ?string
    {
        if ($partyId <= 0 || !self::ready()) { return null; }
        $st = Database::getConnection()->prepare('SELECT fx_code FROM biz_parties WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $partyId, 'u' => $userId]);
        $c = $st->fetchColumn();
        return is_string($c) && in_array($c, self::CODES, true) ? $c : null;
    }

    /** @return array{ok:bool, message:string} */
    public static function setPartyCode(int $userId, int $partyId, string $code): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $code = trim($code);
        if ($code !== '' && !in_array($code, self::CODES, true)) { return ['ok' => false, 'message' => 'ارزِ حساب معتبر نیست.']; }
        $st = Database::getConnection()->prepare('UPDATE biz_parties SET fx_code = :c WHERE id = :id AND user_id = :u');
        $st->execute(['c' => $code === '' ? null : $code, 'id' => $partyId, 'u' => $userId]);
        return ['ok' => true, 'message' => $code === '' ? 'حسابِ این شخص تومانی شد.' : 'حسابِ این شخص به ' . self::label($code) . ' نگه داشته می‌شود.'];
    }

    /**
     * ⛔ نرخِ یک سندِ تازه برای طرف‌حسابِ ارزی: نرخِ فرم، وگرنه `$fallback` (نرخِ
     *    خودِ پیش‌نویس)، وگرنه نرخِ امروز. طرف‌حسابِ تومانی همیشه `null`.
     * @return array{ok:bool, rate:?float, message?:string}
     */
    public static function docRate(?string $code, $raw, ?float $fallback = null): array
    {
        if ($code === null) { return ['ok' => true, 'rate' => null]; }
        $r = self::parseRate($raw);
        if ($r === null) { return ['ok' => false, 'rate' => null, 'message' => 'نرخِ ' . self::label($code) . ' معتبر نیست (تومان برای یک واحد).']; }
        if ($r > 0) { return ['ok' => true, 'rate' => $r]; }
        if ($fallback !== null && $fallback > 0) { return ['ok' => true, 'rate' => $fallback]; }
        $t = self::todayRate($code);
        if ($t === null) { return ['ok' => false, 'rate' => null, 'message' => 'نرخِ ' . self::label($code) . ' را بنویسید (تومان برای یک واحد).']; }
        return ['ok' => true, 'rate' => (float)$t];
    }

    /** مبلغِ تومانی به ارز — دو رقمِ اعشار. */
    public static function toFx(int $toman, float $rate): float
    {
        return $rate > 0 ? round($toman / $rate, 2) : 0.0;
    }

    /** نمایشِ مبلغِ ارزی: «۱٬۲۳۴٫۵ دلار». */
    public static function money(float $v, ?string $code): string
    {
        $neg = $v < 0; $v = abs($v);
        $s = number_format($v, 2, '٫', '٬');
        $s = rtrim(rtrim($s, '0'), '٫');
        return ($neg ? '−' : '') . toPersianDigits($s) . ' ' . ($code !== null ? (Rates::CODES[$code][1] ?? '') : '');
    }

    /**
     * ⛔ مانده‌ی ارزیِ هر طرف‌حسابِ ارزی و تسعیر. همان نشانه‌های `BALANCE_SQL`
     *    (مثبت = او به ما بدهکار است). سندِ بی‌نرخ (مانده‌ی اول دوره، سندِ پیش از ارزی
     *    شدنِ حساب) به ارز تبدیل **نمی‌شود** و جدا (`unrated`) گزارش می‌شود — حدس زدنِ
     *    نرخ برای آن عددی می‌ساخت که هیچ‌کس پرداخت نکرده.
     *    `diff` = ارزشِ مانده‌ی ارزی به نرخِ امروز − همان بخش به تومانِ دفتر:
     *    مثبت = سودِ نرخ برای فروشگاه، منفی = زیان.
     * @return list<array{id:int, name:string, code:string, fx:float, book:int, rated:int, unrated:int,
     *                    now_rate:?int, value_now:?int, diff:?int}>
     */
    public static function balances(int $userId): array
    {
        if (!self::ready()) { return []; }
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT id, name, fx_code, opening_balance FROM biz_parties WHERE user_id = :u AND fx_code IS NOT NULL ORDER BY name, id');
        $st->execute(['u' => $userId]);
        $rows = [];
        foreach ($st->fetchAll() as $p) {
            if (!in_array((string)$p['fx_code'], self::CODES, true)) { continue; }
            $rows[(int)$p['id']] = ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'code' => (string)$p['fx_code'],
                                    'fx' => 0.0, 'book' => (int)$p['opening_balance'], 'rated' => 0];
        }
        if (!$rows) { return []; }
        $in = implode(',', array_keys($rows));   // شناسه‌های عددیِ همین کوئری، نه ورودی
        $docs = $pdo->prepare(
            "SELECT i.party_id, CASE i.kind WHEN 'sale' THEN i.total WHEN 'purchase_return' THEN i.total ELSE -i.total END AS v,
                    COALESCE(i.fx_rate, o.fx_rate) AS rate
             FROM biz_invoices i LEFT JOIN biz_invoices o ON o.id = i.ref_invoice_id AND o.user_id = i.user_id
             WHERE i.user_id = :u AND i.status = 'issued' AND i.party_id IN ({$in})
             UNION ALL
             SELECT y.party_id, CASE y.kind WHEN 'receipt' THEN -y.amount ELSE y.amount END,
                    COALESCE(y.fx_rate, iv.fx_rate)
             FROM biz_payments y LEFT JOIN biz_invoices iv ON iv.id = y.invoice_id AND iv.user_id = y.user_id
             WHERE y.user_id = :u2 AND y.status = 'ok' AND y.kind IN ('receipt','payment') AND y.party_id IN ({$in})"
        );
        $docs->execute(['u' => $userId, 'u2' => $userId]);
        foreach ($docs->fetchAll() as $d) {
            $id = (int)$d['party_id'];
            $v  = (int)$d['v'];
            $rows[$id]['book'] += $v;
            if ($d['rate'] !== null && (float)$d['rate'] > 0) {
                $rows[$id]['fx']    += $v / (float)$d['rate'];
                $rows[$id]['rated'] += $v;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $r['fx'] = round($r['fx'], 2);
            $r['unrated'] = $r['book'] - $r['rated'];
            $r['now_rate'] = self::todayRate($r['code']);
            $r['value_now'] = $r['now_rate'] !== null ? (int)round($r['fx'] * $r['now_rate']) : null;
            $r['diff'] = $r['value_now'] !== null ? $r['value_now'] - $r['rated'] : null;
            $out[] = $r;
        }
        return $out;
    }
}

final class BizConsign
{
    public const STATUSES = ['held' => 'امانت نزدِ ما', 'bought' => 'خریده شد', 'returned' => 'پس داده شد'];
    public const MAX_UNITS = 50;
    public const NOTE_MAX = 200;

    public static function ready(): bool
    {
        return tableHasColumn('biz_consignments', 'unit_cost');
    }

    /**
     * دریافتِ امانی. گوشی: هر خط یک دستگاه («IMEI۱» یا «IMEI۱ / IMEI۲»)؛ کالای دیگر: تعداد.
     * ⛔ گوشی‌ای که همین حالا در انبارِ ماست یا امانتِ باز است دوباره امانی نمی‌شود.
     * @param array{party_id?:int, product_id?:int, item?:string, qty?:string, imeis?:string, unit_cost?:string, date?:string, note?:string} $in
     * @return array{ok:bool, message:string, ids?:list<int>}
     */
    public static function receive(int $userId, array $in): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.']; }
        $party = BizParties::get($userId, (int)($in['party_id'] ?? 0));
        if (!$party) { return ['ok' => false, 'message' => 'امانت‌دهنده (همکار) را انتخاب کنید.']; }
        $prod = BizProducts::get($userId, (int)($in['product_id'] ?? 0));
        if (!$prod && trim((string)($in['item'] ?? '')) !== '') { $prod = self::productByText($userId, (string)$in['item']); }
        if (!$prod) { return ['ok' => false, 'message' => 'کالا پیدا نشد؛ اول آن را در «محصولات» تعریف کنید.']; }
        if ((int)$prod['track_stock'] !== 1) { return ['ok' => false, 'message' => '«' . $prod['name'] . '» خدمت است و امانی نمی‌شود.']; }
        $cost = sanitizeAmount($in['unit_cost'] ?? '');
        if ($cost <= 0) { return ['ok' => false, 'message' => 'بهای توافقیِ هر واحد را بنویسید.']; }
        if (($e = BizCommon::moneyError($cost, 'بهای توافقی')) !== null) { return ['ok' => false, 'message' => $e]; }
        $date = (string)($in['date'] ?? '');
        if (!isValidDate($date)) { return ['ok' => false, 'message' => 'تاریخِ دریافت معتبر نیست.']; }
        $note = mb_substr(BizCommon::line((string)($in['note'] ?? '')), 0, self::NOTE_MAX);

        require_once __DIR__ . '/biz_quickbuy.php';
        $pdo = Database::getConnection();
        $units = [];
        if ((int)$prod['has_serial'] === 1) {
            $list = BizQuickBuy::parseImeis((string)($in['imeis'] ?? ''));
            if (!$list) { return ['ok' => false, 'message' => 'برای گوشی IMEIِ هر دستگاه را بنویسید (هر خط یک گوشی).']; }
            if (count($list) > self::MAX_UNITS) { return ['ok' => false, 'message' => 'حداکثر ' . toPersianDigits((string)self::MAX_UNITS) . ' دستگاه در یک دریافت.']; }
            $seen = [];
            foreach ($list as [$i1, $i2]) {
                foreach ([$i1, $i2] as $v) {
                    if ($v === '') { continue; }
                    if (!BizSerial::valid($v)) { return ['ok' => false, 'message' => 'IMEI «' . $v . '» معتبر نیست (۱۴ تا ۱۷ رقم).']; }
                    if (isset($seen[$v])) { return ['ok' => false, 'message' => 'IMEI «' . $v . '» تکراری است.']; }
                    $seen[$v] = true;
                }
                $units[] = ['qty' => 1.0, 'imei1' => $i1, 'imei2' => $i2 === '' ? null : $i2];
            }
            $states = BizSerial::states($pdo, $userId, array_keys($seen));
            foreach ($states as $imei => $s) {
                if (($s['dir'] ?? '') === 'in') { return ['ok' => false, 'message' => 'گوشیِ «' . $imei . '» همین حالا در انبارِ شماست؛ امانی نمی‌شود.']; }
            }
            $held = $pdo->prepare("SELECT imei1 FROM biz_consignments WHERE user_id = :u AND status = 'held' AND (imei1 = :a OR imei2 = :b) LIMIT 1");
            foreach (array_keys($seen) as $v) {
                $held->execute(['u' => $userId, 'a' => $v, 'b' => $v]);
                if ($held->fetchColumn() !== false) { return ['ok' => false, 'message' => 'گوشیِ «' . $v . '» همین حالا امانتِ باز است.']; }
            }
        } else {
            $qty = sanitizeQty((string)($in['qty'] ?? ''));
            if ($qty <= 0) { return ['ok' => false, 'message' => 'تعداد را بنویسید.']; }
            if (($e = BizCommon::qtyError($qty, 'تعداد')) !== null) { return ['ok' => false, 'message' => $e]; }
            if (!BizProducts::qtyFits((string)$prod['unit'], $qty)) { return ['ok' => false, 'message' => 'تعدادِ «' . $prod['name'] . '» باید عددِ صحیح باشد.']; }
            $units[] = ['qty' => $qty, 'imei1' => null, 'imei2' => null];
        }

        $ins = $pdo->prepare('INSERT INTO biz_consignments (user_id, party_id, product_id, qty, imei1, imei2, unit_cost, received_on, note, created_by)
                              VALUES (:u, :p, :pr, :q, :i1, :i2, :c, :d, :n, :by)');
        $ids = [];
        $pdo->beginTransaction();
        try {
            foreach ($units as $un) {
                $ins->execute(['u' => $userId, 'p' => (int)$party['id'], 'pr' => (int)$prod['id'], 'q' => $un['qty'],
                               'i1' => $un['imei1'], 'i2' => $un['imei2'], 'c' => $cost, 'd' => $date,
                               'n' => $note === '' ? null : $note, 'by' => Auth::userId()]);
                $ids[] = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'ids' => $ids, 'message' => toPersianDigits((string)count($ids)) . ' ردیف امانی از «' . $party['name']
            . '» ثبت شد. این‌ها موجودیِ شما نیستند تا خریده شوند.'];
    }

    /** کالا از متنِ فرم: شناسه‌ی پنهان نبود ⇒ کد، نام، یا برابریِ «تاشده»ِ **یکتا** (همان `parseLines()`). */
    private static function productByText(int $userId, string $text): ?array
    {
        $parsed = BizInvoices::parseLines($userId, [['item' => $text, 'qty' => '1', 'price' => '0']]);
        $pid = $parsed['meta'][0]['product_id'] ?? null;
        return $pid ? BizProducts::get($userId, (int)$pid) : null;
    }

    public static function get(int $userId, int $id): ?array
    {
        if (!self::ready()) { return null; }
        $st = Database::getConnection()->prepare(
            'SELECT c.*, p.name AS party_name, pr.name AS product_name, pr.unit, pr.sell_price, pr.has_serial
             FROM biz_consignments c JOIN biz_parties p ON p.id = c.party_id AND p.user_id = c.user_id
             JOIN biz_products pr ON pr.id = c.product_id AND pr.user_id = c.user_id
             WHERE c.id = :id AND c.user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->fetch() ?: null;
    }

    /** @return array{rows:array, held:int, held_value:int} */
    public static function list(int $userId, string $status = 'held', int $cap = 500): array
    {
        if (!self::ready()) { return ['rows' => [], 'held' => 0, 'held_value' => 0]; }
        $status = isset(self::STATUSES[$status]) || $status === 'all' ? $status : 'held';
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            'SELECT c.*, p.name AS party_name, pr.name AS product_name, pr.unit, pr.sell_price
             FROM biz_consignments c JOIN biz_parties p ON p.id = c.party_id AND p.user_id = c.user_id
             JOIN biz_products pr ON pr.id = c.product_id AND pr.user_id = c.user_id
             WHERE c.user_id = :u' . ($status === 'all' ? '' : ' AND c.status = :s') . '
             ORDER BY c.received_on DESC, c.id DESC LIMIT :lim');
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        if ($status !== 'all') { $st->bindValue('s', $status); }
        $st->bindValue('lim', $cap, PDO::PARAM_INT);
        $st->execute();
        $sum = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(ROUND(qty * unit_cost)), 0) FROM biz_consignments WHERE user_id = :u AND status = 'held'");
        $sum->execute(['u' => $userId]);
        [$n, $v] = $sum->fetch(PDO::FETCH_NUM) ?: [0, 0];
        return ['rows' => $st->fetchAll(), 'held' => (int)$n, 'held_value' => (int)$v];
    }

    /**
     * ⛔ امانی ⇒ موجودیِ ما: یک فاکتورِ خریدِ **واقعی** از امانت‌دهنده (نسیه، به بهای
     *    توافقی) با `BizQuickBuy::run()` — همان سدهای IMEI، دوره‌ی بسته و صدور.
     *    ردیف اول «ادعا» می‌شود (`held` ⇒ `bought` با شرط)، پس دو کلیکِ هم‌زمان دو
     *    خرید نمی‌سازند؛ اگر خرید رد شد ادعا پس گرفته می‌شود.
     * @return array{ok:bool, message:string, invoice_id?:int}
     */
    public static function buy(int $userId, int $id, string $date): array
    {
        $c = self::get($userId, $id);
        if (!$c) { return ['ok' => false, 'message' => 'ردیفِ امانی پیدا نشد.']; }
        if ($c['status'] !== 'held') { return ['ok' => false, 'message' => 'این ردیف دیگر امانتِ باز نیست (' . self::STATUSES[$c['status']] . ').']; }
        if (!isValidDate($date)) { return ['ok' => false, 'message' => 'تاریخ معتبر نیست.']; }
        require_once __DIR__ . '/biz_quickbuy.php';
        $pdo = Database::getConnection();
        $claim = $pdo->prepare("UPDATE biz_consignments SET status = 'bought', closed_on = :d WHERE id = :id AND user_id = :u AND status = 'held'");
        $claim->execute(['d' => $date, 'id' => $id, 'u' => $userId]);
        if ($claim->rowCount() !== 1) { return ['ok' => false, 'message' => 'این ردیف همین حالا تبدیل شد.']; }
        $r = BizQuickBuy::run($userId, [
            'product_id' => (int)$c['product_id'], 'party_id' => (int)$c['party_id'], 'qty' => (string)(float)$c['qty'],
            'buy' => (string)(int)$c['unit_cost'], 'date' => $date, 'pay' => 'credit',
            'imeis' => $c['imei1'] !== null ? trim($c['imei1'] . ' / ' . ($c['imei2'] ?? ''), ' /') : '',
        ]);
        if (!$r['ok']) {
            $pdo->prepare("UPDATE biz_consignments SET status = 'held', closed_on = NULL WHERE id = :id AND user_id = :u AND status = 'bought' AND invoice_id IS NULL")
                ->execute(['id' => $id, 'u' => $userId]);
            return ['ok' => false, 'message' => $r['message']];
        }
        $pdo->prepare('UPDATE biz_consignments SET invoice_id = :i WHERE id = :id AND user_id = :u')
            ->execute(['i' => (int)$r['invoice_id'], 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'invoice_id' => (int)$r['invoice_id'],
                'message' => '«' . $c['product_name'] . '» از امانی به موجودیِ شما آمد — فاکتورِ خرید (نسیه) به حسابِ «' . $c['party_name'] . '».'];
    }

    /**
     * فروشِ امانی: اول خرید (`buy()`)، بعد **پیش‌نویسِ** فاکتورِ فروش با همان کالا و IMEI
     * به قیمتِ فروشِ کالا — صدورش مثلِ هر فاکتورِ دیگر با کاربر است.
     * @return array{ok:bool, message:string, sale_id?:int}
     */
    public static function sell(int $userId, int $id, string $date): array
    {
        $c = self::get($userId, $id);
        if (!$c) { return ['ok' => false, 'message' => 'ردیفِ امانی پیدا نشد.']; }
        $b = self::buy($userId, $id, $date);
        if (!$b['ok']) { return $b; }
        $d = BizInvoices::saveDraft($userId, 'sale', ['inv_date' => $date, 'lines' => [[
            'item' => (string)$c['product_name'], 'product_id' => (int)$c['product_id'], 'qty' => (string)(float)$c['qty'], 'price' => '',
            'imei1' => (string)($c['imei1'] ?? ''), 'imei2' => (string)($c['imei2'] ?? ''),
        ]]]);
        if (!$d['ok']) { return ['ok' => true, 'message' => $b['message'] . ' پیش‌نویسِ فروش ساخته نشد: ' . $d['message']]; }
        return ['ok' => true, 'sale_id' => (int)$d['id'], 'message' => $b['message'] . ' پیش‌نویسِ فاکتورِ فروش آماده است.'];
    }

    /** @return array{ok:bool, message:string} */
    public static function giveBack(int $userId, int $id, string $date): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        if (!isValidDate($date)) { return ['ok' => false, 'message' => 'تاریخ معتبر نیست.']; }
        $st = Database::getConnection()->prepare("UPDATE biz_consignments SET status = 'returned', closed_on = :d WHERE id = :id AND user_id = :u AND status = 'held'");
        $st->execute(['d' => $date, 'id' => $id, 'u' => $userId]);
        return $st->rowCount() === 1 ? ['ok' => true, 'message' => 'امانی به صاحبش پس داده شد.']
                                     : ['ok' => false, 'message' => 'فقط امانتِ باز پس داده می‌شود.'];
    }

    /** پاک کردنِ ردیفِ اشتباه — فقط امانتِ باز (خریده‌شده سند دارد). */
    public static function remove(int $userId, int $id): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $st = Database::getConnection()->prepare("DELETE FROM biz_consignments WHERE id = :id AND user_id = :u AND status = 'held'");
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() === 1 ? ['ok' => true, 'message' => 'ردیفِ امانی حذف شد.']
                                     : ['ok' => false, 'message' => 'فقط امانتِ باز حذف می‌شود؛ خریده‌شده فاکتور دارد.'];
    }
}

final class BizBankRec
{
    /** روزهای پس و پیش برای جفتِ خودکار (بانک گاهی یکی‌دو روز دیرتر می‌نشاند). */
    public const WINDOW_DAYS = 4;
    public const MAX_ROWS = 3000;

    /**
     * ستون‌های صورت‌حساب. ⚠ «بدهکار/بستانکار» از دیدِ **بانک** است: بدهکار = برداشت از
     * حسابِ شما، بستانکار = واریز (صورت‌حسابِ همه‌ی بانک‌های ایران همین است).
     */
    public const ALIASES = [
        'date'   => ['تاریخ', 'تاریختراکنش', 'تاریخسند', 'date'],
        'amount' => ['مبلغ', 'مبلغتراکنش', 'amount'],
        'in'     => ['واریز', 'بستانکار', 'واریزی', 'credit', 'deposit'],
        'out'    => ['برداشت', 'بدهکار', 'برداشتی', 'debit', 'withdrawal'],
        'desc'   => ['شرح', 'توضیحات', 'شرحتراکنش', 'بابت', 'description', 'desc'],
    ];

    public static function ready(): bool
    {
        return tableHasColumn('biz_bank_lines', 'line_hash');
    }

    private static function key(string $h): string
    {
        return (string)preg_replace('/[\s\x{200c}\-_]+/u', '', mb_strtolower(BizCommon::persian(trim($h))));
    }

    /** تاریخِ صورت‌حساب: شمسی (۱۴۰۴/۰۷/۲۰، ۱۴۰۴۰۷۲۰) یا میلادی ⇒ `Y-m-d`؛ نامعتبر `''`. */
    public static function date(string $v): string
    {
        $t = trim(toLatinDigits($v));
        $t = (string)preg_replace('/\s.*$/', '', $t);           // ساعت کنار گذاشته می‌شود
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $t, $m)) { $t = "{$m[1]}/{$m[2]}/{$m[3]}"; }
        if (!preg_match('~^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$~', $t, $m)) { return ''; }
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($y >= 1900) { $g = sprintf('%04d-%02d-%02d', $y, $mo, $d); return isValidDate($g) ? $g : ''; }
        if ($y < 1300 || $y > 1500 || $mo < 1 || $mo > 12 || $d < 1 || $d > jalaliMonthLength($y, $mo)) { return ''; }
        [$gy, $gm, $gd] = jalaliToGregorian($y, $mo, $d);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /**
     * ردیف‌های جدول ⇒ ردیف‌های صورت‌حساب. سطرِ سرآیند اولین سطری است که «تاریخ» و
     * (مبلغ یا واریز/برداشت) دارد. `$rial` = مبلغ‌ها ریال‌اند (پیش‌فرضِ بانک‌ها) ⇒ ÷۱۰.
     * @return array{ok:bool, message?:string, lines?:list<array{date:string, amount:int, desc:string}>, skipped?:int}
     */
    public static function parse(array $rows, bool $rial = true): array
    {
        $map = null; $start = 0;
        foreach (array_slice($rows, 0, 15, true) as $i => $r) {
            $m = [];
            foreach ((array)$r as $c => $h) {
                $k = self::key((string)$h);
                foreach (self::ALIASES as $f => $al) {
                    if (!isset($m[$f]) && in_array($k, array_map([self::class, 'key'], $al), true)) { $m[$f] = $c; }
                }
            }
            if (isset($m['date']) && (isset($m['amount']) || isset($m['in']) || isset($m['out']))) { $map = $m; $start = $i + 1; break; }
        }
        if ($map === null) {
            return ['ok' => false, 'message' => 'ستون‌های «تاریخ» و «مبلغ» (یا «واریز/برداشت»، «بستانکار/بدهکار») در فایل پیدا نشد.'];
        }
        $num = function ($v): int {
            $t = trim(toLatinDigits((string)$v));
            $neg = (bool)preg_match('/^[\-\x{2212}(]|[\-)]$/u', $t);
            $n = (int)preg_replace('/[^0-9]/', '', explode('٫', explode('.', $t)[0])[0]);
            return $neg ? -$n : $n;
        };
        $lines = []; $skipped = 0;
        foreach (array_slice($rows, $start) as $r) {
            $r = (array)$r;
            $d = self::date((string)($r[$map['date']] ?? ''));
            if (isset($map['amount'])) {
                $a = $num($r[$map['amount']] ?? '');
            } else {
                $a = abs($num($r[$map['in']] ?? '')) - abs($num($r[$map['out']] ?? ''));
            }
            if ($d === '' || $a === 0) { $skipped++; continue; }
            if ($rial) { $a = (int)round($a / 10); }
            if ($a === 0 || ($e = BizCommon::moneyError(abs($a), 'مبلغ')) !== null) { $skipped++; continue; }
            $lines[] = ['date' => $d, 'amount' => $a, 'desc' => mb_substr(BizCommon::line((string)($r[$map['desc'] ?? -1] ?? '')), 0, 255)];
            if (count($lines) > self::MAX_ROWS) { return ['ok' => false, 'message' => 'حداکثر ' . toPersianDigits((string)self::MAX_ROWS) . ' ردیف در یک فایل.']; }
        }
        if (!$lines) { return ['ok' => false, 'message' => 'هیچ ردیفِ تاریخ‌دار با مبلغ در فایل نبود.']; }
        return ['ok' => true, 'lines' => $lines, 'skipped' => $skipped];
    }

    /** حسابِ بانکی/صندوقِ کاربر (نه چک و تهاتر). */
    private static function account(int $userId, int $accountId): ?array
    {
        $st = Database::getConnection()->prepare('SELECT id, name, kind FROM biz_accounts WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $accountId, 'u' => $userId]);
        $a = $st->fetch() ?: null;
        if ($a === null || BizCash::isCheque($a) || (string)$a['kind'] === BizCash::CLEARING_KIND) { return null; }
        return $a;
    }

    /**
     * ⛔ ردیف‌ها با «اثرِ انگشت» (تاریخ، مبلغ، شرح، و چندمین بارِ همین سه در همین فایل)
     *    نوشته می‌شوند: واردِ دوباره‌ی همان صورت‌حساب تکراری نمی‌سازد، ولی دو واریزِ
     *    هم‌مبلغِ یک روز دو ردیف می‌مانند.
     * @return array{ok:bool, message:string, added?:int, dup?:int, matched?:int}
     */
    public static function import(int $userId, int $accountId, array $lines): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.']; }
        if (!self::account($userId, $accountId)) { return ['ok' => false, 'message' => 'حسابِ بانکی را انتخاب کنید.']; }
        $pdo = Database::getConnection();
        $ins = $pdo->prepare('INSERT IGNORE INTO biz_bank_lines (user_id, account_id, line_date, amount, descr, line_hash)
                              VALUES (:u, :a, :d, :am, :ds, :h)');
        $added = 0; $dup = 0; $occ = [];
        $pdo->beginTransaction();
        try {
            foreach ($lines as $l) {
                $base = $l['date'] . '|' . $l['amount'] . '|' . $l['desc'];
                $occ[$base] = ($occ[$base] ?? 0) + 1;
                $ins->execute(['u' => $userId, 'a' => $accountId, 'd' => $l['date'], 'am' => (int)$l['amount'],
                               'ds' => $l['desc'] === '' ? null : $l['desc'], 'h' => sha1($base . '|' . $occ[$base])]);
                $ins->rowCount() === 1 ? $added++ : $dup++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        $m = self::autoMatch($userId, $accountId);
        return ['ok' => true, 'added' => $added, 'dup' => $dup, 'matched' => $m,
                'message' => toPersianDigits((string)$added) . ' ردیف وارد شد' . ($dup ? '، ' . toPersianDigits((string)$dup) . ' ردیفِ تکراری کنار رفت' : '')
                           . '؛ ' . toPersianDigits((string)$m) . ' ردیف خودکار با سندش جفت شد.'];
    }

    /** مبلغِ علامت‌دارِ یک سند برای یک حساب (+ ورود، − خروج). */
    private static function signedSql(): string
    {
        $in = BizPay::sqlList(BizPay::IN_KINDS);
        return "CASE WHEN y.account_id = :a1 THEN (CASE WHEN y.kind IN ({$in}) THEN y.amount ELSE -y.amount END) ELSE y.amount END";
    }

    /** اسنادِ زنده‌ی این حساب که هنوز با هیچ ردیفی جفت نشده‌اند. */
    public static function openPayments(int $userId, int $accountId, string $from = '', string $to = ''): array
    {
        if (!self::ready()) { return []; }
        $range = $from !== '' && $to !== '' ? ' AND y.pay_date BETWEEN :f AND :t' : '';
        $st = Database::getConnection()->prepare(
            'SELECT y.id, y.kind, y.number, y.pay_date, y.title, y.method, p.name AS party_name, ' . self::signedSql() . ' AS signed
             FROM biz_payments y LEFT JOIN biz_parties p ON p.id = y.party_id AND p.user_id = y.user_id
             WHERE y.user_id = :u AND y.status = \'ok\' AND (y.account_id = :a2 OR (y.to_account_id = :a3 AND y.kind = \'transfer\'))' . $range . '
               AND NOT EXISTS (SELECT 1 FROM biz_bank_lines b WHERE b.user_id = y.user_id AND b.account_id = :a4 AND b.payment_id = y.id)
             ORDER BY y.pay_date, y.id LIMIT 2000');
        $p = ['u' => $userId, 'a1' => $accountId, 'a2' => $accountId, 'a3' => $accountId, 'a4' => $accountId];
        if ($range !== '') { $p['f'] = $from; $p['t'] = $to; }
        $st->execute($p);
        return $st->fetchAll();
    }

    /**
     * ⛔ جفتِ خودکار: هم‌مبلغ (با علامت) و در `WINDOW_DAYS` روز، نزدیک‌ترین تاریخ اول؛ هر
     *    سند فقط یک ردیف. جفتِ سندی که بعداً باطل شده اول آزاد می‌شود.
     * @return int شمارِ جفت‌های تازه
     */
    public static function autoMatch(int $userId, int $accountId): int
    {
        if (!self::ready()) { return 0; }
        $pdo = Database::getConnection();
        $pdo->prepare("UPDATE biz_bank_lines b LEFT JOIN biz_payments y ON y.id = b.payment_id AND y.user_id = b.user_id
                       SET b.payment_id = NULL, b.matched_how = NULL
                       WHERE b.user_id = :u AND b.account_id = :a AND b.payment_id IS NOT NULL AND (y.id IS NULL OR y.status <> 'ok')")
            ->execute(['u' => $userId, 'a' => $accountId]);
        $st = $pdo->prepare('SELECT id, line_date, amount FROM biz_bank_lines WHERE user_id = :u AND account_id = :a AND payment_id IS NULL ORDER BY line_date, id');
        $st->execute(['u' => $userId, 'a' => $accountId]);
        $lines = $st->fetchAll();
        if (!$lines) { return 0; }
        $pays = self::openPayments($userId, $accountId);
        $byAmt = [];
        foreach ($pays as $p) { $byAmt[(int)$p['signed']][] = $p; }
        $set = $pdo->prepare("UPDATE biz_bank_lines SET payment_id = :p, matched_how = 'auto' WHERE id = :id AND user_id = :u AND payment_id IS NULL");
        $n = 0;
        foreach ($lines as $l) {
            $cands = $byAmt[(int)$l['amount']] ?? [];
            $best = null; $bestGap = PHP_INT_MAX;
            foreach ($cands as $k => $p) {
                $gap = abs((int)((strtotime((string)$p['pay_date']) - strtotime((string)$l['line_date'])) / 86400));
                if ($gap <= self::WINDOW_DAYS && $gap < $bestGap) { $best = $k; $bestGap = $gap; }
            }
            if ($best === null) { continue; }
            $set->execute(['p' => (int)$cands[$best]['id'], 'id' => (int)$l['id'], 'u' => $userId]);
            if ($set->rowCount() === 1) { $n++; }
            unset($byAmt[(int)$l['amount']][$best]);
        }
        return $n;
    }

    /** @return array{rows:array, open:int, open_sum:int, matched:int, from:?string, to:?string} */
    public static function lines(int $userId, int $accountId, bool $openOnly = false, int $cap = 1000): array
    {
        if (!self::ready()) { return ['rows' => [], 'open' => 0, 'open_sum' => 0, 'matched' => 0, 'from' => null, 'to' => null]; }
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT b.*, y.kind AS pay_kind, y.number AS pay_number, y.pay_date
             FROM biz_bank_lines b LEFT JOIN biz_payments y ON y.id = b.payment_id AND y.user_id = b.user_id
             WHERE b.user_id = :u AND b.account_id = :a' . ($openOnly ? ' AND b.payment_id IS NULL' : '') . '
             ORDER BY b.line_date DESC, b.id DESC LIMIT :lim');
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('a', $accountId, PDO::PARAM_INT);
        $st->bindValue('lim', $cap, PDO::PARAM_INT);
        $st->execute();
        $s = $pdo->prepare('SELECT SUM(payment_id IS NULL), COALESCE(SUM(CASE WHEN payment_id IS NULL THEN amount ELSE 0 END), 0),
                                   SUM(payment_id IS NOT NULL), MIN(line_date), MAX(line_date)
                            FROM biz_bank_lines WHERE user_id = :u AND account_id = :a');
        $s->execute(['u' => $userId, 'a' => $accountId]);
        $r = $s->fetch(PDO::FETCH_NUM) ?: [0, 0, 0, null, null];
        return ['rows' => $st->fetchAll(), 'open' => (int)$r[0], 'open_sum' => (int)$r[1], 'matched' => (int)$r[2],
                'from' => $r[3] !== null ? (string)$r[3] : null, 'to' => $r[4] !== null ? (string)$r[4] : null];
    }

    /** ⛔ جفتِ دستی: فقط سندِ زنده‌ی **همین حساب** با **همان مبلغِ علامت‌دار**. */
    public static function match(int $userId, int $lineId, int $paymentId): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $pdo = Database::getConnection();
        $l = $pdo->prepare('SELECT account_id, amount, payment_id FROM biz_bank_lines WHERE id = :id AND user_id = :u');
        $l->execute(['id' => $lineId, 'u' => $userId]);
        $line = $l->fetch();
        if (!$line) { return ['ok' => false, 'message' => 'ردیفِ صورت‌حساب پیدا نشد.']; }
        if ($line['payment_id'] !== null) { return ['ok' => false, 'message' => 'این ردیف از قبل جفت شده است.']; }
        $acc = (int)$line['account_id'];
        foreach (self::openPayments($userId, $acc) as $p) {
            if ((int)$p['id'] !== $paymentId) { continue; }
            if ((int)$p['signed'] !== (int)$line['amount']) { return ['ok' => false, 'message' => 'مبلغِ سند با ردیفِ بانک یکی نیست.']; }
            $pdo->prepare("UPDATE biz_bank_lines SET payment_id = :p, matched_how = 'manual' WHERE id = :id AND user_id = :u AND payment_id IS NULL")
                ->execute(['p' => $paymentId, 'id' => $lineId, 'u' => $userId]);
            return ['ok' => true, 'message' => 'جفت شد.'];
        }
        return ['ok' => false, 'message' => 'سند پیدا نشد، باطل است، یا از قبل با ردیفِ دیگری جفت شده است.'];
    }

    public static function unmatch(int $userId, int $lineId): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $st = Database::getConnection()->prepare('UPDATE biz_bank_lines SET payment_id = NULL, matched_how = NULL WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $lineId, 'u' => $userId]);
        return ['ok' => true, 'message' => 'جفت برداشته شد.'];
    }

    public static function remove(int $userId, int $lineId): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $st = Database::getConnection()->prepare('DELETE FROM biz_bank_lines WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $lineId, 'u' => $userId]);
        return ['ok' => true, 'message' => 'ردیف از صورت‌حساب حذف شد.'];
    }
}

final class BizRecurring
{
    public const TITLE_MAX = 150;
    /** دوره‌ها (ماه). */
    public const EVERY = [1 => 'ماهانه', 2 => 'هر دو ماه', 3 => 'سه‌ماهه', 6 => 'شش‌ماهه', 12 => 'سالانه'];

    public static function ready(): bool
    {
        return tableHasColumn('biz_recurring', 'every_months');
    }

    /** ⛔ سررسیدِ دوره‌ی `n`ام — همیشه از تاریخِ شروع با روزِ لنگر، هرگز از سررسیدِ قبلی. */
    public static function dueAt(array $r, int $n): string
    {
        $start = (string)$r['start_date'];
        return jalaliAddMonths($start, $n * (int)$r['every_months'], jalaliDayOfDate($start));
    }

    public static function nextDue(array $r): string
    {
        return self::dueAt($r, (int)$r['posted']);
    }

    /** @return array{ok:bool, message:string, id?:int} */
    public static function save(int $userId, array $in, int $id = 0): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.']; }
        $title = BizCommon::line((string)($in['title'] ?? ''));
        $amount = sanitizeAmount($in['amount'] ?? '');
        $every = (int)toLatinDigits((string)($in['every_months'] ?? '1'));
        $start = (string)($in['start_date'] ?? '');
        $cat = (int)($in['category_id'] ?? 0);
        $acc = (int)($in['account_id'] ?? 0);
        if ($title === '' && $cat > 0 && ($c = BizExpCats::get($userId, $cat))) { $title = (string)$c['name']; }
        if ($title === '') { return ['ok' => false, 'message' => 'شرح را بنویسید (مثلاً اجاره‌ی مغازه).']; }
        if (mb_strlen($title) > self::TITLE_MAX) { return ['ok' => false, 'message' => 'شرح بیش از ' . self::TITLE_MAX . ' نویسه است.']; }
        if ($amount <= 0) { return ['ok' => false, 'message' => 'مبلغ را بنویسید.']; }
        if (($e = BizCommon::moneyError($amount, 'مبلغ')) !== null) { return ['ok' => false, 'message' => $e]; }
        if (!isset(self::EVERY[$every])) { return ['ok' => false, 'message' => 'دوره معتبر نیست.']; }
        if (!isValidDate($start)) { return ['ok' => false, 'message' => 'تاریخِ اولین سررسید معتبر نیست.']; }
        if ($cat > 0) {
            $c = BizExpCats::get($userId, $cat);
            if (!$c || $c['kind'] !== 'expense') { return ['ok' => false, 'message' => 'سرفصلِ هزینه پیدا نشد.']; }
        }
        if ($acc > 0 && !self::accountOk($userId, $acc)) { return ['ok' => false, 'message' => 'صندوق پیدا نشد.']; }
        $pdo = Database::getConnection();
        $row = ['t' => $title, 'a' => $amount, 'c' => $cat ?: null, 'ac' => $acc ?: null, 'e' => $every, 's' => $start, 'u' => $userId];
        if ($id > 0) {
            // ⛔ تاریخِ شروع لنگرِ همه‌ی دوره‌هاست؛ عوض کردنش شمارِ ثبت‌شده را صفر می‌کند
            $cur = self::get($userId, $id);
            if (!$cur) { return ['ok' => false, 'message' => 'پیدا نشد.']; }
            $reset = $cur['start_date'] !== $start || (int)$cur['every_months'] !== $every;
            $pdo->prepare('UPDATE biz_recurring SET title = :t, amount = :a, category_id = :c, account_id = :ac, every_months = :e, start_date = :s'
                . ($reset ? ', posted = 0' : '') . ' WHERE id = :id AND user_id = :u')->execute($row + ['id' => $id]);
            return ['ok' => true, 'message' => 'ذخیره شد.', 'id' => $id];
        }
        $pdo->prepare('INSERT INTO biz_recurring (user_id, title, amount, category_id, account_id, every_months, start_date)
                       VALUES (:u, :t, :a, :c, :ac, :e, :s)')->execute($row);
        return ['ok' => true, 'message' => 'هزینه‌ی تکراری ساخته شد.', 'id' => (int)$pdo->lastInsertId()];
    }

    private static function accountOk(int $userId, int $acc): bool
    {
        $st = Database::getConnection()->prepare('SELECT kind, is_active FROM biz_accounts WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $acc, 'u' => $userId]);
        $a = $st->fetch();
        return $a !== false && (int)$a['is_active'] === 1 && !BizCash::isCheque($a) && (string)$a['kind'] !== BizCash::CLEARING_KIND;
    }

    public static function get(int $userId, int $id): ?array
    {
        if (!self::ready()) { return null; }
        $st = Database::getConnection()->prepare('SELECT * FROM biz_recurring WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->fetch() ?: null;
    }

    /** @return list<array> با `next_due` و `overdue` */
    public static function list(int $userId, bool $activeOnly = false, ?string $today = null): array
    {
        if (!self::ready()) { return []; }
        $st = Database::getConnection()->prepare('SELECT * FROM biz_recurring WHERE user_id = :u' . ($activeOnly ? ' AND is_active = 1' : '')
            . ' ORDER BY is_active DESC, title, id');
        $st->execute(['u' => $userId]);
        $today = $today ?? today();
        return array_map(function ($r) use ($today) {
            $r['next_due'] = self::nextDue($r);
            $r['overdue'] = (int)$r['is_active'] === 1 && $r['next_due'] <= $today;
            return $r;
        }, $st->fetchAll());
    }

    /** سررسیدهای پیشِ رو تا `$until` (پیش‌بینیِ نقد). @return list<array{date:string, amount:int, title:string, id:int}> */
    public static function occurrences(int $userId, string $until): array
    {
        $out = [];
        foreach (self::list($userId, true) as $r) {
            for ($n = (int)$r['posted'], $i = 0; $i < 60; $n++, $i++) {
                $d = self::dueAt($r, $n);
                if ($d > $until) { break; }
                $out[] = ['date' => $d, 'amount' => (int)$r['amount'], 'title' => (string)$r['title'], 'id' => (int)$r['id']];
            }
        }
        usort($out, fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        return $out;
    }

    /**
     * ⛔ ثبتِ دوره‌ی جاری = یک «هزینه‌ی فروشگاه»ِ واقعی از `BizPay::createTx()` (سدِ دوره‌ی
     *    بسته، صندوق، سرفصل)، و شمارنده در **همان** تراکنش با شرط جلو می‌رود — دو کلیکِ
     *    هم‌زمان دو هزینه نمی‌سازند.
     * @return array{ok:bool, message:string, payment_id?:int}
     */
    public static function post(int $userId, int $id, array $in = []): array
    {
        $r = self::get($userId, $id);
        if (!$r) { return ['ok' => false, 'message' => 'پیدا نشد.']; }
        if ((int)$r['is_active'] !== 1) { return ['ok' => false, 'message' => 'این هزینه‌ی تکراری غیرفعال است.']; }
        $due = self::nextDue($r);
        $date = isValidDate((string)($in['date'] ?? '')) ? (string)$in['date'] : $due;
        $acc = (int)($in['account_id'] ?? 0) ?: (int)$r['account_id'];
        $amount = trim((string)($in['amount'] ?? '')) !== '' ? (string)$in['amount'] : (string)$r['amount'];
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        Biz::lockShop($pdo, $userId);
        try {
            $adv = $pdo->prepare('UPDATE biz_recurring SET posted = posted + 1 WHERE id = :id AND user_id = :u AND posted = :p');
            $adv->execute(['id' => $id, 'u' => $userId, 'p' => (int)$r['posted']]);
            if ($adv->rowCount() !== 1) { $pdo->rollBack(); return ['ok' => false, 'message' => 'این دوره همین حالا ثبت شد.']; }
            $res = BizPay::createTx($pdo, $userId, ['kind' => 'expense', 'amount' => $amount, 'account_id' => $acc, 'pay_date' => $date,
                'method' => 'cash', 'title' => (string)$r['title'], 'category_id' => (int)($r['category_id'] ?? 0),
                'note' => 'هزینه‌ی تکراری — سررسیدِ ' . toJalali($due)]);
            if (!$res['ok']) { $pdo->rollBack(); return $res; }
            $pdo->commit();
            return ['ok' => true, 'payment_id' => (int)$res['id'], 'message' => '«' . $r['title'] . '» برای سررسیدِ ' . toJalali($due) . ' ثبت شد.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** رد کردنِ یک دوره (مثلاً اجاره‌ای که این ماه بخشیده شد) — بی‌سند. */
    public static function skip(int $userId, int $id): array
    {
        $r = self::get($userId, $id);
        if (!$r) { return ['ok' => false, 'message' => 'پیدا نشد.']; }
        $st = Database::getConnection()->prepare('UPDATE biz_recurring SET posted = posted + 1 WHERE id = :id AND user_id = :u AND posted = :p');
        $st->execute(['id' => $id, 'u' => $userId, 'p' => (int)$r['posted']]);
        return $st->rowCount() === 1 ? ['ok' => true, 'message' => 'سررسیدِ ' . toJalali(self::nextDue($r)) . ' بی‌ثبت رد شد.']
                                     : ['ok' => false, 'message' => 'همین حالا عوض شد؛ دوباره ببینید.'];
    }

    public static function setActive(int $userId, int $id, bool $on): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $st = Database::getConnection()->prepare('UPDATE biz_recurring SET is_active = :a WHERE id = :id AND user_id = :u');
        $st->execute(['a' => $on ? 1 : 0, 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => $on ? 'فعال شد.' : 'غیرفعال شد.'];
    }

    public static function remove(int $userId, int $id): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        Database::getConnection()->prepare('DELETE FROM biz_recurring WHERE id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => 'حذف شد؛ هزینه‌هایی که قبلاً ثبت شده‌اند می‌مانند.'];
    }
}
