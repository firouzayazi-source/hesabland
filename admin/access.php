<?php
/**
 * ورود، ثبت‌نام و پیامک — تنظیماتِ «چه کسی و چگونه وارد می‌شود».
 *
 * ⛔ این‌ها از `admin/users.php` جدا شدند و دلیلش صرفاً مرتبی نیست:
 *    آن صفحه هفت کارتِ بی‌ربط داشت (کاربران، اشتراک، پیامک، ثبت‌نام)
 *    و پیدا کردنِ هر کدام یعنی اسکرول کردنِ بقیه. بدتر از آن، دو فرم
 *    روی یک تنظیمِ مشترک می‌نوشتند: ذخیره‌ی کارتِ «ثبت‌نام» کلیدِ
 *    «ورود با پیامک» را **بی‌صدا خاموش می‌کرد**، چون آن فرم فیلدش را
 *    نداشت و مقدارِ نبوده صفر خوانده می‌شد. حالا هر تنظیم فقط یک
 *    نویسنده دارد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/sms_login.php';

Auth::initSession();
Auth::requireAdmin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    if ($action === 'update_login_setting') {
        setSetting('require_full_login', postParam('require_full_login') === '1' ? '1' : '0');
        redirectWithMessage('access.php', 'success', 'تنظیمات ورود بروزرسانی شد.');
    } elseif ($action === 'update_signup_setting') {
        // ⛔ ثبت‌نام پیش‌فرض خاموش است و فقط از همین‌جا روشن می‌شود. یک
        //    نصبِ خصوصی نباید با به‌روزرسانی، بی‌خبر به روی اینترنت باز شود.
        //
        // ⛔ و اینجا **دیگر به کلیدِ ورود با پیامک دست نمی‌زند**. پیش از
        //    این می‌زد، در حالی که فرمش آن فیلد را نداشت — پس هر ذخیره‌ی
        //    ساده‌ی این کارت، ورود با پیامک را خاموش می‌کرد بی‌آنکه
        //    چیزی گفته شود.
        setSetting(SIGNUP_SETTING, postParam('allow_signup') === '1' ? '1' : '0');

        $sup = trim(postParam('support_email'));
        if ($sup !== '' && !filter_var($sup, FILTER_VALIDATE_EMAIL)) {
            redirectWithMessage('access.php', 'error', 'ایمیل پشتیبانی معتبر نیست.');
        }
        setSetting('support_email', $sup);
        redirectWithMessage('access.php', 'success', 'تنظیمات ثبت‌نام ذخیره شد.');
    } elseif ($action === 'update_sms_setting') {
        // ⛔ ورود با پیامک هم پیش‌فرض خاموش است و فقط از همین‌جا روشن
        //    می‌شود: یک راهِ ورودِ تازه به هر حسابی است.
        setSetting(SMS_LOGIN_SETTING, postParam('allow_sms_login') === '1' ? '1' : '0');
        // ⛔ پیامکِ فروشگاه‌ها به مشتریانشان با همین پنل و به هزینه‌ی مالکِ نصب — پیش‌فرض خاموش
        //    (همان کلیدِ `BizComms::ADMIN_KEY`)
        setSetting('store_sms_enabled', postParam('allow_store_sms') === '1' ? '1' : '0');

        $smsMethodIn = postParam('sms_method');
        if (!array_key_exists($smsMethodIn, smsProviders())) { $smsMethodIn = ''; }
        setSetting('sms_method', $smsMethodIn);
        setSetting('sms_text', trim(postParam('sms_text')));

        redirectWithMessage('access.php', 'success', 'تنظیمات ورود با پیامک ذخیره شد.');
    } elseif ($action === 'update_sms_conn') {
        // ⚠ ورودیِ خالی یعنی «دست نزن»، نه «پاک کن» — همان قاعده‌ی
        //   `saveUserEmail`. فرم مقدارِ فعلیِ کلید را نشان نمی‌دهد (نباید
        //   هم بدهد)، پس ذخیره‌ی ساده نباید آن را خالی کند.
        //
        // ⚠ ولی «نام کاربری» و «کد بادی» و «شماره خط» مقدارشان روی فرم
        //   **دیده می‌شود**، پس برای آن‌ها خالی واقعاً یعنی خالی.
        foreach (['sms_api_key' => false, 'sms_pass' => false, 'sms_user' => true,
                  'sms_pattern' => true, 'sms_sender' => true,
                  'sms_meli_mode' => true] as $k => $visible) {
            // ⚠ مستقیم از `$_POST` خوانده می‌شود نه `postParam()`:
            //   آن تابع «نبود» و «خالی» را یکی می‌کند، و اینجا فرقشان
            //   مهم است — فیلدی که اصلاً در این فرم نیست نباید پاک شود.
            if (!isset($_POST[$k])) { continue; }
            $v = trim((string)$_POST[$k]);
            if ($v === '-') { $v = ''; }
            if ($v === '' && !$visible) { continue; } // مخفی و خالی = دست نزن
            // ⚠ کلید و رمز اغلب از روی یک آدرسِ نمونه کپی می‌شوند؛ همان
            //   تکه‌های اضافه باعثِ «کلید معتبر نیست» می‌شد.
            if (!$visible && $v !== '') { $v = smsNormalizeSecret($v); }
            setSetting($k, $v);
        }
        // ⛔ تست **بعد از** ذخیره و در همین اکشن انجام می‌شود.
        //
        //    پیش از این «ارسال آزمایشی» فرمِ جدایی بود. کاربر «نوع حساب»
        //    را عوض می‌کرد، دکمه‌ی تستِ فرمِ دوم را می‌زد، و تست با
        //    مقدارِ **قبلی** می‌رفت — چون آن فرم فیلدهای این یکی را
        //    نداشت. نتیجه‌اش همان خطای همیشگی بود و کاربر فکر می‌کرد
        //    گزینه‌ی تازه هم جواب نداد. حالا یک فرم است و تست دقیقاً با
        //    همان چیزی می‌رود که روی صفحه می‌بینید.
        // ⛔ تصمیم از روی **خودِ فیلدِ شماره** گرفته می‌شود، نه یک دکمه‌ی
        //    دوم: خالی یعنی فقط ذخیره، پر یعنی ذخیره و بعد آزمایش. یک
        //    ورودی، یک معنا.
        $phoneIn = trim(postParam('test_phone'));
        if ($phoneIn === '') {
            redirectWithMessage('access.php', 'success', 'تنظیمات پیامک ذخیره شد.');
        }

        $to = SmsLogin::normalizePhone($phoneIn);
        if ($to === null) {
            redirectWithMessage('access.php', 'error',
                'تنظیمات ذخیره شد، ولی شماره‌ی آزمایشی معتبر نیست.');
        }

        [$ok, $msg] = smsTestSend($to);
        redirectWithMessage('access.php', $ok ? 'success' : 'error', $msg);
    }
}

$requireFullLoginSetting = getSetting('require_full_login', '0') === '1';
$signupOn      = signupEnabled();
$supportEmail  = getSetting('support_email', '');
$smsLoginOn    = SmsLogin::switchedOn();
$smsTableReady = SmsLogin::tableReady();
$smsConfigured = Sms::isConfigured();
$smsMethod     = Sms::method();
$smsMissing    = $smsMethod === '' ? '' : Sms::missingFor($smsMethod);
// ⛔ اعتبارنامه‌ی کامل با «کار می‌کند» یکی نیست — الگو هم لازم است.
$patternWarning = smsPatternWarning();
// ⛔ «ثبت شده» می‌گوید چیزی هست؛ این می‌گوید آن چیز چه شکلی است.
$smsKeyShape    = smsSecretShape('sms_api_key', 'SMS_API_KEY');
// ⛔ پنلِ کارکن با «ورود پیامکی کار می‌کند» یکی نیست: بدونِ شماره‌ی
//    ثبت‌شده، هیچ کدی برای هیچ‌کس نمی‌رود و هیچ خطایی هم دیده نمی‌شود.
$phoneCount     = $smsTableReady ? SmsLogin::usersWithPhone() : -1;
// ⚠ از `Sms::meliMode()` می‌آید، نه از یک شرطِ تازه در همین صفحه — وگرنه
//   فرم یک نوعِ حساب را نشان می‌دهد و فرستنده نوعِ دیگری را صدا می‌زند.
$meliMode      = Sms::meliMode();
// فقط «ثبت شده یا نه» به صفحه می‌رود، نه خودِ مقدار.
$smsHas = [];
foreach (SMS_SETTING_KEYS as $k => $const) { $smsHas[$k] = smsSetting($k, $const) !== ''; }
// ⛔ فیلدِ خالی بعد از ذخیره دقیقاً شبیهِ «ذخیره نشد» است.
$smsMask = [
    'sms_api_key' => smsMaskedHint('sms_api_key', 'SMS_API_KEY'),
    'sms_pass'    => smsMaskedHint('sms_pass', 'SMS_PASS', false),
];

$pageTitle = 'ورود، ثبت‌نام و پیامک';
include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="card">
    <h2 class="card-title">تنظیمات ورود</h2>
    <p style="font-size:13px; color:var(--color-gray-500); margin-bottom:12px;">
        به‌صورت پیش‌فرض، بعد از اولین ورود موفق روی هر دستگاه، نام کاربری همان‌جا ذخیره می‌شود و دفعات بعد فقط رمز عبور پرسیده می‌شود. اینکه چه زمانی دوباره رمز پرسیده شود را هر کاربر خودش در «حساب کاربری من» تعیین می‌کند (پیش‌فرض: بدون مهلت). با فعال‌کردن این گزینه، ذخیره‌ی نام کاربری خاموش می‌شود و همه همیشه باید نام کاربری و رمز عبور را کامل وارد کنند.
    </p>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_login_setting">
        <label class="switch" style="margin-bottom:14px;">
            <input type="checkbox" name="require_full_login" value="1" <?= $requireFullLoginSetting ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span class="switch-text">الزام به وارد کردن نام کاربری هنگام ورود</span>
        </label>
        <button type="submit" class="btn btn-secondary btn-sm">ذخیره تنظیمات</button>
    </form>
</div>

<div class="card">
    <h2 class="card-title">ثبت‌نام و پشتیبانی</h2>
    <?php /* ⛔ ثبت‌نام پیش‌فرض خاموش است. یک دفترِ خصوصی نباید با یک
             به‌روزرسانی و بی‌خبر، به روی اینترنت باز شود — کسی که
             می‌خواهد اپ را بفروشد خودش روشنش می‌کند. */ ?>
    <p class="hint" style="margin-bottom:12px;">
        با روشن کردن این گزینه، هر کسی می‌تواند از صفحه‌ی ورود برای خودش
        حساب بسازد. تا وقتی خاموش است، آدرس ثبت‌نام اصلاً وجود ندارد و
        فقط شما می‌توانید کاربر بسازید.
    </p>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_signup_setting">
        <label class="switch" style="margin-bottom:14px;">
            <input type="checkbox" name="allow_signup" value="1" <?= $signupOn ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span class="switch-text">ثبت‌نام آزاد برای همه</span>
        </label>

        <div class="form-group">
            <label for="support_email">ایمیل پشتیبانی (اختیاری)</label>
            <input type="email" id="support_email" name="support_email" maxlength="190"
                   value="<?= h($supportEmail) ?>" placeholder="support@example.com"
                   autocapitalize="none" autocorrect="off" spellcheck="false">
            <p class="hint">در صفحه‌ی حریم خصوصی و پروفایل به کاربران نشان داده می‌شود.</p>
        </div>

        <button type="submit" class="btn btn-secondary btn-sm">ذخیره</button>
    </form>
