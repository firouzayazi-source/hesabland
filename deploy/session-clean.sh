#!/usr/bin/env bash
#
# session-clean.sh — پاک کردن فایل‌های نشستِ منقضی
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط داخل /opt/hesab/app/var/sessions را می‌خواند و پاک می‌کند.
# می‌نویسد فقط در:
#     /etc/cron.d/hesab-sessions   ← فقط با --install-cron
# به نشست‌های PHP سرویس‌های دیگر (/var/lib/php/sessions یا هر pool
# دیگری) دست نمی‌زند و اصلاً سراغشان نمی‌رود.
# ───────────────────────────────────────────────────────────────────
#
# ⚠ چرا این اسکریپت لازم است — سه چیز دست به دست هم داده‌اند:
#
#   ۱. `session.save_path` این pool داخل خودِ پروژه است (hesab.conf)،
#      پس کرونِ `sessionclean` دبیان — که فقط مسیرِ داخل php.ini را
#      می‌بیند — هرگز سراغش نمی‌آید.
#   ۲. روی اوبونتو `session.gc_probability = 0` است، یعنی خودِ PHP هم
#      هیچ‌وقت جمعشان نمی‌کند.
#   ۳. `Auth::initSession()` مقدار `gc_maxlifetime` را روی یک ماه
#      می‌گذارد تا نشستِ کاربری که مهلت بلند انتخاب کرده زودتر نمیرد.
#
# نتیجه: پوشه‌ی نشست **هرگز پاک نمی‌شود**. با یک کاربر نامرئی است؛ با
# صدها کاربر، ده‌ها هزار فایل در یک پوشه جمع می‌شود و آن‌وقت خودِ
# باز کردن پوشه کند می‌شود، اینود تمام می‌شود، یا دیسک پر می‌شود.
# خرابی‌اش هم بی‌صداست: یک روز سایت کند می‌شود و کسی ربطش را پیدا
# نمی‌کند.
#
# ⚠ مهلت پاک‌سازی عمداً از مهلتِ خودِ اپ **بلندتر** است. اپ با
# `session_minutes` تصمیم می‌گیرد چه کسی هنوز وارد است؛ این اسکریپت
# فقط آشغال را می‌برد. اگر کوتاه‌تر بود، کاربرِ واردی را بیرون
# می‌انداخت — یعنی همان چیزی که هیچ‌کس ربطش را به یک کرون پیدا نمی‌کند.
#
# اجرا:
#     bash deploy/session-clean.sh                 # نمایشی — فقط گزارش
#     bash deploy/session-clean.sh --apply         # واقعاً پاک کن
#     sudo bash deploy/session-clean.sh --install-cron
#     bash deploy/session-clean.sh --status        # فقط آمار

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
SESSION_DIR="${SESSION_DIR:-$APP_DIR/var/sessions}"

# ⚠ دو مهلتِ جدا، و جدا بودنشان اصلِ ماجراست.
#
# **هر بازدید یک فایل نشست می‌سازد، حتی بدون ورود.** `login.php` هم
# `Auth::initSession()` می‌زند (برای توکن CSRF)، پس هر ربات، هر خزنده و
# هر بازدیدِ گذری یک فایل جا می‌گذارد. روی سرور واقعی این عدد با سه
# کاربر به **۴۳۱۰ فایل** رسیده بود — تقریباً همه‌شان بی‌کاربر.
#
# نسخه‌ی اول همه را ۳۵ روز نگه می‌داشت. آن استدلال («نشستِ کاربرِ فعال
# را پاک نکن») برای فایلی که اصلاً کاربری ندارد بی‌معنا بود و عملاً
# یعنی انبار کردنِ آشغال به مدت پنج هفته.
#
#   KEEP_DAYS  — نشستِ کاربرِ واردشده. باید از بلندترین مهلتِ محدودِ
#                Auth::SESSION_WINDOWS (یک ماه) بیشتر باشد.
#   ANON_DAYS  — نشستِ بی‌کاربر. فقط یک توکن CSRF است؛ فرمی که دو روز
#                باز مانده باشد عملاً وجود ندارد.
KEEP_DAYS="${KEEP_DAYS:-35}"
ANON_DAYS="${ANON_DAYS:-2}"

# نشانه‌ی «این نشست کاربرِ واردشده دارد» در قالبِ سریال‌سازی PHP.
# مثال محتوای فایل:  user_id|i:5;full_name|s:12:"...";
LOGGED_IN_MARK='user_id|i:'

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }
warn()  { printf '\033[0;33m%s\033[0m\n' "$1"; }

# ---------- نصب cron ----------
if [[ "${1:-}" == "--install-cron" ]]; then
    [[ $EUID -ne 0 ]] && { red "برای نصب cron باید با sudo اجرا شود."; exit 1; }
    cat > /etc/cron.d/hesab-sessions <<CRON
# پاک‌سازی نشست‌های منقضی حساب لند — فقط مربوط به همین پروژه
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
15 4 * * * root $APP_DIR/deploy/session-clean.sh --apply >/dev/null 2>&1
CRON
    chmod 644 /etc/cron.d/hesab-sessions
    green "زمان‌بندی شد: هر شب ساعت ۴:۱۵ بامداد."
    info "فایل: /etc/cron.d/hesab-sessions"
    info "برای لغو:  sudo rm /etc/cron.d/hesab-sessions"
    exit 0
fi

