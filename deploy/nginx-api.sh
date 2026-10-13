#!/usr/bin/env bash
#
# nginx-api.sh — افزودن آدرس تمیزِ api/v1 به سایتِ nginx روی نصبِ موجود
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط این فایل را می‌نویسد:
#     /etc/nginx/sites-available/hesab
# و فقط nginx را reload می‌کند (نه stop، نه restart) و فقط بعد از
# `nginx -t` موفق. به nginx.conf، سایت‌های دیگر، و سرویس ربات‌ها دست
# نمی‌زند. اگر تست پیکربندی رد شود، خودش فایل را برمی‌گرداند.
# ───────────────────────────────────────────────────────────────────
#
# چرا لازم است:
#
# api/v1 بدون این قاعده هم کاملاً کار می‌کند، ولی با آدرسِ
# `/api/v1/index.php/transactions`. این قاعده اجازه می‌دهد اپ همان را
# به شکل `/api/v1/transactions` صدا بزند.
#
# ⚠ پشتیبانی از هر دو شکل عمدی است و باید بماند: اگر روزی certbot یا
# کسی فایل سایت را بازنویسی کند، این قاعده از دست می‌رود — و اپ‌هایی که
# روی گوشی مردم نصب شده‌اند نباید با آن بخوابند. برای همین اپ باید
# آدرسِ index.php را بلد باشد و از آن استفاده کند اگر تمیزش جواب نداد.
#
# `deploy/vps-setup.sh` این قاعده را برای نصب‌های تازه دارد. این اسکریپت
# همان را روی سروری که از قبل راه‌اندازی شده اضافه می‌کند.
#
# اجرا:
#     sudo bash deploy/nginx-api.sh            # فقط نشان می‌دهد چه می‌شود
#     sudo bash deploy/nginx-api.sh --apply    # واقعاً اعمال می‌کند
#
# اجرای دوباره‌اش بی‌خطر است: اگر قاعده از قبل باشد، کاری نمی‌کند.

set -euo pipefail

SITE_NAME="${SITE_NAME:-hesab}"
SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/${SITE_NAME}}"
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

if [[ ! -f "$SITE_FILE" ]]; then
    red "⛔ فایل سایت پیدا نشد: $SITE_FILE"
    info "یعنی این سرور با deploy/vps-setup.sh راه‌اندازی نشده. چیزی تغییر نکرد."
    exit 1
fi

step "وضعیت فعلی"
info "فایل سایت: $SITE_FILE"

# سه حالت ممکن است:
#   ۱. قاعده‌ی درست هست            → کاری لازم نیست
#   ۲. قاعده‌ی معیوبِ ^~ هست       → باید ترمیم شود (نه اینکه رد شویم!)
#   ۳. هیچ قاعده‌ای نیست            → درج می‌شود
#
# ⚠ حالت ۲ عمداً جدا شمرده می‌شود. نسخه‌ی قبلیِ همین اسکریپت هر شکلی از
# قاعده را «از قبل هست» می‌دید و بی‌سروصدا بیرون می‌آمد — یعنی سروری که
# قاعده‌ی معیوب داشت و سورس PHP اش لو می‌رفت، با اجرای این اسکریپت هم
# درست نمی‌شد.
MODE=""
if grep -qE '^\s*location\s+\^~\s+/api/v1/' "$SITE_FILE"; then
    MODE="repair"
    red "⚠ قاعده‌ی معیوب پیدا شد: location ^~ /api/v1/"
    info "با ^~ ، nginx فایل PHP را به‌جای اجرا خام تحویل می‌دهد (سورس لو می‌رود)."
    info "این اسکریپت آن را به prefix ساده تبدیل می‌کند."
elif grep -qE '^\s*location\s+/api/v1/' "$SITE_FILE"; then
    green "✓ قاعده‌ی درستِ api/v1 از قبل هست — کاری لازم نیست."
    exit 0
else
    MODE="insert"
    info "قاعده‌ی /api/v1/ وجود ندارد."
fi

