#!/usr/bin/env bash
#
# deploy.sh — پوسته‌ی نازکِ `hesabland deploy`
#
#   cd /opt/hesab/app && sudo ./deploy.sh
#
# ⛔ چرا فقط یک پوسته و نه یک نسخه‌ی دوم: منطقِ استقرار (دسترسی‌ها،
#    دامِ var/sessions، دامِ config.php، reload) از این فایل به
#    `hesabland` منتقل شد. با دو نسخه، اولین اصلاحی که فقط به یکی
#    برسد یک خرابیِ بی‌صدا می‌سازد — دقیقاً همان دلیلی که
#    `includes/transactions.php` یکی است و وب و API هر دو از آن رد
#    می‌شوند.
#
# ⛔ و این فایل حذف نشد: «cd /opt/hesab/app && sudo ./deploy.sh» در
#    حافظه‌ی مالکِ نصب، در `DEPLOY.md` و در پیامِ خطای چند اسکریپتِ
#    دیگر نوشته شده. همان قاعده‌ی `upcoming.php`/`calendar.php` که
#    حذف نشدند بلکه هدایت می‌کنند.
#
# ⚠ کلِ بدنه داخلِ یک تابع است و فراخوانی‌اش آخرین خطِ فایل. دلیلش
#   ظاهری نیست: استقرار خودِ همین فایل را با `git reset --hard`
#   بازنویسی می‌کند، و bash اسکریپت را **تکه‌تکه** می‌خواند. با بدنه‌ی
#   تخت، بعد از بازنویسی از همان آفستِ قبلی در فایلِ **تازه** ادامه
#   می‌داد — یعنی وسطِ یک خط، یا پایانِ بی‌صدای اسکریپت. با این شکل،
#   bash پیش از اجرای هر چیزی کلِ فایل را خوانده است.

set -euo pipefail

main() {
    local here
    here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

    if [[ ! -f "$here/hesabland" ]]; then
        printf '\033[0;31m%s\033[0m\n' "hesabland پیدا نشد — اول یک بار git pull کنید."
        exit 1
    fi

    exec bash "$here/hesabland" deploy "$@"
}

main "$@"
