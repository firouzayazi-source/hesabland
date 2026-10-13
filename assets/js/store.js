/* @cmd-start ================================================
   ⛔ فرمانِ سریع — تشخیصِ نیت (خالص، بی‌DOM؛ `tests/test_store_cmd.php`
      همین تکه را در node اجرا می‌کند، نه کپیِ آن).
   **خواسته‌ی مالکِ نصب:** «ثبت تراکنش ۵ میلیون»، «فاکتور جدید»، «مشتری
   جدید»، «جستجوی علی رضایی»، «نمایش بدهی‌ها»، «نمایش فروش امروز».
   ⛔ هیچ فرمانی خودش چیزی نمی‌نویسد: هر نیت یک **آدرسِ صفحه‌ی موجود** است
      (با مبلغِ پیش‌پرشده)، و ثبت را همان صفحه با CSRF و سنجشِ سرور انجام
      می‌دهد. نیتِ مبهم (مثلاً مبلغ بدونِ نوع) چند گزینه می‌دهد، نه حدس.
   ============================================================ */
(function (root) {
    'use strict';
    var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';
    /**
     * ⛔ تنها «تا» کردنِ متن در این فایل — همان `BizCommon::fold()`ِ سرور: حروفِ
     *    عربی، «آ/ٱ/أ/إ» ← «ا»، «ؤ» ← «و»، ارقام، اعراب/کشیده، نیم‌فاصله، بزرگی.
     *    جست‌وجوی کالا (`stCmd.fold`) و فرمانِ سریع (`norm`) هر دو از همین؛ پیش از
     *    این `norm` «آ» را نگه می‌داشت و «ایفون» در فرمان «آیفون» را پیدا نمی‌کرد.
     */
    function fold(s) {
        return String(s == null ? '' : s)
            .replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); })
            .replace(/[٠-٩]/g, function (d) { return AR.indexOf(d); })
            .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[آأإٱ]/g, 'ا').replace(/ؤ/g, 'و').replace(/[ةۀ]/g, 'ه')
            .replace(/[\u064B-\u065F\u0670\u0640]/g, '')
            .replace(/[\s\u200c\u200e\u200f]+/g, ' ').trim().toLowerCase();
    }
    var norm = fold;
    /**
     * مبلغ از متن — «۵ میلیون»، «۲٫۵ میلیون»، «۳۰۰ هزار»، «۱,۲۰۰,۰۰۰»، «۵م».
     * ⛔ مبلغ به تومان است (واحدِ همه‌ی فرم‌های فروشگاه). عددِ بی‌واحدِ زیرِ
     *    ۱۰۰۰ مبلغ نیست (شماره‌ی فاکتور یا تعداد است) مگر واحد بگیرد.
     */
    function amount(text) {
        var t = norm(text).replace(/(\d)[,٬](?=\d{3}(\D|$))/g, '$1');
        var re = /(\d+(?:[.٫\/]\d+)?)\s*(میلیارد|میلیون|تومان|هزار|م(?=\s|$)|k(?=\s|$)|m(?=\s|$))?/g, mm, best = 0;
        while ((mm = re.exec(t)) !== null) {
            var n = parseFloat(mm[1].replace(/[٫\/]/, '.'));
            if (!isFinite(n) || n <= 0) { continue; }
            var u = mm[2] || '';
            var mul = u === 'میلیارد' ? 1e9 : (u === 'میلیون' || u === 'م' || u === 'm') ? 1e6 : (u === 'هزار' || u === 'k') ? 1e3 : 1;
            var v = Math.round(n * mul);
            if (mul === 1 && u !== 'تومان' && v < 1000) { continue; }
            if (v > best) { best = v; }
        }
        return best;
    }
    function fa(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '٬').replace(/\d/g, function (d) { return FA[d]; }); }
    var PAY = [
        { re: /(دریافت|وصول|گرفتم|واریز\s*مشتری)/, k: 'receipt', t: 'دریافت از مشتری' },
        { re: /(پرداخت|دادم)/,                    k: 'payment', t: 'پرداخت به تأمین‌کننده' },
        { re: /(هزینه|خرج|قبض|اجاره)/,           k: 'expense', t: 'هزینه‌ی فروشگاه' },
        { re: /درامد/,                            k: 'income',  t: 'درآمدِ متفرقه' },   // «درآمد» بعد از fold
        { re: /انتقال/,                           k: 'transfer', t: 'انتقال بینِ صندوق‌ها' }
    ];
    /**
     * @return {Array<{t:string,s:string,u:string,i:string}>} نیت‌ها به ترتیبِ
     *         احتمال — `u` نسبت به `/store/` است (بی‌اسلشِ اول).
     */
    function parse(text) {
        var q = norm(text), out = [], seen = {};
        if (q === '') { return out; }
        function add(t, s, u, i) { if (!seen[u]) { seen[u] = 1; out.push({ t: t, s: s, u: u, i: i || 'go' }); } }
        var srch = q.match(/^(?:جستجو(?:ی)?|جست و جو(?:ی)?|پیدا کن|بگرد|search)\s+(.+)$/);
        if (srch) {
            // ⚠ عبارت از واژه‌های **خودِ کاربر** (بعد از همان تعدادِ واژه‌ی «جستجو …»)، نه از
            //   متنِ تاشده: سرور نامِ طرف‌حساب را با LIKE می‌گردد و «ارش» «آرش» را پیدا نمی‌کند.
            var words = String(text).replace(/[\s\u200c\u200e\u200f]+/g, ' ').trim().split(' ');
            var term = words.slice(q.slice(0, q.length - srch[1].length).trim().split(' ').length).join(' ') || srch[1];
            add('جستجوی «' + term + '»', 'در مشتری، کالا، فاکتور، چک و صندوق', 'search.php?q=' + encodeURIComponent(term), 'search');
            return out;
        }
        var amt = amount(q), amtQ = amt > 0 ? '&amount=' + amt : '', amtS = amt > 0 ? 'مبلغ ' + fa(amt) + ' تومان' : '';
        // گزارش و فهرست
        var per = q.match(/فروش\s*(?:(?:این|امروز)\s*)?(امروز|دیروز|هفته|ماه|امسال|سال)/);
        if (per) {
            var pm = { 'امروز': 'today', 'دیروز': 'today', 'هفته': 'week', 'ماه': 'month', 'امسال': 'year', 'سال': 'year' }[per[1]];
            add('فروشِ ' + ({ today: 'امروز', week: 'این هفته', month: 'این ماه', year: 'امسال' }[pm]), 'گزارشِ فروش و سود', 'reports.php?p=' + pm, 'chart');
        }
        if (/(بدهکار|طلب\s*(از|ما|ها)|بدهی\s*مشتری)/.test(q)) { add('بدهکاران (طلبِ فروشگاه)', 'مشتری‌هایی که مانده‌ی بدهکار دارند', 'parties.php?f=debtor', 'list'); }
        if (/(طلبکار|بدهی\s*به|بدهی\s*فروشگاه)/.test(q)) { add('طلبکاران (بدهیِ فروشگاه)', 'تأمین‌کننده‌هایی که به آن‌ها بدهکارید', 'parties.php?f=creditor', 'list'); }
        if (/^(نمایش\s*)?بدهی/.test(q) || /نمایش\s*بدهی/.test(q)) {
            add('بدهکاران (طلبِ فروشگاه)', 'مشتری‌هایی که مانده‌ی بدهکار دارند', 'parties.php?f=debtor', 'list');
            add('طلبکاران (بدهیِ فروشگاه)', 'تأمین‌کننده‌هایی که به آن‌ها بدهکارید', 'parties.php?f=creditor', 'list');
        }
        if (/(کم\s*موجود|موجودی\s*کم)/.test(q)) { add('کالاهای کم‌موجودی', 'زیرِ حداقلِ موجودی', 'products.php?f=low', 'list'); }
        if (/ناموجود/.test(q)) { add('کالاهای ناموجود', 'موجودیِ صفر', 'products.php?f=out', 'list'); }
        if (/پیش\s*نویس/.test(q)) { add('پیش‌نویس‌های فاکتور', 'اسنادِ صادرنشده', 'sales.php?f=draft', 'list'); }
        if (/(تسویه\s*نشده|فاکتور(های)?\s*باز|نقد\s*نشده)/.test(q)) { add('فاکتورهای تسویه‌نشده', 'مانده‌ی دریافت‌نشده', 'sales.php?f=open', 'list'); }
        // ساختن
        if (/(مشتری|تامین\s*کننده|تأمین\s*کننده|طرف\s*حساب|شخص)\s*(جدید|تازه)|(تعریف|ثبت)\s*(مشتری|تامین|تأمین)/.test(q)) { add('مشتری یا تأمین‌کننده‌ی تازه', 'تعریفِ طرف‌حساب', 'party.php', 'plus'); }
        if (/(کالا|محصول|جنس|گوشی)\s*(جدید|تازه)|(تعریف|ثبت)\s*(کالا|محصول)/.test(q)) { add('محصولِ تازه', 'تعریفِ کالا یا خدمت', 'product.php', 'plus'); }
        if (/چک/.test(q)) {
            if (/(ثبت|جدید|تازه|دریافت)/.test(q)) { add('ثبتِ چکِ دریافتی', amtS || 'چک از مشتری', 'payment.php?k=receipt&method=cheque' + amtQ, 'plus'); }
            if (/پرداخت/.test(q)) { add('ثبتِ چکِ پرداختی', amtS || 'چک به تأمین‌کننده', 'payment.php?k=payment&method=cheque' + amtQ, 'plus'); }
            add('دفترِ چک', /سررسید|گذشته|معوق/.test(q) ? 'چک‌های سررسیدگذشته' : 'چک‌های در جریان', 'cheques.php' + (/سررسید|گذشته|معوق/.test(q) ? '?f=overdue' : ''), 'list');
        }
        if (/فروش\s*سریع|صندوق\s*فروش/.test(q)) { add('فروش سریع', 'اسکنِ بارکد و صدورِ فوری', 'quick-sale.php', 'plus'); }
        if (/فاکتور\s*خرید|خرید\s*(جدید|تازه|کالا)/.test(q)) { add('فاکتور خرید تازه', 'ورودِ کالا از تأمین‌کننده', 'invoice-edit.php?k=purchase', 'plus'); }
        else if (/فاکتور|فروش\s*(جدید|تازه)/.test(q) && !per) { add('فاکتور فروش تازه', 'صدورِ فاکتور برای مشتری', 'invoice-edit.php?k=sale', 'plus'); }
        if (!/چک/.test(q)) {
            var hit = false;
            PAY.forEach(function (p) { if (p.re.test(q)) { hit = true; add(p.t, amtS || 'ثبتِ تازه', 'payment.php?k=' + p.k + amtQ, 'plus'); } });
            // مبلغِ بی‌نوع («ثبت تراکنش ۵ میلیون») — چند گزینه، نه حدس
            if (!hit && (amt > 0 && (/(تراکنش|ثبت|سند)/.test(q) || /^[\d.,٫\s]+(میلیون|هزار|تومان|م)?\s*$/.test(q)))) {
                add('دریافت از مشتری', amtS, 'payment.php?k=receipt' + amtQ, 'plus');
                add('هزینه‌ی فروشگاه', amtS, 'payment.php?k=expense' + amtQ, 'plus');
                add('پرداخت به تأمین‌کننده', amtS, 'payment.php?k=payment' + amtQ, 'plus');
            }
        }
        return out.slice(0, 6);
    }
    root.stCmd = { norm: norm, fold: fold, amount: amount, parse: parse };
})(typeof window !== 'undefined' ? window : globalThis);
/* @cmd-end */

