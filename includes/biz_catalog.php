<?php
/**
 * ⛔ محیطِ فروشگاهی — مرحله‌ی ۲: کالا و موجودی، طرف‌حساب، صندوق.
 *
 * هر چهار کلاس فقط روی جدول‌های `biz_*` کار می‌کنند و **هیچ‌کدام** به
 * جدول‌های حساب لندِ شخصی (`transactions`، `wallets`، `debts`، …) دست
 * نمی‌زنند. حسابی که هم حساب لند دارد هم فروشگاه (`both`) دو دفترِ کاملاً
 * جدا دارد؛ «موجودیِ صندوقِ فروشگاه» هرگز در `walletBalances()` نمی‌نشیند.
 *
 * - `BizProducts` — کالا (تعریف، جست‌وجو، فعال/غیرفعال).
 * - `BizStock`    — ⛔ تنها نویسنده‌ی `stock_qty`/`avg_cost` و تنها جای
 *                   حرکتِ انبار. موجودی هرگز منفی نمی‌شود (تصمیمِ مالکِ
 *                   نصب: «فروشِ بیش از موجودی بسته است»).
 * - `BizParties`  — مشتری و تأمین‌کننده.
 * - `BizCash`     — صندوق و حساب‌های بانکیِ خودِ فروشگاه.
 *
 * ⛔ `user_id` همیشه از فراخواننده می‌آید (که آن را از `Auth::userId()`
 *    گرفته) و روی **هر** کوئری هست، از جمله `UPDATE` و `DELETE`.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/biz.php';      // `Biz::lockError()` — بستنِ دوره
require_once __DIR__ . '/biz_warehouses.php';   // چند انبار — سدِ منفیِ هر انبار در `write()`

/** ابزارهای مشترکِ این فایل. */
final class BizCommon
{
    /**
     * ⛔ سقفِ هر عددِ پولی (تومان) و هر مقدار — یک جا برای همه‌ی فرم‌ها.
     *
     * بازرسیِ مهر ۱۴۰۵: مقدارِ ۹۹۹۹۹۹۹۹۹۹۹۹۹۹۹ خطای ۵۰۰ می‌داد (ستونِ
     * DECIMAL(14,3) سر می‌رفت) و فرمِ تایپ‌شده گم می‌شد؛ یک مبلغِ بیست‌رقمی
     * داشبورد را برای همیشه می‌خواباند (سرریزِ عددِ صحیح)؛ و بارکدِ اسکن‌شده در
     * خانه‌ی قیمت بی‌صدا قیمت می‌شد. صد میلیارد تومان برای یک قلم و یک سند
     * بیش از هر معامله‌ی واقعیِ فروشگاه است.
     */
    public const MONEY_MAX = 100_000_000_000;
    public const QTY_MAX   = 1_000_000;

    /** پیامِ خطا اگر مبلغ از سقف بیشتر است، وگرنه `null`. */
    public static function moneyError(int|float $v, string $label): ?string
    {
        return $v > self::MONEY_MAX
            ? $label . ' بیش از حد بزرگ است (سقف ' . formatMoney(self::MONEY_MAX) . ' تومان) — شاید بارکد یا رقمِ اضافه در خانه‌ی مبلغ نشسته.'
            : null;
    }

    /** پیامِ خطا اگر مقدار از سقف بیشتر است، وگرنه `null`. */
    public static function qtyError(float $v, string $label): ?string
    {
        return $v > self::QTY_MAX ? $label . ' بیش از حد بزرگ است (سقف ' . formatMoney(self::QTY_MAX) . ').' : null;
    }

    /**
     * کوچک‌ترین تاریخِ معتبر (`Y-m-d`) — برای سنجشِ قفلِ دوره روی چیزی که
     * پیش از همه‌ی اسناد حساب می‌شود (موجودی و مانده‌ی اول دوره).
     */
    public static function earliest(array $dates): string
    {
        $ok = array_filter(array_map('strval', $dates), fn($d) => isValidDate(substr($d, 0, 10)));
        return $ok ? min(array_map(fn($d) => substr($d, 0, 10), $ok)) : date('Y-m-d');
    }

    /** فرارِ `%` و `_` برای `LIKE … ESCAPE '!'` — همان قاعده‌ی tx_query. */
    public static function like(string $term): string
    {
        return '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /**
     * عدد به حروف — «مبلغ به حروف»ِ فاکتور. سه‌رقمی‌ها با « و » به هم
     * می‌پیوندند (۱٬۲۰۰٬۰۵۰ → «یک میلیون و دویست هزار و پنجاه»)، و «یکصد»
     * نه «صد»، چون متنِ رسمیِ فاکتور همین را می‌خواهد. کلِ بازه‌ی BIGINT.
     */
    public static function words(int $n): string
    {
        if ($n === 0) { return 'صفر'; }
        static $ones  = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
        static $teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
        static $tens  = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
        static $hund  = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
        static $scale = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون', 'کوادریلیون', 'کوینتیلیون'];
        $three = static function (int $x) use ($ones, $teens, $tens, $hund): string {
            $p = [];
            if ($x >= 100) { $p[] = $hund[intdiv($x, 100)]; $x %= 100; }
            if ($x >= 20)  { $p[] = $tens[intdiv($x, 10)]; $x %= 10; }
            if ($x >= 10)  { $p[] = $teens[$x - 10]; $x = 0; }
            if ($x > 0)    { $p[] = $ones[$x]; }
            return implode(' و ', $p);
        };
        // ⚠ روی رقم‌های رشته، نه `-$n`: قدرِ مطلقِ PHP_INT_MIN در int نمی‌گنجد
        $digits = ltrim((string)$n, '-');
        $parts  = [];
        for ($i = 0, $end = strlen($digits); $end > 0; $i++, $end -= 3) {
            $g = (int)substr($digits, max(0, $end - 3), $end - max(0, $end - 3));
            if ($g === 0) { continue; }
            array_unshift($parts, trim($three($g) . ' ' . $scale[$i]));
        }
        return ($n < 0 ? 'منفی ' : '') . implode(' و ', $parts);
    }

    /** یک‌خطی، بی‌فاصله‌ی اضافه. */
    public static function line(string $v): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $v));
    }

    /**
     * ⛔ «ي/ى» و «ك»ِ عربی → «ی» و «ک»ِ فارسی. `utf8mb4_persian_ci` این دو را
     *    **یکی نمی‌داند**، پس کالایی که از اکسل آمده با کیبوردِ فارسی پیدا
     *    نمی‌شد (نه در پیشنهاد، نه در تطبیقِ سرور، نه در فهرست). نام‌ها با
     *    همین نوشته و جست‌وجوها با همین خوانده می‌شوند.
     */
    public static function persian(string $v): string
    {
        return strtr($v, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']);
    }

    /**
     * کلیدِ تطبیقِ «از نظرِ آدم یکی»: حروفِ عربی، ارقامِ فارسی، اعراب و
     * کشیده، نیم‌فاصله و فاصله‌ی اضافه، و بزرگی/کوچکیِ حروفِ لاتین.
     * ⛔ فقط برای **مقایسه‌ی برابری** — هیچ‌جا ذخیره نمی‌شود.
     */
    public static function fold(string $v): string
    {
        // «آیفون» و «ایفون» یکی‌اند: کاربر روی کیبوردِ گوشی اغلب «آ» را نمی‌زند
        $v = strtr(toLatinDigits(self::persian($v)), ['آ' => 'ا', 'أ' => 'ا', 'إ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و', 'ة' => 'ه', 'ۀ' => 'ه']);
        $v = (string)preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $v);
        $v = (string)preg_replace('/[\s\x{200c}\x{200f}\x{200e}]+/u', ' ', $v);
        return mb_strtolower(trim($v));
    }

    /**
     * صفحه‌بندی — `[page, pages, offset]`؛ شماره‌ی بیرون از بازه به
     * نزدیک‌ترین صفحه بریده می‌شود، نه فهرستِ خالی (همان قاعده‌ی
     * `paged_list.php`).
     */
    public static function window(int $total, int $page, int $size): array
    {
        $pages = max(1, (int)ceil($total / max(1, $size)));
        $page  = min(max(1, $page), $pages);
        return [$page, $pages, ($page - 1) * $size];
    }

    /**
     * ⛔ تنها مرجعِ کدهای رسمی — فروشگاه (سربرگ) و طرف‌حساب (خریدار) هر دو از
     *    همین. [کمینه‌ی رقم، بیشینه‌ی رقم، برچسب]. شناسه‌ی ملیِ شرکت ۱۱ و کدِ
     *    ملیِ شخص ۱۰ رقم است؛ کدِ اقتصادیِ قدیمی ۱۲ و تازه ۱۴ (یا همان کدِ ملی).
     */
    public const CODES = [
        'national_id'   => [10, 11, 'شناسه‌ی ملی / کد ملی'],
        'economic_code' => [10, 14, 'کد اقتصادی'],
        'reg_no'        => [1, 20, 'شماره‌ی ثبت'],
        'postal_code'   => [10, 10, 'کد پستی'],
    ];

    /**
     * یک کدِ رسمی → فقط رقمِ لاتین (کیبوردِ فارسی «۱۲۳» و فاصله و خط‌تیره
     * می‌دهد). خالی یعنی «ندارد»، نه خطا.
     * @return array{ok:bool, value:?string, message?:string}
     */
    public static function code(string $key, string $raw): array
    {
        [$min, $max, $label] = self::CODES[$key];
        $v = (string)preg_replace('/[\s\-\/.\x{200c}]+/u', '', toLatinDigits(trim($raw)));
        if ($v === '') { return ['ok' => true, 'value' => null]; }
        if (!preg_match('/^\d+$/', $v) || strlen($v) < $min || strlen($v) > $max) {
            $len = $min === $max ? toPersianDigits((string)$min) : toPersianDigits((string)$min) . ' تا ' . toPersianDigits((string)$max);
            return ['ok' => false, 'value' => null, 'message' => $label . ' باید ' . $len . ' رقم باشد.'];
        }
        return ['ok' => true, 'value' => $v];
    }
}

/* =================================================================
   کالا
   ================================================================= */
final class BizProducts
{
    /**
     * ⛔ سطح‌های قیمت — تنها مرجع (مشتری `biz_parties.price_level`، کالا ستونِ هر سطح).
     *    قیمتِ سطحِ خالی ⇒ همان قیمتِ فروش (`parseLines()`)؛ پس تعریفِ نکردنِ قیمتِ عمده
     *    یعنی «مثلِ تک‌فروشی»، نه صفر.
     */
    public const LEVELS = [
        'retail'    => ['label' => 'تک‌فروشی', 'col' => 'sell_price'],
        'wholesale' => ['label' => 'عمده',     'col' => 'price_wholesale'],
        'colleague' => ['label' => 'همکار',    'col' => 'price_colleague'],
    ];

    public static function levelsReady(): bool
    {
        return tableHasColumn('biz_products', 'price_wholesale');
    }

