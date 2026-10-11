<?php
/**
 * ⛔ طراحیِ فاکتور و لوگوی فروشگاه — «فاکتورِ خودم با لوگوی خودم».
 *
 * سه کار، هر کدام یک جا:
 *   - گزینه‌ها و پیش‌فرض‌ها: `OPTIONS`/`FLAGS`/`TEXTS`/`ACCENTS` و `clean()`؛
 *     ذخیره فقط از `save()` (ستونِ `biz_settings.invoice_design`).
 *   - لوگو: `saveLogo()` تصویر را با GD **از نو می‌سازد** (نه فایلِ خامِ
 *     کاربر) و در `biz_logos` می‌گذارد؛ روی برگه با `data:` می‌نشیند.
 *   - برگه: `render()` تنها رندرکننده‌ی فاکتورِ چاپی است — فاکتورِ واقعی،
 *     نمونه (`sample()`) و پیش‌نمایشِ زنده‌ی طراح همه از همین.
 *
 * هندسه‌ی کاغذ (اندازه، جهت، حاشیه، قلم) همچنان فقط `Biz::printPrefs()` و
 * `BizPrint::geometry()` است؛ طراحی فقط **محتوا و ظاهرِ** فاکتور است.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

require_once __DIR__ . '/biz_print.php';

final class BizInvoiceDesign
{
    /** قالب‌ها — کلاسِ `pr-t-*` روی بدنه‌ی برگه. */
    public const TEMPLATES = [
        'classic' => 'کلاسیک — جدولِ خط‌دار',
        'modern'  => 'مدرن — نوارِ رنگی',
        'minimal' => 'ساده — بی‌خط',
        'formal'  => 'رسمی — کادرِ فروشنده و خریدار',
    ];

    /** ⛔ تنها مرجعِ گزینه‌های چندحالته؛ کلیدِ اول پیش‌فرض نیست — `DEFAULTS` است. */
    public const OPTIONS = [
        'template'  => self::TEMPLATES,
        'logo_pos'  => ['start' => 'راست', 'center' => 'وسط', 'end' => 'چپ'],
        'logo_size' => ['sm' => 'کوچک', 'md' => 'متوسط', 'lg' => 'بزرگ'],
    ];

    /** رنگ‌های آماده؛ هر `#rrggbb` دیگری هم پذیرفته است (`accent`). */
    public const ACCENTS = [
        '#111827' => 'مشکی',
        '#1e3a8a' => 'سرمه‌ای',
        '#0f766e' => 'سبزآبی',
        '#047857' => 'زمردی',
        '#b45309' => 'کهربایی',
        '#b91c1c' => 'قرمز',
        '#6d28d9' => 'بنفش',
    ];

    /** ⛔ تنها مرجعِ کلیدهای روشن/خاموش — گروه‌بندی فقط برای فرمِ طراح است. */
    public const FLAGS = [
        'سربرگ' => [
            'show_logo'    => 'لوگو',
            'show_shop'    => 'نامِ فروشگاه',
            'show_contact' => 'تلفن و نشانیِ فروشگاه',
            'show_codes'   => 'کدهای رسمیِ فروشگاه (شناسه‌ی ملی، کد اقتصادی…)',
            'show_date'    => 'تاریخ و ساعتِ چاپ',
        ],
        'خریدار' => [
            'show_party'       => 'نام، تلفن و نشانیِ طرف‌حساب',
            'show_party_codes' => 'کدهای رسمیِ طرف‌حساب',
        ],
        'ستون‌های جدول' => [
            'col_row'      => 'ردیف (#)',
            'col_sku'      => 'کدِ کالا',
            'col_note'     => 'شرحِ کالا و IMEI زیرِ نام',
            'col_unit'     => 'واحد',
            'col_discount' => 'تخفیفِ ردیف',
        ],
        'جمع‌ها' => [
            'show_words'   => 'مبلغِ کل به حروف',
            'show_paid'    => 'دریافت‌شده / پرداخت‌شده و مانده',
            'show_balance' => 'مانده‌ی قبلی و مانده‌ی کلِ طرف‌حساب',
        ],
        'پایینِ برگه' => [
            'show_note'   => 'یادداشتِ فاکتور',
            'show_sign'   => 'جای امضا',
            'show_stamp'  => 'جای مهرِ فروشگاه',
            'show_footer' => 'متنِ پای فاکتور (از تنظیماتِ فروشگاه)',
        ],
    ];

    /** متن‌های آزاد و سقفِ طولشان. خالی = متنِ پیش‌فرض. */
    public const TEXTS = [
        'title_sale'     => 60,
        'title_purchase' => 60,
        'terms'          => 600,
        'sign_seller'    => 40,
        'sign_buyer'     => 40,
    ];

    /**
     * ⛔ پیش‌فرض همان ظاهرِ فاکتورِ پیش از طراح است: نصبی که هنوز طراحی
     *    نکرده هیچ فرقی نمی‌بیند. شش کلیدی که در «تنظیمات چاپ» هم هستند
     *    (`PRINT_FALLBACK`) پیش‌فرضشان را از همان‌جا می‌گیرند تا وقتی طراحی
     *    ذخیره نشده؛ از اولین ذخیره، فاکتور فقط از طراحی می‌خواند.
     */
    public const DEFAULTS = [
        'template' => 'classic', 'logo_pos' => 'center', 'logo_size' => 'md', 'accent' => '#111827',
        'show_logo' => true, 'show_shop' => true, 'show_contact' => true, 'show_codes' => true, 'show_date' => true,
        'show_party' => true, 'show_party_codes' => true,
        'col_row' => true, 'col_sku' => false, 'col_note' => true, 'col_unit' => false, 'col_discount' => true,
        'show_words' => false, 'show_paid' => true, 'show_balance' => true,
        'show_note' => true, 'show_sign' => false, 'show_stamp' => false, 'show_footer' => true,
        'title_sale' => '', 'title_purchase' => '', 'terms' => '', 'sign_seller' => '', 'sign_buyer' => '',
    ];

    /** کلیدِ طراحی ← کلیدِ «تنظیمات چاپ»، فقط برای نصبِ هنوز‌طراحی‌نشده. */
    private const PRINT_FALLBACK = [
        'show_shop' => 'show_header', 'show_contact' => 'show_contact', 'show_codes' => 'show_contact',
        'show_date' => 'show_date', 'show_footer' => 'show_footer', 'show_sign' => 'show_sign',
        'show_balance' => 'show_balance',
    ];

    /** اندازه‌ی لوگو روی برگه (میلی‌متر ارتفاع). */
    public const LOGO_MM = ['sm' => 12, 'md' => 18, 'lg' => 26];

    /* ------------------------------------------------------------
       گزینه‌ها
       ------------------------------------------------------------ */

    /** @return string[] همه‌ی کلیدهای روشن/خاموش، بی‌گروه. */
    public static function flagKeys(): array
    {
        $out = [];
        foreach (self::FLAGS as $g) { $out = array_merge($out, array_keys($g)); }
        return $out;
    }

    public static function validAccent(string $v): ?string
    {
        $v = strtolower(trim($v));
        return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : null;
    }

    /**
     * ⛔ ورودیِ ناشناخته (JSONِ خراب، کلیدِ کهنه، رنگِ نامعتبر) بی‌صدا به
     *    پیش‌فرض برمی‌گردد، نه برگه‌ی شکسته.
     * @param bool $form ورودی از فرمِ چک‌باکسی است: کلیدِ نبوده یعنی «خاموش»
     */
    public static function clean(array $in, bool $form, array $printPrefs = []): array
    {
        $out = self::DEFAULTS;
        if (!$form) {
            foreach (self::PRINT_FALLBACK as $k => $pk) {
                if (array_key_exists($pk, $printPrefs)) { $out[$k] = (bool)$printPrefs[$pk]; }
            }
        }
        foreach (self::OPTIONS as $k => $opts) {
            if (isset($in[$k]) && is_string($in[$k]) && isset($opts[$in[$k]])) { $out[$k] = $in[$k]; }
        }
        if (isset($in['accent']) && is_string($in['accent'])) {
            $out['accent'] = self::validAccent($in['accent']) ?? $out['accent'];
        }
        foreach (self::flagKeys() as $k) {
            if ($form) { $out[$k] = !empty($in[$k]); }
            elseif (array_key_exists($k, $in)) { $out[$k] = (bool)$in[$k]; }
        }
        foreach (self::TEXTS as $k => $max) {
            if (!isset($in[$k]) || !is_string($in[$k])) { continue; }
            $v = $k === 'terms' ? trim(str_replace("\r\n", "\n", $in[$k])) : BizCommon::line($in[$k]);
            $out[$k] = mb_substr($v, 0, $max);
        }
        return $out;
    }

    /** طراحیِ ذخیره‌شده — بی‌کوئریِ تازه (از `Biz::settings()`). */
    public static function get(int $userId): array
    {
        $raw = Biz::invoiceDesignRaw($userId);
        $in  = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($in)) {
            // ⛔ ذخیره‌شده: کامل است و «تنظیمات چاپ» دیگر رویش اثر ندارد
            return self::clean($in, false, []);
        }
        return self::clean([], false, Biz::printPrefs($userId));
    }

    /** طراحی ذخیره شده است؟ — صفحه‌ی «تنظیمات چاپ» می‌گوید فاکتور از کجا می‌خواند. */
    public static function saved(int $userId): bool
    {
        return Biz::invoiceDesignRaw($userId) !== '';
    }

    public static function ready(): bool
    {
        static $r = null;
        if ($r !== null) { return $r; }
        try {
            Database::getConnection()->query('SELECT invoice_design FROM biz_settings LIMIT 0');
            Database::getConnection()->query('SELECT user_id FROM biz_logos LIMIT 0');
            return $r = true;
        } catch (PDOException $e) {
            return $r = false;
        }
    }

    /** @return array{ok:bool, message:string} */
    public static function save(int $userId, array $in): array
    {
        if (!self::ready()) {
            return ['ok' => false, 'message' => 'طراحیِ فاکتور هنوز راه نیفتاده است (migration_biz_invoice_design).'];
        }
        if (isset($in['accent']) && is_string($in['accent']) && trim($in['accent']) !== '' && self::validAccent($in['accent']) === null) {
            return ['ok' => false, 'message' => 'رنگ باید به شکلِ ‎#1e3a8a‎ باشد.'];
        }
        $clean = self::clean($in, true);
        Database::getConnection()->prepare(
            'INSERT INTO biz_settings (user_id, invoice_design) VALUES (:u, :d)
             ON DUPLICATE KEY UPDATE invoice_design = VALUES(invoice_design)'
        )->execute(['u' => $userId, 'd' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        Biz::forgetSettings($userId);
        return ['ok' => true, 'message' => 'طراحیِ فاکتور ذخیره شد.'];
    }

    /** برگشت به ظاهرِ پیش‌فرض — لوگو دست نمی‌خورد. */
    public static function reset(int $userId): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'طراحیِ فاکتور هنوز راه نیفتاده است.']; }
        Database::getConnection()->prepare('UPDATE biz_settings SET invoice_design = NULL WHERE user_id = :u')
            ->execute(['u' => $userId]);
        Biz::forgetSettings($userId);
        return ['ok' => true, 'message' => 'طراحیِ فاکتور به حالتِ پیش‌فرض برگشت.'];
    }

    /* ------------------------------------------------------------
       لوگو
       ------------------------------------------------------------ */

    /** ⛔ فهرستِ بسته — روی برگه فقط همین دو نوع با `data:` می‌نشیند. */
    public const LOGO_MIMES = ['image/png', 'image/jpeg'];

    /** قالب‌های ورودی (از **محتوا**، با finfo) — خروجی همیشه PNG یا JPEG است. */
    public const LOGO_INPUT = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public const LOGO_MAX_BYTES = 3 * 1024 * 1024;
    /** سقفِ ابعادِ ورودی — تصویرِ «بمب» حافظه‌ی PHP را تمام نکند. */
    private const LOGO_MAX_PIXELS = 30_000_000;
    /** کادرِ خروجی — برای چاپ تا ۲۶ میلی‌متر ارتفاع بس است. */
    private const LOGO_BOX = [800, 400];
    private const LOGO_MAX_STORED = 900 * 1024;

    /**
     * ⛔ فایلِ کاربر هرگز همان‌طور که آمده ذخیره نمی‌شود: نوع از محتوا
     *    (finfo)، ابعاد سنجیده، و تصویر با GD **از نو کشیده** می‌شود — هر
     *    چیزی کنارِ پیکسل‌ها (متادیتا، فایلِ چندزبانه) همان‌جا می‌ماند.
     *    شفافیت نگه داشته می‌شود (خروجی PNG)؛ عکسِ بی‌شفافیت JPEG می‌شود تا
     *    حجمِ هر برگه‌ی چاپی کم بماند.
     * @param array $file یک ردیفِ `$_FILES`
     * @return array{ok:bool, message:string}
     */
    public static function saveLogo(int $userId, array $file): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'لوگو هنوز راه نیفتاده است (migration_biz_invoice_design).']; }
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) { return ['ok' => false, 'message' => 'فایلی انتخاب نشده است.']; }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'message' => 'فایلِ لوگو بزرگ‌تر از حدِ مجاز است (حداکثر ۳ مگابایت).'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'message' => 'فایل دریافت نشد؛ دوباره امتحان کنید.'];
        }
        $bytes = (string)file_get_contents($tmp);
        return self::saveLogoBytes($userId, $bytes);
    }

    /** همان `saveLogo()` روی بایت‌ها — مسیرِ آزمودنی، بی‌`move_uploaded_file`. */
    public static function saveLogoBytes(int $userId, string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::LOGO_MAX_BYTES) {
            return ['ok' => false, 'message' => 'فایلِ لوگو خالی یا بزرگ‌تر از ۳ مگابایت است.'];
        }
        if (!function_exists('imagecreatefromstring')) {
            return ['ok' => false, 'message' => 'افزونه‌ی GDِ PHP روی سرور نیست.'];
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, self::LOGO_INPUT, true)) {
            return ['ok' => false, 'message' => 'فقط تصویرِ PNG، JPG، WEBP یا GIF پذیرفته است.'];
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::LOGO_MAX_PIXELS) {
            return ['ok' => false, 'message' => 'این فایل تصویرِ معتبری نیست یا ابعادش بیش از حد بزرگ است.'];
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return ['ok' => false, 'message' => 'این قالبِ تصویر روی سرور خوانده نمی‌شود؛ PNG یا JPG امتحان کنید.'];
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, self::LOGO_BOX[0] / $w, self::LOGO_BOX[1] / $h);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $alpha = $mime !== 'image/jpeg';
        $dst = imagecreatetruecolor($nw, $nh);
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        } else {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        ob_start();
        $okOut = $alpha ? imagepng($dst, null, 9) : imagejpeg($dst, null, 88);
        $out = (string)ob_get_clean();
        imagedestroy($dst);
        if (!$okOut || $out === '') { return ['ok' => false, 'message' => 'تصویر ساخته نشد؛ فایلِ دیگری امتحان کنید.']; }
        $b64 = base64_encode($out);
        if (strlen($b64) > self::LOGO_MAX_STORED) {
            return ['ok' => false, 'message' => 'لوگو پس از کوچک‌سازی هم بزرگ است؛ تصویرِ ساده‌تری بدهید.'];
        }
        Database::getConnection()->prepare(
            'INSERT INTO biz_logos (user_id, mime, data, width, height) VALUES (:u, :m, :d, :w, :h)
             ON DUPLICATE KEY UPDATE mime = VALUES(mime), data = VALUES(data), width = VALUES(width), height = VALUES(height)'
        )->execute(['u' => $userId, 'm' => $alpha ? 'image/png' : 'image/jpeg', 'd' => $b64, 'w' => $nw, 'h' => $nh]);
        return ['ok' => true, 'message' => 'لوگو ذخیره شد.'];
    }

    public static function removeLogo(int $userId): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'لوگو هنوز راه نیفتاده است.']; }
        $st = Database::getConnection()->prepare('DELETE FROM biz_logos WHERE user_id = :u');
        $st->execute(['u' => $userId]);
        return $st->rowCount() > 0 ? ['ok' => true, 'message' => 'لوگو برداشته شد.'] : ['ok' => false, 'message' => 'لوگویی ثبت نشده بود.'];
    }

    /**
     * لوگوی همین فروشگاه، یا null.
     * ⛔ روی خواندن هم سنجیده می‌شود (نوعِ بسته + الفبای base64): ردیف
     *    می‌تواند از فایلِ بکاپِ دست‌کاری‌شده آمده باشد، نه از `saveLogo()`.
     * @return array{mime:string, data:string, width:int, height:int}|null
     */
    public static function logo(int $userId): ?array
    {
        try {
            $st = Database::getConnection()->prepare('SELECT mime, data, width, height FROM biz_logos WHERE user_id = :u LIMIT 1');
            $st->execute(['u' => $userId]);
            $r = $st->fetch();
        } catch (PDOException $e) {
            return null;    // جدول هنوز نیامده
        }
        if (!$r || !in_array($r['mime'], self::LOGO_MIMES, true) || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', (string)$r['data'])) {
            return null;
        }
        return ['mime' => (string)$r['mime'], 'data' => (string)$r['data'], 'width' => (int)$r['width'], 'height' => (int)$r['height']];
    }

    public static function dataUri(array $logo): string
    {
        return 'data:' . $logo['mime'] . ';base64,' . $logo['data'];
    }

    /* ------------------------------------------------------------
       برگه
       ------------------------------------------------------------ */

    /** فاکتورِ نمونه برای پیش‌نمایشِ طراح — وقتی هنوز فاکتوری صادر نشده. */
    public static function sample(): array
    {
        $lines = [
            ['description' => 'گوشی موبایل نمونه ۱۲۸ گیگ', 'sku' => 'PH-128', 'unit' => 'عدد', 'qty' => '1', 'unit_price' => 18500000, 'line_discount' => 500000, 'line_total' => 18000000, 'imei1' => '356789012345678', 'imei2' => '', 'note' => ''],
            ['description' => 'گلس و قاب محافظ', 'sku' => 'AC-07', 'unit' => 'عدد', 'qty' => '2', 'unit_price' => 350000, 'line_discount' => 0, 'line_total' => 700000, 'imei1' => '', 'imei2' => '', 'note' => 'مشکی'],
            ['description' => 'نصب و انتقالِ اطلاعات', 'sku' => '', 'unit' => 'خدمت', 'qty' => '1', 'unit_price' => 300000, 'line_discount' => 0, 'line_total' => 300000, 'imei1' => '', 'imei2' => '', 'note' => ''],
        ];
        return [
            'id' => 0, 'kind' => 'sale', 'status' => 'issued', 'number' => 1024,
            'inv_date' => date('Y-m-d'), 'due_date' => null,
            'party_id' => 0, 'party_name' => 'مشتریِ نمونه', 'party_phone' => '09120000000', 'party_address' => 'تهران، خیابانِ نمونه',
            'party_national_id' => '', 'party_economic_code' => '', 'party_postal_code' => '',
            'ref_invoice_id' => null, 'ref_number' => null,
            'subtotal' => 19000000, 'discount' => 200000, 'extra' => 0, 'total' => 18800000, 'paid' => 15000000,
            'note' => 'گارانتیِ ۱۸ ماهه‌ی شرکتی.', 'lines' => $lines,
        ];
    }

    /** عنوانِ برگه — عنوانِ دلخواهِ فروش/خرید جای نامِ نوع می‌نشیند، شماره می‌ماند. */
    public static function title(array $inv, array $d): string
    {
        if (self::isProforma($inv)) { return 'پیش‌فاکتور'; }
        $custom = $inv['kind'] === 'sale' ? $d['title_sale'] : ($inv['kind'] === 'purchase' ? $d['title_purchase'] : '');
        return $custom !== '' ? $custom : (BizInvoices::KINDS[$inv['kind']] ?? 'فاکتور');
    }

    /** پیش‌نویسِ پیش‌فاکتوردار (`BizInvoices::markProforma()`) — با عنوان و شماره‌ی خودش چاپ می‌شود. */
    public static function isProforma(array $inv): bool
    {
        return ($inv['status'] ?? '') === 'draft' && !empty($inv['proforma_no']);
    }

    /**
     * ⛔ تنها رندرکننده‌ی فاکتورِ چاپی. همه‌ی متن‌ها با `h()`؛ نامِ کالا،
     *    شرح، عنوان و شرایط را کاربر نوشته.
     * @param array{back:string, embed?:bool, sample?:bool, balance?:?array, logo?:?array} $opt
     */
    public static function render(int $userId, array $inv, array $d, array $opt): void
    {
        $p    = Biz::printPrefs($userId);
        $set  = Biz::settings($userId);
        $geo  = BizPrint::geometry($p);
        $roll = BizPrint::isRoll($p);
        $shop = $set['shop_name'] !== '' ? $set['shop_name'] : Auth::fullName();
        $embed  = !empty($opt['embed']);
        $logo   = $d['show_logo'] ? ($opt['logo'] ?? null) : null;
        $sale   = in_array($inv['kind'], ['sale', 'sale_return'], true);
        $title  = self::title($inv, $d);
        $void   = ($inv['status'] ?? '') === 'void';
        $money  = static fn($v): string => '<span class="pr-num">' . formatMoney((int)$v) . '</span>';
        $codes  = $d['show_codes'] ? array_filter(array_intersect_key($set, array_flip(Biz::SETTING_CODES)), fn($v) => $v !== '') : [];
        $logoPos = $roll ? 'center' : $d['logo_pos'];
        $cols   = ['row' => $d['col_row'], 'sku' => $d['col_sku'], 'unit' => $d['col_unit'], 'disc' => $d['col_discount']];
        $partyLabel = $sale ? 'خریدار' : 'فروشنده';
        $party  = [
            'نام'        => $inv['party_id'] !== null ? (string)$inv['party_name'] : 'گذری',
            'تلفن'       => (string)($inv['party_phone'] ?? ''),
            'نشانی'      => (string)($inv['party_address'] ?? ''),
        ];
        if ($d['show_party_codes']) {
            $party += [
                'کد اقتصادی'  => (string)($inv['party_economic_code'] ?? ''),
                'شناسه‌ی ملی' => (string)($inv['party_national_id'] ?? ''),
                'کد پستی'     => (string)($inv['party_postal_code'] ?? ''),
            ];
        }
        $party = array_filter($party, fn($v) => $v !== '');
        $pro = self::isProforma($inv);
        $docInfo = array_filter([
            'شماره'        => $pro ? toPersianDigits((string)$inv['proforma_no'])
                            : ($inv['number'] !== null ? toPersianDigits((string)$inv['number']) : ''),
            'اعتبار تا'    => $pro && !empty($inv['proforma_until']) ? toJalali((string)$inv['proforma_until']) : '',
            'پیش‌فاکتور'   => !$pro && !empty($inv['proforma_no']) ? toPersianDigits((string)$inv['proforma_no']) : '',
            'تاریخ'        => toJalali((string)$inv['inv_date']),
            'سررسید'       => !empty($inv['due_date']) ? toJalali((string)$inv['due_date']) : '',
            'فاکتورِ اصلی' => ($inv['ref_invoice_id'] ?? null) !== null ? toPersianDigits((string)$inv['ref_number']) : '',
        ], fn($v) => $v !== '');
        $signs = $sale
            ? [$d['sign_seller'] !== '' ? $d['sign_seller'] : 'امضای فروشنده', $d['sign_buyer'] !== '' ? $d['sign_buyer'] : 'امضای خریدار']
            : [$d['sign_buyer'] !== '' ? $d['sign_buyer'] : 'امضای تحویل‌گیرنده', $d['sign_seller'] !== '' ? $d['sign_seller'] : 'امضای فروشنده'];
        ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title><?= h($title . ($pro ? ' ' . toPersianDigits((string)$inv['proforma_no']) : ($inv['number'] !== null ? ' ' . toPersianDigits((string)$inv['number']) : '')) . ' · ' . $shop) ?></title>
    <?php foreach (assetUrls(['css/store-print.css']) as $u): ?>
    <link rel="stylesheet" href="<?= h($u) ?>">
    <?php endforeach; ?>
    <style><?= $geo['css'] ?>
:root { --pr-accent: <?= $d['accent'] ?>; --pr-logo-h: <?= self::LOGO_MM[$d['logo_size']] ?>mm; }</style>
</head>
<body class="pr-body pr-inv pr-t-<?= h($d['template']) ?><?= $roll ? ' pr-roll' : '' ?><?= $embed ? ' pr-embed' : '' ?>" data-paper="<?= h((string)$p['paper']) ?>">
<?php if (!$embed): ?>
<div class="pr-bar">
    <button type="button" class="pr-btn pr-btn-main" onclick="window.print()">چاپ</button>
    <a class="pr-btn" href="<?= h($opt['back']) ?>">بازگشت</a>
    <a class="pr-btn" href="<?= h(Biz::url('invoice-design.php')) ?>">طراحیِ فاکتور</a>
    <a class="pr-btn" href="<?= h(Biz::url('print-settings.php')) ?>">تنظیماتِ چاپ</a>
    <span class="pr-bar-note"><?= h(Biz::PRINT_OPTIONS['paper'][$p['paper']]) ?><?= $roll ? '' : ' · ' . h(Biz::PRINT_OPTIONS['orient'][$p['orient']]) ?></span>
</div>
<?php endif; ?>
<article class="pr-sheet">
    <?php if ($logo !== null || $d['show_shop'] || ($d['show_contact'] && ($set['phone'] !== '' || $set['address'] !== '')) || $codes): ?>
    <header class="pr-ihead pr-logo-<?= h($logoPos) ?>">
        <?php if ($logo !== null): ?><img class="pr-logo" src="<?= h(self::dataUri($logo)) ?>" alt="لوگوی <?= h($shop) ?>"><?php endif; ?>
        <div class="pr-ishop">
            <?php if ($d['show_shop']): ?><h1 class="pr-shop"><?= h($shop) ?></h1><?php endif; ?>
            <?php if ($d['show_contact'] && ($set['phone'] !== '' || $set['address'] !== '')): ?>
            <p class="pr-contact">
                <?php if ($set['phone'] !== ''): ?><span>تلفن: <span class="pr-num" dir="ltr"><?= h($set['phone']) ?></span></span><?php endif; ?>
                <?php if ($set['address'] !== ''): ?><span><?= h($set['address']) ?></span><?php endif; ?>
            </p>
            <?php endif; ?>
            <?php if ($codes): ?>
            <p class="pr-contact pr-codes">
                <?php foreach ($codes as $ck => $cv): ?><span><?= h(BizCommon::CODES[$ck][2] ?? $ck) ?>: <span class="pr-num" dir="ltr"><?= h($cv) ?></span></span><?php endforeach; ?>
            </p>
            <?php endif; ?>
        </div>
    </header>
    <?php endif; ?>

    <div class="pr-ititle">
        <h2 class="pr-title"><?= h($title) ?><?= $void ? ' <span class="pr-void">(باطل)</span>' : '' ?></h2>
        <dl class="pr-idoc">
            <?php foreach ($docInfo as $k => $v): ?><div><dt><?= h($k) ?></dt><dd class="pr-num"><?= h($v) ?></dd></div><?php endforeach; ?>
        </dl>
    </div>
    <?php if ($d['show_date']): ?><p class="pr-printed">چاپ: <?= h(toJalali(date('Y-m-d'))) ?> · <?= h(toPersianDigits(date('H:i'))) ?></p><?php endif; ?>

    <?php if ($d['show_party'] && $party): ?>
    <section class="pr-parties">
        <?php if ($d['template'] === 'formal'): ?>
        <div class="pr-pbox">
            <h3><?= $sale ? 'فروشنده' : 'خریدار' ?></h3>
            <dl class="pr-meta">
                <div><dt>نام</dt><dd><?= h($shop) ?></dd></div>
                <?php if ($set['phone'] !== ''): ?><div><dt>تلفن</dt><dd class="pr-num" dir="ltr"><?= h($set['phone']) ?></dd></div><?php endif; ?>
                <?php if ($set['address'] !== ''): ?><div><dt>نشانی</dt><dd><?= h($set['address']) ?></dd></div><?php endif; ?>
                <?php foreach (array_filter(array_intersect_key($set, array_flip(Biz::SETTING_CODES)), fn($v) => $v !== '') as $ck => $cv): ?>
                <div><dt><?= h(BizCommon::CODES[$ck][2] ?? $ck) ?></dt><dd class="pr-num" dir="ltr"><?= h($cv) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php endif; ?>
        <div class="pr-pbox">
            <h3><?= $partyLabel ?></h3>
            <dl class="pr-meta">
                <?php foreach ($party as $k => $v): ?><div><dt><?= h($k) ?></dt><dd><?= h($v) ?></dd></div><?php endforeach; ?>
            </dl>
        </div>
    </section>
    <?php endif; ?>

    <div class="pr-scroll"><table class="pr-table pr-itable">
        <thead><tr>
            <?php if ($cols['row']): ?><th class="pr-c-row">#</th><?php endif; ?>
            <?php if ($cols['sku']): ?><th class="pr-wide">کد</th><?php endif; ?>
            <th>شرحِ کالا و خدمات</th>
            <?php if ($cols['unit']): ?><th class="pr-wide">واحد</th><?php endif; ?>
            <th class="pr-c-num">تعداد</th>
            <th class="pr-c-num">فی</th>
            <?php if ($cols['disc']): ?><th class="pr-c-num pr-wide">تخفیف</th><?php endif; ?>
            <th class="pr-c-num">جمع</th>
        </tr></thead>
        <tbody>
        <?php foreach ($inv['lines'] as $n => $l): ?>
            <tr>
                <?php if ($cols['row']): ?><td class="pr-c-row"><span class="pr-num"><?= toPersianDigits((string)($n + 1)) ?></span></td><?php endif; ?>
                <?php if ($cols['sku']): ?><td class="pr-wide pr-c-sku"><span class="pr-num" dir="ltr"><?= h((string)($l['sku'] ?? '')) ?></span></td><?php endif; ?>
                <td><?= h((string)$l['description']) ?><?= $d['col_note'] ? BizDocView::lineSub($l, 'pr-imei') : '' ?></td>
                <?php if ($cols['unit']): ?><td class="pr-wide"><?= h((string)($l['unit'] ?? '')) ?></td><?php endif; ?>
                <td class="pr-c-num"><span class="pr-num"><?= h(formatQty($l['qty'])) ?></span></td>
                <td class="pr-c-num"><?= $money($l['unit_price']) ?></td>
                <?php if ($cols['disc']): ?><td class="pr-c-num pr-wide"><?= (int)$l['line_discount'] > 0 ? $money($l['line_discount']) : '' ?></td><?php endif; ?>
                <td class="pr-c-num"><?= $money($l['line_total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>

    <div class="pr-ifoot">
        <div class="pr-ileft">
            <?php if ($d['show_words']): ?>
            <p class="pr-words"><span>مبلغِ کل به حروف:</span> <?= h(BizCommon::words((int)$inv['total'])) ?> تومان</p>
            <?php endif; ?>
            <?php if ($d['show_note'] && trim((string)($inv['note'] ?? '')) !== ''): ?><p class="pr-note"><?= nl2br(h((string)$inv['note'])) ?></p><?php endif; ?>
            <?php if ($d['terms'] !== ''): ?><div class="pr-terms"><?= nl2br(h($d['terms'])) ?></div><?php endif; ?>
        </div>
        <div class="pr-totals">
            <?php $prTax = (int)($inv['tax_total'] ?? 0); ?>
            <?php if ((int)$inv['discount'] > 0 || (int)$inv['extra'] > 0 || $prTax > 0): ?>
            <div><span>جمعِ ردیف‌ها</span><span><?= $money($inv['subtotal']) ?></span></div>
            <?php if ((int)$inv['discount'] > 0): ?><div><span>تخفیف</span><span>−<?= $money($inv['discount']) ?></span></div><?php endif; ?>
            <?php if ((int)$inv['extra'] > 0): ?><div><span>حمل و هزینه‌ی دیگر</span><span><?= $money($inv['extra']) ?></span></div><?php endif; ?>
            <?php if ($prTax > 0): /* ⛔ مالیات بر ارزش افزوده — سطرِ جدا، با نرخِ خودِ سند */ ?>
            <div><span>مالیات بر ارزش افزوده (<?= h(BizView::pct((float)($inv['vat_rate'] ?? 0))) ?>)</span><span><?= $money($prTax) ?></span></div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="pr-grand"><span>مبلغِ کل</span><span><?= $money($inv['total']) ?> تومان</span></div>
            <?php if ($d['show_paid'] && $inv['status'] === 'issued'): ?>
            <div><span><?= BizInvoices::SETTLED_BY[$inv['kind']] === 'receipt' ? 'دریافت‌شده' : 'پرداخت‌شده' ?></span><span><?= $money($inv['paid']) ?></span></div>
            <div><span>مانده</span><span><?= $money(BizInvoices::remaining($inv)) ?></span></div>
            <?php endif; ?>
            <?php if ($d['show_balance'] && !empty($opt['balance'])) { echo BizDocView::balanceRows($opt['balance'], (string)$inv['party_name'], $money, 'pr-bal'); } ?>
        </div>
    </div>

    <?php if ($d['show_sign'] || $d['show_stamp']): ?>
    <div class="pr-signs">
        <?php if ($d['show_sign']): foreach ($signs as $s): ?><div class="pr-sign"><?= h($s) ?></div><?php endforeach; endif; ?>
        <?php if ($d['show_stamp']): ?><div class="pr-sign pr-stamp">محلِ مهرِ فروشگاه</div><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($d['show_footer'] && $set['invoice_footer'] !== ''): ?>
    <footer class="pr-foot"><?= nl2br(h($set['invoice_footer'])) ?></footer>
    <?php endif; ?>
</article>
<?php if ($embed): ?>
<script>
/* پیش‌نمایشِ طراح: برگه به پهنای قاب کوچک می‌شود، نه اسکرولِ افقی */
(function () {
    var s = document.querySelector('.pr-sheet');
    function fit() { var w = s.offsetWidth + 24; document.documentElement.style.zoom = String(Math.min(1, window.innerWidth / w)); }
    fit(); window.addEventListener('resize', fit);
})();
</script>
<?php endif; ?>
</body>
</html>
        <?php
    }
}