/* ============================================================
   محیطِ فروشگاهی — بهبودِ پیش‌رونده‌ی فرم‌های سند
   ============================================================
   ⛔ هیچ چیزی به این فایل **بند نیست**: هر فرم بی‌آن هم کار می‌کند
      («افزودنِ ردیف» یک دکمه‌ی فرم است و جمع را سرور حساب می‌کند). این فایل
      فقط سه کار را سریع‌تر می‌کند:
      ۱. پر کردنِ کالا و قیمت از فهرستِ `<datalist id="bizProducts">` (کد و
         قیمت در data-* گزینه‌ها — هیچ JSONی در صفحه نیست)،
      ۲. جمعِ زنده‌ی ردیف و فاکتور — **فقط نمایش**؛ سرور عددِ مرورگر را
         نمی‌پذیرد،
      ۳. خواندنِ بارکد و IMEI در «فروش سریع» (اسکنر متن را تایپ و Enter می‌زند)،
      ۴. بازکردنِ درجای پنلِ «کالای تازه» با «+» (بی‌جاوااسکریپت همان دکمه
         فرم را می‌فرستد و سرور پنل را باز برمی‌گرداند).
   ⛔ IMEI: گوشیِ در انبار در همان datalist یک گزینه است (مقدار = IMEI،
      data-imei1/2). اینجا فقط پیدا و پر می‌شود؛ «در انبار هست؟» را سرور
      هنگامِ صدور می‌سنجد (`BizSerial::check`).
   ⛔ جست‌وجوی کالا یک فهرستِ کشویی خودِ ماست، نه پاپ‌آپِ `<datalist>`:
      **گزارشِ مالکِ نصب** «سرچِ کالا اصلاً کار نمی‌کنه». پاپ‌آپِ مرورگر روی
      گوشی قابلِ اتکا نیست، فقط رشته را عیناً می‌گردد (پس «آيفون»ِ اکسل با
      «آیفون»ِ کیبورد، نیم‌فاصله و ارقامِ فارسی هیچ‌وقت نمی‌خوانند) و انتخاب
      نکردن یعنی متنِ نیمه‌کاره «شرحِ آزاد» می‌شد. `fold()` همان
      `BizCommon::fold()`ِ سرور است. بی‌جاوااسکریپت `list="bizProducts"` سرِ
      جایش می‌ماند؛ با آن، این فایل برش می‌دارد تا دو فهرست روی هم نیفتند.
   ⚠ کشوی منو و «+ ثبت» هیچ ربطی به این فایل ندارند (چک‌باکس و details).
   ============================================================ */
