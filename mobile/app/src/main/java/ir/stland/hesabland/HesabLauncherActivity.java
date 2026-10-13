package ir.stland.hesabland;

import android.Manifest;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.Intent;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.provider.Settings;

import androidx.annotation.Nullable;

import com.google.androidbrowserhelper.trusted.LauncherActivity;

import org.json.JSONArray;

import java.net.URLDecoder;
import java.net.URLEncoder;
import java.util.ArrayList;

/**
 * درِ ورودیِ اپ — همان `LauncherActivity`ِ کتابخانه، با **یک** کارِ اضافه:
 * پرسیدنِ مجوزِ پیامک و اعلان در همان اجرای اول.
 *
 * ⛔ **گزارشِ مالکِ نصب:** «اپ اندروید هنگام راه‌اندازی تأییدِ دسترسی
 *    نمی‌گیره، پیامِ برداشت یا واریز هم گوشی نمی‌گیره.» هر دو یک علت
 *    داشتند: مجوز فقط از صفحه‌ی تنظیم (لینکِ `intent://` در پروفایل)
 *    خواسته می‌شد و کلید هم پیش‌فرض خاموش بود — یعنی کسی که فقط اپ را
 *    نصب و باز می‌کرد **هرگز** پیامکی نمی‌گرفت، بی‌هیچ خطایی.
 *
 * ⛔ **و این همان «پنجره‌ی اضافه»ای نیست که یک بار پس گرفته شد.** آن
 *    نسخه `LAUNCHER` را به `SmsSetupActivity` برده بود: هر بار باز کردنِ
 *    اپ یک اکتیویتیِ دیگر پیش از کروم می‌ساخت و کاربر «رفرش و صفحه‌ی
 *    سفید» می‌دید. اینجا هیچ اکتیویتیِ تازه‌ای در کار نیست — همان یک
 *    اکتیویتیِ کتابخانه است که فقط **تا جوابِ دیالوگ** صبر می‌کند
 *    (`shouldLaunchImmediately()` که خودِ کتابخانه برای همین کار گذاشته)
 *    و بعد همان `launchTwa()` را صدا می‌زند. وقتی مجوزها داده شده‌اند —
 *    یعنی از اجرای دوم به بعد — رفتار بایت‌به‌بایت همان کتابخانه است.
 *
 * ⛔ **دو بار می‌پرسد، نه هر بار** (`MAX_ASKS`). پرسیدن در هر اجرا همان
 *    مزاحمتی است که آدم را وادار می‌کند اپ را پاک کند؛ و اندروید ۱۱ به
 *    بعد خودش بعد از دو «نه» دیگر دیالوگ نشان نمی‌دهد. راهِ بعدی همان
 *    صفحه‌ی تنظیم است که حالتِ «مجوز نیست» را با دکمه‌اش نشان می‌دهد.
 *
 * ⛔ **رد کردن هیچ دری را نمی‌بندد**: چه «اجازه» چه «نه»، اپ همان لحظه
 *    باز می‌شود. و هر خطایی در این مسیر به `launchTwa()` می‌رسد نه به
 *    کرش — اپی که به‌خاطرِ یک دیالوگِ کمکی باز نشود، بدترین خرابیِ ممکن
 *    است.
 *
 * ⛔ **و کارِ دوم: آوردنِ پیامک‌های بانکیِ تازه‌ی صندوق به اپ.**
 *    گزارشِ مالکِ نصب: «پیامکِ برداشت فرستادم هیچ اتفاقی نیفتاد… می‌خوام
 *    تمام پیامک‌های حساب روی اپ بالا بیاد و در جای خودش بشینه.» تا دیروز
 *    تنها راه اعلانِ `BankSmsReceiver` بود و دو جا بی‌صدا می‌شکست: رامی
 *    که گیرنده را در پس‌زمینه خواب نگه می‌دارد (هیچ اعلانی ساخته نمی‌شد)،
 *    و کاربری که اعلان را نمی‌زند و اپ را از آیکون باز می‌کند (پیامک
 *    هرگز به دفتر نمی‌رسید). حالا هر بار که اپ باز می‌شود، پیامک‌هایی که
 *    از `PREF_SCAN_AT` به بعد رسیده‌اند در **فرگمنتِ** آدرس (`#smsq=`)
 *    به سایت داده می‌شوند و همان `parseBankSms()` و همان ثبتِ خودکارِ
 *    وب کارشان را می‌کنند.
 *
 * ⛔ این جای تصمیمِ قبلیِ «صندوق هرگز خودکار خوانده نمی‌شود» را گرفت،
 *    به خواستِ صریحِ مالکِ نصب — ولی با همان سه مرز:
 *    - **هیچ پارسِ دومی نیست:** صافی همان `BankSmsReceiver.classify()`
 *      است و تصمیمِ واقعی در `parseBankSms()`.
 *    - **متن روی سیم نمی‌رود:** فقط فرگمنت (قاعده ۱۹)، و هیچ‌جا ذخیره
 *      نمی‌شود — فقط نشانه‌ی زمان جلو می‌رود.
 *    - **فقط با کلیدِ روشن و مجوزِ `READ_SMS`**؛ بدونشان هیچ کاری نمی‌کند.
 *
 * ⚠ `PREF_SCAN_AT` یعنی «تا اینجا به سایت داده شد»، نه «اعلانش ساخته
 *   شد». اگر گیرنده جلویش می‌برد، پیامکی که کاربر اعلانش را نزده بود با
 *   باز کردنِ اپ هم دیگر نمی‌آمد — دقیقاً همان خرابی.
 */
