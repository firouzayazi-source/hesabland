<?php
/**
 * ⛔ ورودِ اطلاعات از نرم‌افزارِ حسابداریِ دیگر — «سندِ افتتاحیه».
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «امکان اضافه کردن کالا از اکسل هست اما
 * اشخاص هم داریم … استاندارد کن که اگه کسی خواست از برنامه‌ی حسابداریِ دیگه‌ای
 * وارد بشه بتونه تمام دیتای خودش رو بیاره.» تصمیم: **اطلاعاتِ پایه + مانده‌ی
 * اول دوره** — همان روشِ مهاجرت در حسابفا، سپیدار و هلو. سابقه‌ی فاکتورها در
 * نرم‌افزارِ قبلی می‌ماند؛ حساب‌ها از امروز درست‌اند.
 *
 * چهار بخش، به همین ترتیب (هر کدام به قبلی تکیه دارد):
 *   ۱. اشخاص — کد، نام، نوع، تلفن، نشانی، کدهای رسمی، **مانده** (بدهکار/بستانکار)
 *   ۲. کالا — همان `BizImport` (یک موتورِ کالا، نه دو)
 *   ۳. صندوق و بانک — نام، نوع، موجودی
 *   ۴. چک‌های در جریان — دریافتی/پرداختی، با طرف‌حسابِ بخشِ ۱
 *
 * ⛔ نرم‌افزارِ مبدأ **معلوم نیست** (پاسخِ مالکِ نصب: «نمی‌دونم، اصلاً معلوم
 *    نیست»). پس دو لایه: فرهنگِ نامِ ستون‌ها برای خروجیِ رایجِ نرم‌افزارهای
 *    ایرانی (`ALIASES`)، و **تطبیقِ دستیِ هر ستون** در پیش‌نمایش (`$map`)
 *    برای هر چیزی که فرهنگ نشناخت. فایلِ هر برنامه‌ای وارد می‌شود.
 *
 * ⛔ مرزها — همان قاعده‌های ورودِ کالا:
 *    - پیش‌نمایش اجباری و **بی‌نوشتن** (`plan()`)؛ ثبت فقط با «ثبت نهایی».
 *    - هر نوشتن از همان راهِ فرم: `BizParties::save()`، `BizCash::save()`،
 *      `BizPay::createTx()` — هیچ INSERT/UPDATEِ مستقیمی روی جدولِ پول نیست
 *      (جز پرچمِ `opening_import` روی همان چکی که همین‌جا ساخته شد).
 *    - تطبیق فقط میانِ داده‌ی **همین** فروشگاه: شخص با کد بعد نام، صندوق با
 *      نام، چک با شماره+مبلغ+طرف‌حساب. ورودِ دوباره‌ی همان فایل تکرار نمی‌سازد.
 *    - ⛔ **مانده‌ی اول دوره‌ی شخص یا صندوقی که سند دارد دست نمی‌خورد.** فایلِ
 *      خروجیِ خودِ هستیکو مانده‌ی **امروز** را دارد؛ اگر دوباره وارد می‌شد و
 *      اول دوره را بازنویسی می‌کرد، همه‌ی فاکتورها دو بار شمرده می‌شدند.
 *    - چکِ در جریان مانده‌ی شخص را از قبل در نرم‌افزارِ قبلی کم کرده؛ پس
 *      مانده‌ی اول دوره به همان اندازه جبران می‌شود (`opening_import`،
 *      `BizParties::chequeComp()`) و اگر برگشت خورد بدهی درست برمی‌گردد.
 */

require_once __DIR__ . '/biz_io.php';
require_once __DIR__ . '/biz_docs.php';

final class BizMigrate
{
    public const MAX_ROWS = 2000;
    public const MAX_COLS = 40;

    /** بخش‌ها، به ترتیبِ پیشنهادیِ ورود. */
    public const ENTITIES = [
        'parties'  => 'اشخاص',
        'products' => 'کالا و موجودی',
        'accounts' => 'صندوق و بانک',
        'cheques'  => 'چک‌های در جریان',
    ];

    public const HINTS = [
        'parties'  => 'مشتری، تأمین‌کننده و کارمند — با کد، تلفن، کدهای رسمی و مانده‌ی بدهکار/بستانکار.',
        'products' => 'کالا و خدمت — با کد، دسته، واحد، قیمتِ خرید و فروش و موجودیِ اول دوره.',
        'accounts' => 'صندوق، حسابِ بانکی و کارت‌خوان — با موجودیِ اول دوره.',
        'cheques'  => 'چک‌های دریافتی و پرداختیِ وصول‌نشده — بعد از اشخاص، چون هر چک به یک شخص می‌خورد.',
    ];

    /**
     * ⛔ تنها مرجعِ فیلدها — برچسب، هم سرآیندِ «فایلِ نمونه» و «خروجی» است و
     *    هم نامِ گزینه در تطبیقِ دستی؛ پس خروجی دوباره واردشدنی است.
     *    کالا از `BizImport::FIELDS` می‌آید (یک فهرست، نه دو).
     */
    public const FIELDS = [
        'parties' => [
            'code'          => 'کد طرف‌حساب',
            'name'          => 'نام طرف‌حساب',
            'first_name'    => 'نام کوچک',
            'last_name'     => 'نام خانوادگی',
            'company'       => 'نام شرکت',
            'kind'          => 'نوع',
            'mobile'        => 'موبایل',
            'phone'         => 'تلفن',
            'province'      => 'استان',
            'city'          => 'شهر',
            'address'       => 'آدرس',
            'national_id'   => 'کد ملی / شناسه ملی',
            'economic_code' => 'کد اقتصادی',
            'postal_code'   => 'کد پستی',
            'note'          => 'توضیحات',
            'debit'         => 'بدهکار',
            'credit'        => 'بستانکار',
            'balance'       => 'مانده',
            'side'          => 'تشخیص',
        ],
        'accounts' => [
            'name'       => 'نام صندوق یا حساب',
            'kind'       => 'نوع',
            'bank'       => 'بانک',
            'account_no' => 'شماره حساب',
            'balance'    => 'موجودی',
        ],
        'cheques' => [
            'direction'  => 'نوع چک',
            'cheque_no'  => 'شماره چک',
            'bank'       => 'بانک',
            'amount'     => 'مبلغ',
            'due'        => 'سررسید',
            'date'       => 'تاریخ دریافت یا صدور',
            'party_code' => 'کد طرف‌حساب',
            'party'      => 'طرف‌حساب',
            'status'     => 'وضعیت',
            'note'       => 'توضیحات',
        ],
    ];

