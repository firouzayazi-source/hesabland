/**
 * اسکن با دوربین در کرومیومِ واقعی (`test_store_scan.php`).
 *
 * ⛔ دوربین و `BarcodeDetector` ساختگی‌اند (`Page.addScriptToEvaluateOnNewDocument`) ولی همه‌ی بقیه
 *    واقعی است: خودِ `store-scan.js`، دکمه‌ها، رویدادها و مسیرِ «Enter»ِ فروشِ سریع که کالا را اضافه می‌کند.
 *    `window.__codes` = بارکدهایی که «دوربین» می‌بیند.
 * ⛔ هیچ وابستگیِ npm ندارد (همان قاعده‌ی `num_probe.js`).
 *
 * اجرا: node scan_probe.js <baseUrl> <username> <password> <cfgJson>
 */
'use strict';

const { spawn } = require('child_process');
const fs = require('fs');

const BINS = [
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    process.env.CHROME_BIN || '',
];
const [base, user, pass, cfgJson] = process.argv.slice(2);
const cfg = JSON.parse(cfgJson || '{}');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { process.stdout.write(JSON.stringify({ ok: false, why: 'no_chromium' }) + '\n'); process.exit(0); }

const FAKE = `(() => {
    if (location.hash === '#nodetector' || sessionStorage.getItem('nodet') === '1') { sessionStorage.setItem('nodet', '1'); try { delete window.BarcodeDetector; } catch (e) {} return; }
    window.__codes = [];
    window.__gum = 0;
    class BarcodeDetector {
        static getSupportedFormats() { return Promise.resolve(['code_128', 'ean_13', 'qr_code']); }
        constructor(o) { this.o = o; }
        detect() { return Promise.resolve((window.__codes || []).map((v) => ({ rawValue: v, format: 'code_128' }))); }
    }
    window.BarcodeDetector = BarcodeDetector;
    const md = navigator.mediaDevices || {};
    md.getUserMedia = () => { window.__gum++; const c = document.createElement('canvas'); c.width = 64; c.height = 48; return Promise.resolve(c.captureStream(5)); };
})();`;