public class HesabLauncherActivity extends LauncherActivity {

    private static final int    REQ_START   = 4031;
    private static final int    REQ_GUIDE   = 4032;
    private static final int    REQ_BATTERY = 4033;
    private static final String KEY_ASKING = "hesabland.asking";

    /**
     * چند بار پرسیده شده — نه بیشتر از `MAX_ASKS`، **در هر نسخه‌ی اپ**.
     *
     * ⛔ **گزارشِ مالکِ نصب (مهر ۱۴۰۵):** «نسخه‌ی آخر رو نصب کردم … از من
     *    درخواستِ دسترسی به باتری و اس‌ام‌اس نخواست.» شمارنده در عمرِ **نصب**
     *    بود و نصبِ نسخه‌ی تازه روی قبلی prefs را نگه می‌دارد؛ کسی که دو بار
     *    دیالوگ را رد کرده (یا اندروید بی‌صدا ردش کرده بود) دیگر هرگز پرسیده
     *    نمی‌شد. حالا با هر نسخه‌ی تازه از صفر (`PREF_ASKS_VC`).
     */
    static final String PREF_ASKS    = "perm_asks";
    static final String PREF_ASKS_VC = "perm_asks_vc";
    static final String PREF_GUIDES  = "perm_guides";
    static final String PREF_BATTERY = "perm_battery";
    static final int    MAX_ASKS  = 2;

    /**
     * ⚠ مرزهای آوردنِ صندوق. `IMPORT_MAX` سقفِ یک نوبت است (فرگمنت از
     *   قبل هم سقفِ ۲۰ دارد — `SMS_HASH_MAX` در `app.js`)؛ باقی‌مانده
     *   بی‌صدا نمی‌ماند: نشانه روی آخرین پیامکِ آورده‌شده می‌ایستد و
     *   باز کردنِ بعدی از همان‌جا ادامه می‌دهد.
     * ⚠ `FIRST_WINDOW_MS`: اولین بار (نشانه صفر) فقط دو روز عقب می‌رود،
     *   نه یک هفته — کاربری که از قبل دستی ثبت کرده، با اولین باز کردن یک
     *   هفته تراکنشِ تکراری نمی‌گیرد. بیشتر از آن با دکمه‌ی «بررسی
     *   پیامک‌های قبلی» است (`EXTRA_DEEP`).
     */
    static final int    IMPORT_MAX      = 10;
    static final int    IMPORT_ROWS     = 200;
    static final long   FIRST_WINDOW_MS = 2L * 24 * 60 * 60 * 1000;
    static final long   DEEP_WINDOW_MS  = 7L * 24 * 60 * 60 * 1000;
    static final String EXTRA_DEEP      = "hesabland.deep_scan";

    /** در همین اجرا یک بار؛ `getLaunchingUrl()` و `onCreate` هر دو می‌پرسند. */
    private boolean pendingApplied;

