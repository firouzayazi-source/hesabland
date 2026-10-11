<?php
/**
 * ⛔ همکارانِ فروشگاه — تنها نویسنده‌ی `store_staff` (migration_biz_staff).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** فروشِ کشوری؛ فروشگاهی که فروشنده و حسابدار
 * دارد نباید رمزِ صاحب را به همه بدهد.
 *
 * - هر همکار **حسابِ ورودِ خودش** را دارد (`users`، نوعِ «فقط فروشگاه») و هیچ داده‌ای
 *   زیرِ شناسه‌ی خودش ندارد؛ همه‌چیز زیرِ صاحبِ فروشگاه است (`Biz::ownerId()`).
 * - نقش فقط از `Biz::STAFF_ROLES` (جز `owner`)؛ دسترسی از `Biz::can()`.
 * - غیرفعال کردن، تغییرِ نقش و حذف همه‌ی دسترسی‌های همان همکار را باطل می‌کنند
 *   (`revokeAllAccessFor()`) — نشستِ باز با نقشِ قبلی نمی‌ماند.
 * - حذفِ صاحبِ فروشگاه حسابِ همکارانش را هم می‌برد (`deleteUserAccount()`)، وگرنه
 *   همکارِ یتیم با ورودِ بعد یک فروشگاهِ خالیِ خودش را می‌دید.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/signup.php';

final class BizStaff
{
    /** سقفِ همکارانِ یک فروشگاه. */
    public const MAX = 30;

    /** سقفِ درصدِ پورسانت. */
    public const COMMISSION_MAX = 50;

    /** نقش‌هایی که صاحب می‌تواند بدهد (همه جز خودِ «صاحب»). */
    public static function roles(): array
    {
        $out = [];
        foreach (Biz::STAFF_ROLES as $k => $r) { if ($k !== 'owner') { $out[$k] = $r['label']; } }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function list(int $ownerId): array
    {
        if (!Biz::staffReady()) { return []; }
        $st = Database::getConnection()->prepare(
            'SELECT s.id, s.staff_id, s.role, s.commission_pct, s.is_active, s.created_at,
                    u.full_name, u.username
             FROM store_staff s JOIN users u ON u.id = s.staff_id
             WHERE s.user_id = :u ORDER BY s.is_active DESC, u.full_name'
        );
        $st->execute(['u' => $ownerId]);
        return $st->fetchAll();
    }

    /** ردیفِ یک همکار — فقط از همین فروشگاه. */
    public static function get(int $ownerId, int $id): ?array
    {
        $st = Database::getConnection()->prepare(
            'SELECT s.*, u.full_name, u.username FROM store_staff s JOIN users u ON u.id = s.staff_id
             WHERE s.id = :id AND s.user_id = :u LIMIT 1'
        );
        $st->execute(['id' => $id, 'u' => $ownerId]);
        return $st->fetch() ?: null;
    }

    private static function cleanRole(string $role): ?string
    {
        return isset(self::roles()[$role]) ? $role : null;
    }

    private static function cleanPct($raw): ?float
    {
        $s = str_replace(['٫', '/', ','], '.', toLatinDigits(trim((string)$raw)));
        if ($s === '') { return 0.0; }
        if (!is_numeric($s)) { return null; }
        $v = round((float)$s, 2);
        return $v < 0 || $v > self::COMMISSION_MAX ? null : $v;
    }

    /**
     * همکارِ تازه: حسابِ ورود + ردیفِ `store_staff` در **یک** تراکنش.
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function add(int $ownerId, array $in): array
    {
        if (!Biz::staffReady()) { return ['ok' => false, 'message' => 'همکاران هنوز راه نیفتاده است (migration_biz_staff).']; }
        $name = trim((string)($in['full_name'] ?? ''));
        $user = trim(toLatinDigits((string)($in['username'] ?? '')));
        $pass = (string)($in['password'] ?? '');
        $role = self::cleanRole((string)($in['role'] ?? ''));
        $pct  = self::cleanPct($in['commission_pct'] ?? '');
        if ($role === null) { return ['ok' => false, 'message' => 'نقش را انتخاب کنید.']; }
        if ($pct === null) { return ['ok' => false, 'message' => 'درصدِ پورسانت باید عددی بین ۰ و ' . toPersianDigits((string)self::COMMISSION_MAX) . ' باشد.']; }
        if ($pass === '') { return ['ok' => false, 'message' => 'برای همکار رمز بگذارید.']; }
        $pdo = Database::getConnection();
        // ⚠ نه `validateNewUser()`: آن ایمیل یا شماره می‌خواهد (راهِ بازیابیِ رمز)؛ رمزِ
        //   همکار را صاحب عوض می‌کند (`resetPassword()`). بقیه‌ی قاعده‌ها همان توابع‌اند.
        $err = $name === '' ? 'نامِ همکار را بنویسید.'
             : (mb_strlen($name) > 100 ? 'نام نباید بیشتر از ۱۰۰ کاراکتر باشد.'
             : (usernameRuleError($user) ?: passwordRuleError($pass)));
        if ($err === '') {
            $dup = $pdo->prepare('SELECT id FROM users WHERE username = :u');
            $dup->execute(['u' => $user]);
            if ($dup->fetch()) { $err = 'این نام کاربری قبلاً استفاده شده است.'; }
        }
        if ($err !== '') { return ['ok' => false, 'message' => $err]; }
        $n = $pdo->prepare('SELECT COUNT(*) FROM store_staff WHERE user_id = :u');
        $n->execute(['u' => $ownerId]);
        if ((int)$n->fetchColumn() >= self::MAX) {
            return ['ok' => false, 'message' => 'حداکثر ' . toPersianDigits((string)self::MAX) . ' همکار برای هر فروشگاه.'];
        }

        $pdo->beginTransaction();
        try {
            $acc = createUserAccount($pdo, $name, $user, '', $pass);
            if (empty($acc['id'])) { throw new RuntimeException((string)($acc['error'] ?? 'ساختِ حساب نشد.')); }
            $sid = (int)$acc['id'];
            // ⛔ «فقط فروشگاه»: دروازه او را از صفحه‌های شخصی بیرون نگه می‌دارد.
            Biz::markStaffLogin($pdo, $sid);
            $pdo->prepare('INSERT INTO store_staff (user_id, staff_id, role, commission_pct) VALUES (:u, :s, :r, :c)')
                ->execute(['u' => $ownerId, 's' => $sid, 'r' => $role, 'c' => $pct]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if (!$e instanceof RuntimeException) { Log::error('store.staff_add', $e); }
            return ['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'ساختِ همکار نشد.'];
        }
        Audit::log('store.staff_added', 'user', $sid, ['role' => $role], null, $sid);
        return ['ok' => true, 'id' => $id,
                'message' => '«' . $name . '» به‌عنوانِ ' . self::roles()[$role] . ' اضافه شد. با نامِ کاربریِ «' . $user . '» از همین آدرس وارد می‌شود.'];
    }

    /**
     * نقش، پورسانت و فعال بودن. ⛔ هر تغییرِ نقش یا غیرفعال کردن نشست‌های باز را می‌بندد.
     * @return array{ok:bool, message:string}
     */
    public static function update(int $ownerId, int $id, array $in): array
    {
        $row = self::get($ownerId, $id);
        if (!$row) { return ['ok' => false, 'message' => 'همکار پیدا نشد.']; }
        $role   = self::cleanRole((string)($in['role'] ?? ''));
        $pct    = self::cleanPct($in['commission_pct'] ?? '');
        $active = !empty($in['is_active']) ? 1 : 0;
        if ($role === null) { return ['ok' => false, 'message' => 'نقش را انتخاب کنید.']; }
        if ($pct === null) { return ['ok' => false, 'message' => 'درصدِ پورسانت باید عددی بین ۰ و ' . toPersianDigits((string)self::COMMISSION_MAX) . ' باشد.']; }
        Database::getConnection()->prepare('UPDATE store_staff SET role = :r, commission_pct = :c, is_active = :a WHERE id = :id AND user_id = :u')
            ->execute(['r' => $role, 'c' => $pct, 'a' => $active, 'id' => $id, 'u' => $ownerId]);
        $changed = $role !== $row['role'] || $active !== (int)$row['is_active'];
        if ($changed) {
            revokeAllAccessFor((int)$row['staff_id']);
            Audit::log('store.staff_changed', 'user', (int)$row['staff_id'], ['role' => $role, 'active' => $active], null, (int)$row['staff_id']);
        }
        return ['ok' => true, 'message' => 'ذخیره شد' . ($changed ? '؛ «' . $row['full_name'] . '» باید دوباره وارد شود.' : '.')];
    }

    /** رمزِ تازه برای همکار (فراموش کرده). @return array{ok:bool, message:string} */
    public static function resetPassword(int $ownerId, int $id, string $pass): array
    {
        $row = self::get($ownerId, $id);
        if (!$row) { return ['ok' => false, 'message' => 'همکار پیدا نشد.']; }
        if ($pass === '') { return ['ok' => false, 'message' => 'رمزِ تازه را بنویسید.']; }
        if (($e = passwordRuleError($pass)) !== '') { return ['ok' => false, 'message' => $e]; }
        Database::getConnection()->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
            ->execute(['p' => password_hash($pass, PASSWORD_DEFAULT), 'id' => (int)$row['staff_id']]);
        revokeAllAccessFor((int)$row['staff_id']);
        Audit::log('store.staff_changed', 'user', (int)$row['staff_id'], ['password' => 'reset'], null, (int)$row['staff_id']);
        return ['ok' => true, 'message' => 'رمزِ «' . $row['full_name'] . '» عوض شد؛ با رمزِ تازه وارد شود.'];
    }

    /**
     * حذفِ همکار: حسابِ ورودش می‌رود؛ سندهایی که ثبت کرده سرِ جایشان می‌مانند
     * (`created_by` فقط نامِ ثبت‌کننده را نشان نمی‌دهد دیگر).
     * @return array{ok:bool, message:string}
     */
    public static function remove(int $ownerId, int $id): array
    {
        $row = self::get($ownerId, $id);
        if (!$row) { return ['ok' => false, 'message' => 'همکار پیدا نشد.']; }
        Database::getConnection()->prepare('DELETE FROM store_staff WHERE id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $ownerId]);
        require_once __DIR__ . '/user_data.php';
        revokeAllAccessFor((int)$row['staff_id']);
        deleteUserAccount((int)$row['staff_id']);
        Audit::log('store.staff_removed', 'user', (int)$row['staff_id'], [], null, null);
        return ['ok' => true, 'message' => '«' . $row['full_name'] . '» از همکاران برداشته شد.'];
    }
}
