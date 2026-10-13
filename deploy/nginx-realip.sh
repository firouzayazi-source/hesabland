#!/usr/bin/env bash
#
# آی‌پیِ واقعیِ کاربر، وقتی سایت پشتِ کلادفلر می‌رود.
#
# ⛔ چرا لازم است — و چرا نبودش خرابیِ **بی‌صدا**ست:
#
#    پشتِ هر CDN، `REMOTE_ADDR` دیگر آی‌پیِ کاربر نیست بلکه آی‌پیِ لبه
#    است. اپ در شش جا روی همان حساب می‌کند و بدترینش سدِ حدس رمز است:
#    سقفِ «۲۰ تلاش در ۱۵ دقیقه برای هر آی‌پی» یک‌باره برای **همه‌ی
#    کاربران با هم** شمرده می‌شود و ظرف چند دقیقه هیچ‌کس نمی‌تواند وارد
#    شود — با همان پیامِ همیشگی که هیچ نمی‌گوید چرا. بازیابیِ رمز،
#    ثبت‌نام و کدِ پیامکی هم همین‌طور.
#
# ⛔ چرا در nginx و نه در PHP:
#
#    اگر PHP سرآیندِ `CF-Connecting-IP` را بخواند، باید خودش تصمیم
#    بگیرد کِی باورش کند — و آن یعنی نسخه‌ی دومی از همان تصمیم، در
#    حالی که nginx ماژولش را دارد. مهم‌تر: nginx فقط وقتی جایگزین
#    می‌کند که **خودِ اتصال** از یکی از آی‌پی‌های زیر آمده باشد، پس
#    سرآیندِ جعلی از هر جای دیگری بی‌اثر است. با خواندنِ خامِ سرآیند در
#    PHP، هر کسی با یک خطِ curl همه‌ی این سدها را دور می‌زد.
#
# استفاده:
#   sudo bash deploy/nginx-realip.sh            # فقط نمایش
#   sudo bash deploy/nginx-realip.sh --apply    # اعمال و سنجش
#   sudo bash deploy/nginx-realip.sh --remove   # برگرداندن
#
set -euo pipefail

SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/hesab}"
DOMAIN="${DOMAIN:-hesab.stland.ir}"
NGINX="${NGINX:-nginx}"
RELOAD="${RELOAD:-systemctl reload nginx}"

# ⛔ سنجش با `--resolve` است نه با آدرسِ خامِ 127.0.0.1 — و این درسِ
#   ثبت‌شده‌ی همین پروژه است که یک بار دیگر هم زد: روی این سرور چند سایت
#   روی یک nginx هستند، پس درخواستی که نامِ دامنه را نمی‌برد به بلوکِ
#   **پیش‌فرض** می‌خورد و صفحه‌ی ۴۰۴ِ یک سایتِ دیگر را می‌گیرد. با
#   `--resolve` هم نام درست می‌رود هم SNI، و اتصال همچنان از خودِ ماشین
#   است — که برای سنجشِ «آی‌پیِ نامعتبر» دقیقاً همان چیزی است که لازم داریم.
PROBE_SCHEME="${PROBE_SCHEME:-https}"
PROBE_PORT="${PROBE_PORT:-443}"

BEGIN='# >>> hesab realip — deploy/nginx-realip.sh'
END='# <<< hesab realip'