    /**
     * ⚠ دیالوگ باز است. اگر اکتیویتی زیرِ آن از نو ساخته شود (چرخشِ
     *   صفحه)، دوباره نمی‌پرسیم و فقط منتظرِ همان جواب می‌مانیم؛ وگرنه
     *   دو دیالوگِ روی هم بالا می‌آمد.
     */
    private boolean asking;

    @Override
    protected void onCreate(@Nullable Bundle saved) {
        asking = saved != null && saved.getBoolean(KEY_ASKING, false);

        // ⛔ پیش از `super`: اگر اپ همین حالا باز باشد و آیکون زده شود،
        //    کتابخانه اکتیویتیِ بی‌داده را همان‌جا می‌بندد و چیزی به سایت
        //    نمی‌رسد. با گذاشتنِ آدرس روی intent، همان TWAی باز به آدرسِ
        //    تازه می‌رود — **فقط وقتی پیامکِ تازه‌ای هست**؛ وگرنه هر تپ
        //    روی آیکون صفحه را به خانه برمی‌گرداند.
        if (saved == null) {
            // ⛔ «اشتراک‌گذاری» از اپِ پیامک — راهی که **در هر گوشی** کار می‌کند،
            //    حتی بی‌هیچ مجوزی (اپِ نصب‌شده از بیرونِ فروشگاه که اندروید
            //    مجوزِ پیامکش را بسته). متن در **فرگمنت** می‌رود، مثلِ اعلان.
            try { takeShared(); } catch (Throwable ignored) { }
            try {
                Intent it = getIntent();
                Uri base = it.getData() != null ? it.getData()
                        : Uri.parse(getString(R.string.launch_url));
                Uri withSms = withPendingSms(base, it.getBooleanExtra(EXTRA_DEEP, false));
                if (withSms != null) {
                    Intent copy = new Intent(it);
                    copy.setData(withSms);
                    setIntent(copy);
                }
            } catch (Throwable ignored) { /* ⛔ پیامک هرگز جلوی باز شدن را نمی‌گیرد */ }
            // ⛔ کارگرِ پس‌زمینه: دوره‌ای زنده بماند، و اگر وصل است همین حالا
            //    هم یک نوبت (پیامکی که گیرنده‌اش خوابانده شده بود).
            try {
                SmsSync.schedule(this);
                if (SmsSync.linked(this)) { SmsSync.kick(this); }
            } catch (Throwable ignored) { }
        }
        super.onCreate(saved);
    }

    /**
     * ⚠ مسیرِ اجرای اول: مجوز تازه داده شده و `onCreate` هنوز نمی‌توانست
     *   صندوق را بخواند. `launchTwa()` همین را صدا می‌زند.
     */
    @Override
    protected Uri getLaunchingUrl() {
        Uri u = super.getLaunchingUrl();
        try {
            Uri withSms = withPendingSms(u, getIntent().getBooleanExtra(EXTRA_DEEP, false));
            if (withSms != null) { u = withSms; }
        } catch (Throwable ignored) { }
        u = withSmsLink(u);
        return withAppVersion(u);
    }

    /**
     * ⛔ **کارِ پنجم: پیامکِ اشتراک‌گذاشته** (`ACTION_SEND`، `text/plain`).
     *
     *    روشِ همه‌ی اپ‌های پیامک‌خوان برای گوشی‌ای که مجوز ندارد: در اپِ
     *    پیامک، پیامکِ بانک را نگه دارید ← «اشتراک‌گذاری» ← حساب‌لند. متن به
     *    همان `#sms=`ِ اعلان تبدیل می‌شود (فرگمنت، نه query — قاعده ۱۹) و
     *    intent دیگر «SEND» نیست تا کتابخانه آن را «هدفِ اشتراکِ وب» نپندارد.
     * ⚠ سقفِ طول: یک پیامک، نه یک کتاب — متنِ بلندتر بریده می‌شود.
     */
    static final int SHARE_MAX = 1000;

