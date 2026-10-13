/*
 * سرویس‌ورکر حساب لند.
 *
 * چرا در ریشه‌ی اپ است و نه داخل assets/: دامنه‌ی کنترلِ یک سرویس‌ورکر
 * نمی‌تواند بالاتر از پوشه‌ی خودش برود. اگر داخل assets/ می‌بود فقط
 * همان پوشه را کنترل می‌کرد و ناوبری صفحه‌ها اصلاً از او رد نمی‌شد.
 *
 * ⚠ قاعده‌ی مرکزی: **هیچ HTML ای کش نمی‌شود.**
 *
 * همه‌ی صفحه‌های این اپ عمداً `Cache-Control: no-store, private` می‌گیرند
 * (در Auth::initSession). دلیلش یک باگ واقعی بود: سافاری روی آیفون نسخه‌ی
 * کش‌شده را نشان می‌داد، کاربر چیزی را حذف می‌کرد، صفحه تازه می‌شد و باز
 * همان عدد قدیمی می‌آمد. اگر سرویس‌ورکر HTML را کش کند، دقیقاً همان باگ
 * برمی‌گردد — این بار بدتر، چون کش سرویس‌ورکر با تازه‌سازی صفحه هم پاک
 * نمی‌شود. پس اینجا فقط دارایی‌های ثابت کش می‌شوند: CSS، JS، فونت، آیکون.
 *
 * دستاورد: باز شدن آنی از آیکون صفحه‌ی اصلی، و یک صفحه‌ی «اینترنت نیست»
 * درست به‌جای صفحه‌ی خطای مرورگر.
 *
 * نسخه را با هر تغییرِ فهرست پیش‌کش بالا ببرید؛ نسخه‌های قدیمی هنگام
 * activate پاک می‌شوند.
 */

// ⚠ v3 → v4 فقط به این دلیل که **فایلِ آیکون‌ها عوض شد** (برندِ تازه) و
//   `PRECACHE` آن‌ها را با آدرسِ بی‌نسخه نگه می‌دارد، پس بدونِ این بالا
//   رفتن، نسخه‌ی کهنه‌شان تا ابد در کشِ سرویس‌ورکر می‌ماند. هزینه‌اش
//   نوشته می‌ماند و یک‌باره است: کلِ کش پاک می‌شود، یعنی همان «تورِ
//   نجاتِ نسخه‌ی قبلیِ فایل» برای یک بار از دست می‌رود و هر کاربر یک
//   بار دوباره ~۲۲۰ کیلوبایت می‌گیرد. برای تغییرِ **منطقِ** این فایل
//   بالا بردنش لازم نیست و حتی بد است.
const VERSION    = 'daftar-v4';
const ASSET_CACHE = VERSION + '-assets';

// مسیرها نسبت به خودِ این فایل حل می‌شوند، پس نصب در زیرپوشه هم کار
// می‌کند و نیازی نیست APP_BASE_PATH را اینجا تکرار کنیم.
const rel = (p) => new URL(p, self.location).toString();

const PRECACHE = [
    rel('offline.html'),
    rel('assets/css/style.css'),
    rel('assets/js/client-errors.js'),
    rel('assets/js/num-input.js'),
    rel('assets/js/app.js'),
    rel('assets/js/sms-core.js'),
    rel('assets/js/jalali-datepicker.js'),
    rel('assets/js/chart.umd.js'),
    rel('assets/fonts/Vazirmatn.woff2'),
    rel('assets/icons/icon-192.png'),
    rel('assets/icons/icon-512.png'),
];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(ASSET_CACHE);
        // تک‌تک، نه addAll: اگر یکی از فایل‌ها ۴۰۴ بدهد، addAll کلِ نصب را
        // رد می‌کند و سرویس‌ورکر هرگز فعال نمی‌شود — یعنی یک فایل جامانده
        // کل قابلیت را بی‌سروصدا خاموش می‌کند.
        await Promise.all(PRECACHE.map(async (url) => {
            try { await cache.add(new Request(url, { cache: 'reload' })); }
            catch (e) { /* این یکی نبود؛ بقیه کار خودشان را می‌کنند */ }
        }));
        self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(
            names.filter((n) => n.startsWith('daftar-') && !n.startsWith(VERSION))
                 .map((n) => caches.delete(n))
        );
        /* ⛔ Navigation Preload: بدونش درخواستِ صفحه تا بیدار شدنِ خودِ
           این سرویس‌ورکر **منتظر** می‌ماند (روی گوشی، بعد از ~۳۰ ثانیه
           بی‌کاری، ده‌ها تا صدها میلی‌ثانیه). با آن، مرورگر درخواست را
           هم‌زمان با بیدار کردنِ ما می‌فرستد و شاخه‌ی `navigate` پایین
           همان پاسخ را برمی‌دارد. */
        if (self.registration.navigationPreload) {
            try { await self.registration.navigationPreload.enable(); } catch (e) {}
        }
        await self.clients.claim();
    })());
});

