/**
 * کارتِ «مانده این ماه»: کشیدن به بالا = ماهِ قبل — در کرومیومِ واقعی با لمسِ واقعی (CDP).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «اگه به سمت بالا بکشیم خود کارت رو دقیقا مبالغ
 * ماه قبلش … و وقتی که کاربر کاری انجام نمیده یعنی رهاش میکنه برگرد رو ماه فعلی.»
 *
 * ⛔ بی‌وابستگیِ npm — همان الگوی `sheet_probe.js`.
 * اجرا: node month_stack_probe.js <baseUrl> <cookieName> <cookieValue>
 */
'use strict';

const { spawn } = require('child_process');
const fs = require('fs');

const BINS = [
    '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    process.env.CHROME_BIN || '',
];
const [baseUrl, cookieName, cookieValue] = process.argv.slice(2);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fail = (why) => { process.stdout.write(JSON.stringify({ ok: false, why }) + '\n'); process.exit(0); };
const bin = BINS.find((b) => b && fs.existsSync(b));
if (!bin) { fail('no_chromium'); }

(async () => {
    const port = 9300 + Math.floor((process.pid % 600));
    const proc = spawn(bin, [
        '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--disable-extensions', '--no-first-run', '--mute-audio',
        `--remote-debugging-port=${port}`, 'about:blank',
    ], { stdio: ['ignore', 'ignore', 'ignore'] });
    const done = (payload) => {
        process.stdout.write(JSON.stringify(payload) + '\n');
        try { proc.kill('SIGKILL'); } catch (e) {}
        process.exit(0);
    };

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
    ws.addEventListener('message', (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    });
    const send = (method, params = {}, sessionId) => new Promise((res) => {
        const i = ++id;
        pending.set(i, res);
        ws.send(JSON.stringify({ id: i, method, params, sessionId }));
    });

    const t = await send('Target.createTarget', { url: 'about:blank' });
    const s = await send('Target.attachToTarget', { targetId: t.result.targetId, flatten: true });
    const sid = s.result.sessionId;
    await send('Runtime.enable', {}, sid);
    await send('Page.enable', {}, sid);
    await send('Network.enable', {}, sid);
    await send('Emulation.setDeviceMetricsOverride', { width: 412, height: 839, deviceScaleFactor: 2.6, mobile: true }, sid);
    await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 }, sid);
    const u = new URL(baseUrl);
    await send('Network.setCookie', { name: cookieName, value: cookieValue, domain: u.hostname, path: '/' }, sid);

    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', {
            expression: `(function(){${expr}})()`, returnByValue: true, awaitPromise: true,
        }, sid);
        if (r.result && r.result.exceptionDetails) {
            done({ ok: false, why: 'js_error', detail: String((r.result.exceptionDetails.exception || {}).description || r.result.exceptionDetails.text) });
        }
        return r.result.result.value;
    };
    const ready = async () => {
        for (let i = 0; i < 60; i++) {
            await sleep(200);
            const ok = await evalJs('return document.readyState === "complete"'
                + ' && !document.documentElement.classList.contains("js-loading");').catch(() => false);
            if (ok) { return true; }
        }
        return false;
    };
    const swipe = async (x, y0, dy, dx = 0) => {
        await send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: y0 }] }, sid);
        for (let i = 1; i <= 12; i++) {
            await send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + dx * i / 12, y: y0 + dy * i / 12 }] }, sid);
            await sleep(16);
        }
        await send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }, sid);
        await sleep(500);
    };
    const state = `var c = document.getElementById('monthStack');
        var r = c.getBoundingClientRect();
        return { past: c.classList.contains('is-past'), month: c.querySelector('.ms-month').textContent,
                 ago: c.querySelector('.ms-ago').textContent, net: c.querySelector('.ms-net').textContent,
                 inc: c.querySelector('.ms-in').textContent, out: c.querySelector('.ms-out').textContent,
                 href: c.querySelector('.ms-open').getAttribute('href'),
                 dots: c.querySelectorAll('.ms-rail i').length,
                 on: Array.prototype.indexOf.call(c.querySelectorAll('.ms-rail i'), c.querySelector('.ms-rail i.is-on')),
                 cx: r.left + r.width / 2, cy: r.top + r.height / 2, scrollY: window.scrollY,
                 touchAction: getComputedStyle(c).touchAction };`;

    // ⚠ اختیاری، فقط برای دیدن با چشم: SHOT_DIR=… عکسِ قبل و بعد را ذخیره می‌کند
    const shot = async (name) => {
        if (!process.env.SHOT_DIR) { return; }
        const r = await send('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 300, width: 412, height: 420, scale: 1 } }, sid);
        if (r.result && r.result.data) { fs.writeFileSync(process.env.SHOT_DIR + '/' + name + '.png', Buffer.from(r.result.data, 'base64')); }
    };
    const out = { ok: true };
    await send('Page.navigate', { url: baseUrl + 'index.php' }, sid);
    if (!(await ready())) { done({ ok: false, why: 'home_not_ready' }); }
    const s0 = await evalJs(state);
    out.touchAction = s0.touchAction;
    out.startCurrent = !s0.past;
    await shot('0-current');

    // ۱) بالا کشیدن → ماهِ قبل
    await swipe(s0.cx, s0.cy + 40, -140);
    await sleep(400);
    out.up1 = await evalJs(state);
    out.pageDidNotScroll = out.up1.scrollY === s0.scrollY;
    await shot('1-prev-month');

    // ۲) دوباره بالا → دو ماه قبل
    await swipe(s0.cx, s0.cy + 40, -140);
    out.up2 = await evalJs(state);
    await shot('2-two-months-ago');

    // ۳) پایین → یک ماه تازه‌تر
    await swipe(s0.cx, s0.cy - 40, 140);
    out.down1 = await evalJs(state);

    // ۴) رها کردن → بعد از چند ثانیه خودش به ماهِ جاری برمی‌گردد
    await sleep(4000);
    out.stillPastAt4s = (await evalJs(state)).past;
    await sleep(3200);
    out.afterIdle = await evalJs(state);

    // ۵) یک لمسِ کوتاه (نه کشیدن) ماه را عوض نمی‌کند
    await swipe(s0.cx, s0.cy, -8);
    out.tapNoChange = !(await evalJs(state)).past;

    done(out);
})().catch((e) => fail('exception: ' + (e && e.message)));