    private void takeShared() throws Exception {
        Intent it = getIntent();
        if (it == null || !Intent.ACTION_SEND.equals(it.getAction())) { return; }
        CharSequence cs = it.getCharSequenceExtra(Intent.EXTRA_TEXT);
        String text = cs == null ? "" : cs.toString().trim();
        Intent copy = new Intent(it);
        copy.setAction(Intent.ACTION_VIEW);
        copy.removeExtra(Intent.EXTRA_TEXT);
        copy.setType(null);
        Uri base = Uri.parse(getString(R.string.launch_url));
        if (!text.isEmpty()) {
            if (text.length() > SHARE_MAX) { text = text.substring(0, SHARE_MAX); }
            String enc = URLEncoder.encode(text, "UTF-8").replace("+", "%20");
            base = base.buildUpon().encodedFragment("sms=" + enc).build();
        }
        copy.setData(base);
        setIntent(copy);
    }

    /**
     * ⛔ **کارِ چهارم: جفت کردنِ گوشی برای ثبتِ پیامک در پس‌زمینه**
     *    (`#smslink=<کد>`) — بی‌هیچ کارِ کاربر.
     *
     *    کد را خودِ اپ ساخته (`SmsSync.nonce`) و در **فرگمنت** است: نه به
     *    سرور می‌رود (فقط صفحه‌ی واردشده آن را با `api/sms_link.php` به
     *    کاربرِ نشست می‌بندد) نه در لاگ می‌نشیند. کارگر بعداً با همان کد
     *    کلیدِ محدودِ `sms` را می‌گیرد.
     *
     * ⚠ فقط وقتی فرگمنتِ دیگری (`#smsq=`) در کار نیست — یک فرگمنت در هر
     *   باز شدن؛ جفت شدن به بارِ بعد می‌ماند. و فقط با کلیدِ روشن و مجوزِ
     *   صندوق: بی‌آن‌ها کارگر چیزی برای ثبت ندارد.
     */
    private Uri withSmsLink(Uri u) {
        try {
            if (u == null || u.getEncodedFragment() != null || SmsSync.linked(this)) { return u; }
            if (!getSharedPreferences(BankSmsReceiver.PREFS, Context.MODE_PRIVATE)
                    .getBoolean(BankSmsReceiver.PREF_ON, BankSmsReceiver.DEFAULT_ON)) { return u; }
            if (!SmsSync.canRead(this)) { return u; }
            return u.buildUpon().encodedFragment("smslink=" + SmsSync.nonce(this)).build();
        } catch (Throwable t) {
            return u;
        }
    }

    /**
     * ⛔ **کارِ سوم: گفتنِ نسخه‌ی خودِ اپ به سایت** (`?appv=10`).
     *    گزارشِ مالکِ نصب: «وقتی آپدیت شد، برنامه رو باز کرد، پیام برای
     *    کاربر بره که آپدیت کنه.» سایت آخرین نسخه را از خودِ فایلِ APKِ
     *    روی دامنه می‌خواند؛ بدونِ این عدد نمی‌دانست نسخه‌ی روی گوشی
     *    کدام است.
     *
     * ⛔ **query است نه فرگمنت، و این استثنای قاعده ۱۹ عمدی و تک‌کلیدی است.**
     *    سرور *باید* این یکی را ببیند (کوکی را پشتِ ریدایرکتِ ورود هم
     *    می‌گذارد)، و این عدد هیچ چیزِ شخصی‌ای نیست — همان چیزی است که
     *    هر فروشگاه هم می‌بیند. متنِ پیامک همچنان فقط در فرگمنت است.
     *
     * ⚠ از `PackageManager` خوانده می‌شود نه `BuildConfig`: AGP 8 کلاسِ
     *   `BuildConfig` را پیش‌فرض نمی‌سازد. هر خطا یعنی آدرسِ دست‌نخورده —
     *   نسخه هرگز جلوی باز شدنِ اپ را نمی‌گیرد.
     */
    static final String PARAM_APP_VERSION = "appv";

    private Uri withAppVersion(Uri u) {
        try {
            if (u == null || u.getQueryParameter(PARAM_APP_VERSION) != null) { return u; }
            long vc = installedVersionCode();
            if (vc <= 0) { return u; }
            return u.buildUpon().appendQueryParameter(PARAM_APP_VERSION, String.valueOf(vc)).build();
        } catch (Throwable t) {
            return u;
        }
    }