</div>

<?php /* ⛔ این کارت **پیش از** کارت‌های اتصال می‌آید و دلیلش فقط
         ترتیبِ خواندن نیست: اسکریپتِ تعویضِ کارت به همین `select`
         بسته است و اگر بعد از آن بیاید، `getElementById` تهی
         برمی‌گرداند و تعویض بی‌صدا کار نمی‌کند. یک بار همین شد و
         در مرورگر دیده شد، نه با خواندنِ کد. */ ?>
<div class="card">
    <h2 class="card-title">ورود با پیامک</h2>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_sms_setting">
        <?php /* ⛔ ورود با پیامک: یک راهِ ورودِ دوم به هر حسابی است، پس
                 پیش‌فرض خاموش. و کلیدی که کار نمی‌کند از نبودنش بدتر
                 است — پس اگر پنلِ پیامک تنظیم نشده باشد همین‌جا صریح
                 گفته می‌شود، نه اینکه کاربر روشنش کند و هیچ کدی نرسد. */ ?>
        <hr style="border:none;border-top:1px solid var(--line);margin:16px 0;">
        <label class="switch" style="margin-bottom:8px;">
            <input type="checkbox" name="allow_sms_login" value="1" <?= $smsLoginOn ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span class="switch-text">ورود با کد پیامکی</span>
        </label>
        <label class="switch" style="margin-bottom:8px;">
            <input type="checkbox" name="allow_store_sms" value="1" <?= getSetting('store_sms_enabled', '0') === '1' ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span class="switch-text">پیامکِ فروشگاه‌ها به مشتریانشان (فاکتور، یادآوری، «آماده‌ی تحویل»)</span>
        </label>
        <p class="hint" style="margin-bottom:14px;">با همین پنل و به هزینه‌ی شما؛ هر فروشگاه هم باید خودش روشن کند و سقفِ ماهانه دارد.</p>
        <p class="hint" style="margin-bottom:14px;">
            <?php if (!$smsTableReady): ?>
                ⛔ جدولش هنوز ساخته نشده. اول <code>migration_sms_login</code> را اجرا کنید.
            <?php else: ?>
                کاربرانی که شماره موبایلشان را در پروفایل ثبت کرده‌اند می‌توانند
                بدون رمز، با کد پیامکی وارد شوند. هر پیامک هزینه دارد، پس سقفِ
                <?= toPersianDigits(SmsLogin::MAX_PER_PHONE) ?> درخواست در ساعت
                برای هر شماره گذاشته شده است.
                <?php if ($phoneCount === 0): ?>
                    <?php /* ⛔ بن‌بستِ مرغ و تخم‌مرغ، و کاملاً بی‌صدا: صفحه‌ی
                             ورود برای شماره‌ی ثبت‌نشده هم «کد فرستاده شد»
                             می‌گوید (تا فهرست کاربران لو نرود) و هیچ پیامکی
                             نمی‌فرستد. تنها جایی که این حقیقت گفتنی است
                             همین‌جاست. */ ?>
                    <br><strong>⛔ هنوز هیچ کاربری شماره ثبت نکرده است</strong>، پس
                    ورود با پیامک برای هیچ‌کس کار نمی‌کند — و صفحه‌ی ورود هم این را
                    نمی‌گوید، چون پیامش برای شماره‌ی ثبت‌شده و ثبت‌نشده عمداً یکی
                    است. هر کاربر (از جمله خودتان) باید یک بار به «حساب کاربری من»
                    برود و شماره‌اش را ذخیره کند. آن فیلد تا همین حالا پنهان بود و
                    فقط بعد از تنظیم شدنِ پنلِ پیامک ظاهر می‌شود.
                <?php elseif ($phoneCount > 0): ?>
                    <br><span class="saved-chip">
                        <?= toPersianDigits((string)$phoneCount) ?> کاربر شماره ثبت کرده‌اند
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </p>

        <?php /* ⛔ سه چیز که مالکِ نصب باید بداند و هیچ‌کدام از خودِ کلید
                 پیدا نیست. بدونشان، رفتارِ سیستم برای او یک جعبه‌ی سیاه
                 است: چرا فلان کاربر کد نمی‌گیرد، چرا فلانی می‌گیرد. */ ?>
        <p class="hint" style="margin-bottom:14px;">
            <strong>این کلید بالاترین حرف را می‌زند.</strong> خاموش کردنش ورودِ پیامکی را
            برای <em>همه</em> می‌بندد، حتی کاربرانی که نسخه‌ی کامل خریده‌اند —
            و صفحه‌ی «<code>sms-login.php</code>» دیگر اصلاً وجود نخواهد داشت.
            <br>
            <strong>ورودِ همیشگی با پیامک جزو نسخه‌ی کامل است</strong>
            (فقط وقتی «اجرای محدودیت طرح» روشن باشد). دو استثنا دارد و هر دو عمدی‌اند:
            ساختنِ حسابِ تازه با شماره همیشه رایگان است، و حسابی که هنوز
            <em>هیچ رمزی ندارد</em> همیشه می‌تواند با پیامک وارد شود — چون پیامک تنها
            درِ اوست و بستنش یعنی قفل شدنِ کاربر بیرونِ دفترِ خودش.
            <br>
            <strong>ثبت‌نام با شماره</strong> وقتی کار می‌کند که هم همین کلید روشن باشد
            هم «ثبت‌نام آزاد برای همه» در کارتِ بالا
            <?= (signupEnabled() && SmsLogin::available())
                ? '— <span class="saved-chip">هر دو روشن‌اند</span>'
                : '— <strong>الان هر دو روشن نیستند، پس ثبت‌نام با شماره کار نمی‌کند.</strong>' ?>
        </p>

        <?php /* ⛔ تنظیمِ پنل از همین‌جا، نه فقط از `config.php`.
                 کسی که می‌خواهد پنلش را وصل کند نباید مجبور باشد با SSH
                 یک فایل PHP را ویرایش کند — وگرنه این قابلیت عملاً وصل
                 نمی‌شود. ولی هر کلیدی که در `config.php` مقدار داشته
                 باشد همچنان **برنده** است. */ ?>
        <div class="form-group">
            <label for="sms_method">سرویس پیامک</label>
            <select id="sms_method" name="sms_method">
                <?php foreach (smsProviders() as $key => $prov): ?>
                    <option value="<?= h($key) ?>" <?= $smsMethod === $key ? 'selected' : '' ?>>
                        <?= h($prov['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint">
                <?php if ($smsMethod === ''): ?>
                    هنوز پنلی وصل نیست. هر کدام را که حساب دارید انتخاب کنید و
                    مشخصاتش را در کارتِ زیر بنویسید.
                <?php elseif ($smsMissing !== ''): ?>
                    ⛔ <?= h($smsMissing) ?>
                <?php elseif ($smsMethod === 'log'): ?>
                    ⛔ این حالت هیچ پیامکی نمی‌فرستد و کدها را در
                    <code>var/sms.log</code> می‌نویسد. فقط برای آزمایش.
                <?php elseif ($patternWarning !== ''): ?>
                    <?php /* ⛔ اینجا «آماده است» نوشتن دروغِ محض بود: همه‌ی
                             اعتبارنامه‌ها هست، پس `missingFor()` چیزی کم
                             نمی‌بیند — ولی بدونِ الگو، پیامکِ کدِ ورود
                             عملاً تحویل نمی‌شود و پنل هم «پذیرفته شد»
                             می‌گوید. تنها جایی که این خرابی دیده می‌شود
                             همین‌جاست. */ ?>
                    ⚠ <?= h($patternWarning) ?>
                <?php else: ?>
                    ✓ آماده است.
                <?php endif; ?>
            </p>
        </div>

        <?php /* ⚠ متنِ آزاد فقط وقتی استفاده می‌شود که الگو نداشته
                 باشید. با الگو، متن را خودِ پنل دارد و این فیلد
                 بی‌اثر است — پس برچسبش همین را می‌گوید. */ ?>
        <div class="form-group">
            <label for="sms_text">متن پیامک <span class="hint">(فقط حالت متن آزاد)</span></label>
            <input type="text" id="sms_text" name="sms_text" autocomplete="off" maxlength="200"
                   value="<?= h(smsSetting('sms_text', 'SMS_TEXT')) ?>"
                   placeholder="کد ورود شما: {code}">
            <p class="hint">
                در متن، <code>{code}</code> جای کد و <code>{ttl}</code> جای
                مهلت (دقیقه) می‌نشیند.
            </p>
        </div>

        <button type="submit" class="btn btn-secondary btn-block">ذخیرهٔ تنظیمات</button>
    </form>
</div>

<?php /* ⛔ کارتِ اتصال **مخصوصِ همان پنل** است، نه یک فهرستِ درهمِ همه‌ی
         فیلدها. با فهرستِ درهم، کاربرِ ملی‌پیامک نمی‌فهمد «شماره خط» به
         او ربط دارد یا نه، و کاربرِ کاوه‌نگار سه فیلدِ بی‌ربط می‌بیند.
         هر کارت فقط چیزهای همان پنل را دارد و با انتخابِ بالا عوض
         می‌شود. */ ?>
<div class="card sms-conn" data-for="melipayamak" <?= $smsMethod === 'melipayamak' ? '' : 'hidden' ?>>
    <h2 class="card-title">اتصال به ملی‌پیامک</h2>
    <?php /* ⚠ «چه چیزی الان ذخیره است» باید دیده شود. بدونِ آن، کاربر
             گزینه را عوض می‌کند، ذخیره نمی‌زند، تست می‌گیرد، و نتیجه‌ی
             مقدارِ قبلی را می‌بیند بی‌آنکه بفهمد چرا. */ ?>
    <p class="conn-state">
        وضعیتِ ذخیره‌شده:
        <strong><?= $meliMode === 'panel' ? 'وب‌سرویس (نام کاربری + رمز یا APIKey)' : 'کنسول جدید (کلید در آدرس)' ?></strong>
        <?php $__m = Sms::missingFor('melipayamak'); ?>
        · <?= $__m === '' ? '<span class="saved-chip">آماده</span>' : h($__m) ?>
    </p>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_sms_conn">

        <?php /* ⛔ نوعِ حساب باید صریح انتخاب شود، نه از خالی بودنِ نام
                 کاربری حدس زده شود. با حدس، مالکِ یک حسابِ قدیمی که فقط
                 کلید را پر کرده بود بی‌آنکه بداند به کنسول فرستاده
                 می‌شد و «کلید کنسول معتبر نیست» می‌گرفت — پیامی که درست
                 است و هیچ نمی‌گوید کدام مسیر رفته. */ ?>
        <div class="form-group">
            <label for="mp_mode">نوع حساب <span class="req">*</span></label>
            <select id="mp_mode" name="sms_meli_mode">
                <option value="panel" <?= $meliMode === 'panel' ? 'selected' : '' ?>>
                    وب‌سرویس — نام کاربری + رمز عبور یا APIKey
                </option>
                <option value="console" <?= $meliMode === 'console' ? 'selected' : '' ?>>
                    کنسول جدید — کلید وب‌سرویس در آدرس
                </option>
            </select>
            <p class="hint">
                <strong>در پنل ملی‌پیامک «APIKey جهت استفاده از وبسرویس» دارید؟
                گزینهٔ اول را بگیرید</strong> — خودِ ملی‌پیامک می‌گوید آن مقدار
                «به جای رمز عبور در پارامتر Password» فرستاده می‌شود، پس
                APIKey یک <em>رمز</em> است نه کلیدِ کنسول. نام کاربری هم همان
                است که با آن وارد پنل می‌شوید. گزینهٔ دوم فقط برای حساب‌هایی
                است که در کنسول جدید کلید می‌گیرند. در هر دو حالت «کد بادی
                الگو» لازم است.
            </p>
        </div>

        <div class="form-group mp-console" <?= $meliMode === 'console' ? '' : 'hidden' ?>>
            <label for="mp_key">کلید وب‌سرویس <span class="req">*</span> <?php if ($smsHas['sms_api_key']): ?><span class="saved-chip">ثبت شده</span><?php endif; ?></label>
            <input type="text" id="mp_key" name="sms_api_key" autocomplete="off" dir="ltr"
                   placeholder="<?= h($smsMask['sms_api_key'] ?: 'کلید وب‌سرویس را بچسبانید') ?>">
            <p class="hint">
                از بخش «تنظیمات» <strong>کنسول</strong> ملی‌پیامک.
                ⚠ «APIKey جهت استفاده از وبسرویس» که در پنل می‌بینید
                <strong>اینجا کار نمی‌کند</strong> — آن یک رمز است و جایش
                گزینهٔ «وب‌سرویس» است، نه این.
                <?php if ($smsKeyShape !== ''): ?>
                    <?php /* ⛔ وقتی پنل می‌گوید «کلید معتبر نیست»، سؤالِ
                             بعدی همیشه این است که آنچه ذخیره شده اصلاً
                             شبیهِ کلید هست یا نه. بدونِ این خط، تنها راهِ
                             فهمیدنش پاک کردن و دوباره چسباندن بود. */ ?>
                    <br>مقدارِ ذخیره‌شده: <span class="ltr-num"><?= h($smsKeyShape) ?></span>.
                    کلید وب‌سرویس کنسول معمولاً <span class="ltr-num">۳۶</span> نویسه و
                    به شکل UUID است (<span class="ltr-num">xxxxxxxx-xxxx-…</span>). اگر
                    آنچه ذخیره شده این شکل را ندارد، آن مقدار کلید وب‌سرویس نیست.
                <?php endif; ?>
            </p>
        </div>

        <div class="form-group mp-panel" <?= $meliMode === 'panel' ? '' : 'hidden' ?>>
            <label for="mp_user">نام کاربری پنل <span class="req">*</span></label>
            <input type="text" id="mp_user" name="sms_user" autocomplete="off" dir="ltr"
                   value="<?= h(smsSetting('sms_user', 'SMS_USER')) ?>" placeholder="">
            <p class="hint">همان نام کاربری‌ای که با آن وارد پنل ملی‌پیامک می‌شوید.</p>
        </div>

        <div class="form-group mp-panel" <?= $meliMode === 'panel' ? '' : 'hidden' ?>>
            <label for="mp_pass">رمز عبور پنل یا APIKey <span class="req">*</span> <?php if ($smsHas['sms_pass']): ?><span class="saved-chip">ثبت شده</span><?php endif; ?></label>
            <input type="text" id="mp_pass" name="sms_pass" autocomplete="off" dir="ltr"
                   placeholder="<?= h($smsMask['sms_pass'] ?: 'رمز عبور پنل') ?>">
            <p class="hint">
                <?php /* ⛔ همان جمله‌ی خودِ ملی‌پیامک زیرِ APIKey در پنل:
                         «کافی است مقدار فوق را به جای رمز عبور در پارامتر
                         Password به متدها ارسال نمایید». تا وقتی این
                         اینجا نوشته نشده بود، کاربر APIKey را در فیلدِ
                         کلیدِ کنسول می‌گذاشت و ۴۰۰ می‌گرفت. */ ?>
                رمزِ ورود به پنل، <strong>یا</strong> همان
                «APIKey جهت استفاده از وبسرویس» — ملی‌پیامک خودش می‌گوید آن
                مقدار به جای رمز عبور در پارامتر Password فرستاده می‌شود.
                خالی گذاشتنش یعنی «دست نزن»؛ برای پاک کردن یک خط تیره (-) بنویسید.
            </p>
        </div>

        <div class="form-group">
            <label for="mp_body">کد بادی الگو <span class="hint">(bodyId)</span></label>
            <input type="text" id="mp_body" name="sms_pattern" autocomplete="off" dir="ltr"
                   value="<?= h(smsSetting('sms_pattern', 'SMS_PATTERN')) ?>" placeholder="">
            <p class="hint">
                از بخش «خدمات پایه» کنسول، شمارهٔ الگوی تأییدشده. برای کد
                ورود عملاً لازم است.
            </p>
        </div>

        <div class="form-group">
            <label for="mp_line">شمارهٔ خط اختصاصی <span class="hint">(اختیاری)</span></label>
            <input type="text" id="mp_line" name="sms_sender" autocomplete="off" dir="ltr"
                   value="<?= h(smsSetting('sms_sender', 'SMS_SENDER')) ?>" placeholder="">
            <p class="hint">فقط اگر الگو ندارید و می‌خواهید متن آزاد از خط خودتان بفرستید.</p>
        </div>


        <?php /* ⛔ همین‌جا و داخلِ همین فرم، نه یک فرمِ جدا: تست باید با
                 همان مقداری برود که کاربر همین حالا انتخاب کرده. */ ?>
        <div class="form-group sms-test-inline">
            <label for="test_mp">ارسال آزمایشی (اختیاری)</label>
            <input type="tel" id="test_mp" name="test_phone" dir="ltr"
                   inputmode="numeric" class="phone-input" placeholder="09123456789">
            <p class="hint">اگر پر باشد، با ذخیره یک کد آزمایشی هم به همین شماره می‌رود و نتیجه‌اش (موفق یا خطای خامِ پنل) بالای صفحه نشان داده می‌شود. خالی بگذارید تا فقط ذخیره شود.</p>
        </div>

        <?php /* ⛔ **یک** دکمه، نه دو تا. کارِ دکمه از پر بودنِ شماره‌ی
                 آزمایشی معلوم می‌شود و برچسبش هم همان را می‌گوید.
                 بدونِ جاوااسکریپت هم درست کار می‌کند: تصمیم را سرور از
                 روی همان فیلد می‌گیرد. */ ?>
        <button type="submit" class="btn btn-primary btn-block js-sms-submit">ذخیرهٔ تنظیمات</button>
    </form>



</div>

<div class="card sms-conn" data-for="kavenegar" <?= $smsMethod === 'kavenegar' ? '' : 'hidden' ?>>
    <h2 class="card-title">اتصال به کاوه‌نگار</h2>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_sms_conn">
        <div class="form-group">
            <label for="kv_key">کلید API <span class="req">*</span> <?php if ($smsHas['sms_api_key']): ?><span class="saved-chip">ثبت شده</span><?php endif; ?></label>
            <input type="text" id="kv_key" name="sms_api_key" autocomplete="off" dir="ltr"
                   placeholder="<?= h($smsMask['sms_api_key'] ?: 'کلید پنل را بچسبانید') ?>">
        </div>
        <div class="form-group">
            <label for="kv_tpl">نام الگو <span class="hint">(verify/lookup)</span></label>
            <input type="text" id="kv_tpl" name="sms_pattern" autocomplete="off" dir="ltr"
                   value="<?= h(smsSetting('sms_pattern', 'SMS_PATTERN')) ?>">
            <p class="hint">الگوی تأییدشده در کاوه‌نگار. برای کد ورود عملاً لازم است.</p>
        </div>
        <div class="form-group">
            <label for="kv_line">شمارهٔ خط اختصاصی <span class="hint">(اختیاری)</span></label>
            <input type="text" id="kv_line" name="sms_sender" autocomplete="off" dir="ltr"
                   value="<?= h(smsSetting('sms_sender', 'SMS_SENDER')) ?>">
        </div>

        <?php /* ⛔ همین‌جا و داخلِ همین فرم، نه یک فرمِ جدا: تست باید با
                 همان مقداری برود که کاربر همین حالا انتخاب کرده. */ ?>
        <div class="form-group sms-test-inline">
            <label for="test_kv">ارسال آزمایشی (اختیاری)</label>
            <input type="tel" id="test_kv" name="test_phone" dir="ltr"
                   inputmode="numeric" class="phone-input" placeholder="09123456789">
            <p class="hint">اگر پر باشد، با ذخیره یک کد آزمایشی هم به همین شماره می‌رود و نتیجه‌اش (موفق یا خطای خامِ پنل) بالای صفحه نشان داده می‌شود. خالی بگذارید تا فقط ذخیره شود.</p>
        </div>

        <?php /* ⛔ **یک** دکمه، نه دو تا. کارِ دکمه از پر بودنِ شماره‌ی
                 آزمایشی معلوم می‌شود و برچسبش هم همان را می‌گوید.
                 بدونِ جاوااسکریپت هم درست کار می‌کند: تصمیم را سرور از
                 روی همان فیلد می‌گیرد. */ ?>
        <button type="submit" class="btn btn-primary btn-block js-sms-submit">ذخیرهٔ تنظیمات</button>
    </form>
</div>

<div class="card sms-conn" data-for="smsir" <?= $smsMethod === 'smsir' ? '' : 'hidden' ?>>
    <h2 class="card-title">اتصال به sms.ir</h2>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_sms_conn">
        <div class="form-group">
            <label for="ir_key">کلید API <span class="req">*</span> <?php if ($smsHas['sms_api_key']): ?><span class="saved-chip">ثبت شده</span><?php endif; ?></label>
            <input type="text" id="ir_key" name="sms_api_key" autocomplete="off" dir="ltr"
                   placeholder="<?= h($smsMask['sms_api_key'] ?: 'کلید پنل را بچسبانید') ?>">
        </div>
        <div class="form-group">
            <label for="ir_tpl">شناسهٔ الگو <span class="hint">(templateId)</span></label>
            <input type="text" id="ir_tpl" name="sms_pattern" autocomplete="off" dir="ltr"
                   value="<?= h(smsSetting('sms_pattern', 'SMS_PATTERN')) ?>">
            <p class="hint">⚠ نام پارامتر داخل الگو باید <code>CODE</code> باشد.</p>
        </div>
        <div class="form-group">
            <label for="ir_line">شمارهٔ خط <span class="req">*</span></label>
            <input type="text" id="ir_line" name="sms_sender" autocomplete="off" dir="ltr"
                   value="<?= h(smsSetting('sms_sender', 'SMS_SENDER')) ?>">
        </div>
        <?php /* ⛔ همین‌جا و داخلِ همین فرم، نه یک فرمِ جدا: تست باید با
                 همان مقداری برود که کاربر همین حالا انتخاب کرده. */ ?>
        <div class="form-group sms-test-inline">
            <label for="test_ir">ارسال آزمایشی (اختیاری)</label>
            <input type="tel" id="test_ir" name="test_phone" dir="ltr"
                   inputmode="numeric" class="phone-input" placeholder="09123456789">
            <p class="hint">اگر پر باشد، با ذخیره یک کد آزمایشی هم به همین شماره می‌رود و نتیجه‌اش (موفق یا خطای خامِ پنل) بالای صفحه نشان داده می‌شود. خالی بگذارید تا فقط ذخیره شود.</p>
        </div>

        <?php /* ⛔ **یک** دکمه، نه دو تا. کارِ دکمه از پر بودنِ شماره‌ی
                 آزمایشی معلوم می‌شود و برچسبش هم همان را می‌گوید.
                 بدونِ جاوااسکریپت هم درست کار می‌کند: تصمیم را سرور از
                 روی همان فیلد می‌گیرد. */ ?>
        <button type="submit" class="btn btn-primary btn-block js-sms-submit">ذخیرهٔ تنظیمات</button>
    </form>
</div>

<script>
/* کارتِ اتصال با انتخابِ سرویس عوض می‌شود — بی‌آنکه صفحه دوباره بارگذاری
   شود. اگر جاوااسکریپت نرسد، کارتِ سرویسِ ذخیره‌شده از سمت سرور باز است،
   پس فرم همچنان کار می‌کند. */
(function () {
    var sel = document.getElementById('sms_method');
    if (!sel) { return; }
    sel.addEventListener('change', function () {
        document.querySelectorAll('.sms-conn').forEach(function (c) {
            c.hidden = c.getAttribute('data-for') !== sel.value;
        });
    });
})();

/* ⛔ برچسبِ دکمه باید بگوید همین حالا چه اتفاقی می‌افتد. با برچسبِ
   ثابت، کاربر شماره را پر می‌کرد، «ذخیره» می‌زد، و نمی‌دانست پیامک
   رفت یا نه. */
(function () {
    document.querySelectorAll('.sms-conn form').forEach(function (f) {
        var phone = f.querySelector('input[name="test_phone"]');
        var btn   = f.querySelector('.js-sms-submit');
        if (!phone || !btn) { return; }
        function sync() {
            btn.textContent = phone.value.trim() === ''
                ? 'ذخیرهٔ تنظیمات'
                : 'ذخیره و ارسال آزمایشی';
        }
        phone.addEventListener('input', sync);
        sync();
    });
})();

/* نوعِ حسابِ ملی‌پیامک: فیلدهای هر روش فقط در همان روش دیده شوند.
   بدون این، کاربر هر چهار فیلد را می‌بیند و نمی‌داند کدامش به او ربط
   دارد — همان چیزی که «رمز را در فیلدِ کلید بگذارید» را ساخته بود. */
(function () {
    var m = document.getElementById('mp_mode');
    if (!m) { return; }
    function sync() {
        document.querySelectorAll('.mp-console').forEach(function (e) { e.hidden = m.value !== 'console'; });
        document.querySelectorAll('.mp-panel').forEach(function (e) { e.hidden = m.value !== 'panel'; });
    }
    m.addEventListener('change', sync);
    sync();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
