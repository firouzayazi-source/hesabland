/**
 * اسکنِ بارکد و IMEI با دوربینِ گوشی — فروشگاه (هر صفحه).
 *
 * خواسته‌ی مالکِ نصب (مهر ۱۴۰۵): جعبه را جلوی دوربین بگیرند و IMEI خودش در فاکتور
 * بنشیند — هم سریع‌تر، هم بی‌اشتباهِ تایپ.
 *
 * ⛔ فقط **بهبود**: کنارِ هر خانه‌ی بارکد/IMEI یک دکمه‌ی دوربین می‌گذارد و مقدارِ خوانده‌شده
 *    را **همان‌طور که کاربر تایپ می‌کرد** در خانه می‌نشاند (رویدادهای input/change، و برای
 *    خانه‌ی اسکنِ فروشِ سریع همان Enter) — هیچ مسیرِ ثبتِ دومی ساخته نمی‌شود.
 * ⛔ فقط با `BarcodeDetector`ِ خودِ مرورگر (کروم اندروید، همان که اپ رویش است) — بی‌کتابخانه‌ی
 *    بیرونی. مرورگری که ندارد (iOS) اصلاً دکمه نمی‌بیند؛ ورودِ دستی سرِ جایش است.
 * ⛔ جعبه‌ی گوشی چند بارکد دارد (EAN، سریال، IMEI ۱ و ۲): خانه‌ی IMEI فقط عددِ ۱۵ رقمیِ
 *    سالم (لون) را می‌پذیرد (`pick()`)، و اگر IMEI۱ پر است IMEI دیگری را برای IMEI۲ برمی‌دارد.
 * ⛔ هیچ درخواستی به سرور نمی‌فرستد (قاعده‌ی «store.js بی‌POST» — پیش‌گیری).
 */