    @SuppressWarnings("deprecation")
    private long installedVersionCode() throws Exception {
        PackageInfo pi = getPackageManager().getPackageInfo(getPackageName(), 0);
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.P ? pi.getLongVersionCode() : pi.versionCode;
    }

    /**
     * آدرسِ `base` + پیامک‌های تازه در `#smsq=`، یا `null` اگر چیزی نیست.
     *
     * ⚠ فرگمنتِ موجود (`#sms=` از تپ روی اعلان، یا `#smsq=`ِ همین
     *   intent پس از `restartInNewTask`) نگه داشته و ادغام می‌شود؛ متنِ
     *   تکراری یک بار می‌آید.
     */
    private Uri withPendingSms(Uri base, boolean deep) throws Exception {
        if (pendingApplied) { return null; }
        // ⚠ بدونِ مجوز نشانه **نمی‌خورد**: در اجرای اول `onCreate` پیش از
        //   دیالوگ می‌پرسد و اگر اینجا «انجام شد» ثبت می‌شد، `launchTwa()`ِ
        //   بعد از «اجازه» دیگر صندوق را نمی‌خواند.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && !has(Manifest.permission.READ_SMS)) {
            return null;
        }
        pendingApplied = true;

        ArrayList<String> items = new ArrayList<>();
        String frag = base.getEncodedFragment();
        if (frag != null && frag.startsWith("sms=")) {
            String one = URLDecoder.decode(frag.substring(4), "UTF-8");
            if (!one.trim().isEmpty()) { items.add(one); }
        } else if (frag != null && frag.startsWith("smsq=")) {
            JSONArray old = new JSONArray(URLDecoder.decode(frag.substring(5), "UTF-8"));
            for (int i = 0; i < old.length(); i++) { items.add(old.optString(i, "")); }
        }

        for (String t : takeInbox(deep)) {
            if (!items.contains(t)) { items.add(t); }
        }
        if (items.isEmpty()) { return null; }

