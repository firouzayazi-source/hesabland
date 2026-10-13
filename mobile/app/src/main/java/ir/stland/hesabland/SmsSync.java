package ir.stland.hesabland;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.job.JobInfo;
import android.app.job.JobParameters;
import android.app.job.JobScheduler;
import android.app.job.JobService;
import android.content.ComponentName;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.webkit.JavascriptInterface;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import org.json.JSONArray;
import org.json.JSONObject;

import java.security.SecureRandom;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;

/**
 * ⛔ ثبتِ پیامکِ بانک در **پس‌زمینه** — بی‌باز کردنِ اپ.
 *
 * **گزارشِ مالکِ نصب (مهر ۱۴۰۵):** «اپ اندروید هنوز نمی‌تونه اس‌ام‌اس‌های
 * بانکی رو بخونه … اپ‌های دیگه دسترسی به باتری و اینا می‌گیرن، مالِ ما نه …
 * خودش بذاره روی حساب در حساب‌لند.» تا امروز پیامک فقط وقتی به دفتر
 * می‌رسید که کاربر اعلان را می‌زد یا اپ را باز می‌کرد.
 *
 * ⛔ **این کلاس هم پیامک را «نمی‌خواند».** پارسر فقط یکی است:
 *    `assets/js/sms-core.js` (`smsWorker()`). این‌جا صفحه‌ی
 *    `assets/sms-worker.html` در یک WebViewِ **نامرئی** باز می‌شود و همان
 *    پارسرِ سایت تصمیم می‌گیرد و می‌فرستد — پس هر اصلاحِ پارسر بی‌APKِ تازه
 *    به گوشی می‌رسد و هیچ پارسرِ دومی (جاوا) ساخته نشد (قاعده ۱۲).
 *
 * ⛔ **و متنِ پیامک روی سیم نمی‌رود.** متن فقط در حافظه به `smsRun()`
 *    داده می‌شود؛ آنچه آن صفحه به سرور می‌فرستد فیلدهای خوانده‌شده است (نوع،
 *    مبلغ، تاریخ، حساب، مانده) با اثرِ انگشتِ هشت‌رقمی. این کلاس **هیچ**
 *    کلاسِ شبکه‌ای ندارد (قاعده ۱۹) و متن را هیچ‌جا ذخیره نمی‌کند — فقط
 *    زمانِ پیامک‌ها (`PREF_REVIEW`) و کلیدِ محدودِ `sms`.
 *
 * ⛔ **جفت شدن بی‌هیچ کارِ کاربر:** اپ یک کدِ تصادفی می‌سازد (`nonce()`) و
 *    `HesabLauncherActivity` صفحه را با `#smslink=<کد>` باز می‌کند؛ سایتِ
 *    واردشده آن را به کاربرِ نشست می‌بندد و این کارگر با همان کد کلید را
 *    می‌گیرد. هیچ صفحه‌ی دیگری کد را ندارد، پس کسی نمی‌تواند گوشی را به
 *    حسابِ خودش وصل کند و پیامک‌های بانکِ کاربر را بگیرد.
 *
 * ⛔ **یک پردازنده در هر حالت، تا تراکنش دو بار ثبت نشود:**
 *    - وصل (`linked()`): فقط همین کارگر صندوق را از `PREF_SCAN_AT` جلو
 *      می‌برد. پیامکی که خودکار نشد («بی‌واحد»، حسابِ نامعلوم) اعلانِ «بزن
 *      تا ثبت شود» می‌گیرد و زمانش در `PREF_REVIEW` می‌نشیند تا با باز شدنِ
 *      اپ روی خانه برای تأیید بیاید (`takeReview()`).
 *    - وصل نه: همان مسیرِ قبلی (اعلان + `#smsq=` با باز شدنِ اپ).
 *    و نگهبانِ تکرارِ سرور (`sms_posted`) هر تلاشِ دوباره را بی‌اثر می‌کند.
 *
 * ⚠ `JobService` است نه سرویسِ پیش‌زمینه: اندروید خودش زمانِ اجرا را با
 *   شبکه هماهنگ می‌کند، اعلانِ دائمی لازم نیست، و تلاشِ دوباره با
 *   `jobFinished(…, true)` رایگان است. یک کارِ دوره‌ای (`schedule()`) هم
 *   پیامکی را که رام گیرنده‌اش را خوابانده بود از صندوق برمی‌دارد.
 */
