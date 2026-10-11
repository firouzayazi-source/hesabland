/* ============================================================
   پیش‌گیریِ صفحه‌ی بعد (و آماده‌سازیِ سه زبانه) — و کهنه نشدنش
   ------------------------------------------------------------
   `<script type="speculationrules" id="specRules">` (از
   `speculationRulesJson()`) صفحه‌ی بعد را وقتی نشانگر/انگشت روی لینک
   می‌نشیند می‌گیرد. خطرش **کهنگی** است: اگر کاربر روی لینکی مکث کند،
   بعد در همین صفحه چیزی را با `fetch` عوض کند (پینِ حساب، ثبتِ خودکار از
   پیامک، …) و بعد روی همان لینک بزند، پاسخِ پیش‌گرفته عددِ **قبل** از
   تغییر را نشان می‌داد — همان باگی که HTML را `no-store` کرد.

   ⛔ پس بعد از هر درخواستِ **نویسنده**ی موفق، قاعده برداشته و یک تیک
   بعد دوباره نشانده می‌شود: برداشتن، پیش‌گرفته‌هایش را دور می‌ریزد.
   ⚠ جایگزینیِ درجا (`replaceChild` با همان متن) **کافی نبود** — کرومیوم
   نامزدهای هم‌آدرس را نگه می‌داشت و پاسخِ کهنه باز هم مصرف می‌شد؛
   هر دو شکل اندازه‌گیری شد. روی `fetch` سراسری است نه تک‌تکِ ۵۰
   فراخوانی، تا مسیرِ نویسنده‌ی فردا خودبه‌خود پوشش داشته باشد.
   ============================================================ */
(function () {
    function reset() {
        var old = document.getElementById('specRules');
        if (!old || !old.parentNode) { return; }
        var parent = old.parentNode, text = old.textContent;
        parent.removeChild(old);
        setTimeout(function () {
            if (document.getElementById('specRules')) { return; }
            var fresh = document.createElement('script');
            fresh.type = 'speculationrules';
            fresh.id = 'specRules';
            fresh.textContent = text;
            parent.appendChild(fresh);
        }, 0);
    }
    window.resetSpeculation = reset;
    // ⚠ بی‌قاعده (صفحه‌های بیرون از قالب، یا node در تست‌ها) هیچ تایمری
    //   راه نمی‌افتد — `setInterval` در node پروسه را برای همیشه زنده نگه
    //   می‌داشت و تست‌هایی که app.js را بار می‌کنند گیر می‌کردند.
    var hasRules = false;
    try { hasRules = !!(document.getElementById && document.getElementById('specRules')); } catch (e) {}

    /* ⛔ کهنگیِ زمانی: زبانه‌های از-پیش-آماده عکسِ لحظه‌ی آماده شدن‌اند.
       دستگاهِ دیگر، cronِ فروشگاه یا پیامکِ رسیده ممکن است در این فاصله
       داده را عوض کرده باشد؛ پس اگر صفحه بیش از `SPEC_MAX_AGE_MS` باز و
       دیده‌شده ماند، یا کاربر بعد از آن به اپ برگشت، دوباره آماده می‌شوند.
       در پس‌زمینه هیچ کاری نمی‌شود (باتری). */
    var SPEC_MAX_AGE_MS = 90000;
    var armedAt = Date.now();
    var baseReset = reset;
    reset = function () { armedAt = Date.now(); baseReset(); };
    window.resetSpeculation = reset;
    /* زبانه‌های بعدی یکی‌یکی و با فاصله آماده می‌شوند (نه هم‌زمان با اولی —
       آن در کرومیوم دومی را لغو کرد). هر reset همه را از نو می‌چیند. */
    var NEXT_GAP_MS = 2500;
    var nextTimers = [];
    function armNext() {
        nextTimers.forEach(clearTimeout);
        nextTimers = [];
        document.querySelectorAll('script.spec-next').forEach(function (n) { n.remove(); });
        var main = document.getElementById('specRules');
        var urls = ((main && main.getAttribute('data-next')) || '').split(' ').filter(Boolean);
        urls.forEach(function (u, i) {
            nextTimers.push(setTimeout(function () {
                if (document.hidden) { return; }
                var sc = document.createElement('script');
                sc.type = 'speculationrules';
                sc.className = 'spec-next';
                sc.textContent = JSON.stringify({ prerender: [{ source: 'list', urls: [u], eagerness: 'immediate' }] });
                document.head.appendChild(sc);
            }, NEXT_GAP_MS * (i + 1)));
        });
    }
    var baseReset2 = reset;
    reset = function () { baseReset2(); armNext(); };
    window.resetSpeculation = reset;
    if (hasRules) {
        if (document.prerendering) {
            document.addEventListener('prerenderingchange', armNext, { once: true });
        } else {
            armNext();
        }
    }

    function refreshIfOld() {
        if (!document.hidden && !document.prerendering && Date.now() - armedAt >= SPEC_MAX_AGE_MS) { reset(); }
    }
    if (hasRules) {
        setInterval(refreshIfOld, 15000);
        document.addEventListener('visibilitychange', refreshIfOld);
    }

    if (typeof window.fetch !== 'function') { return; }
    var orig = window.fetch;
    window.fetch = function (input, init) {
        var method = (init && init.method) || (input && typeof input === 'object' && input.method) || 'GET';
        var p = orig.apply(this, arguments);
        var m = String(method).toUpperCase();
        if (m !== 'GET' && m !== 'HEAD') {
            p.then(function (r) { if (r && r.ok) { try { reset(); } catch (e) {} } }, function () {});
        }
        return p;
    };
})();

/* ============================================================
   «کد پیگیری» برای خطای سرور
   ------------------------------------------------------------
   وقتی سرور ۵۰۰ داد، پاسخ یک `X-Request-Id` دارد؛ `netErr()` همان را
   کنارِ «خطا در ارتباط با سرور» نشان می‌دهد تا کاربر بتواند گزارشش کند
   و مالکِ نصب با `deploy/log-report.php --req` همان یک درخواست را پیدا
   کند.

   ⛔ گزارش‌گرِ خطای مرورگر و `window.csrfToken` در `client-errors.js`
      است — مشترک با پوسته‌ی فروشگاه (`includes/biz_head.php`) و **پیش
      از** این فایل لود می‌شود (`includes/header.php`). نسخه‌ی دوم در
      `store.js` ساخته نشد: صافیِ «Script error.» (قاعده ۵۵) یک بار نوشته
      شده و یک بار آزموده می‌شود.
   ============================================================ */
(function () {
    // شناسه‌ی آخرین پاسخِ ناموفق — از سرآیندی که Log::boot() می‌گذارد.
    window.LAST_REQUEST_ID = null;
    if (window.fetch) {
        var realFetch = window.fetch;
        window.fetch = function () {
            window.LAST_REQUEST_ID = null;
            return realFetch.apply(this, arguments).then(function (r) {
                try {
                    if (r && !r.ok) { window.LAST_REQUEST_ID = r.headers.get('X-Request-Id') || null; }
                } catch (e) { /* headers ممکن است opaque باشد */ }
                return r;
            });
        };
    }
    window.netErr = function () {
        var id = window.LAST_REQUEST_ID;
        return 'خطا در ارتباط با سرور.' + (id ? ' کد پیگیری: ' + id : '');
    };
})();

/* ============================================================
   انتخابگر شخص (چک، طلب و بدهی، معامله)
   ------------------------------------------------------------
   هر `<select class="js-person-select">` یک ورودیِ متنی را کنترل
   می‌کند (`data-target`). انتخاب یک شخص، نامش را داخل همان ورودی
   می‌گذارد؛ زدنِ «سایر» ورودی را باز می‌کند تا کاربر دستی بنویسد.

   چیزی که به سرور می‌رود همچنان همان ورودیِ `counterparty_name` است،
   پس هیچ اندپوینتی عوض نشده.

   بیرون از DOMContentLoaded است چون فرم‌های ویرایش با
   `syncPersonPicker()` از اسکریپت‌های درون‌صفحه‌ای پر می‌شوند و آن‌ها
   زودتر از این فایل اجرا می‌شوند.
   ============================================================ */
(function () {
    var OTHER = '__other__';

    /** سمتِ شخصِ انتخاب‌شده را کنار عنوان نشان می‌دهد. */
    function showRole(sel) {
        var badge = sel.parentNode.querySelector('.js-person-role');
        if (!badge) { return; }
        var opt  = sel.options[sel.selectedIndex];
        var role = (opt && opt.getAttribute('data-role')) || '';
        badge.textContent = role;
        badge.hidden = role === '';
    }

    function applyChoice(sel) {
        var input = document.getElementById(sel.getAttribute('data-target'));
        if (!input) { return; }
        var hint = sel.parentNode.querySelector('.js-person-hint');
        showRole(sel);

        if (sel.value === OTHER) {
            input.hidden = false;
            if (input.value && sel.dataset.knownName === input.value) { input.value = ''; }
            input.focus();
        } else if (sel.value === '') {
            input.hidden = true;
            input.value = '';
        } else {
            input.hidden = true;
            input.value = sel.value;
            sel.dataset.knownName = sel.value;
        }
        if (hint) { hint.hidden = sel.value !== OTHER && sel.value !== ''; }
    }

    /**
     * فرم ویرایش را با نامِ ذخیره‌شده هماهنگ می‌کند: اگر آن نام در فهرست
     * بود همان انتخاب می‌شود، وگرنه «سایر» با ورودیِ باز و پرشده.
     * (روی window چون صفحه‌ها از اسکریپت درون‌خطی صدایش می‌زنند.)
     */
    window.syncPersonPicker = function (inputId, name) {
        var input = document.getElementById(inputId);
        if (!input) { return; }
        input.value = name || '';

        var sel = document.querySelector('.js-person-select[data-target="' + inputId + '"]');
        if (!sel) { input.hidden = false; return; }

        var found = false;
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === name && name !== '') { found = true; break; }
        }
        sel.value = found ? name : OTHER;
        sel.dataset.knownName = found ? name : '';
        input.hidden = found;
        showRole(sel);
        var hint = sel.parentNode.querySelector('.js-person-hint');
        if (hint) { hint.hidden = found; }
    };

    document.addEventListener('change', function (e) {
        var sel = e.target.closest ? e.target.closest('.js-person-select') : null;
        if (sel) { applyChoice(sel); }
    });
}());

/* ============================================================
   ثبت سرویس‌ورکر
   ------------------------------------------------------------
   با این، اپ از آیکون صفحه‌ی اصلی گوشی مثل یک برنامه باز می‌شود،
   فایل‌های ثابت از حافظه‌ی خودِ گوشی می‌آیند، و اگر اینترنت نبود
   به‌جای صفحه‌ی خطای مرورگر، صفحه‌ی «اینترنت نیست» خودمان می‌آید.

   سرویس‌ورکر عمداً هیچ HTML ای کش نمی‌کند — توضیحش در sw.js است.

   ثبت بعد از load انجام می‌شود، نه همان اول: دانلود و نصبِ سرویس‌ورکر
   با بارگذاریِ خودِ صفحه سرِ پهنای باند دعوا نکند.
   ============================================================ */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        var base = (window.APP_BASE || '');
        navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' })
            .catch(function () { /* بی‌سروصدا: اپ بدون این هم کامل کار می‌کند */ });
    });
}

/* ============================================================
   رنگِ وابسته به حالت شب
   ------------------------------------------------------------
   نمودارها رنگشان را در جاوااسکریپت می‌گیرند، پس با عوض شدن حالت شب
   باید دوباره رنگ بگیرند — نقطه‌های رنگی کنار فهرست‌ها این مشکل را
   ندارند چون رنگشان از متغیر CSS می‌آید.

   این تکه عمداً بیرون از DOMContentLoaded است: اسکریپت درون‌صفحه‌ایِ
   صفحه‌ی گزارش شنونده‌اش را هنگام تجزیه‌ی HTML ثبت می‌کند، یعنی زودتر
   از شنونده‌ی این فایل که با defer اجرا می‌شود. اگر این توابع داخل
   شنونده تعریف می‌شدند، آن صفحه با «registerThemedChart is not defined»
   می‌شکست.
   ============================================================ */
(function () {
    var themedCharts = [];
    window.__themeHooks = window.__themeHooks || [];

    function surfaceColor() {
        return getComputedStyle(document.documentElement)
            .getPropertyValue('--surface').trim() || '#ffffff';
    }

    function paintChart(entry) {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        var colors = dark ? entry.dark : entry.light;
        var surface = surfaceColor();
        entry.chart.data.datasets.forEach(function (ds) {
            ds.backgroundColor = colors;
            ds.borderColor = surface;
        });
        entry.chart.update('none');
    }

    window.registerThemedChart = function (chart, lightColors, darkColors) {
        var entry = { chart: chart, light: lightColors, dark: darkColors };
        themedCharts.push(entry);
        paintChart(entry);
    };

    window.onThemeChange = function (fn) { window.__themeHooks.push(fn); };

    window.__repaintThemedCharts = function () {
        themedCharts.forEach(paintChart);
        window.__themeHooks.forEach(function (fn) {
            try { fn(); } catch (e) {}
        });
    };
})();

/* ⛔ پارسرِ پیامکِ بانک (`parseBankSms`، `smsAutoOk`، `smsMatchWallet`،
   `smsFingerprint`، `smsHashDecode`، …) در `assets/js/sms-core.js` است — پیش از
   همین فایل بار می‌شود و اپ اندروید هم همان را می‌خواند. */

/* تصمیم‌های خالصِ کوچک — بیرون از `DOMContentLoaded` تا در node آزمودنی بمانند. */
(function () {
    'use strict';

    /**
     * آیا فوکوس روی این عنصر کیبوردِ مجازی را بالا می‌آورد؟
     *
     * ⛔ تنها جای این تصمیم (مثل `smsAutoOk()`) و عمداً **بیرون از
     *    `DOMContentLoaded`** تا در node آزمودنی باشد: نسخه‌ی اولش
     *    داخلِ `keyboardAwareNav()` بود و آزمونِ جهشِ «همیشه true»
     *    **زنده ماند**، چون تستِ قرارداد فقط دنبالِ رشته‌ها می‌گشت و
     *    آن‌ها سرِ جایشان بودند. جهشِ زنده‌مانده یعنی تست ناقص است.
     *
     * ⚠ چک‌باکس، رادیو، دکمه و `type="color"` فوکوس می‌گیرند **بدونِ**
     *   کیبورد. با شمردنشان، تپ روی هر کلیدِ `.switch` نوارِ پایین را
     *   ناپدید می‌کرد — یعنی رفعِ ما خودش یک خرابیِ تازه می‌ساخت.
     *
     * @param {object} el عنصر (یا هر چیزی شبیهش: `tagName` و `type`)
     * @returns {boolean}
     */
    window.kbNeedsKeyboard = function (el) {
        if (!el) { return false; }
        if (el.isContentEditable) { return true; }
        var tag = String(el.tagName || '').toUpperCase();
        if (tag === 'TEXTAREA') { return true; }
        if (tag !== 'INPUT') { return false; }
        var t = String(el.type || 'text').toLowerCase();
        return !/^(button|submit|reset|checkbox|radio|file|range|color|image|hidden)$/.test(t);
    };

    /**
     * ⛔ اعلان روی گوشی (Web Push) — **پیش‌فرض روشن**، به همان خواستِ مالکِ
     *    نصب. تا امروز فقط با تپ روی «روشن کردن» در پروفایل ساخته می‌شد،
     *    یعنی کسی که آن کارت را پیدا نمی‌کرد هیچ اعلانی روی گوشی نمی‌گرفت.
     *
     *    حالا هر صفحه‌ی داخلیِ اپ، اگر مرورگر اجازه‌ی اعلان را **از قبل**
     *    داده باشد (در اپِ اندروید همان مجوزی که در اولین اجرا پرسیده
     *    می‌شود)، بی‌صدا اشتراک می‌سازد. فقط «خاموش کردن»ِ صریح در پروفایل
     *    (`PUSH_OFF_KEY = '1'`) جلویش را می‌گیرد.
     *
     * ⛔ تنها جای این تصمیم، و خالص — تا در node آزمودنی باشد (مثل
     *    `smsAutoEnabled`):
     *    - `'granted'` + خاموش‌نکرده → «بساز».
     *    - `'default'` → «بپرس» ولی **فقط** در اپِ نصب‌شده (standalone) و
     *      فقط یک بار در عمرِ دستگاه، آن هم با اولین تپِ کاربر: مرورگر
     *      درخواستِ بی‌حرکت را بی‌صدا رد می‌کند، و پرسیدن در هر تبِ
     *      مرورگرِ معمولی همان مزاحمتی است که آدم را وادار می‌کند «هرگز» بزند.
     *    - `'denied'` یا خاموشِ صریح → هیچ.
     */
    window.PUSH_OFF_KEY   = 'daftar_push_off';
    window.PUSH_ASKED_KEY = 'daftar_push_asked';
    window.pushAutoAction = function (perm, off, asked, standalone) {
        if (off === '1') { return 'none'; }
        if (perm === 'granted') { return 'subscribe'; }
        if (perm === 'default' && standalone && asked !== '1') { return 'ask'; }
        return 'none';
    };

    /**
     * ⛔ تنها جای تصمیمِ «معرفیِ اولیه را نشان بده یا نه».
     *
     * ⛔ **نشانه در `localStorage` است، نه دیتابیس** — و این با قاعده‌ی
     *    `wallets.pinned` نمی‌جنگد، از همان قاعده می‌آید: «این حساب را
     *    روی خانه می‌خواهم» تصمیمی ماندگار درباره‌ی خودِ حساب است، ولی
     *    «این معرفی را دیدم» یک **رویدادِ نگاه** است، مثل
     *    `daftar_asset_off`. و مهم‌تر: صفحه‌ی خانه پرهزینه‌ترین بودجه‌ی
     *    کوئریِ اپ را دارد و یک ستونِ تازه یعنی یک کوئریِ تازه در هر
     *    بارگذاری، برای چیزی که یک بار در عمرِ حساب دیده می‌شود.
     *
     * ⚠ پیشوندِ `daftar_` عمداً ماند — همان قاعده‌ای که در `CLAUDE.md`
     *   نوشته شده: این کلیدها نامِ برند نیستند، نشانیِ چیزی هستند که
     *   همین حالا روی دستگاهِ کاربر نشسته.
     *
     * ⛔ **شکستِ `localStorage` به سمتِ «نشان نده» می‌رود، نه برعکس.**
     *   در پنجره‌ی ناشناس خودِ خواندن استثنا می‌دهد؛ اگر آنجا `true`
     *   برمی‌گرداندیم، معرفی در **هر** بارگذاری باز می‌شد و دقیقاً به
     *   همان مزاحمی تبدیل می‌شد که «حالت رد شدن» برای نبودنش خواسته
     *   شده بود.
     */
    window.INTRO_SEEN_KEY = 'daftar_intro_seen';
    window.introSeen = function () {
        try { return localStorage.getItem(window.INTRO_SEEN_KEY) === '1'; }
        catch (e) { return true; }
    };
    window.introMarkSeen = function () {
        try { localStorage.setItem(window.INTRO_SEEN_KEY, '1'); }
        catch (e) { /* پنجره‌ی ناشناس — چیزی برای از دست دادن نیست */ }
    };
})();

/**
 * جای‌نگهدارِ «در حال بارگذاری» به شکلِ ردیف‌های کم‌رنگ، نه یک جمله.
 *
 * ⛔ فقط برای محتوایی است که با `fetch` می‌آید (جزئیاتِ روز، تاریخچه‌ی
 *    پس‌انداز، پیوست‌ها). صفحه‌ها سمتِ سرور رندر می‌شوند و اسکلت روی آن‌ها
 *    یعنی پنهان کردنِ چیزی که **از قبل رسیده** — بدتر از هیچ.
 * ⚠ متنِ «در حال بارگذاری…» برای صفحه‌خوان سرِ جایش می‌ماند
 *   (`.sr-only`)؛ ردیف‌های خاکستری برای چشم‌اند، نه برای گوش.
 * ⚠ بیرون از `DOMContentLoaded` است تا در node آزمودنی باشد — همان
 *   قاعده‌ی `parseBankSms()` و `kbNeedsKeyboard()`.
 */
/**
 * ⛔ دکمه‌ی «برگشت»ِ اندروید **لایه‌ی باز را می‌بندد**، نه صفحه را — گزارشِ
 *    مالکِ نصب (مهر ۱۴۰۵): «منو میاد روی صفحه قبلی … انگار صفحه بسته میشه و
 *    خیلی سخت از اونجا میتونم بیام بیرون». هر شیت/مودالی که باز می‌شود یک
 *    خانه در تاریخچه می‌گذارد (`watchOverlays()`)، و «برگشت» همان را برمی‌دارد.
 *
 * ⛔ پس **هر** تازه‌سازیِ صفحه از این راه است، نه `location.reload()`ِ خام:
 *    بیشترِ ثبت‌ها با شیتِ هنوز باز صفحه را تازه می‌کنند؛ با تازه‌سازیِ خام آن
 *    خانه‌ی تاریخچه می‌ماند و کاربر برای بیرون رفتن **دو بار** «برگشت» می‌زد
 *    (بارِ اول همان صفحه دوباره بار می‌شد). اینجا اول خانه برداشته می‌شود،
 *    بعد تازه‌سازی.
 */
window.OVERLAY_STATE = 'hlOverlay';
window.reloadPage = function () {
    var st = window.history ? window.history.state : null;
    if (st && st[window.OVERLAY_STATE]) {
        window.__hlReloading = true;
        window.addEventListener('popstate', function () { window.location.reload(); }, { once: true });
        window.history.back();
        // ⚠ اگر «برگشت» هیچ رویدادی نداد (تاریخچه‌ی خالی)، تازه‌سازی گم نشود.
        setTimeout(function () { window.location.reload(); }, 700);
        return;
    }
    window.location.reload();
};

window.skeletonHtml = function (rows) {
    var n = Math.max(1, Math.min(6, parseInt(rows, 10) || 1));
    var out = '<div class="skel" role="status" aria-busy="true"><span class="sr-only">در حال بارگذاری…</span>';
    for (var i = 0; i < n; i++) {
        out += '<div class="skel-row" aria-hidden="true"><span class="skel-dot"></span>'
             + '<span class="skel-lines"><span class="skel-line"></span><span class="skel-line is-short"></span></span>'
             + '<span class="skel-amt"></span></div>';
    }
    return out + '</div>';
};

/**
 * آیا نوارِ «نسخه‌ی تازه‌ی اپ آماده است» باید دیده شود؟
 *
 * **خواسته‌ی مالکِ نصب:** «وقتی آپدیت شد، برنامه رو باز کرد، پیام برای
 * کاربر بره که آپدیت کنه.»
 *
 * ⛔ **فقط داخلِ خودِ اپ** (`inApp`). کروم و اپ یک مخزنِ کوکی دارند، پس
 *    کسی که همان سایت را در کرومِ معمولی باز کند هم کوکیِ نسخه را دارد؛
 *    آنجا «اپ را به‌روز کن» بی‌ربط است.
 * ⛔ **نسخه‌ی نامعلوم یعنی قدیمی** (`installed` صفر). اپِ پیش از این
 *    قابلیت (۱٫۸ و قبل‌تر) هیچ نسخه‌ای نمی‌فرستد — و دقیقاً همان‌ها باید
 *    پیام بگیرند. اپِ تازه همیشه می‌فرستد و سرور روی همان اولین درخواست
 *    (حتی پشتِ ریدایرکتِ ورود) کوکی می‌گذارد، پس «نامعلوم» برایش پیش
 *    نمی‌آید.
 * ⛔ **«بعداً» فقط همان نسخه را، و فقط مدتی، ساکت می‌کند** (`snoozeFor`،
 *    `snoozeUntil`). نسخه‌ی تازه‌تر دوباره می‌پرسد؛ پیامی که یک بار بسته
 *    شود و دیگر هرگز نیاید، همان «هیچ اتفاقی نیفتاد» است.
 * ⚠ خالص و بیرون از `DOMContentLoaded` — آزمودنی در node.
 */
window.APP_UPDATE_SNOOZE_MS = 3 * 24 * 60 * 60 * 1000;
window.APP_UPDATE_TAPPED_MS = 6 * 60 * 60 * 1000;
window.appUpdateDue = function (o) {
    o = o || {};
    var latest = parseInt(o.latest, 10);
    if (!o.inApp || !(latest > 0)) { return false; }
    var installed = parseInt(o.installed, 10);
    if (!(installed > 0)) { installed = 0; }
    if (installed >= latest) { return false; }
    if (parseInt(o.snoozeFor, 10) === latest && Number(o.now) < Number(o.snoozeUntil)) { return false; }
    return true;
};

/**
 * ⛔ دکمه‌ی «به‌روزرسانی» کجا برود — تنها جای این تصمیم، خالص.
 *
 *    **گزارشِ مالکِ نصب:** «باید وقتی آپدیت جدید برای اندروید آمده روش
 *    بزنم و بلافاصله آپدیت بشه؛ الان می‌زنم دانلود می‌شه و باید دستی نصب
 *    کنم.» از نسخه‌ی ۱۱ به بعد اپ خودش فایل را دریافت می‌کند و
 *    **همان‌جا** پنجره‌ی نصبِ اندروید را باز می‌کند (`UpdateActivity`) —
 *    یک تپ روی «به‌روزرسانی»ِ همان پنجره. نصبِ کاملاً بی‌صدا را خودِ
 *    اندروید برای هیچ اپی جز فروشگاهِ سیستمی مجاز نمی‌کند.
 *
 * ⚠ اپِ قدیمی‌تر آن صفحه را ندارد، پس همان دانلودِ مرورگر می‌ماند؛ و
 *   `S.browser_fallback_url` یعنی اگر لینکِ `intent://` به هر دلیلی
 *   جایی نرسید، کروم خودش همان فایل را دانلود می‌کند — دکمه هرگز بی‌کار
 *   نمی‌ماند.
 */
window.APP_UPDATE_NATIVE_MIN = 11;
window.appUpdateHref = function (o) {
    o = o || {};
    var url = String(o.url || '');
    var installed = parseInt(o.installed, 10) || 0;
    var latest = parseInt(o.latest, 10) || 0;
    var pkg = String(o.pkg || '');
    if (installed >= window.APP_UPDATE_NATIVE_MIN && /^[a-z][a-z0-9_.]+$/i.test(pkg)
        && /^https:\/\//.test(url)) {
        return {
            native: true,
            href: 'intent://update?v=' + latest + '#Intent;scheme=hesabland;action='
                + pkg + '.UPDATE;package=' + pkg + ';S.browser_fallback_url='
                + encodeURIComponent(url) + ';end'
        };
    }
    return { native: false, href: url || '#' };
};

/* ============================================================
   ⛔ صفحه‌ی از-پیش-آماده (prerender) تا دیده نشده، هیچ کاری نمی‌کند
   ------------------------------------------------------------
   سه زبانه‌ی نوارِ پایین پیش از لمس کاملاً آماده می‌شوند
   (`speculationRulesJson()`). ولی این بدنه هنگامِ بارگذاری **کار
   می‌کند** — صفِ پیامکِ بانک تراکنش ثبت می‌کند، اعلانِ گوشی اشتراک
   می‌گیرد، معرفیِ اولیه باز می‌شود. صفحه‌ای که کاربر هنوز باز نکرده
   (و شاید هرگز باز نکند) نباید هیچ‌کدام را انجام بدهد. پس تا
   `document.prerendering` برقرار است بدنه منتظرِ `prerenderingchange`
   می‌ماند؛ HTML و CSS و چیدمان از قبل آماده‌اند، فقط این ~۳۰ms می‌ماند.
   ============================================================ */
