<?php
/**
 * ⛔ دفترِ کلِ کامل‌تر — حساب‌های دلخواه (`BizCoa`)، سندِ حسابداریِ دستی
 *    (`BizJournal`) و دارایی‌های ثابت با استهلاک (`BizAssets`). migration_biz_books.
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** فروشِ کشوری؛ حسابدارِ فروشگاه باید بتواند
 * سرفصلِ خودش، سندِ اصلاحی و دارایی‌ی ثابت (ویترین، دستگاهِ تعمیر، خودرو) داشته باشد.
 *
 * - دفتر همچنان **مشتق** است (`BizLedger::entries()`): سندِ دستی و استهلاک دو منبعِ
 *   تازه‌ی همان فهرست‌اند، نه دفترِ دوم.
 * - ⛔ سندِ دستی روی حساب‌های کنترلی نمی‌نشیند (`BizJournal::LOCKED`): ماندهِ صندوق،
 *   چک، طرف‌حساب و انبار از سندهای خودشان است و `reconcile()` همان را با صفحه‌ها
 *   می‌سنجد؛ دارایی‌ی ثابت و استهلاکش هم فقط از «دارایی‌های ثابت».
 * - ⛔ استهلاک ذخیره نمی‌شود: خطِ مستقیمِ ماهانه از تاریخِ خرید با لنگرِ روزِ شمسی
 *   (`jalaliAddMonths()`)، باقیمانده‌ی تقسیم به ماهِ آخر، تا کنار گذاشتن. پس ویرایشِ
 *   بها یا عمر (که گذشته را عوض می‌کرد) وجود ندارد — حذف و ثبتِ دوباره، با سدِ دوره‌ی بسته.
 * - اثرِ سود و زیانِ این دو منبع (`BizBooks::plAdjust()`) کنارِ `BizReports::profit()`
 *   می‌نشیند تا سودِ صفحه‌ها با ترازنامه یکی بماند.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/biz_docs.php';

final class BizBooks
{
    public static function ready(): bool
    {
        return tableHasColumn('biz_payments', 'asset_id');
    }

    /**
     * ⛔ اثرِ سندِ دستی و استهلاک بر سود و زیانِ یک بازه (بستانکار − بدهکارِ حساب‌های
     *    درآمد و هزینه) — تنها جایی که `profit()` از این دو منبع خبر می‌گیرد.
     */
    public static function plAdjust(int $userId, string $from, string $to): int
    {
        if (!self::ready()) { return 0; }
        // ⛔ نوعِ حساب از رقمِ اولِ کد است (۴ درآمد، ۵ هزینه — `BizCoa::PREFIX`، برای حساب‌های
        //    دلخواه هم اجباری)، پس جمع بی‌خواندنِ فهرستِ حساب‌ها در خودِ SQL درمی‌آید
        $st = Database::getConnection()->prepare(
            "SELECT COALESCE(SUM(CAST(l.credit AS SIGNED) - CAST(l.debit AS SIGNED)), 0)
             FROM biz_journals j JOIN biz_journal_lines l ON l.journal_id = j.id AND l.user_id = j.user_id
             WHERE j.user_id = :u AND j.status = 'ok' AND j.jdate BETWEEN :f AND :t AND LEFT(l.code, 1) IN ('4', '5')"
        );
        $st->execute(['u' => $userId, 'f' => $from !== '' ? $from : '0000-01-01', 't' => $to]);
        $sum = (int)$st->fetchColumn();
        // دارایی‌ها فقط هزینه می‌سازند (استهلاک و زیانِ کنار گذاشتن) — هر دو در حساب‌های ۵
        foreach (BizAssets::entries($userId, $from, $to) as $e) {
            foreach ($e['lines'] as [$code, , , $d, $c]) {
                if ($code[0] === '4' || $code[0] === '5') { $sum += $c - $d; }
            }
        }
        return $sum;
    }
}

/* =================================================================
   حساب‌های دلخواه
   ================================================================= */
final class BizCoa
{
    /** ⛔ رقمِ اولِ کد = نوع (همان چیدمانِ `BizLedger::ACCOUNTS`) — مرتب و خوانا می‌ماند. */
    public const PREFIX = ['asset' => '1', 'liability' => '2', 'equity' => '3', 'income' => '4', 'expense' => '5'];
    public const NAME_MAX = 80;
    public const MAX = 300;