(function () {
    'use strict';

    function luhn(d) {
        var s = 0;
        for (var i = 0; i < d.length; i++) {
            var n = d.charCodeAt(d.length - 1 - i) - 48;
            if (i % 2 === 1) { n *= 2; if (n > 9) { n -= 9; } }
            s += n;
        }
        return s % 10 === 0;
    }

    /**
     * از مقدارهای خوانده‌شده کدام را برداریم.
     * mode = 'imei': فقط ۱۵ رقمِ لون‌سالم (فاصله و خط تیره حذف) و نه آن‌که در `skip` است؛ 'any': همان IMEI اگر بود، وگرنه اولین مقدارِ ناخالی.
     */
    function pick(values, mode, skip) {
        var first = null;
        for (var i = 0; i < values.length; i++) {
            var v = String(values[i] == null ? '' : values[i]).trim();
            if (v === '') { continue; }
            if (first === null) { first = v; }
            var d = v.replace(/[\s\-\/]/g, '').replace(/^IMEI:?/i, '');
            if (/^\d{15}$/.test(d) && luhn(d) && d !== skip) { return d; }
        }
        // ⛔ خانه‌ی عمومی (فروشِ سریع): IMEI اگر بود (دستگاهِ مشخص)، وگرنه اولین بارکد (لوازم با EAN)
        return mode === 'imei' ? null : first;
    }

    if (typeof module !== 'undefined' && module.exports) { module.exports = { pick: pick, luhn: luhn }; return; }

    var canScan = 'BarcodeDetector' in window && navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function';
    if (!canScan) { return; }

    var SEL = 'input[data-scan], input[data-imei1], input[data-imei2], input[data-scan-cam]';   // ⚠ فقط input — `data-imei1` روی <option>ِ فهرستِ گوشی‌ها هم هست
    var IMEI = 'input[data-imei1], input[data-imei2], input[name$="[imei1]"], input[name$="[imei2]"]';
    var WANT = ['code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_39', 'qr_code', 'data_matrix'];

    function decorate(inp) {
        if (inp.dataset.camReady || inp.type === 'hidden') { return; }
        inp.dataset.camReady = '1';
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'st-cam-btn';
        b.setAttribute('aria-label', 'اسکن با دوربین');
        b.title = 'اسکن با دوربین';
        b.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M10 8v8M13 8v8M17 8v8"/></svg>';
        inp.insertAdjacentElement('afterend', b);
        if (inp.parentElement) { inp.parentElement.classList.add('st-cam-wrap'); }
    }

    function scanAll(root) { root.querySelectorAll(SEL).forEach(decorate); }

    function sibling(inp) {
        // خانه‌ی IMEI۲: IMEI۱ِ همان ردیف دوباره برداشته نشود
        if (!inp.matches('input[data-imei2], input[name$="[imei2]"]')) { return null; }
        var row = inp.closest('[data-row], [data-imei-box], fieldset, form');
        var one = row && row.querySelector('input[data-imei1], input[name$="[imei1]"]');
        return one ? one.value.replace(/\D/g, '') : null;
    }

    var ui = null, stream = null, timer = 0, target = null;

    function close() {
        if (timer) { clearInterval(timer); timer = 0; }
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        if (ui) { ui.hidden = true; }
        target = null;
    }

    function build() {
        ui = document.createElement('div');
        ui.className = 'st-cam';
        ui.setAttribute('role', 'dialog');
        ui.setAttribute('aria-label', 'اسکن با دوربین');
        ui.innerHTML = '<video playsinline muted></video><div class="st-cam-frame" aria-hidden="true"></div>'
            + '<p class="st-cam-hint" data-cam-hint></p><button type="button" class="st-btn st-cam-close">بستن</button>';
        ui.querySelector('.st-cam-close').addEventListener('click', close);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && ui && !ui.hidden) { close(); } });
        document.body.appendChild(ui);
    }

    function hint(t) { ui.querySelector('[data-cam-hint]').textContent = t; }

    function put(inp, v) {
        inp.value = v;
        inp.dispatchEvent(new Event('input', { bubbles: true }));
        inp.dispatchEvent(new Event('change', { bubbles: true }));
        if (inp.matches('[data-scan]')) {
            // ⛔ خانه‌ی اسکنِ فروشِ سریع با Enter کالا را اضافه می‌کند — همان مسیرِ اسکنرِ دستی
            inp.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        }
        if (navigator.vibrate) { try { navigator.vibrate(60); } catch (e) {} }
        inp.focus();
    }

    function open(inp) {
        if (!ui) { build(); }
        target = inp;
        var mode = inp.matches(IMEI) ? 'imei' : 'any';
        hint(mode === 'imei' ? 'بارکدِ IMEI روی جعبه یا برچسب را جلوی دوربین بگیرید.' : 'بارکد را جلوی دوربین بگیرید.');
        ui.hidden = false;
        var video = ui.querySelector('video');
        Promise.resolve(typeof BarcodeDetector.getSupportedFormats === 'function' ? BarcodeDetector.getSupportedFormats() : WANT)
            .then(function (have) {
                var formats = WANT.filter(function (f) { return have.indexOf(f) >= 0; });
                var det = new BarcodeDetector(formats.length ? { formats: formats } : undefined);
                return navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
                    .then(function (s) {
                        if (target !== inp) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
                        stream = s;
                        video.srcObject = s;
                        // ⚠ منتظرِ play() نمی‌مانیم: تا اولین فریم نرسد resolve نمی‌شود و دوربینِ کُند
                        //   حلقه را هرگز شروع نمی‌کرد. detect() روی ویدئوی بی‌فریم خطا می‌دهد و دورِ بعد تکرار می‌شود.
                        video.play().catch(function () {});
                        (function () {
                            var busy = false, skip = sibling(inp);
                            timer = setInterval(function () {
                                if (busy || target !== inp) { return; }
                                busy = true;
                                det.detect(video).then(function (codes) {
                                    busy = false;
                                    var v = pick((codes || []).map(function (c) { return c.rawValue; }), mode, skip);
                                    if (v === null) {
                                        if (codes && codes.length && mode === 'imei') { hint('این بارکد IMEI نیست — بارکدِ IMEI را نشانه بگیرید.'); }
                                        return;
                                    }
                                    var t = target;
                                    close();
                                    put(t, v);
                                }, function () { busy = false; });
                            }, 220);
                        })();
                    });
            })
            .catch(function (e) {
                var denied = e && (e.name === 'NotAllowedError' || e.name === 'SecurityError');
                hint(denied ? 'اجازه‌ی دوربین داده نشد — از تنظیماتِ مرورگر یا اپ اجازه دهید، یا دستی وارد کنید.'
                            : 'دوربین باز نشد — دستی وارد کنید.');
                if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
            });
    }

    function boot() {
        scanAll(document);
        // ⛔ کلیک با واگذاری، نه شنونده روی هر دکمه: «افزودنِ ردیف» ردیفِ آماده را **کپی** می‌کند (دکمه
        //    هم کپی می‌شود) و دکمه‌ی کپی‌شده شنونده ندارد — دیده می‌شد ولی کار نمی‌کرد.
        document.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('.st-cam-btn');
            if (!b) { return; }
            var inp = b.previousElementSibling;
            if (inp && inp.matches(SEL)) { e.preventDefault(); open(inp); }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
