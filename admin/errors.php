<?php
/**
 * ⛔ خطاهای برنامه — اطلاع‌رسانی و **رسیدگی**، نه یک فهرستِ خواندنی.
 *
 * **خواسته‌ی مالکِ نصب:** «یک سیستم لاگ می‌خوام که توی بخش مدیریت
 * اطلاع‌رسانی بشه و بتونم راحت برطرفش کنم، شلوغ نباشه و قابل مدیریت.»
 *
 * سه چیزِ جدا در آن خواسته بود و هیچ‌کدام تا امروز نبود:
 *
 * ۱. **اطلاع‌رسانی.** خطاها یک کارت در **تهِ** `admin/insights.php`
 *    بودند — صفحه‌ای با نُه کارت. یعنی تا کسی آن را باز نمی‌کرد و تا
 *    پایین نمی‌رفت، هیچ‌چیز نمی‌گفت اپ خراب است. حالا عددِ خطای باز روی
 *    نوارِ مدیر است (`admin/_nav.php`) و یک اعلانِ روزانه هم در مرکزِ
 *    اعلانِ خودِ مدیر می‌نشیند (`Notify::generateAdminErrors()`).
 *
 * ۲. **«راحت برطرفش کنم».** تنها اقدامِ ممکن `پاک کردن فهرست` بود، یعنی
 *    **همه یا هیچ**: کسی که یک خطا را رفع کرده ناچار بود تاریخچه‌ی
 *    خطاهایی را هم که هنوز ندیده پاک کند. نتیجه در عمل این بود که
 *    هیچ‌کس دست نمی‌زد. حالا هر ردیف یک دکمه‌ی «برطرف شد» دارد، و
 *    **اگر همان خطا دوباره رخ دهد خودبه‌خود برمی‌گردد** (شرحش در
 *    `migration_error_triage.sql`).
 *
 * ۳. **«شلوغ نباشه».** صفحه‌ی جدا، ده ردیف در هر صفحه
 *    (`includes/paged_list.php`)، و پیش‌فرض فقط **باز**ها. رسیدگی‌شده‌ها
 *    هستند ولی سرِ راه نیستند.
 *
 * ⛔ و مرزِ حریمِ خصوصی دست‌نخورده است: این صفحه درباره‌ی **کد** است.
 *    جدولش نه `user_id` دارد، نه آدرسِ صفحه، نه بدنه‌ی درخواست — و
 *    نباید داشته باشد (توضیحش بالای `includes/app_errors.php`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paged_list.php';

Auth::initSession();
Auth::requireAdmin();

$filter = getParam('f', 'open');
if (!isset(AppErrors::FILTERS[$filter])) { $filter = 'open'; }

// ⛔ هر نوشتنی CSRF دارد (قاعده ۳) و بعدش ریدایرکت می‌شود، نه رندرِ
//    مستقیم: با رندر، تازه‌سازیِ صفحه همان عملیات را دوباره می‌فرستاد.
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    // ⛔ منطقِ دکمه‌ها فقط `AppErrors::handleAction()` — همان که
    //    `store/errors.php` با محیطِ «فروشگاه» صدا می‌زند.
    $flash = AppErrors::handleAction(postParam('action'), (int)postParam('id', '0'));

    header('Location: ' . APP_BASE_PATH . '/admin/errors.php?f=' . urlencode($filter)
        . ($flash !== '' ? '&done=' . urlencode($flash) : ''));
    exit;
}

$rows   = AppErrors::browse($filter, 500);
$slice  = pagedSlice($rows, 'err');
$open   = AppErrors::openCount();
$triage = AppErrors::triageAvailable();
$done   = (string)getParam('done');

/**
 * سطحِ خطا → برچسب و رنگ. برچسب‌ها فقط `AppErrors::levelInfo()`.
 *
 * ⚠ رنگِ میانی `--warn-ink` است نه `--gold`: پالتِ دومِ `style.css` آن
 *   یکی را به **آبی** بازتعریف می‌کند و آن‌وقت «هشدار» دقیقاً شبیهِ
 *   «اطلاع» دیده می‌شد (همان باگی که در `funnelVerdict()` گرفته شد).
 */
