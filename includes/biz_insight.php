<?php
/**
 * ⛔ نگاه به جلو و هشدار — فقط خواندن، فقط از اسنادِ موجود (هیچ جدول و هیچ عددِ ذخیره‌شده‌ای):
 *
 * - `BizReorder`  «چه بخرم»: کف‌ِ موجودی + سرعتِ فروشِ روزهای اخیر؛ «سفارش» فقط **پیش‌نویسِ**
 *                 فاکتورِ خرید است (`saveDraft`) و تا صدور هیچ اثری ندارد.
 * - `BizForecast` پیش‌بینیِ نقد: موجودیِ امروزِ صندوق‌ها (`BizCash::total()`) + چکِ در جریان،
 *                 قسطِ باز و هزینه‌ی تکراری، هفته به هفته.
 * - `BizAlerts`   هشدارها؛ هر کدام از تابعِ همان بخش (`lowStock()`، `BizCheques::due()`، …) نه
 *                 کوئریِ دوم با تعریفِ دیگر.
 * - `BizBranches` سود و زیانِ هر انبار/شعبه — همان فرمولِ `BizReports::sales()` گروه‌شده با
 *                 `biz_invoices.warehouse_id`؛ هزینه‌ی فروشگاه شعبه ندارد و جدا می‌آید.
 * - `BizBarcode`  Code 128 به SVG (بی‌کتابخانه) برای برچسبِ کالا و IMEI.
 */

require_once __DIR__ . '/biz_reports.php';
require_once __DIR__ . '/biz_sales_ext.php';
require_once __DIR__ . '/biz_quickbuy.php';   // `BizQuickBuy::suppliers()` — فهرستِ فروشنده‌های پیشنهادِ خرید

final class BizReorder
{
    public const DAYS  = 30;   // سرعتِ فروش از چند روزِ گذشته
    public const COVER = 30;   // موجودی برای چند روزِ آینده