(function () {
    'use strict';

    var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';
    function latin(s) {
        return String(s == null ? '' : s).replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); })
            .replace(/[٠-٩]/g, function (d) { return AR.indexOf(d); });
    }
    function money(s) { var n = parseInt(latin(s).replace(/[^0-9]/g, ''), 10); return isNaN(n) ? 0 : n; }
    function qty(s) {
        var t = latin(s).trim();
        // «1,000» هزار است، نه یک — همان قاعده‌ی `sanitizeQty()` در سرور
        if (/^\d{1,3}(?:[,،]\d{3})+(?:[.٫]\d+)?$/.test(t)) { t = t.replace(/[,،]/g, ''); }
        t = t.replace(/[٫\/,،]/g, '.').replace(/[^0-9.]/g, '');
        if (t === '') { return null; }
        var n = parseFloat(t); return isNaN(n) ? 0 : n;
    }
    // ⛔ مقداری که اینجا در خانه گذاشته می‌شود همان شکلِ تایپ را می‌گیرد (`num-input.js`)
    function fx(el) { if (window.numInput) { window.numInput.format(el); } }
    function fmt(n) {
        var neg = n < 0; n = Math.round(Math.abs(n));
        var s = String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
        return (neg ? '−' : '') + s.replace(/[0-9]/g, function (d) { return FA[d]; });
    }

    // ---------- فهرستِ کالا از datalist ----------
    function imeiNorm(s) { return latin(s).replace(/[\s\u200c\-\/.]+/g, ''); }
    // ⛔ همان `BizCommon::fold()` — یک پیاده‌سازی، بالای همین فایل (`stCmd.fold`)
    var fold = window.stCmd.fold;
    var byName = {}, bySku = {}, byImei = {}, byFold = {}, byId = {}, index = [];
    var dl = document.getElementById('bizProducts');
    if (dl) {
        Array.prototype.forEach.call(dl.options, function (o) {
            var unit = !!o.dataset.imei1;
            var p = { id: o.dataset.id, name: unit ? o.dataset.name : o.value, sku: o.dataset.sku || '', sell: o.dataset.sell, buy: o.dataset.buy,
                      wholesale: o.dataset.wholesale || '', colleague: o.dataset.colleague || '',
                      unit: o.dataset.unit, stock: parseFloat(o.dataset.stock || '0'), track: o.dataset.track === '1',
                      serial: o.dataset.serial === '1', imei1: unit ? o.dataset.imei1 : '', imei2: unit ? (o.dataset.imei2 || '') : '',
                      vex: o.dataset.vex === '1' };
            p.fname = fold(p.name);
            p.hay = p.fname + ' ' + fold(p.sku) + (unit ? ' ' + p.imei1 + ' ' + p.imei2 : '');
            index.push(p);
            if (unit) {
                byImei[p.imei1] = p;
                if (p.imei2) { byImei[p.imei2] = p; }
                return;
            }
            byName[p.name] = p;
            byId[p.id] = p;
            (byFold[p.fname] = byFold[p.fname] || []).push(p);
            if (p.sku) { bySku[latin(p.sku).toLowerCase()] = p; }
        });
    }
    // تطبیقِ دقیق (اسکنر، Enter، تایپِ کاملِ نام) — «از نظرِ آدم یکی» فقط وقتی یکتاست
    function find(text) {
        var t = String(text || '').trim();
        if (t === '') { return null; }
        var f = byFold[fold(t)];
        return byName[t] || bySku[latin(t).toLowerCase()] || byImei[imeiNorm(t)] || (f && f.length === 1 ? f[0] : null);
    }
    /**
     * پیشنهادها: هر تکه‌ی متن باید جایی در نام/کد/IMEI باشد؛ اولویت با نامی
     * که با متن شروع می‌شود. سقف ۸ — فهرستِ بلندتر روی گوشی خوانده نمی‌شود.
     */
    var AC_MAX = 8;
    function suggest(text) {
        var q = fold(text);
        if (q === '') { return []; }
        var toks = q.split(' '), digits = imeiNorm(text), out = [];
        for (var i = 0; i < index.length; i++) {
            var p = index[i], ok = true;
            for (var k = 0; k < toks.length; k++) { if (p.hay.indexOf(toks[k]) < 0) { ok = false; break; } }
            if (!ok && /^\d{4,}$/.test(digits) && p.imei1 && (p.imei1 + ' ' + p.imei2).indexOf(digits) >= 0) { ok = true; }
            if (!ok) { continue; }
            var score = p.fname.indexOf(q) === 0 ? 0 : (fold(p.sku) === q ? 0 : (p.fname.indexOf(q) >= 0 ? 1 : 2));
            out.push({ p: p, s: score + (p.imei1 ? 0.5 : 0) });
        }
        out.sort(function (a, b) { return a.s - b.s || (a.p.name < b.p.name ? -1 : a.p.name > b.p.name ? 1 : 0); });
        return out.slice(0, AC_MAX).map(function (x) { return x.p; });
    }

    // ---------- فهرستِ کشوییِ پیشنهاد (یکی برای همه‌ی خانه‌ها) ----------
    var ac = null, acFor = null, acItems = [], acActive = -1, acPick = null;
    function acEl() {
        if (ac) { return ac; }
        ac = document.createElement('div');
        ac.className = 'st-ac';
        ac.id = 'stAc';
        ac.setAttribute('role', 'listbox');
        ac.hidden = true;
        document.body.appendChild(ac);
        // ⛔ pointerdown نه click: وگرنه blurِ خانه پیش از انتخاب فهرست را می‌بست
        ac.addEventListener('pointerdown', function (e) {
            var o = e.target.closest('[data-ac-i]');
            if (!o) { return; }
            e.preventDefault();
            acChoose(parseInt(o.dataset.acI, 10));
        });
        return ac;
    }
    function acPlace() {
        if (!ac || ac.hidden || !acFor) { return; }
        var r = acFor.getBoundingClientRect(), vw = document.documentElement.clientWidth;
        var w = Math.min(Math.max(r.width, 280), vw - 16);
        var left = Math.min(Math.max(8, r.right - w), vw - w - 8);    // راست‌چین با خودِ خانه
        var below = window.innerHeight - r.bottom;
        ac.style.width = w + 'px';
        ac.style.left = left + 'px';
        if (below < 200 && r.top > below) { ac.style.top = ''; ac.style.bottom = (window.innerHeight - r.top + 4) + 'px'; }
        else { ac.style.bottom = ''; ac.style.top = (r.bottom + 4) + 'px'; }
    }
    function acClose() {
        if (ac) { ac.hidden = true; ac.innerHTML = ''; }
        if (acFor) { acFor.setAttribute('aria-expanded', 'false'); acFor.removeAttribute('aria-activedescendant'); }
        acFor = null; acItems = []; acActive = -1; acPick = null;
    }
    function acMark() {
        if (!ac) { return; }
        Array.prototype.forEach.call(ac.children, function (c, i) { c.classList.toggle('is-active', i === acActive); c.setAttribute('aria-selected', i === acActive ? 'true' : 'false'); });
        if (acActive >= 0 && ac.children[acActive]) {
            ac.children[acActive].scrollIntoView({ block: 'nearest' });
            acFor.setAttribute('aria-activedescendant', ac.children[acActive].id);
        }
    }
    function acOpen(input, onPick) {
        var list = suggest(input.value);
        if (!list.length) {
            if (acFor === input) { acClose(); }
            if (fold(input.value).length >= 2) { acEmpty(input); }
            return;
        }
        var el = acEl();
        acFor = input; acItems = list; acActive = -1; acPick = onPick;
        el.innerHTML = '';
        list.forEach(function (p, i) {
            var o = document.createElement('div');
            o.className = 'st-ac-opt'; o.id = 'stAcO' + i; o.setAttribute('role', 'option'); o.dataset.acI = String(i);
            var n = document.createElement('b'); n.textContent = p.name;              // ⛔ نامِ کاربر → textContent
            var m = document.createElement('span');
            var bits = [];
            if (p.imei1) { bits.push('IMEI ' + p.imei1); } else if (p.sku) { bits.push(p.sku); }
            var pv = priceOf(input, p); if (pv && pv !== '0') { bits.push(fmt(parseInt(pv, 10))); }
            if (p.imei1) { bits.push('گوشیِ در انبار'); } else if (p.track) { bits.push('موجودی ' + fmt(p.stock)); } else { bits.push('خدمت'); }
            m.textContent = bits.join(' · ');
            if (!p.imei1 && p.track && p.stock <= 0) { o.classList.add('is-out'); }
            o.appendChild(n); o.appendChild(m);
            el.appendChild(o);
        });
        el.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-controls', 'stAc');
        acPlace();
    }
    // چیزی پیدا نشد — گفته می‌شود، نه سکوت (سکوت یعنی «جست‌وجو کار نمی‌کند»)
    function acEmpty(input) {
        var el = acEl();
        acFor = input; acItems = []; acActive = -1; acPick = null;
        el.innerHTML = '';
        var o = document.createElement('div');
        o.className = 'st-ac-none';
        o.textContent = input.closest('[data-row]') && input.closest('form').querySelector('[data-np]')
            ? 'کالایی با این نام نیست — با «+» تعریفش کنید.' : 'کالایی با این نام یا کد نیست.';
        el.appendChild(o);
        el.hidden = false;
        input.setAttribute('aria-expanded', 'false');
        acPlace();
    }
    function acChoose(i) {
        var p = acItems[i], input = acFor, cb = acPick;
        acClose();
        if (p && input && cb) { cb(input, p); }
    }
    function priceKeyOf(input) {
        var f = input.closest('form');
        if (f && f.dataset.price === 'buy') { return 'buy'; }
        // ⛔ سطحِ قیمتِ مشتریِ انتخاب‌شده (`BizProducts::LEVELS`): retail → sell
        var sel = f && f.querySelector('[data-party]'), o = sel && sel.selectedOptions && sel.selectedOptions[0];
        var lv = o && o.dataset.level;
        return lv === 'wholesale' || lv === 'colleague' ? lv : 'sell';
    }
    /** قیمتِ کالا برای همین فرم؛ سطحِ خالیِ کالا = قیمتِ فروشِ معمولی (همان `parseLines`). */
    function priceOf(input, p) {
        var k = priceKeyOf(input);
        return (p[k] && p[k] !== '0') ? p[k] : (k === 'buy' ? p.buy : p.sell);
    }
    /** کلیدهای فهرست؛ true یعنی رویداد مصرف شد. */
    function acKey(e) {
        if (!ac || ac.hidden || acFor !== e.target) { return false; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!acItems.length) { return true; }
            e.preventDefault();
            acActive = (acActive + (e.key === 'ArrowDown' ? 1 : -1) + acItems.length) % acItems.length;
            acMark();
            return true;
        }
        if (e.key === 'Escape') { e.preventDefault(); acClose(); return true; }
        if (e.key === 'Enter' && acActive >= 0) { e.preventDefault(); acChoose(acActive); return true; }
        return false;
    }
    window.addEventListener('scroll', acPlace, { passive: true, capture: true });
    window.addEventListener('resize', acPlace);
    document.addEventListener('focusout', function (e) { if (acFor && e.target === acFor) { setTimeout(function () { if (document.activeElement !== acFor) { acClose(); } }, 120); } });
    // با جاوااسکریپت پاپ‌آپِ مرورگر برداشته می‌شود تا دو فهرست روی هم نیفتند
    if (dl) {
        document.querySelectorAll('input[list="' + dl.id + '"]').forEach(function (inp) {
            inp.removeAttribute('list');
            inp.setAttribute('role', 'combobox');
            inp.setAttribute('aria-autocomplete', 'list');
            inp.setAttribute('aria-expanded', 'false');
        });
    }
    document.querySelectorAll('form[data-invoice]').forEach(function (form) {
        var body = form.querySelector('[data-lines]');
        if (!body) { return; }
        var priceKey = form.dataset.price === 'buy' ? 'buy' : 'sell';

        function rows() { return Array.prototype.slice.call(body.querySelectorAll('[data-row]')); }
        function field(row, name) { return row.querySelector('[data-' + name + ']'); }

        function recalc() {
            var sub = 0, taxable = 0;
            rows().forEach(function (r) {
                var q = qty(field(r, 'qty').value), p = money(field(r, 'price').value), d = money(field(r, 'disc') ? field(r, 'disc').value : '');
                var hasItem = field(r, 'item').value.trim() !== '' || field(r, 'price').value.trim() !== '';
                var lt = hasItem ? Math.round((q === null ? 1 : q) * p) - d : 0;
                var out = field(r, 'lt');
                if (out) { out.textContent = hasItem ? fmt(lt) : ''; }
                sub += lt;
                if (r.dataset.vex !== '1') { taxable += lt; }
            });
            var disc = form.querySelector('[data-discount]'), extra = form.querySelector('[data-extra]');
            var net = sub - (disc ? money(disc.value) : 0) + (extra ? money(extra.value) : 0);
            // ⛔ مالیات بر ارزش افزوده — فقط پیش‌نمایش؛ عددِ واقعی را سرور ردیف‌به‌ردیف
            //    می‌سازد (`BizInvoices::totals()`) و ممکن است یکی‌دو تومان گردتر باشد
            var rateIn = form.querySelector('[data-vat-rate]'), taxOut = form.querySelector('[data-tax]');
            var rate = rateIn ? parseFloat(String(rateIn.value).replace(/[۰-۹]/g, function (c) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(c); }).replace(/[٫\/,]/g, '.')) || 0 : 0;
            var base = sub > 0 ? taxable + (net - sub) * taxable / sub : (rows().length ? net : 0);
            var tax = rate > 0 ? Math.round(base * rate / 100) : 0;
            if (taxOut) { taxOut.textContent = fmt(tax); }
            var total = net + tax;
            var s = form.querySelector('[data-subtotal]'), t = form.querySelector('[data-total]');
            if (s) { s.textContent = fmt(sub); }
            if (t) { t.textContent = fmt(total); }
            balance(total);
        }

        // ---------- مانده‌ی قبلی / پس از این فاکتور — فقط پیش‌نمایش ----------
        // ⛔ عددِ «قبلی» را سرور در data-balance گذاشته (`BALANCE_SQL`)؛ اینجا
        //    فقط جمع می‌شود و هیچ عددی به سرور برنمی‌گردد.
        var balBox = form.querySelector('[data-balbox]'), party = form.querySelector('[data-party]');
        function sideOf(v) { return v > 0 ? 'بدهکار' : (v < 0 ? 'بستانکار' : 'تسویه'); }
        function balance(total) {
            if (!balBox || !party) { return; }
            var opt = party.options[party.selectedIndex];
            var has = opt && opt.value !== '0' && opt.dataset.balance !== undefined;
            balBox.hidden = !has;
            if (!has) { return; }
            var prev = parseInt(opt.dataset.balance, 10) || 0, sign = parseInt(balBox.dataset.sign, 10) || 1;
            var mode = form.querySelector('[data-paymode]:checked'), amt = form.querySelector('[data-payamount]');
            var now = !mode || mode.value === 'none' ? 0 : (mode.value === 'full' ? total : Math.min(money(amt ? amt.value : ''), total));
            var after = prev + sign * (total - now);
            balBox.querySelector('[data-bal-prev]').textContent = fmt(Math.abs(prev));
            balBox.querySelector('[data-bal-side]').textContent = sideOf(prev);
            balBox.querySelector('[data-bal-after]').textContent = fmt(Math.abs(after));
            balBox.querySelector('[data-after-side]').textContent = sideOf(after);
            var link = balBox.querySelector('[data-bal-link]');
            if (link) { link.href = link.href.replace(/party\.php.*$/, 'party.php?id=' + encodeURIComponent(opt.value)); }
        }
        form.addEventListener('change', function (e) { if (e.target.matches('[data-party],[data-paymode]')) { recalc(); } });

        // ⛔ رقمِ کنترلِ IMEIِ ۱۵ رقمی (Luhn) — فقط هشدار روی همان خانه؛ ثبت بسته نیست
        //    (همان `BizSerial::luhnOk()` سمتِ سرور، که پیامِ صدور را هم می‌سازد)
        function luhnBad(v) {
            v = imeiNorm(v);
            if (!/^\d{15}$/.test(v)) { return false; }
            var sum = 0;
            for (var i = 0; i < 15; i++) {
                var d = +v.charAt(14 - i);
                if (i % 2 === 1) { d *= 2; if (d > 9) { d -= 9; } }
                sum += d;
            }
            return sum % 10 !== 0;
        }
        function luhnMark(inp) {
            var bad = luhnBad(inp.value);
            inp.classList.toggle('is-warn', bad);
            inp.title = bad ? 'رقمِ کنترلِ این IMEI درست نیست — با جعبه یا *#06# مقایسه کنید. ثبت انجام می‌شود.' : '';
        }
        form.addEventListener('input', function (e) { if (e.target.matches('[data-imei1],[data-imei2]')) { luhnMark(e.target); } });
        form.querySelectorAll('[data-imei1],[data-imei2]').forEach(luhnMark);

        // «شرحِ کالا»: گوشی → IMEI، بقیه → توضیح (توضیحِ نوشته‌شده هرگز پنهان نمی‌شود)
        function imeiBox(row, show) {
            var box = row.querySelector('[data-imei-box]'), note = field(row, 'note');
            if (!box) { return; }
            var filled = field(row, 'imei1').value.trim() !== '' || field(row, 'imei2').value.trim() !== '';
            box.hidden = !(show || filled);
            if (note) { note.hidden = !box.hidden && note.value.trim() === ''; }
        }
        function apply(row, p) {
            var item = field(row, 'item'), pid = field(row, 'pid'), price = field(row, 'price');
            if (p) {
                item.value = p.name;
                pid.value = p.id;
                if (p.vex) { row.dataset.vex = '1'; } else { delete row.dataset.vex; }
                if (p.imei1) {                                  // یک گوشیِ مشخص: IMEIها و مقدارِ ۱
                    field(row, 'imei1').value = p.imei1;
                    field(row, 'imei2').value = p.imei2 || '';
                    field(row, 'qty').value = '1'; fx(field(row, 'qty'));
                }
                imeiBox(row, p.serial);
                if (p.serial && !p.imei1 && field(row, 'imei1').value.trim() === '') { field(row, 'imei1').focus(); }
                var pv = priceOf(item, p); if (price.value.trim() === '' && pv && pv !== '0') { price.value = pv; fx(price); }
                row.__p = p;
                overMark(row);
                item.title = p.track ? ('موجودی: ' + fmt(p.stock) + ' ' + (p.unit || '')) : '';
            } else {
                pid.value = '';                                 // ⛔ شناسه‌ی کهنه هرگز روی ردیفِ عوض‌شده نمی‌ماند
                delete row.dataset.vex;
                row.__p = null;
                overMark(row);
                imeiBox(row, false);
            }
        }
        /**
         * بیش از موجودی؟ — خانه‌ی تعداد قرمز، و در فاکتورِ فروش (`data-sp-ok`) زیرِ
         * کالا «+ موجودی»: همان دکمه‌ی فرمی که سرور می‌سازد (`BizDocView::lineRows`،
         * `name="sp_open"`)، تا بی‌بارگذاریِ صفحه همان لحظه دیده شود. خودِ تصمیمِ
         * صدور همیشه با سرور است.
         */
        var spOk = form.hasAttribute('data-sp-ok');
        function overMark(row) {
            var p = row.__p || null;
            var over = !!(p && priceKey === 'sell' && p.track && !p.imei1 && (qty(field(row, 'qty').value) || 1) > p.stock + 0.0005);
            row.classList.toggle('is-over', over);
            if (!spOk) { return; }
            var cell = row.querySelector('.st-line-item'), mark = cell && cell.querySelector('.st-line-short');
            if (!over) { if (mark) { mark.remove(); } return; }
            if (!mark) {
                mark = document.createElement('span');
                mark.className = 'st-line-short';
                var b = document.createElement('button');
                b.type = 'submit'; b.name = 'sp_open'; b.className = 'st-link-btn st-sp-open';
                b.setAttribute('formnovalidate', ''); b.setAttribute('data-sp-open', '');
                b.textContent = '+ موجودی';
                mark.appendChild(document.createTextNode(''));
                mark.appendChild(b);
                cell.appendChild(mark);
            }
            mark.firstChild.textContent = 'موجودی ' + fmt(p.stock) + ' ' + (p.unit || '') + ' ';
            mark.querySelector('[data-sp-open]').value = String(rows().indexOf(row));
        }

        function renumber(row, i) {
            row.querySelectorAll('input').forEach(function (inp) {
                inp.name = inp.name.replace(/lines\[\d+\]/, 'lines[' + i + ']');
            });
            var plus = row.querySelector('[data-np-open]');
            if (plus) { plus.value = String(i); }
            var spb = row.querySelector('[data-sp-open]');
            if (spb) { spb.value = String(i); }
            var no = row.querySelector('.st-line-no');
            if (no) { no.textContent = fmt(i + 1); }
        }
        function addRow() {
            var all = rows(), last = all[all.length - 1];
            var copy = last.cloneNode(true);
            copy.querySelectorAll('input').forEach(function (inp) { inp.value = ''; inp.removeAttribute('title'); });
            var note = copy.querySelector('.st-line-note'); if (note) { note.remove(); }
            var sh = copy.querySelector('.st-line-short'); if (sh) { sh.remove(); }
            copy.__p = null;
            var box = copy.querySelector('[data-imei-box]'); if (box) { box.hidden = true; }
            var nt = field(copy, 'note'); if (nt) { nt.hidden = false; }
            var lt = field(copy, 'lt'); if (lt) { lt.textContent = ''; }
            copy.classList.remove('is-over');
            body.appendChild(copy);
            renumber(copy, all.length);
            return copy;
        }
        function firstEmpty() {
            var r = rows().filter(function (x) { return field(x, 'item').value.trim() === ''; })[0];
            return r || addRow();
        }

        body.addEventListener('change', function (e) {
            var row = e.target.closest('[data-row]');
            if (row && e.target.matches('[data-item]')) { apply(row, find(e.target.value)); }
            recalc();
        });
        function pickRow(input, p) {
            var row = input.closest('[data-row]');
            apply(row, p);
            recalc();
            var box = row.querySelector('[data-imei-box]'), im = field(row, 'imei1');
            (box && !box.hidden && im && im.value.trim() === '' ? im : field(row, 'qty')).focus();
        }
        body.addEventListener('input', function (e) {
            var row = e.target.closest('[data-row]');
            if (row && e.target.matches('[data-item]')) {
                var p = find(e.target.value);
                // نامِ کامل یا IMEIِ کاملِ یک گوشیِ در انبار (اسکن)
                if (p && (p.name === e.target.value || p.imei1)) { apply(row, p); } else { field(row, 'pid').value = ''; }
                acOpen(e.target, pickRow);
            }
            if (row && e.target.matches('[data-qty]') && row.__p) { overMark(row); }
            recalc();
        });
        form.addEventListener('input', function (e) { if (e.target.matches('[data-discount],[data-extra],[data-payamount],[data-vat-rate]')) { recalc(); } });

        // Enter داخلِ ردیف فرم را نمی‌فرستد؛ به خانه‌ی بعدی می‌رود
        body.addEventListener('keydown', function (e) {
            if (e.target.matches('[data-item]') && acKey(e)) { return; }
            if (e.key !== 'Enter' || !e.target.matches('input')) { return; }
            e.preventDefault();
            var row = e.target.closest('[data-row]');
            if (e.target.matches('[data-item]')) {
                // Enter روی متنِ نیمه‌کاره: اگر فقط یک پیشنهاد هست همان
                var hit = find(e.target.value), sug = hit ? [] : suggest(e.target.value);
                acClose();
                apply(row, hit || (sug.length === 1 ? sug[0] : null));
                var box = row.querySelector('[data-imei-box]'), im = field(row, 'imei1');
                if (box && !box.hidden && im && im.value.trim() === '') { im.focus(); } else { field(row, 'qty').focus(); }
            } else {
                var next = row.nextElementSibling || addRow();
                field(next, 'item').focus();
            }
            recalc();
        });

        var add = form.querySelector('[data-add-rows]');
        if (add) {
            add.addEventListener('click', function (e) { e.preventDefault(); field(addRow(), 'item').focus(); });
        }

        // ---------- فروشِ سریع: بارکد ----------
        var scan = form.querySelector('[data-scan]');
        if (scan) {
            scan.addEventListener('input', function () { scan.classList.remove('is-miss'); acOpen(scan, function (inp, p) { addScanned(p); scan.focus(); }); });
            scan.addEventListener('keydown', function (e) {
                if (acKey(e)) { return; }
                if (e.key !== 'Enter') { return; }
                e.preventDefault();
                acClose();
                var p = find(scan.value), sug = p ? [] : suggest(scan.value);
                if (!p && sug.length === 1) { p = sug[0]; }
                if (!p) { scan.classList.add('is-miss'); scan.select(); return; }
                addScanned(p);
            });
            function addScanned(p) {
                scan.classList.remove('is-miss');
                if (p.imei1) {
                    // گوشی: هر دستگاه ردیفِ خودش؛ اسکنِ دوباره‌ی همان IMEI چیزی اضافه نمی‌کند
                    var dup = rows().filter(function (x) { var f = field(x, 'imei1'); return f && f.value === p.imei1; })[0];
                    if (!dup) { apply(firstEmpty(), p); }
                    scan.value = '';
                    recalc();
                    return;
                }
                var same = rows().filter(function (x) { return field(x, 'pid').value === p.id; })[0];
                if (same) {
                    var q = qty(field(same, 'qty').value);
                    field(same, 'qty').value = String(Math.round(((q === null ? 1 : q) + 1) * 1000) / 1000); fx(field(same, 'qty'));
                    apply(same, p);
                } else {
                    apply(firstEmpty(), p);
                }
                scan.value = '';
                recalc();
            }
        }
        // ---------- «+»: پنلِ کالای تازه، درجا ----------
        var np = form.querySelector('[data-np]');
        if (np) {
            var npRow = np.querySelector('[data-np-row]'), npName = np.querySelector('[data-np-name]');
            var npImei = np.querySelector('[data-np-imei]');
            function npType() { var c = np.querySelector('[data-np-type]:checked'); return c ? c.value : 'goods'; }
            function npSync() { if (npImei) { npImei.hidden = npType() !== 'phone'; } }
            np.addEventListener('change', function (e) { if (e.target.matches('[data-np-type]')) { npSync(); } });
            body.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-np-open]');
                if (!btn) { return; }
                e.preventDefault();
                var row = btn.closest('[data-row]');
                npRow.value = String(rows().indexOf(row));
                var typed = field(row, 'item').value.trim(), digits = imeiNorm(typed);
                if (/^\d{14,17}$/.test(digits)) {
                    var ph = np.querySelector('[data-np-type][value="phone"]'); if (ph) { ph.checked = true; }
                    var i1 = np.querySelector('[name="np[imei1]"]'); if (i1 && i1.value === '') { i1.value = digits; }
                } else if (typed !== '' && npName.value === '') {
                    npName.value = typed;
                }
                npSync();
                np.hidden = false;
                np.scrollIntoView({ block: 'nearest' });
                npName.focus();
            });
            var close = np.querySelector('[data-np-close]');
            if (close) { close.addEventListener('click', function (e) { e.preventDefault(); np.hidden = true; }); }
            npSync();
        }
        // ردیف‌های رندرشده‌ی سرور: کالایشان را بشناس تا تغییرِ تعداد «بیش از موجودی» را
        // همان لحظه نشان دهد. ⚠ نشانِ سرور دست نمی‌خورد — آن جمعِ همه‌ی ردیف‌های همان کالاست.
        rows().forEach(function (r) { var id = field(r, 'pid').value; if (id && byId[id]) { r.__p = byId[id]; } });
        recalc();
    });
})();

