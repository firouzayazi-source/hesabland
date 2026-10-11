<?php
/**
 * پوسته‌ی محیطِ فروشگاهی — پایینِ صفحه و منوی پایینِ موبایل.
 *
 * منوی پایین: داشبورد، حساب‌ها، **«+ ثبت»** (وسط)، فروش، **بیشتر** (کشوی منو).
 * کلیدها از `Biz::TABBAR`، برچسب‌ها از `Biz::NAV` (یا کوتاهش در
 * `Biz::TABBAR_SHORT`) و کاشی‌های «+» از `Biz::newMenuHtml()` (چیدمانِ همین فروشگاه) — فهرستِ دومی نیست.
 * ⛔ «+» یک `<details>` است و «بیشتر» یک `<label>` برای همان چک‌باکسِ کشو:
 *    هر دو بی‌جاوااسکریپت کار می‌کنند.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }
// ⚠ خودش حساب می‌کند، نه از `biz_head.php` قرض بگیرد (test_dead_code گرفت)
$__bizFootPage  = Biz::navCurrent(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$__bizFootNav   = Biz::navFlat();
$__bizFootIcons = Biz::navIcons();
$__bizFootI     = 0;
?>
</main>
</div>
<nav class="st-tabbar" aria-label="منوی پایین">
    <?php foreach (Biz::TABBAR as $__file):
        if (!isset($__bizFootNav[$__file]) || !is_file(__DIR__ . '/../' . Biz::DIR . '/' . $__file)) { continue; }
        if ($__bizFootI++ === Biz::TABBAR_NEW_AT): ?>
    <details class="st-dd st-tab-new">
        <summary aria-label="ثبتِ جدید"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></summary>
        <div class="st-dd-menu st-new-menu"><?= Biz::newMenuHtml(Biz::ownerId()) ?></div>
    </details>
        <?php endif; ?>
    <a href="<?= h(Biz::url($__file)) ?>"
       class="st-tab<?= $__bizFootPage === $__file ? ' is-active' : '' ?>"<?= $__bizFootPage === $__file ? ' aria-current="page"' : '' ?>>
        <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $__bizFootIcons[$__file] ?? '<circle cx="12" cy="12" r="4"/>' ?></svg>
        <span><?= h(Biz::TABBAR_SHORT[$__file] ?? $__bizFootNav[$__file]) ?></span>
    </a>
    <?php endforeach; ?>
    <label for="stNavToggle" class="st-tab<?= in_array($__bizFootPage, Biz::TABBAR, true) ? '' : ' is-active' ?>" role="button">
        <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg>
        <span>بیشتر</span>
    </label>
</nav>
</body>
</html>
