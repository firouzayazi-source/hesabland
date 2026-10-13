<?php
/**
 * ⛔ تصویرِ شخصیِ کاربر — **تنها** جای ذخیره و حذفِ `users.avatar`.
 *
 * دو در به آن می‌رسند و هر دو از همین رد می‌شوند: پروفایلِ هستیکو
 * (`api/upload_avatar.php` / `api/delete_avatar.php`، JSON) و تنظیماتِ
 * فروشگاه (`store/settings.php`، فرمِ POST + ریدایرکت). با دو نسخه، اولین
 * سخت‌گیریِ تازه (نوع، اندازه، برش) فقط به یکی می‌رسید.
 *
 * ⛔ فایلِ کاربر هرگز همان‌طور ذخیره نمی‌شود: نوع از **محتوا**
 *    (`getimagesize`)، فهرستِ بسته‌ی JPG/PNG/WEBP، و تصویر با GD از نو
 *    کشیده می‌شود (برشِ مربعیِ مرکز، ۲۵۶ پیکسل، JPEG). دنباله‌ی چسبیده به
 *    فایل (`<?php`، `<script>`) با آن از بین می‌رود.
 * ⛔ نامِ فایل تصادفی است و `basename()` روی هر نامِ خوانده‌شده از دیتابیس.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

const AVATAR_MIMES   = ['image/jpeg', 'image/png', 'image/webp'];
const AVATAR_MAX_IN  = 8 * 1024 * 1024;   // ورودی؛ خروجی همیشه بندانگشتیِ کوچک است
const AVATAR_SIZE    = 256;               // برای نمایشِ ۹۶ پیکسلی روی صفحه‌ی رتینا کافی است
const AVATAR_MAX_PX  = 40_000_000;        // سقفِ ابعاد پیش از باز کردن — بمبِ حافظه

function avatarDir(): string
{
    return __DIR__ . '/../uploads/avatars';
}

/** آدرسِ تصویرِ یک نامِ ذخیره‌شده، فقط اگر فایلش واقعاً هست؛ وگرنه ''. */
function avatarUrl(?string $name): string
{
    $name = basename((string)$name);
    if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]+\.jpg$/', $name) || !is_file(avatarDir() . '/' . $name)) {
        return '';
    }
    return APP_BASE_PATH . '/uploads/avatars/' . $name;
}

/**
 * ذخیره‌ی تصویرِ تازه از یک `$_FILES[...]`.
 * @return array{ok:bool, message:string, code:int, name?:string, url?:string}
 */
