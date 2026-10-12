<?php
/**
 * ⛔ محیطِ فروشگاهی — لایه‌ی حسابداری (migration_biz_accounting).
 *
 * **خواسته‌ی مالکِ نصب:** «اگه به عنوان یک حسابدار بخونی … چه مشکلاتی
 * می‌بینی؟» ← «همه رو بساز، تست کن.» (مهر ۱۴۰۵)
 *
 * - `BizExpCats`   سرفصلِ هزینه و درآمد (اجاره، حقوق، قبوض…؛ سه سرفصلِ سیستمی).
 * - `BizVat`       گزارشِ مالیات بر ارزش افزوده (فروش − خرید = بدهی به سازمان).
 * - `BizLedger`    دفترِ دوطرفه‌ی **مشتق**: روزنامه، ترازِ آزمایشی، ترازنامه و
 *                  مغایرت‌گیری. ⛔ جدولِ دومی از پول نیست — هر سطر از همان اسناد
 *                  ساخته می‌شود، پس هرگز از آن‌ها عقب نمی‌افتد. دفتر **بنا به
 *                  ساخت** تراز است؛ آنچه واقعاً سنجیده می‌شود مغایرتِ هر حساب با
 *                  عددی است که صفحه‌های فروشگاه نشان می‌دهند (`reconcile()`).
 * - `BizYear`      بستنِ سالِ مالی (عکسِ تراز + قفلِ دوره تا پایانِ سال).
 * - `BizCashCount` شمارشِ صندوق؛ تفاوت به سرفصلِ «کسری/اضافه‌ی صندوق».
 * - `BizPayroll`   حقوق و مساعده‌ی کارکنان.
 * - `BizMoadian`   صورتحسابِ الکترونیکیِ استاندارد برای سامانه‌ی مودیان (خروجی).
 *
 * ⛔ فقط `biz_*` (قاعده ۷۰)؛ `user_id` روی هر کوئری.
 */

require_once __DIR__ . '/biz_docs.php';
require_once __DIR__ . '/biz_reports.php';
require_once __DIR__ . '/biz_books.php';

/* =================================================================
   سرفصلِ هزینه و درآمد
   ================================================================= */
final class BizExpCats
{
    public const KINDS = ['expense' => 'هزینه', 'income' => 'درآمد'];

    /**
     * ⛔ سرفصل‌های سیستمی — برنامه خودش به آن‌ها سند می‌زند (حقوق، شمارشِ
     *    صندوق)، پس غیرفعال یا حذف نمی‌شوند؛ نامشان عوض‌شدنی است.
     */
    public const SYSTEM = [
        'salary'     => ['expense', 'حقوق و دستمزد'],
        'cash_short' => ['expense', 'کسریِ صندوق'],
        'cash_over'  => ['income', 'اضافه‌ی صندوق'],
    ];

    /** سرفصل‌های پیش‌فرضِ فروشگاهِ تازه — نام ← کلیدِ سیستمی (یا null). */
    public const DEFAULTS = [
        'expense' => ['اجاره' => null, 'حقوق و دستمزد' => 'salary', 'قبوض و شارژ' => null, 'حمل و پیک' => null,
                      'تبلیغات' => null, 'تعمیر و نگهداری' => null, 'کارمزدِ بانکی' => null, 'کسریِ صندوق' => 'cash_short',
                      'سایر هزینه‌ها' => null],
        'income'  => ['سودِ بانکی' => null, 'اضافه‌ی صندوق' => 'cash_over', 'سایر درآمدها' => null],
    ];

    public const NAME_MAX = 80;

    /**
     * سرفصل‌های یک فروشگاه (هر دو جنس یا یکی). بارِ اول پیش‌فرض‌ها ساخته
     * می‌شوند — مثلِ صندوقِ پیش‌فرض (`BizCash::list()`)؛ چیزی که کاربر غیرفعال
     * کرده دوباره زنده نمی‌شود چون فقط «صفر ردیف» پیش‌فرض می‌سازد.
     */
    public static function list(int $userId, string $kind = '', bool $activeOnly = false): array
    {
        if (!Biz::accReady()) { return []; }
        $rows = self::fetch($userId);
        if (!$rows) {
            self::seed(Database::getConnection(), $userId);
            $rows = self::fetch($userId);
        }
        return array_values(array_filter($rows, fn($r) => ($kind === '' || $r['kind'] === $kind)
                                                        && (!$activeOnly || (int)$r['is_active'] === 1)));
    }

