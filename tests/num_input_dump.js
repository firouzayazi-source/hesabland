/**
 * اجرای `assets/js/num-input.js`ِ واقعی در node، برای `test_num_input.php`.
 *
 * عمداً خودِ فایل بارگذاری می‌شود نه کپی‌اش. دو چیز سنجیده می‌شود:
 *   • `money` / `persian` — تبدیلِ خالص.
 *   • `typed` — مسیرِ واقعی: شنونده‌ی `input`ِ سند با یک خانه‌ی ساختگی
 *     (مقدار + جای مکان‌نما) آتش زده می‌شود و مقدار و مکان‌نمای بعدی خوانده
 *     می‌شود. بی‌این، جهشِ «شنونده را بردار» زنده می‌ماند.
 *
 * ورودی (stdin، JSON):
 *   { "money": [..], "persian": [..],
 *     "typed": [{ "attr": "data-money"|"data-num"|"", "value": "...", "caret": n }, ..] }
 * خروجی: { "money": [..], "persian": [..], "typed": [{ "value": "...", "caret": n }, ..] }
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const listeners = {};
const doc = {
    readyState: 'complete',
    activeElement: null,
    addEventListener: (t, fn) => { (listeners[t] = listeners[t] || []).push(fn); },
    querySelectorAll: () => [],
};
const win = {};
const ctx = { window: win, document: doc, String, Number, RegExp, console };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'num-input.js'), 'utf8'), ctx);

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const api = win.numInput;
const out = {
    money: (input.money || []).map((s) => api.money(s)),
    persian: (input.persian || []).map((s) => api.persian(s)),
    typed: (input.typed || []).map((c) => {
        const el = {
            tagName: 'INPUT', value: c.value, selectionStart: c.caret,
            hasAttribute: (a) => a === c.attr,
            setSelectionRange(a) { this.selectionStart = a; },
        };
        doc.activeElement = el;
        (listeners.input || []).forEach((fn) => fn({ target: el, isComposing: false }));
        return { value: el.value, caret: el.selectionStart };
    }),
};
process.stdout.write(JSON.stringify(out));
