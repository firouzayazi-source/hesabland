#!/usr/bin/env bash
#
# tune.sh — تنظیم pool پی‌اچ‌پیِ «حساب لند» روی نصبِ موجود
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط این فایل را می‌نویسد:
#     /etc/php/<نسخه>/fpm/pool.d/hesab.conf
# و فقط این سرویس را reload می‌کند (نه stop، نه restart):
#     php<نسخه>-fpm
# به pool های دیگر، php.ini سراسری، nginx، و سرویس ربات‌ها دست نمی‌زند.
# ───────────────────────────────────────────────────────────────────
#
# چرا لازم شد:
#
# نسخه‌ی اولِ pool با «pm = ondemand» و «max_children = 5» نوشته شده بود.
# دو مشکل داشت:
#
#   ۱. پروسه‌ها بعد از ۳۰ ثانیه بی‌کاری کشته می‌شدند. اولین کلیک بعد از
#      یک مکث باید منتظر ساخته‌شدن پروسه می‌ماند.
#   ۲. هر بارگذاری صفحه چند درخواستِ PHP هم‌زمان داشت (HTML به‌علاوه‌ی
#      CSS و JS که آن موقع از یک اسکریپت PHP می‌آمدند). پنج تا زود پر
#      می‌شد و بقیه در صف می‌ماندند — کاربر می‌دید سایت قفل کرد.
#
# سمت اپ هم درست شد: فایل‌های CSS/JS مستقیم از nginx می‌آیند و اصلاً
# پروسه‌ی PHP نمی‌گیرند.
#
# اجرا:
#     bash deploy/tune.sh            # فقط نشان می‌دهد چه چیزی عوض می‌شود
#     bash deploy/tune.sh --apply    # واقعاً اعمال می‌کند

set -euo pipefail

SITE_NAME="${SITE_NAME:-hesab}"
APP_DIR="${APP_DIR:-/opt/hesab/app}"
APP_USER="${APP_USER:-hesab}"
APPLY=0
[[ "${1:-}" == "--apply" ]] && APPLY=1

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '  %s\n' "$1"; }
step()  { printf '\n\033[1m%s\033[0m\n' "$1"; }

if [[ "$(id -u)" != "0" ]]; then
    red "⛔ این اسکریپت باید با sudo اجرا شود."
    exit 1
fi

# ---------- پیدا کردن نسخه‌ی PHP از روی خود فایل pool ----------
# منطقِ کشف در config-lib.sh است تا hesabland هم همان را به کار ببرد
# (پیش از این آنجا «اولین php-fpmِ فعال» reload می‌شد، نه مالِ این اپ).
# shellcheck source=config-lib.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/config-lib.sh"
POOL_FILE="$(fpm_pool_file "$SITE_NAME")"

if [[ -z "$POOL_FILE" ]]; then
    red "⛔ فایل pool پیدا نشد: /etc/php/*/fpm/pool.d/${SITE_NAME}.conf"
    info "یعنی این سرور با deploy/vps-setup.sh راه‌اندازی نشده. چیزی تغییر نکرد."
    exit 1
fi

PHP_VER="$(fpm_version_of "$POOL_FILE")"
FPM_SERVICE="php${PHP_VER}-fpm"

step "وضعیت فعلی"
info "فایل pool: $POOL_FILE"
info "سرویس:     $FPM_SERVICE"
grep -E '^\s*pm(\.|\s*=)' "$POOL_FILE" | sed 's/^/    /' || info "(هیچ تنظیم pm ای پیدا نشد)"

# ---------- نوشتن تنظیم تازه ----------
# فقط خطوط pm.* عوض می‌شوند؛ بقیه‌ی فایل (open_basedir، session.save_path،
# disable_functions و ...) دست‌نخورده می‌ماند.
NEW_PM=$(cat <<'PM'
pm = dynamic
pm.max_children = 12
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500
PM
)

step "تنظیم تازه"
printf '%s\n' "$NEW_PM" | sed 's/^/    /'
info ""
info "دو پروسه همیشه گرم می‌ماند (چند ده مگابایت) و سقف از ۵ به ۱۲ می‌رسد."

if [[ $APPLY -eq 0 ]]; then
    step "نمایشی بود"
    info "برای اعمال واقعی:  sudo bash deploy/tune.sh --apply"
    exit 0
fi

step "اعمال"

BACKUP="${POOL_FILE}.bak.$(date +%Y%m%d%H%M%S)"
cp -a "$POOL_FILE" "$BACKUP"
chmod 600 "$BACKUP"
info "نسخه‌ی پشتیبان: $BACKUP"

# خطوط pm.* قدیمی حذف و بلوک تازه جایگزین می‌شود
python3 - "$POOL_FILE" <<'PY'
import re, sys

path = sys.argv[1]
with open(path, encoding='utf-8') as fh:
    lines = fh.readlines()

new_block = [
    "pm = dynamic\n",
    "pm.max_children = 12\n",
    "pm.start_servers = 2\n",
    "pm.min_spare_servers = 1\n",
    "pm.max_spare_servers = 3\n",
    "pm.max_requests = 500\n",
]

out, placed = [], False
pm_line = re.compile(r'^\s*pm\s*(=|\.)')
for line in lines:
    if pm_line.match(line):
        if not placed:
            out.extend(new_block)
            placed = True
        continue
    out.append(line)

if not placed:
    out.extend(["\n"] + new_block)

with open(path, 'w', encoding='utf-8') as fh:
    fh.writelines(out)
PY

# ---------- تست پیکربندی پیش از reload ----------
if ! fpm_config_test "$PHP_VER"; then
    red "⛔ پیکربندی php-fpm ایراد دارد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$POOL_FILE"
    exit 1
fi
green "✓ پیکربندی php-fpm سالم است."

# reload و نه restart: درخواست‌های در جریان قطع نمی‌شوند و اگر سرویس
# دیگری هم روی همین php-fpm باشد، آسیبی نمی‌بیند.
if systemctl reload "$FPM_SERVICE"; then
    green "✓ $FPM_SERVICE با reload تنظیم تازه را گرفت."
else
    red "⛔ reload نشد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$POOL_FILE"
    systemctl reload "$FPM_SERVICE" || true
    exit 1
fi

# ---------- بررسی اینکه اپ هنوز جواب می‌دهد ----------
step "بررسی سلامت"
if [[ -r "$APP_DIR/config/config.php" ]]; then
    if sudo -u "$APP_USER" test -r "$APP_DIR/config/config.php"; then
        green "✓ کاربر $APP_USER هنوز config.php را می‌خواند."
    else
        red "⚠ کاربر $APP_USER نمی‌تواند config.php را بخواند — این ربطی به این اسکریپت ندارد ولی سایت ۵۰۰ می‌دهد."
    fi
fi

green "✅ انجام شد."