# ---------- بررسی پوشه ----------
if [[ ! -d "$SESSION_DIR" ]]; then
    red "پوشه‌ی نشست پیدا نشد: $SESSION_DIR"
    info "اگر مسیر نصب فرق دارد:  APP_DIR=/path/to/app bash $0"
    exit 1
fi

# ⛔ محافظِ آخر در برابر پاک کردنِ جای اشتباه.
#
# اگر روزی SESSION_DIR با متغیر محیطی به مسیر دیگری اشاره کند (اشتباه
# تایپی، یا کپی شدنِ این اسکریپت جای دیگر)، این شرط جلوی حذف را
# می‌گیرد. بدون آن، یک `SESSION_DIR=/` کافی بود تا فاجعه شود.
case "$SESSION_DIR" in
    */var/sessions) : ;;
    *)
        red "امتناع: مسیر باید به var/sessions ختم شود، ولی این است:"
        red "  $SESSION_DIR"
        exit 1
        ;;
esac

# ---------- فهرست نامزدهای حذف ----------
#
# دو گروه جدا سنجیده می‌شوند. `grep -l` روی فایل‌های کوچکِ نشست ارزان
# است و فقط روی فایل‌هایی اجرا می‌شود که از نظر زمانی نامزد شده‌اند.
list_anon_stale() {
    find "$SESSION_DIR" -maxdepth 1 -type f -name 'sess_*' -mtime "+$ANON_DAYS" -print0 2>/dev/null \
        | xargs -0 -r grep -LF -- "$LOGGED_IN_MARK" 2>/dev/null || true
}
list_user_stale() {
    find "$SESSION_DIR" -maxdepth 1 -type f -name 'sess_*' -mtime "+$KEEP_DAYS" -print0 2>/dev/null \
        | xargs -0 -r grep -lF -- "$LOGGED_IN_MARK" 2>/dev/null || true
}

count_lines() { [[ -z "$1" ]] && echo 0 || printf '%s\n' "$1" | wc -l; }

# ---------- آمار ----------
total=$(find "$SESSION_DIR" -maxdepth 1 -type f -name 'sess_*' 2>/dev/null | wc -l)
bytes=$(du -sk "$SESSION_DIR" 2>/dev/null | cut -f1)

anon_list=$(list_anon_stale)
user_list=$(list_user_stale)
anon_n=$(count_lines "$anon_list")
user_n=$(count_lines "$user_list")
stale=$(( anon_n + user_n ))

echo
info "پوشه‌ی نشست: $SESSION_DIR"
printf '  فایل‌های نشست                    %s\n' "$total"
printf '  حجم پوشه                        %s KB\n' "${bytes:-؟}"
printf '  بی‌کاربر و کهنه (> %s روز)        %s\n' "$ANON_DAYS" "$anon_n"
printf '  کاربرِ واردشده و کهنه (> %s روز)  %s\n' "$KEEP_DAYS" "$user_n"
echo

if [[ "${1:-}" == "--status" ]]; then
    exit 0
fi

if [[ "$stale" -eq 0 ]]; then
    green "چیزی برای پاک کردن نیست."
    exit 0
fi

if [[ "${1:-}" != "--apply" ]]; then
    warn "حالت نمایشی — چیزی پاک نشد."
    info "برای پاک کردن واقعی:  bash $0 --apply"
    exit 0
fi

# ⛔ محافظ در برابر عوض شدنِ قالبِ نشست.
#
# تشخیصِ «کاربر دارد» به رشته‌ی `user_id|i:` بند است — یعنی به قالبِ
# سریال‌سازیِ پیش‌فرضِ PHP. اگر روزی `session.serialize_handler` عوض
# شود (مثلاً به php_serialize)، آن رشته دیگر پیدا نمی‌شود و **همه‌ی**
# نشست‌ها «بی‌کاربر» به نظر می‌رسند — یعنی این اسکریپت هر کاربرِ
# واردشده را بعد از دو روز بیرون می‌اندازد، بی‌آنکه خطایی بدهد.
#
# پس اگر هیچ نشستی نشانه نداشت ولی پوشه پر است، دست نگه می‌دارد.
if [[ "$total" -gt 20 ]]; then
    marked=$(grep -rlF -- "$LOGGED_IN_MARK" "$SESSION_DIR" 2>/dev/null | wc -l)
    if [[ "$marked" -eq 0 ]]; then
        red "امتناع: در هیچ‌کدام از $total فایل نشست، نشانه‌ی کاربر پیدا نشد."
        red "احتمالاً قالب سریال‌سازی نشست عوض شده و تشخیص کار نمی‌کند."
        red "بدون این محافظ، همه‌ی کاربرانِ واردشده بیرون می‌افتادند."
        exit 1
    fi
fi

# ---------- پاک‌سازی ----------
[[ -n "$anon_list" ]] && printf '%s\n' "$anon_list" | tr '\n' '\0' | xargs -0 -r rm -f
[[ -n "$user_list" ]] && printf '%s\n' "$user_list" | tr '\n' '\0' | xargs -0 -r rm -f

after=$(find "$SESSION_DIR" -maxdepth 1 -type f -name 'sess_*' 2>/dev/null | wc -l)
green "پاک شد: $((total - after)) فایل   (باقی‌مانده: $after)"
info "  بی‌کاربر: $anon_n    کاربرِ واردشده: $user_n"

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/cron-beat.sh"
cron_beat sessions
