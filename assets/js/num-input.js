/* ============================================================
   ورودیِ عدد — مشترکِ حساب‌لند و فروشگاه (هر دو پوسته لودش می‌کنند)
   ------------------------------------------------------------
   خواسته‌ی مالکِ نصب (مهر ۱۴۰۵): «اعداد وقتی وارد می‌کنم انگلیسی تایپ
   می‌شود و از هم جدا نمی‌شوند … وقتی سه رقم شد خودش جدا کند».

   - `input[data-money]` ← مبلغ: هنگامِ تایپ ارقامِ فارسی و جداکننده‌ی
     هزارگانِ «٬» (۱٬۲۵۰٬۰۰۰). مکان‌نما سرِ جایش می‌ماند (شمارِ رقم‌های
     پیش از آن)، پس اصلاحِ وسطِ عدد ممکن است.
   - `input[data-num]` ← تعداد، درصد، تاریخ: فقط رقم‌ها فارسی می‌شوند؛
     «/» و «.» و «٫» دست نمی‌خورند و هیچ جداکننده‌ای اضافه نمی‌شود
     (اعشار و تاریخ را نباید سه‌رقم‌سه‌رقم کرد).

   ⛔ سرور هر دو شکل را می‌فهمد (`sanitizeAmount()`، `sanitizeQty()`،
      `gDate()`)، و هر خواننده‌ی جاوااسکریپت پیش از عدد کردن رقم را لاتین
      می‌کند (`latin()`ِ store.js، `toLatinDigitsJs()`ِ app.js). خانه‌ای که
      خواننده‌اش این کار را نمی‌کند نباید این برچسب‌ها را بگیرد.
   ⛔ شناسه‌ها (IMEI، تلفن، کد، شماره‌ی چک و صیاد) عمداً برچسب ندارند.
   ⛔ «−» یا «-»ِ اول نگه داشته می‌شود: سرور مبلغِ منفی را با پیام رد می‌کند
      (`amountInputError()`) و پاک کردنِ بی‌صدایش آن پیام را کور می‌کرد.
   ============================================================ */
(function () {
    'use strict';

    var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';

    function latin(s) {
        return String(s == null ? '' : s)
            .replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); })
            .replace(/[٠-٩]/g, function (d) { return AR.indexOf(d); });
    }
    function persian(s) {
        return String(s == null ? '' : s)
            .replace(/[0-9]/g, function (d) { return FA.charAt(+d); })
            .replace(/[٠-٩]/g, function (d) { return FA.charAt(AR.indexOf(d)); });
    }

    // «-۱۲۳۴۵۶۷» ← «-۱٬۲۳۴٬۵۶۷»؛ هر چیزِ غیرِ رقم (جز علامتِ اول) کنار می‌رود.
    // ⛔ «٫» (ممیز) و هر چه بعدش آمده همان کاری را می‌کند که `sanitizeAmount()`:
    //    دور ریخته می‌شود — مبلغ کسر ندارد و «۱٫۵» نباید ۱۵ نمایش داده شود.
    function money(s) {
        var t = latin(s).replace(/^\s+/, '');
        var sign = /^[\-−]/.test(t) ? '-' : '';
        t = t.split('٫')[0].replace(/[^0-9]/g, '').replace(/^0+(?=\d)/, '');
        if (t === '') { return sign; }
        return sign + persian(t.replace(/\B(?=(\d{3})+(?!\d))/g, '٬'));
    }

    function digitsBefore(s, pos) {
        var n = 0;
        for (var i = 0; i < pos && i < s.length; i++) { if (/[0-9۰-۹٠-٩]/.test(s.charAt(i))) { n++; } }
        return n;
    }
    function posAfterDigits(s, n) {
        if (n <= 0) { return /^-/.test(s) ? 1 : 0; }
        for (var i = 0; i < s.length; i++) {
            if (/[0-9۰-۹٠-٩]/.test(s.charAt(i)) && --n === 0) { return i + 1; }
        }
        return s.length;
    }

    function setKeepingCaret(el, next, caretFrom) {
        if (el.value === next) { return; }
        var focused = document.activeElement === el, pos = 0;
        try { pos = el.selectionStart; } catch (e) { focused = false; }
        var keep = focused && pos != null ? caretFrom(el.value, pos) : null;
        el.value = next;
        if (keep !== null) { try { el.setSelectionRange(keep, keep); } catch (e) { /* نوعِ خانه انتخاب ندارد */ } }
    }

    function format(el) {
        if (!el || el.tagName !== 'INPUT') { return; }
        if (el.hasAttribute('data-money')) {
            var next = money(el.value);
            setKeepingCaret(el, next, function (old, pos) { return posAfterDigits(next, digitsBefore(old, pos)); });
        } else if (el.hasAttribute('data-num')) {
            // یک‌به‌یک (هر رقم یک نویسه)، پس مکان‌نما همان عدد می‌ماند
            setKeepingCaret(el, persian(el.value), function (old, pos) { return pos; });
        }
    }

    function formatAll(root) {
        (root || document).querySelectorAll('input[data-money],input[data-num]').forEach(format);
    }

    document.addEventListener('input', function (e) {
        if (e.isComposing) { return; }
        format(e.target);
    }, true);
    // مقداری که جاوااسکریپت گذاشته (قیمتِ پیش‌فرضِ کالا، ویرایش) رویدادِ input نمی‌دهد؛
    // با فوکوس و ترکِ خانه هم یکدست می‌شود.
    document.addEventListener('focusin', function (e) { format(e.target); });
    document.addEventListener('change', function (e) { format(e.target); }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { formatAll(); });
    } else {
        formatAll();
    }

    window.numInput = { format: format, formatAll: formatAll, money: money, persian: persian, latin: latin };
})();
