/**
 * ورودیِ عدد و نمایشِ عدد در کرومیومِ واقعی (`test_num_input.php`).
 *
 *   • `pages`: هر رقمِ **لاتینِ دیده‌شده** در متنِ صفحه (بیرون از خانه‌ی ورودی،
 *     اسکریپت و شناسه‌های عمداً لاتین که `data-latin` دارند) فهرست می‌شود —
 *     خواسته‌ی مالکِ نصب: «تمامِ اعدادی که نمایش می‌دهی فارسی باشد».
 *   • `type`: روی یک خانه کلید به کلید تایپ می‌شود (`Input.insertText`، همان
 *     مسیرِ کیبوردِ واقعی) و مقدار، جای مکان‌نما و قلمِ خانه خوانده می‌شود.
 *
 * ⛔ هیچ وابستگیِ npm ندارد (همان قاعده‌ی `store_probe.js`).
 *
 * اجرا: node num_probe.js <baseUrl> <username> <password> <cfgJson> [loginPath]
 */
'use strict';

const { spawn } = require('child_process');
const fs = require('fs');

const BINS = [
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    process.env.CHROME_BIN || '',
];
const [base, user, pass, cfgJson, loginPath] = process.argv.slice(2);
const cfg = JSON.parse(cfgJson || '{}');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { process.stdout.write(JSON.stringify({ ok: false, why: 'no_chromium' }) + '\n'); process.exit(0); }

(async () => {
    const port = 9650 + Math.floor(process.pid % 140);
    const proc = spawn(bin, [
        '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--no-first-run', '--mute-audio', `--remote-debugging-port=${port}`, 'about:blank',
    ], { stdio: ['ignore', 'ignore', 'ignore'] });
    const done = (payload) => {
        process.stdout.write(JSON.stringify(payload) + '\n');
        try { proc.kill('SIGKILL'); } catch (e) {}
        process.exit(0);
    };
    setTimeout(() => done({ ok: false, why: 'timeout' }), 300000);

    let ver = null;
    for (let i = 0; i < 60; i++) {
        await sleep(150);
        try { ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch (e) {}
    }
    if (!ver) { done({ ok: false, why: 'chromium_no_start' }); }

    const ws = new WebSocket(ver.webSocketDebuggerUrl);
    await new Promise((r, j) => { ws.addEventListener('open', r); ws.addEventListener('error', j); });
    let id = 0;
    const pending = new Map();
    const listeners = [];
    ws.addEventListener('message', (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
        else { listeners.forEach((l) => l(m)); }
    });
    const send = (method, params = {}, sessionId) => new Promise((res) => {
        const i = ++id;
        pending.set(i, res);
        ws.send(JSON.stringify({ id: i, method, params, sessionId }));
    });

    const t = await send('Target.createTarget', { url: 'about:blank' });
    const S = (await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true })).result.sessionId;
    await send('Page.enable', {}, S);
    await send('Runtime.enable', {}, S);

    const waitLoad = () => new Promise((r) => {
        const l = (m) => { if (m.method === 'Page.loadEventFired' && m.sessionId === S) { listeners.splice(listeners.indexOf(l), 1); r(); } };
        listeners.push(l);
        setTimeout(() => { const i = listeners.indexOf(l); if (i >= 0) { listeners.splice(i, 1); r(); } }, 10000);
    });
    const ev = async (x) => (await send('Runtime.evaluate', { expression: x, awaitPromise: true, returnByValue: true }, S)).result.result.value;
    const go = async (u) => { const p = waitLoad(); await send('Page.navigate', { url: base + u }, S); await p; await sleep(200); };
    const size = (w, h) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 700 }, S);

    await size(1400, 900);
    await go(loginPath || 'store/login.php');
    const p = waitLoad();
    await ev(`(()=>{document.querySelector('[name=username]').value=${JSON.stringify(user)};document.querySelector('[name=password]').value=${JSON.stringify(pass)};document.querySelector('form').submit();return 1})()`);
    await p;
    await sleep(300);

    const latinProbe = `(()=>{
        const out = [];
        const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        for (let n = w.nextNode(); n; n = w.nextNode()) {
            if (!/[0-9]/.test(n.textContent)) continue;
            const p = n.parentElement;
            if (!p || p.closest('script,style,noscript,template,textarea,[data-latin],[hidden]')) continue;
            const cs = getComputedStyle(p);
            if (cs.display === 'none' || cs.visibility === 'hidden') continue;
            out.push((p.className || p.tagName) + ': ' + n.textContent.trim().slice(0, 50));
        }
        for (const i of document.querySelectorAll('input[data-money],input[data-num]')) {
            if (/[0-9]/.test(i.value)) out.push('input[' + i.name + ']=' + i.value);
        }
        return out;
    })()`;

    const res = { pages: [], typed: [] };
    for (const pg of (cfg.pages || [])) {
        await go(pg);
        res.pages.push({ pg, latin: (await ev(latinProbe)).slice(0, 12) });
    }
    for (const t of (cfg.type || [])) {
        await go(t.page);
        const sel = JSON.stringify(t.sel);
        if (t.run) { await ev(t.run); await sleep(350); }   // مثلاً باز کردنِ شیتی که خانه در آن است
        const fill = JSON.stringify(t.fill || {});
        const ok = await ev(`(()=>{const f=${fill}; for (const k in f) { const x=document.querySelector(k); if (x) x.value=f[k]; }
            const e=document.querySelector(${sel}); if(!e) return false; e.focus(); e.value=''; return true})()`);
        if (!ok) { res.typed.push({ sel: t.sel, missing: true }); continue; }
        for (const ch of t.text) { await send('Input.insertText', { text: ch }, S); }
        res.typed.push(Object.assign({ sel: t.sel }, await ev(`(()=>{const e=document.querySelector(${sel});
            return { value: e.value, caret: e.selectionStart, font: getComputedStyle(e).fontFamily,
                     bodyFont: getComputedStyle(document.body).fontFamily }})()`)));
        if (t.submit) {
            const p2 = waitLoad();
            await ev(`document.querySelector(${sel}).form.requestSubmit()`);
            await p2; await sleep(200);
        }
    }
    done({ ok: true, res });
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: String(e) }) + '\n'); process.exit(0); });