    /**
     * ⛔ هدف = بیشترِ «حداقل موجودی» و «فروشِ روزانه × روزهای پوشش»؛ پیشنهاد = هدف − موجودی
     *    (واحدِ شمارشی ⇒ رو به بالا گرد). کالای بی‌فروش و بی‌حداقل پیشنهاد نمی‌گیرد.
     * @return list<array>
     */
    public static function suggest(int $userId, int $days = self::DAYS, int $cover = self::COVER, ?string $today = null): array
    {
        $today = $today ?? today();
        $from = date('Y-m-d', strtotime($today . ' -' . max(1, $days) . ' days'));
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            "SELECT p.id, p.name, p.unit, p.stock_qty, p.min_stock, p.has_serial, p.buy_price,
                    COALESCE(s.sold, 0) AS sold
             FROM biz_products p
             LEFT JOIN (SELECT l.product_id, SUM(CASE WHEN i.kind = 'sale' THEN l.qty ELSE -l.qty END) AS sold
                        FROM biz_invoices i JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
                        WHERE i.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return')
                          AND i.inv_date > :f AND i.inv_date <= :t AND l.product_id IS NOT NULL
                        GROUP BY l.product_id) s ON s.product_id = p.id
             WHERE p.user_id = :u2 AND p.is_active = 1 AND p.track_stock = 1 AND (p.min_stock > 0 OR s.sold > 0)"
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $today, 'u2' => $userId]);
        $out = [];
        foreach ($st->fetchAll() as $p) {
            $sold = max(0.0, (float)$p['sold']);
            $vel = $sold / max(1, $days);
            $target = max((float)$p['min_stock'], $vel * $cover);
            $stock = (float)$p['stock_qty'];
            $need = $target - $stock;
            if ($need <= 0.0005) { continue; }
            $need = BizProducts::qtyFits((string)$p['unit'], 1.5) ? round($need, 3) : (float)ceil($need - 0.0005);
            $out[(int)$p['id']] = [
                'id' => (int)$p['id'], 'name' => (string)$p['name'], 'unit' => (string)$p['unit'], 'stock' => $stock,
                'min' => (float)$p['min_stock'], 'sold' => $sold, 'per_day' => round($vel, 3),
                'days_left' => $vel > 0 ? (int)floor(max(0, $stock) / $vel) : null,
                'suggest' => $need, 'buy_price' => (int)$p['buy_price'], 'serial' => (int)$p['has_serial'] === 1,
                'supplier_id' => 0, 'supplier' => '',
            ];
        }
        if (!$out) { return []; }
        // آخرین فروشنده‌ی هر کالا — یک کوئری برای همه (شناسه‌های عددیِ همین کوئری)
        $ids = implode(',', array_keys($out));
        $ls = $pdo->prepare("SELECT l.product_id, i.party_id, pa.name FROM biz_invoices i
                             JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
                             JOIN biz_parties pa ON pa.id = i.party_id AND pa.user_id = i.user_id
                             WHERE i.user_id = :u AND i.status = 'issued' AND i.kind = 'purchase' AND l.product_id IN ({$ids})
                             ORDER BY i.inv_date DESC, i.id DESC");
        $ls->execute(['u' => $userId]);
        foreach ($ls->fetchAll() as $r) {
            $pid = (int)$r['product_id'];
            if ($out[$pid]['supplier_id'] === 0) { $out[$pid]['supplier_id'] = (int)$r['party_id']; $out[$pid]['supplier'] = (string)$r['name']; }
        }
        $rows = array_values($out);
        usort($rows, fn($a, $b) => [$a['days_left'] ?? PHP_INT_MAX, $a['name']] <=> [$b['days_left'] ?? PHP_INT_MAX, $b['name']]);
        // کالای بی‌فروش (فقط زیرِ حداقل) پس از کالای در حالِ تمام شدن
        return $rows;
    }

    /**
     * ⛔ «سفارش» = یک **پیش‌نویسِ** فاکتورِ خرید برای هر فروشنده (بی‌فروشنده = یک پیش‌نویسِ
     *    بی‌طرف‌حساب). گوشی کنار می‌ماند: بی‌IMEI ردیفِ خرید ساخته نمی‌شود — از «+ موجودی».
     * @param array<int,string> $qtys  شناسه‌ی کالا ⇒ تعداد
     * @param array<int,int>    $suppliers شناسه‌ی کالا ⇒ فروشنده (۰ = بی‌فروشنده)
     * @return array{ok:bool, message:string, drafts?:list<int>, skipped?:list<string>}
     */
    public static function makeDrafts(int $userId, array $qtys, array $suppliers = []): array
    {
        $groups = []; $skipped = [];
        foreach ($qtys as $pid => $q) {
            $pid = (int)$pid;
            $qty = sanitizeQty((string)$q);
            if ($pid <= 0 || $qty <= 0) { continue; }
            $p = BizProducts::get($userId, $pid);
            if (!$p || (int)$p['track_stock'] !== 1) { continue; }
            if ((int)$p['has_serial'] === 1) { $skipped[] = (string)$p['name']; continue; }
            $sup = (int)($suppliers[$pid] ?? 0);
            if ($sup > 0 && !BizParties::get($userId, $sup)) { $sup = 0; }
            $groups[$sup][] = ['item' => (string)$p['name'], 'product_id' => $pid, 'qty' => (string)$qty, 'price' => ''];
        }
        if (!$groups) {
            return ['ok' => false, 'skipped' => $skipped, 'message' => $skipped
                ? 'گوشی‌ها با IMEI خریده می‌شوند؛ از «+ موجودی» در صفحه‌ی همان کالا.' : 'هیچ کالایی با تعدادِ بیشتر از صفر انتخاب نشد.'];
        }
        $drafts = [];
        foreach ($groups as $sup => $lines) {
            $r = BizInvoices::saveDraft($userId, 'purchase', ['party_id' => $sup, 'inv_date' => today(), 'lines' => $lines,
                                                             'note' => 'سفارشِ خرید (پیشنهادِ خرید)']);
            if (!$r['ok']) { return ['ok' => false, 'message' => $r['message'], 'drafts' => $drafts]; }
            $drafts[] = (int)$r['id'];
        }
        return ['ok' => true, 'drafts' => $drafts, 'skipped' => $skipped,
                'message' => toPersianDigits((string)count($drafts)) . ' پیش‌نویسِ فاکتورِ خرید ساخته شد؛ با رسیدنِ کالا صادرش کنید.'
                           . ($skipped ? ' گوشی‌ها (' . implode('، ', $skipped) . ') با IMEI از «+ موجودی» خریده می‌شوند.' : '')];
    }
}

final class BizForecast
{
    public const WEEKS = 8;