    /** ستونِ قیمتِ فروش برای یک مشتری (گذری/بی‌سطح ⇒ `sell_price`). */
    public static function priceColFor(int $userId, int $partyId): string
    {
        if ($partyId <= 0 || !self::levelsReady()) { return 'sell_price'; }
        $st = Database::getConnection()->prepare('SELECT price_level FROM biz_parties WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $partyId, 'u' => $userId]);
        return self::LEVELS[(string)$st->fetchColumn()]['col'] ?? 'sell_price';
    }

    /** قیمتِ عمده و همکارِ یک کالا (فرمِ کالا) — خالی = همان قیمتِ فروش. @return array{ok:bool, message:string} */
    public static function savePrices(int $userId, int $id, array $in): array
    {
        if (!self::levelsReady()) { return ['ok' => true, 'message' => '']; }
        $vals = [];
        foreach (['price_wholesale' => 'قیمتِ عمده', 'price_colleague' => 'قیمتِ همکار'] as $col => $lbl) {
            $raw = trim((string)($in[$col] ?? ''));
            // ⛔ `sanitizeAmount()` علامت را دور می‌ریزد؛ «−۵» نباید بی‌صدا «۵» شود
            if (preg_match('/^[-−‐]/u', $raw)) { return ['ok' => false, 'message' => $lbl . ' نمی‌تواند منفی باشد.']; }
            $v = $raw === '' ? null : sanitizeAmount($raw);
            if ($v !== null && ($e = BizCommon::moneyError($v, $lbl)) !== null) { return ['ok' => false, 'message' => $e]; }
            $vals[$col] = $v !== null && $v > 0 ? $v : null;
        }
        Database::getConnection()->prepare('UPDATE biz_products SET price_wholesale = :w, price_colleague = :c WHERE id = :id AND user_id = :u')
            ->execute(['w' => $vals['price_wholesale'], 'c' => $vals['price_colleague'], 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => ''];
    }

    /**
     * ⛔ تنها مرجعِ واحدها؛ مقدار یعنی «اعشار می‌پذیرد؟». عدد و بسته
     *    نصفه فروخته نمی‌شوند، کیلوگرم و متر می‌شوند.
     */
    public const UNITS = [
        'عدد'     => false,
        'دستگاه'  => false,
        'بسته'    => false,
        'جفت'     => false,
        'کارتن'   => false,
        'کیلوگرم' => true,
        'گرم'     => true,
        'متر'     => true,
        'لیتر'    => true,
    ];

    public const LIMITS = ['name' => 150, 'sku' => 60, 'category' => 80, 'note' => 300];

    /**
     * ⛔ تنها مرجعِ نوعِ کالا (فروشگاهِ موبایل و لوازم جانبی). نوع ستونِ
     *    جدایی ندارد و از دو ستون ساخته می‌شود — `typeOf()`:
     *    خدمت = `track_stock = 0`، گوشی = `has_serial = 1` (هر واحد IMEI
     *    دارد و جداگانه خرید و فروش می‌شود)، بقیه = کالا و لوازم جانبی.
     */
    public const TYPES = [
        'goods'   => 'کالا / لوازم جانبی',
        'phone'   => 'گوشی (با IMEI)',
        'service' => 'خدمت (تعمیر، نصب…)',
    ];

    /** نوعِ یک ردیفِ کالا — تنها جای خواندنِ این تصمیم از دو ستون. */
    public static function typeOf(array $p): string
    {
        if ((int)($p['track_stock'] ?? 1) !== 1) { return 'service'; }
        return (int)($p['has_serial'] ?? 0) === 1 ? 'phone' : 'goods';
    }

    /** صافی‌های فهرست — تنها مرجع؛ ناشناخته به «همه» برمی‌گردد. */
    public const FILTERS = [
        ''         => 'همه',
        'low'      => 'کم‌موجودی',
        'out'      => 'ناموجود',
        'inactive' => 'غیرفعال',
    ];

    public const PAGE_SIZE = 25;

    /**
     * ⛔ تنها شکلِ خواندنِ کالا — نامِ دسته از `biz_categories` می‌آید
     *    (`migration_biz_docs`)، نه از ستونِ متنیِ قدیمیِ `category` که از آن
     *    migration به بعد همیشه NULL است. ⚠ `c.name AS category` **بعد از**
     *    `p.*` است: در PDO ستونِ هم‌نامِ بعدی برنده است.
     */
    public const SELECT_SQL = 'SELECT p.*, c.name AS category FROM biz_products p LEFT JOIN biz_categories c ON c.id = p.category_id';

    /**
     * ⛔ تنها جای صافی‌های فهرستِ کالا — فهرستِ صفحه‌بندی‌شده و چاپ هر دو
     *    از همین می‌گذرند، وگرنه «کم‌موجودی»ِ روی صفحه با «کم‌موجودی»ِ کاغذ
     *    دو فهرستِ متفاوت می‌شد.
     * @return array{0:string, 1:array}
     */
    private static function where(int $userId, string $q, string $filter, string $category): array
    {
        $where  = ['p.user_id = :u'];
        $params = ['u' => $userId];
        switch (isset(self::FILTERS[$filter]) ? $filter : '') {
            case 'low':
                $where[] = 'p.is_active = 1 AND p.track_stock = 1 AND p.min_stock > 0 AND p.stock_qty <= p.min_stock';
                break;
            case 'out':
                $where[] = 'p.is_active = 1 AND p.track_stock = 1 AND p.stock_qty <= 0';
                break;
            case 'inactive':
                $where[] = 'p.is_active = 0';
                break;
            default:
                $where[] = 'p.is_active = 1';
        }
        if ($q !== '') {
            // ⛔ دو پارامترِ جدا، نه یک نامِ تکراری (EMULATE_PREPARES = false)
            $qi = (string)preg_replace('/[\s\x{200c}\-\/.]+/u', '', toLatinDigits(trim($q)));
            if (preg_match('/^\d{14,17}$/', $qi)) {
                // IMEI (همان قاعده‌ی `BizSerial::valid`) → مدلِ همان گوشی، از ردیف‌های اسناد
                $where[] = '(p.sku = :qs OR p.id IN (SELECT ql.product_id FROM biz_invoice_lines ql
                              WHERE ql.user_id = :qu AND (ql.imei1 = :qi1 OR ql.imei2 = :qi2)))';
                $params['qs'] = $params['qi1'] = $params['qi2'] = $qi;
                $params['qu'] = $userId;
            } else {
                // ⛔ نامِ ذخیره‌شده هم یکسان مقایسه می‌شود (REPLACE با رشته‌ی ثابت، پس
                //    «Illegal mix of collations» ممکن نیست): کالایی که پیش از
                //    `migration_biz_search` با «ي/ك» آمده هم پیدا می‌شود.
                $where[] = "(REPLACE(REPLACE(REPLACE(p.name, 'ي', 'ی'), 'ى', 'ی'), 'ك', 'ک') LIKE :q1 ESCAPE '!' OR p.sku LIKE :q2 ESCAPE '!')";
                $params['q1'] = BizCommon::like(BizCommon::persian($q));
                $params['q2'] = BizCommon::like(toLatinDigits($q));
            }
        }
        if ($category !== '') {
            $where[] = 'c.name = :c';
            $params['c'] = $category;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * همه‌ی ردیف‌ها بی‌صفحه‌بندی — برای چاپ. سقف دارد و می‌گوید بریده شده.
     * @return array{rows:array, capped:bool}
     */
    public static function all(int $userId, string $filter = '', string $category = '', int $cap = 3000): array
    {
        [$sqlWhere, $params] = self::where($userId, '', $filter, $category);
        $st = Database::getConnection()->prepare(
            self::SELECT_SQL . " WHERE {$sqlWhere} ORDER BY c.name IS NULL, c.name, p.name, p.id LIMIT :lim"
        );
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', $cap + 1, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        return ['rows' => array_slice($rows, 0, $cap), 'capped' => count($rows) > $cap];
    }

    /** @return array{rows:array, total:int, page:int, pages:int} */
    public static function list(int $userId, string $q = '', string $filter = '', string $category = '', int $page = 1): array
    {
        [$sqlWhere, $params] = self::where($userId, $q, $filter, $category);
        $pdo = Database::getConnection();

        $st = $pdo->prepare("SELECT COUNT(*) FROM biz_products p LEFT JOIN biz_categories c ON c.id = p.category_id WHERE {$sqlWhere}");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        [$page, $pages, $offset] = BizCommon::window($total, $page, self::PAGE_SIZE);

        $st = $pdo->prepare(
            self::SELECT_SQL . " WHERE {$sqlWhere}
             ORDER BY p.name, p.id LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', self::PAGE_SIZE, PDO::PARAM_INT);
        $st->bindValue('off', $offset, PDO::PARAM_INT);
        $st->execute();

        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** آیا این کالا در سندِ صادرشده یا حرکتِ انبار آمده است؟ */
    public static function hasHistory(int $userId, int $id): bool
    {
        $st = Database::getConnection()->prepare(
            "SELECT EXISTS(SELECT 1 FROM biz_stock_moves WHERE user_id = :u AND product_id = :p)
                 OR EXISTS(SELECT 1 FROM biz_invoice_lines l JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
                           WHERE l.user_id = :u2 AND l.product_id = :p2 AND i.status = 'issued')"
        );
        $st->execute(['u' => $userId, 'p' => $id, 'u2' => $userId, 'p2' => $id]);
        return (bool)$st->fetchColumn();
    }

    public static function get(int $userId, int $id): ?array
    {
        $st = Database::getConnection()->prepare(self::SELECT_SQL . ' WHERE p.id = :id AND p.user_id = :u LIMIT 1');
        $st->execute(['id' => $id, 'u' => $userId]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /** نامِ دسته‌ها — برای `<datalist>` و صافی؛ از همان `BizCategories`. */
    public static function categories(int $userId): array
    {
        return array_column(BizCategories::list($userId), 'name');
    }

    /** مقدار با قاعده‌ی واحد سازگار است؟ */
    public static function qtyFits(string $unit, float $qty): bool
    {
        return (self::UNITS[$unit] ?? false) || abs($qty - round($qty)) < 0.0005;
    }

    /**
     * ساخت یا ویرایش. کالای تازه می‌تواند موجودیِ اول دوره هم بگیرد
     * (`opening_qty`/`opening_cost`) — در همان تراکنش.
     *
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function save(int $userId, array $in, int $id = 0): array
    {
        $name     = BizCommon::line(BizCommon::persian((string)($in['name'] ?? '')));
        $sku      = BizCommon::line(toLatinDigits((string)($in['sku'] ?? '')));
        $category = BizCommon::line((string)($in['category'] ?? ''));
        $note     = trim((string)($in['note'] ?? ''));
        $unit     = (string)($in['unit'] ?? 'عدد');
        $buy      = sanitizeAmount($in['buy_price'] ?? '');
        $sell     = sanitizeAmount($in['sell_price'] ?? '');
        $minStock = sanitizeQty($in['min_stock'] ?? '');
        // نوع: فرمِ تازه `type` می‌فرستد؛ مسیرهای قدیمی (ورود از فایل) فقط
        // `is_service` — آن‌وقت «گوشی بودنِ» کالای موجود دست نمی‌خورد.
        $type     = isset($in['type']) ? (string)$in['type'] : null;
        if ($type !== null && !isset(self::TYPES[$type])) { return ['ok' => false, 'message' => 'نوعِ کالا معتبر نیست.']; }
        $track    = $type !== null ? ($type === 'service' ? 0 : 1) : (empty($in['is_service']) ? 1 : 0);
        $serial   = $type !== null ? ($type === 'phone' ? 1 : 0) : null;

        if ($name === '') { return ['ok' => false, 'message' => 'نامِ کالا الزامی است.']; }
        foreach (['name' => $name, 'sku' => $sku, 'category' => $category, 'note' => $note] as $k => $v) {
            if (mb_strlen($v) > self::LIMITS[$k]) {
                return ['ok' => false, 'message' => 'متنِ یکی از فیلدها بیش از ' . self::LIMITS[$k] . ' نویسه است.'];
            }
        }
        if (!isset(self::UNITS[$unit])) { return ['ok' => false, 'message' => 'واحدِ کالا معتبر نیست.']; }
        foreach (['قیمتِ خرید' => $buy, 'قیمتِ فروش' => $sell] as $lbl => $v) {
            if (($e = BizCommon::moneyError($v, $lbl)) !== null) { return ['ok' => false, 'message' => $e]; }
        }
        if (($e = BizCommon::qtyError($minStock, 'حداقلِ موجودی')) !== null) { return ['ok' => false, 'message' => $e]; }
        if (!self::qtyFits($unit, $minStock)) {
            return ['ok' => false, 'message' => 'حداقلِ موجودی برای واحدِ «' . $unit . '» باید عددِ صحیح باشد.'];
        }
        // ⛔ هر گوشی یک واحدِ کامل با IMEI خودش است؛ «۲٫۵ گوشی» معنا ندارد.
        if ($serial === 1 && self::UNITS[$unit]) {
            return ['ok' => false, 'message' => 'گوشی با واحدِ «' . $unit . '» ثبت نمی‌شود؛ «دستگاه» یا «عدد» را انتخاب کنید.'];
        }

        $openQty  = sanitizeQty($in['opening_qty'] ?? '');
        $openCost = trim((string)($in['opening_cost'] ?? '')) === '' ? $buy : sanitizeAmount($in['opening_cost']);
        if (($e = BizCommon::qtyError($openQty, 'موجودیِ اول دوره')) !== null
            || ($e = BizCommon::moneyError($openCost, 'بهای اول دوره')) !== null) {
            return ['ok' => false, 'message' => $e];
        }
        if ($id === 0 && $openQty > 0) {
            if (!$track) { return ['ok' => false, 'message' => 'خدمت موجودی ندارد؛ موجودیِ اول دوره را خالی بگذارید.']; }
            if (!self::qtyFits($unit, $openQty)) {
                return ['ok' => false, 'message' => 'موجودی برای واحدِ «' . $unit . '» باید عددِ صحیح باشد.'];
            }
        }

        $pdo = Database::getConnection();
        if ($id > 0) {
            $cur = self::get($userId, $id);
            if (!$cur) { return ['ok' => false, 'message' => 'کالا پیدا نشد.']; }
            // ⛔ کالایی که موجودی دارد خدمت نمی‌شود — موجودی‌اش بی‌صدا از
            //    ارزشِ انبار بیرون می‌افتاد.
            if (!$track && (float)$cur['stock_qty'] != 0.0) {
                return ['ok' => false, 'message' => 'این کالا موجودی دارد؛ اول موجودی را صفر کنید، بعد آن را خدمت کنید.'];
            }
            if (!self::qtyFits($unit, (float)$cur['stock_qty'])) {
                return ['ok' => false, 'message' => 'موجودیِ فعلی اعشاری است و با واحدِ «' . $unit . '» جور نیست.'];
            }
            // ⛔ کالا ↔ خدمت فقط برای کالای بی‌سابقه. گزارشِ سود «خریدِ بی‌انبار»
            //    را از همین پرچم می‌خواند، پس عوض کردنش سودِ ماه‌های گذشته — حتی
            //    دوره‌ی بسته — را بازنویسی می‌کرد (خرید هم بهای فروش می‌شد هم
            //    هزینه)، و ابطالِ فاکتورهایش دیگر ممکن نبود (بازرسیِ مهر ۱۴۰۵).
            if ((int)$cur['track_stock'] !== $track && self::hasHistory($userId, $id)) {
                return ['ok' => false, 'message' => 'این قلم در سندِ صادرشده یا حرکتِ انبار آمده؛ «کالا/خدمت» بودنش دیگر عوض نمی‌شود. برای نوعِ دیگر قلمِ تازه بسازید.'];
            }
            if ($serial === null) { $serial = (int)($cur['has_serial'] ?? 0) === 1 && $track === 1 ? 1 : 0; }
        }
        $serial = $track === 1 ? (int)$serial : 0;
        // ⛔ دوباره، بعد از معلوم شدنِ گوشی بودن: مسیرِ ورود از فایل `type`
        //    نمی‌فرستد و سنجشِ بالا را دور می‌زد — گوشیِ «کیلوگرمی» و «۲٫۵ گوشی».
        if ($serial === 1 && self::UNITS[$unit]) {
            return ['ok' => false, 'message' => 'گوشی با واحدِ «' . $unit . '» ثبت نمی‌شود؛ «دستگاه» یا «عدد» را انتخاب کنید.'];
        }

        // ⛔ دسته با نام می‌آید (فرم، ورود از فایل) و به شناسه تبدیل می‌شود؛
        //    نامِ تازه دسته‌ی تازه می‌سازد. ستونِ متنیِ قدیمی همیشه NULL.
        $catId = $category === '' ? null : BizCategories::resolve($userId, $category);
        $row = [
            'u' => $userId, 'n' => $name, 's' => $sku === '' ? null : $sku, 'c' => $catId,
            'un' => $unit, 'b' => $buy, 'sp' => $sell, 'm' => $minStock, 't' => $track, 'hs' => $serial,
            'no' => $note === '' ? null : $note,
        ];
        try {
            $pdo->beginTransaction();
            Biz::lockShop($pdo, $userId);
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE biz_products SET name = :n, sku = :s, category_id = :c, category = NULL, unit = :un, buy_price = :b,
                            sell_price = :sp, min_stock = :m, track_stock = :t, has_serial = :hs, note = :no
                     WHERE id = :id AND user_id = :u'
                )->execute($row + ['id' => $id]);
            } else {
                $pdo->prepare(
                    'INSERT INTO biz_products (user_id, name, sku, category_id, unit, buy_price, sell_price, min_stock, track_stock, has_serial, note)
                     VALUES (:u, :n, :s, :c, :un, :b, :sp, :m, :t, :hs, :no)'
                )->execute($row);
                $id = (int)$pdo->lastInsertId();
                if ($openQty > 0) {
                    $res = BizStock::setOpening($userId, $id, $openQty, $openCost, false);
                    if (!$res['ok']) { $pdo->rollBack(); return $res; }
                }
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ((string)$e->getCode() === '23000') {
                return ['ok' => false, 'message' => 'کالای دیگری با همین کد ثبت شده است.'];
            }
            throw $e;
        }
        return ['ok' => true, 'message' => 'کالا ذخیره شد.', 'id' => $id];
    }

    /**
     * قیمتِ فروش از نرخِ روز (`includes/rates.php`): پایه به واحدِ نرخ (دلار،
     * گرمِ طلا، سکه…) + درصدِ سود. کدِ خالی = قطع؛ قیمتِ فروش همان آخرین
     * عدد می‌ماند و از این به بعد دستی است.
     * ⛔ جدا از `save()` است، عمداً: ورود از فایل و هر مسیرِ دیگری که `save()`
     *    را صدا می‌زند این ستون‌ها را نمی‌فرستد و نباید وصلِ کالا را بی‌صدا قطع کند.
     * @return array{ok:bool, message:string}
     */
    /**
     * معافیت از مالیات بر ارزش افزوده و شناسه‌ی کالا/خدمتِ مودیان. ⛔ جدا از
     * `save()` (همان دلیلِ `saveRate()`): ورود از فایل این‌ها را نمی‌فرستد و
     * نباید پاکشان کند. معافیت فقط روی فاکتورِ **تازه** اثر دارد — هر سند
     * مالیاتِ خودش را نگه داشته است.
     */
    public static function saveTax(int $userId, int $id, array $in): array
    {
        if (!Biz::accReady()) { return ['ok' => true, 'message' => '']; }
        $code = trim(toLatinDigits((string)($in['tax_code'] ?? '')));
        if ($code !== '' && !preg_match('/^\d{13}$/', $code)) { return ['ok' => false, 'message' => 'شناسه‌ی کالا/خدمت سیزده رقم است.']; }
        Database::getConnection()->prepare('UPDATE biz_products SET vat_exempt = :x, tax_code = :c WHERE id = :id AND user_id = :u')
            ->execute(['x' => !empty($in['vat_exempt']) ? 1 : 0, 'c' => $code === '' ? null : $code, 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => ''];
    }

    public static function saveRate(int $userId, int $id, array $in): array
    {
        require_once __DIR__ . '/biz_rates.php';
        if (!tableHasColumn('biz_products', 'rate_code')) { return ['ok' => true, 'message' => '']; }
        $code = trim((string)($in['rate_code'] ?? ''));
        $pdo = Database::getConnection();
        if ($code === '') {
            $pdo->prepare('UPDATE biz_products SET rate_code = NULL, rate_base = NULL, rate_margin = NULL WHERE id = :id AND user_id = :u')
                ->execute(['id' => $id, 'u' => $userId]);
            return ['ok' => true, 'message' => ''];
        }
        if (!Rates::isCode($code)) { return ['ok' => false, 'message' => 'نرخِ انتخاب‌شده معتبر نیست.']; }
        $base = Rates::num((string)($in['rate_base'] ?? ''));
        if ($base === null || $base > 1e9) {
            return ['ok' => false, 'message' => 'قیمتِ پایه به ' . Rates::unit($code) . ' را بنویسید (بیشتر از صفر).'];
        }
        $mRaw = trim(toLatinDigits((string)($in['rate_margin'] ?? '')));
        $neg = str_starts_with($mRaw, '-') || str_starts_with($mRaw, '−');
        $margin = $mRaw === '' ? 0.0 : (float)(Rates::num(ltrim($mRaw, '-−')) ?? 0) * ($neg ? -1 : 1);
        if ($margin < -90 || $margin > 1000) { return ['ok' => false, 'message' => 'درصدِ سود باید بین −۹۰ و ۱۰۰۰ باشد.']; }
        $pdo->prepare('UPDATE biz_products SET rate_code = :c, rate_base = :b, rate_margin = :m WHERE id = :id AND user_id = :u')
            ->execute(['c' => $code, 'b' => round($base, 4), 'm' => round($margin, 2), 'id' => $id, 'u' => $userId]);
        BizRates::apply($userId);
        return ['ok' => true, 'message' => Rates::price($code) === null
            ? 'هنوز نرخی برای «' . Rates::label($code) . '» نیامده؛ قیمتِ فروش با اولین نرخ حساب می‌شود.' : ''];
    }

    /**
     * ⛔ کالای **موجوددار** غیرفعال نمی‌شود: ارزشِ انبار و داشبورد فقط کالای
     *    فعال را می‌شمارند، پس موجودی‌اش بی‌صدا از دارایی بیرون می‌افتاد
     *    (بازرسیِ مهر ۱۴۰۵). اول با انبارگردانی صفرش کنید.
     * @return array{ok:bool, message:string}
     */
    public static function setActive(int $userId, int $id, bool $active): array
    {
        $cur = self::get($userId, $id);
        if (!$cur) { return ['ok' => false, 'message' => 'کالا پیدا نشد.']; }
        if (!$active && abs((float)$cur['stock_qty']) > 0.0005) {
            return ['ok' => false, 'message' => 'این کالا ' . formatQty((float)$cur['stock_qty']) . ' ' . $cur['unit']
                . ' موجودی دارد؛ غیرفعال کردنش آن را از ارزشِ انبار بیرون می‌برد. اول موجودی را با انبارگردانی صفر کنید.'];
        }
        $st = Database::getConnection()->prepare('UPDATE biz_products SET is_active = :a WHERE id = :id AND user_id = :u');
        $st->execute(['a' => $active ? 1 : 0, 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => $active ? 'کالا فعال شد.' : 'کالا غیرفعال شد؛ در فهرستِ «غیرفعال» می‌ماند.'];
    }

    /**
     * حذف — فقط وقتی هیچ حرکتی به سندی (فاکتور) بند نیست. امروز فاکتوری
     * نیست، پس هر کالایی حذف‌شدنی است؛ از مرحله‌ی ۳، کالای فروخته‌شده
     * فقط غیرفعال می‌شود وگرنه فاکتورِ قدیمی به هیچ‌جا اشاره می‌کرد.
     *
     * @return array{ok:bool, message:string}
     */
    public static function delete(int $userId, int $id): array
    {
        $pdo = Database::getConnection();
        // ⛔ ردیفِ فاکتور — حتی پیش‌نویس — به کالا اشاره می‌کند (کلیدِ خارجیِ
        //    RESTRICT)؛ حذفش فاکتور را بی‌کالا می‌کرد.
        // ⛔ و کالایی که **انبارگردانی** دارد هم: کسریِ شمارش هزینه‌ی واقعی است
        //    و در سودِ همان روز نشسته؛ با حذف (CASCADE) آن زیان از سودِ گذشته —
        //    حتی دوره‌ی بسته — پاک می‌شد (بازرسیِ مهر ۱۴۰۵). همان قاعده در `UNUSED_SQL`.
        $st  = $pdo->prepare("SELECT (SELECT COUNT(*) FROM biz_stock_moves WHERE product_id = :id AND user_id = :u AND (ref_type IS NOT NULL OR kind = 'adjust'))
                                   + (SELECT COUNT(*) FROM biz_invoice_lines WHERE product_id = :id2 AND user_id = :u2)");
        $st->execute(['id' => $id, 'u' => $userId, 'id2' => $id, 'u2' => $userId]);
        if ((int)$st->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'این کالا در فاکتور یا انبارگردانی آمده و حذف نمی‌شود؛ غیرفعالش کنید.'];
        }
        // ⛔ موجودیِ اول دوره‌ای که در دوره‌ی بسته نشسته با حذف (CASCADE) پاک می‌شد — همان
        //    سدِ `setOpening()` (بازرسیِ مهر ۱۴۰۵: ارزشِ انبار در ترازِ روزِ قفل ۱۰٬۰۰۰ → ۰).
        $om = $pdo->prepare('SELECT MIN(move_date) FROM biz_stock_moves WHERE product_id = :id AND user_id = :u');
        $om->execute(['id' => $id, 'u' => $userId]);
        $first = (string)$om->fetchColumn();
        if ($first !== '' && ($e = Biz::lockError($userId, $first, 'موجودیِ اول دوره‌ی این کالا')) !== null) {
            return ['ok' => false, 'message' => $e];
        }
        $st = $pdo->prepare('DELETE FROM biz_products WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'کالا حذف شد.']
            : ['ok' => false, 'message' => 'کالا پیدا نشد.'];
    }

    /* ------------------------------------------------------------
       پاک‌سازیِ دسته‌جمعیِ کالاهای استفاده‌نشده
       ------------------------------------------------------------ */

    /**
     * ⛔ تنها تعریفِ «استفاده‌نشده» — همان دو شرطِ `delete()`: هیچ ردیفِ
     *    سندی (حتی پیش‌نویس؛ کلیدِ خارجیِ RESTRICT) و هیچ حرکتِ انبارِ
     *    سندداری. موجودیِ اول دوره و انبارگردانی (`ref_type IS NULL`) مانع
     *    نیستند و با CASCADE همراهِ کالا می‌روند.
     *    ⚠ شرطِ دوم امروز افزونه است (حرکتِ سندداری فقط از ردیفِ سند می‌آید و
     *    سندِ صادرشده حذف نمی‌شود) و جهشِ برداشتنش زنده می‌ماند — همان شرطِ
     *    `delete()` است و نگه داشته شد تا دو تعریف از هم دور نیفتند.
     *    ⚠ روی ستون‌های `p` و با پارامترهای `:uu1`/`:uu2` — پارامترِ جدا،
     *    نه یک `:u` تکراری (EMULATE_PREPARES = false).
     */
    private const UNUSED_SQL = 'NOT EXISTS (SELECT 1 FROM biz_invoice_lines ul WHERE ul.product_id = p.id AND ul.user_id = :uu1)
        AND NOT EXISTS (SELECT 1 FROM biz_stock_moves um WHERE um.product_id = p.id AND um.user_id = :uu2
                        AND (um.ref_type IS NOT NULL OR um.kind = \'adjust\'))';

    /** دامنه‌ی پاک‌سازی — `empty`: فقط بی‌موجودی (پیش‌فرض)، `all`: با موجودیِ اول دوره هم. */
    public const CLEANUP_SCOPES = [
        'empty' => 'فقط کالاهای بی‌موجودی',
        'all'   => 'همه، حتی با موجودیِ اول دوره',
    ];

    /** سقفِ ردیف‌های فهرستِ انتخابی — زیرِ `max_input_vars`ِ پیش‌فرضِ PHP (۱۰۰۰). */
    public const CLEANUP_LIST_MAX = 300;

    /** @return array{0:string, 1:array} */
    private static function unusedWhere(int $userId, string $scope, string $category): array
    {
        $where  = ['p.user_id = :u', self::UNUSED_SQL];
        $params = ['u' => $userId, 'uu1' => $userId, 'uu2' => $userId];
        // ⛔ کالایی که حرکتِ اول دوره‌اش در دوره‌ی بسته است پاک نمی‌شود (همان سدِ `delete()`)
        if (($lock = Biz::lockDate($userId)) !== null) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM biz_stock_moves lm WHERE lm.product_id = p.id AND lm.user_id = :uu3 AND lm.move_date <= :lk)';
            $params['uu3'] = $userId;
            $params['lk']  = $lock;
        }
        if (($scope === '' ? 'empty' : $scope) !== 'all') {
            // ⚠ خدمت موجودی ندارد، پس همیشه «بی‌موجودی» است
            $where[] = '(p.track_stock = 0 OR p.stock_qty = 0)';
        }
        if ($category !== '') {
            $where[] = 'c.name = :c';
            $params['c'] = $category;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * کالاهای استفاده‌نشده — فهرستِ صفحه‌ی پاک‌سازی، با سقف و شمارِ کل
     * (فهرستِ بریده صریح گفته می‌شود).
     * @return array{rows:array, total:int, capped:bool}
     */
    public static function unused(int $userId, string $scope = 'empty', string $category = ''): array
    {
        [$w, $params] = self::unusedWhere($userId, $scope, $category);
        $pdo = Database::getConnection();
        $st = $pdo->prepare(self::SELECT_SQL . " WHERE {$w} ORDER BY p.name, p.id LIMIT :lim");
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', self::CLEANUP_LIST_MAX + 1, PDO::PARAM_INT);
        $st->execute();
        $rows   = $st->fetchAll();
        $capped = count($rows) > self::CLEANUP_LIST_MAX;
        $total  = count($rows);
        if ($capped) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM biz_products p LEFT JOIN biz_categories c ON c.id = p.category_id WHERE {$w}");
            $st->execute($params);
            $total = (int)$st->fetchColumn();
        }
        return ['rows' => array_slice($rows, 0, self::CLEANUP_LIST_MAX), 'total' => $total, 'capped' => $capped];
    }

    /**
     * حذفِ دسته‌جمعی. `$ids` = null یعنی «همه‌ی استفاده‌نشده‌های همین دامنه»
     * (فهرستِ بلندتر از سقف هم پاک می‌شود)؛ آرایه یعنی فقط همین شناسه‌ها.
     *
     * ⛔ شرطِ «استفاده‌نشده» روی خودِ `DELETE` است، نه فقط در فهرستی که
     *    کاربر دیده: بینِ دیدنِ صفحه و زدنِ دکمه ممکن است کالایی در
     *    فاکتور آمده باشد، و شناسه‌ها از فرم می‌آیند. کالای کاربرِ دیگر و
     *    کالای استفاده‌شده «رد شده» شمرده می‌شوند، نه حذف.
     * @param int[]|null $ids
     * @return array{ok:bool, message:string, deleted:int, skipped:int}
     */
    public static function deleteUnused(int $userId, ?array $ids, string $scope = 'empty', string $category = ''): array
    {
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
            if (!$ids) {
                return ['ok' => false, 'message' => 'هیچ کالایی انتخاب نشده است.', 'deleted' => 0, 'skipped' => 0];
            }
            // انتخابِ دستی به دامنه بند نیست: کاربر خودش کالای با موجودی را تیک زده
            [$w, $params] = self::unusedWhere($userId, 'all', '');
            $in = [];
            foreach ($ids as $n => $id) { $in[] = ':id' . $n; $params['id' . $n] = $id; }
            $w .= ' AND p.id IN (' . implode(',', $in) . ')';
        } else {
            [$w, $params] = self::unusedWhere($userId, $scope, $category);
        }
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        Biz::lockShop($pdo, $userId);
        try {
            // ⚠ MariaDB در DELETE با زیرکوئری روی جدولِ دیگر مشکلی ندارد؛
            //   JOIN به دسته فقط برای صافیِ نام.
            $st = $pdo->prepare("DELETE p FROM biz_products p LEFT JOIN biz_categories c ON c.id = p.category_id WHERE {$w}");
            $st->execute($params);
            $deleted = $st->rowCount();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Log::error('biz.products_cleanup', $e);
            return ['ok' => false, 'message' => 'پاک‌سازی انجام نشد.', 'deleted' => 0, 'skipped' => 0];
        }
        $skipped = $ids !== null ? count($ids) - $deleted : 0;
        if ($deleted === 0) {
            return ['ok' => false, 'message' => 'کالای استفاده‌نشده‌ای برای حذف نبود.', 'deleted' => 0, 'skipped' => $skipped];
        }
        $msg = toPersianDigits((string)$deleted) . ' کالای استفاده‌نشده حذف شد.';
        if ($skipped > 0) {
            $msg .= ' ' . toPersianDigits((string)$skipped) . ' کالا در این فاصله در سندی آمده بود یا پیدا نشد و ماند.';
        }
        return ['ok' => true, 'message' => $msg, 'deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * خلاصه‌ی داشبورد — یک کوئری. ارزشِ انبار = موجودی × میانگینِ بها،
     * فقط کالاهای فعال و انبارشدنی.
     *
     * @return array{count:int, value:int, low:int, out:int}
     */
    public static function summary(int $userId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(CASE WHEN track_stock = 1 AND stock_qty > 0 THEN stock_qty * avg_cost ELSE 0 END), 0) AS val,
                    COALESCE(SUM(track_stock = 1 AND min_stock > 0 AND stock_qty <= min_stock), 0) AS low,
                    COALESCE(SUM(track_stock = 1 AND stock_qty <= 0), 0) AS outq
             FROM biz_products WHERE user_id = :u AND is_active = 1'
        );
        $st->execute(['u' => $userId]);
        $r = $st->fetch() ?: [];
        return [
            'count' => (int)($r['cnt'] ?? 0),
            'value' => (int)round((float)($r['val'] ?? 0)),
            'low'   => (int)($r['low'] ?? 0),
            'out'   => (int)($r['outq'] ?? 0),
        ];
    }

    /** کالاهای کم‌موجودی — برای کارتِ داشبورد. */
    public static function lowStock(int $userId, int $limit = 6): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT id, name, unit, stock_qty, min_stock FROM biz_products
             WHERE user_id = :u AND is_active = 1 AND track_stock = 1
               AND (stock_qty <= 0 OR (min_stock > 0 AND stock_qty <= min_stock))
             ORDER BY (stock_qty <= 0) DESC, stock_qty / GREATEST(min_stock, 1), name
             LIMIT :lim'
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** وضعیتِ موجودیِ یک ردیف: `ok` / `low` / `out` / `service`. */
    public static function stockState(array $p): string
    {
        if ((int)$p['track_stock'] !== 1) { return 'service'; }
        $q = (float)$p['stock_qty'];
        if ($q <= 0) { return 'out'; }
        if ((float)$p['min_stock'] > 0 && $q <= (float)$p['min_stock']) { return 'low'; }
        return 'ok';
    }
}

/* =================================================================
   دسته‌بندیِ کالا (migration_biz_docs)
   ================================================================= */
final class BizCategories
{
    public const NAME_MAX = 80;

    /** کشِ همین درخواست — فرمِ کالا، صافی و datalist یک فهرست را می‌خوانند. */
    private static array $cache = [];

    /** @return array<int,array{id:int,name:string,sort_order:int,products:int}> */
    public static function list(int $userId): array
    {
        if (isset(self::$cache[$userId])) { return self::$cache[$userId]; }
        $st = Database::getConnection()->prepare(
            'SELECT c.id, c.name, c.sort_order,
                    (SELECT COUNT(*) FROM biz_products p WHERE p.category_id = c.id AND p.user_id = c.user_id AND p.is_active = 1) AS products
             FROM biz_categories c WHERE c.user_id = :u ORDER BY c.sort_order, c.name'
        );
        $st->execute(['u' => $userId]);
        return self::$cache[$userId] = $st->fetchAll();
    }

    public static function forget(int $userId): void
    {
        unset(self::$cache[$userId]);
    }

    /**
     * نام → شناسه؛ نامِ تازه ساخته می‌شود. ⚠ مقایسه با پارامترِ bind‌شده
     * است، نه ستون‌به‌ستون، پس collation ستون را می‌گیرد («ی/ي» و فاصله‌ی
     * اضافه را `line()` یکی می‌کند).
     */
    public static function resolve(int $userId, string $name): ?int
    {
        $name = mb_substr(BizCommon::line(str_replace(['ي', 'ك'], ['ی', 'ک'], $name)), 0, self::NAME_MAX);
        if ($name === '') { return null; }
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT id FROM biz_categories WHERE user_id = :u AND name = :n LIMIT 1');
        $st->execute(['u' => $userId, 'n' => $name]);
        $id = $st->fetchColumn();
        if ($id !== false) { return (int)$id; }
        $pdo->prepare('INSERT INTO biz_categories (user_id, name) VALUES (:u, :n)')->execute(['u' => $userId, 'n' => $name]);
        self::forget($userId);
        return (int)$pdo->lastInsertId();
    }

    /** ساخت یا تغییرِ نام. @return array{ok:bool, message:string, id?:int} */
    public static function save(int $userId, string $name, int $id = 0): array
    {
        $name = BizCommon::line(str_replace(['ي', 'ك'], ['ی', 'ک'], $name));
        if ($name === '') { return ['ok' => false, 'message' => 'نامِ دسته الزامی است.']; }
        if (mb_strlen($name) > self::NAME_MAX) { return ['ok' => false, 'message' => 'نامِ دسته بیش از ' . self::NAME_MAX . ' نویسه است.']; }
        $pdo = Database::getConnection();
        try {
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE biz_categories SET name = :n WHERE id = :id AND user_id = :u');
                $st->execute(['n' => $name, 'id' => $id, 'u' => $userId]);
                $chk = $pdo->prepare('SELECT COUNT(*) FROM biz_categories WHERE id = :id AND user_id = :u');
                $chk->execute(['id' => $id, 'u' => $userId]);
                if ((int)$chk->fetchColumn() === 0) { return ['ok' => false, 'message' => 'دسته پیدا نشد.']; }
            } else {
                $pdo->prepare('INSERT INTO biz_categories (user_id, name) VALUES (:u, :n)')->execute(['u' => $userId, 'n' => $name]);
                $id = (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') { return ['ok' => false, 'message' => 'دسته‌ای با همین نام هست.']; }
            throw $e;
        }
        self::forget($userId);
        return ['ok' => true, 'message' => 'دسته ذخیره شد.', 'id' => $id];
    }

    /**
     * حذف — کالاهایش **بی‌دسته** می‌شوند (کلیدِ خارجیِ `SET NULL`)، نه حذف.
     * پیام تعدادشان را می‌گوید تا کسی فکر نکند کالاها رفتند.
     * @return array{ok:bool, message:string}
     */
    public static function delete(int $userId, int $id): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT COUNT(*) FROM biz_products WHERE category_id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        $n = (int)$st->fetchColumn();
        $st = $pdo->prepare('DELETE FROM biz_categories WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        self::forget($userId);
        if ($st->rowCount() === 0) { return ['ok' => false, 'message' => 'دسته پیدا نشد.']; }
        return ['ok' => true, 'message' => $n > 0
            ? 'دسته حذف شد و ' . toPersianDigits((string)$n) . ' کالایش بی‌دسته شد.'
            : 'دسته حذف شد.'];
    }
}

/* =================================================================
   حرکتِ انبار
   ================================================================= */
final class BizStock
{
    /** برچسبِ نوعِ حرکت؛ نوع‌های فاکتور با مرحله‌ی ۳ اضافه می‌شوند. */
    public const KINDS = [
        'opening'         => 'موجودی اول دوره',
        'adjust'          => 'انبارگردانی',
        'sale'            => 'فروش',
        'purchase'        => 'خرید',
        'sale_return'     => 'برگشت از فروش',
        'purchase_return' => 'برگشت از خرید',
    ];

    /** نوعِ ارجاعِ حرکت‌های سند — تنها مقدار؛ `BizInvoices` هم همین را می‌پرسد. */
    public const REF_INVOICE = 'invoice';

    /** خطای گردِ اعشار — «منفی» یعنی کمتر از این. */
    private const EPS = 0.0005;

    /**
     * ⛔ تنها نویسنده‌ی `stock_qty` و `avg_cost` — و تنها نویسنده‌ی بهای
     *    تمام‌شده‌ی **ردیف‌های فروش و برگشت از فروش** (`unit_cost`ِ حرکت و ردیف).
     *
     * حرکت‌ها به ترتیبِ زمان پیموده می‌شوند (موجودیِ اول دوره همیشه اول) و
     * **ارزشِ انبار** (`v`) کنارِ موجودی (`q`) نگه داشته می‌شود؛ میانگین
     * همیشه `v ÷ q` است:
     *   - ورود با بهای خودش (خرید: خالصِ ردیف با سهمِ حمل/تخفیف؛ بی‌بها:
     *     میانگینِ جاری). برگشت از فروش با **بهای ردیفِ فروشِ اصلی** — همان
     *     که در همین پیمایش برایش حساب شد.
     *   - فروش به **میانگینِ همان نقطه از زمان**، نه میانگینِ امروز؛ و گوشیِ
     *     IMEIدار به **بهای خریدِ همان گوشی** (شناساییِ ویژه — گوشیِ کارکرده
     *     هر کدام قیمتِ خودش را دارد). بی‌سابقه‌ی IMEI (اول دوره) ← میانگین.
     *   - برگشت از خرید به **بهای ردیفِ خریدِ اصلی** بیرون می‌رود و میانگینِ
     *     بقیه را جابه‌جا می‌کند — پولی که از فروشنده برمی‌گردد همان است که
     *     داده شد، پس انبار هم دقیقاً همان را از دست می‌دهد.
     *   - انبارگردانی (کم یا زیاد) به میانگینِ جاری.
     *   - موجودیِ صفر یعنی ارزشِ صفر؛ ورودِ بعدی میانگینِ تازه می‌سازد.
     *
     * ⛔ چرا بهای فروش اینجا **دوباره** ساخته می‌شود و نه یک بار هنگامِ صدور:
     *    فاکتورِ تاریخ‌گذشته (خریدی که دیرتر ثبت شد، فروشی با تاریخِ دیروز) یا
     *    اصلاحِ بهای اول دوره بعد از فروش، بهای فروش‌های **بعد از آن** را عوض
     *    می‌کند. بهای منجمد یعنی سودِ غلط و انباری که با جمعِ خرید نمی‌خواند
     *    (ورودی ≠ بهای فروش + ارزشِ مانده). هر حرکتِ تازه همین پیمایش را
     *    دارد، پس زنجیره همیشه درست است — `test_store_cost` همین برابری را
     *    می‌سنجد. فقط ردیفی که عددش واقعاً عوض شده نوشته می‌شود.
     *
     * کمینه‌ی موجودیِ پیموده‌شده هم برگردانده می‌شود تا فراخواننده «منفی شدن
     * در هر لحظه» را رد کند، نه فقط در پایان.
     *
     * @return array{qty:float, avg:float, min:float, value:float}
     */
    public static function recalc(int $userId, int $productId): array
    {
        $pdo = Database::getConnection();
        $st  = $pdo->prepare(
            "SELECT id, kind, qty, unit_cost, ref_type, ref_id, move_date, created_at FROM biz_stock_moves
             WHERE product_id = :p AND user_id = :u
             ORDER BY (kind = 'opening') DESC, move_date, id
             FOR UPDATE"
        );
        // ⛔ خواندنِ **قفل‌دار** (`FOR UPDATE`)، نه عادی: تنها فراخواننده `write()`
        //    است که ردیفِ کالا را قفل کرده، ولی عکسِ تراکنش (REPEATABLE READ) از
        //    **اولین** خواندنِ تراکنش گرفته شده — پیش از انتظار برای همان قفل. پس
        //    خواندنِ عادی حرکتی را که صدورِ هم‌زمانِ دیگری همین حالا کامیت کرده
        //    نمی‌دید: فروش و ابطالِ خرید هم‌زمان هر دو می‌گذشتند و موجودی منفی
        //    می‌ماند (بازرسیِ مهر ۱۴۰۵، بازتولید شد). خواندنِ قفل‌دار همیشه آخرین
        //    کامیت را می‌بیند.
        $st->execute(['p' => $productId, 'u' => $userId]);
        $moves = self::inDocOrder($pdo, $userId, $st->fetchAll());
        $lines = self::docLines($pdo, $userId, $productId, $moves);

        $q = 0.0; $v = 0.0; $avg = 0.0; $min = 0.0;
        $taken = []; $lineCost = []; $fixMove = []; $fixLine = [];
        // گوشی‌های IMEIدارِ در انبار: یک کلید برای هر دستگاه (IMEI ۱ و ۲ یک
        // گوشی‌اند)، با بها. جمع و شمارشان جدا نگه داشته می‌شود تا گوشیِ بی‌سابقه
        // (اول دوره) از «بقیه‌ی انبار» بها بگیرد، نه از میانگینی که گوشی‌های
        // گرانِ IMEIدار را هم دارد.
        $unitOf = []; $unitCost = []; $kSum = 0.0;
        foreach ($moves as $m) {
            $qty  = (float)$m['qty'];
            $kind = (string)$m['kind'];
            // ردیفِ سندِ همین حرکت: k-امین حرکتِ یک سند برای این کالا همان
            // k-امین ردیفِ آن است (`postDoc()` به ترتیبِ ردیف می‌نویسد)
            $line = null;
            if ($m['ref_type'] === self::REF_INVOICE && isset($lines[(int)$m['ref_id']])) {
                $rid  = (int)$m['ref_id'];
                $k    = $taken[$rid] = ($taken[$rid] ?? -1) + 1;
                $line = $lines[$rid][$k] ?? null;
            }
            $imeis = $line !== null ? array_values(array_filter([$line['imei1'], $line['imei2']], fn($x) => $x !== null && $x !== '')) : [];
            $unit  = null;
            foreach ($imeis as $im) { if (isset($unitOf[$im], $unitCost[$unitOf[$im]])) { $unit = $unitOf[$im]; break; } }

            if ($qty > 0) {
                $cost = $m['unit_cost'] !== null && $kind !== 'adjust' ? (float)$m['unit_cost'] : $avg;
                if ($kind === 'adjust') { self::fix($fixMove, $fixLine, $m, null, (int)round($cost)); }
                if ($kind === 'sale_return' && $line !== null && $line['ref_line_id'] !== null
                    && isset($lineCost[(int)$line['ref_line_id']])) {
                    $cost = (float)$lineCost[(int)$line['ref_line_id']];
                    self::fix($fixMove, $fixLine, $m, $line, (int)round($cost));
                }
                $base = max($q, 0.0);
                $v    = ($base > 0 ? max($v, 0.0) : 0.0) + $qty * $cost;
                $avg  = $v / ($base + $qty);
                if ($line !== null) { $lineCost[(int)$line['id']] = (int)round($cost); }
                if ($imeis && $unit === null) {
                    $unit = $imeis[0];
                    foreach ($imeis as $im) { $unitOf[$im] = $unit; }
                    $unitCost[$unit] = $cost; $kSum += $cost;
                }
            } else {
                $out = $avg;
                if ($unit !== null) {
                    $out = $unitCost[$unit];                    // ⛔ شناساییِ ویژه: همان گوشی
                } else {
                    // بی‌سابقه (گوشیِ اول دوره، یا کسریِ انبارگردانی): از «بقیه‌ی انبار»،
                    // منهای گوشی‌های IMEIدار — وگرنه کسریِ یک گوشیِ ارزان از ارزشِ
                    // گوشی‌های گران کم می‌شد
                    $rest = $q - count($unitCost);
                    if ($unitCost && $rest > self::EPS) { $out = max($v - $kSum, 0.0) / $rest; }
                }
                if ($kind === 'purchase_return' && $m['unit_cost'] !== null) {
                    $out = (float)$m['unit_cost'];              // همان بهای خریدِ اصلی
                }
                if ($kind === 'sale') {
                    $c = (int)round($out);
                    if ($line !== null) { $lineCost[(int)$line['id']] = $c; }
                    self::fix($fixMove, $fixLine, $m, $line, $c);
                } elseif ($kind === 'adjust') {
                    self::fix($fixMove, $fixLine, $m, null, (int)round($out));   // ارزشِ کسری — برای سود و زیان
                }
                if ($unit !== null) { $kSum -= $unitCost[$unit]; unset($unitCost[$unit]); }
                $v += $qty * $out;
            }
            $q   = round($q + $qty, 3);
            $min = min($min, $q);
            if ($q > self::EPS) {
                if ($qty < 0) { $v = max($v, 0.0); $avg = $v / $q; }
            } else {
                // بی‌موجودی = بی‌ارزش (ورودِ بعدی با `$base = 0` ارزشِ مانده را
                // دور می‌ریزد)؛ میانگین فقط برای نمایش می‌ماند، و هیچ گوشی‌ای در انبار نیست
                $unitCost = []; $kSum = 0.0;
            }
        }
        $avg = round($avg, 2);

        $pdo->prepare('UPDATE biz_products SET stock_qty = :q, avg_cost = :a WHERE id = :p AND user_id = :u')
            ->execute(['q' => $q, 'a' => $avg, 'p' => $productId, 'u' => $userId]);
        if ($fixMove) {
            $um = $pdo->prepare('UPDATE biz_stock_moves SET unit_cost = :c WHERE id = :id AND user_id = :u');
            foreach ($fixMove as $id => $c) { $um->execute(['c' => $c, 'id' => $id, 'u' => $userId]); }
        }
        if ($fixLine) {
            $ul = $pdo->prepare('UPDATE biz_invoice_lines SET unit_cost = :c WHERE id = :id AND user_id = :u');
            foreach ($fixLine as $id => $c) { $ul->execute(['c' => $c, 'id' => $id, 'u' => $userId]); }
        }
        self::$fixedLines = count($fixLine);

        return ['qty' => $q, 'avg' => $avg, 'min' => $min, 'value' => $q > self::EPS ? round($v, 2) : 0.0];
    }

    /**
     * پیمایشِ دوباره‌ی همه‌ی کالاهای دارای موجودیِ یک فروشگاه — هر کدام از
     * همان `write()` (قفل، `recalc()`، سدِ منفی) با تغییرِ تهی. برای داده‌ای
     * که پیش از «بهای تمام‌شده‌ی زنجیره‌ای» صادر شده: بهای فروش‌های
     * تاریخ‌گذشته، ارزشِ برگشت از خرید و ارزشِ انبارگردانی‌ها درست می‌شوند.
     * چیزی جز بها عوض نمی‌شود (نه موجودی، نه مبلغِ فاکتور، نه مانده).
     *
     * @return array{products:int, lines:int, failed:list<string>}
     */
    public static function rebuild(int $userId): array
    {
        $st = Database::getConnection()->prepare('SELECT id, name FROM biz_products WHERE user_id = :u AND track_stock = 1 ORDER BY id');
        $st->execute(['u' => $userId]);
        $rows = $st->fetchAll();
        $lines = 0; $failed = [];
        foreach ($rows as $p) {
            self::$fixedLines = 0;
            $r = self::write($userId, (int)$p['id'], fn(): ?string => null);
            if ($r['ok']) { $lines += self::$fixedLines; } else { $failed[] = (string)$p['name'] . ': ' . $r['message']; }
        }
        return ['products' => count($rows), 'lines' => $lines, 'failed' => $failed];
    }

    /** شمارِ ردیف‌های سندی که آخرین `recalc()` بهایشان را عوض کرد (برای `rebuild()`). */
    private static int $fixedLines = 0;

    /** بهای تازه‌ی یک حرکت و ردیفش — فقط اگر واقعاً عوض شده. */
    private static function fix(array &$fixMove, array &$fixLine, array $m, ?array $line, int $c): void
    {
        if ($m['unit_cost'] === null || (int)$m['unit_cost'] !== $c) { $fixMove[(int)$m['id']] = $c; }
        if ($line !== null && ($line['unit_cost'] === null || (int)$line['unit_cost'] !== $c)) { $fixLine[(int)$line['id']] = $c; }
    }

    /**
     * ⛔ ترتیبِ حرکت‌های **یک روز**: جای سند در تاریخچه (اولین صدورش)، نه
     *    شناسه‌ی ردیفِ حرکت.
     *
     * «اصلاح و صدورِ دوباره»ی یک سند حرکت‌هایش را پاک و از نو می‌نویسد، پس
     * شناسه‌ی تازه می‌گیرند و به **آخرِ** آن روز می‌رفتند: خریدی که برای
     * اصلاحِ قیمت دوباره صادر شد، پشتِ فروشِ همان روز می‌نشست و ابطالِ خریدِ
     * دیگری «موجودی منفی می‌شود» می‌گفت (بازرسیِ مهر ۱۴۰۵). کلیدِ ترتیب:
     * `first_issued_at` سند (`migration_biz_hardening`)، و برای حرکتِ بی‌سند
     * (انبارگردانی) زمانِ ثبتش.
     *
     * ⚠ سند‌ها **بی‌قفل** خوانده می‌شوند: قفلِ ردیفِ فاکتورهای دیگر وسطِ
     *   صدور، با صدورِ هم‌زمانی که همان فاکتور را قفل کرده و منتظرِ کالاست،
     *   بن‌بست می‌ساخت. `first_issued_at` یک بار نوشته می‌شود و دیگر عوض
     *   نمی‌شود؛ سندی که هنوز در عکسِ این تراکنش صادر نشده، زمانِ ثبتِ
     *   حرکتش را می‌گیرد که همان «اکنون» است.
     */
    private static function inDocOrder(PDO $pdo, int $userId, array $moves): array
    {
        if (!$moves || !tableHasColumn('biz_invoices', 'first_issued_at')) { return $moves; }
        $ids = [];
        foreach ($moves as $m) {
            if ($m['ref_type'] === self::REF_INVOICE && $m['ref_id'] !== null) { $ids[(int)$m['ref_id']] = true; }
        }
        $when = [];
        if ($ids) {
            $keys = []; $params = ['u' => $userId];
            foreach (array_keys($ids) as $n => $id) { $keys[] = ':i' . $n; $params['i' . $n] = $id; }
            $st = $pdo->prepare('SELECT id, first_issued_at FROM biz_invoices WHERE user_id = :u AND id IN (' . implode(',', $keys) . ')');
            $st->execute($params);
            foreach ($st->fetchAll() as $r) {
                if ($r['first_issued_at'] !== null) { $when[(int)$r['id']] = (string)$r['first_issued_at']; }
            }
        }
        return self::sortMoves($moves, $when);
    }

    /**
     * ⛔ تنها کلیدِ ترتیبِ حرکت‌ها — `recalc()`، کاردکس (`ledger()`) و فهرستِ صفحه‌ی
     *    کالا (`moves()`) همه از همین. کاردکسی که ترتیبِ خودش را داشت، موجودیِ
     *    جاری‌اش با زنجیره‌ی بها نمی‌خواند: حرکت‌های یک روز به شناسه‌ی ردیف
     *    می‌آمدند و خریدِ «صدورِ دوباره»شده پشتِ فروشِ همان روز چاپ می‌شد.
     *    `BizSerial::orderSql()` همین ترتیب در SQL است (تاریخ، اولین صدور، سند).
     *
     * @param array<int,string> $when شناسه‌ی سند ← اولین صدور
     */
    private static function sortMoves(array $moves, array $when): array
    {
        $key = static function (array $m) use ($when): array {
            $at = ($m['ref_type'] === self::REF_INVOICE && isset($when[(int)$m['ref_id']]))
                ? $when[(int)$m['ref_id']] : (string)($m['created_at'] ?? '');
            // هم‌ثانیه: شناسه‌ی **سند** (ترتیبِ ساختش)، بعد ردیفِ حرکت. ⚠ حرکتِ بی‌سند
            // (انبارگردانی) آخرِ همان ثانیه: شمارش موجودیِ «تا همین حالا» را می‌سنجد
            // (`adjustTo()`)، پس سندی که در همان ثانیه پیش از آن صادر شد جلویش است.
            $doc = $m['ref_type'] === self::REF_INVOICE ? (int)$m['ref_id'] : PHP_INT_MAX;
            return [$m['kind'] === 'opening' ? 0 : 1, (string)$m['move_date'], $at, $doc, (int)$m['id']];
        };
        usort($moves, static fn(array $a, array $b): int => $key($a) <=> $key($b));
        return $moves;
    }

    /**
     * ردیف‌های سندیِ حرکت‌های یک کالا، به ترتیبِ ردیف — فقط برای سندی که
     * شمارِ ردیف‌هایش با شمارِ حرکت‌هایش یکی است (کالایی که بعداً از «خدمت»
     * به «کالا» تغییر کرده ردیفِ بی‌حرکت دارد؛ آنجا جفت کردن حدس می‌شد، پس
     * آن سند به میانگین برمی‌گردد و ردیفش دست نمی‌خورد).
     * @return array<int, list<array>> شناسه‌ی سند ← ردیف‌ها
     */
    private static function docLines(PDO $pdo, int $userId, int $productId, array $moves): array
    {
        $count = [];
        foreach ($moves as $m) {
            if ($m['ref_type'] === self::REF_INVOICE) { $count[(int)$m['ref_id']] = ($count[(int)$m['ref_id']] ?? 0) + 1; }
        }
        if (!$count) { return []; }
        try {
            $st = $pdo->prepare(
                "SELECT id, invoice_id, ref_line_id, qty, unit_cost, imei1, imei2 FROM biz_invoice_lines
                 WHERE user_id = :u AND product_id = :p
                   AND invoice_id IN (SELECT ref_id FROM biz_stock_moves WHERE user_id = :u2 AND product_id = :p2 AND ref_type = :rt)
                 ORDER BY invoice_id, line_no, id
                 LOCK IN SHARE MODE"
            );
            $st->execute(['u' => $userId, 'p' => $productId, 'u2' => $userId, 'p2' => $productId, 'rt' => self::REF_INVOICE]);
        } catch (PDOException $e) {
            // نصبی که جدولِ سند یا ستونِ IMEI را هنوز ندارد: بی‌زنجیره، مثلِ قبل
            if (in_array((string)$e->getCode(), ['42S02', '42S22'], true)) { return []; }
            throw $e;
        }
        $out = [];
        foreach ($st->fetchAll() as $l) { $out[(int)$l['invoice_id']][] = $l; }
        foreach ($out as $rid => $ls) {
            if (count($ls) !== ($count[$rid] ?? 0)) { unset($out[$rid]); }
        }
        return $out;
    }

    /**
     * ⛔ هر تغییرِ انبار از همین می‌گذرد: قفلِ ردیفِ کالا، تغییر، محاسبه‌ی
     *    دوباره، و اگر موجودی در **هیچ** لحظه‌ای منفی شد، rollback. یک
     *    تستِ خوب جلوی `commit` را نمی‌گیرد؛ خودِ کد باید بگیرد.
     *
     * @param callable(PDO, array):?string $change پیامِ خطا یا null
     * @return array{ok:bool, message:string}
     */
    private static function write(int $userId, int $productId, callable $change, bool $ownTx = true): array
    {
        $pdo = Database::getConnection();
        if ($ownTx) { $pdo->beginTransaction(); Biz::lockShop($pdo, $userId); }
        try {
            $st = $pdo->prepare('SELECT * FROM biz_products WHERE id = :p AND user_id = :u FOR UPDATE');
            $st->execute(['p' => $productId, 'u' => $userId]);
            $product = $st->fetch();
            if (!$product) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => 'کالا پیدا نشد.'];
            }
            if ((int)$product['track_stock'] !== 1) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => 'این قلم خدمت است و موجودی ندارد.'];
            }
            $err = $change($pdo, $product);
            if ($err !== null) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => $err];
            }
            $r = self::recalc($userId, $productId);
            if ($r['min'] < -self::EPS) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => 'با این تغییر موجودیِ کالا منفی می‌شود؛ انجام نشد.'];
            }
            // ⛔ و هیچ انباری هم منفی نشود (فقط فروشگاهِ چندانباره — `BizWarehouses::any()`)
            if (($e = BizWarehouses::negError($pdo, $userId, $productId)) !== null) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => $e];
            }
            if ($ownTx) { $pdo->commit(); }
            return ['ok' => true, 'message' => 'موجودی به‌روز شد.'];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * موجودیِ اول دوره — یک ردیف برای هر کالا؛ دوباره زدنش همان ردیف را
     * عوض می‌کند و مقدارِ صفر حذفش می‌کند.
     */
    public static function setOpening(int $userId, int $productId, float $qty, int $unitCost, bool $ownTx = true): array
    {
        if ($qty < 0)      { return ['ok' => false, 'message' => 'موجودیِ اول دوره نمی‌تواند منفی باشد.']; }
        if ($unitCost < 0) { return ['ok' => false, 'message' => 'بهای واحد نمی‌تواند منفی باشد.']; }
        if (($e = BizCommon::qtyError($qty, 'موجودیِ اول دوره')) !== null
            || ($e = BizCommon::moneyError($unitCost, 'بهای واحد')) !== null) { return ['ok' => false, 'message' => $e]; }
        return self::write($userId, $productId, function (PDO $pdo, array $p) use ($userId, $productId, $qty, $unitCost): ?string {
            if (!BizProducts::qtyFits((string)$p['unit'], $qty)) {
                return 'موجودی برای واحدِ «' . $p['unit'] . '» باید عددِ صحیح باشد.';
            }
            // ⛔ اول دوره تاریخِ ساختِ کالا را دارد و بهای همه‌ی فروش‌های بعدی از آن
            //    ساخته می‌شود؛ کالای قدیمی در دوره‌ی بسته دیگر اول دوره‌اش عوض نمی‌شود
            // ⛔ و با **قدیمی‌ترین حرکتِ** کالا، نه فقط تاریخِ ساخت: اول دوره پیش از
            //    همه‌ی حرکت‌ها حساب می‌شود، پس فروشِ تاریخ‌گذشته‌ی کالای تازه هم
            //    بهایش را از آن می‌گیرد — و سودِ ماهِ بسته عوض می‌شد (بازرسیِ مهر ۱۴۰۵).
            $mm = $pdo->prepare('SELECT MIN(move_date) FROM biz_stock_moves WHERE user_id = :u AND product_id = :p');
            $mm->execute(['u' => $userId, 'p' => $productId]);
            $since = BizCommon::earliest([substr((string)$p['created_at'], 0, 10), (string)$mm->fetchColumn()]);
            if (($e = Biz::lockError($userId, $since, 'موجودیِ اول دوره‌ی این کالا')) !== null) { return $e; }
            $pdo->prepare("DELETE FROM biz_stock_moves WHERE product_id = :p AND user_id = :u AND kind = 'opening'")
                ->execute(['p' => $productId, 'u' => $userId]);
            if ($qty > 0) {
                $pdo->prepare(
                    "INSERT INTO biz_stock_moves (user_id, product_id, move_date, kind, qty, unit_cost)
                     VALUES (:u, :p, :d, 'opening', :q, :c)"
                )->execute(['u' => $userId, 'p' => $productId, 'd' => substr((string)$p['created_at'], 0, 10),
                            'q' => $qty, 'c' => $unitCost]);
            }
            return null;
        }, $ownTx);
    }

    /**
     * انبارگردانی — «موجودیِ واقعیِ شمارش‌شده این است»؛ تفاوت یک حرکت
     * می‌شود. ورودِ اضافه به میانگینِ جاری ارزش‌گذاری می‌شود.
     */
    public static function adjustTo(int $userId, int $productId, float $actual, string $note = '', ?int $warehouseId = null): array
    {
        if ($actual < 0) { return ['ok' => false, 'message' => 'موجودیِ واقعی نمی‌تواند منفی باشد.']; }
        if (($e = BizCommon::qtyError($actual, 'موجودیِ واقعی')) !== null) { return ['ok' => false, 'message' => $e]; }
        $today = date('Y-m-d');
        if (($e = Biz::lockError($userId, $today, 'انبارگردانی')) !== null) { return ['ok' => false, 'message' => $e]; }
        $note = mb_substr(BizCommon::line($note), 0, 200);
        $changed = false;
        $res = self::write($userId, $productId, function (PDO $pdo, array $p) use ($userId, $productId, $actual, $note, $today, $warehouseId, &$changed): ?string {
            if (!BizProducts::qtyFits((string)$p['unit'], $actual)) {
                return 'موجودی برای واحدِ «' . $p['unit'] . '» باید عددِ صحیح باشد.';
            }
            // ⛔ شمارش «امروز» است، پس با موجودیِ **تا امروز** سنجیده می‌شود، نه با
            //    `stock_qty` (که سندهای تاریخِ آینده را هم دارد): فروشِ پیش‌فاکتورِ
            //    هفته‌ی بعد شمارشِ درستِ امروز را «اضافه» نشان می‌داد و حرکتِ غلط
            //    می‌ساخت، و خریدِ تاریخِ آینده شمارشِ درست را با «منفی می‌شود» رد
            //    می‌کرد (بازبینیِ مهر ۱۴۰۵، بازتولید شد). انبارگردانی در ترتیبِ
            //    `recalc()` آخرِ همان روز است، پس «تا امروز» = همه‌ی حرکت‌های تا امروز.
            // ⛔ فروشگاهِ چندانباره: شمارشِ **یک انبار** (پیش‌فرض اصلی) با موجودیِ همان انبار تا امروز
            $multi = BizWarehouses::any($userId);
            if ($multi) {
                $have = (float)(BizWarehouses::walk($pdo, $userId, $productId, $today)['now'][$warehouseId ?? 0] ?? 0.0);
            } else {
                $q = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) FROM biz_stock_moves
                                    WHERE user_id = :u AND product_id = :p AND (move_date <= :d OR kind = 'opening')");
                $q->execute(['u' => $userId, 'p' => $productId, 'd' => $today]);
                $have = (float)$q->fetchColumn();
            }
            $delta = round($actual - $have, 3);
            if (abs($delta) < 0.0005) { return null; }
            $changed = true;
            $pdo->prepare(
                "INSERT INTO biz_stock_moves (user_id, product_id, move_date, kind, qty, unit_cost, note)
                 VALUES (:u, :p, :d, 'adjust', :q, NULL, :n)"
            )->execute(['u' => $userId, 'p' => $productId, 'd' => $today, 'q' => $delta, 'n' => $note === '' ? null : $note]);
            if ($multi && $warehouseId !== null) {
                $pdo->prepare('UPDATE biz_stock_moves SET warehouse_id = :w WHERE id = :id AND user_id = :u')
                    ->execute(['w' => $warehouseId, 'id' => (int)$pdo->lastInsertId(), 'u' => $userId]);
            }
            return null;
        });
        if ($res['ok'] && !$changed) { $res['message'] = 'موجودی همان عدد بود؛ چیزی عوض نشد.'; }
        return $res;
    }

    /**
     * ⛔ حرکت‌های یک سند (فاکتور یا برگشت) — تنها راهِ سند به انبار.
     *
     * جایگزینیِ کامل است: برای هر کالا حرکت‌های قبلیِ همین سند پاک و
     * تازه‌ها نوشته می‌شوند، پس «صدور»، «برگشت به پیش‌نویس» و «باطل» یک مسیر
     * دارند (باطل = فهرستِ خالی). هر کالا از `write()` می‌گذرد: قفلِ ردیف،
     * محاسبه‌ی دوباره، و سدِ «موجودی در هیچ لحظه‌ای منفی نشود».
     *
     * ⛔ فقط داخلِ تراکنشِ فراخواننده (`ownTx = false`) — اگر کالای سوم
     *    شکست بخورد، دو کالای اول هم باید برگردند؛ فراخواننده rollback می‌کند.
     * ⚠ کالاها به ترتیبِ شناسه قفل می‌شوند تا دو سندِ هم‌زمان به بن‌بست نخورند.
     *
     * @param array<int, list<array{qty:float, unit_cost:?int, date:string}>> $moves
     * @param int[] $clear کالاهایی که پیش از این حرکتی از همین سند داشتند
     * @return array{ok:bool, message:string, avg?:array<int,float>}
     */
    public static function postDoc(int $userId, string $kind, int $refId, array $moves, array $clear = []): array
    {
        if (!isset(self::KINDS[$kind]) || in_array($kind, ['opening', 'adjust'], true)) {
            return ['ok' => false, 'message' => 'نوعِ سند معتبر نیست.'];
        }
        $ids = array_values(array_unique(array_map('intval', array_merge(array_keys($moves), $clear))));
        sort($ids);
        $avg = [];
        // ⛔ انبارِ سند (سرِ فاکتور، برگشت از اصلش) — فقط فروشگاهِ چندانباره؛ بقیه `NULL` = اصلی
        $wh = BizWarehouses::forDoc(Database::getConnection(), $userId, $refId);
        foreach ($ids as $pid) {
            $list = $moves[$pid] ?? [];
            $res = self::write($userId, $pid, function (PDO $pdo, array $p) use ($userId, $pid, $kind, $refId, $list, $wh, &$avg): ?string {
                $avg[$pid] = (float)$p['avg_cost'];
                $pdo->prepare('DELETE FROM biz_stock_moves WHERE user_id = :u AND product_id = :p AND ref_type = :rt AND ref_id = :r')
                    ->execute(['u' => $userId, 'p' => $pid, 'rt' => self::REF_INVOICE, 'r' => $refId]);
                $ins = $pdo->prepare(
                    'INSERT INTO biz_stock_moves (user_id, product_id, move_date, kind, qty, unit_cost, ref_type, ref_id)
                     VALUES (:u, :p, :d, :k, :q, :c, :rt, :r)'
                );
                foreach ($list as $m) {
                    if (!BizProducts::qtyFits((string)$p['unit'], abs((float)$m['qty']))) {
                        return 'مقدارِ «' . $p['name'] . '» برای واحدِ «' . $p['unit'] . '» باید عددِ صحیح باشد.';
                    }
                    $ins->execute(['u' => $userId, 'p' => $pid, 'd' => $m['date'], 'k' => $kind, 'q' => round((float)$m['qty'], 3),
                                   'c' => $m['unit_cost'] ?? (int)round((float)$p['avg_cost']), 'rt' => self::REF_INVOICE, 'r' => $refId]);
                    if ($wh !== null) {
                        $pdo->prepare('UPDATE biz_stock_moves SET warehouse_id = :w WHERE id = :id AND user_id = :u')
                            ->execute(['w' => $wh, 'id' => (int)$pdo->lastInsertId(), 'u' => $userId]);
                    }
                }
                return null;
            }, false);
            if (!$res['ok']) {
                $name = self::nameOf($userId, $pid);
                return ['ok' => false, 'message' => ($name !== '' ? '«' . $name . '»: ' : '') . $res['message']];
            }
        }
        return ['ok' => true, 'message' => 'موجودی به‌روز شد.', 'avg' => $avg];
    }

    private static function nameOf(int $userId, int $pid): string
    {
        $st = Database::getConnection()->prepare('SELECT name FROM biz_products WHERE id = :p AND user_id = :u');
        $st->execute(['p' => $pid, 'u' => $userId]);
        return (string)($st->fetchColumn() ?: '');
    }

    /** حذفِ یک حرکتِ انبارگردانی — اگر موجودی در هیچ لحظه‌ای منفی نشود. */
    public static function deleteMove(int $userId, int $moveId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT product_id, kind, move_date FROM biz_stock_moves WHERE id = :id AND user_id = :u LIMIT 1'
        );
        $st->execute(['id' => $moveId, 'u' => $userId]);
        $m = $st->fetch();
        if (!$m) { return ['ok' => false, 'message' => 'حرکت پیدا نشد.']; }
        if ($m['kind'] !== 'adjust') {
            return ['ok' => false, 'message' => 'فقط انبارگردانی از اینجا حذف می‌شود.'];
        }
        if (($e = Biz::lockError($userId, (string)$m['move_date'], 'این انبارگردانی')) !== null) { return ['ok' => false, 'message' => $e]; }
        return self::write($userId, (int)$m['product_id'], function (PDO $pdo) use ($userId, $moveId): ?string {
            $pdo->prepare('DELETE FROM biz_stock_moves WHERE id = :id AND user_id = :u')
                ->execute(['id' => $moveId, 'u' => $userId]);
            return null;
        });
    }