/* رنگِ فروشگاه: پیش‌نمایشِ فوری و ذخیره با همان فرم (بهبودِ تدریجی — بی‌اسکریپت
   هم دکمه‌ی «ذخیره‌ی رنگ» کار می‌کند). نام فقط از میانِ رادیوهای خودِ صفحه
   است، پس هیچ مقدارِ دلخواهی روی ویژگی نمی‌نشیند. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var radios = document.querySelectorAll('.st-pal input[name="palette"]');
        if (!radios.length) { return; }
        var first = radios[0].value;
        Array.prototype.forEach.call(radios, function (r) {
            r.addEventListener('change', function () {
                if (r.value === first) { document.documentElement.removeAttribute('data-st-palette'); }
                else { document.documentElement.setAttribute('data-st-palette', r.value); }
                if (r.form && r.form.requestSubmit) { r.form.requestSubmit(); }
            });
        });
    });
})();

/* مشخصاتِ چک در «دریافت/پرداخت»: فقط وقتی روش «چک» است دیده می‌شود.
   ⛔ بی‌اسکریپت همیشه دیده می‌شود (نه `hidden` در HTML)، پس نرسیدنِ این
   فایل فرمِ چک را بی‌صدا نمی‌بندد. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var sel = document.querySelector('[data-cheque-method]');
        var box = document.querySelector('[data-cheque-fields]');
        if (!sel || !box) { return; }
        var sync = function () { box.style.display = sel.value === 'cheque' ? '' : 'none'; };
        sel.addEventListener('change', sync);
        sync();
    });
})();

/* نرخِ ارز (`BizFx`): فقط وقتی طرف‌حسابِ انتخاب‌شده حسابِ ارزی دارد (`data-fx` روی گزینه).
   ⛔ بی‌اسکریپت همیشه دیده می‌شود؛ سرور نرخِ طرف‌حسابِ تومانی را نادیده می‌گیرد. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-fx-field]'), function (box) {
            var form = box.closest('form');
            var sel = form && form.querySelector('select[name="party_id"]');
            if (!sel) { return; }
            var label = box.querySelector('span');
            var sync = function () {
                var o = sel.options[sel.selectedIndex];
                var fx = o ? o.getAttribute('data-fx') : null;
                box.hidden = !fx;
                // ⛔ متن نه HTML — نامِ ارز از ویژگیِ گزینه می‌آید
                if (label && fx) { label.textContent = 'نرخِ ' + fx + ' (تومان برای یک واحد؛ خالی = نرخِ روز)'; }
            };
            sel.addEventListener('change', sync);
            sync();
        });
    });
})();

/* «انتخابِ همه» — چک‌باکسِ `js-check-all` همه‌ی `[data-group]`ِ هم‌نامش را
   تیک می‌زند یا برمی‌دارد (پاک‌سازیِ کالاها). بی‌اسکریپت هر ردیف جدا تیک
   می‌خورد و فرم همان کار را می‌کند. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.js-check-all'), function (all) {
            var boxes = document.querySelectorAll('input[type="checkbox"][data-group="' + all.getAttribute('data-target') + '"]');
            var sync = function () {
                var on = 0;
                Array.prototype.forEach.call(boxes, function (b) { if (b.checked) { on++; } });
                all.checked = on === boxes.length;
                all.indeterminate = on > 0 && on < boxes.length;
            };
            all.addEventListener('change', function () {
                Array.prototype.forEach.call(boxes, function (b) { b.checked = all.checked; });
                sync();
            });
            Array.prototype.forEach.call(boxes, function (b) { b.addEventListener('change', sync); });
            sync();
        });
    });
})();

/* طراحیِ فاکتور: پیش‌نمایشِ زنده. ⛔ فقط آدرسِ قاب عوض می‌شود
   (`print.php?…&preview=1`، فقط خواندنی)؛ ذخیره همچنان با دکمه‌ی فرم است و
   بی‌اسکریپت پیش‌نمایش طراحیِ ذخیره‌شده را نشان می‌دهد. توکنِ CSRF و
   `action` در آدرس نمی‌روند. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form  = document.querySelector('[data-design-form]');
        var frame = document.querySelector('[data-design-preview]');
        if (!form || !frame) { return; }
        var picker = form.querySelector('[data-accent-picker]');
        var custom = form.querySelector('[data-accent-custom]');
        if (picker && custom) {
            picker.addEventListener('input', function () { custom.value = picker.value; custom.checked = true; });
            picker.addEventListener('click', function () { custom.checked = true; });
        }
        var t = null;
        var refresh = function () {
            var q = new URLSearchParams();
            new FormData(form).forEach(function (v, k) {
                if (k !== 'csrf_token' && k !== 'action' && typeof v === 'string') { q.append(k, v); }
            });
            q.set('preview', '1');
            var base = frame.getAttribute('data-base');
            frame.src = base + (base.indexOf('?') >= 0 ? '&' : '?') + q.toString();
        };
        var later = function () { clearTimeout(t); t = setTimeout(refresh, 350); };
        form.addEventListener('input', later);
        form.addEventListener('change', later);
    });
})();


/* ============================================================
   پوسته — حالتِ شب، جمع کردنِ منو، بستنِ منوهای کشویی، Toast، و فرمانِ
   سریع (Ctrl+K). ⛔ همه بهبودِ تدریجی‌اند: دکمه‌های حالتِ شب و «جمع کردن»
   تا رسیدنِ این اسکریپت `hidden` می‌مانند، و جعبه‌ی جست‌وجو بی‌آن یک فرمِ
   GET به `search.php` است.
   ============================================================ */
