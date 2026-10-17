<?php
/**
 * برگشت از درگاهِ پرداخت (`StorePlan::finishGateway()`) — عمداً بی‌`requirePage()`.
 *
 * ⛔ نشستی که در درگاه منقضی شده نباید پرداختِ انجام‌شده را گم کند. امن است چون هیچ چیزی
 *    از آدرس باور نمی‌شود: ردیف با شناسه **و** authorityِ همان ردیف پیدا می‌شود، مبلغ از
 *    ردیفِ خودمان است و پرداخت فقط با تأییدِ سرور به سرور (`PayGateway::verify()`) و فقط
 *    یک بار ثبت می‌شود. بعد به صفحه‌ی اشتراک (که ورود می‌خواهد) برمی‌گردد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/store_plan.php';

Auth::initSession();
$back = Biz::url('subscription.php');
if (!StorePlan::ready() || getParam('p') === '') { header('Location: ' . $back); exit; }
$r = StorePlan::finishGateway((int)getParam('p'), (string)getParam('Authority'), (string)getParam('Status'));
redirectWithMessage($back, $r['ok'] ? 'success' : 'error', $r['message']);
