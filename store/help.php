<?php
/**
 * راهنمای فروشگاه — همه‌ی موضوع‌ها در یک صفحه، به ترتیبِ منو (`BizHelp::TOPICS`).
 * ⛔ بی‌کوئری؛ همان کاتالوگی که دکمه‌ی «؟» بالای هر صفحه نشان می‌دهد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_help.php';

$pageTitle = 'راهنما';
require __DIR__ . '/../includes/biz_head.php';
$seen = [];
?>
<h1 class="st-h1">راهنما</h1>
<p class="st-muted">روی هر صفحه دکمه‌ی «؟» بالای صفحه راهنمای همان صفحه را نشان می‌دهد. این‌جا همه یک‌جاست.</p>

<?php foreach (Biz::NAV as $group => $items): ?>
<h2 class="st-h2 st-section-title"><?= h($group) ?></h2>
<div class="st-help-grid">
    <?php foreach ($items as $file => $label): $t = BizHelp::topic($file); if ($t === null) { continue; } $seen[$file] = true; ?>
    <section class="st-card st-help-card" id="<?= h(pathinfo($file, PATHINFO_FILENAME)) ?>">
        <h3 class="st-h3"><a href="<?= h(Biz::url($file)) ?>"><?= h($t[0]) ?></a></h3>
        <ul><?php foreach ($t[1] as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul>
    </section>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<h2 class="st-h2 st-section-title">صفحه‌های دیگر</h2>
<div class="st-help-grid">
<?php foreach (BizHelp::TOPICS as $file => [$title, $points]): if (isset($seen[$file])) { continue; } ?>
    <section class="st-card st-help-card" id="<?= h(pathinfo($file, PATHINFO_FILENAME)) ?>">
        <h3 class="st-h3"><?= h($title) ?></h3>
        <ul><?php foreach ($points as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul>
    </section>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