public class SmsSync extends JobService {

    static final int  JOB_NOW      = 7101;
    static final int  JOB_PERIODIC = 7102;
    static final long PERIOD_MS    = 30L * 60 * 1000;
    static final long TIMEOUT_MS   = 90L * 1000;
    static final int  BATCH        = 15;
    static final int  REVIEW_MAX   = 40;

    /** کلیدِ محدودِ `sms` (فقط `api/v1/sms/*`) — نه رمز، نه نشست. */
    static final String PREF_TOKEN   = "sms_token";
    static final String PREF_USER    = "sms_user";
    static final String PREF_NONCE   = "sms_link_nonce";
    /** زمانِ (نه متنِ) پیامک‌هایی که تأییدِ کاربر را می‌خواهند. */
    static final String PREF_REVIEW  = "sms_review";
    /** ردِ آخرین اجرا برای صفحه‌ی تنظیم: زمان، نتیجه، شمارِ ثبت‌شده‌ها. */
    static final String PREF_SYNC_AT  = "sms_sync_at";
    static final String PREF_SYNC_WHY = "sms_sync_why";
    static final String PREF_SYNC_N   = "sms_sync_n";

    static final String WHY_OK      = "ok";
    static final String WHY_OFFLINE = "offline";
    static final String WHY_UNAUTH  = "unauth";

    /**
     * ⚠ پیامکی که گیرنده همین حالا گرفت، **فقط در حافظه**. ممکن است کارگر
     *   زودتر از نوشته شدنش در صندوق اجرا شود؛ اگر پروسه بمیرد، اجرای بعدی
     *   همان را از صندوق برمی‌دارد.
     */
    private static final ArrayList<Item> PENDING = new ArrayList<>();

    static final class Item {
        final String text;
        final String from;
        final long   at;
        Item(String text, String from, long at) { this.text = text; this.from = from; this.at = at; }
    }

    private WebView       web;
    private JobParameters job;
    private Handler       main;
    private boolean       finished;
    private boolean       started;
    private ArrayList<Item> items = new ArrayList<>();
    private boolean       hadToken;

    // ------------------------------------------------------------------
    // ابزارهای بیرونی (گیرنده، درِ ورودی، صفحه‌ی تنظیم)
    // ------------------------------------------------------------------

    static SharedPreferences prefs(Context ctx) {
        return ctx.getSharedPreferences(BankSmsReceiver.PREFS, Context.MODE_PRIVATE);
    }

    static boolean linked(Context ctx) {
        String t = prefs(ctx).getString(PREF_TOKEN, "");
        return t != null && !t.isEmpty();
    }

    /** کدِ جفت شدن — یک بار ساخته می‌شود و تا گرفتنِ کلید می‌ماند. */
    static String nonce(Context ctx) {
        SharedPreferences sp = prefs(ctx);
        String n = sp.getString(PREF_NONCE, "");
        if (n != null && n.matches("[a-f0-9]{32}")) { return n; }
        byte[] b = new byte[16];
        new SecureRandom().nextBytes(b);
        StringBuilder sb = new StringBuilder();
        for (byte x : b) { sb.append(String.format("%02x", x & 0xff)); }
        sp.edit().putString(PREF_NONCE, sb.toString()).apply();
        return sb.toString();
    }

    /** پیامکِ تازه از گیرنده: در حافظه بنشیند و کارگر همین حالا بیدار شود. */
    static void offer(Context ctx, String text, String from) {
        synchronized (PENDING) {
            PENDING.add(new Item(text, from, System.currentTimeMillis()));
            while (PENDING.size() > BATCH) { PENDING.remove(0); }
        }
        kick(ctx);
    }

