<?php
/**
 * ⛔ خطاهای فروشگاه — فقط برای مدیرِ کلِ نصب.
 *
 * **خواسته‌ی مالکِ نصب:** «یک سیستم لاگ برای باگ در فروشگاهم بیاد — الان
 * در حساب‌لند داریم — البته فقط برای سوپر ادمین.»
 *
 * همان `app_errors` و همان رسیدگیِ `admin/errors.php`، فقط ردیف‌هایی که
 * از محیطِ فروشگاه آمده‌اند (`AppErrors::AREAS`، `migration_error_area.sql`):
 * خطای PHP روی هر صفحه‌ی `store/`، و خطای جاوااسکریپتِ صفحه‌های فروشگاه.
 *
 * ⛔ مالکِ فروشگاه این صفحه را **نمی‌بیند و از وجودش هم خبر ندارد**:
 *    - در `Biz::NAV` نیست (منو، فرمانِ سریع و منوی پایین از آن ساخته
 *      می‌شوند)؛ لینکش را `biz_head.php` فقط برای مدیر رندر می‌کند.
 *    - برای غیرِ مدیر همان ۴۰۴ِ خنثیِ `Biz::notFound()` است، نه ۴۰۳ — ۴۰۳
 *      یعنی «اینجا چیزی هست که به تو نمی‌دهیم».
 *    خطای کد یک چیزِ داخلیِ نصب است، نه اطلاعاتِ فروشگاه؛ و فروشنده‌ای که
 *    این نرم‌افزار را خریده نباید متنِ خطای PHP و مسیرِ فایل‌ها را ببیند.
 *
 * ⛔ منطقِ دکمه‌ها فقط `AppErrors::handleAction()` با محیطِ `store`:
 *    «پاک کردن همه» اینجا خطاهای حساب‌لند را نمی‌برد و «برطرف شد» با
 *    شناسه‌ی یک خطای حساب‌لند کاری نمی‌کند.
 *
 * ⛔ و همچنان نه `user_id`، نه آدرس، نه نامِ فروشگاه — این صفحه درباره‌ی
 *    **کد** است (بالای `includes/app_errors.php`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
if (!Auth::isAdmin()) { Biz::notFound(); }
require_once __DIR__ . '/../includes/biz_catalog.php';

$area   = 'store';
$self   = Biz::url('errors.php');
$filter = getParam('f', 'open');
if (!isset(AppErrors::FILTERS[$filter])) { $filter = 'open'; }

// ⛔ هر نوشتنی CSRF دارد و بعدش ریدایرکت می‌شود (تازه‌سازی دوباره نمی‌فرستد).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $flash = AppErrors::handleAction(postParam('action'), (int)postParam('id', '0'), $area);
    header('Location: ' . $self . '?f=' . urlencode($filter) . ($flash !== '' ? '&done=' . urlencode($flash) : ''));
    exit;
}

$ready  = AppErrors::areaAvailable();
$rows   = $ready ? AppErrors::browse($filter, 500, $area) : [];
// صفحه‌بندیِ خودِ فروشگاه (`BizView::pager()`)، بیست ردیف — همان فهرستِ ۵۰۰تاییِ پنلِ مدیر
$perPg  = 20;
$pages  = max(1, (int)ceil(count($rows) / $perPg));
$pg     = min($pages, max(1, (int)getParam('page', '1')));
$shown  = array_slice($rows, ($pg - 1) * $perPg, $perPg);
$open   = $ready ? AppErrors::openCount($area) : 0;
$triage = AppErrors::triageAvailable();
$done   = (string)getParam('done');

$stamp = static function (?string $ts): string {
    if ($ts === null || $ts === '') { return '—'; }
    return toPersianDigits(toJalali(substr($ts, 0, 10)) . ' ' . substr($ts, 11, 5));
};

$doneText = '';
if ($done === 'resolved')                 { $doneText = 'خطا «برطرف شد» علامت خورد. اگر دوباره رخ دهد، خودبه‌خود به فهرستِ باز برمی‌گردد.'; }
elseif ($done === 'reopened')             { $doneText = 'به فهرستِ باز برگشت.'; }
elseif ($done === 'cleared')              { $doneText = 'کلِ فهرستِ خطاهای فروشگاه پاک شد.'; }
elseif (str_starts_with($done, 'purged:')) { $doneText = toPersianDigits((string)(int)substr($done, 7)) . ' خطای رسیدگی‌شده پاک شد.'; }

$pageTitle = 'خطاهای فروشگاه';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">خطاهای فروشگاه
            <?php if ($open > 0): ?><span class="st-badge is-error st-num"><?= h(toPersianDigits((string)$open)) ?> باز</span>
            <?php elseif ($ready): ?><span class="st-badge is-ok">همه رسیدگی شده</span><?php endif; ?>
        </h1>
        <p class="st-muted">خطاهای سرور و مرورگر روی صفحه‌های فروشگاه — فقط مدیرِ نصب این صفحه را می‌بیند. خطاهای حساب‌لند در پنلِ مدیر است.</p>
    </div>
    <?php if (!Biz::isStoreOnly()): ?>
    <a class="st-link-btn" href="<?= h(APP_BASE_PATH . '/admin/errors.php') ?>">همه‌ی خطاها (پنلِ مدیر)</a>
    <?php endif; ?>
</div>

<?php if ($doneText !== ''): ?><div class="st-flash st-flash-ok" role="status" data-toast><?= h($doneText) ?></div><?php endif; ?>

<?php if (!$ready): ?>
    <?php /* ⚠ بی‌ستونِ محیط، هیچ خطایی «فروشگاه» نیست — فهرستِ خالی با این پیام،
             نه فهرستِ حساب‌لند با برچسبِ غلط (`AppErrors::areaWhere()`). */ ?>
    <div class="st-flash st-flash-warn" role="status">migration_error_area اجرا نشده، پس خطاها هنوز به تفکیکِ محیط ثبت نمی‌شوند. روی سرور: <code dir="ltr">sudo ./hesabland deploy --migrate</code></div>