    /**
     * کاردکسِ کالا — همه‌ی حرکت‌ها به ترتیبِ زمان با موجودیِ جاری؛ همان
     * ترتیبِ `recalc()` (اول دوره همیشه اول)، پس موجودیِ پایانی دقیقاً همان
     * `stock_qty` است.
     * @return array{rows:array, capped:bool}
     */
    public static function ledger(int $userId, int $productId, int $cap = 2000): array
    {
        $rows = self::orderedMoves($userId, $productId, "(m.kind = 'opening') DESC, m.move_date, m.id", $cap + 1);
        $capped = count($rows) > $cap;
        $run = 0.0;
        foreach ($rows as &$m) { $run = round($run + (float)$m['qty'], 3); $m['balance'] = $run; }
        unset($m);
        return ['rows' => array_slice($rows, 0, $cap), 'capped' => $capped];
    }

    /** آخرین حرکت‌ها برای صفحه‌ی کالا — همان ترتیبِ `recalc()`، وارونه (تازه‌ترین بالا، اول دوره ته). */
    public static function moves(int $userId, int $productId, int $limit = 50): array
    {
        return array_reverse(self::orderedMoves($userId, $productId, "(m.kind = 'opening'), m.move_date DESC, m.id DESC", $limit));
    }

    /**
     * حرکت‌های یک کالا به ترتیبِ `recalc()` (`sortMoves()`). SQL فقط **کدام**
     * ردیف‌ها را با سقف برمی‌دارد؛ ترتیب را همان کلیدِ PHP می‌دهد. اولین صدورِ
     * سند با همان کوئری (LEFT JOIN، بی‌قفل) می‌آید — صفحه کوئریِ تازه‌ای نمی‌گیرد.
     */
    private static function orderedMoves(int $userId, int $productId, string $pick, int $limit): array
    {
        $fi  = tableHasColumn('biz_invoices', 'first_issued_at');
        $st  = Database::getConnection()->prepare(
            'SELECT m.*' . ($fi ? ', i.first_issued_at AS doc_at' : '') . ' FROM biz_stock_moves m'
            . ($fi ? ' LEFT JOIN biz_invoices i ON m.ref_type = :rt AND i.id = m.ref_id AND i.user_id = m.user_id' : '')
            . " WHERE m.product_id = :p AND m.user_id = :u ORDER BY {$pick} LIMIT :lim"
        );
        if ($fi) { $st->bindValue('rt', self::REF_INVOICE); }
        $st->bindValue('p', $productId, PDO::PARAM_INT);
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        if (!$fi) { return $rows; }
        $when = [];
        foreach ($rows as &$r) {
            if ($r['doc_at'] !== null) { $when[(int)$r['ref_id']] = (string)$r['doc_at']; }
            unset($r['doc_at']);
        }
        unset($r);
        return self::sortMoves($rows, $when);
    }
}