    private static function fetch(int $userId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT id, kind, name, sys_key, sort_order, is_active FROM biz_expense_cats WHERE user_id = :u ORDER BY kind, sort_order, id'
        );
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    }

    private static function seed(PDO $pdo, int $userId): void
    {
        $ins = $pdo->prepare('INSERT IGNORE INTO biz_expense_cats (user_id, kind, name, sys_key, sort_order) VALUES (:u, :k, :n, :s, :o)');
        foreach (self::DEFAULTS as $kind => $names) {
            $o = 0;
            foreach ($names as $name => $sys) { $ins->execute(['u' => $userId, 'k' => $kind, 'n' => $name, 's' => $sys, 'o' => $o += 10]); }
        }
    }

    public static function get(int $userId, int $id): ?array
    {
        if (!Biz::accReady()) { return null; }
        $st = Database::getConnection()->prepare('SELECT * FROM biz_expense_cats WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->fetch() ?: null;
    }

    /**
     * ⛔ شناسه‌ی سرفصلِ سیستمی — اگر نیست ساخته می‌شود (اگر کاربر همان نام را
     *    خودش ساخته بود، همان ردیف سیستمی می‌شود، نه ردیفِ دوم).
     */
    public static function sysId(PDO $pdo, int $userId, string $key): int
    {
        [$kind, $name] = self::SYSTEM[$key];
        // ⛔ اول پیش‌فرض‌ها (اگر جدول خالی است) — وگرنه ردیفِ سیستمیِ تنها جدول را «پر»
        //    نشان می‌داد و `list()` دیگر اجاره و قبوض و بقیه را نمی‌ساخت (بازرسیِ مهر ۱۴۰۵:
        //    اولین کار «پرداختِ حقوق» بود و فهرستِ هزینه فقط «حقوق و دستمزد» داشت).
        $any = $pdo->prepare('SELECT 1 FROM biz_expense_cats WHERE user_id = :u LIMIT 1');
        $any->execute(['u' => $userId]);
        if (!$any->fetchColumn()) { self::seed($pdo, $userId); }
        $st = $pdo->prepare('SELECT id FROM biz_expense_cats WHERE user_id = :u AND sys_key = :k LIMIT 1');
        $st->execute(['u' => $userId, 'k' => $key]);
        $id = $st->fetchColumn();
        if ($id !== false) { return (int)$id; }
        $pdo->prepare('INSERT INTO biz_expense_cats (user_id, kind, name, sys_key, sort_order) VALUES (:u, :k, :n, :s, 900)
                       ON DUPLICATE KEY UPDATE sys_key = VALUES(sys_key), is_active = 1')
            ->execute(['u' => $userId, 'k' => $kind, 'n' => $name, 's' => $key]);
        $st->execute(['u' => $userId, 'k' => $key]);
        return (int)$st->fetchColumn();
    }

    /** @return array{ok:bool, message:string, id?:int} */
    public static function save(int $userId, string $kind, string $name, int $id = 0): array
    {
        if (!Biz::accReady()) { return ['ok' => false, 'message' => 'لایه‌ی حسابداری هنوز راه نیفتاده است.']; }
        $name = BizCommon::line(BizCommon::persian($name));
        if (!isset(self::KINDS[$kind])) { return ['ok' => false, 'message' => 'جنسِ سرفصل معتبر نیست.']; }
        if ($name === '') { return ['ok' => false, 'message' => 'نامِ سرفصل را بنویسید.']; }
        if (mb_strlen($name) > self::NAME_MAX) { return ['ok' => false, 'message' => 'نامِ سرفصل بیش از ' . self::NAME_MAX . ' نویسه است.']; }
        $pdo = Database::getConnection();
        try {
            if ($id > 0) {
                $cur = self::get($userId, $id);
                if (!$cur) { return ['ok' => false, 'message' => 'سرفصل پیدا نشد.']; }
                $pdo->prepare('UPDATE biz_expense_cats SET name = :n WHERE id = :id AND user_id = :u')->execute(['n' => $name, 'id' => $id, 'u' => $userId]);
                return ['ok' => true, 'message' => 'سرفصل ذخیره شد.', 'id' => $id];
            }
            self::list($userId);                                   // پیش‌فرض‌ها پیش از اولین سرفصلِ دستی
            $pdo->prepare('INSERT INTO biz_expense_cats (user_id, kind, name, sort_order) VALUES (:u, :k, :n, 500)')
                ->execute(['u' => $userId, 'k' => $kind, 'n' => $name]);
            return ['ok' => true, 'message' => 'سرفصلِ «' . $name . '» اضافه شد.', 'id' => (int)$pdo->lastInsertId()];
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') { return ['ok' => false, 'message' => 'سرفصلی با همین نام هست.']; }
            throw $e;
        }
    }

    /** ⛔ سرفصلِ سیستمی غیرفعال نمی‌شود — برنامه به آن سند می‌زند. */
    public static function setActive(int $userId, int $id, bool $on): array
    {
        $cur = self::get($userId, $id);
        if (!$cur) { return ['ok' => false, 'message' => 'سرفصل پیدا نشد.']; }
        if (!$on && $cur['sys_key'] !== null) { return ['ok' => false, 'message' => 'این سرفصلِ سیستمی است (حقوق یا شمارشِ صندوق) و غیرفعال نمی‌شود.']; }
        Database::getConnection()->prepare('UPDATE biz_expense_cats SET is_active = :a WHERE id = :id AND user_id = :u')
            ->execute(['a' => $on ? 1 : 0, 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => $on ? 'سرفصل فعال شد.' : 'سرفصل غیرفعال شد؛ سندهای گذشته‌اش سرِ جایشان‌اند.'];
    }
}

/* =================================================================
   مالیات بر ارزش افزوده
   ================================================================= */
final class BizVat
{
    /** [از، تا]ِ میلادیِ یک فصلِ شمسی (۱ تا ۴) — دوره‌ی اظهارنامه. */
    public static function quarter(int $jy, int $q): array
    {
        $q = max(1, min(4, $q));
        $m1 = ($q - 1) * 3 + 1; $m3 = $m1 + 2;
        [$fy, $fm, $fd] = jalaliToGregorian($jy, $m1, 1);
        [$ty, $tm, $td] = jalaliToGregorian($jy, $m3, jalaliMonthLength($jy, $m3));
        return [sprintf('%04d-%02d-%02d', $fy, $fm, $fd), sprintf('%04d-%02d-%02d', $ty, $tm, $td)];
    }

    /** [از، تا]ِ میلادیِ یک سالِ شمسی. */
    public static function year(int $jy): array
    {
        [$fy, $fm, $fd] = jalaliToGregorian($jy, 1, 1);
        [$ty, $tm, $td] = jalaliToGregorian($jy, 12, jalaliMonthLength($jy, 12));
        return [sprintf('%04d-%02d-%02d', $fy, $fm, $fd), sprintf('%04d-%02d-%02d', $ty, $tm, $td)];
    }

    /** سال و ماهِ شمسیِ یک تاریخِ میلادی. @return array{0:int,1:int} */
    public static function jym(string $date): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        [$jy, $jm] = gregorianToJalali($y, $m, $d);
        return [(int)$jy, (int)$jm];
    }

    /**
     * ⛔ گزارشِ مالیات بر ارزش افزوده‌ی یک بازه، ماه‌به‌ماه (شمسی).
     *    - فروش: مالیاتِ فاکتورِ فروش − برگشت از فروش (اعتبارِ برگشت)
     *    - خرید: مالیاتِ فاکتورِ خرید − برگشت از خرید (اعتبارِ قابلِ کسر)
     *    - بدهی به سازمان = فروش − خرید (منفی = اعتبارِ قابلِ انتقال)
     *    مبلغِ «پیش از مالیات» = total − tax_total، فقط اسنادِ صادرشده.
     *    - ⛔ «معاف» = جمعِ خالصِ **ردیف‌های** بی‌مالیات، نه فاکتورِ تمام‌معاف: ردیفِ
     *      معافِ فاکتورِ مختلط (یک کالای معاف کنارِ یک مشمول) پیش از این صفر
     *      شمرده می‌شد (بازبینیِ مهر ۱۴۰۵، بازتولید شد).
     * @return array{rows:array, sum:array}
     */
    public static function report(int $userId, string $from, string $to): array
    {
        $zero = ['sale_net' => 0, 'sale_tax' => 0, 'buy_net' => 0, 'buy_tax' => 0, 'exempt' => 0, 'payable' => 0, 'docs' => 0];
        if (!Biz::accReady()) { return ['rows' => [], 'sum' => $zero]; }
        $st = Database::getConnection()->prepare(
            "SELECT i.inv_date, i.kind, COUNT(*) AS n, SUM(i.total - i.tax_total) AS net, SUM(i.tax_total) AS tax,
                    COALESCE(SUM(x.exempt), 0) AS exempt
             FROM biz_invoices i
             LEFT JOIN (SELECT l.invoice_id, SUM(l.net_total) AS exempt
                        FROM biz_invoice_lines l JOIN biz_invoices j ON j.id = l.invoice_id AND j.user_id = l.user_id
                        WHERE l.user_id = :u2 AND l.tax_amount = 0 AND j.status = 'issued' AND j.inv_date BETWEEN :f2 AND :t2
                        GROUP BY l.invoice_id) x ON x.invoice_id = i.id
             WHERE i.user_id = :u AND i.status = 'issued' AND i.inv_date BETWEEN :f AND :t
             GROUP BY i.inv_date, i.kind ORDER BY i.inv_date"
        );
        $st->execute(['u' => $userId, 'u2' => $userId, 'f' => $from, 't' => $to, 'f2' => $from, 't2' => $to]);
        $rows = []; $sum = $zero;
        foreach ($st->fetchAll() as $r) {
            [$jy, $jm] = self::jym((string)$r['inv_date']);
            $k = sprintf('%04d-%02d', $jy, $jm);
            $rows[$k] ??= $zero + ['jy' => $jy, 'jm' => $jm];
            $sale = in_array($r['kind'], ['sale', 'sale_return'], true);
            $sign = in_array($r['kind'], ['sale', 'purchase'], true) ? 1 : -1;
            $rows[$k][$sale ? 'sale_net' : 'buy_net'] += $sign * (int)$r['net'];
            $rows[$k][$sale ? 'sale_tax' : 'buy_tax'] += $sign * (int)$r['tax'];
            if ($sale) { $rows[$k]['exempt'] += $sign * (int)$r['exempt']; }
            $rows[$k]['docs'] += (int)$r['n'];
        }
        foreach ($rows as $k => $r) {
            $rows[$k]['payable'] = $r['sale_tax'] - $r['buy_tax'];
            foreach ($zero as $f => $_) { $sum[$f] += $rows[$k][$f]; }
        }
        return ['rows' => array_values($rows), 'sum' => $sum];
    }
}

/* =================================================================
   دفترِ دوطرفه‌ی مشتق
   ================================================================= */
final class BizLedger
{
    /**
     * ⛔ تنها فهرستِ حساب‌ها (سرفصلِ کل). نوع: دارایی، بدهی، حقوقِ صاحبان
     *    سرمایه، درآمد، هزینه. طرف‌حساب‌ها یک «حسابِ کنترل» دارند (۱۱۰۳) و در
     *    ترازنامه بنا به علامتِ مانده‌ی هر نفر به بدهکاران و بستانکاران تقسیم
     *    می‌شوند — همان قاعده‌ی `BizParties::BALANCE_SQL`.
     */
    public const ACCOUNTS = [
        '1101' => ['صندوق و بانک', 'asset'],
        '1102' => ['اسنادِ دریافتنی (چکِ در جریان)', 'asset'],
        '1103' => ['طرف‌حساب‌ها (دریافتنی و پرداختنی)', 'asset'],
        '1104' => ['موجودیِ کالا', 'asset'],
        '1105' => ['مالیات بر ارزش افزوده‌ی خرید', 'asset'],
        '1201' => ['دارایی‌های ثابت (بهای خرید)', 'asset'],
        '1202' => ['استهلاکِ انباشته', 'asset'],
        '2102' => ['اسنادِ پرداختنی (چکِ پرداختیِ در جریان)', 'liability'],
        '2103' => ['مالیات بر ارزش افزوده‌ی فروش', 'liability'],
        '3101' => ['سرمایه‌ی اول دوره', 'equity'],
        '3102' => ['آورده‌ی مالک', 'equity'],
        '3103' => ['برداشتِ مالک', 'equity'],
        '4101' => ['فروش', 'income'],
        '4102' => ['برگشت از فروش', 'income'],
        '4201' => ['درآمدهای متفرقه', 'income'],
        '5101' => ['بهای تمام‌شده‌ی کالای فروش‌رفته', 'expense'],
        '5102' => ['کسری و اضافه‌ی انبار', 'expense'],
        '5103' => ['خرید و خدمتِ بی‌انبار', 'expense'],
        '5201' => ['هزینه‌های فروشگاه', 'expense'],
        '5202' => ['هزینه‌ی استهلاک', 'expense'],
        '5203' => ['زیانِ کنار گذاشتنِ دارایی', 'expense'],
    ];

    public const TYPES = ['asset' => 'دارایی', 'liability' => 'بدهی', 'equity' => 'سرمایه', 'income' => 'درآمد', 'expense' => 'هزینه'];

    /** تاریخِ سندِ «افتتاحیه» (مانده‌ی اول دوره‌ی صندوق و طرف‌حساب، که تاریخ ندارد). */
    /** سقفِ سطرهای روزنامه‌ی نمایشی. */
    public const JOURNAL_CAP = 3000;

    /** کدِ حسابِ یک صندوق بنا به نوعش. */
    private static function cashCode(string $kind): string
    {
        return $kind === BizCash::CHEQUE_KINDS['in'] ? '1102' : ($kind === BizCash::CHEQUE_KINDS['out'] ? '2102' : '1101');
    }

    /**
     * ⛔ همه‌ی سندهای حسابداری تا `$to` (و از `$from` اگر داده شود).
     *    هر سند: `date`، `ref` (برای پیوند)، `desc`، و `lines` = فهرستِ
     *    `[کد، کلیدِ تفصیلی، نامِ تفصیلی، بدهکار، بستانکار]`. هر سند **بنا به
     *    ساخت** تراز است (`balanced()` در تست همه را می‌سنجد).
     * @return list<array{date:string, ref:string, desc:string, lines:list<array>}>
     */
    public static function entries(int $userId, string $to, string $from = ''): array
    {
        $pdo = Database::getConnection();
        $out = [];
        $f = $from !== '' ? $from : '0000-01-01';
        $acc = Biz::accReady();

        // ۱) افتتاحیه — صندوق‌ها و طرف‌حساب‌ها، هر کدام **با تاریخِ خودش**: روزِ ساختنش یا
        //    قدیمی‌ترین سندش، هر کدام زودتر — همان قاعده‌ی موجودیِ اول دوره‌ی کالا و همان
        //    تاریخی که سدِ قفلِ فرمش (`lockError`) می‌سنجد. ⛔ بازرسیِ مهر ۱۴۰۵: همه با
        //    تاریخِ ثابتِ ۲۰۰۰-۰۱-۰۱ می‌نشستند، پس ساختنِ یک طرف‌حساب یا صندوقِ تازه با مانده
        //    ترازِ دوره‌ای را که بسته و گزارش شده بود عوض می‌کرد (۱۱۰۱: ۱۰۰۰ → ۸۰۰۰).
        $byDate = [];
        $st = $pdo->prepare(
            "SELECT a.id, a.name, a.kind, a.opening_balance,
                    LEAST(DATE(a.created_at), COALESCE((SELECT MIN(y.pay_date) FROM biz_payments y
                          WHERE y.user_id = a.user_id AND (y.account_id = a.id OR y.to_account_id = a.id)), '9999-12-31')) AS d
             FROM biz_accounts a WHERE a.user_id = :u AND a.opening_balance <> 0"
        );
        $st->execute(['u' => $userId]);
        foreach ($st->fetchAll() as $a) {
            $v = (int)$a['opening_balance'];
            $byDate[(string)$a['d']][] = [self::cashCode((string)$a['kind']), 'a' . $a['id'], (string)$a['name'], max($v, 0), max(-$v, 0)];
            $byDate[(string)$a['d']][] = ['3101', '', '', max(-$v, 0), max($v, 0)];
        }
        $st = $pdo->prepare(
            "SELECT p.id, p.name, p.opening_balance,
                    LEAST(DATE(p.created_at),
                          COALESCE((SELECT MIN(i.inv_date) FROM biz_invoices i WHERE i.user_id = p.user_id AND i.party_id = p.id), '9999-12-31'),
                          COALESCE((SELECT MIN(y.pay_date) FROM biz_payments y WHERE y.user_id = p.user_id AND y.party_id = p.id), '9999-12-31')) AS d
             FROM biz_parties p WHERE p.user_id = :u AND p.opening_balance <> 0"
        );
        $st->execute(['u' => $userId]);
        foreach ($st->fetchAll() as $p) {
            $v = (int)$p['opening_balance'];
            $byDate[(string)$p['d']][] = ['1103', 'p' . $p['id'], (string)$p['name'], max($v, 0), max(-$v, 0)];
            $byDate[(string)$p['d']][] = ['3101', '', '', max(-$v, 0), max($v, 0)];
        }
        ksort($byDate);
        foreach ($byDate as $d => $lines) {
            if ($d < $f || $d > $to) { continue; }
            $out[] = ['date' => $d, 'ref' => '', 'desc' => 'افتتاحیه — مانده‌ی اول دوره‌ی صندوق‌ها و طرف‌حساب‌ها', 'lines' => $lines];
        }

        // ۲) انبار — اول دوره و انبارگردانی (همان ارزشِ `BizReports::OTHER_SQL`)
        $st = $pdo->prepare(
            "SELECT m.kind, m.move_date, SUM(ROUND(m.qty * COALESCE(m.unit_cost, p.avg_cost))) AS v
             FROM biz_stock_moves m JOIN biz_products p FORCE INDEX (PRIMARY) ON p.id = m.product_id AND p.user_id = m.user_id
             WHERE m.user_id = :u AND m.kind IN ('opening','adjust') AND m.move_date BETWEEN :f AND :t
             GROUP BY m.kind, m.move_date ORDER BY m.move_date"
        );
        $st->execute(['u' => $userId, 'f' => $f, 't' => $to]);
        foreach ($st->fetchAll() as $m) {
            $v = (int)$m['v'];
            if ($v === 0) { continue; }
            if ($m['kind'] === 'opening') {
                $out[] = ['date' => (string)$m['move_date'], 'ref' => '', 'desc' => 'موجودیِ اول دوره‌ی کالا',
                          'lines' => [['1104', '', '', max($v, 0), max(-$v, 0)], ['3101', '', '', max(-$v, 0), max($v, 0)]]];
            } else {
                $out[] = ['date' => (string)$m['move_date'], 'ref' => '', 'desc' => $v < 0 ? 'کسریِ انبارگردانی' : 'اضافه‌ی انبارگردانی',
                          'lines' => [['1104', '', '', max($v, 0), max(-$v, 0)], ['5102', '', '', max(-$v, 0), max($v, 0)]]];
            }
        }

        // ۳) فاکتورها — درآمد/خرید بی‌مالیات، مالیات جدا، بهای تمام‌شده
        $st = $pdo->prepare(
            "SELECT i.id, i.kind, i.number, i.inv_date, i.party_id, i.total, " . ($acc ? 'i.tax_total' : '0') . " AS tax, pa.name AS party_name,
                    SUM(CASE WHEN l.product_id IS NOT NULL AND p.track_stock = 1 THEN l.net_total ELSE 0 END) AS net_stock,
                    SUM(CASE WHEN l.product_id IS NULL OR p.track_stock = 0 THEN l.net_total ELSE 0 END) AS net_free,
                    SUM(ROUND(COALESCE(l.unit_cost, 0) * l.qty)) AS cost
             FROM biz_invoices i
             JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id
             LEFT JOIN biz_products p FORCE INDEX (PRIMARY) ON p.id = l.product_id AND p.user_id = l.user_id
             LEFT JOIN biz_parties pa ON pa.id = i.party_id AND pa.user_id = i.user_id
             WHERE i.user_id = :u AND i.status = 'issued' AND i.inv_date BETWEEN :f AND :t
             GROUP BY i.id ORDER BY i.inv_date, i.id"
        );
        $st->execute(['u' => $userId, 'f' => $f, 't' => $to]);
        foreach ($st->fetchAll() as $i) {
            $pk = $i['party_id'] !== null ? 'p' . $i['party_id'] : 'p0';
            $pn = $i['party_id'] !== null ? (string)$i['party_name'] : 'گذری';
            $total = (int)$i['total']; $tax = (int)$i['tax']; $ns = (int)$i['net_stock']; $nf = (int)$i['net_free']; $cost = (int)$i['cost'];
            $title = BizInvoices::KINDS[$i['kind']] . ' ' . toPersianDigits((string)$i['number']) . ' — ' . $pn;
            switch ($i['kind']) {
                case 'sale':
                    $l = [['1103', $pk, $pn, $total, 0], ['4101', '', '', 0, $ns + $nf], ['2103', '', '', 0, $tax],
                          ['5101', '', '', $cost, 0], ['1104', '', '', 0, $cost]];
                    break;
                case 'sale_return':
                    $l = [['4102', '', '', $ns + $nf, 0], ['2103', '', '', $tax, 0], ['1103', $pk, $pn, 0, $total],
                          ['1104', '', '', $cost, 0], ['5101', '', '', 0, $cost]];
                    break;
                case 'purchase':
                    $l = [['1104', '', '', $ns, 0], ['5103', '', '', $nf, 0], ['1105', '', '', $tax, 0], ['1103', $pk, $pn, 0, $total]];
                    break;
                default:                                                         // purchase_return
                    $l = [['1103', $pk, $pn, $total, 0], ['1104', '', '', 0, $ns], ['5103', '', '', 0, $nf], ['1105', '', '', 0, $tax]];
            }
            $out[] = ['date' => (string)$i['inv_date'], 'ref' => 'invoice.php?id=' . (int)$i['id'], 'desc' => $title,
                      'lines' => array_values(array_filter($l, fn($x) => $x[3] !== 0 || $x[4] !== 0))];
        }

        // ۴) دریافت، پرداخت، هزینه، درآمد، انتقال، آورده و برداشت
        $st = $pdo->prepare(
            "SELECT y.id, y.kind, y.number, y.pay_date, y.amount, y.party_id, y.account_id, y.to_account_id, y.title,
                    a.kind AS akind, a.name AS aname, t.kind AS tkind, t.name AS tname, pa.name AS party_name"
            . ($acc ? ', c.name AS cat_name, y.category_id' : ', NULL AS cat_name, NULL AS category_id')
            . (BizBooks::ready() ? ', y.asset_id' : ', NULL AS asset_id') . "
             FROM biz_payments y
             JOIN biz_accounts a ON a.id = y.account_id AND a.user_id = y.user_id
             LEFT JOIN biz_accounts t ON t.id = y.to_account_id AND t.user_id = y.user_id
             LEFT JOIN biz_parties pa ON pa.id = y.party_id AND pa.user_id = y.user_id"
            . ($acc ? ' LEFT JOIN biz_expense_cats c ON c.id = y.category_id AND c.user_id = y.user_id' : '') . "
             WHERE y.user_id = :u AND y.status = 'ok' AND y.pay_date BETWEEN :f AND :t
             ORDER BY y.pay_date, y.id"
        );
        $st->execute(['u' => $userId, 'f' => $f, 't' => $to]);
        foreach ($st->fetchAll() as $y) {
            $amt = (int)$y['amount'];
            $cash = [self::cashCode((string)$y['akind']), 'a' . $y['account_id'], (string)$y['aname']];
            $pk = $y['party_id'] !== null ? 'p' . $y['party_id'] : 'p0';
            $pn = $y['party_id'] !== null ? (string)$y['party_name'] : 'گذری';
            $head = (string)($y['cat_name'] ?? '') !== '' ? (string)$y['cat_name'] : (string)($y['title'] ?? '');
            $hk   = $y['category_id'] !== null ? 'c' . $y['category_id'] : 't:' . $head;
            $dr = fn(array $a) => [$a[0], $a[1], $a[2], $amt, 0];
            $cr = fn(array $a) => [$a[0], $a[1], $a[2], 0, $amt];
            switch ($y['kind']) {
                case 'receipt':  $l = [$dr($cash), $cr(['1103', $pk, $pn])]; break;
                case 'payment':  $l = [$dr(['1103', $pk, $pn]), $cr($cash)]; break;
                case 'expense':  $l = [$dr(['5201', $hk, $head]), $cr($cash)]; break;
                case 'income':   $l = [$dr($cash), $cr(['4201', $hk, $head])]; break;
                case 'capital':  $l = [$dr($cash), $cr(['3102', '', ''])]; break;
                case 'drawing':  $l = [$dr(['3103', '', '']), $cr($cash)]; break;
                case 'transfer': $l = [$dr([self::cashCode((string)$y['tkind']), 'a' . $y['to_account_id'], (string)$y['tname']]), $cr($cash)]; break;
                case 'asset':    $l = [$dr(['1201', 'f' . (int)$y['asset_id'], (string)$y['title']]), $cr($cash)]; break;
                default: continue 2;
            }
            $out[] = ['date' => (string)$y['pay_date'], 'ref' => 'payment.php?id=' . (int)$y['id'],
                      'desc' => (BizPay::KINDS[$y['kind']] ?? '') . ' ' . toPersianDigits((string)$y['number'])
                                . (in_array($y['kind'], ['receipt', 'payment'], true) ? ' — ' . $pn : ($head !== '' ? ' — ' . $head : '')),
                      'lines' => $l];
        }
        // ۵) سندِ دستی و دارایی‌های ثابت (`includes/biz_books.php`)
        foreach (BizJournal::entries($userId, $from, $to) as $e) { $out[] = $e; }
        foreach (BizAssets::entries($userId, $from, $to) as $e) { $out[] = $e; }
        usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    /** آیا هر سند تراز است؟ — فقط تست؛ هر سند بنا به ساخت تراز است و صفحه نشانی برایش ندارد. */
    public static function balanced(array $entries): bool
    {
        foreach ($entries as $e) {
            $d = 0; $c = 0;
            foreach ($e['lines'] as $l) { $d += $l[3]; $c += $l[4]; }
            if ($d !== $c) { return false; }
        }
        return true;
    }

    /**
     * ترازِ آزمایشی تا `$to` (و فقط گردشِ از `$from` اگر داده شود).
     * @return array{rows:array<string,array>, detail:array<string,array<string,array>>, dr:int, cr:int}
     */
    public static function trial(int $userId, string $to, string $from = '', ?array $entries = null): array
    {
        $entries ??= self::entries($userId, $to, $from);
        $rows = []; $detail = []; $dr = 0; $cr = 0;
        $chart = BizCoa::map($userId);
        foreach ($entries as $e) {
            foreach ($e['lines'] as [$code, $dk, $dn, $d, $c]) {
                $rows[$code] ??= ['code' => $code, 'name' => $chart[$code][0] ?? $code, 'type' => $chart[$code][1] ?? 'expense', 'dr' => 0, 'cr' => 0];
                $rows[$code]['dr'] += $d; $rows[$code]['cr'] += $c;
                if ($dk !== '') {
                    $detail[$code][$dk] ??= ['name' => $dn, 'dr' => 0, 'cr' => 0];
                    $detail[$code][$dk]['dr'] += $d; $detail[$code][$dk]['cr'] += $c;
                    if ($dn !== '') { $detail[$code][$dk]['name'] = $dn; }
                }
                $dr += $d; $cr += $c;
            }
        }
        ksort($rows);
        foreach ($rows as $k => $r) { $rows[$k]['balance'] = $r['dr'] - $r['cr']; }
        return ['rows' => $rows, 'detail' => $detail, 'dr' => $dr, 'cr' => $cr, 'to' => $to, 'from' => $from];
    }

    /**
     * سندهای تا یک روز از فهرستِ همه‌ی سندها — همان `entries($u, $at)` (هر منبع
     * با تاریخِ خودش بریده می‌شود)، بی‌کوئریِ دوم. صفحه‌ی ترازنامه یک بار همه را
     * می‌سازد: ترازنامه از «تا آن روز» و مغایرت‌گیری از «همه» (`reconcile()`).
     */
    public static function upTo(array $entries, string $at): array
    {
        return array_values(array_filter($entries, fn(array $e): bool => $e['date'] <= $at));
    }

    /**
     * ⛔ ترازنامه در یک تاریخ — دارایی = بدهی + سرمایه. سودِ انباشته = جمعِ
     *    حساب‌های درآمد و هزینه تا آن روز (همان `BizReports::profit()` بنا به
     *    ساخت: فروشِ خالص − بهای تمام‌شده − کسریِ انبار − خریدِ بی‌انبار +
     *    درآمد − هزینه)، جدا برای «سال‌های قبل» و «سالِ جاری».
     * @return array{asset:array, liability:array, equity:array, assets:int, liabilities:int, equities:int, diff:int, year_from:string}
     */
    public static function balanceSheet(int $userId, string $at, ?array $entries = null): array
    {
        $entries ??= self::entries($userId, $at);
        [$jy] = BizVat::jym($at);
        [$yf] = BizVat::year($jy);
        $before = array_values(array_filter($entries, fn($e) => $e['date'] < $yf));
        $tb  = self::trial($userId, $at, '', $entries);
        $pl  = fn(array $t): int => array_sum(array_map(fn($r) => in_array($r['type'], ['income', 'expense'], true) ? $r['cr'] - $r['dr'] : 0, $t['rows']));
        $bal = fn(string $c): int => (int)($tb['rows'][$c]['balance'] ?? 0);

        $recv = 0; $pay = 0;
        foreach ($tb['detail']['1103'] ?? [] as $d) {
            $b = $d['dr'] - $d['cr'];
            if ($b > 0) { $recv += $b; } else { $pay -= $b; }
        }
        $asset = ['صندوق و بانک' => $bal('1101'), 'اسنادِ دریافتنی (چکِ در جریان)' => $bal('1102'),
                  'بدهکاران (طلب از طرف‌حساب‌ها)' => $recv, 'موجودیِ کالا' => $bal('1104'),
                  'مالیات بر ارزش افزوده‌ی خرید' => $bal('1105'),
                  'دارایی‌های ثابت (خالصِ استهلاک)' => $bal('1201') + $bal('1202')];
        $liab  = ['بستانکاران (بدهی به طرف‌حساب‌ها)' => $pay, 'اسنادِ پرداختنی (چکِ پرداختیِ در جریان)' => -$bal('2102'),
                  'مالیات بر ارزش افزوده‌ی فروش' => -$bal('2103')];
        $prevProfit = $pl(self::trial($userId, $at, '', $before));
        $equity = ['سرمایه‌ی اول دوره' => -$bal('3101'), 'آورده‌ی مالک' => -$bal('3102'), 'برداشتِ مالک' => -$bal('3103'),
                   'سود و زیانِ سال‌های قبل' => $prevProfit, 'سود و زیانِ سالِ جاری' => $pl($tb) - $prevProfit];
        // ⛔ حساب‌های دلخواه (`BizCoa`) در گروهِ نوعِ خودشان؛ درآمد و هزینه‌شان از راهِ `$pl`
        foreach ($tb['rows'] as $code => $r) {
            if (isset(self::ACCOUNTS[$code])) { continue; }
            $label = toPersianDigits((string)$code) . ' — ' . $r['name'];
            if ($r['type'] === 'asset') { $asset[$label] = $r['balance']; }
            elseif ($r['type'] === 'liability') { $liab[$label] = -$r['balance']; }
            elseif ($r['type'] === 'equity') { $equity[$label] = -$r['balance']; }
        }
        $A = array_sum($asset); $L = array_sum($liab); $E = array_sum($equity);
        return ['asset' => $asset, 'liability' => $liab, 'equity' => $equity, 'assets' => $A, 'liabilities' => $L,
                'equities' => $E, 'diff' => $A - $L - $E, 'year_from' => $yf];
    }

    /**
     * ⛔ مغایرت‌گیری — همان چیزی که دفترِ مشتق واقعاً می‌سنجد: مانده‌ی هر
     *    صندوق و هر طرف‌حساب در دفتر باید **دقیقاً** همان عددِ صفحه‌های
     *    فروشگاه باشد (`BizCash::balanceSql()`، `BizParties::BALANCE_SQL`).
     *    ناهمخوانی یعنی سندی که به یکی رسیده و به دیگری نه — خطا. ارزشِ انبار
     *    (میانگینِ موزون) با جمعِ ورود و خروج به بهای سند چند تومانی گرد
     *    می‌خورد؛ آن فقط «اطلاع» است.
     *
     * ⛔ همیشه **کلِ** دفتر، نه دفترِ «تا یک تاریخ»: عددِ صفحه‌ها مانده‌ی امروز
     *    با همه‌ی سندهاست، پس ترازنامه‌ی دیروز (یا امروز، کنارِ یک دریافتِ
     *    تاریخِ آینده) «مغایرت»ِ قرمزِ دروغ نشان می‌داد (بازبینیِ مهر ۱۴۰۵،
     *    بازتولید شد). این سنجش به تاریخ بسته نیست — درستیِ ساختِ دفتر است و
     *    دفترِ هر تاریخ بریده‌ی همان سندهاست (`upTo()`). ترازِ ناقص نادیده
     *    گرفته و کلِ دفتر ساخته می‌شود.
     * @return array{cash:list<array>, parties:list<array>, stock:array{ledger:int, book:int, diff:int}, ok:bool}
     */
    public static function reconcile(int $userId, ?array $trial = null): array
    {
        if ($trial === null || ($trial['to'] ?? '') !== BizReports::ALL_TO || ($trial['from'] ?? '') !== '') {
            $trial = self::trial($userId, BizReports::ALL_TO);
        }
        $pdo = Database::getConnection();
        $cash = [];
        foreach (BizCash::list($userId) as $a) {
            $code = self::cashCode((string)$a['kind']);
            $d = $trial['detail'][$code]['a' . $a['id']] ?? ['dr' => 0, 'cr' => 0];
            $led = $d['dr'] - $d['cr'];
            if ($led !== (int)$a['balance']) { $cash[] = ['name' => (string)$a['name'], 'ledger' => $led, 'book' => (int)$a['balance']]; }
        }
        $st = $pdo->prepare('SELECT p.id, p.name, ' . BizParties::BALANCE_SQL . ' AS bal FROM biz_parties p WHERE p.user_id = :u');
        $st->execute(['u' => $userId]);
        $parties = [];
        foreach ($st->fetchAll() as $p) {
            $d = $trial['detail']['1103']['p' . $p['id']] ?? ['dr' => 0, 'cr' => 0];
            $led = $d['dr'] - $d['cr'];
            if ($led !== (int)$p['bal']) { $parties[] = ['name' => (string)$p['name'], 'ledger' => $led, 'book' => (int)$p['bal']]; }
        }
        // گذری باید صفر باشد (فاکتورِ گذری فقط با تسویه‌ی کامل صادر می‌شود)
        $w = $trial['detail']['1103']['p0'] ?? ['dr' => 0, 'cr' => 0];
        if ($w['dr'] !== $w['cr']) { $parties[] = ['name' => 'گذری', 'ledger' => $w['dr'] - $w['cr'], 'book' => 0]; }
        $st = $pdo->prepare('SELECT COALESCE(SUM(CASE WHEN track_stock = 1 AND stock_qty > 0 THEN stock_qty * avg_cost ELSE 0 END), 0)
                             FROM biz_products WHERE user_id = :u');
        $st->execute(['u' => $userId]);
        $book = (int)round((float)$st->fetchColumn());
        $led  = (int)($trial['rows']['1104']['balance'] ?? 0);
        return ['cash' => $cash, 'parties' => $parties, 'stock' => ['ledger' => $led, 'book' => $book, 'diff' => $led - $book],
                'ok' => !$cash && !$parties];
    }

    /**
     * روزنامه به CSV (BOM، مبلغِ عددِ خام — همان قاعده‌ی خروجیِ تراکنش‌ها).
     * ⛔ از `BizSheet::writeCsv()`: شرح و تفصیلی نامِ طرف‌حساب و شرحِ هزینه‌اند —
     *    متنِ کاربر — و نامی مثلِ `=HYPERLINK(…)` در اکسلِ حسابدار فرمول اجرا
     *    می‌کرد (بازبینیِ مهر ۱۴۰۵، بازتولید شد). مبلغ عدد است و دست نمی‌خورد.
     */
    public static function csv(array $entries, int $userId = 0): string
    {
        $chart = $userId > 0 ? BizCoa::map($userId) : self::ACCOUNTS;
        require_once __DIR__ . '/biz_io.php';
        $rows = [['شماره‌ی سند', 'تاریخ', 'شرح', 'کدِ حساب', 'حساب', 'تفصیلی', 'بدهکار', 'بستانکار']];
        foreach (array_values($entries) as $n => $e) {
            foreach ($e['lines'] as [$code, , $dn, $d, $c]) {
                $rows[] = [$n + 1, toJalali($e['date']), $e['desc'], $code, $chart[$code][0] ?? $code, $dn, (int)$d, (int)$c];
            }
        }
        return BizSheet::writeCsv($rows);
    }
}

/* =================================================================
   بستنِ سالِ مالی
   ================================================================= */
final class BizYear
{
    /** سال‌های بسته. @return list<array> */
    public static function closes(int $userId): array
    {
        if (!Biz::accReady()) { return []; }
        $st = Database::getConnection()->prepare('SELECT id, jyear, from_date, to_date, profit, snapshot, closed_at FROM biz_year_closes WHERE user_id = :u ORDER BY jyear DESC');
        $st->execute(['u' => $userId]);
        return array_map(function ($r) { $r['snapshot'] = json_decode((string)$r['snapshot'], true) ?: []; return $r; }, $st->fetchAll());
    }

    /**
     * ⛔ بستنِ سال: عکسِ سود و زیان، ترازِ آزمایشی و ترازنامه‌ی روزِ آخرِ سال
     *    نگه داشته می‌شود و دوره تا همان روز قفل (`Biz::saveLock()` — همان
     *    قفلی که همه‌ی نوشتن‌ها می‌پرسند). سالی که هنوز تمام نشده بسته نمی‌شود.
     *    ⚠ حساب‌های موقت «صفر» نمی‌شوند (سندِ بستن ساخته نمی‌شود): دفترِ مشتق
     *    سودِ هر سال را از تاریخ جدا می‌کند و ترازنامه آن را «سال‌های قبل» نشان
     *    می‌دهد — همان نتیجه، بی‌سندِ ساختگی.
     * @return array{ok:bool, message:string}
     */
    public static function close(int $userId, int $jy): array
    {
        if (!Biz::accReady()) { return ['ok' => false, 'message' => 'لایه‌ی حسابداری هنوز راه نیفتاده است.']; }
        if ($jy < 1380 || $jy > 1500) { return ['ok' => false, 'message' => 'سال معتبر نیست.']; }
        [$from, $to] = BizVat::year($jy);
        if ($to >= date('Y-m-d')) { return ['ok' => false, 'message' => 'سالِ ' . toPersianDigits((string)$jy) . ' هنوز تمام نشده است؛ بعد از پایانِ اسفند بسته می‌شود.']; }
        $st = Database::getConnection()->prepare('SELECT COUNT(*) FROM biz_year_closes WHERE user_id = :u AND jyear = :y');
        $st->execute(['u' => $userId, 'y' => $jy]);
        if ((int)$st->fetchColumn() > 0) { return ['ok' => false, 'message' => 'این سال از قبل بسته است.']; }

        $sales = BizReports::sales($userId, $from, $to);
        $cash  = BizReports::cash($userId, $from, $to);
        $profit = BizReports::profit($sales['gross'], $sales['other'], $cash['income'], $cash['expense'], BizBooks::plAdjust($userId, $from, $to));
        $entries = BizLedger::entries($userId, $to);
        $trial = BizLedger::trial($userId, $to, '', $entries);
        $bs    = BizLedger::balanceSheet($userId, $to, $entries);
        $snap = ['sales' => $sales, 'income' => $cash['income'], 'expense' => $cash['expense'], 'profit' => $profit,
                 'trial' => array_values(array_map(fn($r) => ['code' => $r['code'], 'dr' => $r['dr'], 'cr' => $r['cr']], $trial['rows'])),
                 'balance' => ['asset' => $bs['asset'], 'liability' => $bs['liability'], 'equity' => $bs['equity']]];
        $lock = Biz::lockDate($userId);
        if ($lock === null || $lock < $to) {
            $r = Biz::saveLock($userId, $to);
            if (!$r['ok']) { return $r; }
        }
        Database::getConnection()->prepare('INSERT INTO biz_year_closes (user_id, jyear, from_date, to_date, profit, snapshot) VALUES (:u, :y, :f, :t, :p, :s)')
            ->execute(['u' => $userId, 'y' => $jy, 'f' => $from, 't' => $to, 'p' => $profit, 's' => json_encode($snap, JSON_UNESCAPED_UNICODE)]);
        return ['ok' => true, 'message' => 'سالِ مالیِ ' . toPersianDigits((string)$jy) . ' بسته شد؛ سود و زیان ' . formatMoney($profit)
                                           . ' تومان. سندهای این سال دیگر ثبت یا اصلاح نمی‌شوند.'];
    }

    /** بازگشاییِ سال — عکس پاک و قفل تا پیش از آن سال عقب می‌رود (تأییدِ صریح). */
    public static function reopen(int $userId, int $jy, bool $confirm): array
    {
        if (!Biz::accReady()) { return ['ok' => false, 'message' => 'لایه‌ی حسابداری هنوز راه نیفتاده است.']; }
        if (!$confirm) { return ['ok' => false, 'message' => 'برای بازگشاییِ سالِ بسته، گزینه‌ی تأیید را بزنید.']; }
        [$from] = BizVat::year($jy);
        $pdo = Database::getConnection();
        $del = $pdo->prepare('DELETE FROM biz_year_closes WHERE user_id = :u AND jyear >= :y');
        $del->execute(['u' => $userId, 'y' => $jy]);
        if ($del->rowCount() === 0) { return ['ok' => false, 'message' => 'این سال بسته نیست.']; }
        $lock = Biz::lockDate($userId);
        if ($lock !== null && $lock >= $from) {
            $prev = date('Y-m-d', strtotime($from . ' -1 day'));
            $r = Biz::saveLock($userId, $prev < BizReports::ALL_FROM ? '' : $prev, true);
            if (!$r['ok']) { return $r; }
        }
        return ['ok' => true, 'message' => 'سالِ ' . toPersianDigits((string)$jy) . ' (و سال‌های بعدش) باز شد.'];
    }
}

/* =================================================================
   شمارشِ صندوق
   ================================================================= */
final class BizCashCount
{
    /**
     * مانده‌ی یک صندوق تا پایانِ یک روز — ⛔ همان `BizCash::balanceSql()` با تاریخ،
     * نه کپیِ آن (کپیِ قبلی فهرستِ نوع‌های خودش را داشت).
     */
    public static function balanceAt(PDO $pdo, int $userId, int $accountId, string $date): ?int
    {
        $st = $pdo->prepare('SELECT ' . BizCash::balanceSql(true) . ' FROM biz_accounts a WHERE a.id = :a AND a.user_id = :u');
        $st->execute(['bal_d1' => $date, 'bal_d2' => $date, 'a' => $accountId, 'u' => $userId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /**
     * ⛔ «پولِ واقعیِ صندوق این است» — تفاوت با دفتر یک سند می‌شود: کسری =
     *    هزینه زیرِ سرفصلِ سیستمیِ «کسریِ صندوق»، اضافه = درآمد زیرِ «اضافه‌ی
     *    صندوق»؛ از همان `BizPay::createTx()` (قفلِ دوره، مانده، سرگذشت). خودِ
     *    شمارش هم ثبت می‌شود، حتی وقتی برابر بود — حسابدار «کِی شمرده شد» را
     *    می‌خواهد. صندوقِ چک شمرده نمی‌شود (پولِ نقد نیست).
     * @return array{ok:bool, message:string, diff?:int}
     */
    public static function record(int $userId, int $accountId, $counted, string $date, string $note = ''): array
    {
        if (!Biz::accReady()) { return ['ok' => false, 'message' => 'لایه‌ی حسابداری هنوز راه نیفتاده است.']; }
        $raw = trim((string)$counted);
        if ($raw === '') { return ['ok' => false, 'message' => 'مبلغِ شمارش‌شده را بنویسید (صندوقِ خالی: ۰).']; }
        $neg = str_starts_with(toLatinDigits($raw), '-');
        $cnt = sanitizeAmount($raw);
        if ($neg) { return ['ok' => false, 'message' => 'پولِ شمارش‌شده منفی نمی‌شود.']; }
        if (($e = BizCommon::moneyError($cnt, 'مبلغِ شمارش‌شده')) !== null) { return ['ok' => false, 'message' => $e]; }
        if (!isValidDate($date)) { return ['ok' => false, 'message' => 'تاریخِ شمارش معتبر نیست.']; }
        if ($date > date('Y-m-d')) { return ['ok' => false, 'message' => 'شمارش برای روزِ آینده ثبت نمی‌شود.']; }
        $note = mb_substr(trim($note), 0, 300);
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        Biz::lockShop($pdo, $userId);
        try {
            $a = $pdo->prepare('SELECT id, name, kind, is_active FROM biz_accounts WHERE id = :id AND user_id = :u');
            $a->execute(['id' => $accountId, 'u' => $userId]);
            $acc = $a->fetch();
            if (!$acc || (int)$acc['is_active'] !== 1) { $pdo->rollBack(); return ['ok' => false, 'message' => 'صندوق را انتخاب کنید.']; }
            if (BizCash::isCheque($acc)) { $pdo->rollBack(); return ['ok' => false, 'message' => 'صندوقِ چک شمرده نمی‌شود؛ چک‌ها را از صفحه‌ی «چک‌ها» ببینید.']; }
            $sys = (int)self::balanceAt($pdo, $userId, $accountId, $date);
            $diff = $cnt - $sys;
            $payId = null;
            if ($diff !== 0) {
                $short = $diff < 0;
                $cat = BizExpCats::sysId($pdo, $userId, $short ? 'cash_short' : 'cash_over');
                $r = BizPay::createTx($pdo, $userId, [
                    'kind' => $short ? 'expense' : 'income', 'amount' => (string)abs($diff), 'account_id' => $accountId,
                    'pay_date' => $date, 'category_id' => $cat, 'method' => 'cash',
                    'title' => ($short ? 'کسریِ صندوق' : 'اضافه‌ی صندوق') . ' — شمارشِ ' . toJalali($date),
                    'note' => $note,
                ]);
                if (!$r['ok']) { $pdo->rollBack(); return $r; }
                $payId = (int)$r['id'];
            }
            $pdo->prepare('INSERT INTO biz_cash_counts (user_id, account_id, count_date, system_balance, counted, payment_id, note)
                           VALUES (:u, :a, :d, :s, :c, :p, :n)')
                ->execute(['u' => $userId, 'a' => $accountId, 'd' => $date, 's' => $sys, 'c' => $cnt, 'p' => $payId,
                           'n' => $note === '' ? null : $note]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        $msg = $diff === 0 ? '«' . $acc['name'] . '» با دفتر برابر بود.'
            : ($diff < 0 ? 'کسریِ ' . formatMoney(-$diff) . ' تومان به‌عنوانِ هزینه‌ی «کسریِ صندوق» ثبت شد.'
                         : 'اضافه‌ی ' . formatMoney($diff) . ' تومان به‌عنوانِ درآمدِ «اضافه‌ی صندوق» ثبت شد.');
        return ['ok' => true, 'message' => $msg, 'diff' => $diff];
    }

    /** آخرین شمارش‌ها. */
    public static function history(int $userId, int $limit = 30): array
    {
        if (!Biz::accReady()) { return []; }
        $st = Database::getConnection()->prepare(
            'SELECT c.*, a.name AS account_name FROM biz_cash_counts c JOIN biz_accounts a ON a.id = c.account_id AND a.user_id = c.user_id
             WHERE c.user_id = :u ORDER BY c.count_date DESC, c.id DESC LIMIT :lim'
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}

/* =================================================================
   حقوق و مساعده
   ================================================================= */
final class BizPayroll
{
    /** کارکنانِ فعال با مانده (مثبت = مساعده‌ی تسویه‌نشده). */
    public static function employees(int $userId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT p.id, p.name, p.phone, " . BizParties::BALANCE_SQL . " AS balance FROM biz_parties p
             WHERE p.user_id = :u AND p.kind = 'employee' AND p.is_active = 1 ORDER BY p.name, p.id LIMIT 500"
        );
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    }

    private static function employee(int $userId, int $id): ?array
    {
        $p = BizParties::get($userId, $id);
        return $p && ($p['kind'] ?? '') === 'employee' && (int)$p['is_active'] === 1 ? $p : null;
    }

    /** مساعده — پرداخت به کارمند؛ مانده‌اش بدهکار می‌شود تا از حقوق کسر شود. */
    public static function advance(int $userId, int $empId, $amount, int $accountId, string $date, string $note = '', string $method = 'cash'): array
    {
        $emp = self::employee($userId, $empId);
        if (!$emp) { return ['ok' => false, 'message' => 'کارمند را انتخاب کنید (طرف‌حسابِ نوعِ «کارمند»).']; }
        return BizPay::create($userId, ['kind' => 'payment', 'party_id' => $empId, 'amount' => $amount, 'account_id' => $accountId,
                                        'pay_date' => $date, 'method' => $method === 'cheque' ? 'cash' : $method,
                                        'title' => 'مساعده', 'note' => $note]);
    }

    /**
     * ⛔ پرداختِ حقوق با کسرِ مساعده — در **یک** تراکنش:
     *    هزینه‌ی کاملِ حقوق (سرفصلِ سیستمیِ «حقوق و دستمزد»، پیوند به کارمند)
     *    + دریافتِ مساعده از همان کارمند به همان صندوق. خروجِ واقعیِ صندوق =
     *    حقوق − کسر؛ سود به اندازه‌ی **کلِ** حقوق کم می‌شود و مانده‌ی کارمند به
     *    اندازه‌ی کسر تسویه. کسر بیش از مساعده‌ی باز یا بیش از حقوق رد می‌شود.
     * @return array{ok:bool, message:string}
     */
    public static function paySalary(int $userId, int $empId, $gross, $deduct, int $accountId, string $date, string $note = '', string $method = 'cash'): array
    {
        if (!Biz::accReady()) { return ['ok' => false, 'message' => 'لایه‌ی حسابداری هنوز راه نیفتاده است.']; }
        $g = sanitizeAmount((string)$gross);
        $d = sanitizeAmount((string)$deduct);
        if ($g <= 0) { return ['ok' => false, 'message' => 'مبلغِ حقوق را بنویسید.']; }
        if ($d > $g) { return ['ok' => false, 'message' => 'کسرِ مساعده از خودِ حقوق بیشتر است.']; }
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        Biz::lockShop($pdo, $userId);
        try {
            $emp = self::employee($userId, $empId);
            if (!$emp) { $pdo->rollBack(); return ['ok' => false, 'message' => 'کارمند را انتخاب کنید (طرف‌حسابِ نوعِ «کارمند»).']; }
            if ($d > 0) {
                $st = $pdo->prepare('SELECT ' . BizParties::BALANCE_SQL . ' FROM biz_parties p WHERE p.id = :id AND p.user_id = :u');
                $st->execute(['id' => $empId, 'u' => $userId]);
                $open = max(0, (int)$st->fetchColumn());
                if ($d > $open) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'مساعده‌ی باز «' . $emp['name'] . '» ' . formatMoney($open) . ' تومان است؛ بیش از آن کسر نمی‌شود.'];
                }
            }
            $cat = BizExpCats::sysId($pdo, $userId, 'salary');
            $r = BizPay::createTx($pdo, $userId, ['kind' => 'expense', 'party_id' => $empId, 'category_id' => $cat, 'amount' => (string)$g,
                                                  'account_id' => $accountId, 'pay_date' => $date, 'method' => $method === 'cheque' ? 'cash' : $method,
                                                  'title' => mb_substr('حقوقِ ' . $emp['name'], 0, BizPay::TITLE_MAX), 'note' => $note]);
            if (!$r['ok']) { $pdo->rollBack(); return $r; }
            if ($d > 0) {
                $r2 = BizPay::createTx($pdo, $userId, ['kind' => 'receipt', 'party_id' => $empId, 'amount' => (string)$d,
                                                       'account_id' => $accountId, 'pay_date' => $date, 'method' => 'cash',
                                                       'title' => 'کسرِ مساعده از حقوق', 'note' => $note]);
                if (!$r2['ok']) { $pdo->rollBack(); return $r2; }
                // ⛔ کسر به حقوقش اشاره می‌کند تا با هم باطل شوند (`BizPay::void()`)
                if (BizPay::pairReady()) {
                    $pdo->prepare('UPDATE biz_payments SET pair_id = :e WHERE id = :r AND user_id = :u')
                        ->execute(['e' => (int)$r['id'], 'r' => (int)$r2['id'], 'u' => $userId]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => 'حقوقِ «' . $emp['name'] . '» ثبت شد: ' . formatMoney($g) . ' تومان'
            . ($d > 0 ? '، کسرِ مساعده ' . formatMoney($d) . '، پرداختِ خالص ' . formatMoney($g - $d) : '') . '.'];
    }

    /** گردشِ حقوق و مساعده (آخرین‌ها). */
    public static function history(int $userId, int $limit = 40): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT y.id, y.kind, y.number, y.pay_date, y.amount, y.title, p.name AS emp
             FROM biz_payments y JOIN biz_parties p ON p.id = y.party_id AND p.user_id = y.user_id
             WHERE y.user_id = :u AND y.status = 'ok' AND p.kind = 'employee' ORDER BY y.pay_date DESC, y.id DESC LIMIT :lim"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}

/* =================================================================
   سامانه‌ی مودیان — صورتحسابِ الکترونیکی (خروجی)
   ================================================================= */
/**
 * ⛔ این کلاس **ارسال نمی‌کند**. ارسالِ زنده امضای دیجیتالِ صورتحساب با کلیدِ
 *    خصوصیِ خودِ فروشگاه، رمزگذاری با کلیدِ سازمان و ثبتِ «شناسه‌ی حافظه» در
 *    کارپوشه می‌خواهد — و با سامانه‌ی واقعی آزموده نشده است. آنچه اینجاست
 *    ساختنِ صورتحسابِ استاندارد (سرآیند، اقلام، پرداخت؛ مبلغ‌ها به **ریال**) و
 *    شماره‌ی مالیاتیِ ۲۲ نویسه‌ای (`taxId()`، الگوریتمِ SDKِ منتشرشده با رقمِ
 *    کنترلِ Verhoeff) است، برای تحویل به شرکتِ معتمد یا ابزارِ ارسال.
 */
final class BizMoadian
{
    /** نوعِ موضوعِ صورتحساب (`ins`). */
    public const SUBJECT = ['sale' => 1, 'sale_return' => 4];

    private const VD = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],
                        [4,0,1,2,3,9,5,6,7,8],[5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],
                        [8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
    private const VP = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],
                        [9,4,5,3,1,2,6,8,7,0],[4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
    private const VINV = [0,4,3,2,1,5,6,7,8,9];

    /** رقمِ کنترلِ Verhoeff برای یک رشته‌ی رقمی. */
    public static function verhoeff(string $digits): int
    {
        $c = 0;
        $rev = array_reverse(str_split($digits));
        foreach ($rev as $i => $ch) { $c = self::VD[$c][self::VP[($i + 1) % 8][(int)$ch]]; }
        return self::VINV[$c];
    }

    /**
     * ⛔ شماره‌ی مالیاتی (taxid): شناسه‌ی حافظه (۶) + روز از ۱۹۷۰ به هگز (۵) +
     *    سریال به هگز (۱۰) + رقمِ کنترل. رقمِ کنترل روی «شناسه‌ی حافظه به
     *    دهدهی (هر حرف = کدِ ASCII) + روز (۶ رقم) + سریال (۱۲ رقم)».
     */
    public static function taxId(string $memoryId, string $date, int $serial): string
    {
        $days = intdiv((int)strtotime($date . ' 00:00:00 UTC'), 86400);
        $dec = '';
        foreach (str_split($memoryId) as $ch) { $dec .= ctype_digit($ch) ? $ch : (string)ord($ch); }
        $check = self::verhoeff($dec . str_pad((string)$days, 6, '0', STR_PAD_LEFT) . str_pad((string)$serial, 12, '0', STR_PAD_LEFT));
        return strtoupper($memoryId . str_pad(dechex($days), 5, '0', STR_PAD_LEFT) . str_pad(dechex($serial), 10, '0', STR_PAD_LEFT) . $check);
    }

    /**
     * صورتحسابِ استاندارد یک فاکتورِ فروش یا برگشت از فروش، با فهرستِ ایرادها
     * (کدِ اقتصادی، شناسه‌ی حافظه، شناسه‌ی کالا…) که پیش از ارسال باید رفع شوند.
     * @return array{ok:bool, message?:string, invoice?:array, problems?:list<string>}
     */
    public static function payload(int $userId, int $invoiceId): array
    {
        $inv = BizInvoices::get($userId, $invoiceId);
        if (!$inv || !isset(self::SUBJECT[$inv['kind']]) || $inv['status'] !== 'issued') {
            return ['ok' => false, 'message' => 'فقط فاکتورِ فروش یا برگشت از فروشِ صادرشده.'];
        }
        $shop = Biz::settings($userId);
        $m = Biz::moadian($userId);
        $problems = [];
        if ($m['memory_id'] === '') { $problems[] = 'شناسه‌ی یکتای حافظه‌ی مالیاتی (تنظیماتِ فروشگاه ← حسابداری) خالی است.'; }
        if ((string)$shop['economic_code'] === '' && (string)$shop['national_id'] === '') { $problems[] = 'کدِ اقتصادی/شناسه‌ی ملیِ فروشگاه (تنظیمات) خالی است.'; }
        $rial = fn(int $t): int => $t * 10;
        $items = []; $tprdis = 0; $tdis = 0; $tadis = 0; $tvam = 0;
        foreach ($inv['lines'] as $n => $l) {
            $sst = (string)($l['tax_code'] ?? '') !== '' ? (string)$l['tax_code'] : $m['sstid'];
            if ($sst === '') { $problems[] = 'ردیفِ ' . toPersianDigits((string)($n + 1)) . ' («' . $l['description'] . '»): شناسه‌ی کالا/خدمت ندارد.'; }
            $am  = (float)$l['qty'];
            $prd = $rial((int)round($am * (int)$l['unit_price']));
            $ad  = $rial((int)$l['net_total']);
            $vam = $rial((int)($l['tax_amount'] ?? 0));
            if ($ad > $prd) { $problems[] = 'ردیفِ ' . toPersianDigits((string)($n + 1)) . ': هزینه‌ی جانبی (حمل) در سامانه قلمِ جدا می‌خواهد.'; }
            $items[] = ['sstid' => $sst, 'sstt' => (string)$l['description'], 'mu' => null, 'am' => $am,
                        'fee' => $rial((int)$l['unit_price']), 'prdis' => $prd, 'dis' => $prd - $ad, 'adis' => $ad,
                        'vra' => (float)($inv['vat_rate'] ?? 0), 'vam' => $vam, 'tsstam' => $ad + $vam];
            $tprdis += $prd; $tdis += $prd - $ad; $tadis += $ad; $tvam += $vam;
        }
        $bid = (string)($inv['party_national_id'] ?? '');
        $tinb = (string)($inv['party_economic_code'] ?? '');
        $typed = $bid !== '' || $tinb !== '';
        $serial = (int)$inv['id'];
        $memory = $m['memory_id'] !== '' ? $m['memory_id'] : 'XXXXXX';
        $taxid = self::taxId($memory, (string)$inv['inv_date'], $serial);
        $irtaxid = null;
        if ($inv['kind'] === 'sale_return' && $inv['ref_invoice_id'] !== null) {
            $orig = BizInvoices::get($userId, (int)$inv['ref_invoice_id']);
            $irtaxid = $orig ? self::taxId($memory, (string)$orig['inv_date'], (int)$orig['id']) : null;
        }
        $total = $rial((int)$inv['total']); $paid = $rial(min((int)$inv['paid'], (int)$inv['total']));
        $header = [
            'taxid' => $taxid, 'indatim' => (int)strtotime($inv['inv_date'] . ' 12:00:00') * 1000,
            'inty' => $typed ? 1 : 2, 'inno' => str_pad((string)$serial, 10, '0', STR_PAD_LEFT), 'irtaxid' => $irtaxid,
            'inp' => 1, 'ins' => self::SUBJECT[$inv['kind']],
            'tins' => (string)$shop['economic_code'] !== '' ? (string)$shop['economic_code'] : (string)$shop['national_id'],
            'tob' => $typed ? (strlen($bid) === 11 ? 2 : 1) : null, 'bid' => $bid !== '' ? $bid : null,
            'tinb' => $tinb !== '' ? $tinb : null, 'bpc' => (string)($inv['party_postal_code'] ?? '') !== '' ? (string)$inv['party_postal_code'] : null,
            'tprdis' => $tprdis, 'tdis' => $tdis, 'tadis' => $tadis, 'tvam' => $tvam, 'todam' => 0, 'tbill' => $tadis + $tvam,
            'setm' => $paid >= $total ? 1 : ($paid === 0 ? 2 : 3), 'cap' => $paid, 'insp' => $total - $paid,
        ];
        if ($header['tbill'] !== $total) { $problems[] = 'جمعِ اقلام با مبلغِ فاکتور نمی‌خواند.'; }
        return ['ok' => true, 'invoice' => ['header' => $header, 'body' => $items, 'payments' => []], 'problems' => array_values(array_unique($problems))];
    }

    /** فاکتورهای فروش/برگشتِ صادرشده‌ی یک بازه. */
    public static function list(int $userId, string $from, string $to, int $cap = 500): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT i.id, i.kind, i.number, i.inv_date, i.total, " . (Biz::accReady() ? 'i.tax_total' : '0 AS tax_total') . ", p.name AS party_name
             FROM biz_invoices i LEFT JOIN biz_parties p ON p.id = i.party_id AND p.user_id = i.user_id
             WHERE i.user_id = :u AND i.status = 'issued' AND i.kind IN ('sale','sale_return') AND i.inv_date BETWEEN :f AND :t
             ORDER BY i.inv_date, i.id LIMIT :lim"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('f', $from); $st->bindValue('t', $to);
        $st->bindValue('lim', $cap, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}
