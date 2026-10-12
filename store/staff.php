<?php
/**
 * همکاران و دسترسی‌ها — فقط صاحبِ فروشگاه (`Biz::PAGE_CAPS['staff.php']`).
 *
 * هر همکار ورودِ خودش را دارد (همین آدرسِ `/store`)، نقشی از `Biz::STAFF_ROLES`
 * و درصدِ پورسانت. منطق فقط `BizStaff`؛ این صفحه فرم است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_staff.php';

$userId = Biz::ownerId();
$self   = Biz::url('staff.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    $sid    = (int)postParam('staff_row');
    $res = match ($action) {
        'add'      => BizStaff::add($userId, ['full_name' => postParam('full_name'), 'username' => postParam('username'),
                                                'password' => (string)($_POST['password'] ?? ''), 'role' => postParam('role'),
                                                'commission_pct' => postParam('commission_pct')]),
        'update'   => BizStaff::update($userId, $sid, ['role' => postParam('role'), 'commission_pct' => postParam('commission_pct'),
                                                       'is_active' => postParam('is_active') === '1']),
        'password' => BizStaff::resetPassword($userId, $sid, (string)($_POST['password'] ?? '')),
        'remove'   => BizStaff::remove($userId, $sid),
        default    => ['ok' => false, 'message' => 'درخواست نامعتبر است.'],
    };
    redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
}

$staff = BizStaff::list($userId);
$roles = BizStaff::roles();

$pageTitle = 'همکاران و دسترسی‌ها';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">همکاران و دسترسی‌ها</h1>
        <p class="st-muted">هر همکار با نام کاربری و رمزِ خودش از همین آدرس وارد می‌شود و فقط بخش‌هایی را می‌بیند که نقشش اجازه می‌دهد. هر سند نامِ ثبت‌کننده‌اش را نگه می‌دارد.</p>
    </div>
</div>

<?php if (!Biz::staffReady()): ?>
    <div class="st-flash st-flash-err">این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.</div>
<?php else: ?>

<section class="st-card">
    <h2 class="st-h2">نقش‌ها چه می‌بینند</h2>
    <div class="st-table-wrap">
        <table class="st-table st-role-table">
            <thead><tr><th>دسترسی</th><?php foreach (Biz::STAFF_ROLES as $rk => $r): ?><th class="st-th-num"><?= h($r['label']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach (Biz::CAPS as $ck => $cl): ?>
                <tr><td><?= h($cl) ?></td>
                <?php foreach (Biz::STAFF_ROLES as $r): $on = in_array('*', $r['caps'], true) || in_array($ck, $r['caps'], true); ?>
                    <td class="st-td-num"><?= $on ? '<span class="st-yes" aria-label="دارد">✓</span>' : '<span class="st-muted-i" aria-label="ندارد">—</span>' ?></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="st-card">
    <h2 class="st-h2">همکارِ تازه</h2>
    <form method="post" class="st-form" action="<?= h($self) ?>" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="add">
        <div class="st-row2">
            <label class="st-field"><span>نام و نام خانوادگی</span><input type="text" name="full_name" required maxlength="100"></label>
            <label class="st-field"><span>نقش</span>
                <select name="role"><?php foreach ($roles as $rk => $rl): ?><option value="<?= h($rk) ?>"><?= h($rl) ?></option><?php endforeach; ?></select>
            </label>
        </div>
        <div class="st-row2">
            <label class="st-field"><span>نام کاربری (برای ورود)</span><input type="text" name="username" required maxlength="50" dir="ltr" autocomplete="off"></label>
            <label class="st-field"><span>رمز</span><input type="password" name="password" required autocomplete="new-password"></label>
        </div>
        <label class="st-field st-field-sm"><span>پورسانت از فروشِ خالص (٪) — اختیاری</span><input data-num type="text" name="commission_pct" inputmode="decimal" dir="ltr" placeholder="۰"></label>
        <button type="submit" class="st-btn">افزودنِ همکار</button>
    </form>
</section>

<?php if (!$staff): ?>
    <div class="st-card st-empty-card"><p class="st-empty">هنوز همکاری اضافه نکرده‌اید.</p></div>
<?php else: ?>
<div class="st-staff-list">
<?php foreach ($staff as $s): ?>
    <section class="st-card st-staff<?= (int)$s['is_active'] === 1 ? '' : ' is-inactive' ?>">
        <div class="st-staff-head">
            <div>
                <h3 class="st-h3"><?= h($s['full_name']) ?></h3>
                <p class="st-muted-i"><span dir="ltr"><?= h($s['username']) ?></span> · <?= h($roles[$s['role']] ?? $s['role']) ?><?= (int)$s['is_active'] === 1 ? '' : ' · غیرفعال' ?></p>
            </div>
        </div>
        <form method="post" class="st-inline-form" action="<?= h($self) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="staff_row" value="<?= (int)$s['id'] ?>">
            <label class="st-field"><span>نقش</span>
                <select name="role"><?php foreach ($roles as $rk => $rl): ?><option value="<?= h($rk) ?>"<?= $rk === $s['role'] ? ' selected' : '' ?>><?= h($rl) ?></option><?php endforeach; ?></select>
            </label>
            <label class="st-field st-field-sm"><span>پورسانت (٪)</span><input data-num type="text" name="commission_pct" inputmode="decimal" dir="ltr" value="<?= h(rtrim(rtrim((string)$s['commission_pct'], '0'), '.')) ?>"></label>
            <label class="st-check"><input type="checkbox" name="is_active" value="1"<?= (int)$s['is_active'] === 1 ? ' checked' : '' ?>> فعال</label>
            <button type="submit" class="st-btn st-btn-ghost">ذخیره</button>
        </form>
        <div class="st-staff-actions">
            <form method="post" class="st-inline-form" action="<?= h($self) ?>" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="password">
                <input type="hidden" name="staff_row" value="<?= (int)$s['id'] ?>">
                <label class="st-field"><span>رمزِ تازه</span><input type="password" name="password" autocomplete="new-password"></label>
                <button type="submit" class="st-btn st-btn-ghost">عوض کردنِ رمز</button>
            </form>
            <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('همکار برداشته شود؟ سندهایی که ثبت کرده می‌مانند، ولی دیگر نمی‌تواند وارد شود.');">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="staff_row" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="st-link-btn st-link-danger">برداشتنِ همکار</button>
            </form>
        </div>
    </section>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