red()  { printf '\033[0;31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
info() { printf '\033[0;36m%s\033[0m\n' "$*"; }
warn() { printf '\033[0;33m%s\033[0m\n' "$*"; }

# ⚠ فهرستِ پشتیبان. اگر گرفتنِ فهرستِ زنده نشد از این استفاده می‌شود،
#   **با هشدار** — چون فهرستِ کهنه یعنی همان قفلِ دسته‌جمعیِ بالا، فقط
#   دیرتر و برای بخشی از کاربران.
FALLBACK_V4='173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22
141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20
197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13
104.24.0.0/14 172.64.0.0/13 131.0.72.0/22'
FALLBACK_V6='2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32
2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32'

MODE="${1:-}"

if [ ! -f "$SITE_FILE" ]; then
    red "فایل سایت پیدا نشد: $SITE_FILE"
    exit 1
fi

# ---------------------------------------------------------------- فهرست
fetch_ranges() {
    local v4 v6
    v4=$(curl -fsS --max-time 15 https://www.cloudflare.com/ips-v4 2>/dev/null || true)
    v6=$(curl -fsS --max-time 15 https://www.cloudflare.com/ips-v6 2>/dev/null || true)

    # ⛔ فهرستِ ناقص از فهرستِ کهنه بدتر است: نیمی از کاربران آی‌پیِ
    #   لبه می‌گیرند و نیمی نه، و تشخیصش تقریباً ناممکن می‌شود. پس یا
    #   کاملِ زنده، یا کاملِ پشتیبان.
    if [ "$(printf '%s\n' "$v4" | grep -c '/')" -ge 10 ] \
       && [ "$(printf '%s\n' "$v6" | grep -c '/')" -ge 4 ]; then
        printf '%s\n%s\n' "$v4" "$v6"
        return 0
    fi
    warn "⚠ فهرستِ زنده‌ی کلادفلر گرفته نشد؛ فهرستِ پشتیبانِ داخلِ اسکریپت به کار رفت." >&2
    warn "  اگر مدتی بعد کاربران بی‌دلیل قفل شدند، همین اسکریپت را دوباره اجرا کنید." >&2
    printf '%s\n%s\n' "$FALLBACK_V4" "$FALLBACK_V6" | tr ' ' '\n' | grep '/'
}

build_block() {
    local trusted="$1"
    printf '%s\n' "$BEGIN"
    printf '    # آی‌پیِ واقعیِ کاربر از سرآیندِ کلادفلر خوانده می‌شود،\n'
    printf '    # ولی **فقط** وقتی اتصال از خودِ کلادفلر آمده باشد.\n'
    printf '%s\n' "$trusted" | while read -r c; do
        [ -n "$c" ] && printf '    set_real_ip_from %s;\n' "$c"
    done
    printf '    real_ip_header CF-Connecting-IP;\n'
    printf '\n'
    printf '    # سنجشِ همین تنظیم، فقط از روی خودِ سرور.\n'
    printf '    #\n'
    printf '    # ⛔ گیت روی $realip_remote_addr است، نه allow/deny — و این\n'
    printf '    # اجباری است، نه سلیقه. `return` در فازِ rewrite اجرا می‌شود و\n'
    printf '    # آن **پیش از** فازِ access است، پس allow/deny کنارِ یک return\n'
    printf '    # هرگز نوبتش نمی‌رسد: این مسیر با آن‌ها برای کلِ اینترنت باز\n'
    printf '    # می‌ماند. روی سرور واقعی دیده شد (۲۰۰ به‌جای ۴۰۳) و با\n'
    printf '    # nginx 1.24 بازتولید شد.\n'
    printf '    #\n'
    printf '    # $realip_remote_addr آدرسِ **واقعیِ اتصال** است، پیش از\n'
    printf '    # جایگزینی. پس بازدیدکننده‌ی بیرونی (که از لبه‌ی کلادفلر\n'
    printf '    # می‌آید) ۴۰۴ می‌گیرد، ولی سنجشِ خودِ این اسکریپت — که از\n'
    printf '    # 127.0.0.1 وصل می‌شود و سرآیند می‌فرستد — همچنان کار می‌کند.\n'
    printf '    location = /__realip {\n'
    printf '        default_type text/plain;\n'
    # ⚠ نقطه با `[.]` نوشته می‌شود نه `\.` — همان معنا، بدونِ هیچ
    # بک‌اسلشی. لازم نیست (انتقال به فایل دیگر escape نمی‌خورد) ولی نگه
    # داشته شد: یک الگوی بی‌بک‌اسلش در برابرِ هر مسیرِ انتقالِ آینده هم امن است.
    printf '        if ($realip_remote_addr !~ %s) { return 404; }\n' "'^(127[.]0[.]0[.]1|::1)\$'"
    printf '        return 200 "$remote_addr\\n";\n'
    printf '    }\n'
    printf '%s\n' "$END"
}

strip_block() {
    awk -v b="$BEGIN" -v e="$END" '
        index($0, b) { skip = 1 }
        !skip { print }
        index($0, e) { skip = 0 }
    ' "$1"
}

# ---------------------------------------------------------------- حالت‌ها
if [ "$MODE" = "--remove" ]; then
    if ! grep -qF "$BEGIN" "$SITE_FILE"; then
        info "چیزی برای برداشتن نبود."
        exit 0
    fi
    cp "$SITE_FILE" "$SITE_FILE.realip-bak"
    strip_block "$SITE_FILE" > "$SITE_FILE.tmp" && mv "$SITE_FILE.tmp" "$SITE_FILE"
    if ! $NGINX -t >/dev/null 2>&1; then
        cp "$SITE_FILE.realip-bak" "$SITE_FILE"
        red "پیکربندی خراب شد؛ برگردانده شد."
        exit 1
    fi
    $RELOAD
    grn "✅ برداشته شد. آی‌پی دوباره همان آی‌پیِ اتصال است."
    exit 0
fi

TRUSTED=$(fetch_ranges)
COUNT=$(printf '%s\n' "$TRUSTED" | grep -c '/')

if [ "$MODE" != "--apply" ]; then
    info "حالت نمایشی — هیچ چیزی نوشته نمی‌شود. برای اعمال: --apply"
    echo
    info "فایل سایت : $SITE_FILE"
    info "دامنه     : $DOMAIN"
    info "بازه‌ها    : $COUNT مورد"
    echo
    if grep -qF "$BEGIN" "$SITE_FILE"; then
        info "این تنظیم از قبل هست و با --apply تازه می‌شود."
    else
        info "این تنظیم هنوز نیست."
    fi
    echo
    info "چیزی که اضافه می‌شود (کوتاه‌شده):"
    build_block "$TRUSTED" | head -6 | sed 's/^/    /'
    info "    … و $((COUNT - 3)) بازه‌ی دیگر"
    exit 0
fi

# ---------------------------------------------------------------- اعمال
cp "$SITE_FILE" "$SITE_FILE.realip-bak"
info "نسخه‌ی پشتیبان: $SITE_FILE.realip-bak"

BLOCK_FILE=$(mktemp)
# اسکریپت بینِ اینجا و پایان چند جا `exit 1` دارد (سنجشِ ناموفق، رول‌بک)،
# پس پاک شدنش با trap است نه با یک `rm` در خطِ آخر.
trap 'rm -f "$BLOCK_FILE"' EXIT
build_block "$TRUSTED" > "$BLOCK_FILE"

# ⛔ بلوک از راهِ **فایل** به awk می‌رود، نه `-v block=…`.
#   awk مقدارِ `-v` را مثل یک رشته‌ی برنامه تفسیر می‌کند و هر دنباله‌ی
#   escape را **بی‌صدا** عوض می‌کند: `\.` می‌شود `.` و `\n` می‌شود یک
#   خطِ واقعی. یعنی چیزی که روی سرور می‌نشیند با چیزی که `build_block`
#   ساخته یکی نیست — و آن‌وقت تستی که خروجیِ `build_block` را می‌سنجد،
#   چیزی را تأیید می‌کند که اجرا نمی‌شود. روی سرور واقعی با هشدارِ
#   «escape sequence \. treated as plain .» دیده شد.
#   با `getline` بایت‌به‌بایت کپی می‌شود و هیچ تفسیری در کار نیست.
#
# بلوکِ قبلی (اگر بود) برداشته می‌شود تا اجرای دوباره تکراری نسازد، و
# بلوکِ تازه بعد از هر `server_name` گذاشته می‌شود — هم در بلوکِ ۸۰ و هم ۴۴۳.
strip_block "$SITE_FILE" > "$SITE_FILE.tmp"
awk -v bf="$BLOCK_FILE" '
    { print }
    /^[[:space:]]*server_name[[:space:]]/ {
        while ((getline line < bf) > 0) { print line }
        close(bf)
    }
' "$SITE_FILE.tmp" > "$SITE_FILE.new" && mv "$SITE_FILE.new" "$SITE_FILE"
rm -f "$SITE_FILE.tmp"

restore() {
    cp "$SITE_FILE.realip-bak" "$SITE_FILE"
    $NGINX -t >/dev/null 2>&1 && $RELOAD || true
}

if ! $NGINX -t >/dev/null 2>&1; then
    red "پیکربندی معتبر نیست؛ برگردانده شد."
    $NGINX -t 2>&1 | sed 's/^/    /'
    restore
    exit 1
fi

$RELOAD
sleep 1

# ⛔ «پیکربندی معتبر است» با «درست کار می‌کند» یکی نیست — همان درسی که
#    قاعده‌ی nginx در api/v1 داد. پس خودِ رفتار سنجیده می‌شود، در هر دو
#    جهت، چون هر کدام یک خرابیِ متفاوت را می‌گیرد:
#
#      ۱. سرآیند از یک آی‌پیِ **نامعتبر** نباید باور شود (وگرنه هر کسی
#         با یک curl همه‌ی سدهای آی‌پی را دور می‌زند).
#      ۲. سرآیند از یک آی‌پیِ **معتبر** باید باور شود (وگرنه ماژول
#         بارگذاری نشده یا نامِ سرآیند غلط است و همه‌ی کاربران باز هم
#         یک آی‌پی می‌گیرند — یعنی همان قفلِ دسته‌جمعی).
SPOOF='198.51.100.77'
PROBE_URL="$PROBE_SCHEME://$DOMAIN:$PROBE_PORT/__realip"

probe() {
    # ⛔ --noproxy: با پراکسیِ محیط --resolve نادیده گرفته می‌شود و سرآیندِ
    #    جعلی به پراکسی می‌رسد، نه به nginxِ همین سرور (درسِ hesabland).
    curl -s --noproxy '*' --max-time 10 \
         --resolve "$DOMAIN:$PROBE_PORT:127.0.0.1" \
         -H "CF-Connecting-IP: $SPOOF" \
         "$PROBE_URL" | tr -d '\r\n' || true
}

# ⛔ پاسخ باید **شکلِ آی‌پی** داشته باشد، نه فقط با مقدارِ جعلی فرق کند.
#   نسخه‌ی اول همین را نداشت و یک صفحه‌ی ۴۰۴ِ HTML را «موفق» خواند —
#   یعنی سنجشی که روی خرابی سبز می‌شود، که از نبودِ سنجش بدتر است.
looks_like_ip() {
    printf '%s' "$1" | grep -qE '^[0-9a-fA-F:.]{3,45}$'
}

SEEN=$(probe)
if [ "$SEEN" = "$SPOOF" ]; then
    red "سنجش شکست خورد: سرآیندِ جعلی از یک آی‌پیِ نامعتبر باور شد."
    restore
    exit 1
fi
if ! looks_like_ip "$SEEN"; then
    red "سنجش شکست خورد: $PROBE_URL آی‌پی برنگرداند."
    info "پاسخ: ${SEEN:0:120}"
    echo
    info "اگر ۴۰۴ است یعنی درخواست به بلوکِ این سایت نخورده."
    info "دامنه یا پورت را با متغیر بدهید، مثلاً:"
    info "    sudo DOMAIN=hesab.stland.ir PROBE_PORT=443 bash deploy/nginx-realip.sh --apply"
    restore
    exit 1
fi
info "✓ سرآیند از آی‌پیِ نامعتبر نادیده گرفته می‌شود (دیده شد: $SEEN)"

# حالا موقتاً همین ماشین را «قابل اعتماد» می‌کنیم تا جهتِ دوم هم سنجیده
# شود. ⚠ این حالت فقط چند ثانیه می‌ماند و بعد برداشته می‌شود.
sed "s|    real_ip_header CF-Connecting-IP;|    set_real_ip_from 127.0.0.1;\n    real_ip_header CF-Connecting-IP;|" \
    "$SITE_FILE" > "$SITE_FILE.probe"
cp "$SITE_FILE" "$SITE_FILE.final"
cp "$SITE_FILE.probe" "$SITE_FILE"

OK2=0
if $NGINX -t >/dev/null 2>&1; then
    $RELOAD; sleep 1
    SEEN2=$(probe)
    [ "$SEEN2" = "$SPOOF" ] && OK2=1
fi

cp "$SITE_FILE.final" "$SITE_FILE"
rm -f "$SITE_FILE.probe" "$SITE_FILE.final"
$NGINX -t >/dev/null 2>&1 && $RELOAD

if [ "$OK2" != "1" ]; then
    red "سنجش شکست خورد: از آی‌پیِ معتبر هم سرآیند خوانده نشد."
    red "یعنی ماژول real_ip کار نمی‌کند یا نامِ سرآیند نمی‌خواند."
    restore
    exit 1
fi
info "✓ سرآیند از آی‌پیِ معتبر خوانده می‌شود"

echo
grn "✅ اعمال شد — $COUNT بازه‌ی کلادفلر."
echo
info "حالا در پنل کلادفلر رکوردِ hesab را از ابرِ خاکستری به **نارنجی** ببرید،"
info "و در همان پنل SSL/TLS را روی «Full (strict)» بگذارید."
info "با «Flexible» کلادفلر با http به سرور وصل می‌شود، بلوکِ :80 به https"
info "برش می‌گرداند، و سایت در حلقه‌ی ریدایرکت می‌افتد — یعنی برای همه‌ی"
info "کاربران با هم می‌خوابد، همان لحظه‌ی نارنجی شدن."
echo
info "⛔ و یک خطِ دیگر که فراموش کردنش بی‌صداست — در config/config.php:"
info "    define('APP_BEHIND_CDN', true);"
info "پشتِ CDN، ارتباطِ رمزنگاری‌شده‌ی کاربر روی لبه‌ی آن‌ها باز می‌شود؛"
info "یعنی رمز و شماره کارت از دیدشان رد می‌شود و صفحه‌ی «حریم خصوصی»"
info "باید همین را بگوید. برنامه سرآیندِ خودِ کلادفلر را هم می‌سنجد، پس"
info "بدونِ این خط هم معمولاً درست می‌گوید — ولی این یکی قطعی‌اش می‌کند."
echo
info "⛔ /__realip از بیرون ۴۰۴ می‌دهد و آن **درست است**: آن مسیر عمداً"
info "فقط از روی خودِ سرور باز است. پس آن را نشانه‌ی خرابی نگیرید."
info "سنجشِ زنده بودنِ پیکربندی از روی خودِ سرور و بدونِ رد شدن از کلادفلر:"
info "    curl -s --resolve $DOMAIN:$PROBE_PORT:127.0.0.1 $PROBE_URL"
info "باید 127.0.0.1 چاپ کند."
