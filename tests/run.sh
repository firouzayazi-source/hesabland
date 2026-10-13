#!/usr/bin/env bash
#
# run.sh — اجرای همه‌ی تست‌ها
#
# بدون Composer و بدون هیچ پکیج خارجی. تنها نیاز: php، و برای تست
# هم‌خوانی تقویم، node. اگر node نباشد آن یک تست رد می‌شود نه شکست.
#
#     bash tests/run.sh          همه‌ی تست‌ها
#     bash tests/run.sh jalali   فقط تست‌هایی که نامشان jalali دارد
#
# کد خروج ۰ یعنی همه موفق. هر چیز دیگری یعنی شکست — برای CI مناسب است.

set -uo pipefail
cd "$(dirname "$0")/.."

# ⛔ هرگز روی سایتِ اصلی: تست‌ها کاربر و سند می‌سازند و پاک می‌کنند و تنظیماتِ سراسری
#    (`app_settings`: پنلِ پیامک، کلیدِ پیامکِ فروشگاه…) را عوض می‌کنند. جای اجرا پوشه‌ی
#    نسخه‌ی آزمایشی یا ماشینِ توسعه است، با دیتابیسِ خودش.
if [[ "$(pwd -P)" == "/opt/hesab/app" && "${HESAB_TESTS_ON_MAIN:-}" != "1" ]]; then
    printf '\033[0;31m⛔ تست‌ها روی پوشه‌ی سایتِ اصلی (/opt/hesab/app) اجرا نمی‌شوند — از /opt/hesab/staging اجرا کنید.\033[0m\n' >&2
    exit 1
fi

# ⛔ هیچ پیامک، ایمیل یا اعلانی بیرون نمی‌رود — جز به سرورِ ساختگیِ همین ماشین (`includes/outbound.php`).
#    متغیرِ محیطی به هر فرایندِ فرزند و `php -S` می‌رسد.
export HESAB_NO_SEND=1

# ⛔ هشدارهای «Deprecated»ِ هنگامِ اجرا (نه فقط کامپایل) از **همه‌ی** فرایندها — `php -S`،
#    کارگرهای فرزند — در یک فایل جمع می‌شوند و هر کدام شکست است. اسکن‌دایرکتوریِ پیش‌فرض
#    می‌ماند («:» در ابتدا)، فقط یک ini افزوده می‌شود. (انتقال به PHP 8.5، مهر ۱۴۰۵.)
DEPR_DIR=$(mktemp -d)
DEPR_LOG="$DEPR_DIR/deprecated.log"
printf 'error_reporting = E_ALL\nlog_errors = On\nerror_log = %s\n' "$DEPR_LOG" > "$DEPR_DIR/zz-hesab-tests.ini"
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$DEPR_DIR"
trap 'rm -rf "$DEPR_DIR"' EXIT

FILTER="${1:-}"
FAILED=()
BLOCKED=()
RAN=0

bold() { printf '\n\033[1m═══ %s\033[0m\n' "$1"; }

for t in tests/test_*.php; do
    name=$(basename "$t" .php)
    [[ -n "$FILTER" && "$name" != *"$FILTER"* ]] && continue
    bold "$name"
    RAN=$((RAN+1))
    php "$t"
    rc=$?
    # ⛔ کد ۲ یعنی «اجرا نشد» نه «شکست خورد» — دو چیزِ کاملاً متفاوت با دو
    #    راهِ حلِ متفاوت، و تا امروز هر دو **سبز** دیده می‌شدند (پایین‌تر).
    case "$rc" in
        0) ;;
        2) BLOCKED+=("$name") ;;
        *) FAILED+=("$name") ;;
    esac
done

if (( RAN == 0 )); then
    printf '\n\033[0;33mهیچ تستی با فیلتر «%s» پیدا نشد.\033[0m\n\n' "$FILTER"
    exit 1
fi

printf '\n\033[1m%s\033[0m\n' "$(printf '═%.0s' {1..62})"

if [[ -s "$DEPR_LOG" ]] && grep -q 'Deprecated' "$DEPR_LOG"; then
    printf '\033[0;31m❌ هشدارِ Deprecated هنگامِ اجرا (PHP %s):\033[0m\n' "$(php -r 'echo PHP_VERSION;')"
    grep 'Deprecated' "$DEPR_LOG" | sed -E 's/^\[[^]]*\] //' | sort | uniq -c | sort -rn | head -30
    echo
    FAILED+=("deprecations")
fi

if (( ${#FAILED[@]} > 0 )); then
    printf '\033[0;31m❌ %d مجموعه از %d شکست خورد:\033[0m\n' "${#FAILED[@]}" "$RAN"
    printf '   • %s\n' "${FAILED[@]}"
    echo
    exit 1
fi

# ⛔ «اجرا نشد» هم یک شکست است. یک بار MariaDB خوابیده بود و همین اسکریپت
#    «✅ هر ۴۲ مجموعه تست موفق بود» چاپ کرد، در حالی که حدود ۲۵ مجموعه
#    اصلاً به دیتابیس نرسیده بودند. اگر اینجا صفر برگردد، همان push ای
#    که قرار بود این اسکریپت جلویش را بگیرد بی‌مانع انجام می‌شود.
if (( ${#BLOCKED[@]} > 0 )); then
    printf '\033[0;31m⛔ %d مجموعه از %d اصلاً اجرا نشد (زیرساخت لازم نبود):\033[0m\n' \
        "${#BLOCKED[@]}" "$RAN"
    printf '   • %s\n' "${BLOCKED[@]}"
    printf '\n   \033[0;33mعلتِ رایج: سرویس دیتابیس بالا نیست، یا config/config.php ساخته نشده.\033[0m\n'
    printf '   \033[0;90mاین شکست نیست ولی تأیید هم نیست — هیچ‌کدام از این مجموعه‌ها چیزی را نسنجیدند.\033[0m\n\n'
    exit 1
fi

printf '\033[0;32m✅ هر %d مجموعه تست موفق بود.\033[0m\n\n' "$RAN"
exit 0
