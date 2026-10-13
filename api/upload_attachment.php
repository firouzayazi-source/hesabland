<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$txId = (int)postParam('transaction_id');

$pdo = Database::getConnection();
$own = $pdo->prepare('SELECT id FROM transactions WHERE id = :id AND user_id = :u');
$own->execute(['id' => $txId, 'u' => $userId]);
if (!$own->fetch()) { jsonResponse(['success' => false, 'message' => 'تراکنش یافت نشد.'], 404); }

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['file']['error'] ?? -1;
    $msg = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
        ? 'حجم فایل بیش از حد مجاز سرور است.'
        : 'فایلی دریافت نشد.';
    jsonResponse(['success' => false, 'message' => $msg], 422);
}

$file = $_FILES['file'];

// حداکثر ۳ مگابایت — روی هاست اشتراکی محافظه‌کارانه
$maxBytes = 3 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    jsonResponse(['success' => false, 'message' => 'حجم فایل نباید بیشتر از ۳ مگابایت باشد.'], 422);
}

// تشخیص نوع واقعی فایل از محتوا (نه از پسوند که قابل جعل است)
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

$mime = null;
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    unset($finfo);   // ⛔ نه `finfo_close()` — در PHP 8.5 منسوخ
} elseif (function_exists('mime_content_type')) {
    $mime = mime_content_type($file['tmp_name']);
}

if ($mime === null || !isset($allowed[$mime])) {
    jsonResponse(['success' => false, 'message' => 'فقط تصویر (JPG, PNG, WEBP) یا PDF پذیرفته می‌شود.'], 422);
}

$ext = $allowed[$mime];

// پوشه‌ی آپلود بیرون از دسترس مستقیم وب نیست، پس با .htaccess محافظت می‌شود
$uploadDir = __DIR__ . '/../uploads';
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        jsonResponse(['success' => false, 'message' => 'پوشه‌ی آپلود ساخته نشد. دسترسی نوشتن را بررسی کنید.'], 500);
    }
}

// جلوگیری از اجرای هر فایلی در این پوشه
$guard = $uploadDir . '/.htaccess';
if (!is_file($guard)) {
    @file_put_contents($guard, "php_flag engine off\nOptions -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar|cgi|pl)$\">\n    Require all denied\n</FilesMatch>\n");
}

// نام فایل تصادفی — هرگز از نام ارسالی کاربر استفاده نمی‌کنیم
try {
    $safeName = 'a' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
} catch (Exception $e) {
    $safeName = 'a' . $userId . '_' . uniqid('', true) . '.' . $ext;
}
$target = $uploadDir . '/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    jsonResponse(['success' => false, 'message' => 'ذخیره فایل ناموفق بود.'], 500);
}
@chmod($target, 0644);

try {
    $stmt = $pdo->prepare('
        INSERT INTO attachments (user_id, transaction_id, file_name, original_name, mime_type, file_size)
        VALUES (:u, :tx, :fn, :on, :mt, :sz)
    ');
    $stmt->execute([
        'u' => $userId, 'tx' => $txId, 'fn' => $safeName,
        'on' => mb_substr($file['name'], 0, 255), 'mt' => $mime, 'sz' => (int)$file['size'],
    ]);
    jsonResponse(['success' => true, 'message' => 'پیوست ذخیره شد.']);
} catch (PDOException $e) {
    @unlink($target); // اگر ثبت در دیتابیس نشد، فایل یتیم نماند
    Log::error('api.upload_attachment', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی در ثبت رخ داد.'], 500);
}