function appMain() {
    // شاهدِ تست (`test_page_speed`): بدنه بعد از فعال شدن اجرا شد، نه هنگامِ prerender.
    window.__appMainAt = performance.now();

    // اول از همه: حالا که این فایل واقعاً اجرا شد، صفحه دیگر «در حال
    // آماده شدن» نیست. پیش از این، اگر app.js نمی‌رسید صفحه کامل و
    // خوش‌ظاهر بالا می‌آمد و کاربر دکمه می‌زد و هیچ اتفاقی نمی‌افتاد،
    // بی‌هیچ نشانه‌ای از خرابی.
    document.documentElement.classList.remove('js-loading');

    // ---------- نوارِ «نسخه‌ی تازه‌ی اپ آماده است» ----------
    // ⛔ تصمیم در `appUpdateDue()` است؛ اینجا فقط ورودی‌ها جمع می‌شوند و
    //    نوار ساخته می‌شود. متن‌ها با `textContent` (نامِ برنامه از
    //    پیکربندی می‌آید) و هیچ `position: fixed` ای نیست — همان کارتِ
    //    معمولیِ `.apk-bar`، بالای محتوا.
    (function appUpdateBar() {
        var meta = document.querySelector('meta[name="apk-latest"]');
        if (!meta) { return; }

        var inApp = false;
        try {
            // اپ روی اولین آدرس `?appv=` می‌گذارد و اولین ناوبریِ TWA
            // referrerِ `android-app://` دارد. sessionStorage مالِ همان
            // زبانه است، پس کرومِ معمولی آن را نمی‌بیند.
            var qs = /[?&]appv=\d/.test(location.search);
            if (qs || (document.referrer || '').indexOf('android-app://') === 0) {
                sessionStorage.setItem('daftar_in_app', '1');
                try { localStorage.setItem('daftar_has_apk', '1'); } catch (e2) {}
            }
            inApp = sessionStorage.getItem('daftar_in_app') === '1';
            if (qs && history.replaceState) {
                // ⚠ فرگمنت (`#smsq=`) دست نمی‌خورد؛ فقط `appv` از نوارِ آدرس می‌رود.
                var q = location.search.replace(/([?&])appv=\d+(&|$)/, function (m, a, b) { return b ? a : ''; })
                                       .replace(/[?&]$/, '');
                history.replaceState(history.state, '', location.pathname + q + location.hash);
            }
        } catch (e) {}
        // ⚠ ورود از صفحه‌ی ورود (ریدایرکت) referrer و query را می‌برد؛ اپِ
        //   نصب‌شده در حالتِ standalone باز می‌شود و کوکیِ نسخه را دارد.
        if (!inApp && window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
            var hasApk = false;
            try { hasApk = localStorage.getItem('daftar_has_apk') === '1'; } catch (e) {}
            inApp = hasApk || meta.getAttribute('data-installed') !== '';
        }

        var latest = parseInt(meta.getAttribute('content'), 10);
        var snoozeFor = 0, snoozeUntil = 0;
        try {
            var sz = String(localStorage.getItem('daftar_update_snooze') || '').split(':');
            snoozeFor = parseInt(sz[0], 10) || 0;
            snoozeUntil = parseInt(sz[1], 10) || 0;
        } catch (e) {}

        if (!window.appUpdateDue({
            latest: latest, installed: meta.getAttribute('data-installed'), inApp: inApp,
            snoozeFor: snoozeFor, snoozeUntil: snoozeUntil, now: Date.now()
        })) { return; }

        var host = document.querySelector('.page-content');
        if (!host) { return; }
        function snooze(ms) {
            try { localStorage.setItem('daftar_update_snooze', latest + ':' + (Date.now() + ms)); } catch (e) {}
        }

        var bar = document.createElement('div');
        bar.className = 'apk-bar app-update-bar';
        bar.setAttribute('role', 'status');
        var icon = document.createElement('span');
        icon.className = 'apk-bar-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 4v5h-5"/></svg>';
        var body = document.createElement('div');
        body.className = 'apk-bar-body';
        var title = document.createElement('strong');
        var name = meta.getAttribute('data-name') || '';
        title.textContent = 'نسخه‌ی تازه‌ی «' + (meta.getAttribute('data-app') || 'حساب لند') + '» آماده است'
            + (name ? ' (' + name.replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }).replace('.', '٫') + ')' : '') + '.';
        var note = document.createElement('span');
        note.textContent = 'با یک تپ به‌روز کنید؛ اطلاعاتتان روی سرور است و سرِ جایش می‌ماند.';
        body.appendChild(title);
        body.appendChild(note);
        var cta = document.createElement('a');
        cta.className = 'btn btn-primary btn-sm apk-bar-cta';
        var absUrl = '';
        try { absUrl = new URL(meta.getAttribute('data-url') || '', window.location.href).href; }
        catch (e) { absUrl = meta.getAttribute('data-url') || ''; }
        var go = window.appUpdateHref({
            url: absUrl, latest: latest,
            installed: meta.getAttribute('data-installed'),
            pkg: meta.getAttribute('data-package')
        });
        // ⚠ آدرسِ مطلق فقط برای بازگشتِ مرورگر داخلِ intent لازم است؛
        //   دانلودِ معمولی همان آدرسِ نسبیِ سرور را نگه می‌دارد.
        cta.href = go.native ? go.href : (meta.getAttribute('data-url') || '#');
        if (!go.native) { cta.setAttribute('download', ''); }
        cta.textContent = 'به‌روزرسانی';
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'apk-bar-x';
        x.setAttribute('aria-label', 'بعداً');
        x.textContent = '×';

        cta.addEventListener('click', function () {
            // ⚠ دانلود را خودِ مرورگر انجام می‌دهد؛ اینجا فقط گامِ بعد گفته
            //   می‌شود — اندروید نصب را خودش نمی‌کند، کاربر باید فایل را باز کند.
            snooze(window.APP_UPDATE_TAPPED_MS);
            note.textContent = go.native
                ? 'در حال دریافت… وقتی پنجره‌ی نصب آمد «به‌روزرسانی» را بزنید.'
                : 'فایل در حال دریافت است. از اعلانِ دانلود بازش کنید و «به‌روزرسانی» را بزنید.'
                  + ' اگر اندروید اجازه خواست، «نصب از این منبع» را روشن کنید.';
        });
        x.addEventListener('click', function () {
            snooze(window.APP_UPDATE_SNOOZE_MS);
            bar.remove();
        });

        bar.appendChild(icon);
        bar.appendChild(body);
        bar.appendChild(cta);
        bar.appendChild(x);
        host.insertBefore(bar, host.firstChild);
    })();

    // پایه‌ی آدرس API — تا فراخوانی‌ها از داخل پوشه admin/ هم درست کار کند
    function apiUrl(name) {
        var base = (typeof window.APP_BASE === 'string') ? window.APP_BASE : '';
        return base + '/api/' + name;
    }

    // ⛔ همه‌ی درخواست‌های این بدنه توکن را فقط از اینجا می‌گیرند (`window.csrfToken`).
    function csrf() { return window.csrfToken(); }

    // ---------- ⛔ جفت شدنِ گوشی برای ثبتِ پیامک در پس‌زمینه ----------
    //
    // اپ اندروید وقتی هنوز کلید ندارد، صفحه را با `#smslink=<کد>` باز می‌کند.
    // کدِ تصادفی را **خودِ اپ** ساخته؛ این‌جا فقط به کاربرِ همین نشست بسته
    // می‌شود (`api/sms_link.php`) و اپ با همان کد کلیدِ محدودِ `sms` را
    // می‌گیرد. بعد صفحه‌ی «خواندنِ پیامک»ِ اپ باز می‌شود تا همان لحظه کلید را
    // بگیرد و اجازه‌ی باتری را بپرسد.
    // ⚠ فرگمنت **پیش از** درخواست پاک می‌شود: با تازه‌سازی دوباره نرود.
    (function smsLinkFromFragment() {
        var m = /^#smslink=([a-f0-9]{32,64})$/.exec(window.location.hash || '');
        if (!m) { return; }
        try {
            history.replaceState(history.state, '', window.location.pathname + window.location.search);
        } catch (e) { window.location.hash = ''; }
        if (!csrf()) { return; }
        var fd = new FormData();
        fd.set('csrf_token', csrf());
        fd.set('nonce', m[1]);
        fetch(apiUrl('sms_link.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.json(); })
            .then(function (j) {
                if (!j || !j.success) { return; }
                var meta = document.querySelector('meta[name="apk-latest"]');
                var pkg = meta ? String(meta.getAttribute('data-package') || '') : '';
                if (!/^[a-z][a-z0-9_.]+$/i.test(pkg)) { return; }
                window.location.href = 'intent://sms-setup#Intent;scheme=hesabland;action='
                    + pkg + '.SMS_SETUP;package=' + pkg + ';S.linked=1;end';
            })
            .catch(function () { /* بارِ بعدی که اپ باز شود دوباره می‌پرسد */ });
    })();

    // ---------- منوی موبایل ----------
    var menuToggle = document.getElementById('menuToggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');

    if (menuToggle && sidebar && overlay) {
        menuToggle.addEventListener('click', function () {
            sidebar.classList.add('open');
            overlay.classList.add('show');
        });
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    }

    // ---------- فرمت‌کننده مبلغ (جداکننده سه‌رقمی هنگام تایپ) ----------
    // ⚠ هم ارقامِ فارسی (۰-۹) و هم عربی (٠-٩) — کیبوردِ عربیِ بعضی گوشی‌ها
    //   دومی را می‌نویسد؛ همان کاری که `sms-core.js` و `store.js` می‌کنند.
    function toLatinDigitsJs(str) {
        return String(str).replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 0x06F0; })
                          .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 0x0660; });
    }

    function toPersianDigitsJs(str) {
        var persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return String(str).replace(/[0-9]/g, function (d) { return persian[d]; });
    }

    // ⛔ تنها شکلِ نمایشِ مبلغ در این فایل: «۱٬۲۳۴٬۵۶۷» (ارقامِ فارسی، جداکننده‌ی
    //    عربیِ ٬). پیش از این ~۲۰ کپیِ همین یک خط بود.
    function fmtMoneyJs(n) {
        return toPersianDigitsJs(Number(n).toLocaleString('en-US').replace(/,/g, '\u066C'));
    }

    // بازگرداندن یک <select> به گزینه‌ی پیش‌فرضِ خودِ HTML.
    // مقداردهی دستی به '0' اینجا کار نمی‌کند: فهرست حسابِ فروش گزینه‌ی
    // «۰» ندارد و مرورگر انتخاب را خالی می‌گذارد، آن‌وقت فرم بدون حساب
    // ارسال می‌شد.
    function resetSelect(el) {
        if (!el) return;
        for (var i = 0; i < el.options.length; i++) {
            if (el.options[i].defaultSelected) { el.selectedIndex = i; return; }
        }
        el.selectedIndex = 0;
    }

    function setupAmountFormatter(inputId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('input', function () {
            // ⚠ اول تبدیل، بعد پاک‌سازی: `\d` بی‌پرچمِ `u` فقط ۰-۹ِ لاتین است و ارقامِ
            //   عربیِ ٠-٩ را بی‌صدا حذف می‌کرد.
            var raw = toLatinDigitsJs(this.value).replace(/[^0-9]/g, '');
            if (raw === '') {
                this.value = '';
                return;
            }
            this.value = fmtMoneyJs(raw);
        });
    }

    setupAmountFormatter('amount');
    setupAmountFormatter('edit_amount');

    // ---------- تاگل نوع تراکنش (درآمد/هزینه) — قابل استفاده مجدد برای چند فرم مستقل ----------
    function populateCategorySelect(selectEl, type) {
        if (!selectEl || !window.CATEGORY_DATA) return;
        while (selectEl.options.length > 1) {
            selectEl.remove(1);
        }
        var list = window.CATEGORY_DATA[type] || [];
        list.forEach(function (cat) {
            var opt = document.createElement('option');
            opt.value = cat.id;
            opt.textContent = cat.name;
            selectEl.appendChild(opt);
        });
    }

    // ---------- شبکه‌ی چیپ‌های دسته‌بندی ----------
    //
    // ⛔ جایگزینِ `<select>` نیست، رویش سوار است: مقدار همیشه در خودِ
    //    `<select>` می‌نشیند و چیپ فقط `value` را می‌نویسد و `change`
    //    می‌دهد. پس `optionPicker()`، `resetSelect()`، `smsAutoOk()` و
    //    اعتبارسنجیِ فرم دست‌نخورده کار می‌کنند.
    //
    // ⛔ هر بار از نو ساخته می‌شود: عوض شدنِ نوع فهرست را عوض می‌کند و
    //    یک نسخه‌ی کش‌شده بی‌صدا کهنه می‌ماند — همان درسِ `setType()`.
    function syncCategoryGrid(gridEl, selectEl) {
        if (!gridEl || !selectEl) return;
        gridEl.querySelectorAll('.cat-chip').forEach(function (c) {
            c.classList.toggle('active', c.getAttribute('data-id') === selectEl.value);
        });
    }

    function renderCategoryGrid(gridEl, selectEl, type) {
        if (!gridEl || !selectEl || !window.CATEGORY_DATA) return;

        var list = window.CATEGORY_DATA[type] || [];
        var max = window.CATEGORY_GRID_MAX || 8;
        gridEl.textContent = '';

        // ⛔ کدام دسته‌ها چیپ می‌گیرند را **سرور** تصمیم می‌گیرد
        //    (`categoriesForGrid()`): یا پینِ خودِ کاربر، یا
        //    پرکاربردترین‌ها. اینجا فقط ترتیبِ همان فهرست پیاده می‌شود.
        //    با `slice()` در مرورگر، انتخابِ کاربر بی‌صدا نادیده می‌ماند.
        var wanted = (window.CATEGORY_GRID && window.CATEGORY_GRID[type]) || null;
        var shown;
        if (wanted && wanted.length) {
            var byId = {};
            list.forEach(function (c) { byId[c.id] = c; });
            shown = wanted.map(function (id) { return byId[id]; }).filter(Boolean);
        } else {
            shown = list.slice(0, max);
        }

        shown.slice(0, max).forEach(function (cat) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cat-chip';
            btn.setAttribute('data-id', String(cat.id));
            btn.style.setProperty('--cc', cat.color || '#64748b');

            var ic = document.createElement('span');
            ic.className = 'cat-chip-icon';
            // ⚠ این markup از `categoryIconSvg()` می‌آید (نگاشتِ ثابتِ
            //   خودمان)، نه از ورودیِ کاربر — برخلافِ نام، که پایین با
            //   `textContent` نوشته می‌شود.
            ic.innerHTML = cat.icon || '';

            var nm = document.createElement('span');
            nm.className = 'cat-chip-name';
            nm.textContent = cat.name;

            btn.appendChild(ic);
            btn.appendChild(nm);

            btn.addEventListener('click', function () {
                // تپ روی چیپِ فعال، انتخاب را برمی‌دارد — وگرنه کاربری که
                // اشتباه زده هیچ راهی جز باز کردنِ منو نداشت.
                var id = String(cat.id);
                selectEl.value = (selectEl.value === id) ? '' : id;
                selectEl.dispatchEvent(new Event('change', { bubbles: true }));
            });

            gridEl.appendChild(btn);
        });

        syncCategoryGrid(gridEl, selectEl);
    }

    function setupTypeToggle(toggleId, hiddenInputId, categorySelectId, gridId) {
        var toggle = document.getElementById(toggleId);
        if (!toggle) return null;

        var buttons = toggle.querySelectorAll('.type-btn');
        var hiddenInput = document.getElementById(hiddenInputId);
        var categorySelect = categorySelectId ? document.getElementById(categorySelectId) : null;
        var grid = gridId ? document.getElementById(gridId) : null;

        // هر تغییرِ مقدار — از چیپ، از `optionPicker()`، یا از مسیرِ
        // «عنوانِ قبلی» — چیپِ فعال را هم‌گام می‌کند.
        if (grid && categorySelect) {
            categorySelect.addEventListener('change', function () {
                syncCategoryGrid(grid, categorySelect);
            });
        }

        function activate(btn, resetCategory) {
            buttons.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var type = btn.getAttribute('data-type');
            hiddenInput.value = type;

            if (categorySelect && (type === 'income' || type === 'expense')) {
                populateCategorySelect(categorySelect, type);
                if (resetCategory) categorySelect.value = '';
                renderCategoryGrid(grid, categorySelect, type);
            }
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () { activate(this, true); });
        });

        var initialBtn = toggle.querySelector('.type-btn.active') || buttons[0];
        if (initialBtn) activate(initialBtn, false);

        return {
            setType: function (type) {
                var btn = toggle.querySelector('.type-btn[data-type="' + type + '"]');
                if (btn) activate(btn, false);
            }
        };
    }

    var quickAddToggle = setupTypeToggle('typeToggle', 'transactionType', 'category_id', 'catGrid');
    var editToggle = setupTypeToggle('editTypeToggle', 'edit_transaction_type', 'edit_category_id');

    // ---------- ارسال فرم ثبت سریع تراکنش (AJAX) ----------
    var quickAddForm = document.getElementById('quickAddForm');
    if (quickAddForm) {
        quickAddForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var submitBtn = document.getElementById('submitBtn');
            var submitBtnText = document.getElementById('submitBtnText');
            var formMessage = document.getElementById('formMessage');
            var amountInput = document.getElementById('amount');

            var formData = new FormData(quickAddForm);
            var rawAmount = amountInput.value.replace(/[,\u066C]/g, '');
            formData.set('amount', rawAmount);

            // \u0646\u0634\u0627\u0646\u0647\u200C\u06CC \u00AB\u0627\u06CC\u0646 \u062B\u0628\u062A \u062E\u0648\u062F\u06A9\u0627\u0631 \u0628\u0648\u062F\u00BB \u2014 \u0647\u0645\u0627\u0646\u200C\u062C\u0627 \u062E\u0648\u0627\u0646\u062F\u0647 \u0648 **\u067E\u0627\u06A9**
            // \u0645\u06CC\u200C\u0634\u0648\u062F\u060C \u0648\u06AF\u0631\u0646\u0647 \u062B\u0628\u062A\u0650 \u062F\u0633\u062A\u06CC\u0650 \u0628\u0639\u062F\u06CC \u0647\u0645 \u0646\u0648\u0627\u0631\u0650 \u00AB\u062E\u0648\u062F\u06A9\u0627\u0631 \u062B\u0628\u062A \u0634\u062F\u00BB
            // \u0645\u06CC\u200C\u06AF\u0631\u0641\u062A.
            var auto = null;
            if (quickAddForm.dataset.autoSms) {
                try { auto = JSON.parse(quickAddForm.dataset.autoSms); } catch (e) { auto = null; }
                delete quickAddForm.dataset.autoSms;
            }
            // ⚠ مانده‌ی پیامک هم همین‌جا خوانده و **پاک** می‌شود: اگر این
            //   ثبت شکست بخورد، ثبتِ دستیِ بعدی نباید آن را با خودش ببرد.
            var smsBal = null;
            if (quickAddForm.dataset.smsBal) {
                try { smsBal = JSON.parse(quickAddForm.dataset.smsBal); } catch (e) { smsBal = null; }
                delete quickAddForm.dataset.smsBal;
            }

            // ⛔ «یاد بگیر» فقط برای ثبتِ **دستیِ** همین پیامک (نه خودکار — آنجا
            //    حساب از قبل قطعی بود)، و مثلِ مانده خوانده و **پاک** می‌شود.
            var smsLearn = null;
            if (quickAddForm.dataset.smsLearn) {
                try { smsLearn = JSON.parse(quickAddForm.dataset.smsLearn); } catch (e) { smsLearn = null; }
                delete quickAddForm.dataset.smsLearn;
            }
            if (auto) { smsLearn = null; }

            submitBtn.disabled = true;
            submitBtnText.textContent = 'در حال ثبت...';

            fetch(apiUrl('add_transaction.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                formMessage.hidden = false;
                formMessage.classList.remove('success', 'error');

                if (data.success) {
                    if (quickAddForm.dataset.smsFp) {
                        smsMarkAuto(quickAddForm.dataset.smsFp);
                        delete quickAddForm.dataset.smsFp;
                    }
                    formMessage.classList.add('show', 'success');
                    formMessage.textContent = data.message || 'تراکنش با موفقیت ثبت شد.';

                    // ⛔ ثبتِ خودکار باید **بعد از تازه‌سازی** هم راهِ
                    //    برگشت داشته باشد. تازه‌سازی قابلِ حذف نیست (جمع‌ها
                    //    و موجودیِ بالای صفحه از سمتِ سرور رندر شده‌اند و
                    //    بدونش کاربر فکر می‌کند ثبت نشده)، پس نشانه در
                    //    `sessionStorage` می‌ماند و نوارِ «لغو» را همان
                    //    صفحه‌ی تازه می‌سازد. اگر اینجا فقط یک دکمه‌ی
                    //    درجا می‌گذاشتیم، تازه‌سازی می‌بردش — یعنی ثبتِ
                    //    خودکاری بدونِ راهِ برگشت.
                    if (auto && data.id) {
                        queueUndoBar(
                            'از پیامک بانک خودکار ثبت شد: '
                            + (auto.type === 'income' ? 'واریز ' : 'برداشت ')
                            + fmtMoneyJs(auto.amount || 0)
                            + ' تومان',
                            { ep: 'delete_transaction.php', field: 'transaction_id', value: data.id }
                        );
                    }
                    var chosen = String(formData.get('wallet_id') || '');
                    if (smsLearn && /^[1-9][0-9]*$/.test(chosen)) {
                        smsLearnWallet(chosen, smsLearn).then(function () { window.reloadPage(); });
                        return;
                    }
                    // ⛔ مانده‌ی پیامک فقط روی همان حسابی که پیامک نشان داد؛
                    //    اگر کاربر حساب را در فرم عوض کرده، دست نمی‌زنیم.
                    if (smsBal && String(formData.get('wallet_id')) === String(smsBal.w)) {
                        smsPostBalance(smsBal.w, smsBal.b, smsBal.d)
                            .then(function () { window.reloadPage(); });
                        return;
                    }
                    window.reloadPage();
                } else {
                    formMessage.classList.add('show', 'error');
                    formMessage.textContent = data.message || 'خطایی رخ داد. دوباره تلاش کنید.';
                }
            })
            .catch(function () {
                formMessage.hidden = false;
                formMessage.classList.add('show', 'error');
                formMessage.textContent = netErr() + ' اتصال اینترنت را بررسی کنید.';
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtnText.textContent = 'ثبت تراکنش';
            });
        });
    }

    // ---------- یادآورها و اعلان‌ها ----------
    //
    // ⚠ همه‌ی این فرم‌ها بعد از موفقیت صفحه را تازه می‌کنند، چون فهرست و
    //   نشانِ نخوانده‌ی زنگ از سمتِ سرور رندر شده‌اند. بدونِ تازه‌سازی
    //   کاربر «ذخیره شد» می‌بیند ولی هیچ تغییری روی صفحه نمی‌بیند و فکر
    //   می‌کند کار نکرده — همان قاعده‌ی «هر تغییر پول باید بلافاصله
    //   دیده شود».
    (function () {
        var post = function (url, form) {
            return fetch(url, {
                method: 'POST', body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); });
        };

        var reminderForm = document.getElementById('reminderForm');
        if (reminderForm) {
            reminderForm.addEventListener('submit', function (e) {
                e.preventDefault();
                post(apiUrl('save_reminder.php'), reminderForm)
                    .then(function (d) {
                        if (d.success) { window.reloadPage(); }
                        else { alert(d.message || 'ذخیره نشد.'); }
                    })
                    .catch(function () { alert(netErr()); });
            });

            // ویرایش: همان فرم پر می‌شود، نه یک مودالِ دوم — با فرمِ دوم
            // اعتبارسنجی و تقویم باید دو بار نوشته می‌شدند.
            var resetBtn = document.querySelector('.js-reminder-reset');

            var repeatChips = document.getElementById('rm_repeat_chips');
            var customWrap  = document.getElementById('rm_custom');
            var nInput      = document.getElementById('rm_n');
            var unitSel     = document.getElementById('rm_unit');
            var rtypeIn     = document.getElementById('rm_rtype');
            var rnIn        = document.getElementById('rm_rn');
            var countWrap   = document.getElementById('rm_count_wrap');
            var countIn     = document.getElementById('rm_count');
            var amountIn    = document.getElementById('rm_amount');
            var amodeChips  = document.getElementById('rm_amount_mode');
            var amodeIn     = document.getElementById('rm_amode');
            var planNote    = document.getElementById('rm_plan_note');
            var daysWrap    = document.getElementById('rm_notify_days');
            var daysIn      = document.getElementById('rm_days');

            var fa = function (s) {
                return String(s).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
            };
            var groupNum = function (n) {
                return fa(String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '٬'));
            };

            // ⛔ تنها جایی که وضعیتِ فرم به فیلدهای پنهان می‌نشیند. چیپ‌ها
            //    `<button>` اند و خودشان هیچ چیزی به FormData اضافه
            //    نمی‌کنند — اگر این تابع جا بیفتد، فرم بی‌صدا مقدارِ
            //    پیش‌فرض را می‌فرستد و کاربر فکر می‌کند انتخابش ذخیره شد.
            function syncRepeat() {
                if (!repeatChips || !rtypeIn) { return; }
                var on  = repeatChips.querySelector('.stay-chip.active');
                var key = on ? on.dataset.key : 'once';

                if (customWrap) { customWrap.hidden = key !== 'custom'; }

                if (key === 'custom') {
                    rtypeIn.value = unitSel ? unitSel.value : 'every_n_months';
                    rnIn.value    = String(Math.max(1, Math.min(60, parseInt(nInput && nInput.value, 10) || 1)));
                } else if (on) {
                    rtypeIn.value = on.dataset.type;
                    rnIn.value    = on.dataset.n;
                }

                // «چند بار تکرار شود» فقط برای تکرارشونده معنا دارد.
                var repeats = rtypeIn.value !== 'once';
                if (countWrap) {
                    countWrap.hidden = !repeats;
                    if (!repeats && countIn) { countIn.value = '0'; }
                }
                syncPlan();
            }

            // خلاصه‌ی زنده: کاربر پیش از ذخیره می‌بیند «۱۲ قسط ۱۲ میلیون»
            // یعنی چه. بدونِ آن، انتخابِ «جمع کل» و «هر قسط» از بیرون یکی
            // به نظر می‌رسند و اشتباهش ده‌برابرِ مبلغ است.
            function syncPlan() {
                if (!planNote || !amountIn) { return; }
                var count = Math.max(0, parseInt(countIn && countIn.value, 10) || 0);
                var multi = count > 1 && rtypeIn && rtypeIn.value !== 'once';
                if (amodeChips) { amodeChips.hidden = !multi; }
                if (!multi) { planNote.hidden = true; return; }

                // ⚠ کیبوردِ فارسی «۱۲۰۰۰» می‌نویسد؛ بی‌تبدیل، `[^0-9]` همه را می‌برد و
                //   خلاصه بی‌صدا پنهان می‌ماند.
                var raw = toLatinDigitsJs(String(amountIn.value || '')).replace(/[^0-9]/g, '');
                if (!raw) { planNote.hidden = true; return; }

                var val = parseInt(raw, 10);
                var total, each, last;
                if (amodeIn && amodeIn.value === 'total') {
                    total = val;
                    each  = Math.floor(total / count);
                    last  = total - each * (count - 1);
                } else {
                    each = val; last = val; total = each * count;
                }
                planNote.hidden = false;
                planNote.textContent = fa(count) + ' قسط · هر قسط ' + groupNum(each) +
                    (last !== each ? ' (آخری ' + groupNum(last) + ')' : '') +
                    ' · جمع کل ' + groupNum(total) + ' تومان';
            }

            function syncDays() {
                if (!daysWrap || !daysIn) { return; }
                var picked = [];
                daysWrap.querySelectorAll('.stay-chip.active').forEach(function (c) {
                    picked.push(parseInt(c.dataset.day, 10));
                });
                picked.sort(function (a, b) { return b - a; });
                daysIn.value = JSON.stringify(picked);
            }

            if (repeatChips) {
                repeatChips.addEventListener('click', function (e) {
                    var b = e.target.closest('.stay-chip');
                    if (!b) { return; }
                    repeatChips.querySelectorAll('.stay-chip').forEach(function (c) {
                        c.classList.toggle('active', c === b);
                    });
                    syncRepeat();
                });
            }
            if (nInput)  { nInput.addEventListener('input', syncRepeat); }
            if (unitSel) { unitSel.addEventListener('change', syncRepeat); }
            if (countIn) { countIn.addEventListener('input', syncPlan); }
            if (amountIn) { amountIn.addEventListener('input', syncPlan); }
            if (amodeChips) {
                amodeChips.addEventListener('click', function (e) {
                    var b = e.target.closest('.stay-chip');
                    if (!b) { return; }
                    amodeChips.querySelectorAll('.stay-chip').forEach(function (c) {
                        c.classList.toggle('active', c === b);
                    });
                    if (amodeIn) { amodeIn.value = b.dataset.mode; }
                    syncPlan();
                });
            }
            if (daysWrap) {
                // ⚠ چندانتخابی: چیپ‌ها همدیگر را خاموش نمی‌کنند.
                daysWrap.addEventListener('click', function (e) {
                    var b = e.target.closest('.stay-chip');
                    if (!b) { return; }
                    b.classList.toggle('active');
                    syncDays();
                });
            }

            var presetWrap = document.getElementById('rm_presets');
            if (presetWrap) {
                presetWrap.addEventListener('click', function (e) {
                    var b = e.target.closest('.stay-chip');
                    if (!b) { return; }
                    presetWrap.querySelectorAll('.stay-chip').forEach(function (c) {
                        c.classList.toggle('active', c === b);
                    });
                    var t = document.getElementById('rm_title');
                    if (t) { t.value = b.dataset.title || ''; if (!b.dataset.title) { t.focus(); } }
                    pickRepeat(b.dataset.repeat || 'once');
                });
            }

            // چیپِ تکرار را با کلید انتخاب می‌کند — هم برای پیش‌فرض‌ها و
            // هم برای پر کردنِ فرمِ ویرایش، تا یک منطق باشد نه دو تا.
            function pickRepeat(key) {
                if (!repeatChips) { return; }
                var found = false;
                repeatChips.querySelectorAll('.stay-chip').forEach(function (c) {
                    var on = c.dataset.key === key;
                    if (on) { found = true; }
                    c.classList.toggle('active', on);
                });
                if (!found) {
                    var last = repeatChips.querySelector('.stay-chip[data-key="custom"]');
                    if (last) { last.classList.add('active'); }
                }
                syncRepeat();
            }

            syncRepeat();
            syncDays();

            document.querySelectorAll('.js-reminder-edit').forEach(function (b) {
                b.addEventListener('click', function () {
                    document.getElementById('rm_id').value      = b.dataset.id;
                    document.getElementById('rm_title').value   = b.dataset.title;
                    document.getElementById('rm_note').value    = b.dataset.note || '';

                    if (countIn) { countIn.value = b.dataset.count || '0'; }
                    // ⛔ با «جمع کل» پر می‌شود، نه با مبلغِ هر قسط: باقیمانده‌ی
                    //    تقسیم روی قسطِ آخر است، پس ذخیره‌ی دوباره از روی
                    //    مبلغِ هر قسط جمعِ کل را بی‌صدا چند تومان کم می‌کرد.
                    var total = parseInt(b.dataset.total, 10) || 0;
                    if (total > 0 && (parseInt(b.dataset.count, 10) || 0) > 1) {
                        if (amountIn) { amountIn.value = total; }
                        if (amodeIn) { amodeIn.value = 'total'; }
                        if (amodeChips) {
                            amodeChips.querySelectorAll('.stay-chip').forEach(function (c) {
                                c.classList.toggle('active', c.dataset.mode === 'total');
                            });
                        }
                    } else if (amountIn) {
                        amountIn.value = b.dataset.amount !== '0' ? b.dataset.amount : '';
                    }

                    if (nInput)  { nInput.value  = b.dataset.rn || '1'; }
                    if (unitSel) { unitSel.value = b.dataset.rtype === 'every_n_days' ? 'every_n_days' : 'every_n_months'; }
                    pickRepeat(b.dataset.rkey || 'once');

                    // ⛔ روزهای اعلان یک آرایه‌ی JSON است. اگر خرابِ ذخیره شده
                    //    باشد نباید کلِ فرم بایستد — انتخاب‌ها فقط پاک می‌شوند.
                    var picked = [];
                    try { picked = JSON.parse(b.dataset.days || '[1]') || []; } catch (err) { picked = []; }
                    if (daysWrap) {
                        daysWrap.querySelectorAll('.stay-chip').forEach(function (c) {
                            c.classList.toggle('active', picked.indexOf(parseInt(c.dataset.day, 10)) !== -1);
                        });
                        syncDays();
                    }
                    var disp = document.getElementById('rm_date_display');
                    if (disp) { disp.value = b.dataset.date; }
                    if (resetBtn) { resetBtn.hidden = false; }
                    reminderForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });
            if (resetBtn) {
                resetBtn.addEventListener('click', function () { window.reloadPage(); });
            }
        }

        // صفحه‌ی سررسیدها: تسویه / تعویق / رد
        document.querySelectorAll('.js-due-form').forEach(function (f) {
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                post(f.getAttribute('action'), f)
                    .then(function (d) {
                        if (d.success) { window.reloadPage(); }
                        else { alert(d.message || 'انجام نشد.'); }
                    })
                    .catch(function () { alert(netErr()); });
            });
        });

        document.querySelectorAll('.js-reminder-done, .js-reminder-delete, .js-notif-clear')
            .forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    // ⚠ حذفِ یادآور بدونِ «مطمئنید؟» است چون برگشت‌پذیر
                    //   شده؛ «انجام شد» و «پاک کردن اعلان» از اول تأیید
                    //   نمی‌خواستند.
                    var isDel = f.classList.contains('js-reminder-delete');
                    post(f.getAttribute('action'), f)
                        .then(function (d) {
                            if (!d.success) { alert(d.message || 'انجام نشد.'); return; }
                            if (isDel && d.undo_token) {
                                queueUndoBar('یادآور حذف شد.', {
                                    ep: 'undo_delete.php', field: 'undo_token',
                                    value: d.undo_token
                                });
                            }
                            window.reloadPage();
                        })
                        .catch(function () { alert(netErr()); });
                });
            });
    })();

    // ---------- اعلام پرداخت اشتراک ----------
    (function () {
        var form = document.getElementById('payForm');
        if (!form) { return; }
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var msg = document.getElementById('payMessage');
            var sub = document.getElementById('paySubmit');
            msg.hidden = true; msg.classList.remove('show', 'error', 'success');
            sub.disabled = true;

            fetch(apiUrl('submit_payment.php'), {
                method: 'POST', body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                msg.hidden = false;
                msg.classList.add('show', d.success ? 'success' : 'error');
                msg.textContent = d.message || (d.success ? 'ثبت شد.' : 'ثبت نشد.');
                // تازه‌سازی تا فهرستِ «پرداخت‌های شما» همان لحظه به‌روز شود —
                // بدون آن کاربر فکر می‌کند ثبت نشده و دوباره می‌فرستد.
                if (d.success) { setTimeout(function () { window.reloadPage(); }, 1200); }
            })
            .catch(function () {
                msg.hidden = false;
                msg.classList.add('show', 'error');
                msg.textContent = netErr();
            })
            .finally(function () { sub.disabled = false; });
        });
    })();

    // ---------- حذف حساب ----------
    // ⛔ عمداً بعد از موفقیت هیچ چیزی روی صفحه نشان داده نمی‌شود بلکه
    //    مستقیم به صفحه‌ی ورود می‌رود: نشستِ کاربر همان لحظه باطل شده و
    //    ماندن روی صفحه‌ای که دیگر داده‌ای پشتش نیست فقط خطای بی‌ربط
    //    می‌سازد.
    (function () {
        var btn  = document.getElementById('deleteAccountBtn');
        var form = document.getElementById('deleteAccountForm');
        if (!btn || !form) { return; }

        btn.addEventListener('click', function () { openModal('deleteAccountModal'); });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var msg = document.getElementById('deleteAccountMessage');
            var sub = document.getElementById('deleteAccountSubmit');
            msg.hidden = true;
            msg.classList.remove('show', 'error');
            sub.disabled = true;

            fetch(apiUrl('delete_account.php'), {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    window.location.href = d.redirect || 'login.php';
                    return;
                }
                msg.hidden = false;
                msg.classList.add('show', 'error');
                msg.textContent = d.message || 'حذف انجام نشد.';
            })
            .catch(function () {
                msg.hidden = false;
                msg.classList.add('show', 'error');
                msg.textContent = netErr();
            })
            .finally(function () { sub.disabled = false; });
        });
    })();

    // ---------- ثبتِ خودکار از پیامک: کلید، و نگهبانِ تکراری ----------
    //
    // هر دو کلید عمداً در `localStorage` همان دستگاه‌اند و هیچ‌کدام به
    // سرور نمی‌روند — همان قاعده‌ای که متنِ پیامک را در فرگمنت نگه
    // می‌دارد. `try/catch` هم لازم است: در پنجره‌ی ناشناس یا با بستنِ
    // داده‌ی سایت، خواندن و نوشتن **استثنا پرتاب می‌کند** و آن استثنا
    // کلِ ثبتِ تراکنش را می‌خواباند.
    //
    // (خودِ کلیدِ روشن/خاموش بالاتر و بیرون از این بلوک است، تا در node
    //  آزمودنی بماند: `window.smsAutoEnabled`.)
    var SMS_AUTO_KEY  = window.SMS_AUTO_KEY;
    var SMS_SEEN_KEY  = 'daftar_sms_seen';
    // ⚠ هشت روز، نه یک شبانه‌روز: اپ اندروید حالا صندوقِ هفت روزِ اخیر را
    //   هم می‌آورد («بررسی پیامک‌های قبلی»)، و نگهبانی کوتاه‌تر از آن
    //   پنجره یعنی پیامکی که دیروز ثبت شده دوباره ثبت می‌شد.
    var SMS_SEEN_TTL  = 8 * 24 * 60 * 60 * 1000;
    var SMS_SEEN_MAX  = 200;

    var smsAutoEnabled = window.smsAutoEnabled;

    function smsSeenList() {
        try {
            var v = JSON.parse(localStorage.getItem(SMS_SEEN_KEY) || '[]');
            if (!Array.isArray(v)) { return []; }
            var now = Date.now();
            return v.filter(function (e) {
                return e && e.h && (now - (e.t || 0)) < SMS_SEEN_TTL;
            });
        } catch (e) { return []; }
    }

    /** آیا این پیامک همین اواخر خودکار ثبت شده؟ */
    function smsAlreadyAuto(fp) {
        var list = smsSeenList();
        for (var i = 0; i < list.length; i++) {
            if (list[i].h === fp) { return true; }
        }
        return false;
    }

    function smsMarkAuto(fp) {
        var list = smsSeenList();
        list.push({ h: fp, t: Date.now() });
        if (list.length > SMS_SEEN_MAX) { list = list.slice(-SMS_SEEN_MAX); }
        try { localStorage.setItem(SMS_SEEN_KEY, JSON.stringify(list)); }
        catch (e) { /* بی‌نگهبان بهتر از خرابیِ ثبت است */ }
    }

    // ---------- پیامک‌های «برای بررسی» ----------
    //
    // ⛔ **گزارشِ مالکِ نصب:** «خواندن اس ام اس الان معضل شده، یکسره روی
    //    صفحه‌ست، مخصوصاً وقتی بخش حساب‌ها می‌رم و هیچ کاری نمیشه کرد.»
    //    صفِ قبلی با **هر** بارگذاری شیتِ ثبت را باز می‌کرد تا کاربر
    //    پیامکِ نامطمئن را تکلیف کند — یعنی تا وقتی صف خالی نمی‌شد، هیچ
    //    صفحه‌ای قابلِ استفاده نبود.
    //
    //    حالا پیامکِ نامطمئن فقط **یادداشت** می‌شود و هیچ چیزی باز
    //    نمی‌شود. کارتِ کوچکِ «برای بررسی» فقط روی **خانه** است و کاربر
    //    هر وقت خواست تکلیفش را روشن می‌کند (ثبت یا رد).
    // ⚠ متن روی همین دستگاه می‌ماند (localStorage، نه سرور) — همان قاعده‌ی
    //   فرگمنت. مهلتش یک هفته است: پیامکِ کهنه‌تر از آن دیگر ارزشِ
    //   بررسی ندارد و فقط کارت را شلوغ می‌کند.
    var SMS_PENDING_KEY = 'daftar_sms_pending';
    var SMS_PENDING_TTL = 7 * 24 * 60 * 60 * 1000;
    var SMS_PENDING_MAX = 30;

    function smsPendingRead() {
        try {
            var v = JSON.parse(localStorage.getItem(SMS_PENDING_KEY) || '[]');
            if (!Array.isArray(v)) { return []; }
            var now = Date.now();
            return v.filter(function (e) {
                return e && typeof e.raw === 'string' && e.fp
                    && (now - (e.at || 0)) < SMS_PENDING_TTL
                    && !smsAlreadyAuto(e.fp);
            });
        } catch (e) { return []; }
    }
    function smsPendingWrite(list) {
        try {
            if (!list.length) { localStorage.removeItem(SMS_PENDING_KEY); }
            else { localStorage.setItem(SMS_PENDING_KEY, JSON.stringify(list.slice(-SMS_PENDING_MAX))); }
        } catch (e) { /* ناشناس — فقط همین نوبت دیده نمی‌شود */ }
    }
    function smsPendingAdd(raw, fp) {
        var list = smsPendingRead();
        for (var i = 0; i < list.length; i++) { if (list[i].fp === fp) { return false; } }
        list.push({ raw: raw, fp: fp, at: Date.now() });
        smsPendingWrite(list);
        return true;
    }
    /** «رد»: از فهرست بیرون، و دیگر هرگز از صندوق برنمی‌گردد. */
    function smsPendingDrop(fp) {
        smsMarkAuto(fp);
        smsPendingWrite(smsPendingRead().filter(function (e) { return e.fp !== fp; }));
    }

    // ---------- ثبت و هم‌ترازیِ مانده، بی‌آنکه چیزی باز شود ----------
    //
    // ⚠ از همان اندپوینت‌هایی می‌رود که فرم و «تعدیل موجودی» می‌روند
    //   (`add_transaction.php`, `adjust_wallet.php`) — منطقِ پول فقط
    //   سمتِ سرور است (`txCreate()`)، اینجا فقط پاکت ساخته می‌شود.
    /** عنوان‌های اخیر از `#recentTitles` (همان datalist) برای `smsLearnedCategory()`. */
    function smsCategoryFor(r) {
        var list = document.getElementById('recentTitles');
        var titles = [];
        if (list) {
            for (var i = 0; i < list.options.length; i++) {
                var o = list.options[i];
                titles.push({ title: o.value, type: o.getAttribute('data-type'),
                              category: o.getAttribute('data-category') });
            }
        }
        return window.smsLearnedCategory(r.note, r.type, titles);
    }

    function smsPostTx(r, walletId) {
        var fd = new FormData();
        fd.set('csrf_token', csrf());
        fd.set('type', r.type);
        fd.set('amount', String(r.amount));
        fd.set('title', r.note || ((r.type === 'income' ? 'واریز' : 'برداشت') + ' — از پیامک بانک'));
        fd.set('note', '');
        var dEl = document.getElementById('transaction_date');
        fd.set('transaction_date', r.date || (dEl ? dEl.defaultValue : ''));
        var lc = smsCategoryFor(r);
        fd.set('category_id', lc ? String(lc) : '');
        fd.set('wallet_id', String(walletId));
        return fetch(apiUrl('add_transaction.php'), {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (j) { return j && j.success ? (j.id || -1) : 0; });
    }

    // ⛔ «مانده»ی پیامک موجودیِ حساب را **برابر** می‌کند (حالتِ «موجودی
    //    واقعی این‌قدر است» در تعدیل)، پس تراکنشی که ثبت نشده بود یا
    //    موجودیِ اولیه‌ای که درست وارد نشده بود، خودش جبران می‌شود.
    // ⛔ فقط از پیامکی که **خودکار** ثبت شد و حسابش با شماره‌ی کارت/حساب
    //    یا نامِ بانک پیدا شد — نه با «کاربر فقط یک حساب دارد»: کیف پولِ
    //    نقدی نباید موجودیِ بانک را بگیرد. و هرگز از پیامکی **قدیمی‌تر**
    //    از آخرین مانده‌ای که روی همان حساب نشسته (نگهبانِ تاریخ).
    var SMS_BAL_KEY = 'daftar_sms_bal_at';
    function smsBalanceNewer(walletId, iso) {
        try {
            var m = JSON.parse(localStorage.getItem(SMS_BAL_KEY) || '{}') || {};
            return !m[walletId] || String(iso) >= String(m[walletId]);
        } catch (e) { return true; }
    }
    function smsPostBalance(walletId, balance, iso) {
        if (!smsBalanceNewer(walletId, iso)) { return Promise.resolve(false); }
        var fd = new FormData();
        fd.set('csrf_token', csrf());
        fd.set('wallet_id', String(walletId));
        fd.set('mode', 'set');
        fd.set('amount', String(Math.abs(balance)));
        if (balance < 0) { fd.set('negative', '1'); }
        return fetch(apiUrl('adjust_wallet.php'), {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (j) {
            if (!j || !j.success) { return false; }
            try {
                var m = JSON.parse(localStorage.getItem(SMS_BAL_KEY) || '{}') || {};
                m[walletId] = iso;
                localStorage.setItem(SMS_BAL_KEY, JSON.stringify(m));
            } catch (e) { /* بی‌نگهبان: پیامکِ بعدی باز هم مانده را می‌گذارد */ }
            return true;
        })
        .catch(function () { return false; });
    }

    /**
     * ⛔ حسابی که کاربر برای یک پیامک انتخاب کرد → یاد گرفته می‌شود
     *    (`SmsSync::learn()`) و «مانده»ی همان پیامک روی آن حساب می‌نشیند —
     *    کاربر خودش گفت این پیامک مالِ این حساب است، پس مانده هم مالِ اوست.
     *    نوار می‌گوید از این به بعد چه می‌شود.
     */
    function smsLearnWallet(walletId, learn) {
        var fd = new FormData();
        fd.set('csrf_token', csrf());
        fd.set('wallet_id', String(walletId));
        fd.set('keys', (learn.keys || []).join(','));
        return fetch(apiUrl('sms_learn.php'), {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (j) {
            if (!j || !j.success) { return false; }
            var sel = document.getElementById('wallet_select');
            var opt = sel && sel.options[sel.selectedIndex];
            var name = opt ? opt.textContent.trim() : '';
            queueUndoBar('از این به بعد پیامک‌های همین بانک خودکار'
                + (name ? ' در «' + name + '»' : ' در همین حساب') + ' ثبت می‌شوند.', null);
            if (learn.b !== null && learn.b !== undefined) {
                return smsPostBalance(walletId, learn.b, learn.d);
            }
            return true;
        })
        .catch(function () { return false; });
    }

    // ---------- نوارِ «انجام شد — لغو» ----------
    //
    // ⛔ **یک نوار برای همه**: هم ثبتِ خودکار از پیامک، هم هر حذفی که
    //    برگشت‌پذیر است. دو پیاده‌سازیِ جدا یعنی دو رفتار که دیر یا زود
    //    از هم دور می‌افتند — همان دلیلی که `includes/transactions.php`
    //    ساخته شد.
    //
    // ⛔ چرا بعد از **تازه‌سازی** نشان داده می‌شود و نه درجا: جمع‌ها و
    //    موجودیِ بالای صفحه از سمتِ سرور رندر شده‌اند، پس بدونِ
    //    تازه‌سازی کاربر «حذف شد» می‌بیند ولی عددها عوض نشده‌اند —
    //    همان قاعده‌ی «هر تغییر پول باید بلافاصله دیده شود». نشانه در
    //    `sessionStorage` می‌ماند و صفحه‌ی تازه نوار را می‌سازد.
    var UNDO_KEY = 'daftar_undo_last';

    /**
     * نوار را برای **بارگذاریِ بعدی** صف می‌کند. صداکننده بلافاصله بعدش
     * صفحه را تازه می‌کند.
     *
     * @param {string} text  چه اتفاقی افتاد
     * @param {object} undo  {ep, field, value} — اندپوینتِ برگشت و بارش
     */
    function queueUndoBar(text, undo) {
        try {
            sessionStorage.setItem(UNDO_KEY, JSON.stringify({
                text: text, undo: undo || null, at: Date.now()
            }));
        } catch (e) { /* ناشناس یا پر — نوار نمی‌آید ولی کار انجام شده */ }
    }
    window.queueUndoBar = queueUndoBar;

    (function () {
        var raw = null;
        try { raw = sessionStorage.getItem(UNDO_KEY); } catch (e) { return; }
        if (!raw) { return; }
        try { sessionStorage.removeItem(UNDO_KEY); } catch (e) { /* بی‌اهمیت */ }

        var d;
        try { d = JSON.parse(raw); } catch (e) { return; }
        // ⚠ کهنه‌اش نشان داده نمی‌شود: sessionStorage تا بسته شدنِ تب
        //   می‌ماند و بدونِ این شرط، کاربری که نیم‌ساعت بعد صفحه‌ای را
        //   باز می‌کند نوارِ یک کارِ فراموش‌شده را می‌دید.
        if (!d || !d.at || (Date.now() - d.at) > 120000) { return; }

        var host = document.querySelector('.page-content') || document.body;
        if (!host) { return; }

        var bar = document.createElement('div');
        bar.className = 'undo-bar';

        var txt = document.createElement('span');
        txt.className = 'undo-bar-text';
        txt.textContent = d.text || 'انجام شد.';
        bar.appendChild(txt);

        // ⚠ توکنِ CSRF از `csrf()` (متا، یا هر فرمی که روی صفحه هست؛ شیتِ ثبت تراکنش در
        //   فوترِ **هر** صفحه است)، پس تقریباً همیشه پیدا می‌شود. اگر
        //   نبود، دکمه‌ی «لغو» اصلاً رندر نمی‌شود — دکمه‌ی بی‌کار از
        //   نبودنش بدتر است.
        var token = csrf();
        if (d.undo && d.undo.ep && token) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'undo-bar-undo';
            btn.textContent = 'لغو';
            btn.addEventListener('click', function () {
                btn.disabled = true;
                // ⚠ `value` می‌تواند آرایه باشد: چند تراکنشی که از صندوقِ
                //   پیامک با هم ثبت شده‌اند با **یک** «لغو» برمی‌گردند.
                //   پشتِ سرِ هم، نه موازی — شکستِ وسطِ کار باید همان‌جا
                //   دیده شود، نه اینکه نیمی برگردد و پیام «انجام شد» بدهد.
                var values = Array.isArray(d.undo.value) ? d.undo.value : [d.undo.value];
                var one = function (v) {
                    var fd = new FormData();
                    fd.set('csrf_token', token);
                    fd.set(d.undo.field, v);
                    return fetch(apiUrl(d.undo.ep), {
                        method: 'POST', body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(function (res) { return res.json(); });
                };
                values.reduce(function (p, v) {
                    return p.then(function (j) { return (j && !j.success) ? j : one(v); });
                }, Promise.resolve(null))
                .then(function (j) {
                    if (j && j.success) { window.reloadPage(); return; }
                    btn.disabled = false;
                    txt.textContent = (j && j.message) || 'لغو انجام نشد.';
                })
                .catch(function () {
                    btn.disabled = false;
                    txt.textContent = 'خطا در ارتباط با سرور.';
                });
            });
            bar.appendChild(btn);
        }

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'undo-bar-close';
        close.setAttribute('aria-label', 'بستن');
        close.textContent = '×';
        close.addEventListener('click', function () { bar.remove(); });
        bar.appendChild(close);

        host.insertBefore(bar, host.firstChild);
    })();

    // ---------- کارتِ «ثبت خودکار از پیامک» فقط داخلِ اپ اندروید ----------
    //
    // ⚠ تشخیص از روی `document.referrer` یا user-agent نیست (هر دو
    //   دست‌کاری‌شدنی و شکننده‌اند) بلکه از روی همان چیزی است که TWA
    //   واقعاً می‌سازد: اپِ نصب‌شده در حالتِ standalone باز می‌شود و
    //   اندروید است. در مرورگرِ معمولیِ اندروید کارت دیده نمی‌شود، چون
    //   آنجا آن لینک هیچ کاری نمی‌کند.
    (function () {
        var card = document.getElementById('smsCaptureCard');
        if (!card) { return; }
        var standalone = window.matchMedia
            && window.matchMedia('(display-mode: standalone)').matches;
        var android = /Android/i.test(navigator.userAgent || '');
        if (standalone && android) { card.hidden = false; }

        var auto = document.getElementById('smsAutoToggle');
        if (!auto) { return; }
        auto.checked = smsAutoEnabled();
        auto.addEventListener('change', function () {
            try {
                // ⚠ روشن = پاک کردنِ کلید (پیش‌فرض روشن است)؛ خاموش = `'0'`.
                if (auto.checked) { localStorage.removeItem(SMS_AUTO_KEY); }
                else { localStorage.setItem(SMS_AUTO_KEY, '0'); }
            } catch (e) { /* حالت ناشناس یا داده‌ی سایت بسته */ }
        });
    })();

    // ---------- «از پیامک بانک» و پیشنهادِ عنوان‌های قبلی ----------
    (function () {
        var box = document.getElementById('smsPasteBox');
        var btn = document.getElementById('smsPasteBtn');
        if (!box || !btn) { return; }

        var ta   = document.getElementById('smsPasteText');
        var go   = document.getElementById('smsPasteApply');
        var msg  = document.getElementById('smsPasteMsg');
        var amountEl = document.getElementById('amount');
        var titleEl  = document.getElementById('title');
        var walletEl = document.getElementById('wallet_select');
        var dateEl   = document.getElementById('transaction_date');

        btn.addEventListener('click', function () {
            var open = box.classList.toggle('open');
            btn.classList.toggle('active', open);
            if (open) { ta.focus(); }
        });

        function setAmount(v) {
            if (!amountEl) { return; }
            amountEl.value = fmtMoneyJs(v);
        }

        function setDate(iso) {
            if (!dateEl || !window.JalaliDatePicker) { return false; }
            var p = iso.split('-').map(Number);
            var j = window.JalaliDatePicker.gregorianToJalali(p[0], p[1], p[2]);
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            dateEl.value = iso;
            var field = dateEl.closest('.jdp-field');
            var disp  = field && field.querySelector('.jdp-display');
            if (disp) {
                disp.value = window.JalaliDatePicker.toFa(j[0]) + '/'
                           + window.JalaliDatePicker.toFa(pad(j[1])) + '/'
                           + window.JalaliDatePicker.toFa(pad(j[2]));
            }
            return true;
        }

        // ---- «چسباندن و خواندن»: یک تپ به‌جای نگه‌داشتن و چسباندن ----
        //
        // ⛔ خواندنِ خودکارِ خودِ پیامک ممکن نیست — نه PWA و نه TWA به
        //    SMS دسترسی دارند. این نزدیک‌ترین چیز به آن است.
        //
        // ⚠ دکمه فقط وقتی نشان داده می‌شود که API واقعاً باشد **و**
        //   بافتِ صفحه امن باشد (`isSecureContext`): روی http دکمه
        //   می‌آمد و با زدنش هیچ اتفاقی نمی‌افتاد.
        var readBtn = document.getElementById('smsPasteRead');
        if (readBtn && window.isSecureContext
            && navigator.clipboard && navigator.clipboard.readText) {
            readBtn.hidden = false;
            readBtn.addEventListener('click', function () {
                navigator.clipboard.readText().then(function (txt) {
                    if (!txt || !txt.trim()) {
                        msg.classList.remove('ok');
                        msg.classList.add('warn');
                        msg.textContent = 'چیزی در کلیپ‌بورد نبود. اول پیامک را کپی کنید.';
                        return;
                    }
                    ta.value = txt;
                    go.click();
                }).catch(function () {
                    // اجازه داده نشد — راهِ دستی سرِ جایش است.
                    msg.classList.remove('ok');
                    msg.classList.add('warn');
                    msg.textContent = 'اجازه‌ی خواندن کلیپ‌بورد داده نشد؛ پیامک را دستی بچسبانید.';
                });
            });
        }

        // ---- پیامک‌هایی که اپ اندروید خودش آورده ----
        //
        // ⛔ **هیچ اندپوینتِ تازه‌ای در کار نیست و متن روی سیم نمی‌رود.**
        //    اپ اندروید متن را در **فرگمنتِ** آدرس می‌گذارد (`#sms=…` یا
        //    `#smsq=…`) و مرورگر فرگمنت را هرگز به سرور نمی‌فرستد. تنها
        //    چیزی که به سرور می‌رسد همان فیلدهای یک تراکنش است.
        //
        // ⛔ **و هیچ چیزی باز نمی‌شود.** گزارشِ مالکِ نصب: «خواندن اس ام اس
        //    معضل شده، یکسره روی صفحه‌ست، مخصوصاً وقتی بخش حساب‌ها می‌رم و
        //    هیچ کاری نمیشه کرد». صفِ قبلی برای هر پیامکِ نامطمئن شیتِ ثبت
        //    را باز می‌کرد و تا صف خالی نمی‌شد، **هر** صفحه همان‌جا گیر
        //    بود. حالا مثلِ اپ‌های حرفه‌ای:
        //      • پیامکی که خواندنش قطعی است (`smsAutoOk`) بی‌صدا در حسابِ
        //        خودش ثبت می‌شود و مانده‌ی حساب با «مانده»ی پیامک برابر
        //        می‌شود؛ یک نوارِ کوچک با «لغو» می‌گوید چه شد.
        //      • پیامکِ نامطمئن فقط در فهرستِ «برای بررسی» می‌نشیند و کارتِ
        //        کوچکش روی **خانه** است — کاربر هر وقت خواست.
        //      • پیامکی که اصلاً تراکنش نیست (کدِ تأیید، تبلیغ) بی‌صدا کنار
        //        می‌رود.
        function openWithSms(raw) {
            // شیتِ ثبت تراکنش را باز کن — از همان کلاسِ مشترک، نه یک
            // شناسه: `.js-add-tx` هم روی نوارِ پایین است هم نوارِ کناری.
            var opener = document.querySelector('.js-add-tx');
            if (opener) { opener.click(); }

            // ⚠ کلاسِ باز شدن `open` است، نه `is-open`. نسخه‌ی اول
            //   `is-open` نوشته بود (کلاسِ بخش‌های دیگرِ همین فایل) و
            //   خرابی‌اش بی‌صدا بود: متن پر و پارس می‌شد ولی جعبه بسته
            //   می‌ماند، پس کاربر پیامِ «مبلغ پر شد» را اصلاً نمی‌دید.
            box.classList.add('open');
            btn.classList.add('active');

            ta.value = raw;
            go.click();
        }

        var fmtToman = function (n) {
            return fmtMoneyJs(Math.abs(Number(n) || 0)) + ' تومان';
        };
        function smsSummary(r) {
            var s = (r.type === 'income' ? 'واریز ' : 'برداشت ') + fmtToman(r.amount);
            var api = window.JalaliDatePicker;
            if (r.date && api && api.gregorianToJalali) {
                var g = r.date.split('-').map(Number);
                var j = api.gregorianToJalali(g[0], g[1], g[2]);
                s += ' · ' + toPersianDigitsJs(j[0] + '/' + (j[1] < 10 ? '0' : '') + j[1]
                                              + '/' + (j[2] < 10 ? '0' : '') + j[2]);
            }
            return s;
        }

        /**
         * کارتِ «پیامکِ بانکی برای بررسی» — یکی‌یکی، با «ثبت» و «رد».
         * جایش خانه است (`#smsPendingSlot`)؛ در صفحه‌های دیگر فقط وقتی
         * همین حالا پیامکی رسیده یک بار بالای صفحه می‌آید (`flash`) و با
         * رفتن به صفحه‌ی بعد دیگر تکرار نمی‌شود.
         */
        function renderSmsPending(flash) {
            var slot = document.getElementById('smsPendingSlot');
            var host = slot;
            if (!host) {
                if (!flash) { return; }
                var pc = document.querySelector('.page-content');
                if (!pc) { return; }
                host = document.getElementById('smsPendingFlash') || document.createElement('div');
                host.id = 'smsPendingFlash';
                if (!host.parentNode) { pc.insertBefore(host, pc.firstChild); }
            }
            var list = smsPendingRead();
            host.innerHTML = '';
            if (!list.length) { host.hidden = true; return; }
            host.hidden = false;

            var first = list[0];
            var r = window.parseBankSms(first.raw);
            var card = document.createElement('div');
            card.className = 'sms-review';

            var head = document.createElement('div');
            head.className = 'sms-review-head';
            head.textContent = 'پیامکِ بانکی برای بررسی'
                + (list.length > 1 ? ' (' + toPersianDigitsJs(String(list.length)) + ' مورد)' : '');
            card.appendChild(head);

            var body = document.createElement('div');
            body.className = 'sms-review-body';
            body.textContent = r.ok ? smsSummary(r) : 'پیامک خوانده نشد';
            card.appendChild(body);

            var acts = document.createElement('div');
            acts.className = 'sms-review-actions';
            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'btn btn-primary btn-sm';
            ok.textContent = 'بررسی و ثبت';
            ok.addEventListener('click', function () { openWithSms(first.raw); });
            var no = document.createElement('button');
            no.type = 'button';
            no.className = 'btn btn-secondary btn-sm';
            no.textContent = 'رد';
            no.addEventListener('click', function () {
                smsPendingDrop(first.fp);
                renderSmsPending(flash);
            });
            acts.appendChild(ok);
            acts.appendChild(no);
            if (!slot) {
                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'undo-bar-close';
                x.setAttribute('aria-label', 'بستن');
                x.textContent = '×';
                x.addEventListener('click', function () { host.hidden = true; });
                acts.appendChild(x);
            }
            card.appendChild(acts);
            host.appendChild(card);
        }

        /**
         * همه‌ی پیامک‌های یک نوبت، پشتِ سرِ هم و **به ترتیبِ زمان** (اپ
         * اندروید صندوق را `date ASC` می‌آورد) — تا مانده‌ی آخر همان
         * مانده‌ی تازه‌ترین پیامک باشد.
         */
        var smsBusy = false;
        // ⛔ نوبتی که وسطِ نوبتِ قبلی می‌رسد (تپ روی اعلانِ دوم وقتی اولی هنوز
        //    در حالِ ثبت است → `hashchange`) **گم نمی‌شود**: فرگمنتش همان لحظه
        //    پاک شده (`takeSmsFragment`)، پس جای دیگری برای برگشتن ندارد. در صف
        //    می‌ماند و پیش از تازه‌سازی، در همان نوبت ثبت می‌شود.
        var smsQueued = [];
        function smsProcessBatch(items) {
            if (smsBusy) { Array.prototype.push.apply(smsQueued, items); return; }
            smsBusy = true;
            var wallets = window.SMS_WALLETS || [];
            var autoOn = smsAutoEnabled();
            var dEl = document.getElementById('transaction_date');
            var today = dEl ? dEl.defaultValue : '';
            var saved = [], pending = 0, dup = 0, seen = 0;

            function round(list) {
                var bal = {};
                seen += list.length;
                var seq = Promise.resolve();
                list.forEach(function (raw) {
                    seq = seq.then(function () {
                        var fp = window.smsFingerprint(raw);
                        if (smsAlreadyAuto(fp)) { dup++; return; }
                        var r = window.parseBankSms(raw);
                        if (!r.ok) { return; }          // کدِ تأیید و تبلیغ: بی‌صدا
                        var m = window.smsMatchWallet(raw, r, wallets);
                        if (!autoOn || !window.smsAutoOk(r, !!m.id).ok) {
                            if (smsPendingAdd(raw, fp)) { pending++; }
                            return;
                        }
                        return smsPostTx(r, m.id).then(function (id) {
                            if (!id) { if (smsPendingAdd(raw, fp)) { pending++; } return; }
                            smsMarkAuto(fp);
                            saved.push({ id: id, type: r.type, amount: r.amount });
                            if (r.balance !== null && r.balanceKnown
                                && window.SMS_BALANCE_HOW.indexOf(m.how) !== -1) {
                                bal[m.id] = { b: r.balance, d: r.date || today };
                            }
                        }).catch(function () {
                            if (smsPendingAdd(raw, fp)) { pending++; }
                        });
                    });
                });
                return seq.then(function () {
                    return Promise.all(Object.keys(bal).map(function (w) {
                        return smsPostBalance(w, bal[w].b, bal[w].d);
                    }));
                }).then(function () {
                    return smsQueued.length ? round(smsQueued.splice(0)) : null;
                });
            }

            round(items).then(function () {
                smsBusy = false;
                if (saved.length) {
                    var ids = saved.map(function (x) { return x.id; }).filter(function (i) { return i > 0; });
                    var text = saved.length === 1
                        ? 'از پیامک بانک ثبت شد: ' + (saved[0].type === 'income' ? 'واریز ' : 'برداشت ')
                          + fmtToman(saved[0].amount)
                        : toPersianDigitsJs(String(saved.length)) + ' تراکنش از پیامک بانک ثبت شد';
                    if (pending) {
                        text += ' · ' + toPersianDigitsJs(String(pending)) + ' مورد برای بررسی روی خانه';
                    }
                    queueUndoBar(text, ids.length
                        ? { ep: 'delete_transaction.php', field: 'transaction_id', value: ids }
                        : null);
                    window.reloadPage();
                    return;
                }
                if (pending) { renderSmsPending(true); return; }
                if (dup && seen === 1) {
                    queueUndoBar('این پیامک قبلاً ثبت شده بود.', null);
                    window.reloadPage();
                }
            });
        }

        function takeSmsFragment() {
            var h = window.location.hash || '';
            if (h.indexOf('#sms=') !== 0 && h.indexOf('#smsq=') !== 0) { return; }

            var got = window.smsHashDecode(h);

            // ⚠ فرگمنت **بلافاصله** پاک می‌شود: با تازه‌سازیِ صفحه دوباره
            //   اجرا می‌شد، و تا آن موقع هم متنِ پیامک در نوارِ آدرس و
            //   تاریخچه‌ی مرورگر می‌ماند.
            try {
                history.replaceState(null, '', window.location.pathname + window.location.search);
            } catch (e) { window.location.hash = ''; }

            if (got.length) { smsProcessBatch(got); }
        }

        // ⛔ `setTimeout(0)` اختیاری نیست: این بلوک بالاتر از
        //    `go.addEventListener('click', …)` است و «بررسی و ثبت» از
        //    `go.click()` می‌رود — با یک تیک تأخیر همه‌ی شنونده‌ها ثبت
        //    شده‌اند. صفِ قدیمیِ `sessionStorage` (نسخه‌ی شیت‌به‌شیت) هم
        //    یک بار پاک می‌شود تا دیگر چیزی را باز نکند.
        setTimeout(function () {
            try { sessionStorage.removeItem('daftar_sms_queue'); } catch (e) { /* بی‌اهمیت */ }
            if (/^#smsq?=/.test(window.location.hash || '')) { takeSmsFragment(); }
            renderSmsPending(false);
        }, 0);

        // ⛔ `hashchange` هم لازم است: اگر اپ **همین حالا** روی همان صفحه
        //    باز باشد، تپ روی اعلان فقط فرگمنت را عوض می‌کند و صفحه دوباره
        //    بارگذاری نمی‌شود — بدونِ این، پیامک **بی‌صدا نادیده** می‌ماند.
        window.addEventListener('hashchange', takeSmsFragment);

        go.addEventListener('click', function () {
            var r = window.parseBankSms(ta.value);
            msg.classList.remove('ok', 'warn');

            if (!r.ok) {
                msg.classList.add('warn');
                msg.textContent = r.reason;
                return;
            }

            // ⚠ اثرِ انگشت روی فرم می‌نشیند تا ثبتِ **دستیِ** همین پیامک هم
            //   نگهبانِ تکراری را بزند؛ وگرنه «بررسی پیامک‌های قبلی» همان
            //   را دوباره پیشنهاد می‌داد.
            if (quickAddForm) { quickAddForm.dataset.smsFp = window.smsFingerprint(ta.value); }

            var done = [];
            setAmount(r.amount);
            done.push(r.currency === 'toman' ? 'مبلغ' : 'مبلغ (از ریال)');

            if (r.type && quickAddToggle) { quickAddToggle.setType(r.type); done.push('نوع'); }
            if (r.date && setDate(r.date)) { done.push('تاریخ'); }
            if (r.note && titleEl && !titleEl.value) { titleEl.value = r.note; done.push('عنوان'); }
            // ⛔ دسته از انتخابِ قبلیِ کاربر برای همین پذیرنده (`smsLearnedCategory`)
            //    — بعد از `setType`، که فهرستِ دسته‌ها را از نو می‌سازد.
            var learnedCat = smsCategoryFor(r);
            var catEl = document.getElementById('category_id');
            if (learnedCat && catEl) {
                catEl.value = String(learnedCat);
                if (catEl.value === String(learnedCat)) {
                    catEl.dispatchEvent(new Event('change', { bubbles: true }));
                    done.push('دسته');
                }
            }

            // ⚠ حساب فقط از `smsMatchWallet()` (کارت → حساب → نامِ بانک →
            //   تک‌حساب) — وگرنه پول در حسابِ اشتباه می‌نشست.
            var match = window.smsMatchWallet(ta.value, r, window.SMS_WALLETS || []);
            if (match.id && walletEl) {
                walletEl.value = String(match.id);
                if (match.how !== 'single') { done.push('حساب'); }
            }

            // ---- ثبتِ خودکار، اگر کلیدش روشن و خواندن قطعی باشد ----
            //
            // ⛔ فقط از مسیرِ خودِ فرم (`requestSubmit`)، نه یک fetch تازه:
            //    منطقِ ثبت — اعتبارسنجی، CSRF، پیام، تازه‌سازی — همان‌جاست.
            // ⚠ «مقصد قطعی است» یعنی `smsMatchWallet()` حسابی داد — از
            //   جمله «کاربر اصلاً یک حساب دارد»، که آنجا شیت `<select>`
            //   نمی‌سازد و `walletEl` تهی است.
            var walletCertain = !!match.id;

            if (smsAutoEnabled() && quickAddForm) {
                var verdict = window.smsAutoOk(r, walletCertain);
                var fp = window.smsFingerprint(ta.value);
                if (verdict.ok && smsAlreadyAuto(fp)) {
                    msg.classList.remove('ok');
                    msg.classList.add('warn');
                    msg.textContent = 'این پیامک همین اواخر ثبت شده — دوباره ثبت نشد.';
                    return;
                }
                if (verdict.ok) {
                    // ⛔ عنوان در مسیرِ دستی خالی می‌ماند و کاربر خودش
                    //    می‌نویسد، ولی اینجا کسی نیست که بنویسد — و
                    //    فیلدش `required` است. بدونِ این پیش‌فرض،
                    //    `requestSubmit()` روی اعتبارسنجیِ خودِ مرورگر
                    //    می‌ایستد و **هیچ اتفاقی نمی‌افتد**: نه ثبتی، نه
                    //    خطایی، نه پیامی. در مرورگر دیده شد.
                    if (titleEl && !titleEl.value) {
                        titleEl.value = (r.type === 'income' ? 'واریز' : 'برداشت')
                                      + ' — از پیامک بانک';
                    }

                    // ⛔ و محافظِ دوم: اگر باز هم فرم معتبر نبود، «خودکار»
                    //    ادعا نمی‌کنیم. سکوت اینجا بدترین حالت است، چون
                    //    کاربر فکر می‌کند ثبت شده و ماه بعد جای خالی‌اش
                    //    را می‌بیند.
                    if (quickAddForm.checkValidity && !quickAddForm.checkValidity()) {
                        msg.classList.remove('ok');
                        msg.classList.add('warn');
                        msg.textContent = 'خودکار ثبت نشد — فرم کامل نیست؛ خودتان ثبت کنید.';
                        quickAddForm.reportValidity();
                        return;
                    }

                    smsMarkAuto(fp);
                    // ⚠ نشانه روی خودِ فرم است، نه یک متغیرِ مشترک: شنونده‌ی
                    //   submit در بلوکِ دیگری است و با متغیر باید به ترتیبِ
                    //   تعریفشان در فایل وابسته می‌شد.
                    quickAddForm.dataset.autoSms = JSON.stringify({
                        type: r.type, amount: r.amount
                    });
                    // ⛔ مانده‌ی پیامک فقط وقتی حساب با شماره یا نامِ بانک
                    //    پیدا شده — همان شرطِ `smsProcessBatch()`.
                    if (r.balance !== null && r.balanceKnown
                        && window.SMS_BALANCE_HOW.indexOf(match.how) !== -1) {
                        var dEl0 = document.getElementById('transaction_date');
                        quickAddForm.dataset.smsBal = JSON.stringify({
                            w: match.id, b: r.balance,
                            d: r.date || (dEl0 ? dEl0.defaultValue : '')
                        });
                    }
                    msg.classList.add('ok');
                    msg.textContent = 'خوانده شد — در حال ثبتِ خودکار…';
                    if (quickAddForm.requestSubmit) { quickAddForm.requestSubmit(); }
                    else { quickAddForm.dispatchEvent(new Event('submit', { cancelable: true })); }
                    return;
                }
                // ⚠ چرا خودکار نشد **گفته می‌شود**. سکوت اینجا یعنی کاربر
                //   کلید را روشن کرده و گاهی کار می‌کند و گاهی نه، بی‌آنکه
                //   بفهمد چرا — و آن‌وقت نتیجه می‌گیرد کلید خراب است.
                if (match.id || !walletEl) { done.push('خودکار نشد (' + verdict.why + ')'); }
            }

            // ⛔ «یک بار بپرس، بعد خودکار» (گزارشِ مالکِ نصب: «مگه نباید بپرسه
            //    از چه حسابی برداشت شده و از دفعه بعد خودش وارد اون حساب کنه؟»).
            //    حسابی که کاربر حالا انتخاب و ثبت می‌کند، با نشانه‌های همین
            //    پیامک (`smsSourceKeys()`) یاد گرفته می‌شود — شنونده‌ی submit
            //    بعد از ثبتِ موفق `api/sms_learn.php` را می‌زند و مانده‌ی پیامک
            //    را روی همان حساب می‌گذارد. فقط نشانه‌ها می‌روند، نه متن.
            var learnKeys = window.smsSourceKeys(ta.value, r);
            if (quickAddForm && learnKeys.length) {
                var dEl1 = document.getElementById('transaction_date');
                quickAddForm.dataset.smsLearn = JSON.stringify({
                    keys: learnKeys,
                    b: r.balanceKnown && r.balance !== null ? r.balance : null,
                    d: r.date || (dEl1 ? dEl1.defaultValue : '')
                });
            }
            // ⛔ و حساب **واقعاً پرسیده می‌شود**: `<select>` پیش‌فرض اولین حساب را
            //    دارد، پس بی‌این گزینه‌ی خالیِ اجباری، کاربری که فقط «ثبت» را زد
            //    پیامکِ بلو را برای همیشه به حسابِ اول یاد می‌داد — و مانده‌ی
            //    بانک را روی آن می‌نشاند.
            var askWallet = !match.id && !!walletEl;
            if (askWallet) {
                var ph = walletEl.querySelector('option[value=""]');
                if (!ph) {
                    ph = document.createElement('option');
                    ph.value = '';
                    ph.textContent = '— این پیامک مالِ کدام حساب است؟ —';
                    walletEl.insertBefore(ph, walletEl.firstChild);
                }
                walletEl.value = '';
                walletEl.required = true;
                walletEl.focus();
                msg.classList.add('warn');
                msg.textContent = 'این پیامک مالِ کدام حساب است؟ «از/به حساب» را انتخاب و ثبت کنید — '
                    + 'از این به بعد پیامک‌های همین بانک خودکار در همان حساب ثبت می‌شوند. ('
                    + done.join('، ') + ' پر شد.)';
                return;
            }

            // ⚠ وقتی واحد در متن نبود و ریال **فرض** شده، پیام زرد است
            //   نه سبز: مبلغ محتمل‌ترین حالت را دارد ولی کاربر باید یک
            //   نگاه بیندازد. سبزِ الکی یعنی هیچ‌وقت نگاه نمی‌کند.
            var assumed = r.currency === 'rial_assumed';
            msg.classList.add(assumed ? 'warn' : 'ok');
            msg.textContent = (assumed
                ? done.join('، ') + ' پر شد — واحد در پیامک نبود و ریال فرض شد؛ مبلغ را ببینید.'
                : done.join('، ') + ' پر شد.');

            if (titleEl && !titleEl.value) { titleEl.focus(); }
        });

        // ---- انتخابِ یک عنوانِ قبلی، بقیه‌ی فرم را هم پر می‌کند ----
        var list = document.getElementById('recentTitles');
        if (list && titleEl) {
            titleEl.addEventListener('input', function () {
                var opt = null, opts = list.options;
                for (var i = 0; i < opts.length; i++) {
                    if (opts[i].value === titleEl.value) { opt = opts[i]; break; }
                }
                if (!opt) { return; }

                var t = opt.getAttribute('data-type');
                if (t && quickAddToggle) { quickAddToggle.setType(t); }

                // ⚠ دسته بعد از setType پر می‌شود: عوض کردنِ نوع فهرستِ
                //   دسته‌ها را از نو می‌سازد و انتخابِ قبلی را می‌برد.
                var cat = document.getElementById('category_id');
                var cid = opt.getAttribute('data-category');
                if (cat && cid && cid !== '0') {
                    cat.value = cid;
                    // ⚠ نوشتنِ مستقیمِ `value` رویدادِ `change` نمی‌دهد، پس
                    //   چیپِ شبکه بی‌صدا از انتخاب عقب می‌ماند: منو یک
                    //   دسته را نشان می‌داد و هیچ چیپی روشن نبود.
                    cat.dispatchEvent(new Event('change', { bubbles: true }));
                }

                var wid = opt.getAttribute('data-wallet');
                if (walletEl && wid && wid !== '0') { walletEl.value = wid; }

                // مبلغ فقط وقتی که کاربر هنوز چیزی ننوشته — نوشته‌ی خودش
                // همیشه برنده است.
                var amt = opt.getAttribute('data-amount');
                if (amountEl && !amountEl.value && amt && amt !== '0') { setAmount(amt); }
            });
        }
    })();


    /**
     * حذفِ برگشت‌پذیر: بدونِ «مطمئنید؟»، با نوارِ «لغو» بعدِ تازه‌سازی.
     *
     * ⛔ مودالِ تأیید هزینه‌اش را از **همه** می‌گیرد تا اشتباهِ گاه‌به‌گاهِ
     *    یک نفر را بگیرد — و کاربری که صد بار «بله» زده، بارِ صد و یکم
     *    هم بی‌خواندن می‌زند. یعنی مودال دقیقاً وقتی لازم است کار
     *    نمی‌کند.
     *
     * ⚠ و اگر سرور توکنِ برگشت نداد (عکس گرفتن ممکن نشد)، همان
     *   `confirm()` قدیمی می‌آید — **پیش از** حذف. «لغوِ ناموجود» از
     *   تأیید بدتر است: کاربر روی وعده‌ای حساب می‌کند که وجود ندارد.
     *   به همین دلیل وقتی مطمئن نیستیم، اول می‌پرسیم.
     */
    function deleteWithUndo(opts) {
        var fd = new FormData();
        fd.set('csrf_token', opts.csrf || '');
        fd.set(opts.field, opts.value);
        if (opts.extra) {
            Object.keys(opts.extra).forEach(function (k) { fd.set(k, opts.extra[k]); });
        }
        return fetch(apiUrl(opts.ep), {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.success) { alert(j.message || 'حذف انجام نشد.'); return; }
            if (j.undo_token) {
                queueUndoBar(opts.text || 'حذف شد.',
                    { ep: 'undo_delete.php', field: 'undo_token', value: j.undo_token });
            }
            window.reloadPage();
        })
        .catch(function () { alert(netErr()); });
    }

    // ---------- حذف تراکنش (AJAX) — در صفحه اصلی و صفحه تراکنش‌ها ----------
    // ⚠ واگذارشده به `document`، نه بسته به هر دکمه: ردیف‌های «جزئیاتِ روز»ِ
    //   تقویمِ خانه بعد از بارگذاری می‌رسند و دکمه‌ی بسته‌شده‌ی قبلی آنجا
    //   بی‌صدا هیچ کاری نمی‌کرد.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-delete-tx');
        if (!btn) return;
        (function () {
            var txId = this.getAttribute('data-id');

            // ⛔ بدونِ «مطمئنید؟». پیامِ قدیمی می‌گفت «قابل بازگشت
            //    نیست» — حالا هست، و همین جمله بود که تأیید را لازم
            //    می‌کرد.
            deleteWithUndo({
                ep: 'delete_transaction.php', field: 'transaction_id',
                value: txId, csrf: csrf(), text: 'تراکنش حذف شد.'
            });
        }).call(btn);
    });

    // ---------- سودِ فروشگاهِ اصلاح‌شده: برگرداندن به عددِ فروشگاه ----------
    // ⚠ واگذارشده به `document`، به همان دلیلِ حذف (ردیف‌های جزئیاتِ روز).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-store-revert');
        if (!btn) return;
        var fd = new FormData();
        fd.set('csrf_token', csrf());
        fd.set('transaction_id', btn.getAttribute('data-id'));
        btn.disabled = true;
        fetch(apiUrl('store_share_revert.php'), {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.success) { alert(j.message || 'برگرداندن انجام نشد.'); btn.disabled = false; return; }
            window.reloadPage();
        })
        .catch(function () { alert(netErr()); btn.disabled = false; });
    });

    // ---------- ویرایش تراکنش: پیش‌پر کردن مودال ----------
    var monthNamesFa = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-edit-tx');
        if (!btn) return;
        (function () {
            if (!editToggle || !window.JalaliDatePicker) return;

            var id = this.getAttribute('data-id');
            var type = this.getAttribute('data-type');
            var amount = this.getAttribute('data-amount');
            var title = this.getAttribute('data-title');
            var note = this.getAttribute('data-note');
            var date = this.getAttribute('data-date');
            var categoryId = this.getAttribute('data-category-id');

            document.getElementById('edit_transaction_id').value = id;
            document.getElementById('edit_amount').value = amount ? fmtMoneyJs(amount) : '';
            document.getElementById('edit_title').value = title || '';
            document.getElementById('edit_note').value = note || '';

            editToggle.setType(type);
            document.getElementById('edit_category_id').value = (categoryId && categoryId !== '0') ? categoryId : '';

            var parts = date.split('-').map(Number);
            var j = window.JalaliDatePicker.gregorianToJalali(parts[0], parts[1], parts[2]);
            document.getElementById('edit_date_display').value =
                window.JalaliDatePicker.toFa(j[2]) + ' ' + monthNamesFa[j[1] - 1] + ' ' + window.JalaliDatePicker.toFa(j[0]);
            document.getElementById('edit_transaction_date').value = date;

            var msgEl = document.getElementById('editTxMessage');
            if (msgEl) { msgEl.hidden = true; msgEl.classList.remove('show', 'success', 'error'); }
            var storeHint = document.getElementById('editTxStoreHint');
            if (storeHint) { storeHint.hidden = this.getAttribute('data-store') !== '1'; }

            openModal('editTxModal');
        }).call(btn);
    });

    var editTxForm = document.getElementById('editTxForm');
    if (editTxForm) {
        editTxForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var submitBtn = document.getElementById('editTxSubmitBtn');
            var msgEl = document.getElementById('editTxMessage');
            var amountInput = document.getElementById('edit_amount');

            var formData = new FormData(editTxForm);
            var rawAmount = amountInput.value.replace(/[,\u066C]/g, '');
            formData.set('amount', rawAmount);

            submitBtn.disabled = true;

            fetch(apiUrl('update_transaction.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                msgEl.hidden = false;
                msgEl.classList.remove('success', 'error');
                if (data.success) {
                    msgEl.classList.add('show', 'success');
                    msgEl.textContent = data.message || 'با موفقیت ذخیره شد.';
                    window.reloadPage();
                } else {
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = data.message || 'خطایی رخ داد.';
                }
            })
            .catch(function () {
                msgEl.hidden = false;
                msgEl.classList.add('show', 'error');
                msgEl.textContent = 'خطا در ارتباط با سرور.';
            })
            .finally(function () {
                submitBtn.disabled = false;
            });
        });
    }

    // ---------- افزودن طلب/بدهی ----------
    setupAmountFormatter('add_debt_amount');
    setupAmountFormatter('edit_debt_amount');

    var addDebtToggle = setupTypeToggle('addDebtToggle', 'add_debt_direction', null);

    function setJdpValue(displayId, hiddenId, gDateStr) {
        if (!window.JalaliDatePicker || !gDateStr) return;
        var parts = gDateStr.split('-').map(Number);
        var j = window.JalaliDatePicker.gregorianToJalali(parts[0], parts[1], parts[2]);
        function pad2(n) { return (n < 10 ? '0' : '') + n; }
        document.getElementById(displayId).value =
            window.JalaliDatePicker.toFa(j[0]) + '/' + window.JalaliDatePicker.toFa(pad2(j[1])) + '/' + window.JalaliDatePicker.toFa(pad2(j[2]));
        document.getElementById(hiddenId).value = gDateStr;
    }

    // ⛔ «جدید» باید تاریخِ پیش‌فرضِ خودِ صفحه را برگرداند، نه تاریخِ آخرین
    //    ویرایش. `setJdpValue(..., '')` عمداً هیچ کاری نمی‌کند، و
    //    `defaultValue`ِ ورودیِ پنهان به درد نمی‌خورد (نوشتن در `value`ِ
    //    `type=hidden` خودِ صفت را عوض می‌کند)؛ پس مقدارِ پنهان همین‌جا،
    //    هنگامِ بارگذاری، عکس گرفته می‌شود. نمایش `defaultValue`ِ خودش را دارد.
    var jdpInitial = {};
    document.querySelectorAll('.jdp-hidden[id]').forEach(function (el) { jdpInitial[el.id] = el.value; });
    function resetJdpValue(displayId, hiddenId) {
        var d = document.getElementById(displayId), h = document.getElementById(hiddenId);
        if (h) { h.value = jdpInitial[hiddenId] || ''; }
        if (d) { d.value = d.defaultValue; }
    }

    // ---------- «بدون سررسید» روی طلب/بدهی ----------
    // کلید مقدارِ پنهان را خالی می‌کند و تاریخ‌گزین را غیرفعال؛ سرور خالی
    // را `NULL` ذخیره می‌کند (`debtDueOptional()`).
    function setNoDue(hiddenId, on) {
        var box = document.querySelector('.js-no-due[data-target="' + hiddenId + '"]');
        var hidden = document.getElementById(hiddenId);
        if (!box || !hidden) return;
        box.checked = !!on;
        var display = hidden.closest('.jdp-field').querySelector('.jdp-display');
        if (on) { hidden.value = ''; display.value = ''; }
        display.disabled = !!on;
    }
    document.querySelectorAll('.js-no-due').forEach(function (box) {
        box.addEventListener('change', function () {
            setNoDue(this.getAttribute('data-target'), this.checked);
        });
    });

    document.querySelectorAll('.js-add-debt').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var direction = this.getAttribute('data-direction');
            if (addDebtToggle) addDebtToggle.setType(direction);

            document.getElementById('add_counterparty').value = '';
            document.getElementById('add_debt_amount').value = '';
            document.getElementById('add_debt_note').value = '';

            var dueHidden = document.getElementById('add_due_date');
            dueHidden.value = '';
            dueHidden.closest('.jdp-field').querySelector('.jdp-display').value = '';
            setNoDue('add_due_date', false);

            var msgEl = document.getElementById('addDebtMessage');
            if (msgEl) { msgEl.hidden = true; msgEl.classList.remove('show', 'success', 'error'); }

            openModal('addDebtModal');
        });
    });

    var addDebtForm = document.getElementById('addDebtForm');
    if (addDebtForm) {
        addDebtForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = document.getElementById('addDebtSubmitBtn');
            var msgEl = document.getElementById('addDebtMessage');
            var amountInput = document.getElementById('add_debt_amount');

            // ⛔ خالی فقط وقتی مجاز است که «بدون سررسید» صریح زده شده باشد؛
            //    فیلدِ خالیِ بی‌کلید معمولاً یعنی فراموشی، نه تصمیم.
            var noDueBox = addDebtForm.querySelector('.js-no-due');
            if (!document.getElementById('add_due_date').value && !(noDueBox && noDueBox.checked)) {
                msgEl.hidden = false;
                msgEl.classList.remove('success');
                msgEl.classList.add('show', 'error');
                msgEl.textContent = noDueBox
                    ? 'تاریخ سررسید را انتخاب کنید یا «بدون سررسید» را بزنید.'
                    : 'تاریخ سررسید را انتخاب کنید.';
                return;
            }

            var formData = new FormData(addDebtForm);
            formData.set('amount', amountInput.value.replace(/[,\u066C]/g, ''));

            submitBtn.disabled = true;

            fetch(apiUrl('add_debt.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                msgEl.hidden = false;
                msgEl.classList.remove('success', 'error');
                if (data.success) {
                    msgEl.classList.add('show', 'success');
                    msgEl.textContent = data.message || 'با موفقیت ثبت شد.';
                    window.reloadPage();
                } else {
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = data.message || 'خطایی رخ داد.';
                }
            })
            .catch(function () {
                msgEl.hidden = false;
                msgEl.classList.add('show', 'error');
                msgEl.textContent = 'خطا در ارتباط با سرور.';
            })
            .finally(function () {
                submitBtn.disabled = false;
            });
        });
    }

    // ---------- ویرایش طلب/بدهی ----------
    document.querySelectorAll('.js-edit-debt').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_debt_id').value = this.getAttribute('data-id');
            syncPersonPicker('edit_counterparty', this.getAttribute('data-counterparty'));
            document.getElementById('edit_debt_amount').value = fmtMoneyJs(this.getAttribute('data-amount'));
            document.getElementById('edit_debt_note').value = this.getAttribute('data-note') || '';

            setJdpValue('edit_entry_date_display', 'edit_entry_date', this.getAttribute('data-entry-date'));
            var editDue = this.getAttribute('data-due-date') || '';
            if (editDue) {
                setJdpValue('edit_due_date_display', 'edit_due_date', editDue);
            } else {
                document.getElementById('edit_due_date').value = '';
                document.getElementById('edit_due_date_display').value = '';
            }
            setNoDue('edit_due_date', editDue === '');

            var msgEl = document.getElementById('editDebtMessage');
            if (msgEl) { msgEl.hidden = true; msgEl.classList.remove('show', 'success', 'error'); }

            openModal('editDebtModal');
        });
    });

    var editDebtForm = document.getElementById('editDebtForm');
    if (editDebtForm) {
        editDebtForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = document.getElementById('editDebtSubmitBtn');
            var msgEl = document.getElementById('editDebtMessage');
            var amountInput = document.getElementById('edit_debt_amount');

            var formData = new FormData(editDebtForm);
            formData.set('amount', amountInput.value.replace(/[,\u066C]/g, ''));

            submitBtn.disabled = true;

            fetch(apiUrl('update_debt.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                msgEl.hidden = false;
                msgEl.classList.remove('success', 'error');
                if (data.success) {
                    msgEl.classList.add('show', 'success');
                    msgEl.textContent = data.message || 'با موفقیت ذخیره شد.';
                    window.reloadPage();
                } else {
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = data.message || 'خطایی رخ داد.';
                }
            })
            .catch(function () {
                msgEl.hidden = false;
                msgEl.classList.add('show', 'error');
                msgEl.textContent = 'خطا در ارتباط با سرور.';
            })
            .finally(function () {
                submitBtn.disabled = false;
            });
        });
    }

    /**
     * پرسیدن «به کدام حساب؟» پیش از تسویه‌ی یک چک یا طلب/بدهی.
     *
     * تیک بلافاصله برداشته می‌شود و فقط بعد از پاسخ موفق سرور (که صفحه
     * را تازه می‌کند) دوباره تیک می‌خورد. این‌طور اگر کاربر مودال را
     * ببندد، ظاهرِ صفحه چیزی را نشان نمی‌دهد که در دیتابیس ثبت نشده.
     */
    var settleAsk = {};

    function askSettleWallet(modalId, checkboxEl, opts) {
        var modal = document.getElementById(modalId);
        var form  = document.getElementById(modalId + 'Form');
        var msgEl = document.getElementById(modalId + 'Message');
        var btnEl = document.getElementById(modalId + 'SubmitBtn');
        var selEl = document.getElementById(modalId + 'Wallet');
        var hintEl = document.getElementById(modalId + 'Hint');
        if (!modal || !form) { return; }

        // تا وقتی سرور تأیید نکرده، تیک نمی‌ماند. بازکننده ممکن است
        // چک‌باکس نباشد (نشانِ وضعیتِ چک یک دکمه است).
        if (checkboxEl && checkboxEl.type === 'checkbox') { checkboxEl.checked = false; }

        settleAsk[modalId] = { id: checkboxEl.getAttribute('data-id'), opts: opts };

        if (hintEl) { hintEl.textContent = opts.hint || ''; }
        if (msgEl) { msgEl.hidden = true; msgEl.classList.remove('show', 'success', 'error'); }
        if (btnEl) { btnEl.disabled = false; }
        resetSelect(selEl);

        if (!form.getAttribute('data-wired')) {
            form.setAttribute('data-wired', '1');
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var pending = settleAsk[modalId];
                if (!pending) { return; }

                var fd = new FormData();
                fd.append(pending.opts.idField, pending.id);
                fd.append('wallet_id', selEl ? selEl.value : '');
                fd.append('csrf_token', csrf());
                // قلاب برای فرم‌هایی که فیلدِ بیشتری دارند (وضعیتِ چک).
                // مسیرِ طلب/بدهی چیزی نمی‌فرستد و دست‌نخورده می‌ماند.
                if (typeof pending.opts.extraFields === 'function') {
                    pending.opts.extraFields(fd);
                }
                if (btnEl) { btnEl.disabled = true; }

                var fail = function (text) {
                    if (msgEl) {
                        msgEl.hidden = false;
                        msgEl.classList.remove('success');
                        msgEl.classList.add('show', 'error');
                        msgEl.textContent = text;
                    }
                    if (btnEl) { btnEl.disabled = false; }
                };

                fetch(pending.opts.url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.success) { window.reloadPage(); return; }
                        fail(d.message || 'خطایی رخ داد.');
                    })
                    .catch(function () { fail('خطا در ارتباط با سرور.'); });
            });
        }

        modal.classList.add('show');
    }

    // ---------- تیک تسویه طلب/بدهی ----------
    // زدن تیک یعنی پول واقعاً جابه‌جا شده، پس اول می‌پرسیم از/به کدام
    // حساب. برداشتن تیک سؤالی ندارد — همان اثر پس گرفته می‌شود.
    var debtSettleModal = document.getElementById('debtSettle');
    document.querySelectorAll('.js-toggle-debt').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var checkboxEl = this;
            if (debtSettleModal && checkboxEl.checked) {
                askSettleWallet('debtSettle', checkboxEl, {
                    hint: 'باقیمانده‌ی این مورد به‌عنوان تسویه ثبت می‌شود.',
                    url: apiUrl('toggle_debt_settled.php'),
                    idField: 'debt_id'
                });
                return;
            }

            var debtId = this.getAttribute('data-id');

            var formData = new FormData();
            formData.append('debt_id', debtId);
            formData.append('csrf_token', csrf());

            checkboxEl.disabled = true;

            fetch(apiUrl('toggle_debt_settled.php'), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    window.reloadPage();
                } else {
                    alert(data.message || 'خطایی رخ داد.');
                    checkboxEl.checked = !checkboxEl.checked;
                    checkboxEl.disabled = false;
                }
            })
            .catch(function () {
                alert(netErr());
                checkboxEl.checked = !checkboxEl.checked;
                checkboxEl.disabled = false;
            });
        });
    });

    // ---------- حذف طلب/بدهی ----------
    document.querySelectorAll('.js-delete-debt').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var debtId = this.getAttribute('data-id');

            // ⚠ پرداخت‌های ثبت‌شده با CASCADE می‌روند و «لغو» آن‌ها را
            //   هم برمی‌گرداند.
            deleteWithUndo({ ep: 'delete_debt.php', field: 'debt_id',
                             value: debtId, csrf: csrf(),
                             text: 'طلب/بدهی با پرداخت‌هایش حذف شد.' });
        });
    });

    // ---------- گزارش دسته‌بندی: نمایش تراکنش‌های هر دسته با کلیک ----------
    document.querySelectorAll('.js-cat-toggle').forEach(function (el) {
        el.addEventListener('click', function () {
            var catId = this.getAttribute('data-cat-id');
            var detailEl = document.getElementById('catDetail' + catId);
            if (!detailEl) return;

            if (!detailEl.hidden) {
                detailEl.hidden = true;
                return;
            }

            if (detailEl.dataset.loaded === '1') {
                detailEl.hidden = false;
                return;
            }

            var typeEl = document.getElementById('reportType');
            var fromEl = document.getElementById('reportFromDate');
            var toEl = document.getElementById('reportToDate');
            if (!typeEl || !fromEl || !toEl) return;

            detailEl.innerHTML = window.skeletonHtml(3);
            detailEl.hidden = false;

            var url = apiUrl('category_transactions.php') + '?category_id=' + encodeURIComponent(catId)
                + '&type=' + encodeURIComponent(typeEl.value)
                + '&from_date=' + encodeURIComponent(fromEl.value)
                + '&to_date=' + encodeURIComponent(toEl.value);

            fetch(url)
                .then(function (res) { return res.text(); })
                .then(function (html) {
                    detailEl.innerHTML = html;
                    detailEl.dataset.loaded = '1';
                })
                .catch(function () {
                    detailEl.innerHTML = '<p style="text-align:center; color:var(--color-expense); padding:8px 0;">خطا در بارگذاری.</p>';
                });
        });
    });

    // ---------- شیت ثبت تراکنش ----------
    //
    // ⛔ به **کلاس** بسته است، نه به یک شناسه — و این اصلاحِ یک باگِ
    //    واقعی است: تنها راهِ باز کردنِ این شیت دکمه‌ی + داخلِ نوارِ
    //    پایین بود، و آن نوار بالای ۹۰۰ پیکسل `display:none` می‌شود.
    //    یعنی روی دسکتاپ **هیچ راهی** برای ثبت تراکنش وجود نداشت —
    //    هسته‌ی اپ، کاملاً از کار افتاده، بی‌هیچ خطایی. حالا دکمه‌ی
    //    نوارِ پایین (موبایل) و دکمه‌ی نوارِ بالا (دسکتاپ) هر دو همین
    //    کلاس را دارند، پس ورودیِ سومی که فردا اضافه شود هم بدون
    //    دست زدن به این فایل کار می‌کند.
    var addTxSheet = document.getElementById('addTxSheet');
    if (addTxSheet) {
        document.querySelectorAll('.js-add-tx').forEach(function (btn) {
            btn.addEventListener('click', function () {
                addTxSheet.classList.add('show');

                // ⛔ فوکوسِ **همزمان**، نه فقط داخلِ setTimeout.
                //    روی iOS کیبوردِ مجازی تنها وقتی بالا می‌آید که
                //    `focus()` در همان زنجیره‌ی رویدادِ لمسِ کاربر صدا
                //    زده شود؛ داخلِ `setTimeout` آن زنجیره بریده است،
                //    پس فیلد فوکوس می‌گرفت و کیبورد **نمی‌آمد** —
                //    یعنی کاربر باید یک تپِ دیگر هم روی خودِ فیلد
                //    می‌زد. عنصر همین حالا `display:flex` شده، پس
                //    فوکوس‌پذیر است (انیمیشنِ `sheetUp` فقط تصویری است).
                //    ⚠ این استدلال است نه اندازه‌گیری: اینجا iOS نداریم.
                //    آنچه سنجیده شد، فوکوس گرفتنِ فیلد در کرومیوم است.
                var amt = document.getElementById('amount');
                if (amt) {
                    amt.focus();
                    // و یک بار هم بعد از انیمیشن، برای موتوری که فوکوس
                    // روی عنصرِ تازه‌نمایان را نادیده می‌گیرد.
                    setTimeout(function () { amt.focus(); }, 120);
                }
            });
        });
    }

    // بستن شیت‌ها: کلیک روی پس‌زمینه، دکمه ضربدر، یا کلید Escape
    document.querySelectorAll('.sheet-overlay, .more-sheet-overlay').forEach(function (ov) {
        ov.addEventListener('click', function (e) {
            if (e.target === ov) ov.classList.remove('show');
        });
    });
    document.querySelectorAll('[data-sheet-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ov = btn.closest('.sheet-overlay, .more-sheet-overlay');
            if (ov) ov.classList.remove('show');
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.sheet-overlay.show, .more-sheet-overlay.show').forEach(function (ov) {
            ov.classList.remove('show');
        });
    });

    // ---------- حساب‌ها و انتقال ----------
    setupAmountFormatter('wallet_init');
    setupAmountFormatter('transfer_amount');
    setupAmountFormatter('transfer_fee');

    function walletKindFields() {
        var kind = document.getElementById('wallet_kind');
        var box  = document.getElementById('walletBankFields');
        if (!kind || !box) return;
        box.hidden = (kind.value === 'cash');

        // «سایر» باید اسم داشته باشد، وگرنه بعداً معلوم نیست چه حسابی بوده
        var labelWrap = document.getElementById('walletKindLabelWrap');
        if (labelWrap) {
            labelWrap.hidden = (kind.value !== 'other');
            if (kind.value === 'other') { walletKindLabelField(); }
        }
    }

    // انتخاب «+ نوع تازه…» جعبه‌ی متن را باز می‌کند
    function walletKindLabelField() {
        var sel = document.getElementById('wallet_kind_label');
        var txt = document.getElementById('wallet_kind_new');
        if (!sel || !txt) return;
        var isNew = (sel.value === '__new__' || sel.options.length === 1);
        txt.hidden = !isNew;
        if (!isNew) { txt.value = ''; }
    }
    var kindLabelSel = document.getElementById('wallet_kind_label');
    if (kindLabelSel) kindLabelSel.addEventListener('change', walletKindLabelField);
    var kindSel = document.getElementById('wallet_kind');
    if (kindSel) kindSel.addEventListener('change', walletKindFields);

    // فرم «حساب جدید» باید کاملاً خام باز شود. نسخه‌ی قبلی فقط چند فیلد
    // را پاک می‌کرد و شماره‌ی کارت و حساب و شبای حسابِ قبلی سر جایشان
    // می‌ماندند — کاربر حساب تازه می‌ساخت و اطلاعات حساب دیگری تویش بود.
    function resetWalletForm() {
        ['wallet_id', 'wallet_name', 'wallet_bank', 'wallet_last4', 'wallet_init',
         'wallet_card_number', 'wallet_account_number', 'wallet_iban',
         'wallet_kind_new'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
        var smsRow = document.getElementById('walletSmsForgetRow');
        if (smsRow) { smsRow.hidden = true; document.getElementById('wallet_sms_forget').checked = false; }
        var negEl = document.getElementById('wallet_init_neg');
        if (negEl) negEl.checked = false;
        var kind = document.getElementById('wallet_kind');
        if (kind) kind.value = 'cash';
        var bcode = document.getElementById('wallet_bank_code');
        if (bcode) bcode.value = '';
        if (window.syncBankLogo) window.syncBankLogo();
        var kl = document.getElementById('wallet_kind_label');
        if (kl) resetSelect(kl);
        var color = document.getElementById('wallet_color');
        if (color) color.value = '#16794f';
        var m = document.getElementById('walletMessage');
        if (m) { m.hidden = true; m.classList.remove('show', 'success', 'error'); }
    }

    document.querySelectorAll('.js-add-wallet').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('walletModalTitle').textContent = 'حساب جدید';
            resetWalletForm();
            document.getElementById('walletExtraActions').hidden = true;
            walletKindFields();
            openModal('walletModal');
        });
    });

    // ---------- شماره کارت / شبا: گروه‌بندی چهارتایی هنگام تایپ ----------
    function groupDigits(v) {
        var d = String(v || '').replace(/[^\d۰-۹٠-٩]/g, '');
        d = toLatinDigitsJs(d);
        return d.replace(/(.{4})/g, '$1 ').trim();
    }
    ['wallet_card_number', 'wallet_iban'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function () {
            var atEnd = this.selectionStart === this.value.length;
            this.value = groupDigits(this.value);
            if (atEnd) this.setSelectionRange(this.value.length, this.value.length);
            // ۴ رقم آخر کارت را خودش پر کند
            if (id === 'wallet_card_number') {
                var last4 = document.getElementById('wallet_last4');
                var digits = toLatinDigitsJs(this.value).replace(/\D/g, '');
                if (last4 && digits.length >= 4) { last4.value = digits.slice(-4); }
            }
        });
    });

    // انتخاب بانک، رنگ حساب را تنظیم می‌کند (کاربر بعدش می‌تواند عوض کند)
    var bankSel = document.getElementById('wallet_bank_code');
    /* پیش‌نمایشِ لوگوی بانکِ انتخاب‌شده کنارِ منو. ⚠ هم با `change` و هم
       پیش از باز شدنِ فرمِ ویرایش صدا زده می‌شود — آنجا مقدار با کد نوشته
       می‌شود و `change` نمی‌دهد، پس لوگوی بانکِ قبلی سرِ جایش می‌ماند. */
    function syncBankLogo() {
        var box = document.getElementById('walletBankLogo');
        if (!box || !bankSel) return;
        var opt = bankSel.options[bankSel.selectedIndex];
        var lu = (opt && opt.getAttribute('data-logo')) || '';
        box.hidden = !lu;
        if (lu) { box.querySelector('img').src = lu; }
    }
    window.syncBankLogo = syncBankLogo;
    if (bankSel) {
        bankSel.addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];
            var c1 = opt && opt.getAttribute('data-c1');
            if (c1) { document.getElementById('wallet_color').value = c1; }
            syncBankLogo();
        });
    }

    /* ---------- اسلایدرِ خانه: هم‌گام کردنِ نقطه‌ها ----------
       ⚠ در راست‌به‌چپ `scrollLeft` از صفر شروع می‌شود و **منفی** می‌رود،
         پس قدرِ مطلق لازم است؛ بدونش نقطه همیشه روی اسلاید اول می‌ماند.
       ⚠ پرش با `scrollIntoView` است نه با نوشتنِ `scrollLeft`: علامتِ آن
         عدد بین موتورها یکی نیست و محاسبه‌ی دستی‌اش روی یکی از آن‌ها
         بی‌صدا برعکس می‌شد. `block: 'nearest'` هم لازم است، وگرنه صفحه
         عمودی هم می‌پرد. */
    var homeSlides = document.getElementById('homeSlides');
    var homeDots = homeSlides ? homeSlides.parentNode.querySelectorAll('.home-dot') : [];
    if (homeSlides && homeDots.length) {
        var syncHomeDots = function () {
            /* ⚠ گامِ اسلاید از فاصله‌ی **واقعیِ** دو اسلایدِ اول خوانده
               می‌شود، نه از `عرض + عددِ gap`: با عددِ ثابت، اولین باری که
               `gap` در CSS عوض شود نقطه‌ها بی‌صدا از اسلاید عقب می‌افتند. */
            var kids = homeSlides.children;
            var step = kids.length > 1
                ? Math.abs(kids[1].getBoundingClientRect().x - kids[0].getBoundingClientRect().x)
                : 1;
            if (!step) { return; }
            var idx = Math.round(Math.abs(homeSlides.scrollLeft) / step);
            homeDots.forEach(function (d, n) { d.classList.toggle('is-on', n === idx); });
        };
        homeDots.forEach(function (d, n) {
            d.addEventListener('click', function () {
                var slide = homeSlides.children[n];
                if (slide) { slide.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' }); }
            });
        });
        homeSlides.addEventListener('scroll', syncHomeDots, { passive: true });
        window.addEventListener('resize', syncHomeDots);
        syncHomeDots();
    }

    /* ---------- پین کردنِ حساب روی صفحه‌ی خانه ----------
       ⚠ صفحه **تازه‌سازی نمی‌شود** و این عمدی است، برخلافِ بیشترِ
         تغییرهای این اپ: هیچ عددی روی همین صفحه عوض نمی‌شود (نوارِ
         خانه جای دیگری است)، پس تازه‌سازی فقط جای کاربر را در فهرست
         گم می‌کرد. حالتِ دکمه درجا عوض می‌شود و همان بازخوردِ لازم است. */
    document.querySelectorAll('.js-pin-wallet').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var on = btn.getAttribute('data-pinned') === '1';
            var fd = new FormData();
            fd.set('csrf_token', csrf());
            fd.set('wallet_id', btn.getAttribute('data-id') || '');
            fd.set('pinned', on ? '0' : '1');
            btn.disabled = true;

            fetch(apiUrl('toggle_wallet_pin.php'), {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.success) {
                        // ⛔ سقفِ تعداد از سرور می‌آید و باید **گفته** شود.
                        //    سکوت یعنی کاربر می‌زند، هیچ اتفاقی نمی‌افتد،
                        //    و نتیجه می‌گیرد دکمه خراب است.
                        alert(j.message || 'انجام نشد.');
                        return;
                    }
                    btn.setAttribute('data-pinned', j.pinned ? '1' : '0');
                    btn.setAttribute('aria-pressed', j.pinned ? 'true' : 'false');
                    btn.title = j.pinned ? 'برداشتن از صفحه‌ی خانه' : 'نمایش روی صفحه‌ی خانه';
                    btn.classList.toggle('is-on', !!j.pinned);
                })
                .catch(function () { alert(netErr()); })
                .finally(function () { btn.disabled = false; });
        });
    });

    // ---------- نمای کارت: زدن روی ردیف حساب ----------
    var cardModal = document.getElementById('bankCardModal');
    if (cardModal) {
        /* ⛔ انتخابگر `.js-show-card` است، نه `.wallet-row.js-show-card`:
           کارتِ پین‌شده‌ی صفحه‌ی خانه هم همین نما را باز می‌کند و با
           انتخابگرِ بسته به `.wallet-row` **بی‌صدا** هیچ‌کاری نمی‌کرد —
           تپ می‌شد و هیچ اتفاقی نمی‌افتاد. */
        document.querySelectorAll('.js-show-card').forEach(function (row) {
            // ⚠ کارتِ خانه `role="button"` دارد، پس با کیبورد هم باید باز شود.
            row.addEventListener('keydown', function (e) {
                if (this.getAttribute('role') !== 'button') return;
                if (e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                this.click();
            });
            row.addEventListener('click', function (e) {
                // دکمه‌ی ⋮ کار خودش را دارد
                if (e.target.closest('[data-stop], button, a')) return;

                var v = document.getElementById('bankCardVisual');
                v.style.setProperty('--bc1', this.getAttribute('data-c1') || '#475569');
                v.style.setProperty('--bc2', this.getAttribute('data-c2') || '#2f3b4a');

                var bank = this.getAttribute('data-bank') || '';
                document.getElementById('bankCardTitle').textContent = this.getAttribute('data-name') || 'کارت';
                document.getElementById('bcBank').textContent = bank || (this.getAttribute('data-name') || '');
                document.getElementById('bcKind').textContent = this.getAttribute('data-kind') || '';
                document.getElementById('bcNumber').textContent = this.getAttribute('data-card') || '';
                document.getElementById('bcOwner').textContent = this.getAttribute('data-name') || '';
                document.getElementById('bcBalance').textContent = this.getAttribute('data-balance') || '';
                // لوگوی رسمیِ بانک — `data-logo` خالی یعنی بانکی انتخاب نشده.
                var logo = document.getElementById('bcLogo');
                if (logo) {
                    var lu = this.getAttribute('data-logo') || '';
                    logo.hidden = !lu;
                    if (lu) { logo.querySelector('img').src = lu; }
                }

                // ردیف‌های زیر کارت فقط برای چیزهایی که واقعاً پر شده‌اند
                var rows = document.getElementById('bcRows');
                rows.innerHTML = '';
                [['شماره کارت', this.getAttribute('data-card')],
                 ['شماره حساب', this.getAttribute('data-account')],
                 ['شبا', this.getAttribute('data-iban')]].forEach(function (pair) {
                    if (!pair[1]) return;
                    var r = document.createElement('div');
                    r.className = 'bank-card-row';
                    var l = document.createElement('span');
                    l.className = 'bank-card-row-label'; l.textContent = pair[0];
                    var val = document.createElement('span');
                    val.className = 'bank-card-row-value'; val.textContent = pair[1];
                    var cp = document.createElement('button');
                    cp.type = 'button'; cp.className = 'bank-card-copy'; cp.textContent = 'کپی';
                    cp.addEventListener('click', function () {
                        var plain = toLatinDigitsJs(pair[1]).replace(/\s/g, '');
                        var done = function () {
                            cp.textContent = 'کپی شد'; cp.classList.add('done');
                            setTimeout(function () { cp.textContent = 'کپی'; cp.classList.remove('done'); }, 1600);
                        };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(plain).then(done, function () {});
                        } else {
                            // مرورگرهای قدیمی‌تر یا زمینه‌ی غیرامن
                            var ta = document.createElement('textarea');
                            ta.value = plain; document.body.appendChild(ta); ta.select();
                            try { document.execCommand('copy'); done(); } catch (err) {}
                            document.body.removeChild(ta);
                        }
                    });
                    r.appendChild(l); r.appendChild(val); r.appendChild(cp);
                    rows.appendChild(r);
                });
                if (!rows.children.length) {
                    var empty = document.createElement('p');
                    empty.className = 'hint';
                    empty.style.textAlign = 'center';
                    empty.textContent = 'شماره کارت، حساب یا شبا ثبت نشده — از دکمه‌ی ⋮ اضافه کنید.';
                    rows.appendChild(empty);
                }

                // دو کار روی همین حساب: تعدیل موجودی و دیدن تراکنش‌هایش
                var wid = this.getAttribute('data-id') || '';
                var wname = this.getAttribute('data-name') || '';
                var wbal = this.getAttribute('data-balance') || '';
                var adjBtn = document.getElementById('bcAdjustBtn');
                if (adjBtn) {
                    adjBtn.setAttribute('data-id', wid);
                    adjBtn.setAttribute('data-name', wname);
                    adjBtn.setAttribute('data-balance', wbal);
                }
                var mergeBtn = document.getElementById('bcMergeBtn');
                if (mergeBtn) {
                    mergeBtn.setAttribute('data-id', wid);
                    mergeBtn.setAttribute('data-name', wname);
                }
                var txLink = document.getElementById('bcTxLink');
                if (txLink) {
                    txLink.href = (window.APP_BASE || '') + '/transactions.php?wallet=' + encodeURIComponent(wid);
                }

                // ویرایش کامل همین حساب — رنگ، شماره کارت، شبا و بقیه.
                // دکمه‌ی ⋮ روی ردیف همین کار را می‌کند ولی پیدا کردنش
                // آسان نبود؛ اینجا کنار خود کارت است.
                /* ⛔ شیتِ ویرایش فقط در `wallets.php` هست. روی صفحه‌ی خانه
                   نبودنش یعنی دکمه‌ای که زده می‌شود و هیچ اتفاقی نمی‌افتد —
                   «دکمه‌ی بی‌کار از نبودنش بدتر است». پس آنجا همان دکمه
                   کاربر را به صفحه‌ی حساب‌ها می‌برد و برچسبش هم همین را
                   می‌گوید. */
                var editBtn = document.getElementById('bcEditBtn');
                var rowEdit = this.querySelector('.js-edit-wallet');
                if (editBtn) {
                    editBtn.textContent = rowEdit ? 'ویرایش حساب' : 'ویرایش در حساب‌ها';
                    editBtn.onclick = function () {
                        closeModal('bankCardModal');
                        if (rowEdit) { rowEdit.click(); return; }
                        window.location.href = (window.APP_BASE || '') + '/wallets.php';
                    };
                }

                openModal('bankCardModal');
            });
        });
    }

    // ---------- تعدیل موجودی حساب ----------
    /* ---------- «دقیقه‌ی اول»: موجودی اولیه روی صفحه‌ی خانه ----------
       عمداً همان `api/adjust_wallet.php` با `mode=set` را صدا می‌زند، نه
       یک اندپوینتِ تازه: منطقِ «تفاوت تا رسیدن به عددِ واقعی» و بررسیِ
       مالکیت آنجاست و نسخه‌ی دومش دیر یا زود از آن دور می‌افتاد. */
    var startBalanceForm = document.getElementById('startBalanceForm');
    if (startBalanceForm) {
        setupAmountFormatter('start_balance');

        startBalanceForm.addEventListener('submit', function (e) {
            e.preventDefault();
            submitJson(startBalanceForm, apiUrl('adjust_wallet.php'),
                document.getElementById('startBalanceMessage'),
                document.getElementById('startBalanceSubmit'), 'start_balance');
        });

        var startSkip = document.getElementById('startBalanceSkip');
        if (startSkip) {
            startSkip.addEventListener('click', function () {
                var fd = new FormData();
                fd.set('csrf_token', startBalanceForm.querySelector('[name="csrf_token"]').value);
                startSkip.disabled = true;
                fetch(apiUrl('dismiss_balance_hint.php'), {
                    method: 'POST', body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.json(); })
                    // ⚠ تازه‌سازی لازم است نه پنهان کردنِ کارت: عددهای بالای
                    //   صفحه سمتِ سرور رندر شده‌اند و کاربر باید ببیند چیزی
                    //   عوض نشده — همان قاعده‌ی «هر تغییر باید دیده شود».
                    .then(function () { window.reloadPage(); })
                    .catch(function () { startSkip.disabled = false; });
            });
        }
    }

    var adjustForm = document.getElementById('adjustWalletForm');
    if (adjustForm) {
        var adjustHints = {
            add: 'این مبلغ به موجودی حساب اضافه می‌شود.',
            sub: 'این مبلغ از موجودی حساب کم می‌شود.',
            set: 'موجودی واقعی حساب را بنویسید؛ تفاوتش خودکار اعمال می‌شود.'
        };

        document.querySelectorAll('#adjustModeToggle .type-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('#adjustModeToggle .type-btn').forEach(function (x) {
                    x.classList.remove('active');
                });
                this.classList.add('active');
                var mode = this.getAttribute('data-mode');
                document.getElementById('adjust_mode').value = mode;
                document.getElementById('adjustModeHint').textContent = adjustHints[mode] || '';
                var neg = document.getElementById('adjustNegWrap');
                neg.hidden = mode !== 'set';
                if (mode !== 'set') { document.getElementById('adjust_negative').checked = false; }
            });
        });

        var adjustBtn = document.getElementById('bcAdjustBtn');
        if (adjustBtn) {
            adjustBtn.addEventListener('click', function () {
                closeModal('bankCardModal');
                document.getElementById('adjust_wallet_id').value = this.getAttribute('data-id') || '';
                document.getElementById('adjustWalletTitle').textContent =
                    'تعدیل موجودی: ' + (this.getAttribute('data-name') || '');
                document.getElementById('adjustCurrentHint').textContent =
                    'موجودی فعلی: ' + (this.getAttribute('data-balance') || '') + ' تومان';
                document.getElementById('adjust_amount').value = '';
                document.getElementById('adjust_negative').checked = false;
                document.querySelector('#adjustModeToggle .type-btn[data-mode="add"]').click();
                var m = document.getElementById('adjustWalletMessage');
                if (m) { m.hidden = true; m.classList.remove('show', 'success', 'error'); }
                openModal('adjustWalletModal');
            });
        }

        setupAmountFormatter('adjust_amount');

        adjustForm.addEventListener('submit', function (e) {
            e.preventDefault();
            submitJson(adjustForm, apiUrl('adjust_wallet.php'),
                document.getElementById('adjustWalletMessage'),
                document.getElementById('adjustWalletSubmitBtn'), 'adjust_amount');
        });
    }

    // ---------- انتقالِ تراکنش‌ها به حسابِ دیگر ----------
    /* منطق در `mergeWallet()` است؛ اینجا فقط مقصد انتخاب می‌شود. خودِ
       حسابِ مبدأ از فهرست پنهان می‌شود (انتقال به خودش معنا ندارد)، و
       اگر مقصدِ دیگری نماند دکمه غیرفعال می‌شود با توضیح — دکمه‌ای که
       زده شود و فقط «دو حسابِ متفاوت انتخاب کنید» بگیرد بی‌کار است. */
    var mergeForm = document.getElementById('mergeWalletForm');
    var mergeOpen = document.getElementById('bcMergeBtn');
    if (mergeForm && mergeOpen) {
        mergeOpen.addEventListener('click', function () {
            var wid = this.getAttribute('data-id') || '';
            closeModal('bankCardModal');
            document.getElementById('merge_wallet_id').value = wid;
            document.getElementById('mergeWalletTitle').textContent =
                'انتقال تراکنش‌های «' + (this.getAttribute('data-name') || '') + '»';
            var sel = document.getElementById('merge_into_id');
            var first = null;
            for (var i = 0; i < sel.options.length; i++) {
                var o = sel.options[i];
                o.hidden = o.disabled = (o.value === wid);
                if (!o.disabled && first === null) { first = i; }
            }
            if (first !== null) { sel.selectedIndex = first; }
            document.getElementById('mergeNoTarget').hidden = first !== null;
            document.getElementById('mergeWalletSubmitBtn').disabled = first === null;
            document.getElementById('merge_delete').checked = true;
            var m = document.getElementById('mergeWalletMessage');
            if (m) { m.hidden = true; m.classList.remove('show', 'success', 'error'); }
            openModal('mergeWalletModal');
        });

        mergeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var sel = document.getElementById('merge_into_id');
            var dst = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
            var del = document.getElementById('merge_delete').checked;
            // ⚠ confirm عمداً مانده: «لغو» اینجا ممکن نیست (کلِ دفترِ یک
            //   حساب جابه‌جا می‌شود، نه یک ردیف) — همان دلیلِ حذفِ حساب.
            if (!confirm('همه‌ی تراکنش‌های این حساب به «' + dst + '» منتقل شود'
                + (del ? ' و این حساب حذف شود' : '') + '؟ این کار برگشت‌پذیر نیست.')) { return; }
            submitJson(mergeForm, apiUrl('merge_wallet.php'),
                document.getElementById('mergeWalletMessage'),
                document.getElementById('mergeWalletSubmitBtn'));
        });
    }

    document.querySelectorAll('.js-edit-wallet').forEach(function (btn) {
        btn.addEventListener('click', function () {
            resetWalletForm();
            document.getElementById('walletModalTitle').textContent = 'ویرایش حساب';
            document.getElementById('wallet_id').value = this.getAttribute('data-id');
            document.getElementById('wallet_name').value = this.getAttribute('data-name');
            document.getElementById('wallet_kind').value = this.getAttribute('data-kind');
            document.getElementById('wallet_bank').value = this.getAttribute('data-bank') || '';
            document.getElementById('wallet_last4').value = this.getAttribute('data-last4') || '';
            document.getElementById('wallet_color').value = this.getAttribute('data-color') || '#64748b';
            var bcSel = document.getElementById('wallet_bank_code');
            if (bcSel) bcSel.value = this.getAttribute('data-bank-code') || '';
            if (window.syncBankLogo) window.syncBankLogo();
            var cardEl = document.getElementById('wallet_card_number');
            if (cardEl) cardEl.value = groupDigits(this.getAttribute('data-card') || '');
            var accEl = document.getElementById('wallet_account_number');
            if (accEl) accEl.value = this.getAttribute('data-account') || '';
            var ibanEl = document.getElementById('wallet_iban');
            if (ibanEl) ibanEl.value = groupDigits(this.getAttribute('data-iban') || '');

            var init = parseInt(this.getAttribute('data-init') || '0', 10);
            document.getElementById('wallet_init').value = init ? fmtMoneyJs(Math.abs(init)) : '';
            var negBox = document.getElementById('wallet_init_neg');
            if (negBox) negBox.checked = init < 0;

            // نوع دلخواه: اگر در فهرست بود انتخابش کن، وگرنه به‌عنوان
            // نوع تازه در جعبه‌ی متن بنشیند تا از دست نرود
            var kl = this.getAttribute('data-kind-label') || '';
            var klSel = document.getElementById('wallet_kind_label');
            var klNew = document.getElementById('wallet_kind_new');
            if (klSel && klNew) {
                var found = false;
                for (var i = 0; i < klSel.options.length; i++) {
                    if (klSel.options[i].value === kl) { klSel.selectedIndex = i; found = true; break; }
                }
                if (!found) { klSel.value = '__new__'; klNew.value = kl; }
                else { klNew.value = ''; }
                walletKindLabelField();
            }

            var smsRow = document.getElementById('walletSmsForgetRow');
            if (smsRow) { smsRow.hidden = this.getAttribute('data-sms-learned') !== '1'; }

            var extra = document.getElementById('walletExtraActions');
            extra.hidden = false;
            var isActive = this.getAttribute('data-active') === '1';
            var tgl = document.getElementById('walletToggleBtn');
            tgl.textContent = isActive ? 'غیرفعال کردن' : 'فعال کردن';
            tgl.setAttribute('data-id', this.getAttribute('data-id'));
            document.getElementById('walletDeleteBtn').setAttribute('data-id', this.getAttribute('data-id'));

            var m = document.getElementById('walletMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            walletKindFields();
            openModal('walletModal');
        });
    });

    var walletForm = document.getElementById('walletForm');
    if (walletForm) {
        walletForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(walletForm);
            fd.set('initial_balance', toLatinDigitsJs(document.getElementById('wallet_init').value).replace(/[,\u066C]/g, ''));
            submitJson(walletForm, apiUrl('save_wallet.php'),
                document.getElementById('walletMessage'),
                document.getElementById('walletSubmitBtn'), null, fd);
        });
    }

    var walletToggleBtn = document.getElementById('walletToggleBtn');
    if (walletToggleBtn) {
        walletToggleBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('wallet_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('toggle_wallet.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.reloadPage(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert(netErr()); });
        });
    }

    var walletDeleteBtn = document.getElementById('walletDeleteBtn');
    if (walletDeleteBtn) {
        walletDeleteBtn.addEventListener('click', function () {
            if (!confirm('این حساب حذف شود؟')) return;
            var fd = new FormData();
            fd.append('wallet_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('delete_wallet.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){
                    if (d.success) { window.reloadPage(); }
                    else {
                        var m = document.getElementById('walletMessage');
                        m.hidden = false; m.classList.remove('success');
                        m.classList.add('show','error');
                        m.textContent = d.message || 'قابل حذف نیست.';
                    }
                })
                .catch(function(){ alert(netErr()); });
        });
    }

    // ---------- انتقال ----------
    document.querySelectorAll('.js-add-transfer').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('transferModalTitle').textContent = 'انتقال بین حساب‌ها';
            document.getElementById('transfer_id').value = '';
            document.getElementById('transfer_amount').value = '';
            document.getElementById('transfer_fee').value = '';
            document.getElementById('transfer_note').value = '';
            // ⚠ حساب‌ها و تاریخ هم به پیش‌فرضِ صفحه؛ وگرنه «انتقالِ تازه» بعد از
            //   یک ویرایش، حساب‌ها و تاریخِ همان انتقالِ قدیمی را بی‌صدا نگه می‌داشت.
            resetSelect(document.getElementById('transfer_from'));
            resetSelect(document.getElementById('transfer_to'));
            resetJdpValue('transfer_date_display', 'transfer_date');
            var m = document.getElementById('transferMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('transferModal');
        });
    });

    document.querySelectorAll('.js-edit-transfer').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('transferModalTitle').textContent = 'ویرایش انتقال';
            document.getElementById('transfer_id').value = this.getAttribute('data-id');
            document.getElementById('transfer_from').value = this.getAttribute('data-from');
            document.getElementById('transfer_to').value = this.getAttribute('data-to');
            document.getElementById('transfer_amount').value =
                fmtMoneyJs(this.getAttribute('data-amount'));
            var fee = parseInt(this.getAttribute('data-fee') || '0', 10);
            document.getElementById('transfer_fee').value = fee ? fmtMoneyJs(fee) : '';
            document.getElementById('transfer_note').value = this.getAttribute('data-note') || '';
            setJdpValue('transfer_date_display', 'transfer_date', this.getAttribute('data-date'));
            var m = document.getElementById('transferMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('transferModal');
        });
    });

    var transferForm = document.getElementById('transferForm');
    if (transferForm) {
        transferForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var from = document.getElementById('transfer_from').value;
            var to   = document.getElementById('transfer_to').value;
            var msg  = document.getElementById('transferMessage');
            if (from === to) {
                msg.hidden = false; msg.classList.remove('success');
                msg.classList.add('show','error');
                msg.textContent = 'حساب مبدأ و مقصد نمی‌توانند یکی باشند.';
                return;
            }
            var fd = new FormData(transferForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('transfer_amount').value).replace(/[,\u066C]/g, ''));
            fd.set('fee',    toLatinDigitsJs(document.getElementById('transfer_fee').value).replace(/[,\u066C]/g, ''));
            submitJson(transferForm, apiUrl('save_transfer.php'), msg,
                document.getElementById('transferSubmitBtn'), null, fd);
        });
    }

    document.querySelectorAll('.js-delete-transfer').forEach(function (btn) {
        btn.addEventListener('click', function () {
            deleteWithUndo({ ep: 'delete_transfer.php', field: 'transfer_id',
                             value: this.getAttribute('data-id'), csrf: csrf(),
                             text: 'انتقال حذف شد — موجودی هر دو حساب برگشت.' });
        });
    });

    // ---------- بودجه‌بندی ----------
    setupAmountFormatter('budget_amount');

    var budgetPeriodSel = document.getElementById('budget_period');
    function budgetCustomToggle() {
        var box = document.getElementById('budgetCustomDates');
        if (box) box.hidden = (budgetPeriodSel.value !== 'custom');
    }
    if (budgetPeriodSel) budgetPeriodSel.addEventListener('change', budgetCustomToggle);

    document.querySelectorAll('.js-add-budget').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('budgetModalTitle').textContent = 'بودجه جدید';
            document.getElementById('budget_id').value = '';
            document.getElementById('budget_category').selectedIndex = 0;
            document.getElementById('budget_period').value = 'monthly';
            document.getElementById('budget_amount').value = '';
            resetJdpValue('budget_start_display', 'budget_start');
            resetJdpValue('budget_end_display', 'budget_end');
            document.getElementById('budgetExtraActions').hidden = true;
            var m = document.getElementById('budgetMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            budgetCustomToggle();
            openModal('budgetModal');
        });
    });

    document.querySelectorAll('.js-edit-budget').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('budgetModalTitle').textContent = 'ویرایش بودجه';
            document.getElementById('budget_id').value = this.getAttribute('data-id');
            document.getElementById('budget_category').value = this.getAttribute('data-category');
            document.getElementById('budget_period').value = this.getAttribute('data-period');
            document.getElementById('budget_amount').value =
                fmtMoneyJs(this.getAttribute('data-amount'));

            var start = this.getAttribute('data-start');
            var end = this.getAttribute('data-end');
            if (start) { setJdpValue('budget_start_display', 'budget_start', start); }
            else { resetJdpValue('budget_start_display', 'budget_start'); }
            if (end) { setJdpValue('budget_end_display', 'budget_end', end); }
            else { resetJdpValue('budget_end_display', 'budget_end'); }

            var extra = document.getElementById('budgetExtraActions');
            extra.hidden = false;
            var isActive = this.getAttribute('data-active') === '1';
            var tgl = document.getElementById('budgetToggleBtn');
            tgl.textContent = isActive ? 'غیرفعال کردن' : 'فعال کردن';
            tgl.setAttribute('data-id', this.getAttribute('data-id'));
            document.getElementById('budgetDeleteBtn').setAttribute('data-id', this.getAttribute('data-id'));

            var m = document.getElementById('budgetMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            budgetCustomToggle();
            openModal('budgetModal');
        });
    });

    var budgetForm = document.getElementById('budgetForm');
    if (budgetForm) {
        budgetForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(budgetForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('budget_amount').value).replace(/[,\u066C]/g, ''));
            submitJson(budgetForm, apiUrl('save_budget.php'),
                document.getElementById('budgetMessage'),
                document.getElementById('budgetSubmitBtn'), null, fd);
        });
    }

    var budgetToggleBtn = document.getElementById('budgetToggleBtn');
    if (budgetToggleBtn) {
        budgetToggleBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('budget_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('toggle_budget.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.reloadPage(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert(netErr()); });
        });
    }

    var budgetDeleteBtn = document.getElementById('budgetDeleteBtn');
    if (budgetDeleteBtn) {
        budgetDeleteBtn.addEventListener('click', function () {
            deleteWithUndo({ ep: 'delete_budget.php', field: 'budget_id',
                             value: this.getAttribute('data-id'), csrf: csrf(),
                             text: 'بودجه حذف شد.' });
        });
    }

    // ---------- اهداف پس‌انداز ----------
    setupAmountFormatter('goal_target');
    setupAmountFormatter('entry_amount');

    // ⛔ پیش‌نمایشِ زنده — تنها جایی که رنگ و عنوان و حساب پیش از ذخیره
    //    دیده می‌شوند. بدونِ آن کاربر رنگ را انتخاب می‌کرد و تا **بعد از
    //    ذخیره** نمی‌دانست چه شکلی می‌شود؛ و چون انتخابگرِ رنگ خودش هم
    //    یک خطِ نازک بود (padding روی `input[type=color]`)، عملاً هیچ
    //    بازخوردی وجود نداشت.
    function syncGoalPreview() {
        var bar = document.getElementById('goalPreviewBar');
        if (!bar) { return; }
        var color = document.getElementById('goal_color').value || '#16794f';
        var title = document.getElementById('goal_title').value.trim();
        bar.style.background = color;
        document.getElementById('goalPreviewPct').style.color = color;
        document.getElementById('goalPreviewTitle').textContent = title || 'عنوان هدف';

        var sel = document.getElementById('goal_wallet');
        var lbl = document.getElementById('goalPreviewWallet');
        if (sel && lbl) { lbl.textContent = sel.options[sel.selectedIndex].text; }
    }
    ['goal_color', 'goal_title', 'goal_wallet'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('input', syncGoalPreview); el.addEventListener('change', syncGoalPreview); }
    });

    document.querySelectorAll('.js-add-goal').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('goalModalTitle').textContent = 'هدف جدید';
            document.getElementById('goal_id').value = '';
            document.getElementById('goal_title').value = '';
            document.getElementById('goal_target').value = '';
            document.getElementById('goal_color').value = '#16794f';
            document.getElementById('goal_date').value = '';
            document.getElementById('goal_date_display').value = '';
            var gw = document.getElementById('goal_wallet');
            if (gw) { gw.value = '0'; }
            syncGoalPreview();
            document.getElementById('goalExtraActions').hidden = true;
            var m = document.getElementById('goalMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('goalModal');
        });
    });

    document.querySelectorAll('.js-edit-goal').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('goalModalTitle').textContent = 'ویرایش هدف';
            document.getElementById('goal_id').value = this.getAttribute('data-id');
            document.getElementById('goal_title').value = this.getAttribute('data-title');
            document.getElementById('goal_target').value =
                fmtMoneyJs(this.getAttribute('data-target'));
            document.getElementById('goal_color').value = this.getAttribute('data-color') || '#16794f';
            var gwEdit = document.getElementById('goal_wallet');
            if (gwEdit) {
                // ⚠ حسابی که پاک شده باشد دیگر در فهرست نیست؛ آن‌وقت
                //   انتساب بی‌صدا رد می‌شود و «مشخص نشده» می‌ماند — درست است.
                gwEdit.value = this.getAttribute('data-wallet') || '0';
                if (gwEdit.selectedIndex < 0) { gwEdit.value = '0'; }
            }
            syncGoalPreview();

            var d = this.getAttribute('data-date');
            if (d) { setJdpValue('goal_date_display', 'goal_date', d); }
            else { document.getElementById('goal_date').value = ''; document.getElementById('goal_date_display').value = ''; }

            var extra = document.getElementById('goalExtraActions');
            extra.hidden = false;
            var isArchived = this.getAttribute('data-archived') === '1';
            var ab = document.getElementById('goalArchiveBtn');
            ab.textContent = isArchived ? 'خارج از بایگانی' : 'بایگانی کردن';
            ab.setAttribute('data-id', this.getAttribute('data-id'));
            document.getElementById('goalDeleteBtn').setAttribute('data-id', this.getAttribute('data-id'));

            var m = document.getElementById('goalMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('goalModal');
        });
    });

    var goalForm = document.getElementById('goalForm');
    if (goalForm) {
        goalForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(goalForm);
            fd.set('target_amount', toLatinDigitsJs(document.getElementById('goal_target').value).replace(/[,\u066C]/g, ''));
            submitJson(goalForm, apiUrl('save_goal.php'),
                document.getElementById('goalMessage'),
                document.getElementById('goalSubmitBtn'), null, fd);
        });
    }

    var goalArchiveBtn = document.getElementById('goalArchiveBtn');
    if (goalArchiveBtn) {
        goalArchiveBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('goal_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('archive_goal.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.reloadPage(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert(netErr()); });
        });
    }

    var goalDeleteBtn = document.getElementById('goalDeleteBtn');
    if (goalDeleteBtn) {
        goalDeleteBtn.addEventListener('click', function () {
            // ⚠ تاریخچه‌ی واریز/برداشت با CASCADE می‌رود و «لغو» هم
            //   همان را برمی‌گرداند — عکس فرزندها را هم دارد.
            deleteWithUndo({ ep: 'delete_goal.php', field: 'goal_id',
                             value: this.getAttribute('data-id'), csrf: csrf(),
                             text: 'هدف پس‌انداز با کل تاریخچه‌اش حذف شد.' });
        });
    }

    document.querySelectorAll('.js-goal-deposit, .js-goal-withdraw').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var isDeposit = this.classList.contains('js-goal-deposit');
            document.getElementById('goalEntryTitle').textContent =
                (isDeposit ? 'واریز به «' : 'برداشت از «') + this.getAttribute('data-title') + '»';
            document.getElementById('entry_goal_id').value = this.getAttribute('data-id');
            document.getElementById('entry_direction').value = isDeposit ? 'deposit' : 'withdraw';
            document.getElementById('entry_amount').value = '';
            document.getElementById('entry_note').value = '';
            var m = document.getElementById('goalEntryMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('goalEntryModal');
        });
    });

    var goalEntryForm = document.getElementById('goalEntryForm');
    if (goalEntryForm) {
        goalEntryForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(goalEntryForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('entry_amount').value).replace(/[,\u066C]/g, ''));
            submitJson(goalEntryForm, apiUrl('add_savings_entry.php'),
                document.getElementById('goalEntryMessage'),
                document.getElementById('goalEntrySubmitBtn'), null, fd);
        });
    }

    document.querySelectorAll('.js-goal-history').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var goalId = this.getAttribute('data-id');
            var box = document.getElementById('goalHistory' + goalId);
            if (!box) return;

            if (!box.hidden) { box.hidden = true; return; }

            box.innerHTML = window.skeletonHtml(2);
            box.hidden = false;

            fetch(apiUrl('savings_history.php') + '?goal_id=' + encodeURIComponent(goalId))
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    box.innerHTML = html;
                    box.dataset.loaded = '1';
                    box.querySelectorAll('.js-delete-savings-entry').forEach(function (delBtn) {
                        delBtn.addEventListener('click', function () {
                            deleteWithUndo({ ep: 'delete_savings_entry.php', field: 'entry_id',
                                             value: this.getAttribute('data-id'), csrf: csrf(),
                                             text: 'رکورد پس‌انداز حذف شد.' });
                        });
                    });
                })
                .catch(function () {
                    box.innerHTML = '<p style="text-align:center; color:var(--out); padding:10px 0;">خطا در بارگذاری.</p>';
                });
        });
    });

    // ---------- پرداخت جزئی طلب/بدهی ----------
    setupAmountFormatter('payment_amount');

    // ---------- کلید «قسطی است» در فرم افزودن ----------
    var instToggle = document.getElementById('add_debt_installment');
    if (instToggle) {
        var instFields = document.getElementById('addInstFields');
        var instCount  = document.getElementById('add_inst_count');
        var instPrev   = document.getElementById('addInstPreview');
        var debtAmount = document.getElementById('add_debt_amount');

        function instPreview() {
            if (!instPrev) { return; }
            var amt = parseInt(toLatinDigitsJs(debtAmount ? debtAmount.value : '').replace(/[^\d]/g, ''), 10) || 0;
            var n   = parseInt(toLatinDigitsJs(instCount ? instCount.value : '').replace(/[^\d]/g, ''), 10) || 0;
            if (amt > 0 && n > 1) {
                // ⚠ همان محاسبه‌ی سمتِ سرور: باقیمانده به قسطِ آخر
                //   می‌رود، نه پخش‌شده — وگرنه جمعِ اقساط با مبلغِ وام
                //   یکی نمی‌شد. پیش‌نمایش باید همان را بگوید.
                var base = Math.floor(amt / n);
                var last = amt - base * (n - 1);
                instPrev.textContent = 'هر قسط ' + fmtMoneyJs(base) +
                    ' تومان' + (last !== base ? ' (قسط آخر ' + fmtMoneyJs(last) + ')' : '') + '.';
            } else {
                instPrev.textContent = 'مبلغ هر قسط خودکار حساب می‌شود و همه‌ی اقساط در «آینده مالی» و «پول قابل خرج» دیده می‌شوند.';
            }
        }

        instToggle.addEventListener('change', function () {
            if (instFields) { instFields.classList.toggle('is-open', instToggle.checked); }
            instPreview();
        });
        if (instCount)  { instCount.addEventListener('input', instPreview); }
        if (debtAmount) { debtAmount.addEventListener('input', instPreview); }
    }

    document.querySelectorAll('.js-debt-payment').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var remaining = parseInt(this.getAttribute('data-remaining'), 10);
            var nextInst  = parseInt(this.getAttribute('data-next-inst'), 10) || 0;
            document.getElementById('debtPaymentTitle').textContent = 'ثبت پرداخت — ' + this.getAttribute('data-name');
            document.getElementById('payment_debt_id').value = this.getAttribute('data-id');
            // مبلغِ قسط بعدی از پیش پر می‌شود — برای وامِ قسطی، همان
            // عددی است که کاربر تقریباً همیشه می‌خواهد. قابل تغییر است.
            document.getElementById('payment_amount').value =
                nextInst > 0 ? nextInst.toLocaleString('en-US') : '';
            document.getElementById('payment_note').value = '';
            var hint = 'باقیمانده: ' + fmtMoneyJs(remaining) + ' تومان';
            if (nextInst > 0) {
                hint += ' — مبلغ قسط بعدی از پیش پر شده است.';
            }
            document.getElementById('paymentRemainingHint').textContent = hint;
            var m = document.getElementById('debtPaymentMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('debtPaymentModal');
        });
    });

    var debtPaymentForm = document.getElementById('debtPaymentForm');
    if (debtPaymentForm) {
        debtPaymentForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(debtPaymentForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('payment_amount').value).replace(/[,\u066C]/g, ''));
            var msgEl = document.getElementById('debtPaymentMessage');
            var btnEl = document.getElementById('debtPaymentSubmitBtn');
            btnEl.disabled = true;
            fetch(apiUrl('add_debt_payment.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    msgEl.hidden = false; msgEl.classList.remove('success', 'error');
                    if (d.success) {
                        msgEl.classList.add('show', 'success');
                        msgEl.textContent = d.message || 'ثبت شد.';
                        window.reloadPage();
                    } else {
                        msgEl.classList.add('show', 'error');
                        msgEl.textContent = d.message || 'خطایی رخ داد.';
                    }
                })
                .catch(function () {
                    msgEl.hidden = false; msgEl.classList.add('show', 'error');
                    msgEl.textContent = 'خطا در ارتباط با سرور.';
                })
                .finally(function () { btnEl.disabled = false; });
        });
    }

    // ---------- تراکنش دوره‌ای ----------
    setupAmountFormatter('recurring_amount');
    setupAmountFormatter('confirm_amount');

    var recurringToggle = setupTypeToggle('recurringTypeToggle', 'recurring_type', 'recurring_category');

    var recurModeHints = {
        remind:  'فقط یادآوری می‌دهد؛ خودتان تراکنش را ثبت می‌کنید.',
        confirm: 'سررسید که برسد، از شما می‌خواهد تأیید کنید تا ثبت شود.',
        auto:    'سررسید که برسد، خودش بدون نیاز به تأیید ثبت می‌کند.'
    };
    document.querySelectorAll('.recurring-mode-item').forEach(function (item) {
        item.addEventListener('click', function () {
            document.querySelectorAll('.recurring-mode-item').forEach(function (i) { i.classList.remove('active'); });
            this.classList.add('active');
            var mode = this.getAttribute('data-mode');
            document.getElementById('recurring_mode').value = mode;
            document.getElementById('recurringModeHint').textContent = recurModeHints[mode] || '';
        });
    });
    function setRecurringMode(mode) {
        document.querySelectorAll('.recurring-mode-item').forEach(function (i) {
            i.classList.toggle('active', i.getAttribute('data-mode') === mode);
        });
        document.getElementById('recurring_mode').value = mode;
        document.getElementById('recurringModeHint').textContent = recurModeHints[mode] || '';
    }

    document.querySelectorAll('.js-add-recurring').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('recurringModalTitle').textContent = 'قانون جدید';
            document.getElementById('recurring_id').value = '';
            document.getElementById('recurring_title').value = '';
            document.getElementById('recurring_amount').value = '';
            document.getElementById('recurring_wallet').selectedIndex = 0;
            document.getElementById('recurring_frequency').value = 'monthly';
            document.getElementById('recurring_interval').value = '1';
            document.getElementById('recurring_note').value = '';
            resetJdpValue('recurring_start_display', 'recurring_start');
            resetJdpValue('recurring_end_display', 'recurring_end');
            document.getElementById('recurringExtraActions').hidden = true;
            if (recurringToggle) recurringToggle.setType('income');
            setRecurringMode('remind');
            var m = document.getElementById('recurringMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('recurringModal');
        });
    });

    document.querySelectorAll('.js-edit-recurring').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('recurringModalTitle').textContent = 'ویرایش قانون';
            document.getElementById('recurring_id').value = this.getAttribute('data-id');
            document.getElementById('recurring_title').value = this.getAttribute('data-title');
            document.getElementById('recurring_amount').value =
                fmtMoneyJs(this.getAttribute('data-amount'));
            document.getElementById('recurring_wallet').value = this.getAttribute('data-wallet');
            document.getElementById('recurring_frequency').value = this.getAttribute('data-frequency');
            document.getElementById('recurring_interval').value = toPersianDigitsJs(this.getAttribute('data-interval'));
            document.getElementById('recurring_note').value = this.getAttribute('data-note') || '';

            if (recurringToggle) recurringToggle.setType(this.getAttribute('data-type'));
            document.getElementById('recurring_category').value = this.getAttribute('data-category') || '';

            setJdpValue('recurring_start_display', 'recurring_start', this.getAttribute('data-start'));
            var end = this.getAttribute('data-end');
            if (end) { setJdpValue('recurring_end_display', 'recurring_end', end); }
            else { document.getElementById('recurring_end').value = ''; document.getElementById('recurring_end_display').value = ''; }

            setRecurringMode(this.getAttribute('data-mode'));

            var extra = document.getElementById('recurringExtraActions');
            extra.hidden = false;
            var isActive = this.getAttribute('data-active') === '1';
            var tgl = document.getElementById('recurringToggleBtn');
            tgl.textContent = isActive ? 'غیرفعال کردن' : 'فعال کردن';
            tgl.setAttribute('data-id', this.getAttribute('data-id'));
            document.getElementById('recurringDeleteBtn').setAttribute('data-id', this.getAttribute('data-id'));

            var m = document.getElementById('recurringMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('recurringModal');
        });
    });

    var recurringForm = document.getElementById('recurringForm');
    if (recurringForm) {
        recurringForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(recurringForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('recurring_amount').value).replace(/[,\u066C]/g, ''));
            fd.set('interval_count', toLatinDigitsJs(document.getElementById('recurring_interval').value).replace(/[,\u066C]/g, ''));
            submitJson(recurringForm, apiUrl('save_recurring.php'),
                document.getElementById('recurringMessage'),
                document.getElementById('recurringSubmitBtn'), null, fd);
        });
    }

    var recurringToggleBtn = document.getElementById('recurringToggleBtn');
    if (recurringToggleBtn) {
        recurringToggleBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('recurring_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('toggle_recurring.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.reloadPage(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert(netErr()); });
        });
    }

    var recurringDeleteBtn = document.getElementById('recurringDeleteBtn');
    if (recurringDeleteBtn) {
        recurringDeleteBtn.addEventListener('click', function () {
            if (!confirm('این قانون حذف شود؟ تراکنش‌های قبلی حذف نمی‌شوند.')) return;
            var fd = new FormData();
            fd.append('recurring_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('delete_recurring.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.reloadPage(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert(netErr()); });
        });
    }

    // ---------- تأیید / رد سررسید ----------
    document.querySelectorAll('.js-confirm-recurring').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('confirmRecurringTitle').textContent = 'تأیید — ' + this.getAttribute('data-title');
            document.getElementById('confirm_recurring_id').value = this.getAttribute('data-id');
            document.getElementById('confirm_amount').value =
                fmtMoneyJs(this.getAttribute('data-amount'));
            var m = document.getElementById('confirmRecurringMessage');
            if (m) { m.hidden = true; m.classList.remove('show','success','error'); }
            openModal('confirmRecurringModal');
        });
    });

    var confirmRecurringForm = document.getElementById('confirmRecurringForm');
    if (confirmRecurringForm) {
        confirmRecurringForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(confirmRecurringForm);
            fd.set('amount', toLatinDigitsJs(document.getElementById('confirm_amount').value).replace(/[,\u066C]/g, ''));
            submitJson(confirmRecurringForm, apiUrl('confirm_recurring.php'),
                document.getElementById('confirmRecurringMessage'),
                document.getElementById('confirmRecurringSubmitBtn'), null, fd);
        });
    }

    document.querySelectorAll('.js-skip-recurring').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('این دوره بدون ثبت تراکنش رد شود؟')) return;
            var fd = new FormData();
            fd.append('recurring_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('skip_recurring.php'), { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
                .then(function(r){return r.json();})
                .then(function(d){ if (d.success) { window.reloadPage(); } else { alert(d.message || 'خطا'); } })
                .catch(function(){ alert(netErr()); });
        });
    });

    // ---------- تقویمِ خانه (`includes/home_calendar.php`) ----------
    //
    // ⛔ خانه فقط پوسته را رندر می‌کند (بی‌کوئری) و داده‌ی ماه از
    //    `api/home_calendar.php` می‌آید — همان `homeCalendarHtml()`، پس ماهِ
    //    بعد و ماهِ جاری از یک رندرکننده‌اند. شنونده‌ها روی خودِ کارت‌اند
    //    (نه روی خانه‌ها)، چون درونِ کارت با هر ماه عوض می‌شود.
    // ⚠ `seq`: کاربری که تند «ماهِ بعد» را می‌زند، پاسخِ کندِ ماهِ قبلی را
    //   روی ماهِ تازه نمی‌بیند.
    (function () {
        var cal = document.getElementById('cal');
        if (!cal) return;
        var seq = 0;
        var day = cal.querySelector('.hcal-day');
        var dayTitle = cal.querySelector('.hcal-day-title');
        var dayBody = cal.querySelector('.hcal-day-body');

        function syncToggle() {
            var t = cal.querySelector('.js-hcal-toggle');
            if (t) t.setAttribute('aria-expanded', cal.classList.contains('is-week') ? 'false' : 'true');
        }
        function closeDay() {
            if (day) day.hidden = true;
            cal.querySelectorAll('.hcal-d.is-sel').forEach(function (c) { c.classList.remove('is-sel'); });
        }
        function load(jy, jm) {
            var my = ++seq;
            var inner = cal.querySelector('.hcal-inner');
            if (inner) inner.classList.add('is-loading');
            return fetch(apiUrl('home_calendar.php') + '?jy=' + encodeURIComponent(jy) + '&jm=' + encodeURIComponent(jm),
                         { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
                .then(function (html) {
                    if (my !== seq) return;
                    var box = document.createElement('div');
                    box.innerHTML = html;
                    var fresh = box.querySelector('.hcal-inner');
                    var cur = cal.querySelector('.hcal-inner');
                    if (fresh && cur) cur.parentNode.replaceChild(fresh, cur);
                    syncToggle();
                })
                .catch(function () {
                    var cur = cal.querySelector('.hcal-inner');
                    if (cur && my === seq) cur.classList.remove('is-loading');
                });
        }

        var first = cal.querySelector('.hcal-inner');
        syncToggle();
        if (first) load(first.getAttribute('data-jy'), first.getAttribute('data-jm'));

        cal.addEventListener('click', function (e) {
            var go = e.target.closest('.js-hcal-go');
            if (go) {
                e.preventDefault();
                closeDay();
                // ماهِ دیگر = ماهِ کامل؛ نوارِ هفته فقط هفته‌ی امروز را دارد.
                cal.classList.remove('is-week');
                load(go.getAttribute('data-jy'), go.getAttribute('data-jm'));
                return;
            }
            var tg = e.target.closest('.js-hcal-toggle');
            if (tg) {
                var month = cal.classList.toggle('is-week') === false;
                try { localStorage.setItem('daftar_hcal_month', month ? '1' : '0'); } catch (err) {}
                syncToggle();
                return;
            }
            if (e.target.closest('.js-hcal-close')) { closeDay(); return; }

            var cell = e.target.closest('.js-hcal-day');
            if (!cell || !day || !dayBody) return;
            if (cell.classList.contains('is-sel')) { closeDay(); return; }
            closeDay();
            cell.classList.add('is-sel');
            dayTitle.textContent = cell.getAttribute('data-label') || '';
            dayBody.innerHTML = window.skeletonHtml(2);
            day.hidden = false;
            var date = cell.getAttribute('data-date');
            fetch(apiUrl('day_detail.php') + '?date=' + encodeURIComponent(date), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
                .then(function (html) {
                    if (!cell.classList.contains('is-sel')) return;
                    dayBody.innerHTML = html;
                    dayBody.querySelectorAll('.tx-row-summary').forEach(function (row) {
                        row.addEventListener('click', function (ev) {
                            if (ev.target.closest('button')) return;
                            var parent = this.closest('.tx-row');
                            if (parent) parent.classList.toggle('expanded');
                        });
                    });
                    day.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                })
                .catch(function () {
                    dayBody.innerHTML = '<p class="empty-row" style="color:var(--out);">خطا در بارگذاری.</p>';
                });
        });
    })();

    // ---------- ورود اطلاعات از CSV ----------
    var previewBtn = document.getElementById('previewBtn');
    if (previewBtn && window.IMPORT_ROWS) {
        previewBtn.addEventListener('click', function () {
            var map = {};
            document.querySelectorAll('.map-select').forEach(function (sel) {
                map[sel.getAttribute('data-field')] = parseInt(sel.value, 10);
            });

            if (map.date < 0 || map.amount < 0 || map.type < 0) {
                alert('ستون‌های تاریخ، مبلغ و نوع الزامی هستند.');
                return;
            }

            var valid = [], invalid = [];

            window.IMPORT_ROWS.forEach(function (row, idx) {
                var res = parseRow(row, map);
                if (res.ok) { valid.push(res.data); }
                else { invalid.push({ line: idx + 2, error: res.error }); }
            });

            renderPreview(valid, invalid);
            document.getElementById('importPayload').value = JSON.stringify(valid);
            document.getElementById('previewArea').hidden = false;
            document.getElementById('commitBtn').disabled = (valid.length === 0);
        });
    }

    function cell(row, idx) {
        return (idx >= 0 && row[idx] !== undefined) ? String(row[idx]).trim() : '';
    }

    function parseRow(row, map) {
        // ---- تاریخ ----
        var rawDate = toLatinDigitsJs(cell(row, map.date)).replace(/[\/.]/g, '-');
        var m = rawDate.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (!m) { return { ok: false, error: 'تاریخ نامعتبر: ' + (rawDate || 'خالی') }; }

        var y = parseInt(m[1], 10), mo = parseInt(m[2], 10), d = parseInt(m[3], 10);
        var gDate;
        if (y < 1900) {
            if (mo < 1 || mo > 12 || d < 1 || d > 31) { return { ok: false, error: 'تاریخ شمسی نامعتبر' }; }
            // ⛔ همان یک الگوریتمِ تقویم (`jalali-datepicker.js`، که در `header.php` پیش از این فایل
            //    بار می‌شود)؛ نسخه‌ی کپی‌شده‌ی اینجا هم‌خوانیِ PHP و JS را دو جا می‌کرد.
            var g = window.JalaliDatePicker.jalaliToGregorian(y, mo, d);
            gDate = pad4(g[0]) + '-' + pad2(g[1]) + '-' + pad2(g[2]);
        } else {
            gDate = pad4(y) + '-' + pad2(mo) + '-' + pad2(d);
        }

        // ---- مبلغ ----
        var amount = parseInt(toLatinDigitsJs(cell(row, map.amount)).replace(/[^\d]/g, ''), 10);
        if (!amount || amount <= 0) { return { ok: false, error: 'مبلغ نامعتبر' }; }

        // ---- نوع ----
        var rawType = cell(row, map.type).toLowerCase();
        var type = null;
        ['income', 'درآمد', 'دریافت', 'واریز', '+'].forEach(function (w) {
            if (type === null && rawType.indexOf(w) !== -1) type = 'income';
        });
        if (type === null) {
            ['expense', 'هزینه', 'پرداخت', 'برداشت', '-'].forEach(function (w) {
                if (type === null && rawType.indexOf(w) !== -1) type = 'expense';
            });
        }
        if (type === null) { return { ok: false, error: 'نوع نامشخص: ' + (rawType || 'خالی') }; }

        // ---- عنوان ----
        var title = cell(row, map.title);
        if (!title) { title = (type === 'income' ? 'درآمد واردشده' : 'هزینه واردشده'); }

        // ---- دسته‌بندی ----
        var categoryId = null;
        var catName = cell(row, map.category).toLowerCase();
        if (catName && window.IMPORT_CATEGORIES) {
            window.IMPORT_CATEGORIES.forEach(function (c) {
                if (categoryId === null && c.name.toLowerCase() === catName && c.type === type) {
                    categoryId = c.id;
                }
            });
        }

        // ---- حساب ----
        var walletId = window.IMPORT_DEFAULT_WALLET;
        var wName = cell(row, map.wallet).toLowerCase();
        if (wName && window.IMPORT_WALLETS) {
            window.IMPORT_WALLETS.forEach(function (w) {
                if (w.name === wName) walletId = w.id;
            });
        }

        return {
            ok: true,
            data: {
                transaction_date: gDate,
                amount: amount,
                type: type,
                title: title.substring(0, 255),
                category_id: categoryId,
                wallet_id: walletId,
                note: cell(row, map.note).substring(0, 1000) || null
            }
        };
    }

    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function pad4(n) { return ('000' + n).slice(-4); }

    function renderPreview(valid, invalid) {
        var summary = document.getElementById('importSummary');
        var table = document.getElementById('previewTable');
        if (!summary || !table) return;

        var html = '<div class="alert ' + (invalid.length ? 'alert-info' : 'alert-success') + '">';
        html += toPersianDigitsJs(valid.length) + ' سطر آماده ثبت';
        if (invalid.length) { html += ' · ' + toPersianDigitsJs(invalid.length) + ' سطر نامعتبر (نادیده گرفته می‌شود)'; }
        html += '</div>';

        if (invalid.length) {
            html += '<details class="import-errors"><summary>مشاهده سطرهای نامعتبر</summary>';
            invalid.slice(0, 30).forEach(function (e) {
                // ⛔ پیامِ خطا متنِ خامِ خانه‌ی CSV را دارد («تاریخ نامعتبر: …») — فرار داده می‌شود.
                html += '<div class="import-error-row">سطر ' + toPersianDigitsJs(e.line) + ': ' + escapeHtml(e.error) + '</div>';
            });
            html += '</details>';
        }
        summary.innerHTML = html;

        var rows = '<thead><tr><th>تاریخ</th><th>عنوان</th><th>نوع</th><th>مبلغ</th></tr></thead><tbody>';
        valid.slice(0, 25).forEach(function (v) {
            rows += '<tr>';
            rows += '<td data-label="تاریخ">' + toPersianDigitsJs(v.transaction_date) + '</td>';
            rows += '<td data-label="عنوان">' + escapeHtml(v.title) + '</td>';
            rows += '<td data-label="نوع">' + (v.type === 'income' ? 'درآمد' : 'هزینه') + '</td>';
            rows += '<td data-label="مبلغ">' + fmtMoneyJs(v.amount) + '</td>';
            rows += '</tr>';
        });
        rows += '</tbody>';
        table.innerHTML = rows;
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ---------- پیوست رسید ----------
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-load-attachments');
        if (!btn) return;

        var txId = btn.getAttribute('data-tx-id');
        var box = document.getElementById('attachBox' + txId);

        // اولین بار: باکس را همین‌جا می‌سازیم تا HTML صفحه سبک بماند
        if (!box) {
            box = document.createElement('div');
            box.className = 'attach-box';
            box.id = 'attachBox' + txId;
            box.setAttribute('data-tx-id', txId);
            box.innerHTML =
                '<div class="attach-list"></div>' +
                '<label class="attach-add">' +
                '<input type="file" class="js-attach-input" data-tx-id="' + txId + '" ' +
                'accept="image/jpeg,image/png,image/webp,application/pdf" hidden>' +
                '<span>+ افزودن رسید (عکس یا PDF، حداکثر ۳ مگابایت)</span></label>';
            var host = btn.closest('.tx-row-details') || btn.parentNode;
            host.appendChild(box);
            loadAttachments(txId);
            return;
        }

        if (!box.hidden) { box.hidden = true; return; }
        box.hidden = false;
        loadAttachments(txId);
    });

    function loadAttachments(txId) {
        var box = document.getElementById('attachBox' + txId);
        if (!box) return;
        var list = box.querySelector('.attach-list');
        if (!list) return;

        list.innerHTML = window.skeletonHtml(1);
        fetch(apiUrl('transaction_attachments.php') + '?transaction_id=' + encodeURIComponent(txId))
            .then(function (r) { return r.text(); })
            .then(function (html) { list.innerHTML = html; })
            .catch(function () { list.innerHTML = '<p class="hint">خطا در بارگذاری.</p>'; });
    }

    document.addEventListener('change', function (e) {
        var input = e.target.closest('.js-attach-input');
        if (!input || !input.files || !input.files[0]) return;

        var txId = input.getAttribute('data-tx-id');
        var fd = new FormData();
        fd.append('transaction_id', txId);
        fd.append('csrf_token', csrf());
        fd.append('file', input.files[0]);

        var box = document.getElementById('attachBox' + txId);
        var list = box ? box.querySelector('.attach-list') : null;
        if (list) list.innerHTML = '<p class="hint">در حال آپلود…</p>';

        fetch(apiUrl('upload_attachment.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                input.value = '';
                if (d.success) { loadAttachments(txId); }
                else {
                    alert(d.message || 'خطا در آپلود.');
                    loadAttachments(txId);
                }
            })
            .catch(function () { alert(netErr()); });
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-delete-attachment');
        if (!btn) return;
        if (!confirm('این پیوست حذف شود؟')) return;

        var box = btn.closest('.attach-box');
        var txId = box ? box.getAttribute('data-tx-id') : null;

        var fd = new FormData();
        fd.append('attachment_id', btn.getAttribute('data-id'));
        fd.append('csrf_token', csrf());

        fetch(apiUrl('delete_attachment.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success && txId) { loadAttachments(txId); }
                else if (!d.success) { alert(d.message || 'خطا'); }
            })
            .catch(function () { alert(netErr()); });
    });

    // ---------- حساب کاربری من ----------
    var profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', function (e) {
            e.preventDefault();
            submitJson(profileForm, apiUrl('update_profile.php'),
                document.getElementById('profileMessage'),
                document.getElementById('profileSubmitBtn'));
        });
    }

    var passwordForm = document.getElementById('passwordForm');
    if (passwordForm) {
        passwordForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var np = document.getElementById('pf_new_pass').value;
            var np2 = document.getElementById('pf_new_pass2').value;
            var msg = document.getElementById('passwordMessage');
            if (np !== np2) {
                msg.hidden = false;
                msg.classList.remove('success');
                msg.classList.add('show', 'error');
                msg.textContent = 'رمز جدید و تکرار آن یکسان نیستند.';
                return;
            }
            submitJson(passwordForm, apiUrl('change_password.php'), msg,
                document.getElementById('passwordSubmitBtn'));
        });
    }

    // ماندن در حساب — کلید «همیشه» + چیپ‌های مهلت
    //
    // ---------- یادآوری سررسید ----------
    // همان الگوی «ماندن در حساب»: کلید + چیپ، ذخیره با هر ضربه.
    var remindToggle = document.getElementById('remindToggle');
    if (remindToggle) {
        var remindDays = document.getElementById('remindDays');
        var remindMsg  = document.getElementById('remindMsg');
        var remindCard = remindToggle.closest('.card');
        var remindChips = remindCard ? remindCard.querySelectorAll('.stay-chip[data-days]') : [];

        function remindSave() {
            var active = remindCard.querySelector('.stay-chip[data-days].active');
            var fd = new FormData();
            var token = remindCard.querySelector('input[name="csrf_token"]');
            if (token) { fd.append('csrf_token', token.value); }
            fd.append('email_on', remindToggle.checked ? '1' : '0');
            fd.append('days_before', active ? active.getAttribute('data-days') : '3');

            fetch(apiUrl('update_reminder_pref.php'), {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!remindMsg) { return; }
                    remindMsg.hidden = false;
                    remindMsg.classList.remove('success', 'error');
                    remindMsg.classList.add('show', d.success ? 'success' : 'error');
                    remindMsg.textContent = d.message || (d.success ? 'ذخیره شد.' : 'خطا');
                    if (d.success) { setTimeout(function () { remindMsg.hidden = true; }, 2200); }
                })
                .catch(function () {
                    if (!remindMsg) { return; }
                    remindMsg.hidden = false;
                    remindMsg.classList.add('show', 'error');
                    remindMsg.textContent = 'خطا در ارتباط با سرور.';
                });
        }

        remindToggle.addEventListener('change', function () {
            if (remindDays) { remindDays.classList.toggle('is-open', remindToggle.checked); }
            remindSave();
        });

        remindChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                remindChips.forEach(function (c) { c.classList.remove('active'); });
                chip.classList.add('active');
                // زدنِ یک بازه یعنی «یادآوری را می‌خواهم» — اگر کلید
                // خاموش بود روشنش کن، وگرنه کاربر بازه را انتخاب می‌کرد
                // و هیچ اتفاقی نمی‌افتاد.
                if (!remindToggle.checked) {
                    remindToggle.checked = true;
                    if (remindDays) { remindDays.classList.add('is-open'); }
                }
                remindSave();
            });
        });
    }

    // ⚠ دکمه‌ی «ذخیره» عمداً برداشته شد و هر ضربه بلافاصله ذخیره می‌شود.
    //   با دکمه، کاربر گزینه را می‌زد و می‌رفت و تغییر اعمال نمی‌شد —
    //   یعنی تنظیمی که کار نمی‌کند. اشتباه زدن هم بی‌هزینه است: یک ضربه‌ی
    //   دیگر برش می‌گرداند.
    var stayBox = document.getElementById('stayBox');
    if (stayBox) {
        var stayToggle  = document.getElementById('stayForever');
        var stayChoices = document.getElementById('stayChoices');
        var stayNote    = document.getElementById('stayNote');
        var stayMsg     = document.getElementById('sessionMessage');
        var stayChips   = stayBox.querySelectorAll('.stay-chip');
        // متنِ بخشِ «دستگاه‌های مورد اعتماد» هم همین عدد را می‌گوید؛ اگر
        // به‌روز نشود، کاربر بلافاصله دو حرفِ متناقض روی یک صفحه می‌بیند.
        var stayDevWin  = document.getElementById('deviceWindow');

        // آخرین مهلتِ زمان‌دارِ انتخاب‌شده، تا خاموش کردنِ کلید همان را
        // برگرداند نه یک پیش‌فرضِ دلخواه. بدون این، کاربری که «۸ ساعت»
        // داشت و کلید را روشن و دوباره خاموش می‌کرد، ناگهان روی «۱ دقیقه»
        // می‌افتاد.
        var lastTimed = parseInt(stayBox.getAttribute('data-current'), 10) || 60;

        function stayLabelFor(minutes) {
            var chip = stayBox.querySelector('.stay-chip[data-minutes="' + minutes + '"]');
            return chip ? chip.textContent.trim() : minutes + ' دقیقه';
        }

        function stayRender(minutes) {
            stayChips.forEach(function (c) {
                c.classList.toggle('active', parseInt(c.getAttribute('data-minutes'), 10) === minutes);
            });
            if (minutes === 0) {
                stayChoices.classList.remove('is-open');
                stayNote.innerHTML = 'تا وقتی خودتان <strong>خارج</strong> نشوید، رمز پرسیده نمی‌شود.';
                if (stayDevWin) { stayDevWin.textContent = 'تا وقتی خودتان خارج نشوید'; }
            } else {
                stayChoices.classList.add('is-open');
                stayNote.innerHTML = 'اگر <strong>' + stayLabelFor(minutes) +
                    '</strong> از برنامه استفاده نکنید، دوباره رمز می‌پرسد. ' +
                    'هر بار استفاده، این مهلت را از نو شروع می‌کند.';
                if (stayDevWin) {
                    stayDevWin.textContent = 'تا ' + stayLabelFor(minutes) + ' پس از آخرین استفاده';
                }
            }
        }

        function staySave(minutes) {
            var fd = new FormData();
            fd.append('session_minutes', minutes);
            var token = stayBox.querySelector('input[name="csrf_token"]');
            if (token) { fd.append('csrf_token', token.value); }

            fetch(apiUrl('update_session_pref.php'), {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!stayMsg) { return; }
                    stayMsg.hidden = false;
                    stayMsg.classList.remove('success', 'error');
                    stayMsg.classList.add('show', d.success ? 'success' : 'error');
                    stayMsg.textContent = d.message || (d.success ? 'ذخیره شد.' : 'خطا');
                    if (d.success) {
                        setTimeout(function () { stayMsg.hidden = true; }, 2200);
                    }
                })
                .catch(function () {
                    if (!stayMsg) { return; }
                    stayMsg.hidden = false;
                    stayMsg.classList.add('show', 'error');
                    stayMsg.textContent = 'خطا در ارتباط با سرور.';
                });
        }

        stayToggle.addEventListener('change', function () {
            var minutes = stayToggle.checked ? 0 : lastTimed;
            stayRender(minutes);
            staySave(minutes);
        });

        stayChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                var minutes = parseInt(chip.getAttribute('data-minutes'), 10);
                lastTimed = minutes;
                stayRender(minutes);
                staySave(minutes);
            });
        });

        stayRender(parseInt(stayBox.getAttribute('data-current'), 10) || 0);
    }

    document.querySelectorAll('.js-revoke-device').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('این دستگاه حذف شود؟ دفعه بعد باید رمز وارد کند.')) return;
            var fd = new FormData();
            fd.append('device_id', this.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            fetch(apiUrl('revoke_device.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.success) { window.reloadPage(); } else { alert(d.message || 'خطا'); } })
                .catch(function () { alert(netErr()); });
        });
    });

    var revokeAllBtn = document.getElementById('revokeAllBtn');
    if (revokeAllBtn) {
        revokeAllBtn.addEventListener('click', function () {
            if (!confirm('همه دستگاه‌ها حذف شوند؟ از همه‌جا خارج می‌شوید.')) return;
            var fd = new FormData();
            fd.append('all', '1');
            fd.append('csrf_token', csrf());
            fetch(apiUrl('revoke_device.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.success) { window.location.href = (window.APP_BASE || '') + '/logout.php'; } else { alert(d.message || 'خطا'); } })
                .catch(function () { alert(netErr()); });
        });
    }

    // ---------- قفل اسکرول: فقط لایه‌ی رویی اسکرول شود ----------
    //
    // وقتی شیت یا مودالی باز است، کشیدن انگشت نباید صفحه‌ی زیرش را
    // جابه‌جا کند — کاربر فکر می‌کند دارد فهرست شیت را می‌بندد ولی
    // صفحه‌ی پشت سُر می‌خورد و جای خودش را گم می‌کند. با برگشتن به
    // صفحه، اسکرول دقیقاً از همان‌جا ادامه پیدا می‌کند.
    var scrollLock = { count: 0 };

    // ⛔ قفلِ اسکرول **هیچ چیزی را جابه‌جا نمی‌کند** — و این کلِ نکته است.
    //
    //    نسخه‌ی قبلی `body` را `position: fixed` با `top: -Y` می‌کرد.
    //    راهِ رایجی است، ولی روی آیفون نوارِ پایین را بالا می‌آورد: با
    //    بازِ شدنِ شیت، صفحه دیگر اسکرول‌شدنی نبود، سافاری نوارهای
    //    خودش را دوباره باز می‌کرد، پنجره‌ی دیداری کوتاه می‌شد و هر
    //    عنصرِ `fixed`ی که به کفِ صفحه چسبیده بود با آن بالا می‌پرید.
    //    یک بار هم تلاش کردم با کشیدنِ `min-height` بدنه درستش کنم —
    //    **کار نکرد**، چون فرضش غلط بود: عنصرِ `fixed` نسبت به پنجره
    //    جای می‌گیرد، نه نسبت به جعبه‌ی `body`.
    //
    //    راهِ درست، برداشتنِ صورت‌مسئله است: هیچ ویژگیِ چیدمانی روی
    //    `body` عوض نمی‌شود، پس هیچ چیزی هم نمی‌تواند تکان بخورد.
    //    اسکرولِ پس‌زمینه به‌جایش با دو چیز بسته می‌شود:
    //      • `overflow: hidden` روی html و body (ماوس و کیبورد)
    //      • جلوگیری از `touchmove` روی خودِ لایه (لمس روی iOS، که
    //        `overflow: hidden` به‌تنهایی جلویش را نمی‌گیرد)
    //    و چون صفحه هرگز جابه‌جا نمی‌شود، بازگرداندنِ موقعیتِ اسکرول
    //    هم لازم نیست — یعنی پرشِ هنگام بستن هم از بین می‌رود.
    function lockBodyScroll() {
        if (scrollLock.count++ > 0) return;
        document.documentElement.classList.add('is-locked');
    }
    function unlockBodyScroll() {
        if (scrollLock.count === 0) return;
        if (--scrollLock.count > 0) return;
        document.documentElement.classList.remove('is-locked');
    }

    // لمس روی پس‌زمینه‌ی لایه نباید صفحه‌ی زیرش را بلغزاند، ولی لمس روی
    // بخشِ اسکرول‌شونده‌ی خودِ شیت باید کار کند. پس فقط وقتی جلویش
    // گرفته می‌شود که هیچ نیای اسکرول‌شونده‌ای در مسیرِ لمس نباشد.
    // `passive: false` لازم است، وگرنه `preventDefault` نادیده می‌ماند.
    document.addEventListener('touchmove', function (e) {
        if (!document.documentElement.classList.contains('is-locked')) return;
        var node = e.target;
        while (node && node !== document.body) {
            if (node.scrollHeight > node.clientHeight + 1) {
                var st = window.getComputedStyle(node).overflowY;
                if (st === 'auto' || st === 'scroll') return;
            }
            node = node.parentElement;
        }
        e.preventDefault();
    }, { passive: false });

    // هر لایه‌ای که با کلاس show باز/بسته می‌شود، خودکار قفل را می‌گیرد
    // و پس می‌دهد — بدون اینکه لازم باشد هر جای کد یادش باشد.
    // ---------- معرفیِ اولیه ----------
    //
    // ⛔ فقط وقتی اصلاً وجود دارد که `index.php` رندرش کرده باشد، و آن
    //    هم فقط برای دفترِ خالی. پس اینجا هیچ شرطِ دومی درباره‌ی «کاربرِ
    //    تازه» نوشته نمی‌شود — نسخه‌ی دومِ همان تصمیم، دیر یا زود با
    //    اصلی‌اش اختلاف پیدا می‌کرد.
    //
    // ⛔ و **هر** راهِ خروجی نشانه را می‌زند، نه فقط اسلایدِ آخر: کسی که
    //    وسطش می‌بندد یعنی «نمی‌خواهم». با نشانه‌گذاریِ فقط در پایان،
    //    همان آدم هر بار دوباره همین را می‌دید.
    //
    // ⛔ **هدفِ نورافکن از `data-target`ِ خودِ اسلاید خوانده می‌شود، نه از
    //    یک فهرستِ جاوااسکریپتی.** تنها مرجع `INTRO_SLIDES` در
    //    `includes/intro_sheet.php` است؛ با فهرستِ دوم، اولین باری که یک
    //    انتخابگر عوض شود نورافکن بی‌صدا روی هیچ می‌نشیند و کاربر یک
    //    حفره‌ی خالی وسطِ صفحه می‌بیند — همان قاعده‌ی `DUE_TABS`.
    (function intro() {
        var box = document.getElementById('introModal');
        if (!box) return;

        var slides = box.querySelectorAll('.intro-slide');
        var dots   = box.querySelectorAll('.intro-dot');
        var prev   = document.getElementById('introPrev');
        var next   = document.getElementById('introNext');
        var close  = document.getElementById('introClose');
        var card   = document.getElementById('introCard');
        var spot   = document.getElementById('introSpot');
        var beak   = document.getElementById('introBeak');
        if (!slides.length || !prev || !next || !close || !card || !spot || !beak) return;

        var at = 0;
        var SPOT_PAD = 8;    // حاشیه‌ی حفره دورِ خودِ عنصر
        var CARD_GAP = 12;   // فاصله‌ی کارت تا لبه‌ی حفره
        var CARD_EDGE = 14;  // همان `left/right/bottom: 14px`ِ CSS
        var CARD_MIN = 170;  // ⚠ کفِ ارتفاعِ کارت — پایین‌تر، متن می‌رود زیرِ اسکرول
        var WIDE = 901;      // همان مرزی که `.bottom-nav` با آن کنار می‌رود

        // ⛔ نامزدها **به ترتیبِ خودشان** آزموده می‌شوند، نه به ترتیبِ
        //    سند. همین اپ روی موبایل و دسکتاپ دو چیدمان دارد: دکمه‌ی ثبت
        //    روی موبایل `#addTxBtn`ِ نوارِ پایین است و روی دسکتاپ قلمِ
        //    نوارِ کناری (نوارِ پایین بالای ۹۰۰ پیکسل `display: none`
        //    می‌شود). یک `querySelectorAll` با کلِ فهرست، نتیجه را به
        //    **ترتیبِ سند** می‌دهد نه به ترتیبِ انتخابگرها — و آنجا
        //    نوارِ کناری و متنِ خالیِ صفحه هر دو جلوترند، پس روی موبایل
        //    دکمه‌ی نوارِ پایین **هیچ‌وقت** برنده نمی‌شد. با اندازه‌گیری
        //    دیده شد، نه با خواندنِ کد.
        // ⚠ «دیده‌شدنی» یعنی هم اندازه دارد هم داخلِ پنجره است: عنصرِ
        //   پایین‌ترِ صفحه با قفلِ اسکرولِ لایه دیگر بالا نمی‌آید، پس
        //   نورافکن رویش یعنی حفره‌ای بیرون از صفحه — و کاربر فقط یک
        //   صفحه‌ی تیره می‌دید. در آن حالت اسلاید تمام‌صفحه می‌شود.
        function firstVisible(sel) {
            if (!sel) return null;
            var groups = sel.split(',');
            var vw = window.innerWidth, vh = window.innerHeight;
            for (var g = 0; g < groups.length; g++) {
                var one = groups[g].trim();
                if (!one) continue;
                var list;
                try { list = document.querySelectorAll(one); } catch (e) { continue; }
                for (var i = 0; i < list.length; i++) {
                    var r = list[i].getBoundingClientRect();
                    if (r.width < 8 || r.height < 8) continue;
                    if (r.bottom <= 0 || r.top >= vh) continue;
                    if (r.right <= 0 || r.left >= vw) continue;
                    return { el: list[i], rect: r };
                }
            }
            return null;
        }

        // ⛔ عنصرِ برنده **خودش** علامت می‌خورد، نه اینکه نامش جایی نوشته
        //    شود. تنها مصرف‌کننده‌اش تست است و دلیلش این است که آن باید
        //    بسنجد **کدام** نامزد انتخاب شده، بدونِ اینکه قاعده‌ی ترتیب را
        //    در خودش دوباره پیاده کند — همان دامِ «آزمونی که نسخه‌ی دومِ
        //    خودش را می‌سنجد» که یک بار سرِ بخشِ CSVِ `test_tx_search`
        //    افتاد. با نامِ متنی، دو عنصرِ هم‌کلاس از هم تشخیص داده
        //    نمی‌شدند.
        var marked = null;
        function markHit(el) {
            if (marked && marked !== el) { marked.removeAttribute('data-intro-hit'); }
            marked = el;
            if (el) { el.setAttribute('data-intro-hit', '1'); }
        }

        function place() {
            var sel = slides[at].getAttribute('data-target') || '';
            var hit = firstVisible(sel);
            var r = hit ? hit.rect : null;

            // ⛔ هر چیزی که جاوااسکریپت نوشته پاک می‌شود، وگرنه استایلِ
            //    درون‌خطیِ اسلایدِ قبلی بر `.is-full` (که فقط یک قاعده‌ی
            //    CSS است) برنده می‌شود و کارتِ تمام‌صفحه سرِ جای قبلی
            //    می‌ماند — خرابی‌ای که هیچ خطایی نمی‌دهد.
            card.classList.remove('is-full', 'is-under', 'is-over', 'is-left', 'is-right');
            card.style.top = card.style.bottom = card.style.left = '';
            card.style.maxHeight = '';
            beak.style.top = beak.style.left = '';
            beak.style.display = '';

            if (!r) {
                // بی‌هدف: حفره صفر می‌ماند و همان `box-shadow` کلِ صفحه را
                // تیره می‌کند؛ کارت هم تمام‌صفحه می‌شود.
                markHit(null);
                spot.classList.remove('has-target');
                spot.style.top = '50%';
                spot.style.left = '50%';
                spot.style.width = '0';
                spot.style.height = '0';
                card.classList.add('is-full');
                return;
            }

            var vw = window.innerWidth, vh = window.innerHeight;
            var top  = Math.max(0, r.top - SPOT_PAD);
            var left = Math.max(0, r.left - SPOT_PAD);
            var w = Math.min(vw, r.right + SPOT_PAD) - left;
            var h = Math.min(vh, r.bottom + SPOT_PAD) - top;

            markHit(hit.el);
            spot.classList.add('has-target');
            spot.style.top = top + 'px';
            spot.style.left = left + 'px';
            spot.style.width = w + 'px';
            spot.style.height = h + 'px';

            // ⛔ کارت همیشه **بیرونِ** حفره می‌نشیند، وگرنه دقیقاً چیزی را
            //    می‌پوشاند که دارد نشانش می‌دهد. چهار سمت آزموده می‌شوند و
            //    اولین سمتی که جا دارد برنده است.
            //    ⚠ دو سمتِ افقی فقط روی عرضِ بزرگ ممکن‌اند: زیرِ ۹۰۱ پیکسل
            //      کارت تمام‌عرض است و کنارِ حفره جا نمی‌شود. و لازم هم
            //      هستند — روی دسکتاپ هدفِ «ناوبری» خودِ نوارِ کناری است و
            //      آن از بالا تا پایینِ صفحه کشیده شده، پس نه بالایش جا
            //      هست نه پایینش. اندازه‌گیری شد: بالا ۵۸ و پایین ۱۱۶
            //      پیکسل، هر دو کمتر از کفِ کارت.
            var wide = vw >= WIDE;
            var cw = card.getBoundingClientRect().width;
            var needV = CARD_MIN + CARD_GAP + CARD_EDGE;
            var needH = cw + CARD_GAP + CARD_EDGE;
            var side;
            if (vh - (top + h) >= needV)            { side = 'under'; }
            else if (top >= needV)                  { side = 'over'; }
            else if (wide && vw - (left + w) >= needH) { side = 'right'; }
            else if (wide && left >= needH)         { side = 'left'; }
            else { side = (vh - (top + h) >= top) ? 'under' : 'over'; }
            card.classList.add('is-' + side);

            if (side === 'under' || side === 'over') {
                if (side === 'under') {
                    card.style.top = (top + h + CARD_GAP) + 'px';
                    card.style.bottom = 'auto';
                    card.style.maxHeight =
                        Math.max(CARD_MIN, vh - (top + h + CARD_GAP) - CARD_EDGE) + 'px';
                } else {
                    card.style.bottom = (vh - top + CARD_GAP) + 'px';
                    card.style.top = 'auto';
                    card.style.maxHeight =
                        Math.max(CARD_MIN, top - CARD_GAP - CARD_EDGE) + 'px';
                }
                // روی عرضِ بزرگ کارت هم‌مرکزِ حفره می‌شود، نه وسطِ صفحه:
                // کارتی که وسطِ یک صفحه‌ی ۱۴۰۰ پیکسلی بنشیند و به گوشه‌ی
                // مقابل اشاره کند، اشاره نمی‌کند.
                if (wide) {
                    card.style.left = Math.max(CARD_EDGE,
                        Math.min(vw - cw - CARD_EDGE, (left + w / 2) - cw / 2)) + 'px';
                }
            } else {
                card.style.left = (side === 'right' ? (left + w + CARD_GAP)
                                                    : (left - CARD_GAP - cw)) + 'px';
                var ch = card.getBoundingClientRect().height;
                card.style.top = Math.max(CARD_EDGE,
                    Math.min(vh - ch - CARD_EDGE, (top + h / 2) - ch / 2)) + 'px';
                card.style.bottom = 'auto';
            }

            // نوکِ اشاره: وسطِ همان ضلعِ حفره — ولی فقط اگر واقعاً داخلِ
            // خودِ کارت بیفتد. نوکی که از لبه‌ی کارت بیرون بزند شبیهِ
            // خرابیِ رندر دیده می‌شود، نه شبیهِ اشاره.
            var cr = card.getBoundingClientRect();
            var along, span;
            if (side === 'under' || side === 'over') {
                along = (left + w / 2) - cr.left;
                span = cr.width;
            } else {
                along = (top + h / 2) - cr.top;
                span = cr.height;
            }
            if (along < 18 || along > span - 18) {
                beak.style.display = 'none';
            } else if (side === 'under' || side === 'over') {
                beak.style.left = (along - 7) + 'px';
            } else {
                beak.style.top = (along - 7) + 'px';
            }
        }

        function paint() {
            for (var i = 0; i < slides.length; i++) {
                slides[i].classList.toggle('is-on', i === at);
                if (dots[i]) dots[i].classList.toggle('is-on', i === at);
            }
            // ⚠ «قبلی» روی اسلایدِ اول پنهان می‌شود، نه غیرفعال: دکمه‌ای
            //   که دیده شود و کاری نکند همان «دکمه‌ی بی‌کار».
            // ⛔ و `display` است نه `visibility`: با `visibility` جای دکمه
            //   خالی می‌ماند و روی اسلایدِ **تمام‌صفحه** «بعدی» نصفِ ردیف
            //   را می‌گرفت و کنارش یک حفره‌ی خالی — در اسکرین‌شات دیده شد.
            //   جهشِ چیدمانی‌اش هم اینجا بی‌خطر است: بینِ اسلایدِ اول و
            //   دوم کلِ کارت از تمام‌صفحه به پاپ‌آپ تبدیل می‌شود، پس
            //   چیدمانِ پایداری نیست که با این جابه‌جا شود.
            prev.style.display = at === 0 ? 'none' : '';
            next.textContent = at === slides.length - 1 ? 'شروع کنیم' : 'بعدی';
            place();
        }

        function done() {
            window.introMarkSeen();
            box.classList.remove('show');
            // ⚠ علامت روی عنصرِ هدف است، نه روی لایه؛ با بستنِ معرفی باید
            //   برداشته شود وگرنه تا پایانِ نشست روی DOMِ خودِ اپ می‌ماند.
            markHit(null);
        }

        prev.addEventListener('click', function () {
            if (at > 0) { at--; paint(); }
        });
        next.addEventListener('click', function () {
            if (at < slides.length - 1) { at++; paint(); return; }
            done();
        });
        close.addEventListener('click', done);

        // ⚠ تپ روی خودِ لایه (نه جعبه) هم بستن است — همان رفتاری که
        //   کاربر از هر مودالِ دیگری انتظار دارد. `.intro-spot` عمداً
        //   `pointer-events: none` دارد، پس تپ روی ناحیه‌ی تیره هم به
        //   همین‌جا می‌رسد.
        box.addEventListener('click', function (e) {
            if (e.target === box) done();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && box.classList.contains('show')) done();
        });

        // ⚠ چرخشِ گوشی و باز شدنِ کیبورد جای عنصرِ هدف را عوض می‌کنند؛
        //   بدونِ این، حفره سرِ جای قبلی می‌ماند و روی هیچ می‌نشیند.
        window.addEventListener('resize', function () {
            if (box.classList.contains('show')) place();
        });

        if (!window.introSeen()) {
            box.classList.add('show');
            paint();
        }
    })();

    // ⛔ و همین ناظر «برگشت»ِ اندروید را به لایه گره می‌زند (`reloadPage()`
    //    بالای فایل دلیلش را دارد): بازِ اولین لایه یک خانه در تاریخچه می‌گذارد،
    //    «برگشت» فقط **رویی‌ترین** لایه را می‌بندد، و بستن از راهِ دیگر (×،
    //    پس‌زمینه، Escape) همان خانه را برمی‌دارد تا «برگشت»ِ بعدی صفحه را ببرد.
    (function watchOverlays() {
        var overlays = document.querySelectorAll('.modal-overlay, .sheet-overlay, .more-sheet-overlay');
        if (!overlays.length || !window.MutationObserver) return;
        var KEY = window.OVERLAY_STATE;
        var stack = [];
        var ours = function (st) { return !!(st && st[KEY]); };
        var mark = function () {
            var st = {};
            st[KEY] = true;
            try { history.pushState(st, ''); } catch (e) { /* بی‌تاریخچه: فقط × و پس‌زمینه */ }
        };
        function opened(el) {
            if (stack.indexOf(el) === -1) { stack.push(el); }
            if (!ours(history.state)) { mark(); }
        }
        // ⛔ «همه بسته شد؟» **بعد از** همه‌ی جابه‌جاییِ همان لحظه سنجیده می‌شود، نه
        //    در لحظه‌ی بستن. گزارشِ مالکِ نصب: «تعدیل حساب نمی‌شه کرد» — دکمه‌های
        //    کارتِ حساب (`closeModal('bankCardModal')` و بلافاصله `openModal(…)`)
        //    لایه را عوض می‌کنند؛ ناظرِ لایه‌ی بسته اول اجرا می‌شد، پشته را خالی
        //    می‌دید و `history.back()` می‌زد، و `popstate`ِ دیررسِ آن **لایه‌ی تازه**
        //    را می‌بست. مودال یک لحظه باز و بسته می‌شد، یعنی هیچ‌وقت دیده نمی‌شد.
        var settle = 0;
        function closed(el) {
            var i = stack.indexOf(el);
            if (i !== -1) { stack.splice(i, 1); }
            clearTimeout(settle);
            settle = setTimeout(function () {
                if (!stack.length && ours(history.state) && !window.__hlReloading) { history.back(); }
            }, 0);
        }
        window.addEventListener('popstate', function (e) {
            if (window.__hlReloading) return;
            if (stack.length) {
                var top = stack[stack.length - 1];
                if (stack.length > 1) { mark(); }   // لایه‌ی زیرین هنوز باز است
                top.classList.remove('show');
                return;
            }
            // ⚠ خانه‌ی کهنه (فرمی که با لایه‌ی باز به صفحه‌ی دیگری رفت): ردش کن.
            if (ours(e.state)) { history.back(); }
        });
        overlays.forEach(function (el) {
            var wasOpen = el.classList.contains('show');
            if (wasOpen) { lockBodyScroll(); opened(el); }
            new MutationObserver(function () {
                var isOpen = el.classList.contains('show');
                if (isOpen === wasOpen) return;
                wasOpen = isOpen;
                if (isOpen) { lockBodyScroll(); opened(el); }
                else { unlockBodyScroll(); closed(el); }
            }).observe(el, { attributes: true, attributeFilter: ['class'] });
        });
    })();

    // ---------- شیتِ کفِ صفحه وقتی کیبورد باز است ----------
    // ⛔ گزارشِ مالکِ نصب (اندروید): «این منو که میاد بالا دیگه با دست من بالا
    //    پایین نمیشه». شیتِ ثبت با فوکوسِ مبلغ باز می‌شود و کیبورد بالا می‌آید؛
    //    کرومِ اندروید (`resizes-visual`، مثلِ iOS) فقط viewport **دیداری** را
    //    کوچک می‌کند و لایه‌ی `fixed`ِ `inset: 0` قدِ کاملِ صفحه می‌ماند. پس
    //    پایینِ شیت (حساب، عنوان، دکمه‌ی ثبت) **زیرِ کیبورد** بود و شیت فقط به
    //    اندازه‌ی سرریزِ خودش (روی پیکسل ۷: ۷۱ پیکسل) اسکرول می‌شد — یعنی هیچ‌وقت
    //    به آن‌ها نمی‌رسید، و صفحه‌ی پشت هم قفل بود.
    //    رفع: تا کیبورد باز است، لایه‌ی باز دقیقاً روی viewport دیداری می‌نشیند
    //    و `.sheet` با `max-height: min(…, 100%)` در آن جا می‌شود و تا ته اسکرول
    //    می‌خورد. (`interactive-widget=resizes-content` نه: کلِ صفحه را با هر
    //    کیبورد از نو می‌چید و تشخیصِ کیبوردِ نوارِ پایین را کور می‌کرد.)
    (function fitOverlaysToKeyboard() {
        var vv = window.visualViewport;
        if (!vv) return;
        var KB_MIN = 120;   // همان کفِ `keyboardAwareNav()`
        function fit() {
            var kb = vv.height < window.innerHeight - KB_MIN;
            document.querySelectorAll('.sheet-overlay, .more-sheet-overlay, .modal-overlay').forEach(function (ov) {
                var on = kb && ov.classList.contains('show');
                ov.style.top    = on ? Math.round(vv.offsetTop) + 'px' : '';
                ov.style.height = on ? Math.round(vv.height) + 'px' : '';
                ov.style.bottom = on ? 'auto' : '';
            });
        }
        window.fitOverlaysToKeyboard = fit;
        vv.addEventListener('resize', fit);
        vv.addEventListener('scroll', fit);
    })();

    // ---------- کشیدنِ سرِ شیت به پایین = بستن ----------
    // ⛔ حرکتِ طبیعیِ گوشی برای بستنِ شیت (همان کارِ دستگیره‌ی «بیشتر»)، روی
    //    دستگیره و سرِ شیت — نه روی بدنه‌ی فرم، که کشیدنش اسکرول است.
    document.querySelectorAll('.sheet-overlay').forEach(function (ov) {
        var sheet = ov.querySelector('.sheet');
        if (!sheet) return;
        var y0 = null;
        sheet.querySelectorAll('.more-sheet-handle, .sheet-head').forEach(function (grip) {
            grip.addEventListener('touchstart', function (e) { y0 = e.touches[0].clientY; }, { passive: true });
            grip.addEventListener('touchmove', function (e) {
                if (y0 !== null && sheet.scrollTop <= 0 && e.touches[0].clientY - y0 > 60) {
                    y0 = null;
                    ov.classList.remove('show');
                }
            }, { passive: true });
            grip.addEventListener('touchend', function () { y0 = null; }, { passive: true });
        });
    });

    // ---------- نوارِ پایین وقتی کیبورد باز است ----------
    // ⛔ خرابیِ واقعی که کاربر گزارش کرد: «گاهی منوی پایین می‌رود وسطِ
    //    صفحه». علتش کیبوردِ مجازی است، نه CSS:
    //      • `.bottom-nav` با `position: fixed` نسبت به **viewport
    //        چیدمان** جای می‌گیرد.
    //      • با باز شدنِ کیبورد، iOS (و از کروم ۱۰۸ به بعد اندروید هم،
    //        که پیش‌فرضش `resizes-visual` است) **viewport چیدمان را
    //        کوچک نمی‌کند** — فقط viewport دیداری کوچک می‌شود و صفحه
    //        اسکرول می‌شود تا فیلد دیده شود.
    //      • نتیجه: کفِ viewport چیدمان جایی **وسطِ صفحه‌ی دیداری**
    //        می‌افتد و نوار دقیقاً همان‌جا روی محتوا شناور می‌ماند.
    //    هیچ خطایی نمی‌دهد و در مرورگرِ دسکتاپ اصلاً بازتولید نمی‌شود —
    //    همان درسِ حاشیه‌ی امن و منوی کناری.
    //
    // ⛔ رفعش «جابه‌جا کردنِ نوار» نیست، **برداشتنش** است. جبران‌سازی با
    //    `visualViewport.offsetTop` یعنی نوار با هر ضربه‌ی اسکرول تکان
    //    می‌خورد و همان درسِ قفلِ اسکرول تکرار می‌شود: وقتی جبران‌سازی
    //    جواب نمی‌دهد، صورت‌مسئله را بردارید. ضمناً کاربری که دارد تایپ
    //    می‌کند به ناوبری نیاز ندارد و فضای بیشتری هم به دست می‌آورد.
    (function keyboardAwareNav() {
        var root = document.documentElement;
        var vv = window.visualViewport;

        // فقط فیلدی که واقعاً کیبورد بالا می‌آورد — تعریفش بالای فایل
        // است تا در node آزمودنی بماند (`window.kbNeedsKeyboard`).
        var typing = window.kbNeedsKeyboard;

        // ⚠ کفِ تشخیص: کیبوردِ گوشی دست‌کم یک‌سومِ صفحه را می‌گیرد، ولی
        //   جمع شدنِ نوارِ آدرسِ مرورگر فقط چند ده پیکسل است. با عددِ
        //   کوچک‌تر، اسکرولِ ساده هم «کیبورد» خوانده می‌شد.
        var KB_MIN = 120;

        function set(on) { root.classList.toggle('kb-open', !!on); }

        // ⛔ viewport دیداری دقیق‌ترین نشانه است و تصمیمِ فوکوس را
        //    **تصحیح** می‌کند: با کیبوردِ سخت‌افزاری (آیپد، دسکتاپ) فیلد
        //    فوکوس می‌گیرد ولی هیچ چیزی کوچک نمی‌شود، پس نوار باید بماند.
        function reconcile() {
            if (!typing(document.activeElement)) { set(false); return; }
            set(vv ? vv.height < window.innerHeight - KB_MIN : true);
        }

        document.addEventListener('focusin', function (e) {
            if (!typing(e.target)) return;
            // ⚠ فوراً پنهان می‌شود، نه بعد از سنجش: کیبورد چند صد
            //   میلی‌ثانیه بالا می‌آید و در همان فاصله نوار وسطِ صفحه
            //   دیده می‌شد — یعنی دقیقاً همان چیزی که رفع شده.
            set(true);
            if (vv) { setTimeout(reconcile, 400); }
        });
        // ⚠ با تأخیرِ یک تیک: هنگام جابه‌جا شدنِ فوکوس بینِ دو فیلد،
        //   `focusout` زودتر از `focusin`ِ بعدی می‌آید و بدونِ این تأخیر
        //   نوار یک لحظه ظاهر و دوباره پنهان می‌شد.
        document.addEventListener('focusout', function () {
            setTimeout(function () {
                if (!typing(document.activeElement)) set(false);
            }, 0);
        });

        if (vv) { vv.addEventListener('resize', reconcile); }
    })();

    // ---------- منوی بومیِ <select> دست‌نخورده می‌ماند ----------
    // ⛔ یک بار `optionPicker()` ساخته شد — یک پاپ‌آپِ خودمان روی هر
    // <select> — تا جهتِ راست‌به‌چپ و عرضِ منو را درست کند. کار
    // می‌کرد و در کرومیوم اندازه‌گیری شده بود، ولی مالکِ نصب پسش
    // گرفت: روی گوشی فرم‌ها را شلوغ می‌کرد. این دومین باری است
    // که جعبهٔ خودمان پس گرفته می‌شود (بارِ اول شیتِ کفِ صفحه بود،
    // کامیت `c8a4384`). تاریخچه‌اش در گیت هست.
    //
    // ⚠ هزینه‌اش واقعی است و نوشته می‌ماند: منوی بومیِ iOS باز هم
    // چپ‌چین دیده می‌شود و عرضش را خودِ سیستم تعیین می‌کند — همان
    // حدی که در CLAUDE.md نوشته شده. **اگر روزی دوباره سراغش رفتید،
    // بدانید که دو شکلِ متفاوت امتحان و رد شده‌اند.**

    // ---------- شیت «بیشتر» در ناوبری پایین ----------
    var moreBtn = document.getElementById('moreTabBtn');
    var moreSheet = document.getElementById('moreSheet');
    if (moreBtn && moreSheet) {
        function closeMoreSheet() { moreSheet.classList.remove('show'); }

        // دکمه‌ی «بیشتر» ضامن است نه فقط بازکننده: با زدن دوباره بسته
        // می‌شود. قبلاً فقط باز می‌کرد و چون شیت تا ۸۲٪ صفحه را می‌گیرد،
        // نوار باریکِ بالای آن تنها راه بستن بود — عملاً گیر می‌کرد.
        moreBtn.addEventListener('click', function (e) {
            e.preventDefault();
            moreSheet.classList.toggle('show');
        });
        moreSheet.addEventListener('click', function (e) {
            if (e.target === moreSheet) closeMoreSheet();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMoreSheet();
        });
        // دستگیره و دکمه‌ی بستن، هر دو می‌بندند
        moreSheet.querySelectorAll('.more-sheet-grab, .js-close-more').forEach(function (el) {
            el.addEventListener('click', closeMoreSheet);
        });
        // کشیدن دستگیره به پایین هم می‌بندد — حرکت طبیعی روی گوشی
        var dragStart = null;
        var grip = moreSheet.querySelector('.more-sheet-grab');
        if (grip) {
            grip.addEventListener('touchstart', function (e) { dragStart = e.touches[0].clientY; }, { passive: true });
            grip.addEventListener('touchmove', function (e) {
                if (dragStart !== null && e.touches[0].clientY - dragStart > 45) {
                    closeMoreSheet();
                    dragStart = null;
                }
            }, { passive: true });
            grip.addEventListener('touchend', function () { dragStart = null; }, { passive: true });
        }
    }

    // ---------- زیرشیت مدیریت ----------
    var adminSheet = document.getElementById('adminSheet');
    if (adminSheet) {
        function closeAdminSheet() { adminSheet.classList.remove('show'); }
        document.querySelectorAll('.js-open-admin').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (moreSheet) moreSheet.classList.remove('show');
                adminSheet.classList.add('show');
            });
        });
        adminSheet.addEventListener('click', function (e) {
            if (e.target === adminSheet) closeAdminSheet();
        });
        adminSheet.querySelectorAll('.more-sheet-grab, .js-close-admin').forEach(function (el) {
            el.addEventListener('click', closeAdminSheet);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAdminSheet();
        });
    }

    // ---------- کلید حالت شب / روز ----------
    var themeMedia = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function themeMode() {
        try { return localStorage.getItem('daftar_theme') || 'auto'; }
        catch (e) { return 'auto'; }
    }

    function applyTheme(mode) {
        var sysDark = themeMedia ? themeMedia.matches : false;
        var dark = (mode === 'dark') || (mode === 'auto' && sysDark);
        if (dark) { document.documentElement.setAttribute('data-theme', 'dark'); }
        else { document.documentElement.removeAttribute('data-theme'); }
        document.documentElement.setAttribute('data-theme-mode', mode);
        try { localStorage.setItem('daftar_theme', mode); } catch (e) {}

        var autoBox = document.getElementById('themeAuto');
        if (autoBox) { autoBox.checked = (mode === 'auto'); }

        // نمودارها رنگشان را در جاوااسکریپت گرفته‌اند و خودشان خبر ندارند
        window.__repaintThemedCharts();
        syncThemeColorMeta();
    }

    // ---------- پالتِ رنگ (پروفایل → «نمایش») ----------
    // ⛔ رنگِ نوارِ وضعیتِ گوشی از خودِ `--brand`ِ محاسبه‌شده خوانده
    //    می‌شود، نه از یک نگاشتِ دومِ جاوااسکریپتی: با نگاشتِ دوم، اولین
    //    پالتِ تازه‌ی CSS نوار را بی‌صدا روی رنگِ قبلی نگه می‌داشت.
    //    متای ایستای سرآیند (قاعده ۵۷) همان زمرد می‌ماند؛ این فقط وقتی
    //    چیزی عوض می‌کند که پالت یا حالت شب عوض شده باشد.
    function syncThemeColorMeta() {
        var meta = document.querySelector('meta[name="theme-color"]');
        if (!meta) return;
        var c = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim();
        if (c) meta.setAttribute('content', c);
    }

    var palettePicker = document.getElementById('palettePicker');
    if (palettePicker) {
        // ⛔ نامِ پیش‌فرض از خودِ صفحه می‌آید (اولین کلیدِ UI_PALETTES)، نه
        //    یک رشته‌ی سخت‌کد — پیش‌فرض یک بار از زمرد به نیلی رفت و با نامِ
        //    سخت‌کد، زدنِ «نیلی» ویژگی می‌نوشت و زدنِ «زمرد» پاکش می‌کرد.
        var defPal = palettePicker.getAttribute('data-default') || '';
        var curPal = document.documentElement.getAttribute('data-palette') || defPal;
        // مقدارِ ذخیره‌شده‌ای که سرآیند نپذیرفت (پالتِ حذف‌شده، دست‌کاری) همین‌جا
        // پاک می‌شود؛ وگرنه زدنِ پیش‌فرض — که از قبل انتخاب است — `change` نمی‌دهد
        // و آن مقدار تا ابد در مرورگر می‌ماند.
        if (curPal === defPal) { try { localStorage.removeItem('daftar_palette'); } catch (e) {} }
        palettePicker.querySelectorAll('input[name="ui_palette"]').forEach(function (r) {
            r.checked = (r.value === curPal);
            r.addEventListener('change', function () {
                if (!this.checked) return;
                // ⛔ پیش‌فرض ویژگی نمی‌گیرد و کلید هم پاک می‌شود، نه نوشتنِ نامش
                //    — تا پیش‌فرضِ فردا (اگر عوض شد) به همه برسد.
                if (this.value === defPal) {
                    document.documentElement.removeAttribute('data-palette');
                    try { localStorage.removeItem('daftar_palette'); } catch (e) {}
                } else {
                    document.documentElement.setAttribute('data-palette', this.value);
                    try { localStorage.setItem('daftar_palette', this.value); } catch (e) {}
                }
                window.__repaintThemedCharts();
                syncThemeColorMeta();
            });
        });
    }
    syncThemeColorMeta();

    // وقتی گوشی بین حالت شب و روز جابه‌جا می‌شود، اپ هم زنده عوض شود
    if (themeMedia) {
        var onSysChange = function () {
            if (themeMode() === 'auto') { applyTheme('auto'); }
        };
        if (themeMedia.addEventListener) { themeMedia.addEventListener('change', onSysChange); }
        else if (themeMedia.addListener) { themeMedia.addListener(onSysChange); }
    }

    var themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            // با زدن دکمه، از حالت خودکار خارج می‌شویم و صریح انتخاب می‌کنیم
            var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            applyTheme(isDark ? 'light' : 'dark');
        });
    }

    var themeAuto = document.getElementById('themeAuto');
    if (themeAuto) {
        themeAuto.checked = (themeMode() === 'auto');
        themeAuto.addEventListener('change', function () {
            if (this.checked) {
                applyTheme('auto');
            } else {
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                applyTheme(isDark ? 'dark' : 'light');
            }
        });
    }

    // ---------- عکس پروفایل ----------
    // ---------- تنظیم و بارگذاری تصویر پروفایل ----------
    //
    // تصویر گالری گوشی تقریباً هیچ‌وقت مربع نیست و صورت آدم هم وسط کادر
    // نیست. پیش از این هرچه انتخاب می‌شد مستقیم بالا می‌رفت و سرور وسط
    // را می‌برید — نتیجه‌اش اغلب نیمی از سر بود. حالا کاربر خودش قاب را
    // می‌بندد و همان چیزی که در دایره می‌بیند ذخیره می‌شود.
    //
    // بدون کتابخانه: یک <canvas> که تصویر را با مقیاس و جابه‌جایی
    // می‌کشد، کشیدن با یک انگشت/ماوس، بزرگ‌نمایی با نوار یا دو انگشت.
    var avatarInput = document.getElementById('avatarInput');
    var cropModal   = document.getElementById('avatarCropModal');

    if (avatarInput && cropModal) {
        var stage    = document.getElementById('cropStage');
        var canvas   = document.getElementById('cropCanvas');
        var zoomEl   = document.getElementById('cropZoom');
        var msgEl    = document.getElementById('avatarMessage');
        var saveBtn  = document.getElementById('cropSave');
        var ctx      = canvas.getContext('2d');

        var OUT = 512;              // اندازه‌ی تصویر ذخیره‌شده (مربع)
        var img = null;             // تصویر بارگذاری‌شده
        var minScale = 1, scale = 1, offX = 0, offY = 0;

        function stageSize() {
            // بوم را با اندازه‌ی واقعی پیکسلی صحنه هماهنگ می‌کنیم تا روی
            // صفحه‌های رتینا تار نشود
            var r = stage.getBoundingClientRect();
            var dpr = Math.min(window.devicePixelRatio || 1, 2);
            var css = Math.max(1, Math.round(r.width));
            if (canvas.width !== Math.round(css * dpr)) {
                canvas.width  = Math.round(css * dpr);
                canvas.height = Math.round(css * dpr);
            }
            return { css: css, dpr: dpr };
        }

        // جابه‌جایی نباید بگذارد لبه‌ی تصویر داخل دایره بیفتد
        function clamp() {
            var s = stageSize().css;
            var w = img.naturalWidth * scale, h = img.naturalHeight * scale;
            var maxX = Math.max(0, (w - s) / 2), maxY = Math.max(0, (h - s) / 2);
            offX = Math.min(maxX, Math.max(-maxX, offX));
            offY = Math.min(maxY, Math.max(-maxY, offY));
        }

        function draw() {
            if (!img) return;
            var d = stageSize(), s = d.css;
            clamp();
            ctx.setTransform(d.dpr, 0, 0, d.dpr, 0, 0);
            ctx.clearRect(0, 0, s, s);
            var w = img.naturalWidth * scale, h = img.naturalHeight * scale;
            ctx.drawImage(img, (s - w) / 2 + offX, (s - h) / 2 + offY, w, h);
        }

        function fit() {
            var s = stageSize().css;
            // کوچک‌ترین مقیاسی که تصویر تمام صحنه را بپوشاند
            minScale = Math.max(s / img.naturalWidth, s / img.naturalHeight);
            scale = minScale; offX = 0; offY = 0;
            zoomEl.min = String(minScale);
            zoomEl.max = String(minScale * 4);
            zoomEl.step = String(minScale / 100);
            zoomEl.value = String(scale);
            draw();
        }

        function setScale(next, cx, cy) {
            next = Math.min(minScale * 4, Math.max(minScale, next));
            if (next === scale) return;
            // بزرگ‌نمایی حول نقطه‌ی مرکزِ اشاره، نه گوشه‌ی تصویر
            if (typeof cx === 'number') {
                var k = next / scale;
                offX = cx - k * (cx - offX);
                offY = cy - k * (cy - offY);
            }
            scale = next;
            zoomEl.value = String(scale);
            draw();
        }

        function showMsg(text, kind) {
            if (!msgEl) return;
            msgEl.hidden = false;
            msgEl.classList.remove('success', 'error');
            msgEl.classList.add('show');
            if (kind) msgEl.classList.add(kind);
            msgEl.textContent = text;
        }

        function closeCrop() {
            cropModal.classList.remove('show');
            img = null;
            avatarInput.value = '';   // تا انتخاب دوباره‌ی همان فایل هم change بدهد
        }

        // ⛔ تصویر با FileReader و آدرسِ data: خوانده می‌شود، نه با
        // URL.createObjectURL. سیاستِ CSP سایت فقط `img-src 'self' data:` را
        // اجازه می‌دهد و آدرسِ blob: را می‌بندد؛ با createObjectURL مرورگر
        // تصویر را **بی‌صدا** بار نمی‌کرد و هر عکسی — از هر گوشی‌ای — پیامِ
        // «تصویر معتبری نیست» می‌گرفت. قاعده ۵۹ برگشتش را می‌بندد.
        //
        // هر نوعی که مرورگر بتواند باز کند پذیرفته است (HEIC روی آیفون هم)،
        // چون سرور هرگز فایلِ خام را نمی‌بیند: همین‌جا روی بوم کشیده و
        // JPEG می‌شود.
        avatarInput.addEventListener('change', function () {
            if (!this.files || !this.files[0]) return;
            var file = this.files[0];

            if (file.type && file.type.indexOf('image/') !== 0) {
                showMsg('فایلِ انتخاب‌شده عکس نیست.', 'error');
                closeCrop();
                return;
            }

            var reader = new FileReader();
            reader.onload = function () {
                var probe = new Image();
                probe.onload = function () {
                    img = probe;
                    cropModal.classList.add('show');
                    // صحنه باید اول در DOM دیده شود تا اندازه‌اش را بدانیم
                    requestAnimationFrame(fit);
                };
                probe.onerror = function () {
                    showMsg('مرورگرِ شما این نوع عکس را باز نمی‌کند (مثلاً HEIC روی بعضی اندرویدها). یک عکسِ JPG یا PNG انتخاب کنید یا از دوربین عکس بگیرید.', 'error');
                    closeCrop();
                };
                probe.src = String(reader.result || '');
            };
            reader.onerror = function () {
                showMsg('خواندنِ فایل ممکن نشد. دوباره انتخاب کنید.', 'error');
                closeCrop();
            };
            reader.readAsDataURL(file);
        });

        // ---- کشیدن با انگشت یا ماوس ----
        var pointers = {}, pinchStart = 0, scaleStart = 1;

        stage.addEventListener('pointerdown', function (e) {
            stage.setPointerCapture(e.pointerId);
            pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
            if (Object.keys(pointers).length === 2) {
                var p = Object.keys(pointers).map(function (k) { return pointers[k]; });
                pinchStart = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
                scaleStart = scale;
            }
        });

        stage.addEventListener('pointermove', function (e) {
            var prev = pointers[e.pointerId];
            if (!prev || !img) return;
            var ids = Object.keys(pointers);

            if (ids.length === 2 && pinchStart > 0) {
                pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
                var p = ids.map(function (k) { return pointers[k]; });
                var dist = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
                if (dist > 0) setScale(scaleStart * (dist / pinchStart));
                return;
            }

            offX += e.clientX - prev.x;
            offY += e.clientY - prev.y;
            pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
            draw();
        });

        function endPointer(e) {
            delete pointers[e.pointerId];
            if (Object.keys(pointers).length < 2) pinchStart = 0;
        }
        stage.addEventListener('pointerup', endPointer);
        stage.addEventListener('pointercancel', endPointer);

        stage.addEventListener('wheel', function (e) {
            if (!img) return;
            e.preventDefault();
            setScale(scale * (e.deltaY < 0 ? 1.08 : 1 / 1.08));
        }, { passive: false });

        zoomEl.addEventListener('input', function () { setScale(parseFloat(this.value)); });
        document.getElementById('cropZoomIn').addEventListener('click', function () { setScale(scale * 1.15); });
        document.getElementById('cropZoomOut').addEventListener('click', function () { setScale(scale / 1.15); });
        document.getElementById('cropReset').addEventListener('click', function () { if (img) fit(); });
        document.getElementById('cropCancel').addEventListener('click', closeCrop);
        cropModal.addEventListener('click', function (e) { if (e.target === cropModal) closeCrop(); });
        window.addEventListener('resize', function () { if (img) draw(); });

        // ---- برش و بارگذاری ----
        saveBtn.addEventListener('click', function () {
            if (!img) return;
            var s = stageSize().css;

            var out = document.createElement('canvas');
            out.width = OUT; out.height = OUT;
            var octx = out.getContext('2d');
            // JPEG شفافیت ندارد؛ بدون این، بخش‌های شفاف سیاه درمی‌آیند
            octx.fillStyle = '#ffffff';
            octx.fillRect(0, 0, OUT, OUT);

            // همان تبدیلی که روی صحنه دیده می‌شود، در مقیاس خروجی
            var k = OUT / s;
            var w = img.naturalWidth * scale * k, h = img.naturalHeight * scale * k;
            octx.drawImage(img, (OUT - w) / 2 + offX * k, (OUT - h) / 2 + offY * k, w, h);

            saveBtn.disabled = true;
            showMsg('در حال بارگذاری…');

            out.toBlob(function (blob) {
                if (!blob) {
                    saveBtn.disabled = false;
                    showMsg('ساخت تصویر ناموفق بود.', 'error');
                    return;
                }
                var fd = new FormData();
                fd.append('avatar', blob, 'avatar.jpg');
                fd.append('csrf_token', csrf());

                fetch(apiUrl('upload_avatar.php'), {
                    method: 'POST', body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.success) {
                            window.reloadPage();
                        } else {
                            saveBtn.disabled = false;
                            showMsg(d.message || 'خطا در بارگذاری.', 'error');
                            closeCrop();
                        }
                    })
                    .catch(function () {
                        saveBtn.disabled = false;
                        showMsg('خطا در ارتباط با سرور.', 'error');
                        closeCrop();
                    });
            }, 'image/jpeg', 0.9);
        });
    }

    // ---------- عددِ اعلان روی آیکونِ اپ ----------
    // ⛔ «نوتیف روی آیکون مثل سایر اپ‌ها». عدد همان `data-unread`ِ زنگِ سرآیند
    //    است (هیچ کوئریِ تازه‌ای نیست). ⚠ روی آیفون (PWAِ نصب‌شده، iOS 16.4+
    //    و با اجازه‌ی اعلان) و دسکتاپ کار می‌کند؛ روی اندروید خودِ سیستم از
    //    روی اعلان‌ها یک نقطه روی آیکون می‌گذارد.
    (function syncAppBadge() {
        var bell = document.querySelector('[data-unread]');
        if (!bell || !('setAppBadge' in navigator)) return;
        var n = parseInt(bell.getAttribute('data-unread'), 10) || 0;
        try {
            var p = n > 0 ? navigator.setAppBadge(n) : navigator.clearAppBadge();
            if (p && p.catch) p.catch(function () {});
        } catch (e) {}
    })();

    // کلیدِ VAPID (base64url) ← بایت، و کلیدهای اشتراک ← base64url. یک نسخه
    // برای هر دو مسیرِ اشتراک (خودکار، و کارتِ پروفایل).
    function pushKeyBytes(b64) {
        var pad = '='.repeat((4 - b64.length % 4) % 4);
        var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
        return out;
    }
    function pushB64(buf) {
        var str = '', a = new Uint8Array(buf);
        for (var i = 0; i < a.length; i++) { str += String.fromCharCode(a[i]); }
        return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    // ---------- اعلان روی گوشی: اشتراکِ خودکار (پیش‌فرض روشن) ----------
    // ⛔ تصمیم از `window.pushAutoAction()` می‌آید؛ اینجا فقط اجرا می‌شود.
    //    کلیدِ عمومیِ VAPID از `push_subscribe.php` (action=key) گرفته
    //    می‌شود تا هیچ صفحه‌ای کوئری یا خواندنِ فایلِ تازه نگیرد — فقط
    //    دستگاهی که هنوز اشتراک ندارد، یک بار.
    (function () {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { return; }
        if (!document.querySelector('meta[name="csrf-token"]')) { return; }   // فقط صفحه‌های داخلی
        var off = null, asked = null;
        try { off = localStorage.getItem(PUSH_OFF_KEY); asked = localStorage.getItem(PUSH_ASKED_KEY); }
        catch (e) { return; }
        var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
        // ⚠ یک تلاش در هر نشستِ مرورگر: اگر شبکه نبود، بارگذاریِ بعدیِ
        //   همین نشست دوباره امتحان نمی‌کند (هر صفحه یک درخواستِ بی‌حاصل).
        try { if (sessionStorage.getItem('daftar_push_try') === '1') { return; } } catch (e) { /* ادامه */ }

        var post = function (fields) {
            var fd = new FormData();
            fd.append('csrf_token', csrf());
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
            return fetch(apiUrl('push_subscribe.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); });
        };
        var subscribe = function () {
            try { sessionStorage.setItem('daftar_push_try', '1'); } catch (e) { /* ناشناس */ }
            var reg;
            return navigator.serviceWorker.ready.then(function (r) {
                reg = r;
                return reg.pushManager.getSubscription();
            }).then(function (sub) {
                if (sub) { return null; }   // از قبل روشن است
                return post({ action: 'key' }).then(function (d) {
                    if (!d || !d.success || !d.key) { return null; }
                    return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: pushKeyBytes(d.key) })
                        .then(function (s) {
                            return post({ endpoint: s.endpoint, p256dh: pushB64(s.getKey('p256dh')), auth: pushB64(s.getKey('auth')) });
                        });
                });
            }).catch(function () { /* بی‌صدا: کارتِ پروفایل وضعیتِ واقعی را می‌گوید */ });
        };

        var act = window.pushAutoAction(Notification.permission, off, asked, standalone);
        if (act === 'subscribe') { subscribe(); return; }
        if (act !== 'ask') { return; }
        // ⚠ با اولین تپ، نه در بارگذاری: درخواستِ بی‌حرکتِ کاربر رد می‌شود.
        document.addEventListener('click', function () {
            try { localStorage.setItem(PUSH_ASKED_KEY, '1'); } catch (e) { /* ناشناس */ }
            Promise.resolve(Notification.requestPermission()).then(function (p) {
                if (p === 'granted') { subscribe(); }
            }).catch(function () {});
        }, { once: true, capture: true });
    })();

    // ---------- اعلان روی گوشی (Web Push) ----------
    var pushCard = document.getElementById('pushCard');
    if (pushCard) {
        var pState = document.getElementById('pushState');
        var pOn    = document.getElementById('pushOn');
        var pOff   = document.getElementById('pushOff');
        var pTest  = document.getElementById('pushTest');
        var pMsg   = document.getElementById('pushMsg');
        var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
        var isIOS = /iP(hone|ad|od)/.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

        var pSay = function (text, kind) {
            pMsg.hidden = false;
            pMsg.classList.remove('success', 'error');
            pMsg.classList.add('show');
            if (kind) pMsg.classList.add(kind);
            pMsg.textContent = text;
        };
        var pShow = function (on, off, test) { pOn.hidden = !on; pOff.hidden = !off; pTest.hidden = !test; };
        var post = function (name, fields) {
            var fd = new FormData();
            fd.append('csrf_token', csrf());
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
            return fetch(apiUrl(name), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); });
        };

        var supported = ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
        var refresh = function () {
            if (!supported) {
                // ⛔ دکمه‌ای که کار نمی‌کند رندر نمی‌شود؛ به‌جایش علت گفته می‌شود.
                pState.textContent = (isIOS && !standalone)
                    ? 'روی آیفون، اعلان فقط وقتی کار می‌کند که اپ از آیکونِ صفحه‌ی اصلی باز شده باشد: در سافاری «اشتراک‌گذاری» ← «افزودن به صفحه‌ی اصلی».'
                    : 'این مرورگر اعلانِ وب را پشتیبانی نمی‌کند. با کروم یا اپِ اندرویدِ حساب لند باز کنید.';
                pShow(false, false, false);
                return;
            }
            if (Notification.permission === 'denied') {
                pState.textContent = 'اجازه‌ی اعلان برای این برنامه بسته است. از تنظیماتِ گوشی ← برنامه‌ها ← حساب لند (یا کروم) ← اعلان‌ها بازش کنید.';
                pShow(false, false, false);
                return;
            }
            navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription();
            }).then(function (sub) {
                if (sub) {
                    pState.textContent = '✓ اعلان روی این دستگاه روشن است.';
                    pShow(false, true, true);
                } else {
                    pState.textContent = 'اعلان روی این دستگاه خاموش است.';
                    pShow(true, false, false);
                }
            }).catch(function () {
                pState.textContent = 'اعلان روی این دستگاه خاموش است.';
                pShow(true, false, false);
            });
        };

        pOn.addEventListener('click', function () {
            pOn.disabled = true;
            try { localStorage.removeItem(PUSH_OFF_KEY); } catch (e) { /* ناشناس */ }
            // ⛔ اجازه باید **مستقیم** از همین تپ خواسته شود؛ مرورگرها درخواستِ
            //    بی‌حرکتِ کاربر را بی‌صدا رد می‌کنند.
            Promise.resolve(Notification.requestPermission()).then(function (perm) {
                if (perm !== 'granted') { throw new Error('perm'); }
                return navigator.serviceWorker.ready;
            }).then(function (reg) {
                return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: pushKeyBytes(pushCard.getAttribute('data-key')) });
            }).then(function (sub) {
                return post('push_subscribe.php', {
                    endpoint: sub.endpoint,
                    p256dh: pushB64(sub.getKey('p256dh')),
                    auth: pushB64(sub.getKey('auth'))
                });
            }).then(function (d) {
                pSay(d.message || (d.success ? 'روشن شد.' : 'روشن نشد.'), d.success ? 'success' : 'error');
            }).catch(function (e) {
                pSay(e && e.message === 'perm'
                    ? 'اجازه داده نشد. هر وقت خواستید دوباره همین دکمه را بزنید.'
                    : 'روشن کردن ممکن نشد — اینترنتِ گوشی را بررسی کنید و دوباره امتحان کنید.', 'error');
            }).then(function () { pOn.disabled = false; refresh(); });
        });

        pOff.addEventListener('click', function () {
            // ⛔ خاموشِ **صریح** ثبت می‌شود، وگرنه اشتراکِ خودکار در صفحه‌ی
            //    بعد دوباره روشنش می‌کرد — یعنی دکمه‌ای که کار نمی‌کند.
            try { localStorage.setItem(PUSH_OFF_KEY, '1'); } catch (e) { /* ناشناس */ }
            navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription();
            }).then(function (sub) {
                if (!sub) return null;
                var ep = sub.endpoint;
                return sub.unsubscribe().then(function () { return post('push_subscribe.php', { action: 'unsubscribe', endpoint: ep }); });
            }).then(function () { pSay('اعلان روی این دستگاه خاموش شد.', 'success'); })
              .catch(function () { pSay(netErr(), 'error'); })
              .then(refresh);
        });

        pTest.addEventListener('click', function () {
            pTest.disabled = true;
            post('push_test.php', {}).then(function (d) {
                pSay(d.message || '', d.success ? 'success' : 'error');
            }).catch(function () { pSay(netErr(), 'error'); })
              .then(function () { pTest.disabled = false; });
        });

        refresh();
    }

    var avatarDeleteBtn = document.getElementById('avatarDeleteBtn');
    if (avatarDeleteBtn) {
        avatarDeleteBtn.addEventListener('click', function () {
            if (!confirm('تصویر پروفایل حذف شود؟')) return;
            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fetch(apiUrl('delete_avatar.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.success) { window.reloadPage(); } else { alert(d.message || 'خطا'); } })
                .catch(function () { alert(netErr()); });
        });
    }

    // ---------- مدیریت لیست‌های مرجع (بانک‌ها / انواع دارایی) ----------

    // ---------- نرخِ روزِ نوع دارایی ----------
    var assetPriceModal = document.getElementById('assetPrice');
    if (assetPriceModal) {
        setupAmountFormatter('assetPriceValue');
        var apId    = document.getElementById('assetPriceId');
        var apVal   = document.getElementById('assetPriceValue');
        var apUnit  = document.getElementById('assetPriceUnit');
        var apTitle = document.getElementById('assetPriceTitle');
        var apMsg   = document.getElementById('assetPriceMessage');
        var apBtn   = document.getElementById('assetPriceSubmitBtn');
        // نرخِ خودکار (`includes/rates.php`) — وصل که شد خانه‌ی دستی کنار می‌رود.
        var apRate  = document.getElementById('assetPriceRate');
        var apRateHint = document.getElementById('assetPriceRateHint');
        var apManual = document.getElementById('assetPriceManual');
        function apSyncRate() {
            var on = !!(apRate && apRate.value);
            if (apManual) { apManual.hidden = on; }
            if (apRateHint) { apRateHint.hidden = !on; }
        }
        if (apRate) { apRate.addEventListener('change', apSyncRate); }

        document.querySelectorAll('.js-asset-price').forEach(function (btn) {
            btn.addEventListener('click', function () {
                apId.value = btn.getAttribute('data-id');
                apTitle.textContent = 'نرخ روز — ' + btn.getAttribute('data-name');
                apUnit.textContent = btn.getAttribute('data-unit') || 'واحد';
                var p = parseInt(btn.getAttribute('data-price'), 10) || 0;
                apVal.value = p > 0 ? p.toLocaleString('en-US') : '';
                if (apRate) { apRate.value = btn.getAttribute('data-rate') || ''; apSyncRate(); }
                if (apMsg) { apMsg.hidden = true; apMsg.classList.remove('show', 'success', 'error'); }
                if (apBtn) { apBtn.disabled = false; }
                assetPriceModal.classList.add('show');
            });
        });

        document.getElementById('assetPriceForm').addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('type_id', apId.value);
            // ارقام فارسی و جداکننده‌ها پاک می‌شوند؛ سمتِ سرور هم
            // sanitizeAmount دوباره همین کار را می‌کند.
            fd.append('price', toLatinDigitsJs(apVal.value).replace(/[,٬\s]/g, ''));
            if (apRate) { fd.append('rate_code', apRate.value); }
            if (apBtn) { apBtn.disabled = true; }

            fetch(apiUrl('update_asset_price.php'), {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) { window.reloadPage(); return; }
                    if (apMsg) {
                        apMsg.hidden = false;
                        apMsg.classList.add('show', 'error');
                        apMsg.textContent = d.message || 'خطایی رخ داد.';
                    }
                    if (apBtn) { apBtn.disabled = false; }
                })
                .catch(function () {
                    if (apMsg) {
                        apMsg.hidden = false;
                        apMsg.classList.add('show', 'error');
                        apMsg.textContent = 'خطا در ارتباط با سرور.';
                    }
                    if (apBtn) { apBtn.disabled = false; }
                });
        });
    }

    // افزودنِ یک‌جای دسته‌های پیشنهادیِ خانوار. یک درخواست، نه ۱۶ تا.
    var suggestBtn = document.getElementById('addSuggestedCats');
    if (suggestBtn) {
        suggestBtn.addEventListener('click', function () {
            suggestBtn.disabled = true;
            suggestBtn.textContent = 'در حال افزودن…';

            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('kind', 'category');
            fd.append('action', 'add_suggested');

            fetch(apiUrl('manage_reference.php'), {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) {
                        alert(d.message || 'خطا');
                        suggestBtn.disabled = false;
                        suggestBtn.textContent = 'افزودن یک‌جا';
                        return;
                    }
                    // تازه‌سازی لازم است: همین دسته‌ها باید فوراً در
                    // شیت ثبت و بودجه و گزارش دیده شوند، و خودِ ردیفِ
                    // پیشنهاد هم باید ناپدید شود.
                    window.reloadPage();
                })
                .catch(function () {
                    alert(netErr());
                    suggestBtn.disabled = false;
                    suggestBtn.textContent = 'افزودن یک‌جا';
                });
        });
    }

    document.querySelectorAll('.js-ref-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var kind = this.getAttribute('data-kind');
            var input = document.getElementById(this.getAttribute('data-input'));
            if (!input) return;
            var name = input.value.trim();
            if (name === '') { alert('نام را وارد کنید.'); return; }

            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('kind', kind);
            fd.append('action', 'add');
            fd.append('name', name);

            // دسته‌بندی درآمد و هزینه دو فهرست جدا هستند و سرور باید
            // بداند این نام برای کدام‌شان است
            var refType = this.getAttribute('data-type');
            if (refType) { fd.append('type', refType); }

            var unitInputId = this.getAttribute('data-unit-input');
            if (unitInputId) {
                var unitInput = document.getElementById(unitInputId);
                if (unitInput && unitInput.value.trim() !== '') {
                    fd.append('unit', unitInput.value.trim());
                }
            }

            // سمتِ شخص (همکار / مشتری / …). اختیاری است؛ سرور خالی را
            // «سایر» می‌گیرد.
            var roleInputId = this.getAttribute('data-role-input');
            if (roleInputId) {
                var roleInput = document.getElementById(roleInputId);
                if (roleInput && roleInput.value.trim() !== '') {
                    fd.append('role', roleInput.value.trim());
                }
            }

            fetch(apiUrl('manage_reference.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) { window.reloadPage(); }
                    else { alert(d.message || 'خطایی رخ داد.'); }
                })
                .catch(function () { alert(netErr()); });
        });
    });

    // ---------- پینِ دسته‌بندی روی فرمِ ثبت ----------
    //
    // ⚠ برخلافِ حذف، صفحه **تازه نمی‌شود**: هیچ عددی روی همین صفحه عوض
    //   نمی‌شود (ردیفِ چیپ جای دیگری است)، پس تازه‌سازی فقط جای کاربر را
    //   در فهرستِ بلندِ دسته‌ها گم می‌کرد — همان استدلالِ پینِ حساب.
    document.querySelectorAll('.js-cat-pin').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var self = this;
            var want = self.getAttribute('data-pinned') !== '1';

            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('category_id', self.getAttribute('data-id'));
            fd.append('pinned', want ? '1' : '0');

            fetch(apiUrl('toggle_category_pin.php'), {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) { alert(d.message || 'خطایی رخ داد.'); return; }
                    self.setAttribute('data-pinned', want ? '1' : '0');
                    self.setAttribute('aria-pressed', want ? 'true' : 'false');
                    self.classList.toggle('is-on', want);
                    self.title = want ? 'برداشتن از فرم ثبت' : 'نشان دادن روی فرم ثبت';
                })
                .catch(function () { alert(netErr()); });
        });
    });

    document.querySelectorAll('.js-ref-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('حذف شود؟')) return;

            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('kind', this.getAttribute('data-kind'));
            fd.append('action', 'delete');
            fd.append('id', this.getAttribute('data-id'));

            var chip = this.closest('.ref-chip');

            fetch(apiUrl('manage_reference.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    // فقط برداشتن چیپ کافی نیست: فهرست «پیش‌فرض‌ها» و
                    // فرم‌های دیگرِ همین صفحه هم از همین داده ساخته شده‌اند
                    if (d.success) { if (chip) chip.remove(); window.reloadPage(); }
                    else { alert(d.message || 'قابل حذف نیست.'); }
                })
                .catch(function () { alert(netErr()); });
        });
    });

    // ---------- پیش‌نمایشِ فایلِ بکاپ ----------
    /* ⛔ فایل خودش شمارشش را دارد (`summary` در خروجی)، پس پیش‌نمایش
       هیچ رفت‌وبرگشتی به سرور لازم ندارد و محتوای بکاپ هیچ‌جا نمی‌رود —
       همان قاعده‌ای که متنِ پیامکِ بانک را در فرگمنت نگه می‌دارد.
       ⚠ و اگر این بلوک اصلاً اجرا نشود فرم دست‌نخورده کار می‌کند؛ فقط
         بی‌پیش‌نمایش. پیش‌نمایشی که نرسد نباید جلوی بازگرداندن را بگیرد. */
    var restoreFile = document.getElementById('restoreFile');
    var restoreBox  = document.getElementById('restorePreview');
    if (restoreFile && restoreBox && window.FileReader) {
        restoreFile.addEventListener('change', function () {
            restoreBox.hidden = true;
            var f = restoreFile.files && restoreFile.files[0];
            if (!f) { return; }

            var fr = new FileReader();
            fr.onload = function () {
                var d = null;
                try { d = JSON.parse(String(fr.result)); } catch (e) { d = null; }

                restoreBox.textContent = '';
                if (!d || !d.tables) {
                    restoreBox.textContent = 'این فایل خوانده نشد. فایلِ بکاپِ همین برنامه را انتخاب کنید.';
                    restoreBox.hidden = false;
                    return;
                }

                var total = 0;
                var sum = d.summary || {};
                Object.keys(sum).forEach(function (k) { total += (parseInt(sum[k], 10) || 0); });

                var line = 'این فایل ' + toPersianDigitsJs(String(total)) + ' ردیف دارد';
                if (d.exported_jalali) { line += ' — گرفته‌شده در ' + d.exported_jalali; }
                line += '.';

                /* ⚠ با textContent، نه innerHTML: محتوای فایل دستِ کاربر
                   است و هر رشته‌ای می‌تواند داخلش باشد. */
                restoreBox.textContent = line;
                restoreBox.hidden = false;
            };
            fr.onerror = function () {
                restoreBox.textContent = 'فایل خوانده نشد.';
                restoreBox.hidden = false;
            };
            fr.readAsText(f);
        });
    }

    // ---------- چک‌ها ----------
    setupAmountFormatter('add_cheque_amount');
    setupAmountFormatter('edit_cheque_amount');

    function fillBankSelect(selectEl, direction, selectedId) {
        if (!selectEl || !window.BANK_DATA) return;
        while (selectEl.options.length > 1) { selectEl.remove(1); }
        var list = window.BANK_DATA[direction === 'issued' ? 'mine' : 'external'] || [];
        list.forEach(function (b) {
            var opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = b.name;
            selectEl.appendChild(opt);
        });
        if (selectedId && String(selectedId) !== '0') { selectEl.value = String(selectedId); }
    }

    document.querySelectorAll('.js-add-cheque').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var direction = this.getAttribute('data-direction');
            document.getElementById('add_cheque_direction').value = direction;
            document.getElementById('addChequeTitle').textContent =
                direction === 'received' ? 'ثبت چک دریافتی' : 'ثبت چک صادره';

            document.getElementById('add_cheque_counterparty').value = '';
            document.getElementById('add_cheque_amount').value = '';
            document.getElementById('add_sayadi').value = '';
            document.getElementById('add_cheque_number').value = '';
            document.getElementById('add_cheque_note').value = '';

            var due = document.getElementById('add_cheque_due');
            due.value = '';
            due.closest('.jdp-field').querySelector('.jdp-display').value = '';

            fillBankSelect(document.getElementById('add_cheque_bank'), direction, null);

            // روش و سری از نو؛ برچسب برای چکِ صادره «دادیم» است
            var vm = document.getElementById('add_value_method');
            if (vm) {
                vm.value = '';
                document.getElementById('add_value_rate').value = '';
                document.getElementById('add_series_count').value = '۱';
                document.getElementById('add_loan_principal').value = '';
                setJdpValue('add_loan_from_display', 'add_loan_from', document.getElementById('add_loan_from').defaultValue);
                document.getElementById('add_value_label').textContent =
                    direction === 'received' ? 'این چک را چطور گرفتیم؟' : 'این چک را چطور دادیم؟';
                var pv = document.getElementById('addValuePreview');
                if (pv) pv.hidden = true;
                syncValueFields('add');
            }

            var msg = document.getElementById('addChequeMessage');
            if (msg) { msg.hidden = true; msg.classList.remove('show', 'success', 'error'); }

            openModal('addChequeModal');
        });
    });

    function submitJson(form, url, msgEl, btnEl, amountFieldId, preparedFd) {
        var fd = preparedFd || new FormData(form);
        if (amountFieldId) {
            var amtEl = document.getElementById(amountFieldId);
            if (amtEl) fd.set('amount', toLatinDigitsJs(amtEl.value).replace(/[,\u066C]/g, ''));
        }
        if (btnEl) btnEl.disabled = true;

        return fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                msgEl.hidden = false;
                msgEl.classList.remove('success', 'error');
                if (d.success) {
                    msgEl.classList.add('show', 'success');
                    msgEl.textContent = d.message || 'انجام شد.';
                    window.reloadPage();
                } else {
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = d.message || 'خطایی رخ داد.';
                }
            })
            .catch(function () {
                msgEl.hidden = false;
                msgEl.classList.add('show', 'error');
                msgEl.textContent = 'خطا در ارتباط با سرور.';
            })
            .finally(function () { if (btnEl) btnEl.disabled = false; });
    }

    // ---------- «این چک را چطور گرفتیم؟» — روش، درصد، سری و اقساطِ وام ----------
    // ⛔ هیچ حسابی اینجا نیست: پیش‌نمایش از `api/cheque_value_preview.php` می‌آید (همان
    //    `chequePresentValue()`/`chequeLoanPlan()` سرور)، تا فرم و کارت یک عدد بگویند.
    setupAmountFormatter('add_loan_principal');
    var cleanNum = function (v) { return toLatinDigitsJs(String(v || '')).replace(/[,\u066C\s]/g, ''); };
    function syncValueFields(prefix) {
        var sel = document.getElementById(prefix + '_value_method');
        if (!sel) return;
        var m = sel.value;
        var opt = sel.options[sel.selectedIndex];
        var hint = document.getElementById(prefix + '_value_hint');
        if (hint) hint.textContent = opt ? (opt.getAttribute('data-hint') || '') : '';
        var rate = document.getElementById(prefix + '_rate_group');
        if (rate) rate.hidden = m === '';
        if (prefix !== 'add') return;
        var loan = m === 'loan';
        document.getElementById('add_loan_group').hidden = !loan;
        document.getElementById('add_amount_group').hidden = loan;
        document.getElementById('add_cheque_amount').required = !loan;
        document.getElementById('add_loan_principal').required = loan;
        var n = parseInt(cleanNum(document.getElementById('add_series_count').value), 10) || 1;
        document.getElementById('add_due_label').textContent =
            n > 1 ? 'سررسیدِ اولین چک (بقیه ماهانه)' : (m !== '' ? 'تاریخ سررسید' : 'تاریخ سررسید (اختیاری)');
        document.getElementById('add_amount_label').firstChild.textContent = n > 1 ? 'مبلغِ هر چک (تومان) ' : 'مبلغ (تومان) ';
    }
    var previewTimer = null, previewSeq = 0;
    function refreshChequePreview() {
        var box = document.getElementById('addValuePreview');
        if (!box) return;
        clearTimeout(previewTimer);
        previewTimer = setTimeout(function () {
            var m = document.getElementById('add_value_method').value;
            var n = parseInt(cleanNum(document.getElementById('add_series_count').value), 10) || 1;
            if (m === '' && n < 2) { box.hidden = true; return; }
            var q = new URLSearchParams({
                value_method: m,
                value_rate: cleanNum(document.getElementById('add_value_rate').value),
                series_count: String(n),
                due_date: document.getElementById('add_cheque_due').value,
                amount: cleanNum(document.getElementById('add_cheque_amount').value),
                loan_principal: cleanNum(document.getElementById('add_loan_principal').value),
                loan_from: document.getElementById('add_loan_from').value
            });
            var seq = ++previewSeq;
            fetch(apiUrl('cheque_value_preview.php') + '?' + q.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (seq !== previewSeq) return;               // پاسخِ کهنه‌ی تایپِ قبلی
                    if (!d.success) {
                        box.classList.add('is-error');
                        box.textContent = d.message || 'برای دیدنِ ارزشِ امروز، مبلغ، درصد و سررسید را کامل کنید.';
                        box.hidden = false;
                        return;
                    }
                    box.classList.remove('is-error');
                    box.innerHTML = '';
                    var line = function (parts) {
                        var div = document.createElement('div');
                        parts.forEach(function (p) {
                            var el = document.createElement(p[1] ? 'b' : 'span');
                            el.textContent = p[0];
                            div.appendChild(el);
                        });
                        box.appendChild(div);
                    };
                    var fa = window.JalaliDatePicker ? window.JalaliDatePicker.toFa : function (x) { return x; };
                    line(d.count > 1
                        ? [[fa(String(d.count)) + ' چک', true], [' · هر چک ', false], [d.each, true], [' تومان · ماهانه از ' + d.first + ' تا ' + d.last, false]]
                        : [['سررسید ' + d.first, false]]);
                    line([['جمعِ مبلغِ چک‌ها ', false], [d.face, true], [' · ارزشِ امروز ', false], [d.value, true], [' تومان', false]]);
                    if (m !== '') { line([['سودِ باقی‌مانده تا پاس شدن ', false], [d.profit, true], [' تومان', false]]); }
                    box.hidden = false;
                })
                .catch(function () { box.hidden = true; });
        }, 300);
    }
    ['add_value_method', 'add_value_rate', 'add_series_count', 'add_cheque_amount', 'add_loan_principal', 'add_cheque_due', 'add_loan_from']
        .forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener(el.tagName === 'SELECT' || el.type === 'hidden' ? 'change' : 'input', function () {
                syncValueFields('add');
                refreshChequePreview();
            });
        });
    var editMethodSel = document.getElementById('edit_value_method');
    if (editMethodSel) editMethodSel.addEventListener('change', function () { syncValueFields('edit'); });

    var addChequeForm = document.getElementById('addChequeForm');
    if (addChequeForm) {
        addChequeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(addChequeForm);
            fd.set('amount', cleanNum(document.getElementById('add_cheque_amount').value));
            if (document.getElementById('add_loan_principal')) {
                fd.set('loan_principal', cleanNum(document.getElementById('add_loan_principal').value));
                fd.set('series_count', cleanNum(document.getElementById('add_series_count').value) || '1');
                fd.set('value_rate', cleanNum(document.getElementById('add_value_rate').value));
            }
            submitJson(addChequeForm, apiUrl('add_cheque.php'),
                document.getElementById('addChequeMessage'),
                document.getElementById('addChequeSubmitBtn'), null, fd);
        });
    }

    document.querySelectorAll('.js-edit-cheque').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_cheque_id').value = this.getAttribute('data-id');
            syncPersonPicker('edit_cheque_counterparty', this.getAttribute('data-counterparty'));
            document.getElementById('edit_cheque_amount').value =
                fmtMoneyJs(this.getAttribute('data-amount'));
            document.getElementById('edit_sayadi').value = this.getAttribute('data-sayadi') || '';
            document.getElementById('edit_cheque_number').value = this.getAttribute('data-cheque-number') || '';
            document.getElementById('edit_cheque_note').value = this.getAttribute('data-note') || '';

            fillBankSelect(document.getElementById('edit_cheque_bank'),
                this.getAttribute('data-direction'), this.getAttribute('data-bank-id'));

            var dueVal = this.getAttribute('data-due-date');
            if (dueVal) {
                setJdpValue('edit_cheque_due_display', 'edit_cheque_due', dueVal);
            } else {
                document.getElementById('edit_cheque_due').value = '';
                document.getElementById('edit_cheque_due_display').value = '';
            }

            var em = document.getElementById('edit_value_method');
            if (em) {
                var loanOpt = em.querySelector('option[value="loan"]');
                // ⛔ «اقساطِ وام» اصلِ پول را از ثبتِ سری دارد؛ چکِ دیگر به آن روش نمی‌رود
                if (loanOpt) loanOpt.disabled = this.getAttribute('data-loan') !== '1';
                em.value = this.getAttribute('data-value-method') || '';
                var rr = this.getAttribute('data-value-rate') || '';
                document.getElementById('edit_value_rate').value = window.JalaliDatePicker ? window.JalaliDatePicker.toFa(rr) : rr;
                syncValueFields('edit');
            }

            var msg = document.getElementById('editChequeMessage');
            if (msg) { msg.hidden = true; msg.classList.remove('show', 'success', 'error'); }

            openModal('editChequeModal');
        });
    });

    var editChequeForm = document.getElementById('editChequeForm');
    if (editChequeForm) {
        editChequeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var efd = new FormData(editChequeForm);
            if (document.getElementById('edit_value_rate')) {
                efd.set('value_rate', cleanNum(document.getElementById('edit_value_rate').value));
            }
            submitJson(editChequeForm, apiUrl('update_cheque.php'),
                document.getElementById('editChequeMessage'),
                document.getElementById('editChequeSubmitBtn'), 'edit_cheque_amount', efd);
        });
    }

    var chequeSettleModal = document.getElementById('chequeSettle');
    // ---------- وضعیتِ چک: پاس / برگشت / خرج ----------
    // نشانِ وضعیت خودش دکمه است، پس کنترلِ تازه‌ای به کارت اضافه نشد.
    document.querySelectorAll('.js-cheque-status').forEach(function (badge) {
        badge.addEventListener('click', function () {
            if (!chequeSettleModal) { return; }

            var cur       = badge.getAttribute('data-status') || 'pending';
            var direction = badge.getAttribute('data-direction');
            var chips     = document.querySelectorAll('#chequeOutcomes .stay-chip');
            var walletG   = document.getElementById('chequeWalletGroup');
            var endorseG  = document.getElementById('chequeEndorseGroup');
            var bounceH   = document.getElementById('chequeBounceHint');
            var endorseIn = document.getElementById('chequeEndorsedTo');
            var backChip  = document.getElementById('chequeBackToPending');
            var titleEl   = document.getElementById('chequeSettleTitle');
            var hiddenSt  = document.getElementById('chequeSettleStatus');

            if (titleEl) { titleEl.textContent = 'وضعیت چک ' + (badge.getAttribute('data-name') || ''); }
            if (endorseIn) { endorseIn.value = badge.getAttribute('data-endorsed') || ''; }

            // «در انتظار» فقط برای چکی معنا دارد که از حالت پایه بیرون
            // رفته — برای چکِ تازه‌ثبت‌شده گزینه‌ی بی‌معنایی است.
            if (backChip) { backChip.hidden = (cur === 'pending'); }

            function pick(status) {
                if (hiddenSt) { hiddenSt.value = status; }
                chips.forEach(function (c) {
                    c.classList.toggle('active', c.getAttribute('data-status') === status);
                });
                if (walletG)  { walletG.hidden  = (status !== 'cleared'); }
                if (endorseG) { endorseG.hidden = (status !== 'endorsed'); }
                // پیامِ «طلب ساخته می‌شود» فقط برای چکِ **دریافتی** درست
                // است؛ چکِ صادره‌ی برگشتی طلبی برای شما نمی‌سازد.
                if (bounceH) {
                    bounceH.hidden = !(status === 'bounced' && direction === 'received');
                }
            }

            // پیش‌فرض: کاری که کاربر معمولاً می‌خواهد. برای چکی که هنوز
            // در جریان است «پاس شد»؛ برای چکی که از جریان خارج شده،
            // برگرداندن به «در انتظار».
            pick(cur === 'pending' ? 'cleared' : 'pending');

            chips.forEach(function (chip) {
                if (chip.getAttribute('data-wired')) { return; }
                chip.setAttribute('data-wired', '1');
                chip.addEventListener('click', function () {
                    pick(chip.getAttribute('data-status'));
                });
            });

            askSettleWallet('chequeSettle', badge, {
                hint: 'اگر پاس شده، مبلغش در موجودی حساب انتخابی اعمال می‌شود.',
                url: apiUrl('toggle_cheque_settled.php'),
                idField: 'cheque_id',
                extraFields: function (fd) {
                    fd.append('status', hiddenSt ? hiddenSt.value : 'cleared');
                    if (endorseIn) { fd.append('endorsed_to', endorseIn.value.trim()); }
                }
            });
        });
    });

    document.querySelectorAll('.js-toggle-cheque').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var el = this;
            if (chequeSettleModal && el.checked) {
                askSettleWallet('chequeSettle', el, {
                    hint: 'مبلغ چک در موجودی حساب انتخابی اعمال می‌شود.',
                    url: apiUrl('toggle_cheque_settled.php'),
                    idField: 'cheque_id'
                });
                return;
            }
            var fd = new FormData();
            fd.append('cheque_id', el.getAttribute('data-id'));
            fd.append('csrf_token', csrf());
            el.disabled = true;

            fetch(apiUrl('toggle_cheque_settled.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) { window.reloadPage(); }
                    else { alert(d.message || 'خطایی رخ داد.'); el.checked = !el.checked; el.disabled = false; }
                })
                .catch(function () { alert(netErr()); el.checked = !el.checked; el.disabled = false; });
        });
    });

    document.querySelectorAll('.js-delete-cheque').forEach(function (btn) {
        btn.addEventListener('click', function () {
            deleteWithUndo({ ep: 'delete_cheque.php', field: 'cheque_id',
                             value: this.getAttribute('data-id'), csrf: csrf(),
                             text: 'چک حذف شد.' });
        });
    });

    // ---------- دارایی‌ها ----------
    setupAmountFormatter('add_asset_price');
    setupAmountFormatter('edit_asset_price');

    // ---------- ارزش به نرخِ روز (`includes/rates.php`) ----------
    // منوی کنارِ هر نوعِ دارایی: انتخابِ نرخ = وصلِ خودکار؛ «دستی» = قطع با نگه
    // داشتنِ همان قیمتِ فعلی (نه پاک کردنش — ارزش ناگهان به بهای خرید برمی‌گشت).
    document.querySelectorAll('.js-asset-rate').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('type_id', sel.getAttribute('data-id'));
            fd.append('rate_code', sel.value);
            if (!sel.value) {
                var keep = parseInt(sel.getAttribute('data-price'), 10) || 0;
                fd.append('price', keep > 0 ? String(keep) : '');
            }
            sel.disabled = true;
            fetch(apiUrl('update_asset_price.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) { window.reloadPage(); return; }
                    alert(d.message || 'خطایی رخ داد.');
                    sel.disabled = false;
                })
                .catch(function () { alert(netErr()); sel.disabled = false; });
        });
    });

    // مقدارِ دارایی — همان قاعده‌ی `sanitizeQty()` در سرور (و `qty()`ِ store.js): «1,000»
    // هزار است و «۲/۵»، «۲٫۵» و «2,5» دو و نیم. خامِ تایپ‌شده فرستاده می‌شود و سرور
    // همین را می‌خواند؛ پیش از این ویرگول حذف و «/» نادیده گرفته می‌شد (۲/۵ ⇒ ۲۵).
    function assetQtyJs(s) {
        var t = toLatinDigitsJs(String(s || '')).replace(/\s/g, '');
        if (/^\d{1,3}(?:[,،]\d{3})+(?:[.٫]\d+)?$/.test(t)) { t = t.replace(/[,،]/g, ''); }
        t = t.replace(/[٫\/,،]/g, '.').replace(/[^0-9.]/g, '');
        return parseFloat(t) || 0;
    }

    // ارزشِ امروزِ داراییِ در حالِ ثبت، هم‌زمان با تایپِ مقدار
    (function () {
        var type = document.getElementById('add_asset_type');
        var qty  = document.getElementById('add_asset_qty');
        var out  = document.getElementById('addAssetLive');
        if (!type || !qty || !out) return;
        function fmt(n) { return fmtMoneyJs(Math.round(n)); }
        function live() {
            var opt = type.options[type.selectedIndex];
            var price = opt ? (parseInt(opt.getAttribute('data-price'), 10) || 0) : 0;
            var unit = opt ? (opt.getAttribute('data-unit') || 'واحد') : 'واحد';
            var q = assetQtyJs(qty.value);
            if (price <= 0) { out.hidden = true; return; }
            var src = opt.getAttribute('data-auto') === '1' ? 'نرخِ روزِ بازار' : 'نرخِ دستی';
            out.hidden = false;
            out.textContent = q > 0
                ? 'ارزشِ امروز ≈ ' + fmt(q * price) + ' تومان (' + src + '، هر ' + unit + ' ' + fmt(price) + ')'
                : 'هر ' + unit + ' امروز ' + fmt(price) + ' تومان (' + src + ')';
        }
        type.addEventListener('change', live);
        qty.addEventListener('input', live);
        live();
    })();

    var addAssetForm = document.getElementById('addAssetForm');
    if (addAssetForm) {
        addAssetForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(addAssetForm);
            fd.set('unit_price', toLatinDigitsJs(document.getElementById('add_asset_price').value).replace(/[,\u066C]/g, ''));
            fd.set('quantity', toLatinDigitsJs(document.getElementById('add_asset_qty').value).trim());

            var msgEl = document.getElementById('addAssetMessage');
            var btnEl = document.getElementById('addAssetSubmitBtn');
            btnEl.disabled = true;

            fetch(apiUrl('add_asset.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    msgEl.hidden = false;
                    msgEl.classList.remove('success', 'error');
                    if (d.success) {
                        msgEl.classList.add('show', 'success');
                        msgEl.textContent = d.message || 'ثبت شد.';
                        window.reloadPage();
                    } else {
                        msgEl.classList.add('show', 'error');
                        msgEl.textContent = d.message || 'خطایی رخ داد.';
                    }
                })
                .catch(function () {
                    msgEl.hidden = false;
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = 'خطا در ارتباط با سرور.';
                })
                .finally(function () { btnEl.disabled = false; });
        });
    }

    document.querySelectorAll('.js-edit-asset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_asset_id').value = this.getAttribute('data-id');
            document.getElementById('edit_asset_qty').value = toPersianDigitsJs(this.getAttribute('data-quantity').replace(/\.?0+$/, '') || '0');
            var price = this.getAttribute('data-unit-price');
            document.getElementById('edit_asset_price').value =
                (price && price !== '0') ? fmtMoneyJs(price) : '';
            document.getElementById('edit_asset_note').value = this.getAttribute('data-note') || '';
            setJdpValue('edit_asset_date_display', 'edit_asset_date', this.getAttribute('data-entry-date'));

            var msg = document.getElementById('editAssetMessage');
            if (msg) { msg.hidden = true; msg.classList.remove('show', 'success', 'error'); }

            openModal('editAssetModal');
        });
    });

    var editAssetForm = document.getElementById('editAssetForm');
    if (editAssetForm) {
        editAssetForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(editAssetForm);
            fd.set('unit_price', toLatinDigitsJs(document.getElementById('edit_asset_price').value).replace(/[,\u066C]/g, ''));
            fd.set('quantity', toLatinDigitsJs(document.getElementById('edit_asset_qty').value).trim());

            var msgEl = document.getElementById('editAssetMessage');
            var btnEl = document.getElementById('editAssetSubmitBtn');
            btnEl.disabled = true;

            fetch(apiUrl('update_asset.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    msgEl.hidden = false;
                    msgEl.classList.remove('success', 'error');
                    if (d.success) {
                        msgEl.classList.add('show', 'success');
                        msgEl.textContent = d.message || 'ذخیره شد.';
                        window.reloadPage();
                    } else {
                        msgEl.classList.add('show', 'error');
                        msgEl.textContent = d.message || 'خطایی رخ داد.';
                    }
                })
                .catch(function () {
                    msgEl.hidden = false;
                    msgEl.classList.add('show', 'error');
                    msgEl.textContent = 'خطا در ارتباط با سرور.';
                })
                .finally(function () { btnEl.disabled = false; });
        });
    }

    document.querySelectorAll('.js-delete-asset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            deleteWithUndo({ ep: 'delete_asset.php', field: 'asset_id',
                             value: this.getAttribute('data-id'), csrf: csrf(),
                             text: 'دارایی حذف شد.' });
        });
    });

    // ---------- دکمه‌ی بازگشت صفحه‌های فرعی ----------
    // اگر کاربر از داخل خود اپ آمده، برگردیم همان‌جا که بود؛ اگر مستقیم
    // این آدرس را باز کرده (یا از میان‌بر صفحه‌ی اصلی گوشی)، href صفحه‌ی
    // خانه جایگزین است.
    document.querySelectorAll('.js-page-back').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (window.history.length > 1 && document.referrer.indexOf(location.host) !== -1) {
                e.preventDefault();
                window.history.back();
            }
        });
    });

    // ---------- فیلترهای صفحه تراکنش‌ها ----------
    var filterChips = document.querySelectorAll('.filter-chip[data-filter], .seg-item[data-filter]');
    if (filterChips.length > 0) {
        filterChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                var group = this.getAttribute('data-group');
                document.querySelectorAll('[data-group="' + group + '"]').forEach(function (c) {
                    c.classList.remove('active');
                });
                this.classList.add('active');

                var form = document.getElementById('filterForm');
                var hiddenInput = form.querySelector('input[name="' + group + '"]');
                if (hiddenInput) {
                    hiddenInput.value = this.getAttribute('data-filter');
                }
                form.submit();
            });
        });
    }

    // ---------- مودال‌های عمومی (افزودن/ویرایش) در صفحات مدیریت و ویرایش تراکنش ----------
    function openModal(id) {
        var modal = document.getElementById(id);
        if (modal) modal.classList.add('show');
    }
    function closeModal(id) {
        var modal = document.getElementById(id);
        if (modal) modal.classList.remove('show');
    }
    window.openModal = openModal;
    window.closeModal = closeModal;

    document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(this.getAttribute('data-modal-open'));
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modal = btn.closest('.modal-overlay');
            if (modal) modal.classList.remove('show');
        });
    });
    document.querySelectorAll('.modal-overlay').forEach(function (overlay2) {
        overlay2.addEventListener('click', function (e) {
            if (e.target === overlay2) overlay2.classList.remove('show');
        });
    });

    // ---------- کارت‌های جمع‌شونده (داشبورد) ----------
    document.querySelectorAll('.collapsible-header').forEach(function (header) {
        header.addEventListener('click', function () {
            var card = header.closest('.collapsible-card');
            if (card) card.classList.toggle('collapsed');
        });
    });

    // ---------- ردیف فشرده تراکنش (باز/بسته شدن با کلیک) ----------
    document.querySelectorAll('.tx-row-summary').forEach(function (summary) {
        summary.addEventListener('click', function (e) {
            if (e.target.closest('button')) return;
            var row = summary.closest('.tx-row');
            if (row) row.classList.toggle('expanded');
        });
    });

    // ---------- پیش‌پر کردن فرم ویرایش کاربر (مدیریت کاربران) ----------
    document.querySelectorAll('.js-edit-user').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.getElementById('editUserForm');
            if (!form) return;
            form.querySelector('[name="user_id"]').value = this.getAttribute('data-id');
            form.querySelector('[name="full_name"]').value = this.getAttribute('data-full-name');
            form.querySelector('[name="username"]').value = this.getAttribute('data-username');
            var emailField = form.querySelector('[name="email"]');
            if (emailField) emailField.value = this.getAttribute('data-email') || '';
            // ⚠ مثل ایمیل: اگر این خط اجرا نشود فیلد خالی می‌ماند، ولی
            //   `saveUserPhone()` خالی را «دست نزن» می‌فهمد نه «پاک کن» —
            //   پس بدترین حالتش این است که مدیر مقدارِ فعلی را نبیند.
            var phoneField = form.querySelector('[name="phone"]');
            if (phoneField) phoneField.value = this.getAttribute('data-phone') || '';
            form.querySelector('[name="role"]').value = this.getAttribute('data-role');
            form.querySelector('[name="password"]').value = '';
            form.querySelector('[name="password_confirm"]').value = '';
            openModal('editUserModal');
        });
    });

    // ---------- پیش‌پر کردن فرم ویرایش دسته‌بندی (مدیریت دسته‌بندی‌ها) ----------
    document.querySelectorAll('.js-edit-category').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.getElementById('editCategoryForm');
            if (!form) return;
            form.querySelector('[name="category_id"]').value = this.getAttribute('data-id');
            form.querySelector('[name="name"]').value = this.getAttribute('data-name');
            form.querySelector('[name="type"]').value = this.getAttribute('data-type');
            var iconEl = form.querySelector('[name="icon"]');
            var colorEl = form.querySelector('[name="color"]');
            if (iconEl) iconEl.value = this.getAttribute('data-icon') || '📌';
            if (colorEl) colorEl.value = this.getAttribute('data-color') || '#64748b';
            openModal('editCategoryModal');
        });
    });


    // ---------- کلید بخش معاملات در پروفایل ----------
    var tradesToggle = document.getElementById('tradesToggle');
    if (tradesToggle) {
        tradesToggle.addEventListener('change', function () {
            var fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('enabled', this.checked ? '1' : '0');
            var msg = document.getElementById('tradesToggleMsg');
            fetch(apiUrl('toggle_trades.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (msg) {
                        msg.hidden = false;
                        msg.classList.remove('success', 'error');
                        msg.classList.add('show', d.success ? 'success' : 'error');
                        msg.textContent = d.message || (d.success ? 'ذخیره شد.' : 'خطا');
                    }
                    if (!d.success) { tradesToggle.checked = !tradesToggle.checked; return; }
                    // منوی «بیشتر» و نوار کناری با حالت قبلی رندر شده‌اند؛
                    // بی‌تازه‌سازی، دکمه‌ی معاملات تا ناوبری بعدی سر جایش
                    // می‌ماند (یا برعکس، غایب می‌ماند) و کاربر فکر می‌کند
                    // کلید کار نکرد.
                    window.reloadPage();
                })
                .catch(function () {
                    tradesToggle.checked = !tradesToggle.checked;
                    if (msg) { msg.hidden = false; msg.classList.add('show', 'error'); msg.textContent = netErr(); }
                });
        });
    }

    // ---------- پروفایل → «صفحه‌ی خانه»: روشن/خاموشِ هر قلم ----------
    //
    // ⛔ هر تغییر **همان لحظه** ذخیره می‌شود، بی‌دکمه‌ی «ذخیره»: کلیدی
    //    که روشن شده ولی ذخیره نشده همان «تنظیمی که کار نمی‌کند» است.
    //    کلِ فرم فرستاده می‌شود (نه فقط همان یک کلید) تا سرور همیشه
    //    حالتِ کامل را بگیرد و دو تپِ سریع ترتیبشان مهم نباشد.
    // ⚠ صفحه تازه نمی‌شود: هیچ چیزِ همین صفحه به این تنظیم بسته نیست.
    var hwForm = document.getElementById('homeWidgetsForm');
    if (hwForm) {
        var hwMsg = document.getElementById('homeWidgetsMsg');
        var hwAll = document.getElementById('hwAllOn');
        var hwCount = document.getElementById('hwCount');
        var hwTimer = null;
        var hwBoxes = function () { return hwForm.querySelectorAll('input[name="on[]"]'); };
        var hwSync = function () {
            var boxes = hwBoxes(), on = 0;
            for (var i = 0; i < boxes.length; i++) { if (boxes[i].checked) on++; }
            if (hwCount) hwCount.textContent = toPersianDigitsJs(String(on)) + ' از ' + toPersianDigitsJs(String(boxes.length)) + ' روشن';
            if (hwAll) hwAll.hidden = on === boxes.length;
        };
        var hwSay = function (ok, text) {
            if (!hwMsg) return;
            hwMsg.hidden = false;
            hwMsg.classList.remove('success', 'error');
            hwMsg.classList.add('show', ok ? 'success' : 'error');
            hwMsg.textContent = text;
            clearTimeout(hwTimer);
            if (ok) hwTimer = setTimeout(function () { hwMsg.hidden = true; }, 1800);
        };
        var hwSave = function (undo) {
            hwSync();
            fetch(apiUrl('save_home_widgets.php'), { method: 'POST', body: new FormData(hwForm), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) { undo(); hwSync(); hwSay(false, d.message || 'ذخیره نشد.'); return; }
                    hwSay(true, d.message || 'ذخیره شد.');
                })
                .catch(function () { undo(); hwSync(); hwSay(false, netErr()); });
        };
        hwForm.addEventListener('change', function (e) {
            var box = e.target;
            if (!box || box.name !== 'on[]') return;
            hwSave(function () { box.checked = !box.checked; });
        });
        if (hwAll) {
            hwAll.addEventListener('click', function () {
                var boxes = hwBoxes(), was = [];
                for (var i = 0; i < boxes.length; i++) { was.push(boxes[i].checked); boxes[i].checked = true; }
                hwSave(function () { for (var j = 0; j < boxes.length; j++) boxes[j].checked = was[j]; });
            });
        }
    }

    // ---------- نمای دارایی‌ها: روشن/خاموش کردن هر قلم ----------
    //
    // هر قلم (دارایی ثبت‌شده، کالای معاملاتی، مجموع حساب‌ها) یک کلید
    // دارد. خاموش کردنش چیزی را پاک نمی‌کند؛ فقط از جمع کل، درصدها و
    // نمودار بیرونش می‌گذارد — تا بشود پرسید «دارایی‌ام بدون طلا چقدر
    // است؟». انتخاب در همین مرورگر می‌ماند تا هر بار از نو نچینید.
    (function () {
        var list = document.getElementById('assetBreakdown');
        if (!list) { return; }

        var STORE = 'daftar_asset_off';
        var items = [].slice.call(list.querySelectorAll('.asset-item'));
        var totalEl = document.getElementById('assetGrandTotal');
        var noteEl = document.getElementById('assetExcludedNote');
        var unit = totalEl ? (totalEl.querySelector('small') ? totalEl.querySelector('small').textContent : '') : '';

        var off = {};
        try { off = JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch (e) { off = {}; }

        var chart = null;
        var chartData = [];
        var dataEl = document.getElementById('assetPortfolioData');
        if (dataEl) {
            try { chartData = JSON.parse(dataEl.textContent) || []; } catch (e) { chartData = []; }
        }

        // ---------- اسپارک‌لاینِ روندِ خالص دارایی ----------
        // بی‌محور، بی‌عدد، بی‌راهنما: اینجا فقط **شکلِ** روند مهم است؛
        // مقدارِ دقیق را همان عددِ بزرگِ بالای صفحه می‌گوید. افزودنِ
        // محور و اعداد یعنی گفتنِ دوباره‌ی چیزی که همان‌جا نوشته شده.
        (function () {
            var sparkEl = document.getElementById('nwSpark');
            var nwEl = document.getElementById('netWorthData');
            if (!sparkEl || !nwEl || !window.Chart) { return; }

            var nw = [];
            try { nw = JSON.parse(nwEl.textContent) || []; } catch (e) { return; }
            if (nw.length < 2) { return; }

            // رنگ از روندِ کلی می‌آید: رشد سبز، افت قرمز. رنگ اینجا
            // معناست نه تزئین — همان قاعده‌ی نمودارهای دیگر.
            function paint(c) {
                var up = nw[nw.length - 1].v >= nw[0].v;
                var css = getComputedStyle(document.documentElement);
                var col = (css.getPropertyValue(up ? '--in' : '--out') || '#16a34a').trim();
                c.data.datasets[0].borderColor = col;
                c.data.datasets[0].backgroundColor = col + '22';
                c.update('none');
            }

            var spark = new Chart(sparkEl.getContext('2d'), {
                type: 'line',
                data: {
                    labels: nw.map(function (p) { return p.d; }),
                    datasets: [{
                        data: nw.map(function (p) { return p.v; }),
                        borderWidth: 2, pointRadius: 0, fill: true, tension: 0.25
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    scales: { x: { display: false }, y: { display: false } },
                    animation: false
                }
            });
            paint(spark);
            // ⚠ `onThemeChange` نه `registerThemedChart`: دومی آرایه‌ی
            //   رنگِ قاچ‌ها را می‌گیرد (برای دونات)، نه یک تابع. اینجا
            //   رنگ از --in/--out خوانده می‌شود، پس باید خودمان دوباره
            //   رنگ بزنیم. بدون این، نمودار با عوض شدنِ حالت شب رنگِ
            //   قبلی را نگه می‌داشت — همان درسی که صفحه‌ی گزارش داد.
            if (typeof window.onThemeChange === 'function') {
                window.onThemeChange(function () { paint(spark); });
            }
        })();

        function money(n) {
            var neg = n < 0;
            var t = fmtMoneyJs(Math.abs(n));
            return (neg ? '\u2212' : '') + t;
        }

        function isOn(key) { return !off[key]; }

        function refresh() {
            var total = 0, excluded = 0;
            items.forEach(function (el) {
                if (isOn(el.getAttribute('data-key'))) {
                    total += parseInt(el.getAttribute('data-value') || '0', 10);
                } else {
                    excluded++;
                }
            });

            if (totalEl) {
                totalEl.innerHTML = '';
                totalEl.appendChild(document.createTextNode(money(total) + ' '));
                var sm = document.createElement('small');
                sm.textContent = unit;
                totalEl.appendChild(sm);
            }

            if (noteEl) {
                noteEl.hidden = excluded === 0;
                noteEl.textContent = excluded === 0 ? ''
                    : toPersianDigitsJs(String(excluded)) + ' قلم از این محاسبه بیرون گذاشته شده.';
            }

            items.forEach(function (el) {
                var on = isOn(el.getAttribute('data-key'));
                var val = parseInt(el.getAttribute('data-value') || '0', 10);
                var pct = (on && total > 0 && val > 0) ? Math.round(val / total * 1000) / 10 : 0;
                el.classList.toggle('asset-item-off', !on);

                // درصد فقط وقتی معنا دارد که جمع کل مثبت باشد. با خالصِ منفیِ
                // بزرگ (بدهی بیشتر از دارایی) جمع منفی می‌شود و «۰٪» گمراه بود.
                var pctEl = el.querySelector('.cat-breakdown-pct');
                if (pctEl) { pctEl.textContent = (on && val > 0 && total > 0) ? toPersianDigitsJs(String(pct)) + '٪' : '—'; }

                var bar = el.querySelector('.cat-breakdown-bar');
                if (bar) { bar.style.width = (on ? pct : 0) + '%'; }
            });

            if (chart) {
                var keep = chartData.filter(function (d) { return isOn(d.key) && d.value > 0; });
                chart.data.labels = keep.map(function (d) { return d.name; });
                chart.data.datasets[0].data = keep.map(function (d) { return d.value; });
                repaintAssetChart(keep);
            }
        }

        function repaintAssetChart(keep) {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            var surface = getComputedStyle(document.documentElement)
                .getPropertyValue('--surface').trim() || '#ffffff';
            chart.data.datasets[0].backgroundColor = keep.map(function (d) { return dark ? d.cd : d.cl; });
            chart.data.datasets[0].borderColor = surface;
            chart.update('none');
        }

        items.forEach(function (el) {
            var box = el.querySelector('.js-asset-include');
            if (!box) { return; }
            var key = el.getAttribute('data-key');
            box.checked = isOn(key);
            box.addEventListener('change', function () {
                if (this.checked) { delete off[key]; } else { off[key] = 1; }
                try { localStorage.setItem(STORE, JSON.stringify(off)); } catch (e) {}
                refresh();
            });
        });

        var canvas = document.getElementById('assetChart');
        if (canvas && chartData.length) {
            var box = canvas.closest('.chart-container');
            // Chart.js ممکن است نرسیده باشد؛ در آن صورت به‌جای یک مستطیل
            // خالی، فهرست رنگی پایین به‌تنهایی کار می‌کند.
            if (!window.Chart) { if (box) box.hidden = true; }
            else {
                chart = new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: { labels: [], datasets: [{ data: [], borderWidth: 2 }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: { legend: { display: false } }
                    }
                });
                // با عوض شدن حالت شب، رنگ قاچ‌ها هم باید عوض شود
                window.onThemeChange(function () {
                    var keep = chartData.filter(function (d) { return isOn(d.key) && d.value > 0; });
                    repaintAssetChart(keep);
                });
            }
        }

        refresh();
    })();

    // ---------- فیلتر فهرست دارایی‌ها ----------
    // وقتی اقلام زیاد می‌شوند، پیدا کردن یکی‌شان با اسکرول سخت است.
    var assetFilter = document.getElementById('assetFilter');
    if (assetFilter) {
        var assetItems = [].slice.call(document.querySelectorAll('#assetBreakdown .asset-item'));
        var noMatch = document.getElementById('assetNoMatch');
        assetFilter.addEventListener('input', function () {
            var q = toLatinDigitsJs(this.value).trim().toLowerCase();
            var shown = 0;
            assetItems.forEach(function (el) {
                var name = (el.getAttribute('data-name') || '').toLowerCase();
                var hit = q === '' || name.indexOf(q) !== -1;
                el.hidden = !hit;
                if (hit) shown++;
            });
            if (noMatch) noMatch.hidden = shown !== 0;
        });
    }

    // ---------- معاملات (خرید و فروش) ----------
    (function () {
        // روشن کردن بخش از صفحه‌ی خودش (وقتی خاموش است)
        var enableBtn = document.getElementById('enableTradesBtn');
        if (enableBtn) {
            enableBtn.addEventListener('click', function () {
                enableBtn.disabled = true;
                var fd = new FormData();
                fd.append('csrf_token', csrf());
                fd.append('enabled', '1');
                fetch(apiUrl('toggle_trades.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.success) { window.reloadPage(); return; }
                        enableBtn.disabled = false;
                        var m = document.getElementById('enableTradesMsg');
                        if (m) { m.hidden = false; m.classList.add('show', 'error'); m.textContent = d.message || 'خطا'; }
                    })
                    .catch(function () { enableBtn.disabled = false; });
            });
        }

        var tradeForm = document.getElementById('tradeForm');
        var assetForm = document.getElementById('assetSellForm');
        // زبانه‌ی «دارایی» فرم خرید ندارد ولی فرم فروش دارایی دارد
        if (!tradeForm && !assetForm) return;

        // مبلغ‌ها هنگام تایپ فارسی و هزارگان‌دار می‌شوند — 1000000 ← ۱٬۰۰۰٬۰۰۰
        setupAmountFormatter('trade_buy_total');
        setupAmountFormatter('trade_side_costs');
        setupAmountFormatter('sell_total');

        // ---- حالت نمایش: کارتی / فهرستی (مثل حالت‌های نمایش ویندوز) ----
        var wrap = document.getElementById('tradesWrap');
        var viewBtns = document.querySelectorAll('.view-switch-btn');
        function applyViewMode(mode) {
            if (!wrap) return;
            wrap.classList.toggle('view-list', mode === 'list');
            viewBtns.forEach(function (b) {
                b.classList.toggle('active', b.getAttribute('data-view-mode') === mode);
            });
            try { localStorage.setItem('tradesViewMode', mode); } catch (e) {}
        }
        var savedMode = 'card';
        try { savedMode = localStorage.getItem('tradesViewMode') || 'card'; } catch (e) {}
        applyViewMode(savedMode);
        viewBtns.forEach(function (b) {
            b.addEventListener('click', function () { applyViewMode(this.getAttribute('data-view-mode')); });
        });
        // در حالت فهرستی، لمس ردیف جزئیاتش را باز می‌کند
        if (wrap) {
            wrap.addEventListener('click', function (e) {
                if (!wrap.classList.contains('view-list')) return;
                if (e.target.closest('button, a, input, select, textarea')) return;
                var card = e.target.closest('.trade-card');
                if (card) card.classList.toggle('expanded');
            });
        }

        function setJdpByHidden(hiddenEl, gDateStr) {
            if (!window.JalaliDatePicker || !gDateStr) return;
            var parts = gDateStr.split('-').map(Number);
            var j = window.JalaliDatePicker.gregorianToJalali(parts[0], parts[1], parts[2]);
            function pad2(n) { return (n < 10 ? '0' : '') + n; }
            hiddenEl.value = gDateStr;
            hiddenEl.closest('.jdp-field').querySelector('.jdp-display').value =
                window.JalaliDatePicker.toFa(j[0]) + '/' + window.JalaliDatePicker.toFa(pad2(j[1])) + '/' + window.JalaliDatePicker.toFa(pad2(j[2]));
        }
        // زبانه‌ی «دارایی» فرم خرید ندارد، پس تاریخ امروز را از هر فرمی که
        // در صفحه هست برمی‌داریم.
        var todayHidden = document.getElementById('trade_buy_date')
                       || document.getElementById('asset_sell_date');
        var todayG = todayHidden ? todayHidden.value : '';

        // ---- پرداخت: نقدی یا امانی/نسیه ----
        // ⛔ نسیه یعنی هیچ حسابی درگیر نیست، پس منوی حساب پنهان می‌شود و
        //    توضیحِ «در طلب و بدهی ثبت می‌شود» دیده می‌شود. خودِ تصمیم سمتِ
        //    سرور است (`pay_mode`)؛ اینجا فقط نما.
        function applyPayMode(sel) {
            if (!sel) return;
            var credit = sel.value === 'credit';
            var box = document.getElementById(sel.getAttribute('data-wallet-box'));
            var hint = document.getElementById(sel.getAttribute('data-credit-hint'));
            if (box) box.hidden = credit;
            if (hint) hint.hidden = !credit;
            // در نسیه نامِ طرف لازم است (سرور بی‌آن ۴۲۲ می‌دهد)؛ برچسبِ
            // «(اختیاری)» آنجا دروغ می‌گفت.
            var pIn = document.getElementById(sel.getAttribute('data-person') || '');
            var pLbl = pIn ? pIn.closest('.form-group').querySelector('.person-label-row label, label') : null;
            if (pLbl) {
                if (!pLbl.dataset.orig) pLbl.dataset.orig = pLbl.textContent;
                pLbl.textContent = credit ? pLbl.dataset.orig.replace(/\s*\(اختیاری\)\s*$/, '') + ' — لازم' : pLbl.dataset.orig;
            }
        }
        function setPayMode(id, mode) {
            var sel = document.getElementById(id);
            if (!sel) return;
            sel.value = mode === 'credit' ? 'credit' : 'paid';
            applyPayMode(sel);
        }
        document.querySelectorAll('.js-pay-mode').forEach(function (sel) {
            sel.addEventListener('change', function () { applyPayMode(this); });
            applyPayMode(sel);
        });

        var addBtn = document.getElementById('addTradeBtn');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                document.getElementById('tradeModalTitle').textContent = 'خرید جدید';
                document.getElementById('trade_id').value = '';
                document.getElementById('trade_title').value = '';
                document.getElementById('trade_qty').value = '1';
                document.getElementById('trade_buy_total').value = '';
                document.getElementById('trade_side_costs').value = '';
                resetSelect(document.getElementById('trade_wallet'));
                setPayMode('trade_pay_mode', 'paid');
                syncPersonPicker('trade_counterparty', '');
                document.getElementById('trade_notes').value = '';
                setJdpByHidden(document.getElementById('trade_buy_date'), todayG);
                var m = document.getElementById('tradeMessage');
                m.hidden = true; m.classList.remove('show', 'error', 'success');
                openModal('tradeModal');
            });
        }

        document.querySelectorAll('.js-edit-trade').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('tradeModalTitle').textContent = 'ویرایش معامله';
                document.getElementById('trade_id').value = this.getAttribute('data-id');
                document.getElementById('trade_title').value = this.getAttribute('data-title');
                document.getElementById('trade_qty').value = this.getAttribute('data-qty');
                document.getElementById('trade_buy_total').value = this.getAttribute('data-buy-total');
                var sc = this.getAttribute('data-side-costs');
                document.getElementById('trade_side_costs').value = sc === '0' ? '' : sc;
                document.getElementById('trade_wallet').value = this.getAttribute('data-wallet') || '0';
                setPayMode('trade_pay_mode', this.getAttribute('data-pay-mode'));
                syncPersonPicker('trade_counterparty', this.getAttribute('data-counterparty') || '');
                document.getElementById('trade_notes').value = this.getAttribute('data-notes') || '';
                setJdpByHidden(document.getElementById('trade_buy_date'), this.getAttribute('data-buy-date'));
                var m = document.getElementById('tradeMessage');
                m.hidden = true; m.classList.remove('show', 'error', 'success');
                openModal('tradeModal');
            });
        });

        // ⚠ زبانه‌ی «دارایی» فقط فرمِ فروش دارد؛ بی این شرط اینجا خطا می‌داد
        //   و بقیه‌ی بلوک (فروش و حذف) بی‌صدا اجرا نمی‌شد.
        if (tradeForm) {
            tradeForm.addEventListener('submit', function (e) {
                e.preventDefault();
                submitJson(tradeForm, apiUrl('save_trade.php'),
                    document.getElementById('tradeMessage'),
                    document.getElementById('tradeSubmitBtn'));
            });
        }

        // ---- فروش ----
        var sellForm = document.getElementById('sellForm');
        document.querySelectorAll('.js-sell-trade').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var remaining = this.getAttribute('data-remaining') || '1';
                document.getElementById('sellModalTitle').textContent = 'فروش: ' + this.getAttribute('data-title');
                document.getElementById('sell_trade_id').value = this.getAttribute('data-id');
                document.getElementById('sell_total').value = '';
                document.getElementById('sell_qty').value = remaining;
                resetSelect(document.getElementById('sell_wallet'));
                setPayMode('sell_pay_mode', 'paid');
                syncPersonPicker('sell_counterparty', '');
                document.getElementById('sell_notes').value = '';
                document.getElementById('sellRemainingHint').textContent =
                    remaining === '1' ? '' : 'مانده: ' + remaining;
                setJdpByHidden(document.getElementById('sell_date'), todayG);
                var m = document.getElementById('sellMessage');
                m.hidden = true; m.classList.remove('show', 'error', 'success');
                openModal('sellModal');
            });
        });
        if (sellForm) {
            sellForm.addEventListener('submit', function (e) {
                e.preventDefault();
                submitJson(sellForm, apiUrl('sell_trade.php'),
                    document.getElementById('sellMessage'),
                    document.getElementById('sellSubmitBtn'));
            });
        }

        // ---- فروش دارایی (زبانه‌ی «دارایی») ----
        var assetSellForm = document.getElementById('assetSellForm');
        document.querySelectorAll('.js-sell-asset').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var remaining = this.getAttribute('data-remaining') || '1';
                var unit = this.getAttribute('data-unit') || '';
                document.getElementById('assetSellTitle').textContent = 'فروش: ' + this.getAttribute('data-title');
                document.getElementById('asset_sell_id').value = this.getAttribute('data-id');
                document.getElementById('asset_sell_total').value = '';
                document.getElementById('asset_sell_qty').value = remaining;
                resetSelect(document.getElementById('asset_sell_wallet'));
                document.getElementById('asset_sell_notes').value = '';
                document.getElementById('assetSellRemaining').textContent = 'موجودی: ' + remaining + ' ' + unit;
                setJdpByHidden(document.getElementById('asset_sell_date'), todayG);
                var m = document.getElementById('assetSellMessage');
                m.hidden = true; m.classList.remove('show', 'error', 'success');
                openModal('assetSellModal');
            });
        });
        if (assetSellForm) {
            setupAmountFormatter('asset_sell_total');
            assetSellForm.addEventListener('submit', function (e) {
                e.preventDefault();
                submitJson(assetSellForm, apiUrl('sell_asset.php'),
                    document.getElementById('assetSellMessage'),
                    document.getElementById('assetSellSubmitBtn'));
            });
        }

        // ---- حذف ----
        document.querySelectorAll('.js-del-trade').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('این معامله با همه‌ی فروش‌هایش حذف شود؟ قابل بازگشت نیست.')) return;
                var fd = new FormData();
                fd.append('csrf_token', csrf());
                fd.append('trade_id', this.getAttribute('data-id'));
                fetch(apiUrl('delete_trade.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d.success) { window.reloadPage(); } else { alert(d.message || 'خطا'); } })
                    .catch(function () { alert(netErr()); });
            });
        });

        document.querySelectorAll('.js-del-sale').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('این فروش حذف شود؟')) return;
                var fd = new FormData();
                fd.append('csrf_token', csrf());
                fd.append('sale_id', this.getAttribute('data-id'));
                fetch(apiUrl('delete_trade_sale.php'), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d.success) { window.reloadPage(); } else { alert(d.message || 'خطا'); } })
                    .catch(function () { alert(netErr()); });
            });
        });
    })();

    /* ── پاسخِ آماده‌ی پشتیبانی ──
       انتخابگر فقط متن را داخلِ همان `<textarea>` می‌ریزد و چیزی
       نمی‌فرستد: مدیر باید بتواند قبلِ ارسال ویرایشش کند.

       ⚠ متنِ قبلی **پاک نمی‌شود** بلکه پاسخِ آماده به آن اضافه می‌شود؛
         وگرنه یک انتخابِ اشتباه، جمله‌ای را که مدیر نوشته بی‌صدا از
         بین می‌برد و راهِ برگشتی هم ندارد.

       ⛔ بیرون از بلوکِ «معاملات» است: آنجا پشتِ
         `if (!tradeForm && !assetForm) return;` بود و در `admin/support.php`
         (که هیچ‌کدام را ندارد) هرگز اجرا نمی‌شد. */
    (function () {
        var sel = document.getElementById('supCanned');
        var box = document.getElementById('supAdminReply');
        if (!sel || !box || !window.SUPPORT_CANNED) return;
        sel.addEventListener('change', function () {
            var body = window.SUPPORT_CANNED[sel.value];
            if (!body) return;
            box.value = box.value.trim() === '' ? body : (box.value.replace(/\s+$/, '') + '\n\n' + body);
            box.focus();
            sel.selectedIndex = 0;
        });
    })();

}
document.addEventListener('DOMContentLoaded', function () {
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', appMain, { once: true });
    } else {
        appMain();
    }
});