    /** @var array<int, array> */
    private static array $cache = [];

    /** @return list<array{id:int, code:string, name:string, type:string, is_active:int, used:int}> */
    public static function custom(int $userId): array
    {
        if (!BizBooks::ready()) { return []; }
        $st = Database::getConnection()->prepare(
            "SELECT c.id, c.code, c.name, c.type, c.is_active,
                    (SELECT COUNT(*) FROM biz_journal_lines l WHERE l.user_id = c.user_id AND l.code = c.code) AS used
             FROM biz_coa c WHERE c.user_id = :u ORDER BY c.code"
        );
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    }

    /**
     * کد ← [نام، نوع، سیستمی؟، فعال؟] — حساب‌های برنامه به‌علاوه‌ی دلخواهِ همین فروشگاه.
     * @return array<string, array{0:string, 1:string, 2:bool, 3:bool}>
     */
    public static function map(int $userId): array
    {
        if (isset(self::$cache[$userId])) { return self::$cache[$userId]; }
        $out = [];
        foreach (BizLedger::ACCOUNTS as $k => [$n, $t]) { $out[(string)$k] = [$n, $t, true, true]; }
        foreach (self::custom($userId) as $c) { $out[(string)$c['code']] = [(string)$c['name'], (string)$c['type'], false, (int)$c['is_active'] === 1]; }
        ksort($out, SORT_STRING);
        return self::$cache[$userId] = $out;
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    /** @return array{ok:bool, message:string, id?:int} */
    public static function save(int $userId, string $code, string $name, string $type, int $id = 0): array
    {
        if (!BizBooks::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $code = toLatinDigits(trim($code));
        $name = BizCommon::line($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) { return ['ok' => false, 'message' => 'نامِ حساب را بنویسید (حداکثر ' . toPersianDigits((string)self::NAME_MAX) . ' نویسه).']; }
        $pdo = Database::getConnection();
        if ($id > 0) {
            $st = $pdo->prepare('SELECT id FROM biz_coa WHERE id = :id AND user_id = :u');
            $st->execute(['id' => $id, 'u' => $userId]);
            if (!$st->fetchColumn()) { return ['ok' => false, 'message' => 'حساب پیدا نشد.']; }
            // ⛔ کد و نوع ثابت‌اند: عوض کردنشان سندهای گذشته را به حسابِ دیگری می‌برد
            $pdo->prepare('UPDATE biz_coa SET name = :n WHERE id = :id AND user_id = :u')->execute(['n' => $name, 'id' => $id, 'u' => $userId]);
            self::forget();
            return ['ok' => true, 'message' => 'نامِ حساب ذخیره شد.', 'id' => $id];
        }
        if (!isset(self::PREFIX[$type])) { return ['ok' => false, 'message' => 'نوعِ حساب معتبر نیست.']; }
        if (!preg_match('/^\d{4,8}$/', $code)) { return ['ok' => false, 'message' => 'کدِ حساب ۴ تا ۸ رقم است.']; }
        if ($code[0] !== self::PREFIX[$type]) {
            return ['ok' => false, 'message' => 'کدِ حسابِ «' . BizLedger::TYPES[$type] . '» با ' . toPersianDigits(self::PREFIX[$type]) . ' شروع می‌شود.'];
        }
        if (isset(BizLedger::ACCOUNTS[$code])) { return ['ok' => false, 'message' => 'این کد مالِ حساب‌های خودِ برنامه است.']; }
        if (count(self::custom($userId)) >= self::MAX) { return ['ok' => false, 'message' => 'سقفِ حساب‌های دلخواه پر است.']; }
        try {
            $pdo->prepare('INSERT INTO biz_coa (user_id, code, name, type) VALUES (:u, :c, :n, :t)')
                ->execute(['u' => $userId, 'c' => $code, 'n' => $name, 't' => $type]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { return ['ok' => false, 'message' => 'این کد از قبل هست.']; }
            throw $e;
        }
        self::forget();
        return ['ok' => true, 'message' => 'حسابِ ' . toPersianDigits($code) . ' ساخته شد.', 'id' => (int)$pdo->lastInsertId()];
    }

    /** غیرفعال = در فرمِ سندِ تازه نیست؛ سندهای گذشته سرِ جایشان. */
    public static function setActive(int $userId, int $id, bool $on): array
    {
        if (!BizBooks::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $st = Database::getConnection()->prepare('UPDATE biz_coa SET is_active = :a WHERE id = :id AND user_id = :u');
        $st->execute(['a' => $on ? 1 : 0, 'id' => $id, 'u' => $userId]);
        self::forget();
        return $st->rowCount() > 0 || $on ? ['ok' => true, 'message' => $on ? 'حساب فعال شد.' : 'حساب غیرفعال شد.'] : ['ok' => false, 'message' => 'حساب پیدا نشد.'];
    }
}

/* =================================================================
   سندِ حسابداریِ دستی
   ================================================================= */
final class BizJournal
{
    /**
     * ⛔ حساب‌هایی که سندِ دستی نمی‌گیرند — ماندهِ هر کدام از سندهای خودش است:
     *    صندوق و بانک، چک‌های در جریان (هر دو سو)، طرف‌حساب‌ها، انبار، دارایی‌ی ثابت و استهلاکش.
     *    سندِ دستی رویشان یعنی دفتری که با صفحه‌ها نمی‌خواند (`reconcile()`).
     */
    public const LOCKED = ['1101', '1102', '1103', '1104', '2102', '1201', '1202'];
    public const LINES_MAX = 40;
    public const DESC_MAX = 255;

    /** حساب‌هایی که فرمِ سندِ دستی پیشنهاد می‌کند. @return array<string, string> کد ← نام */
    public static function codes(int $userId): array
    {
        $out = [];
        foreach (BizCoa::map($userId) as $k => [$n, , , $on]) {
            if ($on && !in_array((string)$k, self::LOCKED, true)) { $out[(string)$k] = $n; }
        }
        return $out;
    }

    /**
     * @param list<array{code?:string, debit?:mixed, credit?:mixed, note?:string}> $lines
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function create(int $userId, string $date, string $desc, array $lines): array
    {
        if (!BizBooks::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        if (!isValidDate($date)) { return ['ok' => false, 'message' => 'تاریخِ سند معتبر نیست.']; }
        $desc = BizCommon::line($desc);
        if ($desc === '' || mb_strlen($desc) > self::DESC_MAX) { return ['ok' => false, 'message' => 'شرحِ سند را بنویسید.']; }
        $codes = self::codes($userId);
        $clean = []; $dr = 0; $cr = 0;
        foreach ($lines as $i => $l) {
            $code = toLatinDigits(trim((string)($l['code'] ?? '')));
            $d = sanitizeAmount((string)($l['debit'] ?? ''));
            $c = sanitizeAmount((string)($l['credit'] ?? ''));
            if ($code === '' && $d === 0 && $c === 0) { continue; }                     // ردیفِ خالیِ فرم
            $no = toPersianDigits((string)($i + 1));
            if (in_array($code, self::LOCKED, true)) {
                return ['ok' => false, 'message' => "ردیفِ {$no}: این حساب از سندهای خودش پر می‌شود (صندوق، چک، طرف‌حساب، انبار، دارایی‌ی ثابت) — سندِ دستی نمی‌گیرد."];
            }
            if (!isset($codes[$code])) { return ['ok' => false, 'message' => "ردیفِ {$no}: حساب را انتخاب کنید."]; }
            if (($d > 0) === ($c > 0)) { return ['ok' => false, 'message' => "ردیفِ {$no}: فقط یکی از بدهکار یا بستانکار."]; }
            foreach ([$d, $c] as $v) {
                if (($e = BizCommon::moneyError($v, 'مبلغ')) !== null) { return ['ok' => false, 'message' => "ردیفِ {$no}: {$e}"]; }
            }
            $note = BizCommon::line((string)($l['note'] ?? ''));
            $clean[] = [$code, $d, $c, mb_substr($note, 0, 160)];
            $dr += $d; $cr += $c;
        }
        if (count($clean) < 2) { return ['ok' => false, 'message' => 'سند دست‌کم دو ردیف دارد.']; }
        if (count($clean) > self::LINES_MAX) { return ['ok' => false, 'message' => 'سند حداکثر ' . toPersianDigits((string)self::LINES_MAX) . ' ردیف دارد.']; }
        if ($dr !== $cr) {
            return ['ok' => false, 'message' => 'سند تراز نیست: بدهکار ' . formatMoney($dr) . ' و بستانکار ' . formatMoney($cr) . '.'];
        }
        if (($e = Biz::lockError($userId, $date, 'سندِ حسابداری')) !== null) { return ['ok' => false, 'message' => $e]; }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            Biz::lockShop($pdo, $userId);
            $n = $pdo->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM biz_journals WHERE user_id = :u FOR UPDATE');
            $n->execute(['u' => $userId]);
            $number = (int)$n->fetchColumn();
            $actor = class_exists('Auth') && Auth::userId() !== null ? (int)Auth::userId() : $userId;
            $pdo->prepare('INSERT INTO biz_journals (user_id, number, jdate, description, created_by) VALUES (:u, :n, :d, :s, :a)')
                ->execute(['u' => $userId, 'n' => $number, 'd' => $date, 's' => $desc, 'a' => $actor]);
            $jid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO biz_journal_lines (user_id, journal_id, code, debit, credit, note) VALUES (:u, :j, :c, :d, :r, :n)');
            foreach ($clean as [$code, $d, $c, $note]) {
                $ins->execute(['u' => $userId, 'j' => $jid, 'c' => $code, 'd' => $d, 'r' => $c, 'n' => $note === '' ? null : $note]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => 'سندِ حسابداریِ شماره‌ی ' . toPersianDigits((string)$number) . ' ثبت شد.', 'id' => $jid];
    }

    /** ابطال — شماره می‌ماند (جای خالی در شماره‌ها نمی‌افتد). */
    public static function void(int $userId, int $id): array
    {
        if (!BizBooks::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $j = self::get($userId, $id);
        if (!$j || $j['status'] !== 'ok') { return ['ok' => false, 'message' => 'سند پیدا نشد یا از قبل باطل است.']; }
        if (($e = Biz::lockError($userId, (string)$j['jdate'], 'این سند')) !== null) { return ['ok' => false, 'message' => $e]; }
        Database::getConnection()->prepare("UPDATE biz_journals SET status = 'void' WHERE id = :id AND user_id = :u")
            ->execute(['id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => 'سندِ شماره‌ی ' . toPersianDigits((string)$j['number']) . ' باطل شد.'];
    }

    public static function get(int $userId, int $id): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM biz_journals WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        $j = $st->fetch();
        if (!$j) { return null; }
        $l = Database::getConnection()->prepare('SELECT code, debit, credit, note FROM biz_journal_lines WHERE journal_id = :j AND user_id = :u ORDER BY id');
        $l->execute(['j' => $id, 'u' => $userId]);
        $j['lines'] = $l->fetchAll();
        return $j;
    }

    /** آخرین سندها (با ردیف‌ها) برای زبانه‌ی «سندِ دستی». */
    public static function recent(int $userId, int $limit = 50): array
    {
        if (!BizBooks::ready()) { return []; }
        $st = Database::getConnection()->prepare(
            'SELECT j.id, j.number, j.jdate, j.description, j.status, SUM(l.debit) AS total
             FROM biz_journals j JOIN biz_journal_lines l ON l.journal_id = j.id AND l.user_id = j.user_id
             WHERE j.user_id = :u GROUP BY j.id ORDER BY j.jdate DESC, j.number DESC LIMIT :lim'
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** سندهای زنده‌ی بازه به شکلِ `BizLedger::entries()`. */
    public static function entries(int $userId, string $from, string $to): array
    {
        if (!BizBooks::ready()) { return []; }
        $st = Database::getConnection()->prepare(
            "SELECT j.id, j.number, j.jdate, j.description, l.code, l.debit, l.credit
             FROM biz_journals j JOIN biz_journal_lines l ON l.journal_id = j.id AND l.user_id = j.user_id
             WHERE j.user_id = :u AND j.status = 'ok' AND j.jdate BETWEEN :f AND :t ORDER BY j.jdate, j.number, l.id"
        );
        $st->execute(['u' => $userId, 'f' => $from !== '' ? $from : '0000-01-01', 't' => $to]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $k = (int)$r['id'];
            $out[$k] ??= ['date' => (string)$r['jdate'], 'ref' => 'accounting.php?t=vouchers#j' . $k,
                          'desc' => 'سندِ دستیِ ' . toPersianDigits((string)$r['number']) . ' — ' . $r['description'], 'lines' => []];
            $out[$k]['lines'][] = [(string)$r['code'], '', '', (int)$r['debit'], (int)$r['credit']];
        }
        return array_values($out);
    }
}

/* =================================================================
   دارایی‌های ثابت و استهلاک
   ================================================================= */
final class BizAssets
{
    public const FUNDING = ['cash' => 'پرداخت از صندوق یا بانک', 'owner' => 'آورده‌ی مالک (بی‌پرداخت)'];
    public const LIFE_MAX = 600;
    public const NAME_MAX = 120;

    /**
     * ⛔ جدولِ استهلاکِ خطِ مستقیم — تنها محاسبه. ماهِ m (۱..عمر) در `jalaliAddMonths(خرید، m)`
     *    با لنگرِ روزِ شمسیِ خرید؛ باقیمانده‌ی تقسیم به ماهِ آخر (جمع دقیقاً = بها − اسقاط).
     * @return list<array{date:string, amount:int}>
     */
    public static function schedule(array $a): array
    {
        $life = max(1, (int)$a['life_months']);
        $base = max(0, (int)$a['cost'] - (int)$a['salvage']);
        $per  = intdiv($base, $life);
        $anchor = jalaliDayOfDate((string)$a['acq_date']);
        $out = [];
        for ($m = 1; $m <= $life; $m++) {
            $out[] = ['date' => jalaliAddMonths((string)$a['acq_date'], $m, $anchor),
                      'amount' => $m === $life ? $base - $per * ($life - 1) : $per];
        }
        return $out;
    }

    /** استهلاکِ انباشته تا یک روز (و نه بعد از کنار گذاشتن). */
    public static function accumulated(array $a, string $at): int
    {
        $stop = $a['disposed_on'] !== null && (string)$a['disposed_on'] < $at ? (string)$a['disposed_on'] : $at;
        $sum = 0;
        foreach (self::schedule($a) as $s) { if ($s['date'] <= $stop) { $sum += $s['amount']; } }
        return $sum;
    }

    /** @return list<array> هر دارایی با انباشته، ارزشِ دفتری و قسطِ ماهانه در `$at`. */
    public static function list(int $userId, string $at = ''): array
    {
        if (!BizBooks::ready()) { return []; }
        $at = $at !== '' ? $at : date('Y-m-d');
        $st = Database::getConnection()->prepare(
            "SELECT f.*, y.id AS payment_id, y.number AS pay_no, a.name AS account_name
             FROM biz_fixed_assets f
             LEFT JOIN biz_payments y ON y.asset_id = f.id AND y.user_id = f.user_id AND y.status = 'ok'
             LEFT JOIN biz_accounts a ON a.id = y.account_id AND a.user_id = y.user_id
             WHERE f.user_id = :u ORDER BY f.acq_date DESC, f.id DESC"
        );
        $st->execute(['u' => $userId]);
        $out = [];
        foreach ($st->fetchAll() as $a) {
            $acc = self::accumulated($a, $at);
            $out[] = $a + ['accumulated' => $acc, 'book' => (int)$a['cost'] - $acc,
                           'monthly' => intdiv(max(0, (int)$a['cost'] - (int)$a['salvage']), max(1, (int)$a['life_months']))];
        }
        return $out;
    }

    public static function get(int $userId, int $id): ?array
    {
        if (!BizBooks::ready()) { return null; }
        $st = Database::getConnection()->prepare('SELECT * FROM biz_fixed_assets WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->fetch() ?: null;
    }

    /**
     * خریدِ دارایی. پرداخت از صندوق = سندِ «خریدِ دارایی‌ی ثابت» (`biz_payments.kind = asset`،
     * با پرچمِ داخلیِ `_asset`) در همان تراکنش؛ آورده‌ی مالک = بی‌پول (بدهکار دارایی، بستانکار آورده).
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function create(int $userId, array $in): array
    {
        if (!BizBooks::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $name = BizCommon::line((string)($in['name'] ?? ''));
        $date = (string)($in['acq_date'] ?? '');
        $cost = sanitizeAmount((string)($in['cost'] ?? ''));
        $salv = sanitizeAmount((string)($in['salvage'] ?? ''));
        $life = (int)toLatinDigits(trim((string)($in['life_months'] ?? '')));
        $fund = (string)($in['funding'] ?? 'cash');
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 300);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) { return ['ok' => false, 'message' => 'نامِ دارایی را بنویسید.']; }
        if (!isValidDate($date)) { return ['ok' => false, 'message' => 'تاریخِ خرید معتبر نیست.']; }
        if ($cost <= 0) { return ['ok' => false, 'message' => 'بهای خرید باید بیشتر از صفر باشد.']; }
        if (($e = BizCommon::moneyError($cost, 'بهای خرید')) !== null) { return ['ok' => false, 'message' => $e]; }
        if ($salv >= $cost) { return ['ok' => false, 'message' => 'ارزشِ اسقاط باید کمتر از بهای خرید باشد.']; }
        if ($life < 1 || $life > self::LIFE_MAX) { return ['ok' => false, 'message' => 'عمرِ مفید ۱ تا ' . toPersianDigits((string)self::LIFE_MAX) . ' ماه است.']; }
        if (!isset(self::FUNDING[$fund])) { $fund = 'cash'; }
        if (($e = Biz::lockError($userId, $date, 'خریدِ دارایی')) !== null) { return ['ok' => false, 'message' => $e]; }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            Biz::lockShop($pdo, $userId);
            $pdo->prepare('INSERT INTO biz_fixed_assets (user_id, name, acq_date, cost, salvage, life_months, funding, note)
                           VALUES (:u, :n, :d, :c, :s, :l, :f, :no)')
                ->execute(['u' => $userId, 'n' => $name, 'd' => $date, 'c' => $cost, 's' => $salv, 'l' => $life, 'f' => $fund,
                           'no' => $note === '' ? null : $note]);
            $id = (int)$pdo->lastInsertId();
            if ($fund === 'cash') {
                $r = BizPay::createTx($pdo, $userId, ['kind' => 'asset', '_asset' => true, 'amount' => (string)$cost,
                    'account_id' => (int)($in['account_id'] ?? 0), 'pay_date' => $date, 'method' => (string)($in['method'] ?? 'cash'),
                    'title' => 'خریدِ ' . $name]);
                if (!$r['ok']) { $pdo->rollBack(); return $r; }
                $pdo->prepare('UPDATE biz_payments SET asset_id = :a WHERE id = :id AND user_id = :u')
                    ->execute(['a' => $id, 'id' => (int)$r['id'], 'u' => $userId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => 'دارایی‌ی «' . $name . '» ثبت شد.', 'id' => $id];
    }

    /** کنار گذاشتن (فروش، اسقاط، از دست رفتن): استهلاک می‌ایستد و ارزشِ دفتری زیان می‌شود. */
    public static function dispose(int $userId, int $id, string $date): array
    {
        $a = self::get($userId, $id);
        if (!$a) { return ['ok' => false, 'message' => 'دارایی پیدا نشد.']; }
        if ($a['disposed_on'] !== null) { return ['ok' => false, 'message' => 'این دارایی از قبل کنار گذاشته شده.']; }
        if (!isValidDate($date) || $date < (string)$a['acq_date']) { return ['ok' => false, 'message' => 'تاریخِ کنار گذاشتن معتبر نیست (پیش از خرید نمی‌شود).']; }
        if (($e = Biz::lockError($userId, $date, 'کنار گذاشتنِ دارایی')) !== null) { return ['ok' => false, 'message' => $e]; }
        Database::getConnection()->prepare('UPDATE biz_fixed_assets SET disposed_on = :d WHERE id = :id AND user_id = :u')
            ->execute(['d' => $date, 'id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => '«' . $a['name'] . '» کنار گذاشته شد. اگر فروختید، پولش را «درآمدِ متفرقه» ثبت کنید.'];
    }

    public static function undispose(int $userId, int $id): array
    {
        $a = self::get($userId, $id);
        if (!$a || $a['disposed_on'] === null) { return ['ok' => false, 'message' => 'دارایی پیدا نشد یا کنار گذاشته نشده.']; }
        if (($e = Biz::lockError($userId, (string)$a['disposed_on'], 'کنار گذاشتنِ دارایی')) !== null) { return ['ok' => false, 'message' => $e]; }
        Database::getConnection()->prepare('UPDATE biz_fixed_assets SET disposed_on = NULL WHERE id = :id AND user_id = :u')
            ->execute(['id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => '«' . $a['name'] . '» به فهرستِ دارایی‌ها برگشت.'];
    }

    /**
     * ⛔ حذف = خرید هرگز نبوده: پرداختش باطل می‌شود و همه‌ی استهلاکش می‌رود — پس فقط وقتی
     *    هیچ‌کدام در دوره‌ی بسته نیست (تاریخِ خرید). کنارگذاشته را اول برگردانید.
     */
    public static function remove(int $userId, int $id): array
    {
        $a = self::get($userId, $id);
        if (!$a) { return ['ok' => false, 'message' => 'دارایی پیدا نشد.']; }
        if ($a['disposed_on'] !== null) { return ['ok' => false, 'message' => 'اول «برگرداندن از کنار گذاشته» را بزنید.']; }
        if (($e = Biz::lockError($userId, (string)$a['acq_date'], 'این دارایی')) !== null) { return ['ok' => false, 'message' => $e]; }
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT id FROM biz_payments WHERE asset_id = :a AND user_id = :u AND status = 'ok'");
        $st->execute(['a' => $id, 'u' => $userId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $r = BizPay::void($userId, (int)$pid, true);
            if (!$r['ok']) { return $r; }
        }
        $pdo->prepare('DELETE FROM biz_fixed_assets WHERE id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $userId]);
        return ['ok' => true, 'message' => '«' . $a['name'] . '» حذف شد و پرداختِ خریدش باطل شد.'];
    }

    /**
     * سندهای بازه به شکلِ `BizLedger::entries()`: خریدِ آورده‌ای، استهلاکِ هر تاریخ (همه‌ی
     * دارایی‌ها در یک سند) و کنار گذاشتن. ⛔ خریدِ نقدی از سندِ پرداختش می‌آید، نه این‌جا.
     */
    public static function entries(int $userId, string $from, string $to): array
    {
        if (!BizBooks::ready()) { return []; }
        $f = $from !== '' ? $from : '0000-01-01';
        $st = Database::getConnection()->prepare('SELECT * FROM biz_fixed_assets WHERE user_id = :u AND acq_date <= :t ORDER BY acq_date, id');
        $st->execute(['u' => $userId, 't' => $to]);
        $out = []; $dep = [];
        foreach ($st->fetchAll() as $a) {
            $fk = 'f' . $a['id']; $nm = (string)$a['name'];
            if ($a['funding'] === 'owner' && $a['acq_date'] >= $f) {
                $out[] = ['date' => (string)$a['acq_date'], 'ref' => 'accounting.php?t=assets', 'desc' => 'خریدِ دارایی با آورده‌ی مالک — ' . $nm,
                          'lines' => [['1201', $fk, $nm, (int)$a['cost'], 0], ['3102', '', '', 0, (int)$a['cost']]]];
            }
            $stop = $a['disposed_on'] !== null ? (string)$a['disposed_on'] : '9999-12-31';
            foreach (self::schedule($a) as $s) {
                if ($s['date'] > $to || $s['date'] > $stop) { break; }
                if ($s['date'] < $f || $s['amount'] === 0) { continue; }
                $dep[$s['date']][] = [$fk, $nm, $s['amount']];
            }
            if ($a['disposed_on'] !== null && $stop >= $f && $stop <= $to) {
                $acc = self::accumulated($a, $stop);
                $out[] = ['date' => $stop, 'ref' => 'accounting.php?t=assets', 'desc' => 'کنار گذاشتنِ دارایی — ' . $nm,
                          'lines' => array_values(array_filter([['1202', $fk, $nm, $acc, 0], ['5203', '', '', (int)$a['cost'] - $acc, 0],
                                                                ['1201', $fk, $nm, 0, (int)$a['cost']]], fn($x) => $x[3] !== 0 || $x[4] !== 0))];
            }
        }
        foreach ($dep as $d => $rows) {
            $lines = [['5202', '', '', array_sum(array_column($rows, 2)), 0]];
            foreach ($rows as [$fk, $nm, $v]) { $lines[] = ['1202', $fk, $nm, 0, $v]; }
            $out[] = ['date' => (string)$d, 'ref' => 'accounting.php?t=assets', 'desc' => 'استهلاکِ ماهانه‌ی دارایی‌های ثابت', 'lines' => $lines];
        }
        return $out;
    }
}