BLOCK='    # ---- api/v1 : آدرس تمیز برای اپ‌های موبایل ----
    # ⛔ هرگز ^~ : ارزیابی location های regex را متوقف می‌کند و فایل PHP
    # به‌جای اجرا، خام تحویل داده می‌شود (سورس لو می‌رود).
    location /api/v1/ {
        try_files $uri $uri/ /api/v1/index.php$is_args$args;
    }
'

if [[ "$MODE" == "repair" ]]; then
    step "چیزی که ترمیم می‌شود"
    info "فقط ^~ از همان خط برداشته می‌شود؛ بقیه‌ی فایل دست نمی‌خورد."
else
    step "چیزی که اضافه می‌شود"
    printf '%s' "$BLOCK" | sed 's/^/  /'
    info ""
    info "پیش از قاعده‌ی عمومیِ فایل‌های ثابت قرار می‌گیرد."
fi

if [[ $APPLY -eq 0 ]]; then
    step "نمایشی بود"
    info "برای اعمال واقعی:  sudo bash deploy/nginx-api.sh --apply"
    exit 0
fi

step "اعمال"

BACKUP="${SITE_FILE}.bak.$(date +%Y%m%d%H%M%S)"
cp -a "$SITE_FILE" "$BACKUP"
chmod 600 "$BACKUP"
info "نسخه‌ی پشتیبان: $BACKUP"

python3 - "$SITE_FILE" "$MODE" <<'PY'
import re, sys

path, mode = sys.argv[1], sys.argv[2]
with open(path, encoding='utf-8') as fh:
    text = fh.read()

# ترمیم: فقط ^~ را برمی‌داریم و بقیه‌ی فایل دست نمی‌خورد
if mode == 'repair':
    fixed = re.sub(r'(^[ \t]*location\s+)\^~\s+(/api/v1/)', r'\1\2', text, flags=re.M)
    if fixed == text:
        sys.stderr.write('قاعده‌ی معیوب پیدا شد ولی جایگزین نشد.\n')
        sys.exit(2)
    with open(path, 'w', encoding='utf-8') as fh:
        fh.write(fixed)
    sys.exit(0)

block = (
    '    # ---- api/v1 : آدرس تمیز برای اپ‌های موبایل ----\n'
    '    # هرگز ^~ : جلوی location های regex را می‌گیرد و سورس PHP لو می‌رود.\n'
    '    location /api/v1/ {\n'
    '        try_files $uri $uri/ /api/v1/index.php$is_args$args;\n'
    '    }\n\n'
)

# پیش از اولین location داخل بلوکِ server قرار می‌گیرد. اگر هیچ‌کدام از
# لنگرهای شناخته‌شده نبود، هیچ حدسی زده نمی‌شود — با خطا بیرون می‌آید تا
# فایل نیمه‌کاره نماند.
for pattern in (
    r'^[ \t]*location\s*=\s*/sw\.js',
    r'^[ \t]*location\s+~\*\s+\\?\.\(css\|js',
    r'^[ \t]*location\s+/\s*\{',
):
    m = re.search(pattern, text, re.M)
    if m:
        text = text[:m.start()] + block + text[m.start():]
        with open(path, 'w', encoding='utf-8') as fh:
            fh.write(text)
        sys.exit(0)

sys.stderr.write('جای مناسبی برای درج قاعده پیدا نشد.\n')
sys.exit(2)
PY

if ! nginx -t; then
    red "⛔ پیکربندی nginx ایراد دارد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    exit 1
fi
green "✓ پیکربندی nginx سالم است."

# reload و نه restart: سایت‌های دیگرِ همین nginx قطع نمی‌شوند.
if systemctl reload nginx; then
    green "✓ nginx با reload قاعده‌ی تازه را گرفت."
else
    red "⛔ reload نشد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t && systemctl reload nginx || true
    exit 1
fi

# ---------- سنجشِ واقعی، نه فقط «nginx -t موفق بود» ----------
#
# ⚠ این بخش از یک خرابیِ واقعی درآمد. نسخه‌ی اول این اسکریپت قاعده را با
# `^~` می‌نوشت. `nginx -t` سبز بود، reload موفق بود، و اسکریپت «✅ انجام
# شد» می‌گفت — در حالی که `^~` ارزیابیِ location های regex را متوقف
# می‌کند و `location ~ \.php$` هرگز اجرا نمی‌شد. یعنی nginx فایل PHP را
# به‌جای اجرا خام تحویل می‌داد: /api/v1/ping سورس index.php را برمی‌گرداند
# و API اصلاً کار نمی‌کرد. «پیکربندی معتبر است» با «درست کار می‌کند» یکی
# نیست، پس حالا خودِ اندپوینت صدا زده می‌شود.
step "سنجش"

