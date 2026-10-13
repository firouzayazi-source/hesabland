/* ============================================================
   خطای جاوااسکریپت — گزارش‌گرِ مشترکِ حساب‌لند و فروشگاه
   ------------------------------------------------------------
   بی‌صداترین خرابیِ این اپ صفحه‌ای است که کامل بالا می‌آید و هیچ
   دکمه‌ای کار نمی‌کند. هر `error`/`unhandledrejection` یک بار
   (حداکثر دو تا در هر صفحه) به `api/log_client_error.php` می‌رود:
   پیام، فایل، خط، مسیرِ صفحه و محیط (حساب‌لند/فروشگاه) — نه محتوایش.
   («کد پیگیری»ِ خطای سرور، `netErr()`، در `app.js` است.)
   ============================================================ */
/*
 * ⛔ این فایل در **هر دو** پوسته لود می‌شود، پیش از اسکریپتِ اصلیِ هر کدام:
 *    `includes/header.php` (پیش از `app.js`) و `includes/biz_head.php`
 *    (پیش از `store.js`). پیش از این گزارش‌گر داخلِ `app.js` بود و صفحه‌های
 *    فروشگاه که `app.js` را لود نمی‌کنند **هیچ** خطای مرورگری نمی‌فرستادند —
 *    «دکمه کار نمی‌کند»ِ فروشگاه هیچ‌جا دیده نمی‌شد.
 *
 * ⚠ وابستگیِ صفر: نه DOMContentLoaded، نه چیزی از `app.js`/`store.js`؛ خطا
 *   می‌تواند پیش از همه‌ی آن‌ها بیفتد.
 */
/**
 * ⛔ تنها خواننده‌ی توکنِ CSRF در کدِ مرورگر (`app.js` هم همین را صدا می‌زند): اول `<meta name="csrf-token">`،
 *    بعد هر `[name="csrf_token"]` (شیتِ ثبتِ تراکنش در فوترِ **هر** صفحه
 *    است). پیش از این هر تکه یکی از این دو را تنها می‌خواند؛ `dashboard.php`
 *    و `search.php` متا نداشتند و «حذف» آنجا بی‌صدا ۴۰۳ می‌گرفت.
 */
window.csrfToken = function () {
    var m = document.querySelector('meta[name="csrf-token"]');
    if (m && m.content) { return m.content; }
    var i = document.querySelector('[name="csrf_token"]');
    return i ? i.value : '';
};

(function () {
    var sent = 0;
    var base = (typeof window.APP_BASE === 'string') ? window.APP_BASE : '';
    var csrf = window.csrfToken;
    var root = document.documentElement;
    var area = (root && root.getAttribute && root.getAttribute('data-area')) || '';

    /**
     * ⛔ «Script error.»ِ خالی — تنها خطایی که **خودِ مرورگر** می‌سازد،
     *    نه کدِ ما، و عمداً هیچ جزئیاتی ندارد.
     *
     * وقتی اسکریپتی از **مبدأ دیگری** استثنا بدهد، مرورگر پیام و فایل و
     * خط و ردِ پشته را پنهان می‌کند و فقط همین یک رشته را می‌دهد. همه‌ی
     * اسکریپت‌های این اپ هم‌مبدأ می‌آیند (`assetUrls()` مسیرِ نسبیِ
     * `APP_BASE_PATH` می‌سازد و Chart.js هم داخلِ مخزن است، نه CDN)، پس
     * خطای کدِ خودمان **همیشه** فایل و خط و ردِ پشته دارد و هرگز به این
     * شکل نمی‌رسد. چیزی که به این شکل می‌رسد افزونه‌ی مرورگر یا میزبانِ
     * وب‌ویو است — نه چیزی که از اینجا قابلِ رفع باشد.
     *
     * ⛔ و ثبتش دقیقاً همان «هشدارِ همیشگی» است که این پروژه جای دیگری
     *    ممنوعش کرده: یک ردیف در `app_errors` که هیچ فایل و خط و ردِ
     *    پشته‌ای ندارد، نشانِ نوارِ مدیر را روشن می‌کند، و «برطرف شد»
     *    هم بسته نگهش نمی‌دارد چون `AppErrors::record()` با رخدادِ
     *    بعدی دوباره بازش می‌کند (و درست هم همین است). نتیجه یک نشانِ
     *    قرمزِ همیشگی است که مدیر عادت می‌کند نادیده بگیرد — و آن‌وقت
     *    خطای **واقعی** هم دیده نمی‌شود.
     *
     * ⚠ صافی عمداً تنگ است و **هر چهار** نشانه را با هم می‌خواهد. خطای
     *   هم‌مبدأ فایل و خط دارد، پس از زیرِ این رد نمی‌شود. گشاد کردنش به
     *   «خطاهای مرورگر را نفرست» همان چیزی را می‌کشد که این لایه برایش
     *   ساخته شد — قاعده ۵۵ در `test_api_contract.php` همین را می‌بندد.
     *
     * بیرونِ `DOMContentLoaded` و خالص است تا در node آزمودنی بماند،
     * مثل `parseBankSms()` و `kbNeedsKeyboard()`.
     */
    function isOpaque(message, file, line, stack) {
        return /^script error\.?$/i.test(String(message == null ? '' : message).trim())
            && !file
            && Number(line || 0) === 0
            && !stack;
    }
    window.isOpaqueClientError = isOpaque;

    function report(message, file, line, stack) {
        if (sent >= 2 || !message) { return; }
        // ⚠ پیش از `sent++`: نباید یکی از دو سهمیه‌ی صفحه را بخورد،
        //   وگرنه یک افزونه می‌توانست جلوی گزارشِ خطای واقعی را بگیرد.
        if (isOpaque(message, file, line, stack)) { return; }
        sent++;
        try {
            var fd = new FormData();
            fd.set('csrf_token', csrf());
            fd.set('message', String(message).slice(0, 300));
            fd.set('file', String(file || '').slice(0, 200));
            fd.set('line', String(line || 0));
            fd.set('page', String(location.pathname).slice(0, 120));
            // ⛔ محیط از `<html data-area>` (فقط `store` در پوسته‌ی فروشگاه)؛
            //    سرور فقط کلیدهای `AppErrors::AREAS` را می‌پذیرد.
            fd.set('area', area);
            fd.set('stack', String(stack || '').slice(0, 800));
            fetch(base + '/api/log_client_error.php', {
                method: 'POST', body: fd, keepalive: true,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).catch(function () {});
        } catch (e) { /* لاگر نباید خودش خطا بسازد */ }
    }

    window.addEventListener('error', function (e) {
        report(e.message, e.filename, e.lineno, e.error && e.error.stack);
    });
    window.addEventListener('unhandledrejection', function (e) {
        var r = e.reason;
        report(r && r.message ? r.message : String(r), '', 0, r && r.stack);
    });
})();