/* =================================================================
   طرف‌حساب (مشتری و تأمین‌کننده)
   ================================================================= */
final class BizParties
{
    /** سطحِ قیمت و سقفِ اعتبار آمده‌اند؟ (migration_biz_pricing) */
    public static function termsReady(): bool
    {
        return tableHasColumn('biz_parties', 'credit_limit');
    }

    /**
     * ⛔ سقفِ اعتبار — از `issueTx` (همان تراکنش، پس با موجودیِ قفل‌شده). `$add` = آنچه این
     *    فروش به مانده اضافه می‌کند (جمع منهای دریافتِ همراه). سقفِ صفر = بی‌سقف؛ فروشی که
     *    مانده را **پایین** می‌آورد (یا دست نمی‌زند) هیچ‌وقت رد نمی‌شود.
     */
    public static function creditError(PDO $pdo, int $userId, int $partyId, int $add): ?string
    {
        if ($add <= 0 || !self::termsReady()) { return null; }
        $st = $pdo->prepare('SELECT p.name, p.credit_limit, ' . self::BALANCE_SQL . ' AS bal FROM biz_parties p WHERE p.id = :id AND p.user_id = :u');
        $st->execute(['id' => $partyId, 'u' => $userId]);
        $r = $st->fetch();
        if (!$r || (int)$r['credit_limit'] <= 0) { return null; }
        $after = (int)$r['bal'] + $add;
        if ($after <= (int)$r['credit_limit']) { return null; }
        return 'سقفِ اعتبارِ «' . $r['name'] . '» ' . formatMoney((int)$r['credit_limit']) . ' تومان است؛ با این فروش مانده‌اش '
             . formatMoney($after) . ' می‌شود. بخشی را نقد بگیرید یا (با دسترسیِ مالی) «گذر از سقفِ اعتبار» را بزنید.';
    }

