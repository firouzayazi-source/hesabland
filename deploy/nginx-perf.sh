#!/usr/bin/env bash
#
# nginx-perf.sh — بررسی و روشن کردن HTTP/2 روی سایتِ «حساب لند»
#
# ─── رعایت قانون جداسازی ───────────────────────────────────────────
# فقط فایل سایتِ همین پروژه را می‌نویسد:
#     /etc/nginx/sites-available/hesab
# و فقط nginx را reload می‌کند (نه stop، نه restart) و فقط بعد از
# `nginx -t` موفق. به nginx.conf، سایت‌های دیگر، و سرویس ربات‌ها دست
# نمی‌زند. اگر تست پیکربندی رد شود، خودش فایل را برمی‌گرداند.
# ───────────────────────────────────────────────────────────────────
#
# چرا:
#
# با HTTP/1.1 مرورگر برای گرفتن فایل‌های یک صفحه چند اتصال جدا باز
# می‌کند و هر کدام رفت‌وبرگشت خودش را دارد. روی شبکه‌ی موبایل با تأخیر
# بالا همین باعث می‌شود صفحه کند حس شود، حتی وقتی سرور در چند
# میلی‌ثانیه جواب داده. با HTTP/2 همه روی یک اتصال و موازی می‌آیند.
#
# ⚠ درسی که همین‌جا گران تمام شد: نسخه‌ی اول این اسکریپت فقط *فایل
# پیکربندی* را می‌خواند و از رویش نتیجه می‌گرفت. روی سرور واقعی گفت
# «HTTP/2 روشن نیست» در حالی که `curl --http2` جواب `HTTP/2 200`
# می‌داد. علتش این است که `http2` در nginx یک تنظیمِ *سوکت* است: اگر
# هر server block دیگری روی همان آدرس و پورت آن را روشن کرده باشد،
# برای همه روشن است — و از روی یک فایل دیده نمی‌شود.
#
# پس حالا **اول از خود سایت می‌پرسد**. پیکربندی فقط وقتی خوانده و
# دست‌کاری می‌شود که پاسخِ واقعیِ سرور بگوید HTTP/2 خاموش است.
#
# اجرا:
#     sudo bash deploy/nginx-perf.sh            # فقط گزارش می‌دهد
#     sudo bash deploy/nginx-perf.sh --apply    # در صورت نیاز روشن می‌کند

set -euo pipefail

SITE_NAME="${SITE_NAME:-hesab}"
SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/${SITE_NAME}}"
DOMAIN="${DOMAIN:-}"
APPLY=0
[[ "${1:-}" == "--apply" ]] && APPLY=1

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
step()  { printf '\n\033[1m%s\033[0m\n' "$1"; }
plain() { printf '  %s\n' "$1"; }

if [[ "$(id -u)" != "0" ]]; then
    red "⛔ این اسکریپت باید با sudo اجرا شود."
    exit 1
fi
[[ -f "$SITE_FILE" ]] || { red "⛔ فایل سایت پیدا نشد: $SITE_FILE"; exit 1; }

step "وضعیت فعلی"
plain "فایل سایت: $SITE_FILE"
NGX_VER="$(nginx -v 2>&1 | sed -E 's#.*/([0-9.]+).*#\1#')"
plain "نسخه‌ی nginx: ${NGX_VER:-نامعلوم}"

# ---------- ۱. پرسیدن از خودِ سایت (مرجع اصلی) ----------
if [[ -z "$DOMAIN" ]]; then
    DOMAIN="$(grep -m1 -oP '^\s*server_name\s+\K[^;]+' "$SITE_FILE" 2>/dev/null \
              | tr ' ' '\n' | grep -v '^_$' | head -1 || true)"
fi

if [[ -z "$DOMAIN" ]]; then
    plain "نام دامنه از فایل خوانده نشد؛ با DOMAIN=example.com اجرا کنید."
    PROTO=""
else
    plain "دامنه: $DOMAIN"
    PROTO="$(curl -sI --http2 --max-time 8 "https://${DOMAIN}/" 2>/dev/null | head -1 | awk '{print $1}' || true)"
fi

if [[ "$PROTO" == HTTP/2* ]]; then
    green "✓ HTTP/2 روشن است — سایت با HTTP/2 جواب می‌دهد. کاری لازم نیست."
    plain "(پاسخ سرور: $PROTO)"
    exit 0
fi

if [[ -z "$PROTO" ]]; then
    plain "پاسخی از https://${DOMAIN}/ گرفته نشد — شاید DNS یا فایروال."
    plain "بدون پاسخِ واقعی نمی‌شود مطمئن بود، پس چیزی تغییر نمی‌کند."
    plain "دستی بسنجید:  curl -sI --http2 https://${DOMAIN}/ | head -1"
    exit 1
fi

plain "سایت با $PROTO جواب می‌دهد — HTTP/2 روشن نیست."