    /**
     * نام‌های دیگرِ هر ستون — بعد از `BizImport::norm()` (بی‌فاصله، بی‌نشانه،
     * ی/ک فارسی، حروفِ کوچک). خروجیِ اکسلِ حسابفا، هلو، سپیدار، محک، نرم‌افزارهای
     * فروشگاهی و برگه‌های دست‌ساز.
     * ⚠ «نام» تنها، وقتی ستونِ «نام خانوادگی» هم هست، «نام» (کوچک) است نه «نام
     *   طرف‌حساب» — `autoMap()` همین را جابه‌جا می‌کند.
     */
    public const ALIASES = [
        'parties' => [
            'code'          => ['کد', 'کدشخص', 'کدطرفحساب', 'کدحساب', 'کدمشتری', 'کدتفصیلی', 'کدتامینکننده', 'شمارهشخص', 'code', 'partycode', 'customercode', 'accountcode', 'id'],
            'name'          => ['نام', 'نامونامخانوادگی', 'نامکامل', 'نامطرفحساب', 'نامشخص', 'ناممشتری', 'نامتامینکننده', 'عنوان', 'عنوانحساب', 'شرححساب', 'نامتفصیلی', 'طرفحساب', 'شخص', 'name', 'fullname', 'customername', 'partyname', 'title', 'displayname'],
            'first_name'    => ['نامکوچک', 'firstname', 'givenname'],
            'last_name'     => ['نامخانوادگی', 'فامیلی', 'فامیل', 'lastname', 'surname', 'familyname'],
            'company'       => ['شرکت', 'نامشرکت', 'سازمان', 'نامفروشگاه', 'company', 'companyname', 'organization'],
            'kind'          => ['نوع', 'نوعطرفحساب', 'نوعشخص', 'گروه', 'گروهطرفحساب', 'گروهشخص', 'دستهبندی', 'نقش', 'type', 'kind', 'group', 'category', 'role'],
            'mobile'        => ['موبایل', 'تلفنهمراه', 'همراه', 'شمارهموبایل', 'شمارههمراه', 'mobile', 'cell', 'cellphone', 'mobilenumber'],
            'phone'         => ['تلفن', 'تلفنثابت', 'شمارهتماس', 'تماس', 'شمارهتلفن', 'phone', 'tel', 'telephone', 'phonenumber'],
            'province'      => ['استان', 'province', 'state'],
            'city'          => ['شهر', 'city'],
            'address'       => ['آدرس', 'نشانی', 'address'],
            'national_id'   => ['کدملی', 'شناسهملی', 'شمارهملی', 'کدملیشناسهملی', 'nationalid', 'nationalcode'],
            'economic_code' => ['کداقتصادی', 'شمارهاقتصادی', 'economiccode', 'taxid'],
            'postal_code'   => ['کدپستی', 'postalcode', 'zip', 'zipcode'],
            'note'          => ['توضیحات', 'توضیح', 'یادداشت', 'شرح', 'note', 'notes', 'description', 'comment'],
            'debit'         => ['بدهکار', 'ماندهبدهکار', 'بدهی', 'debit', 'debtor', 'dr'],
            'credit'        => ['بستانکار', 'ماندهبستانکار', 'طلب', 'credit', 'creditor', 'cr'],
            'balance'       => ['مانده', 'ماندهحساب', 'تراز', 'ماندهاولدوره', 'ماندهافتتاحیه', 'ماندهنهایی', 'balance', 'openingbalance'],
            'side'          => ['تشخیص', 'ماهیت', 'ماهیتمانده', 'نوعمانده', 'وضعیتمانده', 'side', 'nature', 'drcr'],
        ],
        'accounts' => [
            'name'       => ['نام', 'نامحساب', 'نامصندوق', 'عنوان', 'عنوانحساب', 'شرح', 'شرححساب', 'حساب', 'صندوق', 'name', 'title', 'account', 'accountname'],
            'kind'       => ['نوع', 'نوعحساب', 'type', 'kind'],
            'bank'       => ['بانک', 'نامبانک', 'bank', 'bankname'],
            'account_no' => ['شمارهحساب', 'شماره', 'شمارهکارت', 'شبا', 'accountnumber', 'accountno', 'number', 'iban'],
            'balance'    => ['موجودی', 'مانده', 'ماندهحساب', 'موجودیاولدوره', 'ماندهاولدوره', 'balance', 'amount', 'openingbalance'],
        ],
        'cheques' => [
            'direction'  => ['نوع', 'نوعچک', 'دریافتیپرداختی', 'نوعسند', 'type', 'direction', 'kind'],
            'cheque_no'  => ['شمارهچک', 'شماره', 'شمارهصیادی', 'سریال', 'سریالچک', 'chequeno', 'checkno', 'chequenumber', 'checknumber', 'number'],
            'bank'       => ['بانک', 'نامبانک', 'بانکصادرکننده', 'bank', 'bankname'],
            'amount'     => ['مبلغ', 'مبلغچک', 'مبلغریال', 'مبلغتومان', 'amount', 'sum'],
            'due'        => ['سررسید', 'تاریخسررسید', 'سررسیدچک', 'duedate', 'due', 'maturity'],
            'date'       => ['تاریخ', 'تاریخدریافت', 'تاریخصدور', 'تاریخثبت', 'تاریخدریافتصدور', 'date', 'issuedate'],
            'party_code' => ['کدطرفحساب', 'کدشخص', 'کد', 'partycode'],
            'party'      => ['طرفحساب', 'نامطرفحساب', 'شخص', 'نام', 'نامشخص', 'دریافتاز', 'پرداختبه', 'دروجه', 'صاحبحساب', 'صادرکننده', 'مشتری', 'party', 'name', 'payee', 'drawer'],
            'status'     => ['وضعیت', 'وضعیتچک', 'status', 'state'],
            'note'       => ['توضیحات', 'توضیح', 'شرح', 'note', 'description'],
        ],
    ];

    /** ستونی که بی‌آن ردیفی ساخته نمی‌شود (یکی از این‌ها کافی است). */
    private const REQUIRED = [
        'parties'  => ['name', 'first_name', 'last_name', 'company'],
        'products' => ['name'],
        'accounts' => ['name', 'bank', 'account_no'],
        'cheques'  => ['amount'],
    ];

    public const OPTION_KEYS = ['rial', 'update', 'flip', 'sync_stock', 'cheque_out'];

    // ------------------------------------------------------------------
    // ستون‌ها
    // ------------------------------------------------------------------

    /** @return array<string,string> فیلد ← برچسب */
    public static function fields(string $entity): array
    {
        return $entity === 'products' ? BizImport::FIELDS : (self::FIELDS[$entity] ?? []);
    }

    /** @return array<string,string[]> */
    private static function aliases(string $entity): array
    {
        return $entity === 'products' ? BizImport::ALIASES : (self::ALIASES[$entity] ?? []);
    }

    /**
     * سرآیند ← نقشه‌ی خودکار (شماره‌ی ستون ← فیلد). ⚠ برچسبِ دقیقِ خودمان
     * پیش از هر نامِ دیگر، تا خروجیِ خودِ هستیکو همیشه درست برگردد.
     * @param array<int,mixed> $header @return array<int,string>
     */
    public static function autoMap(string $entity, array $header): array
    {
        $fields = self::fields($entity);
        $al = self::aliases($entity);
        $norm = [];
        foreach ($header as $i => $h) {
            if ($i >= self::MAX_COLS) { break; }
            $n = BizImport::norm((string)$h);
            if ($n !== '') { $norm[$i] = $n; }
        }
        $map = []; $taken = [];
        // دورِ اول: برچسبِ خودمان؛ دورِ دوم: نام‌های دیگر
        foreach ([true, false] as $exact) {
            foreach ($norm as $i => $n) {
                if (isset($map[$i])) { continue; }
                foreach ($fields as $f => $label) {
                    if (isset($taken[$f])) { continue; }
                    $hit = $exact ? $n === BizImport::norm($label) : in_array($n, $al[$f] ?? [], true);
                    if ($hit) { $map[$i] = $f; $taken[$f] = $i; break; }
                }
            }
        }
        // ⚠ «نام» + «نام خانوادگی» = نامِ کوچک، نه نامِ کامل
        if ($entity === 'parties' && isset($taken['last_name'], $taken['name']) && !isset($taken['first_name'])
            && ($norm[$taken['name']] ?? '') === 'نام') {
            $map[$taken['name']] = 'first_name';
        }
        ksort($map);
        return $map;
    }

