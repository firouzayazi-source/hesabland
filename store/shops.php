<?php
/**
 * ⛔ چند فروشگاه با یک حساب — فهرست، ساختن و رفتن به هر کدام.
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** کسی که دو فروشگاهِ جدا دارد، هر دو را با یک ورود و
 * جدا از هم داشته باشد (مثلِ «چند شرکت» در قیاس).
 *
 * ⛔ هیچ منطقی اینجا نیست: ساختن فقط `Biz::createShop()`، رفتن فقط `Biz::switchShop()`.
 *    صاحبِ فروشگاه = **حسابِ ورود** (`Auth::userId()`)، نه فروشگاهِ جاری — فروشگاهِ تازه
 *    همیشه مالِ صاحب است، حتی اگر از داخلِ فروشگاهِ دوم ساخته شود. همکار هرگز.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
if (Biz::isStaff() || !Biz::$shopsJoined) { Biz::notFound(); }   // ⛔ آمادگی از کوئریِ همین صفحه (بی‌کوئری)

$login = (int)Auth::userId();
$self  = Biz::url('shops.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    if (postParam('action') === 'create') {
        $r = Biz::createShop($login, (string)postParam('name'));
        if ($r['ok'] && postParam('go') === '1') { Biz::switchShop($login, (int)$r['id']); redirectWithMessage(Biz::url('settings.php'), 'success', $r['message'] . ' حالا سربرگ و تنظیماتش را کامل کنید.'); }
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
    }
    if (postParam('action') === 'switch') {
        $r = Biz::switchShop($login, (int)postParam('id'));
        redirectWithMessage($r['ok'] ? Biz::url() : $self, $r['ok'] ? 'success' : 'error', $r['message']);
    }
    redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
}

$shops = Biz::shops($login);
$cur   = Biz::ownerId();
$pageTitle = 'فروشگاه‌های من';
require __DIR__ . '/../includes/biz_head.php';
?>
<h1 class="st-h1">فروشگاه‌های من</h1>
<p class="st-muted">هر فروشگاه دفترِ کاملاً جدای خودش را دارد — کالا، انبار، طرف‌حساب، صندوق، فاکتور، گزارش، تنظیمات و همکاران. با همین یک حساب واردِ هر کدام می‌شوید؛ بکاپِ هر فروشگاه را از داخلِ خودش («رمز و بکاپ») بگیرید.</p>

<section class="st-card">
    <ul class="st-list">
    <?php foreach ($shops as $sh): ?>
        <li class="st-list-row">
            <span><b><?= h($sh['name']) ?></b><?= $sh['main'] ? ' <span class="st-muted-i">فروشگاهِ اصلی</span>' : '' ?><?= $sh['id'] === $cur ? ' <span class="st-badge">الان اینجا هستید</span>' : '' ?></span>
            <?php if ($sh['id'] !== $cur): ?>
            <form method="post" action="<?= h($self) ?>" class="st-inline-form"><?= Csrf::field() ?><input type="hidden" name="action" value="switch"><input type="hidden" name="id" value="<?= (int)$sh['id'] ?>"><button type="submit" class="st-btn st-btn-ghost">رفتن به این فروشگاه</button></form>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</section>

<?php if (count($shops) - 1 < Biz::SHOPS_MAX): ?>
<h2 class="st-h2 st-section-title">فروشگاهِ تازه</h2>
<form method="post" class="st-card st-form" action="<?= h($self) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <label class="st-field"><span>نامِ فروشگاه</span>
        <input type="text" name="name" required maxlength="<?= Biz::SETTING_LIMITS['shop_name'] ?>" autocomplete="off"></label>
    <label class="st-check"><input type="checkbox" name="go" value="1" checked><span>بعد از ساختن همان‌جا بروم</span></label>
    <button type="submit" class="st-btn">ساختنِ فروشگاه</button>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