    /** سطحِ قیمت و سقفِ اعتبارِ یک طرف‌حساب (فرمِ طرف‌حساب). @return array{ok:bool, message:string} */
    public static function saveTerms(int $userId, int $id, array $in): array
    {
        if (!self::termsReady()) { return ['ok' => true, 'message' => '']; }
        $level = (string)($in['price_level'] ?? 'retail');
        if (!isset(BizProducts::LEVELS[$level])) { $level = 'retail'; }
        $limit = sanitizeAmount((string)($in['credit_limit'] ?? ''));
        if (($e = BizCommon::moneyError($limit, 'سقفِ اعتبار')) !== null) { return ['ok' => false, 'message' => $e]; }
        Database::getConnection()->prepare('UPDATE biz_parties SET price_level = :l, credit_limit = :c WHERE id = :id AND user_id = :u')
            ->execute(['l' => $level, 'c' => $limit, 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => ''];
    }

    /** قدیمی‌ترین تاریخِ سند یا دریافت/پرداختِ این طرف‌حساب ('' اگر هیچ). */
    public static function firstDocDate(int $userId, int $partyId): string
    {
        $st = Database::getConnection()->prepare(
            "SELECT LEAST(COALESCE((SELECT MIN(inv_date) FROM biz_invoices WHERE user_id = :u AND party_id = :p AND status = 'issued'), '9999-12-31'),
                          COALESCE((SELECT MIN(pay_date) FROM biz_payments WHERE user_id = :u2 AND party_id = :p2 AND status = 'ok'), '9999-12-31'))"
        );
        $st->execute(['u' => $userId, 'p' => $partyId, 'u2' => $userId, 'p2' => $partyId]);
        $d = (string)$st->fetchColumn();
        return $d === '9999-12-31' ? '' : $d;
    }