        JSONArray arr = new JSONArray();
        for (String t : items) { if (!t.trim().isEmpty()) { arr.put(t); } }
        // ⛔ فرگمنت، نه query — قاعده ۱۹. `URLEncoder` فاصله را `+`
        //   می‌کند و `decodeURIComponent` آن را برنمی‌گرداند، پس `%20`.
        String enc = URLEncoder.encode(arr.toString(), "UTF-8").replace("+", "%20");
        return base.buildUpon().encodedFragment("smsq=" + enc).build();
    }

    /**
     * پیامک‌های بانکیِ صندوق از نشانه به بعد — و جلو بردنِ نشانه.
     *
     * ⛔ صافی همان `BankSmsReceiver.classify()` است، نه یک نسخه‌ی دوم.
     * ⛔ متن فقط در حافظه است و به آدرس می‌رود؛ هیچ‌جا نوشته نمی‌شود.
     */
    private ArrayList<String> takeInbox(boolean deep) {
        ArrayList<String> out = new ArrayList<>();
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && !has(Manifest.permission.READ_SMS)) {
            return out;
        }
        SharedPreferences sp = getSharedPreferences(BankSmsReceiver.PREFS, Context.MODE_PRIVATE);
        if (!sp.getBoolean(BankSmsReceiver.PREF_ON, BankSmsReceiver.DEFAULT_ON)) { return out; }

        // ⛔ **وصل** به کارگرِ پس‌زمینه: صندوق مالِ `SmsSync` است و همان نشانه را
        //    جلو می‌برد. این‌جا فقط پیامک‌هایی می‌آیند که آن کارگر برای تأیید
        //    کنار گذاشت — وگرنه پیامکی که در پس‌زمینه ثبت شده بود، با باز شدنِ
        //    اپ از مسیرِ وب **دوباره** ثبت می‌شد.
        if (SmsSync.linked(this)) { return SmsSync.takeReview(this); }

        long now  = System.currentTimeMillis();
        long mark = sp.getLong(BankSmsReceiver.PREF_SCAN_AT, 0L);
        long from = deep ? now - DEEP_WINDOW_MS
                  : (mark > 0 ? Math.max(mark, now - DEEP_WINDOW_MS) : now - FIRST_WINDOW_MS);

        long reached = now;
        Cursor c = null;
        try {
            c = getContentResolver().query(
                    Uri.parse("content://sms/inbox"),
                    new String[]{ "body", "date" },
                    "date > ?",
                    new String[]{ String.valueOf(from) },
                    "date ASC");
            if (c == null) { return out; }
            int rows = 0;
            while (c.moveToNext()) {
                long at = c.getLong(1);
                if (++rows > IMPORT_ROWS) { reached = at - 1; break; }
                String txt = c.getString(0);
                if (txt == null || txt.isEmpty()) { continue; }
                if (!BankSmsReceiver.WHY_OK.equals(BankSmsReceiver.classify(txt))) { continue; }
                out.add(txt);
                if (out.size() >= IMPORT_MAX) { reached = at; break; }
            }
        } catch (Throwable t) {
            return new ArrayList<>();   // ⚠ رامی بی‌این provider — اپ باید باز شود
        } finally {
            if (c != null) { c.close(); }
        }

        // ⚠ نشانه عقب نمی‌رود: بررسیِ «عمیق» فقط برای یک نوبت پنجره را
        //   باز می‌کند، و دوباره آوردنِ همان‌ها را نگهبانِ اثرِ انگشتِ
        //   `app.js` می‌گیرد.
        if (reached > mark) {
            sp.edit().putLong(BankSmsReceiver.PREF_SCAN_AT, reached).apply();
        }
        return out;
    }

    @Override
    protected boolean shouldLaunchImmediately() {
        if (asking) { return false; }
        try {
            String[] need = missingPermissions();
            if (need.length == 0) {
                // ⛔ مجوزها هست؛ گامِ بعد (باتری) اگر لازم است — و بعد TWA
                if (nextStep()) { asking = true; return false; }
                return true;
            }

            SharedPreferences sp = prefsForVersion();
            int n = sp.getInt(PREF_ASKS, 0);
            if (n >= MAX_ASKS) { return true; }
            sp.edit().putInt(PREF_ASKS, n + 1).apply();

            requestPermissions(need, REQ_START);
            asking = true;
            return false;
        } catch (Throwable t) {
            asking = false;
            return true;   // ⛔ هر خطایی یعنی «همین حالا باز کن»، نه کرش
        }
    }

    /**
     * prefs با شمارنده‌های «پرسیده شد» که با هر نسخه‌ی تازه‌ی اپ صفر می‌شوند.
     */
    private SharedPreferences prefsForVersion() {
        SharedPreferences sp = getSharedPreferences(BankSmsReceiver.PREFS, Context.MODE_PRIVATE);
        long vc = 0;
        try { vc = installedVersionCode(); } catch (Throwable ignored) { }
        if (sp.getLong(PREF_ASKS_VC, -1L) != vc) {
            sp.edit().putLong(PREF_ASKS_VC, vc).putInt(PREF_ASKS, 0)
              .putInt(PREF_GUIDES, 0).putInt(PREF_BATTERY, 0).apply();
        }
        return sp;
    }

    /**
     * ⛔ گامِ بعد از دیالوگِ مجوز — **در همان زنجیره**، نه یک پنجره در هر اجرا:
     *
     *    ۱. پیامک هنوز اجازه ندارد → راهنمای `SmsSetupActivity`. علتِ رایجش
     *       «تنظیمِ محدود»ِ اندروید ۱۳ به بالاست: اپی که از مرورگر یا مدیرِ فایل
     *       نصب شده، دیالوگِ مجوزِ پیامک را **اصلاً نمی‌بیند** (اندروید بی‌صدا رد
     *       می‌کند) و تنها راه منوی ⋮ صفحه‌ی اطلاعاتِ برنامه است — همان چیزی که
     *       مالکِ نصب دید: «درخواستِ دسترسی نخواست».
     *    ۲. پیامک هست ولی اندروید اپ را در پس‌زمینه محدود کرده → دیالوگِ
     *       یک‌تپیِ «اجرا در پس‌زمینه» (همان که اپ‌های دیگر می‌گیرند).
     *
     *    هر کدام حداکثر `MAX_ASKS` بار در هر نسخه؛ بعد اپ عادی باز می‌شود و
     *    صفحه‌ی تنظیمِ پیامک همان‌ها را با دکمه نشان می‌دهد.
     * @return آیا اکتیویتی‌ای باز شد (جوابش در `onActivityResult` می‌رسد)
     */
    private boolean nextStep() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) { return false; }
        SharedPreferences sp = prefsForVersion();
        if (!sp.getBoolean(BankSmsReceiver.PREF_ON, BankSmsReceiver.DEFAULT_ON)) { return false; }
        if (!has(Manifest.permission.RECEIVE_SMS)) {
            int g = sp.getInt(PREF_GUIDES, 0);
            if (g >= MAX_ASKS) { return false; }
            sp.edit().putInt(PREF_GUIDES, g + 1).apply();
            Intent i = new Intent(this, SmsSetupActivity.class);
            i.putExtra(SmsSetupActivity.EXTRA_GUIDE, "1");
            startActivityForResult(i, REQ_GUIDE);
            return true;
        }
        PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
        if (pm != null && !pm.isIgnoringBatteryOptimizations(getPackageName())) {
            int b = sp.getInt(PREF_BATTERY, 0);
            if (b >= 1) { return false; }
            sp.edit().putInt(PREF_BATTERY, b + 1).apply();
            startActivityForResult(new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                    Uri.parse("package:" + getPackageName())), REQ_BATTERY);
            return true;
        }
        return false;
    }

    @Override
    protected void onActivityResult(int req, int res, Intent data) {
        super.onActivityResult(req, res, data);
        if (req != REQ_GUIDE && req != REQ_BATTERY) { return; }
        asking = false;
        // ⛔ هر جوابی → گامِ بعد اگر هست، وگرنه همان لحظه اپ باز می‌شود
        try {
            if (req == REQ_GUIDE && nextStep()) { asking = true; return; }
        } catch (Throwable ignored) { }
        launchTwa();
    }

    /**
     * مجوزهایی که هنوز داده نشده‌اند.
     *
     * ⚠ مجوزِ پیامک فقط وقتی پرسیده می‌شود که کاربر ثبتِ خودکار را **عمداً**
     *   خاموش نکرده باشد. پیش‌فرض روشن است (`PREF_ON` نبوده = روشن)؛ ولی
     *   کسی که در صفحه‌ی تنظیم «خاموش کردن» را زده نباید با هر نصب و
     *   اجرا دوباره درباره‌ی پیامک پرسیده شود.
     */
    private String[] missingPermissions() {
        ArrayList<String> out = new ArrayList<>();
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) { return new String[0]; }

        boolean captureOn = getSharedPreferences(BankSmsReceiver.PREFS, Context.MODE_PRIVATE)
                .getBoolean(BankSmsReceiver.PREF_ON, BankSmsReceiver.DEFAULT_ON);
        if (captureOn) {
            if (!has(Manifest.permission.RECEIVE_SMS)) { out.add(Manifest.permission.RECEIVE_SMS); }
            if (!has(Manifest.permission.READ_SMS))    { out.add(Manifest.permission.READ_SMS); }
        }
        // ⚠ اعلان فقط روی اندروید ۱۳ به بالا مجوزِ زمانِ اجرا دارد. هم اعلانِ
        //   پیامکِ بانک از آن رد می‌شود هم اعلانِ خودِ سایت (Web Push)، که
        //   با نشستنِ این مجوز همان لحظه «granted» دیده می‌شود.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
                && !has(Manifest.permission.POST_NOTIFICATIONS)) {
            out.add(Manifest.permission.POST_NOTIFICATIONS);
        }
        return out.toArray(new String[0]);
    }

    private boolean has(String perm) {
        return checkSelfPermission(perm) == PackageManager.PERMISSION_GRANTED;
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] results) {
        super.onRequestPermissionsResult(req, perms, results);
        if (req != REQ_START) { return; }
        asking = false;
        // ⛔ جواب هر چه باشد، اپ باز می‌شود — پس از گامِ بعد اگر لازم است.
        try {
            if (nextStep()) { asking = true; return; }
        } catch (Throwable ignored) { }
        launchTwa();
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        out.putBoolean(KEY_ASKING, asking);
    }
}