/** آیا این درخواست یک دارایی ثابتِ خودِ ماست؟ */
function isStaticAsset(url) {
    if (url.origin !== self.location.origin) { return false; }
    const base = new URL('./', self.location).pathname;      // ریشه‌ی نصب
    if (!url.pathname.startsWith(base + 'assets/')) { return false; }
    // manifest.php خودش PHP است و هدر کش خودش را دارد؛ نباید در
    // لایه‌ی سرویس‌ورکر دوباره کش شود.
    return !url.pathname.endsWith('.php');
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') { return; }

    const url = new URL(req.url);

    // ---------- ناوبری: همیشه از شبکه ----------
    // هرگز از کش پاسخ داده نمی‌شود؛ کش فقط برای وقتی است که شبکه اصلاً
    // نیست، و آن هم صفحه‌ی «اینترنت نیست» است نه نسخه‌ی کهنه‌ی داده.
    if (req.mode === 'navigate') {
        event.respondWith((async () => {
            try {
                // ⚠ پاسخِ پیش‌بارگذاری‌شده همان درخواستِ شبکه است (با همان
                //   کوکی و همان `no-store`)، نه نسخه‌ی کش — قاعده‌ی «هیچ HTML
                //   ای کش نمی‌شود» دست‌نخورده می‌ماند.
                const pre = await event.preloadResponse;
                if (pre) { return pre; }
                return await fetch(req);
            } catch (e) {
                const cache = await caches.open(ASSET_CACHE);
                const offline = await cache.match(rel('offline.html'));
                return offline || new Response(
                    'اینترنت در دسترس نیست.',
                    { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } }
                );
            }
        })());
        return;
    }

    // ---------- دارایی‌های ثابت ----------
    if (!isStaticAsset(url)) { return; }     // بقیه (api/ و ...) دست‌نخورده به شبکه

    event.respondWith(assetResponse(req));
});

/** چند ثانیه منتظر شبکه بمانیم پیش از آنکه سراغ نسخه‌ی قبلی برویم. */
const NET_TIMEOUT = 5000;

/**
 * دارایی ثابت: اول کش، و **هرگز صفحه را با دستِ خالی رها نکن**.
 *
 * ⚠ اینجا عمداً «تازه‌سازی در پس‌زمینه» نیست.
 *
 * نسخه‌ی اول این کار را می‌کرد و نتیجه‌اش بدتر از نداشتنِ سرویس‌ورکر بود:
 * در *هر* ناوبری، با اینکه همه‌چیز کش شده بود، باز فونت و style.css و
 * app.js و chart.js دوباره از شبکه گرفته می‌شدند — حدود ۲۲۰ کیلوبایت
 * ترافیکِ بی‌فایده در هر جابه‌جایی، که روی دیتای موبایل با خودِ صفحه سرِ
 * پهنای باند دعوا می‌کرد. تازه شدن لازم نیست چون آدرس این فایل‌ها نسخه
 * دارد (`?v=...`): با هر تغییر، آدرس عوض می‌شود و کشِ تازه می‌گیرد.
 *
 * ولی همین نسخه‌دار بودن یک لبه‌ی تیز دارد و باعث یک باگ واقعی شد:
 * **بعد از هر deploy، آدرسِ app.js عوض می‌شود، پس کش خطا می‌خورد و فایل
 * باید از شبکه بیاید.** اگر آن یک درخواست روی اینترنت موبایل گیر می‌کرد،
 * صفحه کامل و خوش‌ظاهر بالا می‌آمد — HTML و CSS رسیده بودند — ولی
 * app.js اجرا نشده بود. یعنی صفحه اسکرول می‌شد و هیچ دکمه‌ای کار
 * نمی‌کرد، بی‌آنکه هیچ نشانه‌ای از خرابی دیده شود. بستن و باز کردنِ اپ
 * درستش می‌کرد، چون بار دوم فایل می‌رسید.
 *
 * حالا سه لایه هست:
 *   ۱. کشِ دقیق (همان ?v=) — حالت عادی، بدون هیچ درخواستی.
 *   ۲. اگر نبود: شبکه، ولی حداکثر NET_TIMEOUT ثانیه.
 *   ۳. اگر شبکه دیر کرد یا شکست: **نسخه‌ی قبلیِ همان فایل** (با
 *      ignoreSearch، یعنی بدون توجه به ?v=). کدِ یک نسخه عقب‌تر بی‌نهایت
 *      بهتر از صفحه‌ی بی‌جان است، و دفعه‌ی بعد که شبکه درست باشد
 *      خودبه‌خود به‌روز می‌شود.
 */
