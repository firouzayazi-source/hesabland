<?php
/**
 * خروج از محیطِ فروشگاهی — همان `Auth::logout()`ِ مشترک، ولی برگشت به
 * صفحه‌ی ورودِ **فروشگاه**، نه ورودِ حساب لند (آدرس‌ها قاطی نشوند).
 */
require_once __DIR__ . '/../includes/auth.php';

Auth::initSession();
Auth::logout();

header('Location: ' . Biz::url('login.php'));
exit;
