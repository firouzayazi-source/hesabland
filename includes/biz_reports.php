<?php
/**
 * ⛔ گزارش‌های فروشگاه — فقط خواندن، فقط `biz_*`.
 *
 * هر عدد از **اسنادِ صادرشده** ساخته می‌شود (پیش‌نویس و باطل هیچ‌جا
 * نمی‌آیند) و فروش همیشه **خالصِ برگشت** است: برگشت از فروش هم از درآمد
 * کم می‌شود هم از بهای تمام‌شده. سودِ ناخالص = فروشِ خالص − بهای تمام‌شده،
 * و بها همان `unit_cost`ی است که هنگامِ صدور روی ردیف نوشته شد — پس سودِ
 * ماهِ گذشته با خریدِ امروز عوض نمی‌شود.
 *
 * مبلغ‌ها تومان‌اند. هیچ عددی از دفترِ شخصی خوانده نمی‌شود (قاعده ۷۰).
 */

require_once __DIR__ . '/biz_catalog.php';
require_once __DIR__ . '/biz_docs.php';

final class BizReports
{
    /** ⛔ تنها مرجعِ بازه‌ها؛ ناشناخته → «این ماه». */
    public const PERIODS = [
        'today'      => 'امروز',
        'week'       => 'این هفته',
        'month'      => 'این ماه',
        'last_month' => 'ماهِ قبل',
        'year'       => 'امسال',
        'last_year'  => 'سالِ مالیِ قبل',
        'all'        => 'از ابتدا',
        'custom'     => 'بازه‌ی دلخواه',
    ];

    /**
     * «از ابتدا» — مرزهای بازِ تاریخ. فهرستِ فاکتورها بدونِ صافیِ تاریخ همین را
     * نشان می‌دهد، پس چاپِ همان فهرست هم باید همین را بگیرد، نه «این ماه».
     */
    public const ALL_FROM = '2000-01-01';
    public const ALL_TO   = '2099-12-31';

    /** برچسبِ بازه برای سرآیند — «از ابتدا» تاریخِ ساختگیِ ۱۳۷۸ را نشان نمی‌دهد. */
    public static function rangeLabel(string $from, string $to): string
    {
        if ($from === self::ALL_FROM && $to === self::ALL_TO) { return 'همه‌ی زمان‌ها'; }
        return toJalali($from) . ' تا ' . toJalali($to);
    }

    /** نوعِ سند و برگشتِ هم‌سویش — «فروش» خالصِ برگشت از فروش است. */
    public const SIDE_KINDS = ['sale' => ['sale', 'sale_return'], 'purchase' => ['purchase', 'purchase_return']];

    /** @return array{0:string,1:string} [از, تا] میلادی */
    public static function range(string $p, string $from = '', string $to = ''): array
    {
        $today = today();
        switch ($p) {
            case 'today': return [$today, $today];
            case 'week':  return [startOfWeek(), $today];
            case 'year':  return [startOfJalaliYear(), $today];
            case 'all':   return [self::ALL_FROM, self::ALL_TO];
            case 'last_year':
                // ⛔ سالِ مالی = سالِ شمسی (فروردین تا اسفند) — `BizVat::year()`
                [$jy] = BizVat::jym($today);
                return BizVat::year($jy - 1);
            case 'last_month':
                $start = startOfJalaliMonth();
                $end   = date('Y-m-d', strtotime($start . ' -1 day'));
                [$y, $m, $d] = array_map('intval', explode('-', $end));
                [$jy, $jm, ] = gregorianToJalali($y, $m, $d);
                [$gy, $gm, $gd] = jalaliToGregorian($jy, $jm, 1);
                return [sprintf('%04d-%02d-%02d', $gy, $gm, $gd), $end];
            case 'custom':
                if (isValidDate($from) && isValidDate($to)) { return $from <= $to ? [$from, $to] : [$to, $from]; }
                return [startOfJalaliMonth(), $today];
            default: return [startOfJalaliMonth(), $today];
        }
    }

