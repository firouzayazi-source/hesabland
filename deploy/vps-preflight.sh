#!/usr/bin/env bash
#
# vps-preflight.sh — گزارش وضعیت سرور، پیش از هر نصبی
#
# ⚠️ این اسکریپت هیچ چیزی را نصب، تغییر، حذف یا restart نمی‌کند.
#    فقط می‌خواند و گزارش می‌دهد. هیچ دستور نویسنده‌ای در آن نیست.
#
# هدف: قبل از راه‌اندازی «حساب لند» روی VPS مطمئن شویم هیچ‌کدام از
#      سرویس‌های موجود (از جمله ربات‌های تلگرام) با آن تداخل ندارند.
#
# اجرا:  bash deploy/vps-preflight.sh
#        (با sudo کامل‌تر است، ولی بدون آن هم کار می‌کند)

set -uo pipefail

APP_DIR="${APP_DIR:-/opt/hesab/app}"
DB_NAME="${DB_NAME:-hesab_db}"
SITE_NAME="${SITE_NAME:-hesab}"

bold() { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { printf '  \033[0;32m✓\033[0m %s\n' "$1"; }
warn() { printf '  \033[0;33m!\033[0m %s\n' "$1"; }
bad()  { printf '  \033[0;31m✗\033[0m %s\n' "$1"; }
note() { printf '    %s\n' "$1"; }

bold "سیستم"
note "$( (. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME") || uname -a )"
note "کرنل: $(uname -r)   معماری: $(uname -m)"
note "RAM: $(free -h 2>/dev/null | awk '/^Mem:/{print $2" کل، "$7" آزاد"}')"
note "دیسک /: $(df -h / 2>/dev/null | awk 'NR==2{print $4" آزاد از "$2}')"

bold "پورت‌های در حال گوش دادن"
if command -v ss >/dev/null 2>&1; then
    ss -lntp 2>/dev/null | awk 'NR>1{print "    "$4"  →  "$6}' | sed 's/users:(("\([^"]*\)".*/\1/' | sort -u
    echo
    for p in 80 443; do
        if ss -lnt 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$p\$"; then
            warn "پورت $p همین حالا اشغال است — ببینید چه چیزی روی آن است (بالا)."
            note "اگر یکی از ربات‌ها روی این پورت webhook دارد، نباید nginx را جوری تنظیم کنیم که آن را بگیرد."
        else
            ok "پورت $p آزاد است."
        fi
    done
else
    warn "دستور ss نصب نیست؛ بررسی پورت انجام نشد."
fi

bold "nginx"
if command -v nginx >/dev/null 2>&1; then
    ok "نصب است: $(nginx -v 2>&1)"
    if [[ -d /etc/nginx/sites-enabled ]]; then
        note "سایت‌های فعال فعلی (به هیچ‌کدام دست نمی‌زنیم):"
        for f in /etc/nginx/sites-enabled/*; do
            [[ -e "$f" ]] || continue
            note "  - $(basename "$f")  →  server_name: $(grep -hoP 'server_name\s+\K[^;]+' "$f" 2>/dev/null | tr '\n' ' ')"
        done
    fi
    if [[ -e "/etc/nginx/sites-available/$SITE_NAME" || -e "/etc/nginx/sites-enabled/$SITE_NAME" ]]; then
        warn "یک سایت به نام «$SITE_NAME» از قبل وجود دارد — نصب باید آن را بازبینی کند، نه بازنویسی."
    else
        ok "نام سایت «$SITE_NAME» آزاد است."
    fi
else
    warn "nginx نصب نیست. نصبش پکیج تازه اضافه می‌کند و به ربات‌ها کاری ندارد،"
    note "به شرطی که پورت ۸۰/۴۴۳ آزاد باشد (بالا را ببینید)."
fi

bold "PHP"
if command -v php >/dev/null 2>&1; then
    ok "PHP CLI: $(php -v 2>/dev/null | head -1)"
    note "افزونه‌های لازم:"
    for ext in pdo_mysql gd zlib mbstring; do
        if php -m 2>/dev/null | grep -qix "$ext"; then note "  ✓ $ext"; else note "  ✗ $ext  (نصب کنید)"; fi
    done
else
    warn "PHP نصب نیست — لازم است (نسخه ۸.۰ به بالا)."
fi
if compgen -G "/etc/php/*/fpm/pool.d/*.conf" >/dev/null 2>&1; then
    note "pool های php-fpm موجود:"
    for f in /etc/php/*/fpm/pool.d/*.conf; do note "  - $f"; done
    note "ما فقط یک pool تازه به نام hesab اضافه می‌کنیم و بقیه دست‌نخورده می‌مانند."
else
    note "هیچ pool ای برای php-fpm پیدا نشد (احتمالاً php-fpm نصب نیست)."
fi

bold "MySQL / MariaDB"
if command -v mysql >/dev/null 2>&1; then
    ok "کلاینت نصب است: $(mysql --version)"
    if sudo -n mysql -e 'SELECT 1' >/dev/null 2>&1; then
        note "دیتابیس‌های موجود (فقط نام — به محتوایشان کاری نداریم):"
        sudo mysql -N -e 'SHOW DATABASES;' 2>/dev/null | grep -vE '^(information_schema|performance_schema|mysql|sys)$' | sed 's/^/      - /'
        if sudo mysql -N -e 'SHOW DATABASES;' 2>/dev/null | grep -qx "$DB_NAME"; then
            warn "دیتابیس «$DB_NAME» از قبل هست — نصب نباید دوباره بسازدش."
        else
            ok "نام دیتابیس «$DB_NAME» آزاد است."
        fi
    else
        note "برای دیدن فهرست دیتابیس‌ها با sudo اجرا کنید."
    fi
else
    warn "MariaDB/MySQL نصب نیست — لازم است."
fi

bold "سرویس‌های systemd در حال اجرا"
if command -v systemctl >/dev/null 2>&1; then
    note "این‌ها را فقط برای اطلاع فهرست می‌کنیم؛ به هیچ‌کدام دست نمی‌زنیم:"
    systemctl list-units --type=service --state=running --no-legend --no-pager 2>/dev/null \
        | awk '{print "      - "$1}' | head -40
else
    warn "systemctl در دسترس نیست."
fi

bold "مسیر نصب"
if [[ -e "$APP_DIR" ]]; then
    warn "$APP_DIR از قبل وجود دارد:"
    ls -la "$APP_DIR" 2>/dev/null | head -10 | sed 's/^/      /'
else
    ok "$APP_DIR خالی است و آماده."
fi

bold "فایروال"
if command -v ufw >/dev/null 2>&1; then
    sudo -n ufw status 2>/dev/null | sed 's/^/      /' || note "برای دیدن وضعیت ufw با sudo اجرا کنید."
    note "قانون‌های موجود را تغییر نمی‌دهیم؛ فقط در صورت نیاز ۸۰/۴۴۳ اضافه می‌شود."
else
    note "ufw نصب نیست."
fi

printf '\n\033[1mخروجی بالا را بفرستید تا مرحله بعد دقیقاً برای همین سرور تنظیم شود.\033[0m\n\n'
