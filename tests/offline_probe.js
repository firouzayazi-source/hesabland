/**
 * فروش در حالتِ قطعیِ اینترنت در کرومیومِ واقعی (`test_store_offline.php`).
 *
 * ⛔ قطعی واقعیِ شبکه‌ی خودِ کرومیوم است (`Network.emulateNetworkConditions`، پس `navigator.onLine`
 *    و رویدادهای online/offline واقعی‌اند)؛ خودِ `store-offline.js`، فرم و دکمه‌ها واقعی‌اند.
 * ⛔ هیچ وابستگیِ npm ندارد (همان قاعده‌ی `num_probe.js`).
 *
 * اجرا: node offline_probe.js <baseUrl> <username> <password> <cfgJson>
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
    await send('Network.enable', {}, S);
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

        const net = (offline) => send('Network.emulateNetworkConditions', { offline, latency: 0, downloadThroughput: -1, uploadThroughput: -1 }, S);
    const res = {};
    await go('store/quick-sale.php');
    res.boxHiddenAtStart = await ev(`document.querySelector('[data-offline-box]').hidden`);
    // وصل: فروش مثلِ همیشه فرستاده می‌شود (صف نه)
    {
        const pl0 = waitLoad();
        await ev(`(()=>{ const row = document.querySelector('[data-lines] [data-row]');
            row.querySelector('[data-item]').value = 'فروشِ آنلاین'; row.querySelector('[data-qty]').value = '1'; row.querySelector('input[name$="[price]"]').value = '50';
            document.querySelector('.st-actionbar button[value="sale"]').click(); return 1; })()`);
        await pl0; await sleep(300);
        res.onlineSale = await ev(`(()=>{ let q = []; try { q = JSON.parse(localStorage.getItem('hl-offline-sales:' + document.querySelector('form[data-offline-shop]').dataset.offlineShop) || '[]'); } catch (e) {} return { queue: q.length }; })()`);
    }
    await net(true);
    await sleep(300);
    res.noteShown = await ev(`!document.querySelector('[data-offline-note]').hidden`);
    const sell = async (item, qty, price) => ev(`(async()=>{
        const row = document.querySelector('[data-lines] [data-row]');
        const set = (sel, v) => { const i = row.querySelector(sel); i.value = v; i.dispatchEvent(new Event('input', { bubbles: true })); };
        set('[data-item]', ${JSON.stringify(item)}); set('[data-qty]', ${JSON.stringify(qty)}); set('input[name$="[price]"]', ${JSON.stringify(price)});
        document.querySelector('.st-actionbar button[value="sale"]').click();
        await new Promise((r) => setTimeout(r, 400));
        let q = []; try { q = JSON.parse(localStorage.getItem('hl-offline-sales:' + document.querySelector('form[data-offline-shop]').dataset.offlineShop) || '[]'); } catch (e) {}
        return { path: location.pathname, queue: q.length, item: row.querySelector('[data-item]').value,
                 boxShown: !document.querySelector('[data-offline-box]').hidden,
                 title: document.querySelector('[data-offline-title]').textContent,
                 syncDisabled: document.querySelector('[data-offline-sync] button').disabled,
                 buttonsEnabled: [...document.querySelectorAll('.st-actionbar button')].every((b) => !b.disabled),
                 hasCsrf: q.some((s) => s.fields.some((f) => f[0] === 'csrf_token' || f[0] === '_once')) };
    })()`);
    res.s1 = await sell(cfg.item, '۲', '۱٬۵۰۰');
    res.s2 = await sell('شارژر آزمایشی', '1', '300');
    await net(false);
    await sleep(300);
    res.online = await ev(`({ syncDisabled: document.querySelector('[data-offline-sync] button').disabled, noteHidden: document.querySelector('[data-offline-note]').hidden })`);
    const pl = waitLoad();
    await ev(`document.querySelector('[data-offline-sync] button').click(); 1`);
    await pl; await sleep(500);
    res.after = await ev(`(()=>{ let q = null; try { q = JSON.parse(localStorage.getItem('hl-offline-sales:' + document.querySelector('form[data-offline-shop]').dataset.offlineShop) || '[]').length; } catch (e) {}
        return { path: location.pathname, queue: q, boxHidden: document.querySelector('[data-offline-box]').hidden, flash: (document.querySelector('.st-flash, .st-toasts') || {}).textContent || '' }; })()`);
    done({ ok: true, res });
})().catch((e) => { process.stdout.write(JSON.stringify({ ok: false, why: String(e) }) + '\n'); process.exit(0); });
