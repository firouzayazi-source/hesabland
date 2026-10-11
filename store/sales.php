<?php
/**
 * فاکتورهای فروش — و برگشتی‌هایش (زبانه‌ی «برگشتی‌ها»).
 *
 * ⛔ رندرِ فهرست فقط در `BizDocView::docList()` است؛ این صفحه فقط سمت را
 *    می‌گوید، پس فهرستِ فروش و خرید دو نسخه ندارند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';

$userId = Biz::ownerId();

$pageTitle = 'فاکتورهای فروش';
require __DIR__ . '/../includes/biz_head.php';
BizDocView::docList($userId, 'sale');
require __DIR__ . '/../includes/biz_foot.php';