(function () {
    'use strict';
    var doc = document.documentElement;
    function store(k, v) { try { if (v === null) { localStorage.removeItem(k); } else { localStorage.setItem(k, v); } } catch (e) {} }

    // ---------- حالتِ شب ----------
    // ⛔ پیش‌فرض «خودکار» (پیروی از گوشی) است؛ دکمه‌ی بالا = دستی، و کلیدِ
    //    «خودکار» در تنظیمات (`[data-theme-auto]`) برمی‌گرداند.
    var autoBoxes = document.querySelectorAll('[data-theme-auto]');
    function syncAuto() {
        var own = null; try { own = localStorage.getItem('st_theme'); } catch (e) {}
        Array.prototype.forEach.call(autoBoxes, function (c) { c.checked = !own; });
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-theme-toggle]'), function (b) {
        b.hidden = false;
        b.addEventListener('click', function () {
            var next = doc.getAttribute('data-st-theme') === 'dark' ? 'light' : 'dark';
            doc.setAttribute('data-st-theme', next);
            store('st_theme', next);
            syncAuto();
        });
    });
    Array.prototype.forEach.call(autoBoxes, function (c) {
        c.disabled = false;
        c.addEventListener('change', function () {
            if (c.checked) {
                store('st_theme', null);
                var sys = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches;
                doc.setAttribute('data-st-theme', sys ? 'dark' : 'light');
            } else {
                // خاموش کردنِ «خودکار» همان حالتِ فعلی را دستی نگه می‌دارد
                store('st_theme', doc.getAttribute('data-st-theme') === 'dark' ? 'dark' : 'light');
            }
            syncAuto();
        });
    });
    syncAuto();
    // کاربری که خودش انتخاب نکرده، با سیستم جلو می‌رود
    if (window.matchMedia) {
        var mq = matchMedia('(prefers-color-scheme: dark)');
        var onMq = function () { var own = null; try { own = localStorage.getItem('st_theme'); } catch (e) {} if (!own) { doc.setAttribute('data-st-theme', mq.matches ? 'dark' : 'light'); } };
        if (mq.addEventListener) { mq.addEventListener('change', onMq); }
    }

    // ---------- جمع کردنِ نوارِ کناری ----------
    Array.prototype.forEach.call(document.querySelectorAll('[data-side-mini]'), function (b) {
        b.hidden = false;
        b.addEventListener('click', function () {
            var on = doc.getAttribute('data-st-mini') !== '1';
            if (on) { doc.setAttribute('data-st-mini', '1'); } else { doc.removeAttribute('data-st-mini'); }
            store('st_side_mini', on ? '1' : null);
        });
    });

    // ---------- منوهای کشویی: کلیکِ بیرون و Escape ----------
    document.addEventListener('click', function (e) {
        Array.prototype.forEach.call(document.querySelectorAll('details.st-dd[open]'), function (d) {
            if (!d.contains(e.target)) { d.removeAttribute('open'); }
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { Array.prototype.forEach.call(document.querySelectorAll('details.st-dd[open]'), function (d) { d.removeAttribute('open'); }); }
    });

    // ---------- Toast ----------
    var flash = document.querySelector('[data-toast]');
    if (flash) {
        var box = document.createElement('div');
        box.className = 'st-toasts';
        box.setAttribute('aria-live', 'polite');
        document.body.appendChild(box);
        box.appendChild(flash);
        var x = document.createElement('button');
        x.type = 'button'; x.className = 'st-toast-x'; x.setAttribute('aria-label', 'بستن'); x.textContent = '×';
        flash.appendChild(x);
        var gone = function () { flash.classList.add('is-out'); setTimeout(function () { if (flash.parentNode) { flash.parentNode.removeChild(flash); } }, 260); };
        x.addEventListener('click', gone);
        setTimeout(gone, flash.classList.contains('st-flash-err') ? 9000 : 5000);
    }

    // ---------- نمودار: راهنمای روی نقطه ----------
    Array.prototype.forEach.call(document.querySelectorAll('.st-chart'), function (c) {
        var tip = null;
        c.addEventListener('pointerover', function (e) {
            var h = e.target.closest ? e.target.closest('[data-tip]') : null;
            if (!h) { return; }
            if (!tip) { tip = document.createElement('div'); tip.className = 'st-chart-tip'; c.appendChild(tip); }
            var parts = String(h.getAttribute('data-tip')).split('|');
            tip.textContent = '';
            var b = document.createElement('b'); b.textContent = parts.shift(); tip.appendChild(b);
            parts.forEach(function (p) { var d = document.createElement('div'); d.textContent = p; tip.appendChild(d); });
            var r = h.getBoundingClientRect(), cr = c.getBoundingClientRect();
            var left = Math.max(70, Math.min(cr.width - 70, r.left - cr.left + r.width / 2));
            tip.style.left = left + 'px';
            tip.style.top = Math.max(0, r.top - cr.top) + 'px';
            tip.hidden = false;
            var g = h.nextElementSibling; if (g && g.classList.contains('st-guide')) { g.classList.add('is-on'); }
        });
        c.addEventListener('pointerout', function (e) {
            var h = e.target.closest ? e.target.closest('[data-tip]') : null;
            if (h) { var g = h.nextElementSibling; if (g && g.classList.contains('st-guide')) { g.classList.remove('is-on'); } }
            if (tip && !c.contains(e.relatedTarget)) { tip.hidden = true; }
        });
        c.addEventListener('pointerleave', function () { if (tip) { tip.hidden = true; } });
    });

    // ---------- فرمانِ سریع (Ctrl+K) ----------
    var dataEl = document.getElementById('stCmdData');
    if (!dataEl || !window.stCmd) { return; }
    var DATA;
    try { DATA = JSON.parse(dataEl.textContent); } catch (e) { return; }
    var BASE = DATA.search.replace(/search\.php.*$/, '');
    var ICO = {
        go:     '<path d="M5 12h14M13 6l6 6-6 6"/>',
        plus:   '<path d="M12 5v14M5 12h14"/>',
        search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        chart:  '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        list:   '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        page:   '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/>',
        party:  '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        product:'<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/>',
        invoice:'<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/>',
        cheque: '<rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 14h5"/>',
        account:'<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/>',
        payment:'<path d="M7 7h13l-3-3"/><path d="M17 17H4l3 3"/>'
    };
    var back = null, input = null, list = null, opts = [], active = 0, remote = [], ctl = null, timer = null, lastQ = null;
    function svg(k) { return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICO[k] || ICO.go) + '</svg>'; }
    function build() {
        back = document.createElement('div');
        back.className = 'st-cmd-back'; back.hidden = true;
        back.innerHTML = '<div class="st-cmd" role="dialog" aria-modal="true" aria-label="فرمانِ سریع">'
            + '<div class="st-cmd-in">' + svg('search') + '<input type="text" autocomplete="off" spellcheck="false" placeholder="جستجو یا فرمان — مثلاً «دریافت ۵ میلیون»، «مشتری جدید»، «فروش امروز»" aria-label="فرمان"></div>'
            + '<div class="st-cmd-list" role="listbox"></div>'
            + '<div class="st-cmd-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> جابه‌جایی</span><span><kbd>Enter</kbd> باز کردن</span><span><kbd>Esc</kbd> بستن</span></div></div>';
        document.body.appendChild(back);
        input = back.querySelector('input');
        list = back.querySelector('.st-cmd-list');
        back.addEventListener('pointerdown', function (e) { if (e.target === back) { close(); } });
        input.addEventListener('input', function () { render(); fetchRemote(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); mark(Math.min(opts.length - 1, active + 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); mark(Math.max(0, active - 1)); }
            else if (e.key === 'Enter') { e.preventDefault(); go(active); }
            else if (e.key === 'Escape') { e.preventDefault(); close(); }
        });
        list.addEventListener('pointermove', function (e) { var o = e.target.closest('.st-cmd-opt'); if (o) { mark(+o.getAttribute('data-i'), true); } });
        list.addEventListener('click', function (e) { var o = e.target.closest('.st-cmd-opt'); if (o) { e.preventDefault(); go(+o.getAttribute('data-i')); } });
    }
    function fold(s) { return window.stCmd.norm(s).replace(/\s/g, ''); }
    function render() {
        var q = input.value, fq = fold(q), groups = [];
        var intents = window.stCmd.parse(q).map(function (x) { return { t: x.t, s: x.s, u: BASE + x.u, i: x.i, intent: true }; });
        if (intents.length) { groups.push(['پیشنهادِ فرمان', intents]); }
        var news = DATA['new'].filter(function (n) { return fq === '' || fold(n.t).indexOf(fq) >= 0; }).map(function (n) { return { t: n.t, s: 'ثبتِ جدید', u: n.u, i: 'plus' }; });
        var pages = DATA.pages.filter(function (p) { return fq === '' || fold(p.t).indexOf(fq) >= 0 || fold(p.g).indexOf(fq) >= 0; }).map(function (p) { return { t: p.t, s: p.g, u: p.u, i: 'page' }; });
        if (q.trim() === '') { groups.push(['ثبتِ جدید', news.slice(0, 5)]); groups.push(['رفتن به', pages.slice(0, 8)]); }
        else {
            remote.forEach(function (g) { if (g.items && g.items.length) { groups.push([g.label, g.items.map(function (it) { return { t: it.t, s: it.s || '', u: it.u, i: it.i || 'go', end: it.e || '' }; })]); } });
            if (news.length) { groups.push(['ثبتِ جدید', news.slice(0, 4)]); }
            if (pages.length) { groups.push(['صفحه‌ها', pages.slice(0, 5)]); }
            groups.push(['', [{ t: 'جستجوی کامل برای «' + q.trim() + '»', s: 'همه‌ی نتیجه‌ها در یک صفحه', u: DATA.search + '?q=' + encodeURIComponent(q.trim()), i: 'search' }]]);
        }
        opts = []; list.textContent = '';
        groups.forEach(function (g) {
            if (!g[1].length) { return; }
            if (g[0]) { var h = document.createElement('div'); h.className = 'st-cmd-group'; h.textContent = g[0]; list.appendChild(h); }
            g[1].forEach(function (o) {
                var a = document.createElement('a');
                a.className = 'st-cmd-opt' + (o.intent ? ' is-intent' : '');
                a.href = o.u; a.setAttribute('role', 'option'); a.setAttribute('data-i', String(opts.length));
                var ic = document.createElement('span'); ic.className = 'st-cmd-ico'; ic.innerHTML = svg(o.i);
                var tx = document.createElement('span'); tx.className = 'st-cmd-text';
                var b = document.createElement('b'); b.textContent = o.t; tx.appendChild(b);
                if (o.s) { var sp = document.createElement('span'); sp.textContent = o.s; tx.appendChild(sp); }
                a.appendChild(ic); a.appendChild(tx);
                if (o.end) { var en = document.createElement('span'); en.className = 'st-cmd-end'; en.textContent = o.end; a.appendChild(en); }
                list.appendChild(a);
                opts.push(o);
            });
        });
        if (!opts.length) { var em = document.createElement('div'); em.className = 'st-cmd-empty'; em.textContent = 'چیزی پیدا نشد.'; list.appendChild(em); }
        mark(0);
    }
    function mark(i, noScroll) {
        active = i;
        Array.prototype.forEach.call(list.querySelectorAll('.st-cmd-opt'), function (a) {
            var on = +a.getAttribute('data-i') === i;
            a.classList.toggle('is-active', on); a.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on && !noScroll) { a.scrollIntoView({ block: 'nearest' }); }
        });
    }
    function go(i) { var o = opts[i]; if (o) { window.location.href = o.u; } }
    function fetchRemote() {
        var q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { remote = []; return; }
        timer = setTimeout(function () {
            if (q === lastQ) { return; }
            lastQ = q;
            if (ctl && ctl.abort) { ctl.abort(); }
            ctl = window.AbortController ? new AbortController() : null;
            var term = q.replace(/^(جستجو(ی)?|پیدا کن|بگرد)\s+/, '');
            fetch(DATA.search + '?format=json&q=' + encodeURIComponent(term), { credentials: 'same-origin', signal: ctl ? ctl.signal : undefined, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : { groups: [] }; })
                .then(function (j) { if (input.value.trim() === q) { remote = j.groups || []; render(); } })
                .catch(function () {});
        }, 160);
    }
    function open(prefill) {
        if (!back) { build(); }
        back.hidden = false;
        doc.classList.add('st-locked');
        input.value = prefill || '';
        remote = []; lastQ = null;
        render();
        if (input.value) { fetchRemote(); }
        setTimeout(function () { input.focus(); input.select(); }, 0);
    }
    function close() { if (back) { back.hidden = true; doc.classList.remove('st-locked'); } }
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K' || e.key === 'ک')) { e.preventDefault(); if (back && !back.hidden) { close(); } else { open(''); } }
        else if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '') && !(document.activeElement || {}).isContentEditable) { e.preventDefault(); open(''); }
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-cmd-open]'), function (f) {
        var inp = f.querySelector('input');
        f.addEventListener('submit', function (e) { e.preventDefault(); open(inp ? inp.value : ''); });
        if (inp) {
            inp.addEventListener('focus', function () { var v = inp.value; inp.blur(); open(v); });
        }
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-cmd-link]'), function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); open(''); });
    });
})();

