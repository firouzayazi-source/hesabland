/* ⛔ کارت‌خوان در فرمِ «دریافت» (`BizPos`): start ← دستگاه ← finish. سرور به دستگاه وصل نمی‌شود؛
   این مرورگرِ صندوق است که با سرویسِ محلی یا اپِ دستگاه حرف می‌زند و نتیجه را برمی‌گرداند —
   و پاسخِ سرویس **خام** فرستاده می‌شود تا فقط سرور آن را بخواند (یک خواننده). */
(function () {
    var box = document.querySelector('fieldset[data-pos-box]');
    // ⛔ هر تکه‌ی لازم باید باشد — نیمه‌کاره (قالبِ دیگر، اسکریپتِ کهنه در کش) هیچ کاری نمی‌کند، نه خطا
    if (!box || !box.closest('form') || !box.querySelector('[data-pos-go]') || !box.querySelector('[data-pos-manual]')) { return; }
    var form = box.closest('form'), url = box.getAttribute('data-pos-url');
    var tok = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var q = function (s) { return box.querySelector(s); };
    var status = q('[data-pos-status]'), manual = q('[data-pos-manual]'), txnIn = q('[data-pos-txn]');
    var cur = null;
    function say(t) { status.textContent = t; }
    /* ⛔ درخواستِ نویسنده: صفحه‌های از-پیش-آماده (speculation rules) کهنه می‌شوند — همان
       `resetSpeculation`ِ پوسته‌ی شخصی: قاعده برداشته و دوباره گذاشته می‌شود (store.js عمداً
       هیچ درخواستِ نویسنده‌ای ندارد؛ این فایل فقط در فرمِ دریافت بار می‌شود). */
    function resetSpec() {
        var old = document.getElementById('stSpecRules');
        if (!old || !old.parentNode) { return; }
        var parent = old.parentNode, text = old.textContent;
        parent.removeChild(old);
        setTimeout(function () {
            if (document.getElementById('stSpecRules')) { return; }
            var fresh = document.createElement('script');
            fresh.type = 'speculationrules'; fresh.id = 'stSpecRules'; fresh.textContent = text;
            parent.appendChild(fresh);
        }, 0);
    }
    function post(data) {
        var b = new URLSearchParams(data); b.append('csrf_token', tok);
        return fetch(url, { method: 'POST', body: b, credentials: 'same-origin' })
            .then(function (r) { resetSpec(); return r.json(); });
    }
    function done(r) {
        manual.hidden = true;
        if (r.ok) { txnIn.value = String(cur); say('✓ ' + r.message + ' حالا «ثبت» را بزنید.'); }
        else { txnIn.value = ''; say('✗ ' + r.message); }
    }
    q('[data-pos-go]').addEventListener('click', function () {
        var sel = q('[data-pos-terminal]'), opt = sel.options[sel.selectedIndex];
        var acc = form.querySelector('[name="account_id"]'), method = form.querySelector('[name="method"]');
        if (acc) { acc.value = opt.getAttribute('data-account'); }           // پول به صندوقِ همان کارت‌خوان
        if (method) { method.value = 'card'; method.dispatchEvent(new Event('change')); }
        var amount = (form.querySelector('[name="amount"]') || {}).value || '';
        say('در حالِ آماده کردن…');
        post({ action: 'start', terminal: sel.value, amount: amount }).then(function (r) {
            if (!r.ok) { say('✗ ' + r.message); return; }
            cur = r.id; txnIn.value = '';
            q('[data-pos-amount]').textContent = Number(r.amount).toLocaleString('fa-IR');
            var c = r.client || {};
            if (c.driver === 'bridge') {
                say('مبلغ به کارت‌خوان فرستاده شد — کارت را بکشید…');
                var init = { method: c.method, headers: { 'Content-Type': 'application/json' } };
                if (c.method !== 'GET') { init.body = c.body; }
                fetch(c.url, init).then(function (x) { return x.text(); })
                    .then(function (raw) { return post({ action: 'finish', id: cur, raw: raw }); })
                    .then(done)
                    .catch(function () { say('سرویسِ کارت‌خوان پاسخ نداد — نتیجه را دستی ثبت کنید.'); manual.hidden = false; });
                return;
            }
            if (c.driver === 'android' && c.intent) { window.location.href = c.intent; }
            manual.hidden = false;
            say(c.driver === 'android' ? 'اپِ پرداختِ دستگاه باز شد؛ بعد از پرداخت شماره‌ی پیگیری را بنویسید.' : 'مبلغ را روی دستگاه بزنید و بعد از پرداخت شماره‌ی پیگیری را بنویسید.');
            q('[data-pos-rrn]').focus();
        }).catch(function () { say('ارتباط برقرار نشد.'); });
    });
    q('[data-pos-ok]').addEventListener('click', function () {
        if (!cur) { return; }
        post({ action: 'finish', id: cur, ok: '1', rrn: q('[data-pos-rrn]').value, card: q('[data-pos-card]').value }).then(function (r) {
            if (!r.ok && r.status !== 'failed') { say('✗ ' + r.message); return; }   // شماره‌ی خالی/تکراری: فرم باز می‌ماند
            done(r);
        });
    });
    q('[data-pos-fail]').addEventListener('click', function () {
        if (!cur) { return; }
        post({ action: 'finish', id: cur, ok: '0' }).then(done);
    });
})();
