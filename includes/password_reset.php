<?php
/**
 * منطق بازیابی رمز — جدا از صفحه‌ها نگه داشته شده تا بشود تستش کرد.
 *
 * الگوی توکن: selector + validator، همان چیزی که Auth برای «دستگاه مورد
 * اعتماد» استفاده می‌کند.
 *   selector  در لینک می‌آید و برای پیدا کردن سطر است (ایندکس یکتا).
 *   validator در لینک می‌آید ولی در دیتابیس فقط هشش ذخیره می‌شود.
 * فایده: کسی که دیتابیس را بخواند نمی‌تواند لینک معتبر بسازد. و چون
 * جست‌وجو با selector است، مقایسه‌ی validator با hash_equals انجام
 * می‌شود و به زمان پاسخ چیزی درز نمی‌کند.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/signup.php';

class PasswordReset
{
    /** اعتبار لینک */
    public const TTL_MINUTES = 60;

    /** حداکثر درخواست در بازه، برای هر کاربر و هر IP */
    public const MAX_PER_USER = 3;
    public const MAX_PER_IP   = 10;
    public const WINDOW_MIN   = 60;

    /**
     * درخواست بازیابی.
     *
     * عمداً همیشه true برمی‌گرداند مگر خطای فنی: نباید معلوم شود که
     * فلان نام کاربری یا ایمیل در سیستم هست یا نه. پیام صفحه در هر
     * حالت یکی است.
     *
     * @return array{sent:bool, error:string}  sent فقط برای لاگ داخلی
     */
    public static function request(string $identifier, string $ip, string $baseUrl): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') { return ['sent' => false, 'error' => 'empty']; }

        $pdo = Database::getConnection();

        // نام کاربری یا ایمیل — هر دو پذیرفته می‌شود
        $st = $pdo->prepare(
            'SELECT id, username, full_name, email, is_active
             FROM users WHERE username = :id OR (email IS NOT NULL AND email = :id2) LIMIT 1'
        );
        $st->execute(['id' => $identifier, 'id2' => $identifier]);
        $user = $st->fetch();

        if (!$user || !$user['is_active'] || empty($user['email'])) {
            // کاربر نیست، غیرفعال است، یا ایمیل ندارد — بی‌سروصدا رد شو
            return ['sent' => false, 'error' => 'no-target'];
        }

        if (!self::withinRateLimit((int)$user['id'], $ip)) {
            return ['sent' => false, 'error' => 'rate-limited'];
        }

        $selector  = bin2hex(random_bytes(12));            // ۲۴ کاراکتر
        $validator = bin2hex(random_bytes(32));            // ۶۴ کاراکتر

        // ⚠️ زمان انقضا را دیتابیس می‌سازد، نه PHP.
        // دلیلش یک باگ واقعی است که در تست پیدا شد: Auth::initSession()
        // منطقه‌ی زمانی PHP را روی APP_TIMEZONE می‌گذارد، ولی used_at را
        // MySQL با NOW() می‌نوشت و مقایسه‌ی انقضا با strtotime انجام
        // می‌شد که رشته را در منطقه‌ی زمانی PHP تفسیر می‌کند. سه ساعت
        // مختلف در یک جریان، و نتیجه‌اش این بود که لینک بلافاصله
        // «منقضی» اعلام می‌شد. حالا همه‌ی زمان‌ها از یک ساعت می‌آیند.
        $ttl = (int)self::TTL_MINUTES;
        $ins = $pdo->prepare(
            "INSERT INTO password_resets (user_id, selector, validator_hash, expires_at, request_ip)
             VALUES (:uid, :sel, :hash, DATE_ADD(NOW(), INTERVAL $ttl MINUTE), :ip)"
        );
        $ins->execute([
            'uid'  => $user['id'],
            'sel'  => $selector,
            'hash' => hash('sha256', $validator),
            'ip'   => substr($ip, 0, 45),
        ]);

        $link = rtrim($baseUrl, '/') . '/reset-password.php?s=' . $selector . '&t=' . $validator;
        $sent = self::sendEmail($user, $link);

        return ['sent' => $sent, 'error' => $sent ? '' : Mailer::$lastError];
    }

    /**
     * بررسی توکن. اگر معتبر بود، ردیف کاربر را برمی‌گرداند.
     *
     * @return array{ok:bool, user:?array, reason:string}
     */
    public static function verify(string $selector, string $validator): array
    {
        if ($selector === '' || $validator === ''
            || !ctype_xdigit($selector) || !ctype_xdigit($validator)) {
            return ['ok' => false, 'user' => null, 'reason' => 'malformed'];
        }

        $pdo = Database::getConnection();
        // انقضا را خود دیتابیس ارزیابی می‌کند تا با ساعتی که expires_at را
        // ساخته یکی باشد؛ مقایسه در PHP با strtotime همان باگ منطقه‌ی
        // زمانی را برمی‌گرداند.
        $st  = $pdo->prepare(
            'SELECT r.id, r.user_id, r.validator_hash, r.expires_at, r.used_at,
                    (r.expires_at <= NOW()) AS is_expired,
                    u.username, u.full_name, u.email, u.is_active
             FROM password_resets r
             JOIN users u ON u.id = r.user_id
             WHERE r.selector = :sel LIMIT 1'
        );
        $st->execute(['sel' => $selector]);
        $row = $st->fetch();

        if (!$row) { return ['ok' => false, 'user' => null, 'reason' => 'not-found']; }
        if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
            return ['ok' => false, 'user' => null, 'reason' => 'mismatch'];
        }
        if ($row['used_at'] !== null) {
            return ['ok' => false, 'user' => null, 'reason' => 'used'];
        }
        if ((int)$row['is_expired'] === 1) {
            return ['ok' => false, 'user' => null, 'reason' => 'expired'];
        }
        if (!$row['is_active']) {
            return ['ok' => false, 'user' => null, 'reason' => 'inactive'];
        }

        return ['ok' => true, 'user' => $row, 'reason' => ''];
    }

    /**
     * اعمال رمز تازه. توکن مصرف می‌شود و همه‌ی توکن‌های دیگر همان کاربر
     * و همه‌ی دستگاه‌های مورد اعتمادش هم باطل می‌شوند.
     */
    public static function complete(string $selector, string $validator, string $newPassword): array
    {
        $check = self::verify($selector, $validator);
        if (!$check['ok']) { return ['ok' => false, 'reason' => $check['reason']]; }

        // ⚠ رشته‌ی خالی را `passwordRuleError()` معتبر می‌شمارد («رمز
        //   نگذاشتن» یک انتخاب است)، ولی اینجا نیست: بازیابی باید رمزی
        //   بگذارد، وگرنه حسابِ کاربر بی‌رمز می‌شد بی‌آنکه خواسته باشد.
        if ($newPassword === '' || passwordRuleError($newPassword) !== '') {
            return ['ok' => false, 'reason' => 'weak'];
        }

        $pdo = Database::getConnection();
        $uid = (int)$check['user']['user_id'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                ->execute(['h' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $uid]);

            // این توکن مصرف شد
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => $check['user']['id']]);

            // بقیه‌ی توکن‌های همین کاربر هم باطل — اگر چند بار درخواست
            // داده باشد، لینک‌های قدیمی نباید زنده بمانند
            $pdo->prepare(
                'UPDATE password_resets SET used_at = NOW()
                 WHERE user_id = :id AND used_at IS NULL'
            )->execute(['id' => $uid]);

            // ⛔ دستگاه‌های مورد اعتماد **و** توکن‌های اپ: کسی که رمز را
            //    بازیابی می‌کند ممکن است دلیلش دسترسی ناخواسته‌ی شخص
            //    دیگری باشد، و توکنِ API نه به رمز وابسته است نه به
            //    نشست — ۹۰ روز زنده می‌ماند و رمزِ تازه بیرونش نمی‌کند.
            //    این بدترین حالتِ ممکن است: کاربر تازه حسابش را پس
            //    گرفته و خیالش راحت شده.
            try {
                revokeAllAccessFor($uid);
            } catch (Throwable $e) {
                // جدول‌ها هنوز ساخته نشده‌اند — بازیابی رمز نباید بشکند
            }

            $pdo->commit();
            // ⚠ `$check['user']['id']` شناسه‌ی **ردیفِ** password_resets است، نه کاربر —
            //   ممیزی رخدادِ امنیتی را به کاربرِ دیگری نسبت می‌داد.
            Audit::log('auth.password_reset', 'user', $uid, [], null, $uid);
        } catch (Throwable $e) {
            $pdo->rollBack();
            Log::error('password_reset.complete_failed', $e);
            return ['ok' => false, 'reason' => 'db-error'];
        }

        // خبر «رمزت عوض شد» — بعد از commit، نه داخل تراکنش.
        //
        // چرا لازم است: تا اینجا کاربر فقط یک ایمیل گرفته («برای تغییر
        // رمز کلیک کن»). اگر آن درخواست را کس دیگری داده باشد و به لینک
        // دسترسی پیدا کند، صاحب حساب هیچ‌وقت نمی‌فهمد رمزش عوض شده. این
        // ایمیل تنها جایی است که چنین اتفاقی را لو می‌دهد.
        //
        // شکست ارسال نباید تغییر رمز را باطل کند — رمز عوض شده و کاربر
        // باید بتواند وارد شود؛ فقط در لاگ می‌نویسیم.
        try {
            self::sendChangedNotice($check['user']);
        } catch (Throwable $e) {
            Log::error('password_reset.notice_failed', $e);
        }

        return ['ok' => true, 'reason' => '', 'username' => $check['user']['username']];
    }

    /** پاک کردن توکن‌های منقضی — بی‌ضرر و ارزان */
    public static function purgeExpired(): int
    {
        try {
            $pdo = Database::getConnection();
            $st = $pdo->prepare('DELETE FROM password_resets WHERE expires_at < (NOW() - INTERVAL 7 DAY)');
            $st->execute();
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ---------------------------------------------------------------

    private static function withinRateLimit(int $userId, string $ip): bool
    {
        $pdo = Database::getConnection();
        $w   = self::WINDOW_MIN;

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM password_resets
             WHERE user_id = :uid AND created_at > (NOW() - INTERVAL $w MINUTE)"
        );
        $st->execute(['uid' => $userId]);
        if ((int)$st->fetchColumn() >= self::MAX_PER_USER) { return false; }

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM password_resets
             WHERE request_ip = :ip AND created_at > (NOW() - INTERVAL $w MINUTE)"
        );
        $st->execute(['ip' => substr($ip, 0, 45)]);
        return (int)$st->fetchColumn() < self::MAX_PER_IP;
    }

    private static function sendEmail(array $user, string $link): bool
    {
        $app  = defined('APP_NAME') ? APP_NAME : 'حساب لند';
        $name = $user['full_name'] !== '' ? $user['full_name'] : $user['username'];
        $mins = self::TTL_MINUTES;

        $html = '<div style="font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right;'
              . 'max-width:520px;margin:auto;color:#15171c;line-height:1.9">'
              . '<h2 style="margin:0 0 16px">بازیابی رمز عبور</h2>'
              . '<p>سلام ' . h($name) . '،</p>'
              . '<p>برای حساب <b>' . h($user['username']) . '</b> در «' . h($app)
              . '» درخواست تغییر رمز ثبت شده است.</p>'
              . '<p style="margin:24px 0">'
              . '<a href="' . h($link) . '" style="background:#15171c;color:#fff;'
              . 'padding:12px 22px;border-radius:8px;text-decoration:none;display:inline-block">'
              . 'تغییر رمز عبور</a></p>'
              . '<p style="color:#6f7580;font-size:13px">این لینک تا ' . toPersianDigits((string)$mins)
              . ' دقیقه معتبر است و فقط یک بار کار می‌کند.</p>'
              . '<p style="color:#6f7580;font-size:13px">اگر شما این درخواست را نداده‌اید، '
              . 'این ایمیل را نادیده بگیرید؛ رمز فعلی‌تان تغییری نمی‌کند.</p>'
              . '</div>';

        // نسخه‌ی متنی را صریح می‌دهیم تا آدرس لینک حتماً در آن باشد
        $text = "بازیابی رمز عبور\n\n"
              . "سلام {$name}،\n\n"
              . "برای حساب {$user['username']} در «{$app}» درخواست تغییر رمز ثبت شده است.\n\n"
              . "برای تغییر رمز این آدرس را باز کنید:\n{$link}\n\n"
              . "این لینک تا {$mins} دقیقه معتبر است و فقط یک بار کار می‌کند.\n"
              . "اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید.";

        return Mailer::send($user['email'], 'بازیابی رمز عبور — ' . $app, $html, $text);
    }

    /**
     * تأیید تغییر رمز — بعد از اینکه رمز واقعاً عوض شد فرستاده می‌شود.
     *
     * عمداً هیچ لینک و هیچ رمزی داخلش نیست. اگر این ایمیل به دست کسی
     * جز صاحب حساب بیفتد، نباید چیزی به او بدهد؛ فقط باید به صاحب حساب
     * خبر بدهد که اگر کار او نبوده، همین حالا اقدام کند.
     */
    private static function sendChangedNotice(array $user): bool
    {
        if (empty($user['email']) || !Mailer::isConfigured()) { return false; }

        $app  = defined('APP_NAME') ? APP_NAME : 'حساب لند';
        $name = ($user['full_name'] ?? '') !== '' ? $user['full_name'] : $user['username'];
        $when = jalaliWithWeekday(date('Y-m-d')) . '، ساعت ' . toPersianDigits(date('H:i'));
        $login = appBaseUrl() . '/login.php';

        $html = '<div style="font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right;'
              . 'max-width:520px;margin:auto;color:#15171c;line-height:1.9">'
              . '<h2 style="margin:0 0 16px">رمز عبور شما تغییر کرد</h2>'
              . '<p>سلام ' . h($name) . '،</p>'
              . '<p>رمز عبور حساب <b>' . h($user['username']) . '</b> در «' . h($app)
              . '» با موفقیت عوض شد.</p>'
              . '<p style="color:#6f7580;font-size:13px">زمان: ' . h($when) . '</p>'
              . '<p style="margin:24px 0">'
              . '<a href="' . h($login) . '" style="background:#15171c;color:#fff;'
              . 'padding:12px 22px;border-radius:8px;text-decoration:none;display:inline-block">'
              . 'ورود به حساب</a></p>'
              . '<p style="color:#6f7580;font-size:13px">همه‌ی دستگاه‌هایی که «مرا به خاطر بسپار» '
              . 'داشتند بیرون انداخته شدند و باید دوباره وارد شوند.</p>'
              . '<p style="color:#c33c3c;font-size:13px"><b>اگر شما این کار را نکرده‌اید</b>، '
              . 'یعنی کسی به ایمیل شما دسترسی داشته است. همین حالا رمز ایمیلتان را عوض کنید '
              . 'و به مدیر خبر بدهید.</p>'
              . '</div>';

        $text = "رمز عبور شما تغییر کرد\n\n"
              . "سلام {$name}،\n\n"
              . "رمز عبور حساب {$user['username']} در «{$app}» با موفقیت عوض شد.\n"
              . "زمان: {$when}\n\n"
              . "ورود: {$login}\n\n"
              . "همه‌ی دستگاه‌های «مرا به خاطر بسپار» بیرون انداخته شدند.\n\n"
              . "اگر شما این کار را نکرده‌اید، کسی به ایمیل شما دسترسی داشته است. "
              . "همین حالا رمز ایمیلتان را عوض کنید و به مدیر خبر بدهید.";

        return Mailer::send($user['email'], 'رمز عبور شما تغییر کرد — ' . $app, $html, $text);
    }
}