/* تصویرِ شخصی (تنظیمات): پیش از فرستادن در خودِ مرورگر کوچک می‌شود.
   ⛔ `createImageBitmap(file)` (رمزگشایی بیرون از رشته‌ی اصلی) و در نبودش
      `FileReader` + `data:` — هرگز `URL.createObjectURL`: CSP فقط
      `img-src 'self' data:` می‌پذیرد و `blob:` بی‌صدا شکست می‌خورد (قاعده ۵۹).
      رمزگشاییِ یک عکسِ ۱۲ مگاپیکسلی با `<img>`ِ روی رشته‌ی اصلی صفحه را
      ثانیه‌ها قفل کرد (در کرومیومِ آزمایشی اندازه‌گیری شد) — برای همین اول
      `createImageBitmap`.
   عکسِ درشتِ گوشی روی دیتای موبایل کُند است و از سقفِ آپلودِ سرور هم رد
   می‌شد؛ حالا یک JPEGِ ۵۱۲ پیکسلی می‌رود. سرور همچنان خودش می‌سنجد و از نو
   می‌کشد (`saveUserAvatar()`). اگر مرورگر فایل را باز نکند یا `DataTransfer`
   نداشته باشد، همان فایلِ اصلی می‌رود. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var input = document.querySelector('[data-avatar-input]');
        var prev  = document.querySelector('[data-avatar-preview]');
        if (!input) { return; }
        var SIDE = 512;
        var draw = function (src, w, h) {
            var s = Math.min(w, h);
            if (!s) { return; }
            var c = document.createElement('canvas');
            c.width = c.height = Math.min(SIDE, s);
            var g = c.getContext('2d');
            g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
            g.drawImage(src, (w - s) / 2, (h - s) / 2, s, s, 0, 0, c.width, c.height);
            if (src.close) { src.close(); }
            if (prev) {
                prev.classList.add('has-img');
                prev.textContent = '';
                var im = document.createElement('img');
                im.className = 'st-avatar-img'; im.alt = ''; im.src = c.toDataURL('image/jpeg', 0.85);
                prev.appendChild(im);
            }
            if (!c.toBlob || typeof DataTransfer === 'undefined') { return; }
            c.toBlob(function (b) {
                if (!b) { return; }
                try {
                    var dt = new DataTransfer();
                    dt.items.add(new File([b], 'avatar.jpg', { type: 'image/jpeg' }));
                    input.dataset.resized = '1';
                    input.files = dt.files;
                } catch (e) { /* همان فایلِ اصلی */ }
            }, 'image/jpeg', 0.85);
        };
        var viaReader = function (f) {
            if (!window.FileReader) { return; }
            var rd = new FileReader();
            rd.onload = function () {
                var img = new Image();
                img.onload = function () { draw(img, img.naturalWidth, img.naturalHeight); };
                img.src = String(rd.result || '');
            };
            rd.readAsDataURL(f);
        };
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f || input.dataset.resized === '1') { input.dataset.resized = ''; return; }
            if (window.createImageBitmap) {
                createImageBitmap(f).then(function (bm) { draw(bm, bm.width, bm.height); }, function () { viaReader(f); });
            } else {
                viaReader(f);
            }
        });
    });
})();
/* ⛔ دو بار زدن یک ارسال است — همراهِ سدِ سرور (`BizOnce`).
   بازرسیِ مهر ۱۴۰۵: دو بار زدنِ «ثبت» دو فاکتورِ صادرشده می‌ساخت. سرور تکرار
   را می‌گیرد؛ این‌جا فقط درخواستِ دوم اصلاً فرستاده نمی‌شود.
   ⚠ دکمه‌ها **بعد از** شروعِ ارسال غیرفعال می‌شوند (setTimeout): دکمه‌ای که
     وسطِ رویدادِ submit غیرفعال شود، `name/value`ش (مثلاً action=issue_print)
     از فرم می‌افتد و سرور کارِ دیگری می‌کرد.
   ⚠ فرمی که `confirm` ردش کرده (defaultPrevented) قفل نمی‌شود؛ و قفل بعد از
     چند ثانیه باز می‌شود — فرمی که دانلود می‌دهد یا در برگه‌ی تازه باز
     می‌شود از صفحه بیرون نمی‌رود. */