    /**
     * ⛔ دو هزینه‌ی کالایی که از «بهای تمام‌شده‌ی فروش» نمی‌آیند ولی سود را کم
     *    می‌کنند — بدونشان سودِ خالص بیشتر از واقعیت بود:
     *    - `shrink`: کسریِ انبارگردانی به ارزشِ همان لحظه (`unit_cost`ِ حرکت که
     *      `BizStock::recalc()` می‌نویسد؛ حرکتِ قدیمیِ بی‌بها با میانگینِ امروز).
     *      منفی یعنی اضافه‌ی انبار.
     *    - `nonstock`: خریدِ بی‌انبار — ردیفِ شرحِ آزاد یا کالای «خدمت» در
     *      فاکتورِ خرید (کرایه، تعمیر، کالایی که تعریف نشده)، خالصِ برگشت. آن
     *      پول به فروشنده بدهکار می‌شود ولی نه به انبار می‌رود نه به سود.
     *    یک کوئری با فروش (`UNION ALL`)، پس هیچ صفحه‌ای کوئریِ تازه نگرفت.
     */
    private const OTHER_SQL = "
        UNION ALL
        SELECT 'shrink', 0, COALESCE(-SUM(ROUND(m.qty * COALESCE(m.unit_cost, p.avg_cost))), 0), 0
        FROM biz_stock_moves m JOIN biz_products p FORCE INDEX (PRIMARY) ON p.id = m.product_id AND p.user_id = m.user_id
        WHERE m.user_id = :u2 AND m.kind = 'adjust' AND m.move_date BETWEEN :f2 AND :t2
        UNION ALL
        SELECT 'nonstock', 0, COALESCE(SUM(CASE WHEN i.kind = 'purchase' THEN l.net_total ELSE -l.net_total END), 0), 0
        FROM biz_invoices i JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
        LEFT JOIN biz_products p FORCE INDEX (PRIMARY) ON p.id = l.product_id AND p.user_id = l.user_id
        WHERE i.user_id = :u3 AND i.status = 'issued' AND i.kind IN ('purchase','purchase_return')
          AND i.inv_date BETWEEN :f3 AND :t3 AND (l.product_id IS NULL OR p.track_stock = 0)";