    /** آیا نقشه ستونِ لازم را دارد؟ */
    public static function hasRequired(string $entity, array $map): bool
    {
        return (bool)array_intersect(self::REQUIRED[$entity] ?? [], array_values($map));
    }

    /**
     * ردیفِ سرآیند: از ده ردیفِ اول، آنکه بیشترین ستونِ شناخته‌شده را دارد و
     * ستونِ لازم هم در آن هست (فایل‌ها اغلب عنوان یا خطِ خالی بالا دارند).
     * @return array{hi:int, map:array<int,string>, score:int}
     */
    public static function headerRow(string $entity, array $rows): array
    {
        $best = ['hi' => 0, 'map' => [], 'score' => -1];
        foreach (array_slice($rows, 0, 10, true) as $i => $r) {
            $m = self::autoMap($entity, (array)$r);
            $score = count($m) + (self::hasRequired($entity, $m) ? 100 : 0);
            if ($score > $best['score']) { $best = ['hi' => (int)$i, 'map' => $m, 'score' => $score]; }
        }
        return $best;
    }

    /** کدام بخش؟ — آنکه سرآیندش بیشترین ستونِ شناخته‌شده را دارد. */
    public static function detect(array $rows): string
    {
        $best = 'parties'; $score = -1;
        foreach (array_keys(self::ENTITIES) as $e) {
            $h = self::headerRow($e, $rows);
            if ($h['score'] > $score) { $best = $e; $score = $h['score']; }
        }
        return $best;
    }

    /**
     * نقشه‌ی دستی از فرم: شماره‌ی ستون ← فیلد. ⛔ فقط فیلدهای همین بخش، هر
     * فیلد یک بار (ستونِ اول برنده)؛ «—» یعنی نادیده.
     * @return array<int,string>
     */
    public static function cleanMap(string $entity, array $in, int $cols): array
    {
        $fields = self::fields($entity);
        $out = []; $taken = [];
        foreach ($in as $i => $f) {
            $i = (int)$i; $f = (string)$f;
            if ($i < 0 || $i >= min($cols, self::MAX_COLS) || !isset($fields[$f]) || isset($taken[$f])) { continue; }
            $out[$i] = $f; $taken[$f] = true;
        }
        ksort($out);
        return $out;
    }