$levelMeta = static function (string $lv): array {
    [$tone, $label] = AppErrors::levelInfo($lv);
    return [$tone === 'bad' ? 'status-badge-out' : 'status-badge-warn', $label];
};

$stamp = static function (?string $ts): string {
    if ($ts === null || $ts === '') { return '—'; }
    return toPersianDigits(toJalali(substr($ts, 0, 10)) . ' ' . substr($ts, 11, 5));
};

$pageWide  = true;
$pageTitle = 'خطاهای برنامه';
include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/_nav.php'; ?>

<?php if ($done !== ''): ?>
    <div class="alert alert-success">
        <p style="margin:0;">
            <?php if ($done === 'resolved'): ?>
                خطا «برطرف شد» علامت خورد. اگر دوباره رخ دهد، خودبه‌خود به فهرستِ باز برمی‌گردد.
            <?php elseif ($done === 'reopened'): ?>
                به فهرستِ باز برگشت.
            <?php elseif ($done === 'cleared'): ?>
                کلِ فهرست پاک شد.
            <?php elseif (str_starts_with($done, 'purged:')): ?>
                <?= h(toPersianDigits(substr($done, 7))) ?> خطای رسیدگی‌شده پاک شد.
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head-row">
        <h2 class="card-title">خطاهای برنامه</h2>
        <?php if ($open > 0): ?>
            <span class="status-badge status-badge-out ltr-num"><?= h(toPersianDigits((string)$open)) ?></span>
        <?php else: ?>
            <span class="status-badge status-badge-in">همه رسیدگی شده</span>
        <?php endif; ?>
    </div>

    <?php if (!tableExists('app_errors')): ?>
        <p class="hint">migration_app_errors اجرا نشده.</p>
    <?php else: ?>

        <?php /* ⛔ صافی‌ها از `AppErrors::FILTERS` رندر می‌شوند و `?f=` هم با
                 همان سنجیده می‌شود — فهرستِ دوم نسازید (درسِ `DUE_TABS`). */ ?>
        <nav class="page-tabs" aria-label="صافیِ خطاها">
            <?php foreach (AppErrors::FILTERS as $key => $label): ?>
                <a class="page-tab <?= $filter === $key ? 'is-active' : '' ?>"
                   href="<?= pagedUrl(['f' => $key, 'pg_err' => null, 'all' => null, 'done' => null]) ?>"
                   <?= $filter === $key ? 'aria-current="page"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$triage): ?>
            <p class="hint">
                ⚠ <code>migration_error_triage</code> اجرا نشده، پس «برطرف شد» در دسترس نیست
                و همه‌ی خطاها باز دیده می‌شوند. روی سرور:
                <code>sudo ./hesabland deploy --migrate</code>
            </p>
        <?php endif; ?>

        <?php if (!$slice['rows']): ?>
            <p class="hint">
                <?php if ($filter === 'open'): ?>
                    هیچ خطای بازی نیست.
                <?php elseif ($filter === 'resolved'): ?>
                    هنوز چیزی «برطرف شد» علامت نخورده است.
                <?php else: ?>
                    هیچ خطایی ثبت نشده است.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <div class="err-list">
                <?php foreach ($slice['rows'] as $e): ?>
                    <?php
                    [$badgeClass, $badgeLabel] = $levelMeta((string)$e['level']);
                    $isResolved = ($e['resolved_at'] ?? null) !== null;
                    ?>
                    <div class="err-row <?= $isResolved ? 'is-resolved' : '' ?>">
                        <div class="err-main">
                            <div class="err-head">
                                <span class="status-badge <?= $badgeClass ?>"><?= h($badgeLabel) ?></span>
                                <?php /* ⛔ محیطِ غیرِ پیش‌فرض نشان می‌گیرد — این صفحه همه را می‌بیند. */ ?>
                                <?php if (($e['area'] ?? 'app') !== array_key_first(AppErrors::AREAS) && isset(AppErrors::AREAS[$e['area']])): ?>
                                    <span class="status-badge status-badge-muted"><?= h(AppErrors::AREAS[$e['area']]) ?></span>
                                <?php endif; ?>
                                <span class="err-where ltr-num"><?= h((string)$e['file']) ?>:<?= h(toPersianDigits((string)$e['line'])) ?></span>
                            </div>
                            <div class="err-msg"><?= h((string)$e['message']) ?></div>
                            <div class="hint err-meta">
                                <span class="ltr-num"><?= h(toPersianDigits((string)$e['hits'])) ?></span> بار
                                · آخرین: <span class="ltr-num"><?= h($stamp((string)$e['last_seen'])) ?></span>
                                · اولین: <span class="ltr-num"><?= h($stamp((string)$e['first_seen'])) ?></span>
                                <?php if ($isResolved): ?>
                                    · برطرف شد: <span class="ltr-num"><?= h($stamp((string)$e['resolved_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php /* ⚠ دکمه فقط وقتی رندر می‌شود که واقعاً کار کند
                                 (ستونِ migration آمده باشد) — «دکمه‌ی بی‌کار از
                                 نبودنش بدتر است». */ ?>
                        <?php if ($triage): ?>
                            <form method="post" class="err-act">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                                <?php if ($isResolved): ?>
                                    <button type="submit" name="action" value="reopen" class="btn btn-secondary btn-sm">بازگرداندن</button>
                                <?php else: ?>
                                    <button type="submit" name="action" value="resolve" class="btn btn-sm">برطرف شد</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php pagedNav($slice); ?>
        <?php endif; ?>

        <?php /* ⛔ «پاک کردن رسیدگی‌شده‌ها» جای «پاک کردن فهرست» را گرفت و
                 آن یکی فقط به‌عنوان آخرین راه ماند: پاک کردنِ همه، خطاهای
                 دیده‌نشده را هم می‌برد. */ ?>
        <div class="err-tools">
            <form method="post">
                <?= Csrf::field() ?>
                <button type="submit" name="action" value="purge_resolved" class="btn btn-secondary btn-sm">
                    پاک کردن رسیدگی‌شده‌ها
                </button>
            </form>
            <form method="post" onsubmit="return confirm('کلِ فهرستِ خطاها پاک شود؟ خطاهای رسیدگی‌نشده هم می‌روند.');">
                <?= Csrf::field() ?>
                <button type="submit" name="action" value="clear_all" class="delete-btn btn-sm">پاک کردن همه</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title">چطور یک خطا را پیدا کنم</h2>
    <p class="hint" style="margin-top:0;">
        همین فهرست می‌گوید <strong>کجا</strong> و <strong>چند بار</strong>؛ برای جزئیاتِ یک
        درخواست (شناسه‌ی پیگیری، مرحله، ردِ پشته) لاگِ فایل روی سرور است:
    </p>
    <ul class="hint err-howto">
        <li><code>php deploy/log-report.php --tail 30</code> — آخرین خطاها با ردِ پشته</li>
        <li><code>php deploy/log-report.php --req abc123</code> — همه‌ی خطوطِ یک درخواست،
            با همان «کد پیگیری» که کاربر دیده است</li>
        <li><code>./hesabland logs</code> — خلاصه‌ی ۲۴ ساعت (نرخِ خطا، p95، کوئریِ کند)</li>
    </ul>
    <p class="hint">
        ⛔ اینجا نه شناسه‌ی کاربر ثبت می‌شود نه آدرسِ صفحه — این فهرست درباره‌ی
        <strong>کد</strong> است، نه رفتارِ کاربر. عدد و ایمیلِ داخلِ متنِ خطا هم پیش از
        ذخیره پوشانده می‌شوند. خطای رسیدگی‌شده پس از
        <?= h(toPersianDigits((string)AppErrors::KEEP_DAYS)) ?> روز خودش پاک می‌شود؛
        خطای باز هرگز خودبه‌خود پاک نمی‌شود.
    </p>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