    /**
     * جمعِ فروش در بازه، با دو هزینه‌ی کالاییِ دیگر (`OTHER_SQL`).
     * `other` = `shrink` + `nonstock`؛ سودِ خالص = `gross` − `other` + درآمد − هزینه
     * (`profit()` — تنها فرمول).
     * @return array{sales:int, returns:int, net:int, cogs:int, gross:int, margin:?float, docs:int, avg:int,
     *               shrink:int, nonstock:int, other:int}
     */
    public static function sales(int $userId, string $from, string $to): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT i.kind, COALESCE(SUM(l.net_total), 0) AS rev,
                    COALESCE(SUM(ROUND(COALESCE(l.unit_cost, 0) * l.qty)), 0) AS cost,
                    COUNT(DISTINCT i.id) AS docs
             FROM biz_invoices i JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
             WHERE i.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return')
               AND i.inv_date BETWEEN :f AND :t
             GROUP BY i.kind" . self::OTHER_SQL
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to, 'u2' => $userId, 'f2' => $from, 't2' => $to,
                      'u3' => $userId, 'f3' => $from, 't3' => $to]);
        $r = ['sale' => ['rev' => 0, 'cost' => 0, 'docs' => 0], 'sale_return' => ['rev' => 0, 'cost' => 0, 'docs' => 0],
              'shrink' => ['cost' => 0], 'nonstock' => ['cost' => 0]];
        foreach ($st->fetchAll() as $row) { $r[$row['kind']] = ['rev' => (int)$row['rev'], 'cost' => (int)$row['cost'], 'docs' => (int)$row['docs']]; }
        $net   = $r['sale']['rev'] - $r['sale_return']['rev'];
        $cogs  = $r['sale']['cost'] - $r['sale_return']['cost'];
        $gross = $net - $cogs;
        return [
            'sales' => $r['sale']['rev'], 'returns' => $r['sale_return']['rev'], 'net' => $net, 'cogs' => $cogs,
            'gross' => $gross, 'margin' => $net > 0 ? round($gross / $net * 100, 1) : null,
            'docs' => $r['sale']['docs'], 'avg' => $r['sale']['docs'] > 0 ? (int)round($r['sale']['rev'] / $r['sale']['docs']) : 0,
            'shrink' => $r['shrink']['cost'], 'nonstock' => $r['nonstock']['cost'],
            'other' => $r['shrink']['cost'] + $r['nonstock']['cost'],
        ];
    }

    /**
     * ⛔ تنها فرمولِ «سودِ خالص» (صفحه‌ی گزارش، چاپِ سود و زیان، داشبورد، بستنِ سال).
     *    `$adjust` = اثرِ استهلاک و سندِ دستی (`BizBooks::plAdjust()`) — با آن سود همان
     *    ترازنامه است؛ داشبوردِ روزانه بی‌آن (سودِ عملیاتی) می‌ماند.
     */
    public static function profit(int $gross, int $other, int $income, int $expense, int $adjust = 0): int
    {
        return $gross - $other + $income - $expense + $adjust;
    }

    /** پرفروش‌ترین‌ها — خالصِ برگشت، با سودِ هر کدام. */
    public static function topProducts(int $userId, string $from, string $to, int $limit = 10): array
    {
        $st = Database::getConnection()->prepare(
            // ⛔ اول روی خودِ ردیف‌ها گروه می‌شود (کالا ← `product_id`، شرحِ آزاد ←
            //    متن) و نامِ کالا **بعد** از جمع می‌آید: گروه‌بندی روی رشته‌ی
            //    `COALESCE(p.name, …)` با جدولِ موقت ۳۳٪ کندتر بود (۱۵٫۴ → ۱۰٫۴ ms
            //    روی یک سال و ۳۷۵۰ ردیف، نتیجه‌ی یکسان). کالای دارای ردیف حذف‌شدنی
            //    نیست، پس `MIN(description)` فقط برای شرحِ آزاد به کار می‌آید.
            "SELECT COALESCE(p.name, g.descr) AS name, g.product_id, g.unit, g.qty, g.rev, g.cost
             FROM (SELECT l.product_id, l.unit, MIN(l.description) AS descr,
                          SUM(CASE WHEN i.kind = 'sale' THEN l.qty ELSE -l.qty END) AS qty,
                          SUM(CASE WHEN i.kind = 'sale' THEN l.net_total ELSE -l.net_total END) AS rev,
                          SUM(CASE WHEN i.kind = 'sale' THEN 1 ELSE -1 END * ROUND(COALESCE(l.unit_cost, 0) * l.qty)) AS cost
                   FROM biz_invoices i
                   JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
                   WHERE i.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return') AND i.inv_date BETWEEN :f AND :t
                   GROUP BY l.product_id, CASE WHEN l.product_id IS NULL THEN l.description END, l.unit) g
             LEFT JOIN biz_products p ON p.id = g.product_id AND p.user_id = :u2
             ORDER BY g.rev DESC, g.product_id LIMIT :lim"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('u2', $userId, PDO::PARAM_INT);
        $st->bindValue('f', $from);
        $st->bindValue('t', $to);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return array_map(fn($r) => $r + ['profit' => (int)$r['rev'] - (int)$r['cost']], $st->fetchAll());
    }

    /** فروش و سود به تفکیکِ دسته — «بی‌دسته» جدا. */
    public static function byCategory(int $userId, string $from, string $to): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT c.name AS cat,
                    SUM(CASE WHEN i.kind = 'sale' THEN l.net_total ELSE -l.net_total END) AS rev,
                    SUM(CASE WHEN i.kind = 'sale' THEN 1 ELSE -1 END * ROUND(COALESCE(l.unit_cost, 0) * l.qty)) AS cost
             FROM biz_invoice_lines l
             JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
             LEFT JOIN biz_products p FORCE INDEX (PRIMARY) ON p.id = l.product_id AND p.user_id = l.user_id
             LEFT JOIN biz_categories c ON c.id = p.category_id AND c.user_id = l.user_id
             WHERE l.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return') AND i.inv_date BETWEEN :f AND :t
             GROUP BY c.name ORDER BY rev DESC"
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        return array_map(fn($r) => $r + ['profit' => (int)$r['rev'] - (int)$r['cost']], $st->fetchAll());
    }

    /** جمعِ هر نوعِ دریافت/پرداخت در بازه. @return array<string,int> */
    public static function cash(int $userId, string $from, string $to): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT kind, SUM(amount) AS s FROM biz_payments WHERE user_id = :u AND status = 'ok'
               AND pay_date BETWEEN :f AND :t GROUP BY kind"
        );
        $st->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        $out = ['receipt' => 0, 'payment' => 0, 'expense' => 0, 'income' => 0, 'transfer' => 0, 'capital' => 0, 'drawing' => 0];
        foreach ($st->fetchAll() as $r) { $out[(string)$r['kind']] = (int)$r['s']; }
        return $out;
    }

    /**
     * بزرگ‌ترین هزینه‌ها — ⛔ به تفکیکِ **سرفصل** (migration_biz_accounting)؛
     * هزینه‌ی بی‌سرفصل (سندهای پیش از سرفصل) با شرحِ خودش.
     */
    public static function expenses(int $userId, string $from, string $to, int $limit = 8): array
    {
        $head = Biz::accReady() ? "COALESCE(c.name, y.title, 'بی‌شرح')" : "COALESCE(y.title, 'بی‌شرح')";
        $st = Database::getConnection()->prepare(
            "SELECT {$head} AS title, SUM(y.amount) AS s, COUNT(*) AS n FROM biz_payments y"
            . (Biz::accReady() ? ' LEFT JOIN biz_expense_cats c ON c.id = y.category_id AND c.user_id = y.user_id' : '') . "
             WHERE y.user_id = :u AND y.status = 'ok' AND y.kind = 'expense' AND y.pay_date BETWEEN :f AND :t
             GROUP BY {$head} ORDER BY s DESC LIMIT :lim"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('f', $from);
        $st->bindValue('t', $to);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * سنِ طلب — فاکتورهای فروشِ تسویه‌نشده به تفکیکِ روزهای گذشته از
     * سررسید (یا اگر سررسید ندارد، از تاریخِ فاکتور).
     * @return array{buckets:array<string,array{n:int,s:int}>, total:int}
     */
    public static function aging(int $userId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT DATEDIFF(CURDATE(), COALESCE(due_date, inv_date)) AS age, total - paid AS due
             FROM biz_invoices WHERE user_id = :u AND status = 'issued' AND kind = 'sale' AND paid < total"
        );
        $st->execute(['u' => $userId]);
        $b = ['0-30' => ['n' => 0, 's' => 0], '31-60' => ['n' => 0, 's' => 0], '61-90' => ['n' => 0, 's' => 0], '90+' => ['n' => 0, 's' => 0]];
        $total = 0;
        foreach ($st->fetchAll() as $r) {
            $a = max(0, (int)$r['age']);
            $k = $a <= 30 ? '0-30' : ($a <= 60 ? '31-60' : ($a <= 90 ? '61-90' : '90+'));
            $b[$k]['n']++; $b[$k]['s'] += (int)$r['due']; $total += (int)$r['due'];
        }
        return ['buckets' => $b, 'total' => $total];
    }

    /**
     * فروش یا خرید به تفکیکِ کالا — خالصِ برگشتِ هم‌سو. برای فروش بهای
     * تمام‌شده و سود هم می‌آید (از `unit_cost`ِ ردیف، مثلِ `sales()`).
     * ردیفِ شرحِ آزاد (بی‌کالا) با شرحِ خودش گروه می‌شود.
     *
     * @return array{rows:array, qty:float, amount:int, cost:int, profit:int, capped:bool}
     */
    public static function byProduct(int $userId, string $side, string $from, string $to, int $cap = 1000): array
    {
        [$main, $ret] = self::SIDE_KINDS[$side] ?? self::SIDE_KINDS['sale'];
        $st = Database::getConnection()->prepare(
            "SELECT l.product_id, COALESCE(p.name, l.description) AS name, p.sku, l.unit,
                    SUM(CASE WHEN i.kind = :m1 THEN l.qty ELSE -l.qty END) AS qty,
                    SUM(CASE WHEN i.kind = :m2 THEN l.net_total ELSE -l.net_total END) AS amount,
                    SUM(CASE WHEN i.kind = :m3 THEN 1 ELSE -1 END * ROUND(COALESCE(l.unit_cost, 0) * l.qty)) AS cost,
                    COUNT(DISTINCT i.id) AS docs
             FROM biz_invoice_lines l
             JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
             LEFT JOIN biz_products p FORCE INDEX (PRIMARY) ON p.id = l.product_id AND p.user_id = l.user_id
             WHERE l.user_id = :u AND i.status = 'issued' AND i.kind IN (:k1, :k2) AND i.inv_date BETWEEN :f AND :t
             GROUP BY l.product_id, COALESCE(p.name, l.description), p.sku, l.unit
             ORDER BY amount DESC LIMIT :lim"
        );
        $st->bindValue('m1', $main); $st->bindValue('m2', $main); $st->bindValue('m3', $main);
        $st->bindValue('k1', $main); $st->bindValue('k2', $ret);
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('f', $from);
        $st->bindValue('t', $to);
        $st->bindValue('lim', $cap + 1, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        $capped = count($rows) > $cap;
        $rows = array_slice($rows, 0, $cap);
        $out = ['rows' => [], 'qty' => 0.0, 'amount' => 0, 'cost' => 0, 'profit' => 0, 'capped' => $capped];
        foreach ($rows as $r) {
            $r['amount'] = (int)$r['amount'];
            $r['cost']   = $side === 'sale' ? (int)$r['cost'] : 0;
            $r['profit'] = $side === 'sale' ? $r['amount'] - $r['cost'] : 0;
            $out['rows'][] = $r;
            $out['qty']    += (float)$r['qty'];
            $out['amount'] += $r['amount'];
            $out['cost']   += $r['cost'];
            $out['profit'] += $r['profit'];
        }
        return $out;
    }

    /**
     * فاکتورهای صادرشده‌ی یک سو (با برگشتی‌هایش) در بازه — برگشت با علامتِ
     * منفی تا جمع خالص باشد. سودِ هر فاکتورِ فروش = جمعِ خالصِ ردیف‌ها −
     * بهای تمام‌شده؛ برای خرید سود معنا ندارد و صفر می‌ماند.
     *
     * @return array{rows:array, total:int, paid:int, due:int, profit:int, capped:bool}
     */
    public static function byInvoice(int $userId, string $side, string $from, string $to, int $cap = 1000): array
    {
        [$main, $ret] = self::SIDE_KINDS[$side] ?? self::SIDE_KINDS['sale'];
        $st = Database::getConnection()->prepare(
            "SELECT i.id, i.kind, i.number, i.inv_date, i.total, i.paid, pa.name AS party_name,
                    COALESCE((SELECT SUM(l.net_total) FROM biz_invoice_lines l WHERE l.invoice_id = i.id AND l.user_id = i.user_id), 0) AS net,
                    COALESCE((SELECT SUM(ROUND(COALESCE(l.unit_cost, 0) * l.qty)) FROM biz_invoice_lines l WHERE l.invoice_id = i.id AND l.user_id = i.user_id), 0) AS cost
             FROM biz_invoices i
             LEFT JOIN biz_parties pa ON pa.id = i.party_id AND pa.user_id = i.user_id
             WHERE i.user_id = :u AND i.status = 'issued' AND i.kind IN (:k1, :k2) AND i.inv_date BETWEEN :f AND :t
             ORDER BY i.inv_date, i.id LIMIT :lim"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('k1', $main); $st->bindValue('k2', $ret);
        $st->bindValue('f', $from);
        $st->bindValue('t', $to);
        $st->bindValue('lim', $cap + 1, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        $capped = count($rows) > $cap;
        $out = ['rows' => [], 'total' => 0, 'paid' => 0, 'due' => 0, 'profit' => 0, 'capped' => $capped];
        foreach (array_slice($rows, 0, $cap) as $r) {
            $sign = $r['kind'] === $main ? 1 : -1;
            $r['sign']   = $sign;
            $r['due']    = max(0, (int)$r['total'] - (int)$r['paid']);
            $r['profit'] = $side === 'sale' ? $sign * ((int)$r['net'] - (int)$r['cost']) : 0;
            $out['rows'][] = $r;
            $out['total']  += $sign * (int)$r['total'];
            $out['paid']   += $sign * (int)$r['paid'];
            $out['due']    += $sign * $r['due'];
            $out['profit'] += $r['profit'];
        }
        return $out;
    }

    /**
     * خلاصه‌ی هر طرف‌حساب در بازه: فروش، برگشت از فروش، خرید، برگشت از
     * خرید، دریافت و پرداخت — به‌علاوه‌ی مانده‌ی **امروز** (همان
     * `BizParties::BALANCE_SQL`، نه حسابِ دوم). طرف‌حسابِ بی‌گردش در بازه و
     * بی‌مانده نمی‌آید تا برگه شلوغ نشود.
     *
     * @return array{rows:array, sums:array<string,int>, capped:bool}
     */
    public static function byParty(int $userId, string $from, string $to): array
    {
        $pdo = Database::getConnection();
        $inv = $pdo->prepare(
            "SELECT party_id, kind, SUM(total) AS s FROM biz_invoices
             WHERE user_id = :u AND status = 'issued' AND party_id IS NOT NULL AND inv_date BETWEEN :f AND :t
             GROUP BY party_id, kind"
        );
        $inv->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        $pay = $pdo->prepare(
            "SELECT party_id, kind, SUM(amount) AS s FROM biz_payments
             WHERE user_id = :u AND status = 'ok' AND party_id IS NOT NULL AND kind IN ('receipt','payment') AND pay_date BETWEEN :f AND :t
             GROUP BY party_id, kind"
        );
        $pay->execute(['u' => $userId, 'f' => $from, 't' => $to]);
        $cols = ['sale' => 0, 'sale_return' => 0, 'purchase' => 0, 'purchase_return' => 0, 'receipt' => 0, 'payment' => 0];
        $act  = [];
        foreach (array_merge($inv->fetchAll(), $pay->fetchAll()) as $r) {
            $pid = (int)$r['party_id'];
            $act[$pid] ??= $cols;
            $act[$pid][(string)$r['kind']] = (int)$r['s'];
        }
        $all  = BizParties::all($userId);
        // ⛔ طرف‌حسابِ غیرفعال هم: فروشِ دوره‌ای که بعد غیرفعال شد هنوز فروشِ همان دوره است.
        //    بازرسیِ مهر ۱۴۰۵: جمعِ این گزارش صفر بود و `sales()`/`byInvoice()` ۱۰۰۰ می‌گفتند.
        $off  = BizParties::all($userId, 'inactive');
        $sums = $cols + ['balance' => 0];
        $rows = [];
        foreach (array_merge($all['rows'], $off['rows']) as $p) {
            $pid = (int)$p['id'];
            $bal = (int)$p['balance'];
            if (!isset($act[$pid]) && $bal === 0) { continue; }
            $row = ['id' => $pid, 'name' => (string)$p['name'], 'balance' => $bal] + ($act[$pid] ?? $cols);
            foreach ($cols as $k => $_) { $sums[$k] += $row[$k]; }
            $sums['balance'] += $bal;
            $rows[] = $row;
        }
        return ['rows' => $rows, 'sums' => $sums, 'capped' => $all['capped'] || $off['capped']];
    }

    /**
     * گردشِ یک صندوق یا حساب در بازه، با مانده‌ی ابتدای بازه و مانده‌ی
     * جاری. ⛔ اثرِ هر سطر همان منطقِ `BizCash::balanceSql()` است؛ تست ثابت
     *    می‌کند مانده‌ی پایانیِ «از ابتدا» با موجودیِ صفحه‌ی صندوق‌ها یکی است.
     *
     * @return array{account:array, opening:int, lines:array, in:int, out:int, closing:int, capped:bool}|null
     */
    public static function accountStatement(int $userId, int $accountId, string $from, string $to, int $cap = 2000): ?array
    {
        $acc = null;
        foreach (BizCash::list($userId) as $a) {
            if ((int)$a['id'] === $accountId) { $acc = $a; break; }
        }
        if ($acc === null) { return null; }
        $st = Database::getConnection()->prepare(
            "SELECT bp.id, bp.kind, bp.number, bp.pay_date, bp.amount, bp.title, bp.method, bp.account_id, bp.to_account_id,
                    pa.name AS party_name, fa.name AS from_name, ta.name AS to_name
             FROM biz_payments bp
             LEFT JOIN biz_parties pa ON pa.id = bp.party_id AND pa.user_id = bp.user_id
             LEFT JOIN biz_accounts fa ON fa.id = bp.account_id AND fa.user_id = bp.user_id
             LEFT JOIN biz_accounts ta ON ta.id = bp.to_account_id AND ta.user_id = bp.user_id
             WHERE bp.user_id = :u AND bp.status = 'ok' AND (bp.account_id = :a1 OR (bp.kind = 'transfer' AND bp.to_account_id = :a2))
               AND bp.pay_date <= :t
             ORDER BY bp.pay_date, bp.id"
        );
        $st->execute(['u' => $userId, 'a1' => $accountId, 'a2' => $accountId, 't' => $to]);
        $opening = (int)$acc['opening_balance'];
        $lines = []; $in = 0; $out = 0;
        foreach ($st->fetchAll() as $r) {
            $toHere = $r['kind'] === 'transfer' && (int)$r['to_account_id'] === $accountId && (int)$r['account_id'] !== $accountId;
            $amt    = (int)$r['amount'];
            $delta  = $toHere ? $amt : (in_array($r['kind'], BizPay::IN_KINDS, true) ? $amt : -$amt);
            if ((string)$r['pay_date'] < $from) { $opening += $delta; continue; }
            $who = $r['kind'] === 'transfer'
                ? ($toHere ? 'از ' . (string)$r['from_name'] : 'به ' . (string)$r['to_name'])
                : ((string)($r['party_name'] ?? '') !== '' ? (string)$r['party_name'] : (string)($r['title'] ?? ''));
            $lines[] = ['id' => (int)$r['id'], 'date' => (string)$r['pay_date'],
                        'desc' => (BizPay::KINDS[$r['kind']] ?? (string)$r['kind']) . ' ' . toPersianDigits((string)$r['number']) . ($who !== '' ? ' — ' . $who : ''),
                        'in' => max($delta, 0), 'out' => max(-$delta, 0)];
            $delta > 0 ? $in += $delta : $out -= $delta;
        }
        $run = $opening;
        foreach ($lines as &$l) { $run += $l['in'] - $l['out']; $l['balance'] = $run; }
        unset($l);
        $capped = count($lines) > $cap;
        return ['account' => $acc, 'opening' => $opening, 'lines' => $capped ? array_slice($lines, -$cap) : $lines,
                'in' => $in, 'out' => $out, 'closing' => $run, 'capped' => $capped];
    }

    /** برچسبِ ستونِ سنِ طلب. */
    public const AGING_LABELS = ['0-30' => 'تا ۳۰ روز', '31-60' => '۳۱ تا ۶۰', '61-90' => '۶۱ تا ۹۰', '90+' => 'بیش از ۹۰ روز'];
}