    /**
     * جدولِ خام + نقشه ← ردیف‌های استاندارد (`line` + هر فیلد؛ ستونِ
     * نگاشته‌نشده `null` = «دست نزن»).
     * @return array<int,array<string,mixed>>
     */
    public static function normalize(string $entity, array $rows, int $hi, array $map): array
    {
        $out = [];
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            $r = (array)$r;
            if (!array_filter($r, fn($v) => trim((string)$v) !== '')) { continue; }
            $row = ['line' => (int)$i + 1];
            foreach (self::fields($entity) as $f => $_) { $row[$f] = null; }
            foreach ($map as $col => $f) {
                $v = trim((string)($r[$col] ?? ''));
                // «'» ای که خروجیِ CSV برای خنثی کردنِ فرمول گذاشته برداشته می‌شود
                if (strlen($v) > 1 && $v[0] === "'" && strpbrk($v[1], '=+-@') !== false) { $v = substr($v, 1); }
                $row[$f] = $v;
            }
            $out[] = $row;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // مقدارها
    // ------------------------------------------------------------------

    /**
     * عدد با علامت: «(1,250,000)» و «1,250,000-» هم منفی‌اند (شکلِ حسابداریِ
     * اکسل). جداکننده و ارقامِ فارسی را `BizImport::num()` می‌فهمد.
     */
    public static function signedNum(?string $v): ?float
    {
        if ($v === null || trim($v) === '') { return null; }
        $t = trim(toLatinDigits($v));
        $neg = false;
        if (preg_match('/^\((.*)\)$/u', $t, $m)) { $t = $m[1]; $neg = true; }
        if (preg_match('/^(.*\d)\s*[-−]$/u', $t, $m)) { $t = $m[1]; $neg = true; }
        $t = str_replace('−', '-', $t);
        $n = BizImport::num($t, true);
        if ($n === null) { return null; }
        return $neg ? -abs($n) : $n;
    }

    /**
     * تاریخ ← میلادیِ `Y-m-d`، یا null. شمسی (`1405/08/15`، `۱۴۰۵-۰۸-۱۵`،
     * `14050815`)، میلادی (`2026-11-06`)، و عددِ تاریخِ اکسل (`46000`).
     */
    public static function date(?string $v): ?string
    {
        if ($v === null) { return null; }
        $t = trim(toLatinDigits($v));
        if ($t === '') { return null; }
        if (preg_match('/^\d{5}(\.\d+)?$/', $t)) {          // عددِ تاریخِ اکسل
            $n = (int)$t;
            if ($n < 20000 || $n > 80000) { return null; }
            return gmdate('Y-m-d', ($n - 25569) * 86400);
        }
        if (preg_match('/^(1[34]\d{2})(\d{2})(\d{2})$/', $t, $m)
            || preg_match('/^(\d{2,4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $t, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            if ($y < 100) { $y += 1400; }
            if ($y >= 1300 && $y <= 1500) {
                if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) { return null; }
                [$gy, $gm, $gd] = jalaliToGregorian($y, $mo, $d);
                [$by, $bm, $bd] = gregorianToJalali($gy, $gm, $gd);
                if ([$by, $bm, $bd] !== [$y, $mo, $d]) { return null; }   // ۳۱ مهر نیست
                $iso = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
            } elseif ($y >= 1900 && $y <= 2100) {
                $iso = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            } else {
                return null;
            }
            return isValidDate($iso) ? $iso : null;
        }
        return null;
    }

    /** نوعِ شخص از متنِ فایل؛ ناشناخته = null. */
    public static function partyKind(?string $v): ?string
    {
        if ($v === null || trim($v) === '') { return null; }
        $n = BizImport::norm($v);
        if (isset(BizParties::KINDS[$n])) { return $n; }
        foreach (BizParties::KINDS as $k => $label) { if (BizImport::norm($label) === $n) { return $k; } }
        $cust = (bool)preg_match('/مشتری|خریدار|customer|client|buyer/u', $n);
        $supp = (bool)preg_match('/تامین|تأمین|فروشنده|supplier|vendor|seller|تولیدکننده|پخش/u', $n);
        if ($cust && $supp) { return 'both'; }
        if (preg_match('/هردو|both/u', $n)) { return 'both'; }
        if ($cust) { return 'customer'; }
        if ($supp) { return 'supplier'; }
        if (preg_match('/کارمند|پرسنل|کارکن|کارگر|حقوقبگیر|employee|staff/u', $n)) { return 'employee'; }
        return null;
    }

    /** نوعِ صندوق از متنِ فایل؛ ناشناخته = null. */
    public static function accountKind(?string $v): ?string
    {
        if ($v === null || trim($v) === '') { return null; }
        $n = BizImport::norm($v);
        if (in_array($n, BizCash::USER_KINDS, true)) { return $n; }
        if (preg_match('/کارتخوان|پوز|pos/u', $n)) { return 'pos'; }
        if (preg_match('/بانک|جاری|پسانداز|سپرده|bank|current|saving/u', $n)) { return 'bank'; }
        if (preg_match('/صندوق|نقد|تنخواه|cash/u', $n)) { return 'cash'; }
        return null;
    }

    /** جهتِ چک: `in` دریافتی، `out` پرداختی، null ناشناخته. */
    public static function chequeDir(?string $v): ?string
    {
        if ($v === null || trim($v) === '') { return null; }
        $n = BizImport::norm($v);
        if (preg_match('/دریافت|وارده|دریافتنی|received|receivable|incoming|^in$/u', $n)) { return 'in'; }
        if (preg_match('/پرداخت|صادره|پرداختنی|issued|payable|outgoing|paid|^out$/u', $n)) { return 'out'; }
        return null;
    }

    /**
     * ⛔ فقط چکِ **در جریان** وارد می‌شود: وصول‌شده پول است (در موجودیِ صندوق)
     *    و برگشتی بدهیِ شخص (در مانده‌اش) — ورودشان دوباره‌شماری است.
     * @return bool|null true در جریان، false نه، null ستون خالی
     */
    public static function chequePending(?string $v): ?bool
    {
        if ($v === null || trim($v) === '') { return null; }
        $n = BizImport::norm($v);
        if (preg_match('/وصولنشده|درجریان|نزدصندوق|دربانک|درانتظار|pending|open|outstanding|نزدما/u', $n)) { return true; }
        if (preg_match('/وصول|پاس|برگشت|خرج|واگذار|عودت|باطل|cleared|bounced|paid|returned|void|endorsed/u', $n)) { return false; }
        return true;
    }

    /** ریال ÷ ۱۰؛ عددِ تومان دست‌نخورده. */
    private static function money(?float $n, array $opts): ?int
    {
        if ($n === null) { return null; }
        return (int)round(!empty($opts['rial']) ? $n / 10 : $n);
    }

    // ------------------------------------------------------------------
    // پیش‌نمایش
    // ------------------------------------------------------------------

    /**
     * ⛔ پیش‌نمایش — هیچ چیزی نمی‌نویسد. هر ردیف: create / update / skip / error.
     * @return array{rows:array, counts:array<string,int>, total:int}
     */
    public static function plan(int $userId, string $entity, array $rows, array $opts): array
    {
        if ($entity === 'products') {
            $p = BizImport::plan($userId, $rows, $opts);
            $p['total'] = 0;
            return $p;
        }
        $out = match ($entity) {
            'parties'  => self::planParties($userId, $rows, $opts),
            'accounts' => self::planAccounts($userId, $rows, $opts),
            'cheques'  => self::planCheques($userId, $rows, $opts),
            default    => [],
        };
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        $total = 0;
        foreach ($out as $r) {
            $counts[$r['action']]++;
            if (in_array($r['action'], ['create', 'update'], true) && isset($r['amount'])) { $total += (int)$r['amount']; }
        }
        return ['rows' => $out, 'counts' => $counts, 'total' => $total];
    }

    /** شناسه‌ی اشخاص/صندوق‌هایی که سند دارند — یک کوئری، نه یکی به‌ازای هر ردیف. */
    private static function partiesWithDocs(int $userId): array
    {
        $flag = tableHasColumn('biz_payments', 'opening_import') ? ' AND opening_import = 0' : '';
        $st = Database::getConnection()->prepare(
            "SELECT party_id FROM biz_invoices WHERE user_id = :u AND party_id IS NOT NULL
             UNION SELECT party_id FROM biz_payments WHERE user_id = :u2 AND party_id IS NOT NULL{$flag}"
        );
        $st->execute(['u' => $userId, 'u2' => $userId]);
        return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private static function planParties(int $userId, array $rows, array $opts): array
    {
        $pdo = Database::getConnection();
        $hasCode = BizParties::hasPartyCode();
        $st = $pdo->prepare('SELECT id, name' . ($hasCode ? ', code' : '') . ' FROM biz_parties WHERE user_id = :u');
        $st->execute(['u' => $userId]);
        $byCode = []; $byName = [];
        foreach ($st->fetchAll() as $p) {
            if ($hasCode && (string)($p['code'] ?? '') !== '') { $byCode[(string)$p['code']] = (int)$p['id']; }
            $byName[BizCommon::fold((string)$p['name'])] = (int)$p['id'];
        }
        $withDocs = self::partiesWithDocs($userId);

        $seen = []; $out = [];
        foreach ($rows as $r) {
            $name = BizCommon::persian(BizCommon::line((string)($r['name'] ?? '')));
            if ($name === '') {
                $name = BizCommon::persian(BizCommon::line(trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''))));
            }
            $company = BizCommon::persian(BizCommon::line((string)($r['company'] ?? '')));
            if ($name === '') { $name = $company; }
            elseif ($company !== '' && mb_strpos($name, $company) === false && mb_strlen($name . ' — ' . $company) <= BizParties::LIMITS['name']) {
                $name .= ' — ' . $company;
            }
            $code = BizCommon::line(toLatinDigits((string)($r['code'] ?? '')));
            $p = ['line' => (int)$r['line'], 'name' => $name, 'code' => $code, 'id' => 0, 'notes' => [], 'amount' => null];

            // نوع
            $kind = self::partyKind($r['kind'] ?? null);
            if ($kind === null && ($r['kind'] ?? null) !== null && trim((string)$r['kind']) !== '') {
                $p['notes'][] = 'نوعِ «' . $r['kind'] . '» شناخته نشد؛ «مشتری» گذاشته می‌شود';
            }
            $p['kind'] = $kind;

            // تلفن — موبایل مقدم؛ هر دو اگر جا شود
            $mob = BizCommon::line(toLatinDigits((string)($r['mobile'] ?? '')));
            $tel = BizCommon::line(toLatinDigits((string)($r['phone'] ?? '')));
            $phone = $mob !== '' && $tel !== '' && $mob !== $tel && mb_strlen($mob . ' - ' . $tel) <= BizParties::LIMITS['phone']
                   ? $mob . ' - ' . $tel : ($mob !== '' ? $mob : $tel);
            $p['phone'] = ($r['mobile'] ?? null) === null && ($r['phone'] ?? null) === null ? null : $phone;

            $addr = BizCommon::line(implode('، ', array_filter([
                (string)($r['province'] ?? ''), (string)($r['city'] ?? ''), (string)($r['address'] ?? '')], fn($x) => trim($x) !== '')));
            $p['address'] = ($r['address'] ?? null) === null && ($r['city'] ?? null) === null && ($r['province'] ?? null) === null
                          ? null : mb_substr($addr, 0, BizParties::LIMITS['address']);
            $p['note'] = ($r['note'] ?? null) === null ? null : mb_substr(trim((string)$r['note']), 0, BizParties::LIMITS['note']);
            foreach (BizParties::CODE_KEYS as $ck) { $p[$ck] = $r[$ck] ?? null; }

            // ⛔ مانده: مثبت = او بدهکار است (همان قراردادِ `BALANCE_SQL`)
            $bal = null;
            $dr = self::signedNum($r['debit'] ?? null);
            $cr = self::signedNum($r['credit'] ?? null);
            if ($dr !== null || $cr !== null) {
                $bal = abs($dr ?? 0) - abs($cr ?? 0);
            } elseif (($b = self::signedNum($r['balance'] ?? null)) !== null) {
                $bal = $b;
                $side = BizImport::norm((string)($r['side'] ?? ''));
                if ($side !== '') {
                    if (preg_match('/بستانکار|^بس$|طلبکار|credit|^cr$/u', $side)) { $bal = -abs($b); }
                    elseif (preg_match('/بدهکار|^بد$|debit|^dr$/u', $side)) { $bal = abs($b); }
                }
            }
            if ($bal !== null && !empty($opts['flip'])) { $bal = -$bal; }
            $p['balance'] = self::money($bal, $opts);

            $err = null;
            if ($name === '') { $err = 'نامِ طرف‌حساب خالی است'; }
            elseif (mb_strlen($name) > BizParties::LIMITS['name']) { $err = 'نام بیش از حد بلند است'; }
            elseif (mb_strlen($code) > BizParties::CODE_MAX) { $err = 'کد بیش از حد بلند است'; }
            elseif ($p['phone'] !== null && $p['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{3,40}$/', $p['phone'])) { $err = 'شماره‌ی تلفن معتبر نیست («' . $p['phone'] . '»)'; }
            elseif ($p['balance'] !== null && ($e = BizCommon::moneyError(abs($p['balance']), 'مانده')) !== null) { $err = $e; }
            else {
                foreach (BizParties::CODE_KEYS as $ck) {
                    $c = BizCommon::code($ck, (string)($p[$ck] ?? ''));
                    if (!$c['ok']) { $err = $c['message']; break; }
                }
            }
            $key = $code !== '' ? 'c:' . $code : 'n:' . BizCommon::fold($name);
            if ($err === null && isset($seen[$key])) { $err = 'تکراری در همین فایل (ردیفِ ' . $seen[$key] . ')'; }
            $seen[$key] = $p['line'];

            if ($err !== null) {
                $p['action'] = 'error'; $p['notes'][] = $err;
            } else {
                $id = $code !== '' ? ($byCode[$code] ?? 0) : 0;
                if ($id === 0) { $id = $byName[BizCommon::fold($name)] ?? 0; }
                // ⚠ کدِ فایل مالِ کسِ دیگری است ولی نام با این یکی می‌خواند → خطا، نه بازنویسی
                if ($id > 0 && $code !== '' && isset($byCode[$code]) && $byCode[$code] !== $id) { $id = $byCode[$code]; }
                $p['id'] = $id;
                $p['action'] = $id === 0 ? 'create' : (!empty($opts['update']) ? 'update' : 'skip');
                if ($p['action'] === 'skip') { $p['notes'][] = 'از قبل هست'; }
                if ($p['action'] === 'update' && $p['balance'] !== null && isset($withDocs[$id])) {
                    $p['notes'][] = 'این شخص سند دارد؛ مانده‌ی اول دوره دست نمی‌خورد';
                    $p['balance'] = null;
                }
            }
            $p['amount'] = $p['balance'];
            $out[] = $p;
        }
        return $out;
    }