DOMAIN="$(grep -m1 -oP '^\s*server_name\s+\K[^;]+' "$SITE_FILE" | tr ' ' '\n' | grep -v '^_$' | head -1 || true)"
# ⚠ با --resolve ، نه با -H "Host: ...".
#
# روی این سرور چند سایت روی همان nginx هستند. `curl -H "Host: x" \
# https://127.0.0.1` هیچ SNI ای نمی‌فرستد، پس nginx بلوکِ پیش‌فرضِ آن
# سوکت را برای دست‌دادن TLS برمی‌دارد و پاسخی که می‌گیریم ممکن است
# اصلاً از سایتِ دیگری باشد. آن‌وقت یک ۴۰۴ بی‌ربط باعث می‌شد اسکریپت
# پیکربندیِ **درست** را برگرداند. با --resolve هم SNI و هم Host درست
# می‌روند و ترافیک هم از سرور بیرون نمی‌رود.
# ⚠ و **با صبر**، نه بلافاصله.
#
# `systemctl reload nginx` به‌محضِ فرستادنِ سیگنال برمی‌گردد؛ خودِ nginx
# غیرهمزمان بارگذاری می‌کند و تا صدها میلی‌ثانیه بعد، پروسه‌های کارگرِ
# قدیمی هنوز با پیکربندیِ قبلی جواب می‌دهند. سنجشِ فوری همان پاسخِ
# قدیمی را می‌گرفت و اسکریپت پیکربندیِ **درست** را برمی‌گرداند — دو بار
# روی سرور واقعی همین شد. با nginx واقعی اندازه‌گیری شد: تا ۱۵۰ms پاسخِ
# قدیمی می‌آمد.
probe_once() {
    [[ -z "$DOMAIN" ]] && return 1
    # ⛔ --noproxy: با پراکسیِ محیط، curl مقدارِ --resolve را نادیده می‌گیرد و
    #    سنجش درباره‌ی سرورِ دیگری حرف می‌زند (همان درسِ hesabland).
    curl -sk --noproxy '*' --max-time 10 -L \
         --resolve "${DOMAIN}:443:127.0.0.1" \
         --resolve "${DOMAIN}:80:127.0.0.1" \
         "https://${DOMAIN}/api/v1/ping" 2>/dev/null || true
}

rollback() {
    red "$1"
    cp -a "$BACKUP" "$SITE_FILE"
    nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true
    info "به حالت قبل برگشت: $BACKUP"
    exit 1
}

PROBE=""
OK=0
for _ in $(seq 1 20); do          # حداکثر حدود ۱۰ ثانیه
    PROBE="$(probe_once)"
    # سورس خام یعنی خطر — همان‌جا برگرد، صبر کردن کمکی نمی‌کند
    if [[ "$PROBE" == *"<?php"* ]]; then
        rollback "⛔ خطر: سورس PHP خام برگشت — قاعده جلوی اجرای PHP را گرفته است."
    fi
    if [[ "$PROBE" == *'"ok"'* ]]; then OK=1; break; fi
    sleep 0.5
done

if [[ $OK -eq 1 ]]; then
    green "✓ /api/v1/ping پاسخ JSON درست داد."
elif [[ -z "$PROBE" ]]; then
    info "پاسخی از /api/v1/ping نیامد (شاید دامنه از داخل سرور حل نمی‌شود)."
    info "خودتان بررسی کنید:  curl -s https://${DOMAIN:-دامنه}/api/v1/ping"
    info "قاعده اعمال شده و دست‌نخورده ماند."
else
    rollback "⛔ پاسخ /api/v1/ping بعد از ۱۰ ثانیه هنوز درست نیست: ${PROBE:0:120}"
fi

green "✅ انجام شد."