    public const KINDS = [
        'customer' => 'مشتری',
        'supplier' => 'تأمین‌کننده',
        'both'     => 'مشتری و تأمین‌کننده',
        // ⛔ کارمند (حقوق و مساعده — `BizPayroll`): مساعده «پرداخت» به اوست و
        //    مانده‌ی بدهکارش همان مساعده‌ی تسویه‌نشده است.
        'employee' => 'کارمند',
    ];

    public const FILTERS = [
        ''         => 'همه',
        'customer' => 'مشتری‌ها',
        'supplier' => 'تأمین‌کننده‌ها',
        'debtor'   => 'بدهکاران',
        'creditor' => 'طلبکاران',
        'employee' => 'کارکنان',
        'inactive' => 'غیرفعال',
    ];

    public const LIMITS = ['name' => 150, 'phone' => 40, 'address' => 300, 'note' => 300];
    public const PAGE_SIZE = 25;

    /** کدهای رسمیِ خریدار — قاعده‌شان `BizCommon::CODES`. */
    public const CODE_KEYS = ['national_id', 'economic_code', 'postal_code'];

    /** ستون‌های کد آمده‌اند؟ (نصبِ migration‌نخورده نباید بشکند) */
    public static function hasCodes(): bool
    {
        return function_exists('tableHasColumn') && tableHasColumn('biz_parties', 'economic_code');
    }

    /** طولِ «کدِ طرف‌حساب» (migration_biz_import). */
    public const CODE_MAX = 30;

    /** ستونِ «کدِ طرف‌حساب» آمده؟ (migration_biz_import) */
    public static function hasPartyCode(): bool
    {
        return function_exists('tableHasColumn') && tableHasColumn('biz_parties', 'code');
    }

    /**
     * ⛔ جبرانِ چک‌های در جریانِ واردشده (`biz_payments.opening_import`) —
     *    مقداری که **رویِ** مانده‌ی فایلِ نرم‌افزارِ قبلی به مانده‌ی اول دوره
     *    افزوده شده: دریافتیِ c مانده را c کم می‌کند، پس اول دوره c بالاتر است
     *    (پرداختی برعکس). وضعیتِ چک مهم نیست — چکِ برگشتی باطل می‌شود و بدهی
     *    درست برمی‌گردد، چون جبران سرِ جایش است.
     */
    public static function chequeComp(int $userId, int $partyId): int
    {
        if (!tableHasColumn('biz_payments', 'opening_import')) { return 0; }
        $st = Database::getConnection()->prepare(
            "SELECT COALESCE(SUM(CASE kind WHEN 'receipt' THEN amount WHEN 'payment' THEN -amount ELSE 0 END), 0)
             FROM biz_payments WHERE user_id = :u AND party_id = :p AND opening_import = 1"
        );
        $st->execute(['u' => $userId, 'p' => $partyId]);
        return (int)$st->fetchColumn();
    }

    /**
     * ⛔ تنها جابه‌جاییِ مانده‌ی اول دوره **بیرون از فرم** — جبرانِ چکِ
     *    واردشده، داخلِ همان تراکنشِ ثبتِ چک. همان سدِ دوره‌ی بسته‌ی `save()`.
     * @return ?string پیامِ خطا یا null
     */
    public static function shiftOpening(PDO $pdo, int $userId, int $partyId, int $delta): ?string
    {
        $cur = self::get($userId, $partyId);
        if (!$cur) { return 'طرف‌حساب پیدا نشد.'; }
        if (($e = Biz::lockError($userId, BizCommon::earliest([substr((string)$cur['created_at'], 0, 10),
                self::firstDocDate($userId, $partyId)]), 'مانده‌ی اول دوره‌ی این طرف‌حساب')) !== null) {
            return $e;
        }
        $pdo->prepare('UPDATE biz_parties SET opening_balance = opening_balance + :d WHERE id = :id AND user_id = :u')
            ->execute(['d' => $delta, 'id' => $partyId, 'u' => $userId]);
        self::reallocate($pdo, $userId, $partyId);
        return null;
    }

    /** مانده‌ی اول دوره در تسویه‌ی فاکتورها هست (`BizPay::reallocateTx()`) — با تغییرش از نو. */
    private static function reallocate(PDO $pdo, int $userId, int $partyId): void
    {
        if (!class_exists('BizPay', false)) { require_once __DIR__ . '/biz_docs.php'; }
        BizPay::reallocateTx($pdo, $userId, $partyId);
    }