    private static function planAccounts(int $userId, array $rows, array $opts): array
    {
        $st = Database::getConnection()->prepare("SELECT id, name, kind FROM biz_accounts WHERE user_id = :u AND kind IN ('cash','bank','pos')");
        $st->execute(['u' => $userId]);
        $byName = [];
        foreach ($st->fetchAll() as $a) { $byName[BizCommon::fold((string)$a['name'])] = (int)$a['id']; }
        $fst = Database::getConnection()->prepare('SELECT DISTINCT account_id FROM biz_payments WHERE user_id = :u
                                                    UNION SELECT DISTINCT to_account_id FROM biz_payments WHERE user_id = :u2 AND to_account_id IS NOT NULL');
        $fst->execute(['u' => $userId, 'u2' => $userId]);
        $withDocs = array_fill_keys(array_map('intval', $fst->fetchAll(PDO::FETCH_COLUMN)), true);

        $seen = []; $out = [];
        foreach ($rows as $r) {
            $bank = BizCommon::persian(BizCommon::line((string)($r['bank'] ?? '')));
            $no   = BizCommon::line(toLatinDigits((string)($r['account_no'] ?? '')));
            $name = BizCommon::persian(BizCommon::line((string)($r['name'] ?? '')));
            if ($name === '') { $name = BizCommon::line($bank . ' ' . $no); }
            elseif ($no !== '' && mb_strpos($name, $no) === false && mb_strlen($name . ' ' . $no) <= BizCash::NAME_MAX) { $name .= ' ' . $no; }
            $kind = self::accountKind($r['kind'] ?? null) ?? ($bank !== '' || $no !== '' ? 'bank' : 'cash');
            $p = ['line' => (int)$r['line'], 'name' => $name, 'kind' => $kind, 'id' => 0, 'notes' => []];
            $b = self::signedNum($r['balance'] ?? null);
            $p['balance'] = self::money($b, $opts);

            $err = null;
            if ($name === '') { $err = 'نامِ صندوق یا حساب خالی است'; }
            elseif (mb_strlen($name) > BizCash::NAME_MAX) { $err = 'نام بیش از ' . BizCash::NAME_MAX . ' نویسه است'; }
            elseif ($p['balance'] !== null && $p['balance'] < 0) { $err = 'موجودیِ منفی پذیرفته نیست (اضافه‌برداشت را دستی تعدیل کنید)'; }
            elseif ($p['balance'] !== null && ($e = BizCommon::moneyError($p['balance'], 'موجودی')) !== null) { $err = $e; }
            $key = BizCommon::fold($name);
            if ($err === null && isset($seen[$key])) { $err = 'تکراری در همین فایل (ردیفِ ' . $seen[$key] . ')'; }
            $seen[$key] = $p['line'];
            if ($err !== null) {
                $p['action'] = 'error'; $p['notes'][] = $err;
            } else {
                $id = $byName[$key] ?? 0;
                $p['id'] = $id;
                $p['action'] = $id === 0 ? 'create' : (!empty($opts['update']) ? 'update' : 'skip');
                if ($p['action'] === 'skip') { $p['notes'][] = 'از قبل هست'; }
                if ($p['action'] === 'update' && $p['balance'] !== null && isset($withDocs[$id])) {
                    $p['notes'][] = 'این حساب گردش دارد؛ موجودیِ اول دوره دست نمی‌خورد';
                    $p['balance'] = null;
                }
            }
            $p['amount'] = $p['balance'];
            $out[] = $p;
        }
        return $out;
    }

    private static function planCheques(int $userId, array $rows, array $opts): array
    {
        $pdo = Database::getConnection();
        $hasCode = BizParties::hasPartyCode();
        $st = $pdo->prepare('SELECT id, name' . ($hasCode ? ', code' : '') . ' FROM biz_parties WHERE user_id = :u');
        $st->execute(['u' => $userId]);
        $byCode = []; $byName = [];
        foreach ($st->fetchAll() as $p) {
            if ($hasCode && (string)($p['code'] ?? '') !== '') { $byCode[(string)$p['code']] = (int)$p['id']; }
            $k = BizCommon::fold((string)$p['name']);
            // ⚠ دو شخص با یک نام → «مبهم»، نه اولی
            $byName[$k] = isset($byName[$k]) ? -1 : (int)$p['id'];
        }
        $ex = $pdo->prepare("SELECT kind, party_id, amount, COALESCE(cheque_no, '') AS no FROM biz_payments
                             WHERE user_id = :u AND cheque_status IS NOT NULL");
        $ex->execute(['u' => $userId]);
        $existing = [];
        foreach ($ex->fetchAll() as $c) { $existing[$c['kind'] . '|' . $c['party_id'] . '|' . $c['amount'] . '|' . $c['no']] = true; }

        $seen = []; $out = [];
        foreach ($rows as $r) {
            $dir = self::chequeDir($r['direction'] ?? null) ?? (!empty($opts['cheque_out']) ? 'out' : 'in');
            $amount = self::money(self::signedNum($r['amount'] ?? null), $opts);
            $no = BizCommon::line(toLatinDigits((string)($r['cheque_no'] ?? '')));
            $pname = BizCommon::persian(BizCommon::line((string)($r['party'] ?? '')));
            $pcode = BizCommon::line(toLatinDigits((string)($r['party_code'] ?? '')));
            $p = ['line' => (int)$r['line'], 'dir' => $dir, 'amount' => $amount === null ? null : abs($amount), 'cheque_no' => $no,
                  'bank' => mb_substr(BizCommon::persian(BizCommon::line((string)($r['bank'] ?? ''))), 0, 60),
                  'due' => self::date($r['due'] ?? null), 'date' => self::date($r['date'] ?? null),
                  'party_name' => $pname !== '' ? $pname : $pcode, 'party_id' => 0, 'id' => 0,
                  'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 300), 'notes' => []];

            $pid = $pcode !== '' ? ($byCode[$pcode] ?? 0) : 0;
            if ($pid === 0 && $pname !== '') { $pid = $byName[BizCommon::fold($pname)] ?? 0; }

            $err = null; $skip = null;
            if (self::chequePending($r['status'] ?? null) === false) { $skip = 'وصول‌شده/برگشتی است؛ فقط چکِ در جریان وارد می‌شود'; }
            elseif ($p['amount'] === null || $p['amount'] <= 0) { $err = 'مبلغ خالی یا صفر است'; }
            elseif (($e = BizCommon::moneyError($p['amount'], 'مبلغ')) !== null) { $err = $e; }
            elseif ($p['due'] === null) { $err = ($r['due'] ?? null) !== null && trim((string)$r['due']) !== '' ? 'سررسیدِ «' . $r['due'] . '» تاریخ نیست' : 'سررسید خالی است'; }
            elseif (($r['date'] ?? null) !== null && trim((string)$r['date']) !== '' && $p['date'] === null) { $err = 'تاریخِ «' . $r['date'] . '» تاریخ نیست'; }
            elseif ($p['date'] !== null && $p['date'] > date('Y-m-d')) { $err = 'تاریخِ دریافت/صدور در آینده است'; }
            elseif ($pid === -1) { $err = 'دو طرف‌حساب به نامِ «' . $pname . '» هست؛ ستونِ «کد طرف‌حساب» را بدهید'; }
            elseif ($pid === 0) { $err = $p['party_name'] === '' ? 'طرف‌حساب خالی است' : 'طرف‌حسابِ «' . $p['party_name'] . '» پیدا نشد؛ اول اشخاص را وارد کنید'; }
            elseif (mb_strlen($no) > 30) { $err = 'شماره‌ی چک بیش از ۳۰ نویسه است'; }
            $p['party_id'] = max(0, $pid);

            $kind = $dir === 'in' ? 'receipt' : 'payment';
            $key = $kind . '|' . $p['party_id'] . '|' . $p['amount'] . '|' . $no;
            if ($err === null && $skip === null && isset($seen[$key])) { $err = 'تکراری در همین فایل (ردیفِ ' . $seen[$key] . ')'; }
            $seen[$key] = $p['line'];
            if ($err === null && $skip === null && isset($existing[$key])) { $skip = 'این چک از قبل ثبت شده'; }

            if ($err !== null) { $p['action'] = 'error'; $p['notes'][] = $err; }
            elseif ($skip !== null) { $p['action'] = 'skip'; $p['notes'][] = $skip; }
            else { $p['action'] = 'create'; }
            $out[] = $p;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // ثبت
    // ------------------------------------------------------------------

    /**
     * ⛔ ثبت — هر ردیف جدا و از راهِ همان فرم؛ شکستِ یکی بقیه را برنمی‌گرداند.
     * @return array{created:int, updated:int, skipped:int, stock?:int, errors:array<int,string>}
     */
    public static function apply(int $userId, string $entity, array $plan, array $opts): array
    {
        if ($entity === 'products') { return BizImport::apply($userId, $plan, $opts); }
        $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($plan['rows'] as $p) {
            if ($p['action'] === 'skip') { $res['skipped']++; continue; }
            if ($p['action'] === 'error') { continue; }
            $r = match ($entity) {
                'parties'  => self::applyParty($userId, $p),
                'accounts' => self::applyAccount($userId, $p),
                'cheques'  => self::applyCheque($userId, $p),
            };
            if ($r['ok']) { $res[$p['action'] === 'update' ? 'updated' : 'created']++; }
            else { $res['errors'][$p['line']] = $r['message']; }
        }
        return $res;
    }

    private static function applyParty(int $userId, array $p): array
    {
        $cur = $p['action'] === 'update' ? BizParties::get($userId, (int)$p['id']) : null;
        if ($p['action'] === 'update' && !$cur) { return ['ok' => false, 'message' => 'طرف‌حساب پیدا نشد']; }
        // ⛔ مانده: فایل + جبرانِ چک‌های واردشده‌ی همین شخص؛ ستونِ خالی = همان قبلی
        if ($p['balance'] !== null) {
            $ob = (int)$p['balance'] + ($cur ? BizParties::chequeComp($userId, (int)$p['id']) : 0);
        } else {
            $ob = $cur ? (int)$cur['opening_balance'] : 0;
        }
        $in = [
            'name'    => $p['name'],
            'kind'    => $p['kind'] ?? ($cur['kind'] ?? 'customer'),
            'phone'   => $p['phone'] ?? (string)($cur['phone'] ?? ''),
            'address' => $p['address'] ?? (string)($cur['address'] ?? ''),
            'note'    => $p['note'] ?? (string)($cur['note'] ?? ''),
            'opening_amount' => (string)abs($ob),
            'opening_side'   => $ob < 0 ? 'we' : 'they',
        ];
        if ($p['code'] !== '') { $in['code'] = $p['code']; }
        $anyCode = false;
        foreach (BizParties::CODE_KEYS as $ck) { if (($p[$ck] ?? null) !== null) { $anyCode = true; } }
        if ($anyCode) {
            foreach (BizParties::CODE_KEYS as $ck) {
                $in[$ck] = ($p[$ck] ?? null) !== null && trim((string)$p[$ck]) !== '' ? (string)$p[$ck] : (string)($cur[$ck] ?? '');
            }
        }
        return BizParties::save($userId, $in, $p['action'] === 'update' ? (int)$p['id'] : 0);
    }

    private static function applyAccount(int $userId, array $p): array
    {
        $cur = null;
        if ($p['action'] === 'update') {
            $st = Database::getConnection()->prepare('SELECT * FROM biz_accounts WHERE id = :id AND user_id = :u');
            $st->execute(['id' => (int)$p['id'], 'u' => $userId]);
            $cur = $st->fetch() ?: null;
            if (!$cur) { return ['ok' => false, 'message' => 'حساب پیدا نشد']; }
        }
        return BizCash::save($userId, [
            'name' => $p['name'],
            'kind' => $p['kind'],
            'opening_balance' => (string)($p['balance'] ?? ($cur ? (int)$cur['opening_balance'] : 0)),
        ], $p['action'] === 'update' ? (int)$p['id'] : 0);
    }

    /**
     * ⛔ چکِ در جریان = دریافت/پرداختِ چکی (`BizPay::createTx`) + جبرانِ مانده‌ی
     *    اول دوره‌ی همان شخص (`BizParties::shiftOpening`)، **در یک تراکنش**: یا
     *    هر دو، یا هیچ. مانده‌ی امروزِ شخص همان می‌ماند که فایل گفته بود.
     */
    private static function applyCheque(int $userId, array $p): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        Biz::lockShop($pdo, $userId);
        try {
            $kind = $p['dir'] === 'in' ? 'receipt' : 'payment';
            $r = BizPay::createTx($pdo, $userId, [
                'kind' => $kind, 'amount' => (string)$p['amount'], 'party_id' => (int)$p['party_id'],
                'method' => 'cheque', 'pay_date' => $p['date'] ?? date('Y-m-d'),
                'cheque_no' => $p['cheque_no'], 'cheque_bank' => $p['bank'], 'cheque_due' => $p['due'],
                'title' => 'چکِ اول دوره (از نرم‌افزارِ قبلی)', 'note' => $p['note'],
            ]);
            if (!$r['ok']) { $pdo->rollBack(); return $r; }
            $pdo->prepare('UPDATE biz_payments SET opening_import = 1 WHERE id = :id AND user_id = :u')
                ->execute(['id' => (int)$r['id'], 'u' => $userId]);
            $e = BizParties::shiftOpening($pdo, $userId, (int)$p['party_id'], $kind === 'receipt' ? (int)$p['amount'] : -(int)$p['amount']);
            if ($e !== null) { $pdo->rollBack(); return ['ok' => false, 'message' => $e]; }
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // خروجی و فایلِ نمونه
    // ------------------------------------------------------------------

    /** ستون‌های فایلِ نمونه — همه‌ی فیلدها جز ریزه‌های جایگزین. */
    private const TEMPLATE_SKIP = [
        'parties' => ['first_name', 'last_name', 'company', 'phone', 'province', 'city', 'balance', 'side'],
        'accounts' => ['bank', 'account_no'],
        'cheques' => [],
    ];

    /** @return array<int,array<int,string>> */
    public static function template(string $entity): array
    {
        if ($entity === 'products') { return BizExport::template(); }
        $skip = self::TEMPLATE_SKIP[$entity] ?? [];
        return [array_values(array_diff_key(self::fields($entity), array_flip($skip)))];
    }

    /**
     * خروجیِ هر بخش با همان برچسب‌های `FIELDS` — دوباره واردشدنی (در
     * فروشگاهِ دیگر). ⛔ مانده **امروزِ** شخص است؛ در همین فروشگاه، شخصِ
     * سنددار اول دوره‌اش را از آن نمی‌گیرد (`planParties`).
     * @return array<int,array<int,string|int>>
     */
    public static function export(int $userId, string $entity): array
    {
        if ($entity === 'products') { return BizExport::rows($userId); }
        $pdo = Database::getConnection();
        if ($entity === 'parties') {
            $f = self::FIELDS['parties'];
            $out = [[$f['code'], $f['name'], $f['kind'], $f['mobile'], $f['address'], $f['national_id'],
                     $f['economic_code'], $f['postal_code'], $f['note'], $f['debit'], $f['credit']]];
            $hasCode = BizParties::hasPartyCode();
            $hasCodes = BizParties::hasCodes();
            $st = $pdo->prepare('SELECT p.*, ' . BizParties::BALANCE_SQL . ' AS balance FROM biz_parties p WHERE p.user_id = :u ORDER BY p.name, p.id');
            $st->execute(['u' => $userId]);
            while ($p = $st->fetch()) {
                $b = (int)$p['balance'];
                $out[] = [$hasCode ? (string)($p['code'] ?? '') : '', (string)$p['name'], BizParties::KINDS[$p['kind']] ?? (string)$p['kind'],
                          (string)($p['phone'] ?? ''), (string)($p['address'] ?? ''),
                          $hasCodes ? (string)($p['national_id'] ?? '') : '', $hasCodes ? (string)($p['economic_code'] ?? '') : '',
                          $hasCodes ? (string)($p['postal_code'] ?? '') : '', (string)($p['note'] ?? ''),
                          $b > 0 ? $b : '', $b < 0 ? -$b : ''];
            }
            return $out;
        }
        if ($entity === 'accounts') {
            $f = self::FIELDS['accounts'];
            $out = [[$f['name'], $f['kind'], $f['balance']]];
            $st = $pdo->prepare("SELECT a.name, a.kind, " . BizCash::balanceSql() . " AS balance FROM biz_accounts a
                                 WHERE a.user_id = :u AND a.kind IN ('cash','bank','pos') ORDER BY a.sort_order, a.id");
            $st->execute(['u' => $userId]);
            while ($a = $st->fetch()) { $out[] = [(string)$a['name'], BizCash::KINDS[$a['kind']] ?? (string)$a['kind'], (int)$a['balance']]; }
            return $out;
        }
        // چک‌های در جریان
        $f = self::FIELDS['cheques'];
        $out = [[$f['direction'], $f['cheque_no'], $f['bank'], $f['amount'], $f['due'], $f['date'], $f['party_code'], $f['party'], $f['status']]];
        if (!BizCheques::ready()) { return $out; }
        $code = BizParties::hasPartyCode() ? "COALESCE(p.code, '')" : "''";
        $st = $pdo->prepare("SELECT y.kind, y.cheque_no, y.cheque_bank, y.amount, y.cheque_due, y.pay_date, {$code} AS pcode, p.name AS pname
                             FROM biz_payments y LEFT JOIN biz_parties p ON p.id = y.party_id AND p.user_id = y.user_id
                             WHERE y.user_id = :u AND y.status = 'ok' AND y.cheque_status = 'pending' ORDER BY y.cheque_due, y.id");
        $st->execute(['u' => $userId]);
        while ($c = $st->fetch()) {
            $out[] = [$c['kind'] === 'receipt' ? 'دریافتی' : 'پرداختی', (string)($c['cheque_no'] ?? ''), (string)($c['cheque_bank'] ?? ''),
                      (int)$c['amount'], toLatinDigits(toJalali((string)$c['cheque_due'])), toLatinDigits(toJalali((string)$c['pay_date'])),
                      (string)$c['pcode'], (string)($c['pname'] ?? ''), 'در جریان'];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // نگه‌داریِ پیش‌نمایش بینِ دو درخواست — جدولِ **خام** (تا نقشه عوض شود)
    // ------------------------------------------------------------------

    private static function dir(): string
    {
        return dirname(__DIR__) . '/var/biz-import';
    }

    /** @param array<int,array<int,string>> $raw */
    public static function stash(int $userId, string $entity, array $raw, string $label): array
    {
        if (!isset(self::ENTITIES[$entity])) { return ['ok' => false, 'message' => 'بخشِ نامعتبر.']; }
        $h = self::headerRow($entity, $raw);
        $body = count($raw) - $h['hi'] - 1;
        if ($body > self::MAX_ROWS) {
            return ['ok' => false, 'message' => 'فایل بیش از ' . self::MAX_ROWS . ' ردیف دارد؛ آن را چند تکه کنید.'];
        }
        if ($body < 1) { return ['ok' => false, 'message' => 'زیرِ سرآیند هیچ ردیفی نیست.']; }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return ['ok' => false, 'message' => 'پوشه‌ی موقتِ سرور نوشتنی نیست؛ به پشتیبانی خبر بدهید.']; }
        foreach (glob($dir . '/m*.json') ?: [] as $old) {
            if (@filemtime($old) < time() - 7200 || str_starts_with(basename($old), 'm' . $userId . '-')) { @unlink($old); }
        }
        $token = bin2hex(random_bytes(12));
        $raw = array_map(fn($r) => array_slice(array_map('strval', (array)$r), 0, self::MAX_COLS), $raw);
        if (@file_put_contents($dir . '/m' . $userId . '-' . $token . '.json', json_encode(['raw' => $raw], JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'پوشه‌ی موقتِ سرور نوشتنی نیست؛ به پشتیبانی خبر بدهید.'];
        }
        @chmod($dir . '/m' . $userId . '-' . $token . '.json', 0600);
        $_SESSION['biz_migrate'] = ['token' => $token, 'uid' => $userId, 'entity' => $entity, 'label' => $label,
            'hi' => $h['hi'], 'map' => $h['map'],
            'opts' => ['rial' => false, 'update' => true, 'flip' => false, 'sync_stock' => false, 'cheque_out' => false]];
        return ['ok' => true, 'rows' => $body, 'mapped' => count($h['map']), 'required' => self::hasRequired($entity, $h['map'])];
    }

    /** @return array{raw:array, entity:string, label:string, hi:int, map:array, opts:array}|null */
    public static function load(int $userId): ?array
    {
        $s = $_SESSION['biz_migrate'] ?? null;
        if (!is_array($s) || (int)($s['uid'] ?? 0) !== $userId || !preg_match('/^[a-f0-9]{24}$/', (string)($s['token'] ?? ''))
            || !isset(self::ENTITIES[$s['entity'] ?? ''])) {
            return null;
        }
        $raw = @file_get_contents(self::dir() . '/m' . $userId . '-' . $s['token'] . '.json');
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || !isset($d['raw'])) { return null; }
        // ⛔ پیش‌نمایشی که پیش از سدِ فایلِ دودویی (`BizSheet::isBinary()`) از یک گزارشِ QRP ساخته
        //    شده بود در نشست می‌ماند و بعد از به‌روزرسانی هم همان ستون‌های درهم را نشان می‌داد
        //    (گزارشِ مالکِ نصب، مهر ۱۴۰۵) — دور ریخته می‌شود تا فایلِ درست از نو فرستاده شود
        if (BizSheet::rowsLookBinary((array)$d['raw'])) { self::clear($userId); return null; }
        return ['raw' => $d['raw'], 'entity' => $s['entity'], 'label' => (string)$s['label'], 'hi' => (int)$s['hi'],
                'map' => array_map('strval', (array)$s['map']), 'opts' => (array)$s['opts']];
    }

    /** بخش، ردیفِ سرآیند، نقشه و گزینه‌ها از فرمِ پیش‌نمایش. */
    public static function setup(int $userId, array $in): void
    {
        $st = self::load($userId);
        if (!$st) { return; }
        $entity = isset(self::ENTITIES[$in['entity'] ?? '']) ? (string)$in['entity'] : $st['entity'];
        if ($entity !== $st['entity']) {
            // بخشِ دیگر → سرآیند و نقشه از نو
            $h = self::headerRow($entity, $st['raw']);
            $_SESSION['biz_migrate']['entity'] = $entity;
            $_SESSION['biz_migrate']['hi'] = $h['hi'];
            $_SESSION['biz_migrate']['map'] = $h['map'];
        } elseif (isset($in['hi']) && (int)$in['hi'] !== $st['hi'] && (int)$in['hi'] >= 0
                  && (int)$in['hi'] < min(10, count($st['raw']) - 1)) {
            // ردیفِ سرآیندِ دیگر → نقشه‌ی خودکارِ همان ردیف
            $hi = (int)$in['hi'];
            $_SESSION['biz_migrate']['hi'] = $hi;
            $_SESSION['biz_migrate']['map'] = self::autoMap($entity, (array)$st['raw'][$hi]);
        } else {
            $cols = count((array)($st['raw'][$st['hi']] ?? []));
            $_SESSION['biz_migrate']['map'] = self::cleanMap($entity, (array)($in['map'] ?? []), $cols);
        }
        foreach (self::OPTION_KEYS as $k) { $_SESSION['biz_migrate']['opts'][$k] = !empty($in[$k]); }
    }

    public static function clear(int $userId): void
    {
        $s = $_SESSION['biz_migrate'] ?? null;
        if (is_array($s) && preg_match('/^[a-f0-9]{24}$/', (string)($s['token'] ?? ''))) {
            @unlink(self::dir() . '/m' . $userId . '-' . $s['token'] . '.json');
        }
        unset($_SESSION['biz_migrate']);
    }

    /** پیش‌نمایشِ کامل از حالتِ نگه‌داشته. @return array{plan:array, rows:array}|null */
    public static function preview(int $userId, array $st): array
    {
        $rows = self::normalize($st['entity'], $st['raw'], $st['hi'], $st['map']);
        return ['rows' => $rows, 'plan' => self::plan($userId, $st['entity'], $rows, $st['opts'])];
    }
}