(function () {
    'use strict';
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || f.tagName !== 'FORM' || e.defaultPrevented) { return; }
        if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
        if (f.dataset.sending === '1') { e.preventDefault(); return; }
        f.dataset.sending = '1';
        var btns = f.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]');
        setTimeout(function () { btns.forEach(function (b) { b.disabled = true; }); }, 0);
        setTimeout(function () {
            f.dataset.sending = '';
            btns.forEach(function (b) { b.disabled = false; });
        }, 8000);
    });
    // برگشت با دکمه‌ی «عقب» (bfcache): فرم دوباره زنده
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-sending="1"]').forEach(function (f) {
            f.dataset.sending = '';
            f.querySelectorAll('button, input[type="submit"]').forEach(function (b) { b.disabled = false; });
        });
    });
})();
/* ⛔ روشِ پرداخت ← حساب: «کارت‌خوان» به حسابِ کارت‌خوان، «کارت‌به‌کارت/حواله»
   به بانک، «نقد» به صندوق — اولین حسابِ فعالِ همان نوع، اگر هست.
   بازرسیِ مهر ۱۴۰۵: حساب همیشه اولین (صندوق) می‌ماند، پس دریافتِ کارتی در
   صندوقِ نقدی می‌نشست و جمعِ صندوق با پولِ واقعیِ کشو نمی‌خواند. کاربر
   همچنان می‌تواند بعد از انتخابِ روش، حسابِ دیگری بزند. */
(function () {
    'use strict';
    var KIND = { cash: 'cash', card: 'pos', transfer: 'bank' };
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!el || el.name !== 'method' || !el.form) { return; }
        var want = KIND[el.value];
        var sel = el.form.querySelector('select[data-acc-auto]');
        if (!want || !sel) { return; }
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].getAttribute('data-kind') === want) { sel.selectedIndex = i; return; }
        }
    });
})();