async function assetResponse(req) {
    const cache = await caches.open(ASSET_CACHE);

    const exact = await cache.match(req);
    if (exact) { return exact; }

    // نسخه‌ی قبلیِ همین فایل — تورِ نجات
    const stale = await cache.match(req, { ignoreSearch: true });

    const network = fetch(req).then(async (res) => {
        if (res && res.ok && res.type === 'basic') {
            await cache.put(req, res.clone());
            await dropOtherVersions(cache, req);
        }
        return res;
    });
    // اگر مسابقه را به timeout ببازد، رد شدنش نباید unhandled بماند
    network.catch(() => {});

    if (!stale) {
        try { return await network; }
        catch (e) { return new Response('', { status: 504 }); }
    }

    const timeout = new Promise((resolve) => setTimeout(() => resolve(null), NET_TIMEOUT));
    try {
        const res = await Promise.race([network, timeout]);
        if (res) { return res; }        // شبکه به‌موقع رسید
    } catch (e) { /* شبکه شکست — می‌افتیم روی نسخه‌ی قبلی */ }

    return stale;
}

/**
 * نسخه‌های قدیمیِ همین فایل را از کش بردار.
 *
 * بدون این، کش بی‌پایان بزرگ می‌شد: هر deploy یک ?v= تازه می‌سازد و
 * نسخه‌ی قبلی برای همیشه می‌ماند. بعد از چند به‌روزرسانی، چند مگابایتِ
 * مرده روی گوشی کاربر جا خوش کرده بود. یکی را نگه می‌داریم (همین که
 * تازه نشست) و بقیه می‌روند.
 */
async function dropOtherVersions(cache, req) {
    const path = new URL(req.url).pathname;
    const keys = await cache.keys();
    await Promise.all(keys.map((key) => {
        const u = new URL(key.url);
        return (u.pathname === path && key.url !== req.url) ? cache.delete(key) : null;
    }));
}

/* ============================================================
 * اعلان روی گوشی (Web Push) — «نوتیف روی آیکون و گوشی مثل سایر اپ‌ها»
 *
 * ⛔ پیام از `includes/push.php` می‌آید و رمزگشایی‌اش کارِ خودِ مرورگر
 *    است؛ اینجا فقط JSONِ باز شده (`title`, `body`, `url`, `tag`, `badge`)
 *    نشان داده می‌شود.
 * ⛔ هر `push` **باید** یک اعلان نشان بدهد (`userVisibleOnly`)، وگرنه کروم
 *    خودش یک اعلانِ عمومیِ «این سایت در پس‌زمینه به‌روز شد» می‌گذارد. پس
 *    پیامِ خراب هم یک اعلانِ عادی می‌گیرد، نه سکوت.
 * ⚠ عددِ روی آیکون (`setAppBadge`) روی آیفون (PWAِ نصب‌شده) و دسکتاپ کار
 *   می‌کند؛ اندروید خودش از روی اعلان یک نقطه روی آیکون می‌گذارد.
 * ⚠ منطقِ این فایل عوض شد نه فهرستِ پیش‌کش، پس VERSION بالا نرفت.
 * ============================================================ */
self.addEventListener('push', (event) => {
    let d = {};
    try { d = event.data ? event.data.json() : {}; } catch (e) { d = {}; }
    const title = String(d.title || 'حساب لند');
    const url   = rel(String(d.url || 'notifications.php').replace(/^\/+/, ''));
    const count = Number(d.badge || 0);
    event.waitUntil((async () => {
        try {
            if (self.navigator && 'setAppBadge' in self.navigator) {
                if (count > 0) { await self.navigator.setAppBadge(count); }
                else { await self.navigator.clearAppBadge(); }
            }
        } catch (e) { /* نبودِ badge نباید جلوی خودِ اعلان را بگیرد */ }
        await self.registration.showNotification(title, {
            body: String(d.body || ''),
            tag: String(d.tag || 'hesab'),
            renotify: true,
            dir: 'rtl',
            lang: 'fa',
            icon: rel('assets/icons/icon-192.png'),
            badge: rel('assets/icons/badge-96.png'),
            data: { url },
        });
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || rel('notifications.php');
    event.waitUntil((async () => {
        const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        // ⛔ اگر اپ از قبل باز است همان پنجره جلو می‌آید، نه یک پنجره‌ی دوم.
        for (const w of wins) {
            if (w.url.startsWith(self.registration.scope)) {
                try { await w.focus(); } catch (e) {}
                try { await w.navigate(url); } catch (e) {}
                return;
            }
        }
        await self.clients.openWindow(url);
    })());
});