function saveUserAvatar(int $userId, $file): array
{
    $fail = fn(string $m, int $c = 422) => ['ok' => false, 'message' => $m, 'code' => $c];
    $pdo  = Database::getConnection();

    // ستونِ avatar با migration_p4 می‌آید. همین اول، تا فایل بی‌جهت روی دیسک نیاید.
    if (!usersHaveColumn($pdo, 'avatar')) {
        return $fail('ستون تصویر هنوز در دیتابیس ساخته نشده. روی سرور اجرا کنید:  bash deploy/migrate.sh --apply', 500);
    }
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = is_array($file) ? (int)($file['error'] ?? -1) : -1;
        return $fail(($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE)
            ? 'حجم فایل بیش از حد مجاز سرور است.' : 'تصویری دریافت نشد.');
    }
    if ((int)$file['size'] > AVATAR_MAX_IN) {
        return $fail('حجم تصویر نباید بیشتر از ۸ مگابایت باشد.');
    }
    $info = @getimagesize((string)$file['tmp_name']);
    if ($info === false) {
        return $fail('فایل انتخابی تصویر معتبری نیست.');
    }
    [$srcW, $srcH] = [(int)$info[0], (int)$info[1]];
    $mime = (string)($info['mime'] ?? '');
    if (!in_array($mime, AVATAR_MIMES, true)) {
        return $fail('فقط JPG، PNG یا WEBP پذیرفته می‌شود.');
    }
    if ($srcW < 40 || $srcH < 40) {
        return $fail('تصویر بیش از حد کوچک است.');
    }
    if ($srcW * $srcH > AVATAR_MAX_PX) {
        return $fail('ابعادِ تصویر بیش از حد بزرگ است.');
    }

    $dir = avatarDir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return $fail('پوشه‌ی تصاویر ساخته نشد. دسترسی نوشتن را بررسی کنید.', 500);
    }
    // جلوگیری از اجرای هر کدی در پوشه‌ی آپلود
    $guard = __DIR__ . '/../uploads/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "php_flag engine off\nOptions -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar|cgi|pl)$\">\n    Require all denied\n</FilesMatch>\n");
    }

    try {
        $newName = 'u' . $userId . '_' . bin2hex(random_bytes(10)) . '.jpg';
    } catch (Exception $e) {
        $newName = 'u' . $userId . '_' . uniqid('', true) . '.jpg';
    }
    $newName = str_replace('.', '', substr($newName, 0, -4)) . '.jpg';   // uniqid ممکن است نقطه بدهد
    $target  = $dir . '/' . $newName;

    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
        switch ($mime) {
            case 'image/jpeg': $src = @imagecreatefromjpeg((string)$file['tmp_name']); break;
            case 'image/png':  $src = @imagecreatefrompng((string)$file['tmp_name']);  break;
            case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp((string)$file['tmp_name']) : false; break;
            default: $src = false;
        }
        if ($src === false) {
            return $fail('خواندن تصویر ممکن نشد. فرمت دیگری را امتحان کنید.');
        }
        // برشِ مربعی از مرکز، سپس کوچک‌سازی
        $side = min($srcW, $srcH);
        $sx = (int)(($srcW - $side) / 2);
        $sy = (int)(($srcH - $side) / 2);
        $dst = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
        imagefilledrectangle($dst, 0, 0, AVATAR_SIZE, AVATAR_SIZE, imagecolorallocate($dst, 255, 255, 255)); // پس‌زمینه برای PNGِ شفاف
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, AVATAR_SIZE, AVATAR_SIZE, $side, $side);
        $ok = imagejpeg($dst, $target, 82);
        unset($src, $dst);   // ⛔ نه `imagedestroy()` — در PHP 8.5 منسوخ
        if (!$ok) {
            return $fail('ذخیره تصویر ناموفق بود.', 500);
        }
    } else {
        // ⛔ بدونِ GD تصویر از نو کشیده نمی‌شود، پس فایلِ کاربر همان‌طور می‌نشست —
        //    فقط JPEGِ کوچک و فقط اگر پسوندِ ذخیره با نوعِ واقعی بخواند.
        if ($mime !== 'image/jpeg' || (int)$file['size'] > 400 * 1024) {
            return $fail('روی این سرور امکان کوچک‌سازی تصویر نیست. یک JPG کمتر از ۴۰۰ کیلوبایت انتخاب کنید.');
        }
        if (!@move_uploaded_file((string)$file['tmp_name'], $target) && !@copy((string)$file['tmp_name'], $target)) {
            return $fail('ذخیره تصویر ناموفق بود.', 500);
        }
    }
    @chmod($target, 0644);

    try {
        $old = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
        $old->execute(['id' => $userId]);
        $oldName = $old->fetchColumn();
        $pdo->prepare('UPDATE users SET avatar = :a WHERE id = :id')->execute(['a' => $newName, 'id' => $userId]);
        // تصویرِ قبلی پاک شود تا فایلِ یتیم نماند
        if (is_string($oldName) && $oldName !== '') {
            $oldPath = $dir . '/' . basename($oldName);
            if (is_file($oldPath)) { @unlink($oldPath); }
        }
    } catch (PDOException $e) {
        @unlink($target);
        Log::error('avatar.save', $e);
        return $fail('ذخیره در دیتابیس ناموفق بود.', 500);
    }
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION['avatar'] = $newName; }
    return ['ok' => true, 'message' => 'تصویر پروفایل بروزرسانی شد.', 'code' => 200,
            'name' => $newName, 'url' => APP_BASE_PATH . '/uploads/avatars/' . $newName];
}

/** @return array{ok:bool, message:string, code:int} */
function deleteUserAvatar(int $userId): array
{
    $pdo = Database::getConnection();
    try {
        $st = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
        $st->execute(['id' => $userId]);
        $name = $st->fetchColumn();
        $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = :id')->execute(['id' => $userId]);
        if (is_string($name) && $name !== '') {
            $path = avatarDir() . '/' . basename($name);
            if (is_file($path)) { @unlink($path); }
        }
    } catch (PDOException $e) {
        Log::error('avatar.delete', $e);
        return ['ok' => false, 'message' => 'خطایی رخ داد.', 'code' => 500];
    }
    if (session_status() === PHP_SESSION_ACTIVE) { unset($_SESSION['avatar']); }
    return ['ok' => true, 'message' => 'تصویر حذف شد.', 'code' => 200];
}