    /**
     * ⛔ هفته‌ی ۰ از امروز؛ هر چیزِ سررسیدگذشته (چک، قسط، هزینه‌ی تکراری) در هفته‌ی ۰ می‌آید
     *    — پولی است که همین حالا باید بیاید یا برود. مانده‌ی فاکتورِ بی‌سررسید پیش‌بینی
     *    **نمی‌شود** (تاریخ ندارد) و فقط جدا گزارش می‌شود.
     * @return array{start:int, weeks:list<array>, first_negative:?int, open_sale:int, open_purchase:int}
     */
    public static function weeks(int $userId, int $weeks = self::WEEKS, ?string $today = null): array
    {
        $today = $today ?? today();
        $end = date('Y-m-d', strtotime($today . ' +' . ($weeks * 7 - 1) . ' days'));
        $start = BizCash::total(BizCash::list($userId, false, $today));
        $buckets = [];
        for ($w = 0; $w < $weeks; $w++) {
            $f = date('Y-m-d', strtotime($today . ' +' . ($w * 7) . ' days'));
            $buckets[$w] = ['from' => $f, 'to' => date('Y-m-d', strtotime($f . ' +6 days')), 'in' => 0, 'out' => 0, 'items' => []];
        }
        $put = function (string $date, int $amount, string $label) use (&$buckets, $today, $weeks) {
            $w = $date <= $today ? 0 : intdiv((int)round((strtotime($date) - strtotime($today)) / 86400), 7);
            if ($w >= $weeks) { return; }
            $amount >= 0 ? $buckets[$w]['in'] += $amount : $buckets[$w]['out'] += -$amount;
            $buckets[$w]['items'][] = ['date' => $date, 'amount' => $amount, 'label' => $label];
        };
        $pdo = Database::getConnection();
        if (BizCheques::ready()) {
            $st = $pdo->prepare("SELECT y.kind, y.amount, y.cheque_due, y.cheque_no, p.name FROM biz_payments y
                                 LEFT JOIN biz_parties p ON p.id = y.party_id AND p.user_id = y.user_id
                                 WHERE y.user_id = :u AND y.status = 'ok' AND y.cheque_status = 'pending' AND y.cheque_due <= :e");
            $st->execute(['u' => $userId, 'e' => $end]);
            foreach ($st->fetchAll() as $c) {
                $in = $c['kind'] === 'receipt';
                $put((string)$c['cheque_due'], $in ? (int)$c['amount'] : -(int)$c['amount'],
                     ($in ? 'چکِ دریافتی' : 'چکِ پرداختی') . ($c['name'] !== null ? ' — ' . $c['name'] : ''));
            }
        }
        foreach (BizInstallments::open($userId, $end, $today) as $q) {
            $put((string)$q['due_date'], (int)$q['remaining'], 'قسطِ ' . ($q['party_name'] !== '' ? $q['party_name'] : 'مشتری'));
        }
        foreach (BizRecurring::occurrences($userId, $end) as $r) {
            $put($r['date'], -$r['amount'], $r['title']);
        }
        $bal = $start; $neg = null;
        foreach ($buckets as $w => &$b) {
            $b['net'] = $b['in'] - $b['out'];
            $bal += $b['net'];
            $b['balance'] = $bal;
            if ($neg === null && $bal < 0) { $neg = $w; }
            usort($b['items'], fn($x, $y) => $x['date'] <=> $y['date']);
        }
        unset($b);
        $o = $pdo->prepare("SELECT kind, COALESCE(SUM(total - paid), 0) FROM biz_invoices
                            WHERE user_id = :u AND status = 'issued' AND kind IN ('sale','purchase') AND paid < total GROUP BY kind");
        $o->execute(['u' => $userId]);
        $open = ['sale' => 0, 'purchase' => 0];
        foreach ($o->fetchAll(PDO::FETCH_NUM) as [$k, $v]) { $open[$k] = (int)$v; }
        return ['start' => $start, 'weeks' => array_values($buckets), 'first_negative' => $neg,
                'open_sale' => $open['sale'], 'open_purchase' => $open['purchase']];
    }
}

final class BizAlerts
{
    public const BELOW_COST_DAYS = 30;
    public const DEAD_DAYS = 60;
    public const SOON_DAYS = 3;
    public const CONSIGN_DAYS = 30;
    public const LIMIT = 10;

    /**
     * @param bool $cost دیدنِ بهای خرید و سود (`Biz::can('cost')`) — بی‌آن «فروشِ زیرِ بها» و ارزشِ
     *                   کالای خوابیده نمی‌آیند.
     * @return list<array{key:string, title:string, tone:string, items:list<array{text:string, href:string}>}>
     */
    public static function all(int $userId, bool $cost, ?string $today = null): array
    {
        $today = $today ?? today();
        $pdo = Database::getConnection();
        $out = [];
        $u = fn(string $p) => Biz::url($p);

        // ۱. چکِ سررسیدِ نزدیک یا گذشته
        $items = [];
        foreach (BizCheques::due($userId, self::SOON_DAYS, self::LIMIT) as $c) {
            $d = (int)$c['days'];
            $items[] = ['text' => ($c['kind'] === 'receipt' ? 'چکِ دریافتی' : 'چکِ پرداختی') . ' ' . formatMoney((int)$c['amount']) . ' تومان'
                . ($c['party_name'] ? ' — ' . $c['party_name'] : '') . ' · ' . ($d < 0 ? toPersianDigits((string)-$d) . ' روز گذشته' : ($d === 0 ? 'امروز' : toPersianDigits((string)$d) . ' روزِ دیگر')),
                'href' => $u('cheques.php')];
        }
        $out[] = ['key' => 'cheques', 'title' => 'چک‌های سررسیدِ نزدیک', 'tone' => 'warn', 'items' => $items];

        // ۲. قسطِ عقب‌افتاده
        $items = [];
        foreach (BizInstallments::open($userId, date('Y-m-d', strtotime($today . ' -1 day')), $today) as $q) {
            if (count($items) >= self::LIMIT) { break; }
            $items[] = ['text' => 'قسطِ ' . ($q['party_name'] !== '' ? $q['party_name'] : 'مشتری') . ' — ' . formatMoney((int)$q['remaining'])
                . ' تومان · سررسید ' . toJalali((string)$q['due_date']), 'href' => $u('invoice.php?id=' . (int)$q['invoice_id'])];
        }
        $out[] = ['key' => 'installments', 'title' => 'اقساطِ عقب‌افتاده', 'tone' => 'bad', 'items' => $items];

        // ۳. مشتریِ بالای سقفِ اعتبار
        $items = [];
        if (BizParties::termsReady()) {
            $st = $pdo->prepare('SELECT * FROM (SELECT p.id, p.name, p.credit_limit, ' . BizParties::BALANCE_SQL . ' AS bal
                                 FROM biz_parties p WHERE p.user_id = :u AND p.is_active = 1 AND p.credit_limit > 0) x
                                 WHERE x.bal > x.credit_limit ORDER BY x.bal - x.credit_limit DESC LIMIT ' . self::LIMIT);
            $st->execute(['u' => $userId]);
            foreach ($st->fetchAll() as $p) {
                $items[] = ['text' => $p['name'] . ' — بدهی ' . formatMoney((int)$p['bal']) . ' از سقفِ ' . formatMoney((int)$p['credit_limit']) . ' تومان',
                            'href' => $u('party.php?id=' . (int)$p['id'])];
            }
        }
        $out[] = ['key' => 'credit', 'title' => 'بالای سقفِ اعتبار', 'tone' => 'bad', 'items' => $items];

        // ۴. فروشِ زیرِ بهای خرید (فقط با دسترسیِ «بها و سود»)
        if ($cost) {
            $st = $pdo->prepare("SELECT i.id, i.number, i.inv_date, l.description, l.net_total, ROUND(l.unit_cost * l.qty) AS cost
                                 FROM biz_invoices i JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
                                 WHERE i.user_id = :u AND i.status = 'issued' AND i.kind = 'sale' AND i.inv_date >= :f
                                   AND l.unit_cost IS NOT NULL AND l.net_total < ROUND(l.unit_cost * l.qty)
                                 ORDER BY ROUND(l.unit_cost * l.qty) - l.net_total DESC LIMIT " . self::LIMIT);
            $st->execute(['u' => $userId, 'f' => date('Y-m-d', strtotime($today . ' -' . self::BELOW_COST_DAYS . ' days'))]);
            $items = [];
            foreach ($st->fetchAll() as $r) {
                $items[] = ['text' => $r['description'] . ' — فاکتورِ ' . toPersianDigits((string)$r['number']) . ': '
                    . formatMoney((int)$r['cost'] - (int)$r['net_total']) . ' تومان زیرِ بهای خرید', 'href' => $u('invoice.php?id=' . (int)$r['id'])];
            }
            $out[] = ['key' => 'below_cost', 'title' => 'فروشِ زیرِ بهای خرید (۳۰ روزِ اخیر)', 'tone' => 'bad', 'items' => $items];
        }

        // ۵. کم‌موجودی — همان `lowStock()`ِ داشبورد
        $items = [];
        foreach (BizProducts::lowStock($userId, self::LIMIT) as $p) {
            $items[] = ['text' => $p['name'] . ' — موجودی ' . formatQty((float)$p['stock_qty']) . ' ' . $p['unit']
                . ((float)$p['min_stock'] > 0 ? ' (حداقل ' . formatQty((float)$p['min_stock']) . ')' : ''), 'href' => $u('product.php?id=' . (int)$p['id'])];
        }
        $out[] = ['key' => 'low', 'title' => 'کم‌موجودی و تمام‌شده', 'tone' => 'warn', 'items' => $items];

        // ۶. کالای خوابیده: موجودی دارد، دو ماه فروش نداشته، و تازه هم تعریف نشده
        $st = $pdo->prepare("SELECT p.id, p.name, p.unit, p.stock_qty, ROUND(p.stock_qty * p.avg_cost) AS val
                             FROM biz_products p
                             WHERE p.user_id = :u AND p.is_active = 1 AND p.track_stock = 1 AND p.stock_qty > 0 AND p.created_at < :c
                               AND NOT EXISTS (SELECT 1 FROM biz_invoice_lines l JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
                                               WHERE l.user_id = p.user_id AND l.product_id = p.id AND i.kind = 'sale' AND i.status = 'issued' AND i.inv_date >= :c2)
                             ORDER BY val DESC, p.name LIMIT " . self::LIMIT);
        $cut = date('Y-m-d', strtotime($today . ' -' . self::DEAD_DAYS . ' days'));
        $st->execute(['u' => $userId, 'c' => $cut . ' 00:00:00', 'c2' => $cut]);
        $items = [];
        foreach ($st->fetchAll() as $p) {
            $items[] = ['text' => $p['name'] . ' — ' . formatQty((float)$p['stock_qty']) . ' ' . $p['unit']
                . ($cost ? ' به ارزشِ ' . formatMoney((int)$p['val']) . ' تومان' : ''), 'href' => $u('product.php?id=' . (int)$p['id'])];
        }
        $out[] = ['key' => 'dead', 'title' => 'کالای خوابیده (۶۰ روز بی‌فروش)', 'tone' => 'info', 'items' => $items];

        // ۷. هزینه‌ی تکراریِ سررسیدشده
        $items = [];
        foreach (BizRecurring::list($userId, true, $today) as $r) {
            if ($r['overdue']) {
                $items[] = ['text' => $r['title'] . ' — ' . formatMoney((int)$r['amount']) . ' تومان · سررسید ' . toJalali($r['next_due']),
                            'href' => $u('recurring.php')];
            }
        }
        $out[] = ['key' => 'recurring', 'title' => 'هزینه‌های تکراریِ سررسیدشده', 'tone' => 'warn', 'items' => array_slice($items, 0, self::LIMIT)];

        // ۸. امانیِ کهنه — یا بخرید یا پس بدهید
        $items = [];
        if (BizConsign::ready()) {
            $st = $pdo->prepare("SELECT c.id, c.received_on, p.name AS party_name, pr.name AS product_name, c.imei1
                                 FROM biz_consignments c JOIN biz_parties p ON p.id = c.party_id AND p.user_id = c.user_id
                                 JOIN biz_products pr ON pr.id = c.product_id AND pr.user_id = c.user_id
                                 WHERE c.user_id = :u AND c.status = 'held' AND c.received_on <= :d ORDER BY c.received_on LIMIT " . self::LIMIT);
            $st->execute(['u' => $userId, 'd' => date('Y-m-d', strtotime($today . ' -' . self::CONSIGN_DAYS . ' days'))]);
            foreach ($st->fetchAll() as $c) {
                $items[] = ['text' => $c['product_name'] . ($c['imei1'] ? ' (' . $c['imei1'] . ')' : '') . ' — امانیِ ' . $c['party_name']
                    . ' از ' . toJalali((string)$c['received_on']), 'href' => $u('consignments.php')];
            }
        }
        $out[] = ['key' => 'consign', 'title' => 'امانیِ بیش از یک ماه', 'tone' => 'info', 'items' => $items];

        return $out;
    }

    /** شمارِ همه‌ی هشدارها. */
    public static function count(array $all): int
    {
        return array_sum(array_map(fn($s) => count($s['items']), $all));
    }
}

final class BizBranches
{
    /**
     * فروشِ خالص، بهای تمام‌شده و سودِ ناخالصِ هر انبار (`NULL` = انبارِ اصلی) — همان
     * تعریفِ `BizReports::sales()` (فقط صادرشده، خالصِ برگشت، بها از `unit_cost`ِ ردیف).
     * @return array{rows:list<array>, expense:int}
     */
    public static function pl(int $userId, string $from, string $to): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT COALESCE(i.warehouse_id, 0) AS wh, i.kind, COALESCE(SUM(l.net_total), 0) AS rev,
                    COALESCE(SUM(ROUND(COALESCE(l.unit_cost, 0) * l.qty)), 0) AS cost, COUNT(DISTINCT i.id) AS docs
             FROM biz_invoices i JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
             WHERE i.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return') AND i.inv_date BETWEEN :f AND :t
             GROUP BY COALESCE(i.warehouse_id, 0), i.kind"
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        $whs = BizWarehouses::list($userId);
        $by = [];
        foreach ($st->fetchAll() as $r) {
            $w = (int)$r['wh'];
            $by[$w] ??= ['wh' => $w, 'name' => BizWarehouses::nameOf($whs, $w ?: null), 'net' => 0, 'cogs' => 0, 'docs' => 0];
            $sign = $r['kind'] === 'sale' ? 1 : -1;
            $by[$w]['net']  += $sign * (int)$r['rev'];
            $by[$w]['cogs'] += $sign * (int)$r['cost'];
            if ($r['kind'] === 'sale') { $by[$w]['docs'] += (int)$r['docs']; }
        }
        $rows = array_map(function ($b) {
            $b['gross'] = $b['net'] - $b['cogs'];
            $b['margin'] = $b['net'] > 0 ? round($b['gross'] / $b['net'] * 100, 1) : null;
            return $b;
        }, array_values($by));
        usort($rows, fn($a, $b) => [$a['wh'] !== 0, $a['name']] <=> [$b['wh'] !== 0, $b['name']]);
        return ['rows' => $rows, 'expense' => BizReports::cash($userId, $from, $to)['expense']];
    }
}

final class BizBarcode
{
    /** الگوی میله/فاصله‌ی ۱۰۷ نمادِ Code 128 (۰ تا ۱۰۲ داده، ۱۰۳–۱۰۵ شروع، ۱۰۶ پایان). */
    private const P = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** متنِ قابلِ چاپ در Code 128-B (ASCII ۳۲ تا ۱۲۶)؟ */
    public static function encodable(string $s): bool
    {
        return $s !== '' && strlen($s) <= 48 && (bool)preg_match('/^[\x20-\x7E]+$/', $s);
    }

    /**
     * ⛔ نمادها: رقمِ زوج‌زوج با «C» (IMEIِ پانزده‌رقمی: هفت جفت + یک رقم با تعویض به B)،
     *    بقیه «B». چک‌سام = (شروع + Σ مقدار × جایگاه) mod ۱۰۳.
     * @return list<int>
     */
    public static function symbols(string $s): array
    {
        if (ctype_digit($s) && strlen($s) >= 4) {
            $codes = [105];
            $n = strlen($s) - (strlen($s) % 2);
            for ($i = 0; $i < $n; $i += 2) { $codes[] = (int)substr($s, $i, 2); }
            if ($n < strlen($s)) { $codes[] = 100; $codes[] = ord($s[$n]) - 32; }   // CODE B
        } else {
            $codes = [104];
            foreach (str_split($s) as $ch) { $codes[] = ord($ch) - 32; }
        }
        $sum = $codes[0];
        for ($i = 1, $c = count($codes); $i < $c; $i++) { $sum += $codes[$i] * $i; }
        $codes[] = $sum % 103;
        $codes[] = 106;
        return $codes;
    }

    /** پهنای ماژول‌ها (میله، فاصله، میله…) — بی‌حاشیه‌ی آرام. @return list<int> */
    public static function modules(string $s): array
    {
        $w = [];
        foreach (self::symbols($s) as $c) { foreach (str_split(self::P[$c]) as $d) { $w[] = (int)$d; } }
        return $w;
    }

    /** SVGِ بارکد (پهنای ماژول ۱ واحد، حاشیه‌ی آرامِ ۱۰ ماژول)؛ متنِ نامعتبر ⇒ `''`. */
    public static function svg(string $s, int $height = 40): string
    {
        if (!self::encodable($s)) { return ''; }
        $x = 10; $bars = '';
        foreach (self::modules($s) as $i => $w) {
            if ($i % 2 === 0) { $bars .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>'; }
            $x += $w;
        }
        $total = $x + 10;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="'
             . htmlspecialchars($s, ENT_QUOTES) . '"><g fill="#000">' . $bars . '</g></svg>';
    }
}