(async () => {
    const port = 9800 + Math.floor(process.pid % 140);
    const proc = spawn(bin, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--no-first-run', '--mute-audio', '--autoplay-policy=no-user-gesture-required', `--remote-debugging-port=${port}`, 'about:blank'],
        { stdio: ['ignore', 'ignore', 'ignore'] });
    const done = (payload) => { process.stdout.write(JSON.stringify(payload) + '\n'); try { proc.kill('SIGKILL'); } catch (e) {} process.exit(0); };
    setTimeout(() => done({ ok: false, why: 'timeout' }), 180000);

    let ver = null;
    for (let i = 0; i < 60; i++) { await sleep(150); try { ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch (e) {} }
    if (!ver) { done({ ok: false, why: 'chromium_no_start' }); }
    const ws = new WebSocket(ver.webSocketDebuggerUrl);
    await new Promise((r, j) => { ws.addEventListener('open', r); ws.addEventListener('error', j); });
    let id = 0;
    const pending = new Map(), listeners = [];
    ws.addEventListener('message', (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); } else { listeners.forEach((l) => l(m)); }
    });
    const send = (method, params = {}, sessionId) => new Promise((res) => { const i = ++id; pending.set(i, res); ws.send(JSON.stringify({ id: i, method, params, sessionId })); });
    const t = await send('Target.createTarget', { url: 'about:blank' });
    const S = (await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true })).result.sessionId;
    await send('Page.enable', {}, S);
    await send('Runtime.enable', {}, S);
    await send('Page.addScriptToEvaluateOnNewDocument', { source: FAKE }, S);
    const waitLoad = () => new Promise((r) => {
        const l = (m) => { if (m.method === 'Page.loadEventFired' && m.sessionId === S) { listeners.splice(listeners.indexOf(l), 1); r(); } };
        listeners.push(l);
        setTimeout(() => { const i = listeners.indexOf(l); if (i >= 0) { listeners.splice(i, 1); r(); } }, 10000);
    });
    const ev = async (x) => (await send('Runtime.evaluate', { expression: x, awaitPromise: true, returnByValue: true }, S)).result.result.value;
    const go = async (u) => { const p = waitLoad(); await send('Page.navigate', { url: base + u }, S); await p; await sleep(300); };
    await send('Emulation.setDeviceMetricsOverride', { width: 420, height: 860, deviceScaleFactor: 1, mobile: true }, S);

    await go('store/login.php');
    const p = waitLoad();
    await ev(`(()=>{document.querySelector('[name=username]').value=${JSON.stringify(user)};document.querySelector('[name=password]').value=${JSON.stringify(pass)};document.querySelector('form').submit();return 1})()`);
    await p; await sleep(300);

    const res = {};
    // ۱ — فروشِ سریع: خانه‌ی اسکن دکمه دارد؛ «دوربین» جعبه را با سه بارکد می‌بیند
    await go('store/quick-sale.php');
    res.qsButton = await ev(`!!document.querySelector('[data-scan] + .st-cam-btn')`);
    await ev(`window.__codes = ${JSON.stringify(cfg.boxCodes)}; document.querySelector('[data-scan] + .st-cam-btn').click(); 1`);
    await sleep(1500);
    res.qs = await ev(`(()=>({
        imeis: [...document.querySelectorAll('input[data-imei1]')].map((i) => i.value).filter(Boolean),
        overlayHidden: !document.querySelector('.st-cam') || document.querySelector('.st-cam').hidden,
        gum: window.__gum,
        scanValue: document.querySelector('[data-scan]').value,
        hint: (document.querySelector('[data-cam-hint]') || {}).textContent || '',
        rows: document.querySelectorAll('[data-row]').length,
    }))()`);
    // ۲ — فاکتورِ خرید: خانه‌ی IMEI فقط IMEIِ سالم را می‌گیرد؛ IMEI۲ همان IMEI۱ را برنمی‌دارد
    await go('store/invoice-edit.php?k=purchase');
    res.inv = await ev(`(async()=>{
        const i1 = document.querySelector('input[data-imei1]'), i2 = document.querySelector('input[data-imei2]');
        if (!i1 || !i2) return { missing: true };
        const out = { b1: !!(i1.nextElementSibling && i1.nextElementSibling.matches('.st-cam-btn')) };
        window.__codes = ${JSON.stringify(cfg.boxCodes)};
        i1.nextElementSibling.click(); await new Promise((r) => setTimeout(r, 900));
        out.v1 = i1.value;
        i2.nextElementSibling.click(); await new Promise((r) => setTimeout(r, 900));
        out.v2 = i2.value;
        window.__codes = ['6260123456789'];
        i1.value = ''; i1.nextElementSibling.click(); await new Promise((r) => setTimeout(r, 900));
        out.eanOnly = i1.value;
        out.hint = (document.querySelector('[data-cam-hint]') || {}).textContent || '';
        document.querySelector('.st-cam-close').click();
        out.closed = document.querySelector('.st-cam').hidden;
        return out;
    })()`);
    // ۳ — ردیفِ تازه‌ی فاکتور هم دکمه می‌گیرد
    res.added = await ev(`(async()=>{
        const before = document.querySelectorAll('.st-cam-btn').length;
        const add = document.querySelector('[data-add-rows]'); if (!add) return { noAdd: true };
        add.click(); await new Promise((r) => setTimeout(r, 300));
        const ins = [...document.querySelectorAll('input[data-imei1]')], last = ins[ins.length - 1];
        last.value = '';
        window.__codes = ${JSON.stringify(cfg.boxCodes)};
        if (last.nextElementSibling && last.nextElementSibling.matches('.st-cam-btn')) { last.nextElementSibling.click(); }
        await new Promise((r) => setTimeout(r, 900));
        return { before, after: document.querySelectorAll('.st-cam-btn').length, lastValue: last.value, rows: ins.length };
    })()`);
    // ۴ — مرورگرِ بی‌BarcodeDetector: هیچ دکمه‌ای
    await go('store/quick-sale.php#nodetector');
    await go('store/quick-sale.php');
    res.noDetector = await ev(`document.querySelectorAll('.st-cam-btn').length`);
    done({ ok: true, res });
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: String(e) }) + '\n'); process.exit(0); });
