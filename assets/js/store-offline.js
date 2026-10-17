/**
 * فروش در حالتِ قطعیِ اینترنت — فقط صفحه‌ی «فروش سریع» (`BizOffline`).
 *
 * ⛔ بی‌اینترنت «ثبت» فرم را نمی‌فرستد (که صفحه‌ی «اینترنت نیست» می‌شد و فروش گم): همان
 *    فیلدهای فرم در `localStorage`ِ همین دستگاه، جدا برای هر فروشگاه، با یک شناسه‌ی یکتا.
 * ⛔ ارسال فقط با فرمِ واقعی (ناوبری) و با دستِ فروشنده — هرگز fetchِ نویسنده (قاعده‌ی
 *    store.js) و هرگز خودکار وسطِ فروشِ بعدی. سرور فقط پیش‌نویس می‌سازد؛ شناسه‌های رسیده
 *    (`data-done`) از صف پاک می‌شوند و خطاها (`data-errors`) کنارِ همان فروش می‌مانند.
 * ⛔ هر دسترسی به `localStorage` در try: حالتِ خصوصی یا فضای پر نباید فرمِ فروش را بشکند.
 */
(function () {
    'use strict';
    var form = document.querySelector('form[data-offline-shop]');
    var box = document.querySelector('[data-offline-box]');
    if (!form || !box) { return; }
    var KEY = 'hl-offline-sales:' + form.getAttribute('data-offline-shop');
    var SKIP = { csrf_token: 1, _once: 1, action: 1 };
    var note = document.querySelector('[data-offline-note]');
    var sync = box.querySelector('[data-offline-sync]');

    function fa(s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
    function load() {
        try { var v = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; }
    }
    function save(list) {
        try { localStorage.setItem(KEY, JSON.stringify(list)); return true; } catch (e) { return false; }
    }
    function newRef() {
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
        return 'r' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    }
    function num(v) { return parseFloat(String(v || '').replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[^\d.]/g, '')) || 0; }
    function summary(sale) {
        var rows = {}, n = 0, sum = 0;
        sale.fields.forEach(function (p) {
            var m = /^lines\[(\d+)\]\[(item|qty|price)\]$/.exec(p[0]);
            if (m) { (rows[m[1]] = rows[m[1]] || {})[m[2]] = p[1]; }
        });
        Object.keys(rows).forEach(function (k) {
            var r = rows[k];
            if (String(r.item || '').trim() === '') { return; }
            n++; sum += num(r.qty || 1) * num(r.price);
        });
        return { n: n, sum: Math.round(sum) };
    }

    // نتیجه‌ی ارسالِ قبلی: رسیده‌ها پاک، خطاها کنارِ همان فروش
    (function settle() {
        var done = [], errs = {};
        try { done = JSON.parse(box.getAttribute('data-done') || '[]'); errs = JSON.parse(box.getAttribute('data-errors') || '{}'); } catch (e) { /* خالی */ }
        if (!done.length && !Object.keys(errs).length) { return; }
        save(load().filter(function (s) { return done.indexOf(s.ref) < 0; }).map(function (s) {
            if (errs[s.ref]) { s.err = errs[s.ref]; }
            return s;
        }));
    })();

    function render() {
        var list = load(), ul = box.querySelector('[data-offline-list]');
        if (note) { note.hidden = navigator.onLine; }
        box.hidden = list.length === 0;
        if (!list.length) { return; }
        box.querySelector('[data-offline-title]').textContent = fa(list.length) + ' فروشِ آفلاین در این دستگاه';
        sync.querySelector('button').disabled = !navigator.onLine;
        ul.textContent = '';
        list.forEach(function (s) {
            var x = summary(s), li = document.createElement('li');
            var t = document.createElement('span');
            t.textContent = new Date(s.at).toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' })
                + ' · ' + fa(x.n) + ' قلم · ' + fa(x.sum.toLocaleString('en-US').replace(/,/g, '٬')) + ' تومان';
            li.appendChild(t);
            if (s.err) { var e = document.createElement('small'); e.className = 'st-offline-err'; e.textContent = s.err; li.appendChild(e); }
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'st-link-btn'; b.textContent = 'حذف';
            b.addEventListener('click', function () {
                if (!confirm('این فروش از این دستگاه پاک شود؟ (هنوز در سرور ثبت نشده است)')) { return; }
                save(load().filter(function (o) { return o.ref !== s.ref; }));
                render();
            });
            li.appendChild(b);
            ul.appendChild(li);
        });
    }

    function clearForm() {
        form.querySelectorAll('[data-lines] input').forEach(function (i) { if (i.type !== 'hidden' || /\[(product_id|pid)\]/.test(i.name)) { i.value = ''; } });
        var d = form.querySelector('[data-discount]'); if (d) { d.value = ''; }
        var first = form.querySelector('[data-lines] [data-item]');
        if (first) { first.dispatchEvent(new Event('input', { bubbles: true })); first.focus(); }
    }

    // ⛔ فاز گرفتن روی window: پیش از دست‌گیرهای فرم (غیرفعال کردنِ دکمه‌ها) — فرمی که نمی‌رود نباید قفل بماند
    window.addEventListener('submit', function (e) {
        if (e.target !== form || navigator.onLine) { return; }
        var act = e.submitter ? e.submitter.value : '';
        if (act === 'addrows') { return; }
        e.preventDefault();
        e.stopPropagation();
        var fields = [];
        new FormData(form).forEach(function (v, k) { if (!SKIP[k] && typeof v === 'string') { fields.push([k, v]); } });
        var sale = { ref: newRef(), at: Date.now(), fields: fields };
        if (summary(sale).n === 0) { alert('ردیفی برای فروش نیست.'); return; }
        var list = load();
        list.push(sale);
        if (!save(list)) { alert('جای ذخیره در این دستگاه نیست — فروش ذخیره نشد.'); return; }
        clearForm();
        render();
    }, true);

    sync.addEventListener('submit', function (e) {
        var list = load().slice(0, 50).map(function (s) { return { ref: s.ref, at: s.at, fields: s.fields }; });
        if (!list.length || !navigator.onLine) { e.preventDefault(); return; }
        sync.querySelector('input[name="sales"]').value = JSON.stringify(list);
    });

    window.addEventListener('online', render);
    window.addEventListener('offline', render);
    render();
})();