    /**
     * اجرای یک‌باره با شرطِ شبکه. ⚠ اندروید ۱۲ به بالا «فوری» (expedited)
     * — اپ در پس‌زمینه است و کارِ معمولی ممکن بود ساعت‌ها عقب بیفتد.
     */
    static void kick(Context ctx) {
        try {
            JobScheduler js = (JobScheduler) ctx.getSystemService(Context.JOB_SCHEDULER_SERVICE);
            if (js == null) { return; }
            JobInfo.Builder b = new JobInfo.Builder(JOB_NOW, new ComponentName(ctx, SmsSync.class))
                    .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                try {
                    js.schedule(b.setExpedited(true).build());
                    return;
                } catch (Throwable ignored) {
                    b = new JobInfo.Builder(JOB_NOW, new ComponentName(ctx, SmsSync.class))
                            .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY);
                }
            }
            js.schedule(b.setBackoffCriteria(30_000L, JobInfo.BACKOFF_POLICY_EXPONENTIAL).build());
        } catch (Throwable ignored) { /* ⛔ پیامک هرگز چیزی را نمی‌خواباند */ }
    }

    /** کارِ دوره‌ای — پیامکی که گیرنده‌اش خوابانده شده بود، از صندوق. */
    static void schedule(Context ctx) {
        try {
            JobScheduler js = (JobScheduler) ctx.getSystemService(Context.JOB_SCHEDULER_SERVICE);
            if (js == null) { return; }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N && js.getPendingJob(JOB_PERIODIC) != null) { return; }
            js.schedule(new JobInfo.Builder(JOB_PERIODIC, new ComponentName(ctx, SmsSync.class))
                    .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                    .setPeriodic(PERIOD_MS)
                    .setPersisted(true)
                    .build());
        } catch (Throwable ignored) { }
    }

    /**
     * ⛔ پیامک‌هایی که در حالتِ «وصل» تأیید می‌خواهند — برای `#smsq=`ِ
     *    `HesabLauncherActivity`. فقط زمان‌ها ذخیره بودند؛ متن از صندوق.
     */
    static ArrayList<String> takeReview(Context ctx) {
        ArrayList<String> out = new ArrayList<>();
        SharedPreferences sp = prefs(ctx);
        ArrayList<Long> ats = reviewList(sp);
        if (ats.isEmpty()) { return out; }
        for (Item it : readInbox(ctx, ats.get(0) - 1, BATCH * 4)) {
            if (ats.contains(it.at) && !out.contains(it.text)) { out.add(it.text); }
        }
        sp.edit().remove(PREF_REVIEW).apply();
        return out;
    }

    // ------------------------------------------------------------------
    // صندوق
    // ------------------------------------------------------------------

    static boolean canRead(Context ctx) {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.M
            || ctx.checkSelfPermission(Manifest.permission.READ_SMS) == PackageManager.PERMISSION_GRANTED;
    }

    /**
     * پیامک‌های بانکیِ صندوق بعد از `from` — صافی همان
     * `BankSmsReceiver.classify()`، نه یک نسخه‌ی دوم.
     */
    static ArrayList<Item> readInbox(Context ctx, long from, int max) {
        ArrayList<Item> out = new ArrayList<>();
        if (!canRead(ctx)) { return out; }
        Cursor c = null;
        try {
            c = ctx.getContentResolver().query(
                    Uri.parse("content://sms/inbox"),
                    new String[]{ "body", "date", "address" },
                    "date > ?",
                    new String[]{ String.valueOf(from) },
                    "date ASC");
            if (c == null) { return out; }
            while (c.moveToNext() && out.size() < max) {
                String txt = c.getString(0);
                if (txt == null || !BankSmsReceiver.WHY_OK.equals(BankSmsReceiver.classify(txt))) { continue; }
                String addr = c.getString(2);
                out.add(new Item(txt, addr == null ? "" : addr, c.getLong(1)));
            }
        } catch (Throwable t) {
            return new ArrayList<>();
        } finally {
            if (c != null) { c.close(); }
        }
        return out;
    }

    private static ArrayList<Long> reviewList(SharedPreferences sp) {
        ArrayList<Long> out = new ArrayList<>();
        String raw = sp.getString(PREF_REVIEW, "");
        if (raw == null || raw.isEmpty()) { return out; }
        for (String s : raw.split(",")) {
            try { out.add(Long.parseLong(s.trim())); } catch (NumberFormatException ignored) { }
        }
        Collections.sort(out);
        return out;
    }

    private static void addReview(SharedPreferences sp, long at) {
        ArrayList<Long> list = reviewList(sp);
        if (!list.contains(at)) { list.add(at); }
        Collections.sort(list);
        while (list.size() > REVIEW_MAX) { list.remove(0); }
        StringBuilder sb = new StringBuilder();
        for (Long l : list) { if (sb.length() > 0) { sb.append(','); } sb.append(l); }
        sp.edit().putString(PREF_REVIEW, sb.toString()).apply();
    }

    // ------------------------------------------------------------------
    // اجرای کار
    // ------------------------------------------------------------------

    @Override
    public boolean onStartJob(JobParameters params) {
        try {
            SharedPreferences sp = prefs(this);
            if (!sp.getBoolean(BankSmsReceiver.PREF_ON, BankSmsReceiver.DEFAULT_ON)) { return false; }
            String token = sp.getString(PREF_TOKEN, "");
            String nonce = sp.getString(PREF_NONCE, "");
            hadToken = token != null && !token.isEmpty();
            if (!hadToken && (nonce == null || nonce.isEmpty())) { return false; }

            items = collect(sp);
            if (hadToken && items.isEmpty()) { return false; }
            if (!hadToken && !items.isEmpty()) {
                // ⚠ هنوز کلید نیست: فقط برای گرفتنش اجرا می‌شود؛ پیامک‌ها مالِ
                //   مسیرِ اعلان/صندوقِ قبلی‌اند تا کلید برسد — دو پردازنده نه.
                items = new ArrayList<>();
            }

            job = params;
            main = new Handler(Looper.getMainLooper());
            main.postDelayed(new Runnable() {
                @Override public void run() { finish(true); }
            }, TIMEOUT_MS);
            startWorker(token, nonce);
            return true;
        } catch (Throwable t) {
            return false;
        }
    }

    /** حافظه‌ی گیرنده + صندوق از نشانه، بی‌تکرار و به ترتیبِ زمان. */
    private ArrayList<Item> collect(SharedPreferences sp) {
        long mark = sp.getLong(BankSmsReceiver.PREF_SCAN_AT, 0L);
        long now  = System.currentTimeMillis();
        long from = mark > 0 ? Math.max(mark, now - HesabLauncherActivity.DEEP_WINDOW_MS)
                             : now - HesabLauncherActivity.FIRST_WINDOW_MS;
        ArrayList<Item> out = readInbox(this, from, BATCH);
        synchronized (PENDING) {
            for (Item p : PENDING) {
                boolean seen = false;
                for (Item o : out) { if (o.text.equals(p.text)) { seen = true; break; } }
                if (!seen) { out.add(p); }
            }
            PENDING.clear();
        }
        Collections.sort(out, new Comparator<Item>() {
            @Override public int compare(Item a, Item b) { return Long.compare(a.at, b.at); }
        });
        while (out.size() > BATCH) { out.remove(out.size() - 1); }
        return out;
    }

    @SuppressLint({"SetJavaScriptEnabled", "AddJavascriptInterface"})
    private void startWorker(String token, String nonce) throws Exception {
        JSONArray arr = new JSONArray();
        for (Item it : items) {
            arr.put(new JSONObject().put("t", it.text).put("at", it.at));
        }
        final String cfg = new JSONObject()
                .put("token", hadToken ? token : "")
                .put("nonce", hadToken ? "" : nonce)
                .put("items", arr)
                .toString()
                .replace("\u2028", "\\u2028").replace("\u2029", "\\u2029");

        Uri launch = Uri.parse(getString(R.string.launch_url));
        String path = launch.getPath() == null ? "" : launch.getPath();
        String dir  = path.contains("/") ? path.substring(0, path.lastIndexOf('/')) : "";
        final String url = launch.getScheme() + "://" + launch.getEncodedAuthority() + dir + "/assets/sms-worker.html";

        web = new WebView(getApplicationContext());
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        // ⛔ همیشه تازه: اصلاحِ پارسر روی سرور باید همان لحظه به گوشی برسد.
        s.setCacheMode(WebSettings.LOAD_NO_CACHE);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        web.addJavascriptInterface(new Bridge(), "HesabSms");
        web.setWebViewClient(new WebViewClient() {
            // ⛔ هیچ ناوبری‌ای: این WebView جز همان یک صفحه را باز نمی‌کند.
            @Override public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest r) { return true; }
            @SuppressWarnings("deprecation")
            @Override public boolean shouldOverrideUrlLoading(WebView v, String u) { return true; }

            @Override public void onPageFinished(WebView v, String u) {
                if (started || finished || u == null || !u.startsWith(url)) { return; }
                started = true;
                v.evaluateJavascript("window.smsRun && window.smsRun(" + cfg + ");", null);
            }

            @Override public void onReceivedError(WebView v, WebResourceRequest r, WebResourceError e) {
                if (r != null && r.isForMainFrame()) { record(WHY_OFFLINE, 0); finish(true); }
            }
        });
        web.loadUrl(url);
    }

    /** پلِ صفحه → اپ. فقط یک متد، و فقط JSONِ نتیجه. */
    private final class Bridge {
        @JavascriptInterface
        public void done(final String json) {
            if (main == null) { return; }
            main.post(new Runnable() {
                @Override public void run() { handle(json); }
            });
        }
    }

    private void handle(String json) {
        if (finished) { return; }
        boolean retry = false;
        try {
            SharedPreferences sp = prefs(this);
            JSONObject o = new JSONObject(json);
            String got = o.optString("token", "");
            if (!got.isEmpty()) {
                sp.edit().putString(PREF_TOKEN, got).remove(PREF_NONCE)
                  .putString(PREF_USER, o.optString("username", "")).apply();
                // ⚠ تازه وصل شد: پیامک‌های صندوق از همین حالا مالِ این کارگرند.
                kick(this);
            }
            if (o.optBoolean("unauth", false)) {
                // ⛔ کلید باطل شد («خروج از همه‌ی دستگاه‌ها»): پاک، و برگشت به
                //    مسیرِ اعلان — پیامک‌های همین نوبت گم نمی‌شوند.
                sp.edit().remove(PREF_TOKEN).remove(PREF_USER).apply();
                for (Item it : items) { BankSmsReceiver.postNotification(this, it.from, it.text); }
                record(WHY_UNAUTH, 0);
                finish(false);
                return;
            }

            JSONArray res = o.optJSONArray("results");
            long mark = sp.getLong(BankSmsReceiver.PREF_SCAN_AT, 0L);
            long reached = mark;
            int posted = 0;
            int n = res == null ? 0 : res.length();
            for (int i = 0; i < n && i < items.size(); i++) {
                JSONObject r = res.getJSONObject(i);
                Item it = items.get(i);
                String st = r.optString("status", "");
                if ("posted".equals(st) || "dup".equals(st)) {
                    if ("posted".equals(st)) { posted++; }
                    BankSmsReceiver.postSaved(this, r.optString("label", ""), it.text);
                } else if ("review".equals(st)) {
                    addReview(sp, it.at);
                    BankSmsReceiver.postNotification(this, it.from, it.text);
                } else if (!"skip".equals(st)) {
                    retry = true;
                    break;
                }
                reached = Math.max(reached, it.at);
            }
            if (n < items.size() && !retry) { retry = hadToken; }
            if (reached > mark) { sp.edit().putLong(BankSmsReceiver.PREF_SCAN_AT, reached).apply(); }
            record(retry ? WHY_OFFLINE : WHY_OK, posted);
            // ⚠ نوبتِ پُر یعنی شاید باز هم هست.
            if (!retry && items.size() >= BATCH) { kick(this); }
        } catch (Throwable t) {
            retry = true;
        }
        finish(retry);
    }

    private void record(String why, int posted) {
        SharedPreferences sp = prefs(this);
        sp.edit().putLong(PREF_SYNC_AT, System.currentTimeMillis())
          .putString(PREF_SYNC_WHY, why)
          .putInt(PREF_SYNC_N, sp.getInt(PREF_SYNC_N, 0) + posted)
          .apply();
    }

    private void finish(boolean retry) {
        if (finished) { return; }
        finished = true;
        cleanup();
        if (job != null) { jobFinished(job, retry); }
    }

    private void cleanup() {
        if (main != null) { main.removeCallbacksAndMessages(null); }
        if (web != null) {
            try {
                web.stopLoading();
                web.removeJavascriptInterface("HesabSms");
                web.destroy();
            } catch (Throwable ignored) { }
            web = null;
        }
    }

    @Override
    public boolean onStopJob(JobParameters params) {
        // ⚠ سیستم کار را برید (شبکه رفت، سقفِ زمان): دوباره، از همان نشانه.
        finished = true;
        cleanup();
        return true;
    }
}