# ---------- ۲. فقط حالا سراغ پیکربندی می‌رویم ----------
if ! grep -qE '^\s*listen\s+.*\b443\b' "$SITE_FILE"; then
    red "⛔ بلوک HTTPS در $SITE_FILE نیست."
    plain "احتمالاً certbot آن را در فایل دیگری گذاشته. این اسکریپت عمداً"
    plain "فقط فایل سایتِ همین پروژه را دست می‌زند، پس اینجا متوقف می‌شود."
    plain "برای پیدا کردنش:  grep -rl 'listen.*443' /etc/nginx/sites-enabled/"
    exit 1
fi

ver_ge() { printf '%s\n%s\n' "$2" "$1" | sort -V -C; }
NEW_SYNTAX=0
if [[ -n "$NGX_VER" ]] && ver_ge "$NGX_VER" "1.25.1"; then NEW_SYNTAX=1; fi

step "چه چیزی عوض می‌شود"
if [[ $NEW_SYNTAX -eq 1 ]]; then
    plain "به بلوکِ HTTPS این خط اضافه می‌شود:   http2 on;"
else
    plain "به خطِ  listen ... 443 ... ssl;  عبارت  http2  اضافه می‌شود."
fi

if [[ $APPLY -eq 0 ]]; then
    step "نمایشی بود"
    plain "برای اعمال واقعی:  sudo bash deploy/nginx-perf.sh --apply"
    exit 0
fi

step "اعمال"
BACKUP="${SITE_FILE}.bak.$(date +%Y%m%d%H%M%S)"
cp -a "$SITE_FILE" "$BACKUP"; chmod 600 "$BACKUP"
plain "نسخه‌ی پشتیبان: $BACKUP"

# پشتیبانِ بی‌مصرف نباید در sites-available جا بماند.
restore_and_exit() {
    cp -a "$BACKUP" "$SITE_FILE"
    rm -f "$BACKUP"
    exit 1
}

if ! NEW_SYNTAX=$NEW_SYNTAX python3 - "$SITE_FILE" <<'PY'
import os, re, sys

path = sys.argv[1]
new_syntax = os.environ.get('NEW_SYNTAX') == '1'
text = open(path, encoding='utf-8').read()

# خطِ listen مربوط به 443 — با هر شکلی که نوشته شده باشد:
#   listen 443 ssl;                       listen [::]:443 ssl ipv6only=on;
#   listen 1.2.3.4:443 ssl;               listen 443 ssl default_server;
#   listen 443 ssl; # managed by Certbot  ← این یکی نسخه‌ی اول را شکست
#
# کامنتِ انتهای خط جدا نگه داشته می‌شود تا `http2` *پیش از* نقطه‌ویرگول
# اضافه شود و کامنت سر جایش بماند.
LISTEN443 = re.compile(
    r'^(?P<indent>[ \t]*)(?P<body>listen[^\n;]*\b443\b[^\n;]*);(?P<tail>[ \t]*(?:#[^\n]*)?)$',
    re.M
)

hits = list(LISTEN443.finditer(text))
if not hits:
    sys.stderr.write('هیچ خطِ listen مربوط به 443 پیدا نشد.\n')
    sys.exit(2)

if new_syntax:
    if re.search(r'^[ \t]*http2\s+on\s*;', text, re.M):
        sys.stderr.write('http2 on; از قبل هست.\n'); sys.exit(3)
    first = hits[0]
    text = text[:first.end()] + f"\n{first.group('indent')}http2 on;" + text[first.end():]
else:
    changed = False
    def add(mo):
        global changed
        if 'http2' in mo.group('body'):
            return mo.group(0)
        changed = True
        return f"{mo.group('indent')}{mo.group('body').rstrip()} http2;{mo.group('tail')}"
    text = LISTEN443.sub(add, text)
    if not changed:
        sys.stderr.write('http2 از قبل روی خطوط listen هست.\n'); sys.exit(3)

open(path, 'w', encoding='utf-8').write(text)
PY
then
    red "⛔ ویرایش پیکربندی انجام نشد — فایل دست‌نخورده ماند."
    plain "شکلِ خطوط listen را ببینید:  grep -n 'listen' $SITE_FILE"
    restore_and_exit
fi

if ! nginx -t; then
    red "⛔ پیکربندی nginx ایراد دارد — به حالت قبل برگشت."
    restore_and_exit
fi
green "✓ پیکربندی nginx سالم است."

if systemctl reload nginx; then
    green "✓ nginx با reload تنظیم تازه را گرفت."
else
    red "⛔ reload نشد — به حالت قبل برگشت."
    cp -a "$BACKUP" "$SITE_FILE"; rm -f "$BACKUP"
    nginx -t && systemctl reload nginx || true
    exit 1
fi

# ---------- ۳. دوباره از خودِ سایت می‌پرسیم ----------
step "بررسی نتیجه"
sleep 1
AFTER="$(curl -sI --http2 --max-time 8 "https://${DOMAIN}/" 2>/dev/null | head -1 | awk '{print $1}' || true)"
if [[ "$AFTER" == HTTP/2* ]]; then
    green "✅ حالا سایت با $AFTER جواب می‌دهد."
else
    red "⚠ سایت هنوز با ${AFTER:-؟} جواب می‌دهد."
    plain "پیکربندی عوض شد ولی اثر نکرد. نسخه‌ی پشتیبان: $BACKUP"
fi