<?php else: ?>

<nav class="st-tabs" aria-label="صافیِ خطاها">
    <?php foreach (AppErrors::FILTERS as $fk => $fl): ?>
        <a href="<?= h($self . '?f=' . $fk) ?>" class="st-tab-chip<?= $fk === $filter ? ' is-active' : '' ?>"<?= $fk === $filter ? ' aria-current="page"' : '' ?>><?= h($fl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$shown): ?>
    <div class="st-card st-empty-card"><p class="st-empty">
        <?= $filter === 'open' ? 'هیچ خطای بازی در فروشگاه نیست.' : ($filter === 'resolved' ? 'هنوز چیزی «برطرف شد» علامت نخورده است.' : 'هیچ خطایی از فروشگاه ثبت نشده است.') ?>
    </p></div>
<?php else: ?>
<div class="st-errs">
    <?php foreach ($shown as $e):
        [$tone, $label] = AppErrors::levelInfo((string)$e['level']);
        $isResolved = ($e['resolved_at'] ?? null) !== null; ?>
    <article class="st-card st-err<?= $isResolved ? ' is-resolved' : '' ?>">
        <div class="st-err-main">
            <div class="st-err-head">
                <span class="st-badge <?= $tone === 'bad' ? 'is-error' : 'is-warn' ?>"><?= h($label) ?></span>
                <code class="st-err-where" dir="ltr"><?= h((string)$e['file']) ?>:<?= (int)$e['line'] ?></code>
            </div>
            <p class="st-err-msg" dir="auto"><?= h((string)$e['message']) ?></p>
            <p class="st-muted st-err-meta">
                <span class="st-num"><?= h(toPersianDigits((string)$e['hits'])) ?></span> بار
                · آخرین: <span class="st-num"><?= h($stamp((string)$e['last_seen'])) ?></span>
                · اولین: <span class="st-num"><?= h($stamp((string)$e['first_seen'])) ?></span>
                <?php if ($isResolved): ?>· برطرف شد: <span class="st-num"><?= h($stamp((string)$e['resolved_at'])) ?></span><?php endif; ?>
            </p>
        </div>
        <?php if ($triage): ?>
        <form method="post" action="<?= h($self . '?f=' . $filter) ?>" class="st-err-act">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <?php if ($isResolved): ?>
                <button type="submit" name="action" value="reopen" class="st-btn st-btn-ghost st-btn-sm">بازگرداندن</button>
            <?php else: ?>
                <button type="submit" name="action" value="resolve" class="st-btn st-btn-sm">برطرف شد</button>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</div>
<?= BizView::pager('errors.php', ['f' => $filter], $pg, $pages, count($rows)) ?>
<?php endif; ?>

<div class="st-err-tools">
    <form method="post" action="<?= h($self . '?f=' . $filter) ?>">
        <?= Csrf::field() ?>
        <button type="submit" name="action" value="purge_resolved" class="st-btn st-btn-ghost st-btn-sm">پاک کردن رسیدگی‌شده‌ها</button>
    </form>
    <form method="post" action="<?= h($self . '?f=' . $filter) ?>" onsubmit="return confirm('کلِ فهرستِ خطاهای فروشگاه پاک شود؟ خطاهای رسیدگی‌نشده هم می‌روند.');">
        <?= Csrf::field() ?>
        <button type="submit" name="action" value="clear_all" class="st-link-btn st-link-danger">پاک کردن همه</button>
    </form>
</div>

<p class="st-muted st-err-howto">
    برای جزئیاتِ یک درخواست (کدِ پیگیری، ردِ پشته) روی سرور: <code dir="ltr">php deploy/log-report.php --tail 30</code>.
    اینجا نه شناسه‌ی کاربر ثبت می‌شود نه آدرسِ صفحه؛ عدد و ایمیلِ داخلِ متنِ خطا پیش از ذخیره پوشانده می‌شوند.
    خطای رسیدگی‌شده پس از <?= h(toPersianDigits((string)AppErrors::KEEP_DAYS)) ?> روز خودش پاک می‌شود.
</p>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