    /**
     * ⛔ تنها تعریفِ «مانده‌ی یک طرف‌حساب» — فهرست، صافیِ بدهکار/طلبکار،
     *    داشبورد، صورت‌حساب و چاپ همه از همین.
     *    مثبت = او به فروشگاه بدهکار است؛ منفی = فروشگاه به او بدهکار است.
     *
     *    مانده‌ی اول دوره
     *    + فروش − برگشت از فروش − خرید + برگشت از خرید   (فقط صادرشده)
     *    − دریافت از او + پرداخت به او                   (فقط باطل‌نشده)
     *
     * ⚠ تخصیصِ دریافت به فاکتور (`biz_allocations`) اینجا نیست و نباید باشد:
     *   تخصیص فقط می‌گوید کدام فاکتور تسویه شده، نه اینکه چه کسی چقدر بدهکار
     *   است. پولی که هنوز به فاکتوری نخورده همچنان از مانده کم می‌شود.
     */
    public const BALANCE_SQL = "(p.opening_balance
        + COALESCE((SELECT SUM(CASE bi.kind WHEN 'sale' THEN bi.total WHEN 'purchase_return' THEN bi.total ELSE -bi.total END)
                    FROM biz_invoices bi WHERE bi.party_id = p.id AND bi.user_id = p.user_id AND bi.status = 'issued'), 0)
        + COALESCE((SELECT SUM(CASE bp.kind WHEN 'receipt' THEN -bp.amount WHEN 'payment' THEN bp.amount ELSE 0 END)
                    FROM biz_payments bp WHERE bp.party_id = p.id AND bp.user_id = p.user_id AND bp.status = 'ok'), 0))";

    /** ⛔ تنها جای صافی‌های فهرستِ طرف‌حساب (صفحه و چاپ). @return array{0:string, 1:array} */
    private static function where(int $userId, string $q, string $filter): array
    {
        $bal    = self::BALANCE_SQL;
        $where  = ['p.user_id = :u'];
        $params = ['u' => $userId];
        switch (isset(self::FILTERS[$filter]) ? $filter : '') {
            case 'customer': $where[] = "p.is_active = 1 AND p.kind IN ('customer','both')"; break;
            case 'supplier': $where[] = "p.is_active = 1 AND p.kind IN ('supplier','both')"; break;
            case 'debtor':   $where[] = "p.is_active = 1 AND {$bal} > 0"; break;
            case 'creditor': $where[] = "p.is_active = 1 AND {$bal} < 0"; break;
            case 'employee': $where[] = "p.is_active = 1 AND p.kind = 'employee'"; break;
            case 'inactive': $where[] = 'p.is_active = 0'; break;
            default:         $where[] = 'p.is_active = 1';
        }
        if ($q !== '') {
            // ⛔ نام با ی/ک فارسی (نامِ قدیمیِ عربی‌نوشته هم با `q3`)، و تلفن بی‌فاصله
            //    و خط‌تیره در هر دو سو: «0912 123 4567» همان «09121234567» است
            //    (بازرسیِ مهر ۱۴۰۵: هیچ‌کدام پیدا نمی‌شد).
            $where[] = "(p.name LIKE :q1 ESCAPE '!' OR p.name LIKE :q3 ESCAPE '!'
                        OR REPLACE(REPLACE(p.phone, ' ', ''), '-', '') LIKE :q2 ESCAPE '!')";
            $params['q1'] = BizCommon::like(BizCommon::persian($q));
            $params['q3'] = BizCommon::like(strtr($q, ['ی' => 'ي', 'ک' => 'ك']));
            $digits = (string)preg_replace('/[\s\-]+/u', '', toLatinDigits($q));
            $params['q2'] = BizCommon::like($digits !== '' ? $digits : toLatinDigits($q));
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * همه‌ی ردیف‌ها برای چاپ و منوها. @return array{rows:array, capped:bool}
     *
     * ⛔ `$withBalance = false` برای منویی که مانده را نشان نمی‌دهد (فروشِ
     *    سریع، مشتریِ پیش‌فرض، صافیِ گزارش): `BALANCE_SQL` دو زیرکوئریِ
     *    همبسته به‌ازای **هر** طرف‌حساب است و یک منوی ساده را به کُندترین
     *    کوئریِ صفحه تبدیل می‌کرد. کلیدِ `balance` در آن حالت `null` است، نه
     *    صفر — صفر یک مانده‌ی واقعی است.
     */
    public static function all(int $userId, string $filter = '', int $cap = 3000, bool $withBalance = true): array
    {
        $bal = $withBalance ? self::BALANCE_SQL : 'NULL';
        [$sqlWhere, $params] = self::where($userId, '', $filter);
        $st = Database::getConnection()->prepare(
            "SELECT p.*, {$bal} AS balance FROM biz_parties p WHERE {$sqlWhere} ORDER BY p.name, p.id LIMIT :lim"
        );
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', $cap + 1, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        return ['rows' => array_slice($rows, 0, $cap), 'capped' => count($rows) > $cap];
    }

    /**
     * صورت‌حسابِ یک طرف‌حساب: ردیف‌های بدهکار/بستانکار با مانده‌ی جاری.
     * ⛔ مانده‌ی پایانی باید با `BALANCE_SQL` یکی باشد (تست همین را
     *    می‌سنجد): همان سه منبع، با همان شرطِ «صادرشده/باطل‌نشده».
     * @return array{party:array, lines:array, debit:int, credit:int, balance:int}|null
     */
    public static function statement(int $userId, int $id): ?array
    {
        $party = self::get($userId, $id);
        if (!$party) { return null; }
        $lines = [];
        $ob = (int)$party['opening_balance'];
        if ($ob !== 0) {
            $lines[] = ['date' => substr((string)$party['created_at'], 0, 10), 'desc' => 'مانده‌ی اول دوره',
                        'debit' => max($ob, 0), 'credit' => max(-$ob, 0), 'sort' => '0'];
        }
        $pdo = Database::getConnection();
        $docNames = ['sale' => 'فاکتور فروش', 'purchase' => 'فاکتور خرید', 'sale_return' => 'برگشت از فروش', 'purchase_return' => 'برگشت از خرید'];
        $st = $pdo->prepare("SELECT id, kind, number, inv_date, total, created_at FROM biz_invoices
                             WHERE party_id = :p AND user_id = :u AND status = 'issued' ORDER BY inv_date, id");
        $st->execute(['p' => $id, 'u' => $userId]);
        foreach ($st->fetchAll() as $r) {
            $plus = in_array($r['kind'], ['sale', 'purchase_return'], true);
            $lines[] = ['date' => (string)$r['inv_date'], 'desc' => ($docNames[$r['kind']] ?? '') . ' شماره‌ی ' . toPersianDigits((string)$r['number']),
                        'debit' => $plus ? (int)$r['total'] : 0, 'credit' => $plus ? 0 : (int)$r['total'],
                        'sort' => $r['inv_date'] . ' 1 ' . $r['created_at'], 'invoice_id' => (int)$r['id']];
        }
        // ⛔ چکِ برگشتیِ دوره‌ی بسته و سندِ معکوسش هر دو «زنده»اند (`migration_biz_pair`)؛ بی‌برچسب
        //    صورت‌حساب «دریافت ۱ … پرداخت ۱» می‌گفت و مشتری نمی‌فهمید آن پرداخت چیست.
        //    ⚠ بی‌`tableHasColumn()`: آن کلِ `information_schema` را می‌خواند و بودجه‌ی کوئریِ صفحه‌ی
        //      طرف‌حساب و فاکتور یکی بالا می‌رفت. نصبِ migration‌نخورده (42S22) همان کوئریِ قبلی.
        $sql = "SELECT id, kind, number, pay_date, amount, method, created_at, invoice_id, origin_invoice%s FROM biz_payments
                WHERE party_id = :p AND user_id = :u AND status = 'ok' AND kind IN ('receipt','payment') ORDER BY pay_date, id";
        try {
            $st = $pdo->prepare(sprintf($sql, ', cheque_no, cheque_status, pair_id, title'));
            $st->execute(['p' => $id, 'u' => $userId]);
        } catch (PDOException $e) {
            $st = $pdo->prepare(sprintf($sql, ''));
            $st->execute(['p' => $id, 'u' => $userId]);
        }
        foreach ($st->fetchAll() as $r) {
            $rec = $r['kind'] === 'receipt';
            $tag = '';
            if (($r['cheque_status'] ?? null) === 'bounced') {
                $tag = ' — چک' . (!empty($r['cheque_no']) ? ' ' . toPersianDigits((string)$r['cheque_no']) : '') . ' (برگشتی)';
            } elseif (($r['pair_id'] ?? null) !== null && (string)($r['title'] ?? '') !== '') {
                $tag = ' — ' . $r['title'];
            }
            $lines[] = ['date' => (string)$r['pay_date'], 'desc' => ($rec ? 'دریافت' : 'پرداخت') . ' شماره‌ی ' . toPersianDigits((string)$r['number']) . $tag,
                        'debit' => $rec ? 0 : (int)$r['amount'], 'credit' => $rec ? (int)$r['amount'] : 0,
                        'sort' => $r['pay_date'] . ' 2 ' . $r['created_at'], 'payment_id' => (int)$r['id'],
                        'origin_of' => (int)$r['origin_invoice'] === 1 ? (int)$r['invoice_id'] : 0];
        }
        usort($lines, fn($a, $b) => strcmp($a['sort'], $b['sort']));
        $run = 0; $dr = 0; $cr = 0;
        foreach ($lines as &$l) {
            $run += $l['debit'] - $l['credit'];
            $dr += $l['debit']; $cr += $l['credit'];
            $l['balance'] = $run;
        }
        unset($l);
        return ['party' => $party, 'lines' => $lines, 'debit' => $dr, 'credit' => $cr, 'balance' => $run];
    }

    /**
     * مانده‌ی طرف‌حساب **پیش و پس** از یک فاکتورِ صادرشده — برای «مانده‌ی
     * قبلی / مانده‌ی کل» روی فاکتور و برگه‌ی چاپ.
     *
     * ⛔ از همان `statement()` ساخته می‌شود، نه یک حسابِ دوم: «قبلی» مانده‌ی
     *    جاریِ صورت‌حساب درست پیش از ردیفِ همین فاکتور است، پس با صفحه‌ی
     *    طرف‌حساب و برگه‌ی صورت‌حساب همیشه یک عدد می‌گوید.
     * ⛔ «پس از» = قبلی + این فاکتور ± فقط دریافت/پرداختِ **همراهِ همین
     *    فاکتور** (`origin_invoice = 1`) — نه `paid`ِ فاکتور: تخصیصِ FIFO
     *    پیش‌پرداختی را هم دارد که از قبل در «قبلی» کم شده، و آن‌وقت همان
     *    پول دو بار کم می‌شد.
     * @return array{prev:int, doc:int, paid:int, after:int}|null  مثبت = او بدهکار است
     */
    public static function balanceAround(int $userId, int $partyId, int $invoiceId): ?array
    {
        $s = self::statement($userId, $partyId);
        if ($s === null) { return null; }
        $prev = 0; $doc = null; $paid = 0;
        foreach ($s['lines'] as $l) {
            if (($l['origin_of'] ?? 0) === $invoiceId) { $paid += $l['debit'] - $l['credit']; }
            if ($doc !== null) { continue; }
            if (($l['invoice_id'] ?? 0) === $invoiceId) { $doc = $l['debit'] - $l['credit']; continue; }
            $prev = $l['balance'];
        }
        if ($doc === null) { return null; }                      // پیش‌نویس یا باطل — در صورت‌حساب نیست
        return ['prev' => $prev, 'doc' => $doc, 'paid' => $paid, 'after' => $prev + $doc + $paid];
    }

    /** برچسبِ جهتِ یک مانده (مثبت = او بدهکار است). */
    public static function sideLabel(int $bal): string
    {
        return $bal > 0 ? 'بدهکار' : ($bal < 0 ? 'بستانکار' : 'تسویه');
    }

    public static function list(int $userId, string $q = '', string $filter = '', int $page = 1): array
    {
        $bal = self::BALANCE_SQL;
        [$sqlWhere, $params] = self::where($userId, $q, $filter);
        $pdo = Database::getConnection();

        $st = $pdo->prepare("SELECT COUNT(*) FROM biz_parties p WHERE {$sqlWhere}");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        [$page, $pages, $offset] = BizCommon::window($total, $page, self::PAGE_SIZE);

        $st = $pdo->prepare(
            "SELECT p.*, {$bal} AS balance FROM biz_parties p WHERE {$sqlWhere}
             ORDER BY p.name, p.id LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', self::PAGE_SIZE, PDO::PARAM_INT);
        $st->bindValue('off', $offset, PDO::PARAM_INT);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function get(int $userId, int $id): ?array
    {
        $bal = self::BALANCE_SQL;
        $st  = Database::getConnection()->prepare(
            "SELECT p.*, {$bal} AS balance FROM biz_parties p WHERE p.id = :id AND p.user_id = :u LIMIT 1"
        );
        $st->execute(['id' => $id, 'u' => $userId]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /**
     * `opening_side`: `they` (او بدهکار است) یا `we` (فروشگاه بدهکار است).
     * مبلغ همیشه مثبت تایپ می‌شود و جهت جدا انتخاب می‌شود — علامتِ منفی
     * روی کیبوردِ موبایل گم می‌شود و `sanitizeAmount()` هم آن را می‌اندازد.
     *
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function save(int $userId, array $in, int $id = 0): array
    {
        // ⛔ ی/ک عربی ← فارسی هنگامِ ذخیره، تا «علي» و «علی» یک نام باشند و جست‌وجو پیدایش کند
        $name    = BizCommon::persian(BizCommon::line((string)($in['name'] ?? '')));
        $kind    = (string)($in['kind'] ?? 'customer');
        $phone   = BizCommon::line(toLatinDigits((string)($in['phone'] ?? '')));
        $address = BizCommon::line((string)($in['address'] ?? ''));
        $note    = trim((string)($in['note'] ?? ''));
        $amount  = sanitizeAmount($in['opening_amount'] ?? '');
        $side    = (string)($in['opening_side'] ?? 'they');

        if ($name === '') { return ['ok' => false, 'message' => 'نامِ طرف‌حساب الزامی است.']; }
        if (!isset(self::KINDS[$kind])) { return ['ok' => false, 'message' => 'نوعِ طرف‌حساب معتبر نیست.']; }
        if (!in_array($side, ['they', 'we'], true)) { return ['ok' => false, 'message' => 'جهتِ مانده معتبر نیست.']; }
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{3,40}$/', $phone)) {
            return ['ok' => false, 'message' => 'شماره‌ی تلفن معتبر نیست.'];
        }
        foreach (['name' => $name, 'phone' => $phone, 'address' => $address, 'note' => $note] as $k => $v) {
            if (mb_strlen($v) > self::LIMITS[$k]) {
                return ['ok' => false, 'message' => 'متنِ یکی از فیلدها بیش از ' . self::LIMITS[$k] . ' نویسه است.'];
            }
        }
        if (($e = BizCommon::moneyError($amount, 'مانده‌ی اول دوره')) !== null) { return ['ok' => false, 'message' => $e]; }
        $opening = $side === 'we' ? -$amount : $amount;
        // کدهای رسمیِ خریدار (migration_biz_business_info) — فقط رقم
        $codes = [];
        foreach (self::CODE_KEYS as $ck) {
            $c = BizCommon::code($ck, (string)($in[$ck] ?? ''));
            if (!$c['ok']) { return ['ok' => false, 'message' => $c['message']]; }
            $codes[$ck] = $c['value'];
        }
        // ⛔ «کدِ طرف‌حساب» — فقط اگر فرستاده شده (همان قاعده‌ی کدهای رسمی) و
        //    یکتا در همین فروشگاه: کلیدِ تطبیقِ ورود از فایل است و دو شخص با
        //    یک کد یعنی ورودِ بعدی مانده‌ی یکی را روی دیگری می‌نشاند.
        $pcode = null;
        $hasPcode = array_key_exists('code', $in) && self::hasPartyCode();
        if ($hasPcode) {
            $pcode = BizCommon::line(toLatinDigits((string)$in['code']));
            if (mb_strlen($pcode) > self::CODE_MAX) { return ['ok' => false, 'message' => 'کدِ طرف‌حساب بیش از ' . self::CODE_MAX . ' نویسه است.']; }
            if ($pcode !== '') {
                $dup = Database::getConnection()->prepare('SELECT name FROM biz_parties WHERE user_id = :u AND code = :c AND id <> :id LIMIT 1');
                $dup->execute(['u' => $userId, 'c' => $pcode, 'id' => $id]);
                $other = $dup->fetchColumn();
                if ($other !== false) { return ['ok' => false, 'message' => 'کدِ «' . $pcode . '» مالِ «' . $other . '» است؛ کدِ طرف‌حساب یکتاست.']; }
            }
        }

        $pdo = Database::getConnection();
        $row = ['u' => $userId, 'n' => $name, 'k' => $kind, 'ph' => $phone === '' ? null : $phone,
                'a' => $address === '' ? null : $address, 'no' => $note === '' ? null : $note, 'ob' => $opening];
        if ($id > 0) {
            $cur = self::get($userId, $id);
            if (!$cur) { return ['ok' => false, 'message' => 'طرف‌حساب پیدا نشد.']; }
            // ⛔ مانده‌ی اول دوره پیش از همه‌ی اسناد است؛ طرف‌حسابی که در دوره‌ی
            //    بسته ساخته شده، دیگر عوضش نمی‌کند (نام و تلفن آزادند)
            // ⛔ و با قدیمی‌ترین سندِ همین طرف‌حساب (فاکتورِ تاریخ‌گذشته پیش از ساختش)
            if ((int)$cur['opening_balance'] !== $opening
                && ($e = Biz::lockError($userId, BizCommon::earliest([substr((string)$cur['created_at'], 0, 10),
                        self::firstDocDate($userId, $id)]), 'مانده‌ی اول دوره‌ی این طرف‌حساب')) !== null) {
                return ['ok' => false, 'message' => $e];
            }
            $pdo->prepare(
                'UPDATE biz_parties SET name = :n, kind = :k, phone = :ph, address = :a, note = :no, opening_balance = :ob
                 WHERE id = :id AND user_id = :u'
            )->execute($row + ['id' => $id]);
            if ((int)$cur['opening_balance'] !== $opening) { self::reallocate($pdo, $userId, $id); }
        } else {
            $pdo->prepare(
                'INSERT INTO biz_parties (user_id, name, kind, phone, address, note, opening_balance)
                 VALUES (:u, :n, :k, :ph, :a, :no, :ob)'
            )->execute($row);
            $id = (int)$pdo->lastInsertId();
        }
        // ⚠ فقط اگر فرم کدها را فرستاده باشد: مسیرِ ورود از فایل آن‌ها را ندارد
        //   و نباید کدِ ثبت‌شده را پاک کند.
        if (self::hasCodes() && array_intersect_key($in, array_flip(self::CODE_KEYS))) {
            $pdo->prepare('UPDATE biz_parties SET national_id = :ni, economic_code = :ec, postal_code = :pc WHERE id = :id AND user_id = :u')
                ->execute(['ni' => $codes['national_id'], 'ec' => $codes['economic_code'], 'pc' => $codes['postal_code'], 'id' => $id, 'u' => $userId]);
        }
        if ($hasPcode) {
            $pdo->prepare('UPDATE biz_parties SET code = :c WHERE id = :id AND user_id = :u')
                ->execute(['c' => $pcode === '' ? null : $pcode, 'id' => $id, 'u' => $userId]);
        }
        return ['ok' => true, 'message' => 'طرف‌حساب ذخیره شد.', 'id' => $id];
    }

    /**
     * ⛔ طرف‌حسابِ **مانده‌دار** غیرفعال نمی‌شود: طلب و بدهیِ داشبورد فقط
     *    طرف‌حسابِ فعال را می‌شمارند، پس طلبش بی‌صدا صفر دیده می‌شد
     *    (بازرسیِ مهر ۱۴۰۵). اول تسویه کنید.
     * @return array{ok:bool, message:string}
     */
    public static function setActive(int $userId, int $id, bool $active): array
    {
        $cur = self::get($userId, $id);
        if (!$cur) { return ['ok' => false, 'message' => 'طرف‌حساب پیدا نشد.']; }
        if (!$active && (int)($cur['balance'] ?? 0) !== 0) {
            return ['ok' => false, 'message' => 'این طرف‌حساب ' . formatMoney(abs((int)$cur['balance'])) . ' تومان مانده دارد؛'
                . ' غیرفعال کردنش آن را از طلب و بدهی بیرون می‌برد. اول تسویه کنید.'];
        }
        $st = Database::getConnection()->prepare('UPDATE biz_parties SET is_active = :a WHERE id = :id AND user_id = :u');
        $st->execute(['a' => $active ? 1 : 0, 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => $active ? 'طرف‌حساب فعال شد.' : 'طرف‌حساب غیرفعال شد.'];
    }

    /** حذف — طرف‌حسابی که فاکتور یا دریافت/پرداخت دارد فقط غیرفعال می‌شود. */
    public static function delete(int $userId, int $id): array
    {
        $chk = Database::getConnection()->prepare(
            'SELECT (SELECT COUNT(*) FROM biz_invoices WHERE party_id = :id AND user_id = :u)
                  + (SELECT COUNT(*) FROM biz_payments WHERE party_id = :id2 AND user_id = :u2)'
        );
        $chk->execute(['id' => $id, 'u' => $userId, 'id2' => $id, 'u2' => $userId]);
        if ((int)$chk->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'این طرف‌حساب سند دارد و حذف نمی‌شود؛ غیرفعالش کنید.'];
        }
        // ⛔ مانده‌ی اول دوره هم «سند» است — بازرسیِ مهر ۱۴۰۵: طلبِ ۵ میلیونیِ اول دوره که
        //    غیرفعال کردنش رد می‌شد، با حذف بی‌صدا صفر می‌شد.
        $ob = self::get($userId, $id);
        if ($ob && (int)$ob['opening_balance'] !== 0) {
            return ['ok' => false, 'message' => 'این طرف‌حساب مانده‌ی اول دوره دارد و حذف نمی‌شود؛ اول مانده را صفر کنید.'];
        }
        $st = Database::getConnection()->prepare('DELETE FROM biz_parties WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'طرف‌حساب حذف شد.']
            : ['ok' => false, 'message' => 'طرف‌حساب پیدا نشد.'];
    }

    /** @return array{count:int, receivable:int, payable:int, debtors:int, creditors:int} */
    public static function summary(int $userId): array
    {
        // ⛔ مانده **یک بار** برای هر طرف‌حساب حساب می‌شود و جمع در PHP است.
        //    نسخه‌ی قبلی `BALANCE_SQL` را چهار بار در یک `SELECT` می‌نوشت
        //    (طلب، بدهی، شمارِ بدهکار، شمارِ بستانکار) و MariaDB هر چهار را
        //    — هر کدام دو زیرکوئریِ همبسته — جدا اجرا می‌کرد: روی ۸۰ طرف‌حساب
        //    و ۱۵۰۰ فاکتور **۲۳ میلی‌ثانیه** و ۱۴٬۲۱۴ ردیف، روی داشبورد و
        //    فهرستِ طرف‌حساب‌ها هر دو. اندازه‌گیری شد (صفحه‌ی داشبورد را کُند
        //    کرده بود)؛ حالا ~۴ ms.
        $st = Database::getConnection()->prepare(
            'SELECT p.is_active AS a, ' . self::BALANCE_SQL . ' AS b FROM biz_parties p WHERE p.user_id = :u'
        );
        $st->execute(['u' => $userId]);
        $out = ['count' => 0, 'receivable' => 0, 'payable' => 0, 'debtors' => 0, 'creditors' => 0];
        // ⛔ طرف‌حسابِ غیرفعالی که مانده دارد هم شمرده می‌شود: غیرفعال کردن فقط برای مانده‌ی
        //    صفر مجاز است، ولی چکِ برگشتی، ابطالِ پرداخت یا فرمِ تبِ قدیمی بعد از آن مانده
        //    می‌سازند — بازرسیِ مهر ۱۴۰۵: طلبِ ۱۰۰۰ واقعی بود و «طلب» صفر نشان داده می‌شد.
        foreach ($st->fetchAll() as $r) {
            $b = (int)$r['b'];
            if ((int)$r['a'] !== 1 && $b === 0) { continue; }
            $out['count']++;
            if ($b > 0) { $out['receivable'] += $b; $out['debtors']++; }
            elseif ($b < 0) { $out['payable'] -= $b; $out['creditors']++; }
        }
        return $out;
    }
}

/* =================================================================
   صندوق و حساب‌های فروشگاه
   ================================================================= */
final class BizCash
{
    /** قدیمی‌ترین دریافت/پرداختِ این حساب ('' اگر هیچ). */
    public static function firstPayDate(int $userId, int $accountId): string
    {
        $st = Database::getConnection()->prepare(
            "SELECT MIN(pay_date) FROM biz_payments WHERE user_id = :u AND status = 'ok' AND (account_id = :a OR to_account_id = :a2)"
        );
        $st->execute(['u' => $userId, 'a' => $accountId, 'a2' => $accountId]);
        return (string)($st->fetchColumn() ?: '');
    }

    public const KINDS = [
        'cash' => 'صندوق نقدی',
        'bank' => 'حساب بانکی',
        'pos'  => 'کارت‌خوان',
        'cheque_in'  => 'چک‌های دریافتیِ در جریان',
        'cheque_out' => 'چک‌های پرداختیِ در جریان',
        // ⛔ سیستمی: تهاترِ معاوضه (`BizTradeIn`) — هر پول‌آمد با پول‌رفتِ هم‌مبلغِ جفتش، پس همیشه صفر
        'clearing'   => 'تهاتر (معاوضه)',
    ];

    /** صندوقِ سیستمیِ تهاتر — انتخاب‌شدنی نیست (`isSystem()`). */
    public const CLEARING_KIND = 'clearing';

    /**
     * ⛔ نوع‌هایی که کاربر خودش می‌سازد. دو نوعِ چک را فقط
     *    `chequeAccount()` می‌سازد: «پولی» که در آن‌هاست هنوز نقد نیست — در
     *    جمعِ نقدِ فروشگاه نمی‌آید و در فهرستِ انتخابِ صندوق هم نیست.
     */
    public const USER_KINDS = ['cash', 'bank', 'pos'];
    public const CHEQUE_KINDS = ['in' => 'cheque_in', 'out' => 'cheque_out'];

    public const NAME_MAX = 80;

    /**
     * صندوقِ نگه‌دارنده‌ی چک — `in` دریافتی، `out` پرداختی. اگر نیست ساخته و
     * اگر غیرفعال شده دوباره فعال می‌شود (دریافتِ چک به صندوقِ غیرفعال رد می‌شد).
     */
    public static function chequeAccount(PDO $pdo, int $userId, string $dir): int
    {
        $kind = self::CHEQUE_KINDS[$dir] ?? 'cheque_in';
        $st = $pdo->prepare('SELECT id, is_active FROM biz_accounts WHERE user_id = :u AND kind = :k ORDER BY id LIMIT 1 FOR UPDATE');
        $st->execute(['u' => $userId, 'k' => $kind]);
        $row = $st->fetch();
        if ($row) {
            if ((int)$row['is_active'] !== 1) {
                $pdo->prepare('UPDATE biz_accounts SET is_active = 1 WHERE id = :id AND user_id = :u')->execute(['id' => (int)$row['id'], 'u' => $userId]);
            }
            return (int)$row['id'];
        }
        $pdo->prepare('INSERT INTO biz_accounts (user_id, name, kind, sort_order) VALUES (:u, :n, :k, 90)')
            ->execute(['u' => $userId, 'n' => self::KINDS[$kind], 'k' => $kind]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * صندوقِ تهاترِ معاوضه — اگر نیست ساخته و اگر غیرفعال شده دوباره فعال می‌شود
     * (همان الگوی `chequeAccount()`). فقط `BizTradeIn::run()`.
     */
    public static function clearingAccount(PDO $pdo, int $userId): int
    {
        $st = $pdo->prepare('SELECT id, is_active FROM biz_accounts WHERE user_id = :u AND kind = :k ORDER BY id LIMIT 1 FOR UPDATE');
        $st->execute(['u' => $userId, 'k' => self::CLEARING_KIND]);
        $row = $st->fetch();
        if ($row) {
            if ((int)$row['is_active'] !== 1) {
                $pdo->prepare('UPDATE biz_accounts SET is_active = 1 WHERE id = :id AND user_id = :u')->execute(['id' => (int)$row['id'], 'u' => $userId]);
            }
            return (int)$row['id'];
        }
        $pdo->prepare('INSERT INTO biz_accounts (user_id, name, kind, sort_order) VALUES (:u, :n, :k, 95)')
            ->execute(['u' => $userId, 'n' => self::KINDS[self::CLEARING_KIND], 'k' => self::CLEARING_KIND]);
        return (int)$pdo->lastInsertId();
    }

    /** صندوقِ سیستمی (چک یا تهاتر) — در منوی انتخابِ صندوق نمی‌آید. */
    public static function isSystem(array $a): bool
    {
        return self::isCheque($a) || (string)$a['kind'] === self::CLEARING_KIND;
    }

    public static function isCheque(array $a): bool
    {
        return in_array((string)$a['kind'], self::CHEQUE_KINDS, true);
    }

    /**
     * ⛔ تنها تعریفِ «موجودیِ یک صندوق» — داشبورد، فهرستِ صندوق‌ها، گردشِ حساب
     *    و شمارشِ صندوق (`BizCashCount::balanceAt()`) همه از همین. موجودیِ اولیه
     *    + نوع‌های `BizPay::IN_KINDS` − بقیه، و انتقال از این صندوق منفی و به این
     *    صندوق مثبت؛ فقط باطل‌نشده‌ها. ⛔ هیچ‌چیز از `walletBalances()` (دفترِ شخصی)
     *    اینجا نیست.
     *
     * ⛔ فهرستِ «پول می‌آید» از `BizPay::IN_KINDS` ساخته می‌شود، نه دست‌نویس:
     *    نسخه‌ی قبلی (ثابتِ `BALANCE_SQL`) و کپیِ تاریخ‌دارش در شمارشِ صندوق
     *    هر کدام فهرستِ خودشان را داشتند. `$dated` = «تا پایانِ یک روز» با دو
     *    پارامترِ `:bal_d1` و `:bal_d2` (فراخواننده هر دو را می‌دهد).
     */
    public static function balanceSql(bool $dated = false): string
    {
        if (!class_exists('BizPay', false)) { require_once __DIR__ . '/biz_docs.php'; }
        $in = BizPay::sqlList(BizPay::IN_KINDS);
        $d1 = $dated ? ' AND bp.pay_date <= :bal_d1' : '';
        $d2 = $dated ? ' AND bp.pay_date <= :bal_d2' : '';
        return "(a.opening_balance
        + COALESCE((SELECT SUM(CASE WHEN bp.kind IN ({$in}) THEN bp.amount ELSE -bp.amount END)
                    FROM biz_payments bp WHERE bp.account_id = a.id AND bp.user_id = a.user_id AND bp.status = 'ok'{$d1}), 0)
        + COALESCE((SELECT SUM(bp.amount) FROM biz_payments bp
                    WHERE bp.to_account_id = a.id AND bp.user_id = a.user_id AND bp.kind = 'transfer' AND bp.status = 'ok'{$d2}), 0))";
    }

    /**
     * فهرستِ صندوق‌ها با موجودی. اگر فروشگاه هنوز هیچ صندوقی ندارد، یک
     * «صندوق» ساخته می‌شود — فروشگاهِ بی‌صندوق جایی برای نشستنِ پول ندارد.
     * ⚠ این هرگز چیزی را که کاربر حذف کرده زنده نمی‌کند: آخرین صندوقِ فعال
     *   غیرفعال‌شدنی نیست (`setActive()`)، پس «صفر ردیف» فقط بارِ اول است.
     */
    /**
     * `$asOf` = موجودیِ تا پایانِ آن روز. ⛔ داشبورد با امروز می‌خواند: «نقدِ امروز» و
     * «نقدِ اولِ ماه» از همین عدد منهای گردشِ تا امروز ساخته می‌شوند، و پرداختِ تاریخ‌آینده
     * (تا یک سال جلوتر پذیرفته است) آن را از قبل می‌شمرد — بازرسیِ محاسباتی (مهر ۱۴۰۵).
     */
    public static function list(int $userId, bool $activeOnly = false, ?string $asOf = null): array
    {
        $rows = self::fetch($userId, $activeOnly, $asOf);
        if (!$rows && !$activeOnly) {
            Database::getConnection()->prepare(
                "INSERT INTO biz_accounts (user_id, name, kind) VALUES (:u, 'صندوق', 'cash')"
            )->execute(['u' => $userId]);
            $rows = self::fetch($userId, false, $asOf);
        }
        return $rows;
    }

    private static function fetch(int $userId, bool $activeOnly, ?string $asOf = null): array
    {
        $bal = self::balanceSql($asOf !== null);
        $st  = Database::getConnection()->prepare(
            "SELECT a.*, {$bal} AS balance FROM biz_accounts a WHERE a.user_id = :u"
            . ($activeOnly ? ' AND a.is_active = 1' : '')
            . ' ORDER BY a.is_active DESC, a.sort_order, a.id'
        );
        $st->execute(['u' => $userId] + ($asOf !== null ? ['bal_d1' => $asOf, 'bal_d2' => $asOf] : []));
        return $st->fetchAll();
    }

    /** @return array{ok:bool, message:string, id?:int} */
    public static function save(int $userId, array $in, int $id = 0): array
    {
        $name = BizCommon::line((string)($in['name'] ?? ''));
        $kind = (string)($in['kind'] ?? 'cash');
        $ob   = sanitizeAmount($in['opening_balance'] ?? '');
        if (($e = BizCommon::moneyError($ob, 'موجودیِ اولیه')) !== null) { return ['ok' => false, 'message' => $e]; }
        if ($name === '') { return ['ok' => false, 'message' => 'نامِ صندوق یا حساب الزامی است.']; }
        if (mb_strlen($name) > self::NAME_MAX) { return ['ok' => false, 'message' => 'نام بیش از ' . self::NAME_MAX . ' نویسه است.']; }
        if (!in_array($kind, self::USER_KINDS, true)) { return ['ok' => false, 'message' => 'نوعِ حساب معتبر نیست.']; }

        $pdo = Database::getConnection();
        if ($id > 0) {
            // ⛔ صندوقِ چک از فرم ویرایش نمی‌شود — نوعش تنها نشانه‌ی آن است
            $ck = $pdo->prepare('SELECT kind, opening_balance, created_at FROM biz_accounts WHERE id = :id AND user_id = :u');
            $ck->execute(['id' => $id, 'u' => $userId]);
            $cur = $ck->fetch() ?: ['kind' => '', 'opening_balance' => $ob, 'created_at' => ''];
            if (in_array((string)$cur['kind'], self::CHEQUE_KINDS, true) || (string)$cur['kind'] === self::CLEARING_KIND) {
                return ['ok' => false, 'message' => 'صندوقِ چک و تهاتر را خودِ برنامه نگه می‌دارد و ویرایش نمی‌شود.'];
            }
            if ((int)$cur['opening_balance'] !== $ob
                && ($e = Biz::lockError($userId, BizCommon::earliest([substr((string)$cur['created_at'], 0, 10),
                        self::firstPayDate($userId, $id)]), 'موجودیِ اولیه‌ی این حساب')) !== null) {
                return ['ok' => false, 'message' => $e];
            }
            $st = $pdo->prepare('UPDATE biz_accounts SET name = :n, kind = :k, opening_balance = :o WHERE id = :id AND user_id = :u');
            $st->execute(['n' => $name, 'k' => $kind, 'o' => $ob, 'id' => $id, 'u' => $userId]);
            $chk = $pdo->prepare('SELECT COUNT(*) FROM biz_accounts WHERE id = :id AND user_id = :u');
            $chk->execute(['id' => $id, 'u' => $userId]);
            if ((int)$chk->fetchColumn() === 0) { return ['ok' => false, 'message' => 'حساب پیدا نشد.']; }
        } else {
            $pdo->prepare('INSERT INTO biz_accounts (user_id, name, kind, opening_balance) VALUES (:u, :n, :k, :o)')
                ->execute(['u' => $userId, 'n' => $name, 'k' => $kind, 'o' => $ob]);
            $id = (int)$pdo->lastInsertId();
        }
        return ['ok' => true, 'message' => 'حساب ذخیره شد.', 'id' => $id];
    }

    /** ⛔ آخرین صندوقِ فعال غیرفعال نمی‌شود. */
    public static function setActive(int $userId, int $id, bool $active): array
    {
        $pdo = Database::getConnection();
        $ck  = $pdo->prepare('SELECT kind FROM biz_accounts WHERE id = :id AND user_id = :u');
        $ck->execute(['id' => $id, 'u' => $userId]);
        if (in_array((string)$ck->fetchColumn(), self::CHEQUE_KINDS, true)) {
            return ['ok' => false, 'message' => 'صندوقِ چک را خودِ برنامه نگه می‌دارد.'];
        }
        if (!$active) {
            // ⛔ صندوقِ چک «صندوق» نیست — با شمردنش آخرین صندوقِ نقدی غیرفعال‌شدنی می‌شد
            $st = $pdo->prepare("SELECT COUNT(*) FROM biz_accounts WHERE user_id = :u AND is_active = 1 AND id <> :id AND kind NOT IN ('cheque_in','cheque_out')");
            $st->execute(['u' => $userId, 'id' => $id]);
            if ((int)$st->fetchColumn() === 0) {
                return ['ok' => false, 'message' => 'فروشگاه دست‌کم یک صندوقِ فعال لازم دارد.'];
            }
        }
        // ⛔ حسابِ **موجودی‌دار** غیرفعال نمی‌شود: جمعِ نقدِ داشبورد فقط حسابِ
        //    فعال را می‌شمارد، پس پولش بی‌صدا ناپدید می‌شد (بازرسیِ مهر ۱۴۰۵).
        if (!$active) {
            $bl = $pdo->prepare('SELECT ' . self::balanceSql() . ' FROM biz_accounts a WHERE a.id = :id AND a.user_id = :u');
            $bl->execute(['id' => $id, 'u' => $userId]);
            $bal = (int)$bl->fetchColumn();
            if ($bal !== 0) {
                return ['ok' => false, 'message' => 'این حساب ' . formatMoney(abs($bal)) . ' تومان موجودی دارد؛'
                    . ' اول آن را به حسابِ دیگری انتقال دهید، بعد غیرفعالش کنید.'];
            }
        }
        $st = $pdo->prepare('UPDATE biz_accounts SET is_active = :a WHERE id = :id AND user_id = :u');
        $st->execute(['a' => $active ? 1 : 0, 'id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => $active ? 'حساب فعال شد.' : 'حساب غیرفعال شد.']
            : ['ok' => false, 'message' => 'حساب پیدا نشد.'];
    }

    /** ⛔ جمعِ **نقد** — چکِ در جریان هنوز پول نیست و اینجا نمی‌آید. */
    public static function total(array $rows): int
    {
        $t = 0;
        foreach ($rows as $r) {
            // ⛔ حسابِ غیرفعالی که مانده دارد هم پولِ واقعی است (برگشتِ وصول یا ابطالِ پرداخت
            //    بعد از غیرفعال شدن) — بازرسیِ مهر ۱۴۰۵: بانکِ غیرفعالِ −۵۰۰ از جمعِ نقد بیرون بود.
            if ((int)$r['is_active'] !== 1 && (int)$r['balance'] === 0) { continue; }
            if (!self::isCheque($r)) { $t += (int)$r['balance']; }
        }
        return $t;
    }
}

/* =================================================================
   نمایش — تکه‌های مشترکِ صفحه‌های store/
   ================================================================= */
final class BizView
{
    /** درصد برای نمایش: «۱۰٪»، «۹٫۵٪». */
    public static function pct(float $v): string
    {
        $t = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        return toPersianDigits(str_replace('.', '٫', $t)) . '٪';
    }

    /** مقدار با واحد. */
    public static function qty($q, string $unit): string
    {
        return formatQty($q) . ' ' . $unit;
    }

    /**
     * نوارِ صفحه‌بندی — لینک است نه جاوااسکریپت، و بقیه‌ی پارامترها را نگه
     * می‌دارد (همان قاعده‌ی `pagedUrl()`). با یک صفحه رندر نمی‌شود.
     */
    public static function pager(string $page, array $params, int $cur, int $pages, int $total): string
    {
        if ($pages <= 1) { return ''; }
        $url = function (int $p) use ($page, $params): string {
            $params['page'] = $p;
            return Biz::url($page) . '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
        };
        $out = '<nav class="st-pager" aria-label="صفحه‌بندی">';
        $out .= $cur > 1 ? '<a class="st-pager-btn" href="' . h($url($cur - 1)) . '">قبلی</a>' : '<span class="st-pager-btn is-off">قبلی</span>';
        $out .= '<span class="st-pager-info">صفحه‌ی ' . toPersianDigits((string)$cur) . ' از ' . toPersianDigits((string)$pages)
              . ' · ' . toPersianDigits((string)$total) . ' ردیف</span>';
        $out .= $cur < $pages ? '<a class="st-pager-btn" href="' . h($url($cur + 1)) . '">بعدی</a>' : '<span class="st-pager-btn is-off">بعدی</span>';
        return $out . '</nav>';
    }
}
