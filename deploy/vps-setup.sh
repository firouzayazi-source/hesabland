#!/usr/bin/env bash
#
# vps-setup.sh — راه‌اندازی «حساب لند» روی VPS، بدون دست زدن به هیچ سرویس دیگر
#
# ─── قانون این اسکریپت ────────────────────────────────────────────────
# روی این سرور سرویس‌های دیگری (از جمله دو ربات) در حال کار هستند.
# این اسکریپت فقط اجازه دارد به این مسیرها بنویسد:
#
#     /opt/hesab/app                       ← فایل‌های پروژه
#     /etc/nginx/sites-available/hesab     ← فقط همین یک فایل
#     /etc/nginx/sites-enabled/hesab       ← فقط همین یک لینک
#     /etc/php/<v>/fpm/pool.d/hesab.conf   ← pool اختصاصی
#     دیتابیس hesab_db و کاربر 'hesab_user'@'localhost'
#     کاربر سیستمی hesab
#
# هر تلاش برای نوشتن خارج از این فهرست، اسکریپت را متوقف می‌کند.
# هیچ سرویسی restart نمی‌شود (فقط reload، آن هم بعد از تست موفق).
# هیچ چیزی حذف نمی‌شود. هیچ فایل پیکربندی مشترکی (php.ini، nginx.conf،
# سایت‌های دیگر، قوانین فایروال موجود) تغییر نمی‌کند.
# ──────────────────────────────────────────────────────────────────────
#
# اجرا:
#     bash deploy/vps-setup.sh --domain hesab.stland.ir            # فقط نشان می‌دهد چه می‌کند
#     sudo bash deploy/vps-setup.sh --domain hesab.stland.ir --apply   # واقعاً اجرا می‌کند
#
# پیش از --apply حتماً یک بار بدون آن اجرا کنید و خروجی را بخوانید.
#
# ─── نسخه‌ی آزمایشی (staging) ─────────────────────────────────────────
#     sudo bash deploy/vps-setup.sh --staging --domain staging.hesab.stland.ir --apply
#
# همین اسکریپت و همین قالب‌ها — فقط با نام‌های staging:
#     /opt/hesab/staging، کاربر hesabstg، سایت و pool به نامِ hesab-staging،
#     دیتابیسِ hesab_staging با کاربرِ 'hesab_stg'@'localhost'
#     (GRANT فقط روی hesab_staging.*)، و /etc/nginx/hesab-staging.htpasswd
#
# ⛔ چرا همین اسکریپت و نه یک اسکریپتِ دوم: قالبِ nginx (CSP، بستنِ var/،
#    deploy/، tests/، قاعده‌ی api/v1، sw.js) و pool (open_basedir،
#    disable_functions) باید در هر دو **یکی** باشند. با نسخه‌ی دوم، staging
#    دیر یا زود چیزی را نشان می‌داد که سایتِ اصلی ندارد — و آن‌وقت «در
#    staging درست بود» دیگر هیچ چیزی را ثابت نمی‌کرد.
#
# سه چیز فقط در staging اضافه می‌شود: رمزِ ورود جلوی کلِ سایت (جز خودِ
# سرور، برای سنجشِ سلامت)، X-Robots-Tag: noindex، و pool کوچکِ ondemand.

set -euo pipefail

# ⛔ --staging پیش از مقدارهای پیش‌فرض خوانده می‌شود، تا نام‌ها از اول
#    staging باشند و هیچ مسیری حتی یک لحظه روی نام‌های سایتِ اصلی نرود.
STAGING=0
for __a in "$@"; do [[ "$__a" == "--staging" ]] && STAGING=1; done
PROD_DIR="${PROD_DIR:-/opt/hesab/app}"
if [[ $STAGING -eq 1 ]]; then
    APP_DIR="${APP_DIR:-/opt/hesab/staging}"
    APP_USER="${APP_USER:-hesabstg}"
    DB_NAME="${DB_NAME:-hesab_staging}"
    DB_USER="${DB_USER:-hesab_stg}"
    SITE_NAME="${SITE_NAME:-hesab-staging}"
fi

APP_DIR="${APP_DIR:-/opt/hesab/app}"
APP_USER="${APP_USER:-hesab}"
DB_NAME="${DB_NAME:-hesab_db}"
DB_USER="${DB_USER:-hesab_user}"
SITE_NAME="${SITE_NAME:-hesab}"
REPO_URL="${REPO_URL:-}"
DOMAIN=""
APPLY=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 ;;
        --repo)   REPO_URL="${2:-}"; shift 2 ;;
        --apply)  APPLY=1; shift ;;
        --staging) shift ;;
        -h|--help) sed -n '2,45p' "$0"; exit 0 ;;
        *) echo "گزینه ناشناخته: $1"; exit 1 ;;
    esac
done

[[ -z "$DOMAIN" ]] && { echo "دامنه را بدهید:  --domain hesab.stland.ir"; exit 1; }

# ⛔ staging هرگز روی نام‌های سایتِ اصلی نمی‌نشیند — حتی اگر کسی با متغیرِ
#    محیطی اشتباه این کار را بخواهد. یک migration آزمایشی روی hesab_db یا
#    یک chown روی /opt/hesab/app یعنی خواباندنِ سایتِ واقعی.
if [[ $STAGING -eq 1 ]]; then
    if [[ "$APP_DIR" == "$PROD_DIR" || "$APP_USER" == "hesab" || "$SITE_NAME" == "hesab" \
          || "$DB_NAME" == "hesab_db" || "$DB_USER" == "hesab_user" ]]; then
        echo "⛔ staging نمی‌تواند نام‌های سایتِ اصلی را بگیرد (پوشه، کاربر، سایت یا دیتابیس)."
        exit 1
    fi
fi

green() { printf '\033[0;32m%s\033[0m\n' "$1"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$1"; }
info()  { printf '\033[0;36m%s\033[0m\n' "$1"; }
step()  { printf '\n\033[1m── %s\033[0m\n' "$1"; }

# ---------- نگهبان مسیرها ----------
# هر نوشتنی باید از این تابع رد شود. مسیر خارج از فهرست مجاز = توقف کامل.
PHP_VER=""
assert_allowed() {
    local path="$1" p
    local extra=''
    [[ $STAGING -eq 1 ]] && extra="/etc/nginx/${SITE_NAME}.htpasswd"
    for p in "$APP_DIR" \
             "/etc/nginx/sites-available/$SITE_NAME" \
             "/etc/nginx/sites-enabled/$SITE_NAME" \
             "/etc/php/${PHP_VER}/fpm/pool.d/${SITE_NAME}.conf" \
             "$extra"; do
        [[ -n "$p" && ( "$path" == "$p" || "$path" == "$p"/* ) ]] && return 0
    done
    red "⛔ توقف: تلاش برای نوشتن خارج از محدوده‌ی مجاز این پروژه:"
    red "   $path"
    red "   این یعنی باگ در اسکریپت. هیچ تغییری اعمال نشد."
    exit 1
}

run() {   # اجرای دستور — در حالت نمایشی فقط چاپ می‌شود
    if [[ $APPLY -eq 1 ]]; then
        eval "$@"
    else
        printf '  \033[0;90m$ %s\033[0m\n' "$*"
    fi
}

write_file() {   # write_file <path> <<< محتوا
    local path="$1"
    assert_allowed "$path"
    if [[ $APPLY -eq 1 ]]; then
        mkdir -p "$(dirname "$path")"
        cat > "$path"
        green "  نوشته شد: $path"
    else
        printf '  \033[0;90m→ می‌نویسد: %s\033[0m\n' "$path"
        cat > /dev/null
    fi
}

if [[ $APPLY -eq 0 ]]; then
    printf '\n\033[1;33m*** حالت نمایشی — هیچ تغییری اعمال نمی‌شود ***\033[0m\n'
    printf '\033[0;33mبرای اجرای واقعی، بعد از خواندن خروجی، --apply را اضافه کنید.\033[0m\n'
elif [[ $EUID -ne 0 ]]; then
    red "برای --apply باید با sudo اجرا شود."; exit 1
fi

# ---------- ۰. بررسی تداخل ----------
step "۰. بررسی تداخل با سرویس‌های موجود"

for port in 80 443; do
    if command -v ss >/dev/null 2>&1 && ss -lnt 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$port\$"; then
        holder=$(ss -lntp 2>/dev/null | grep -E "[:.]$port\s" | grep -oP 'users:\(\("\K[^"]+' | head -1)
        if [[ "$holder" == "nginx" ]]; then
            info "پورت $port در اختیار nginx است — خوب است، فقط یک server block اضافه می‌کنیم."
        else
            red "⚠️  پورت $port در اختیار «${holder:-نامشخص}» است، نه nginx."
            red "    اگر این یکی از ربات‌های شماست، متوقفش نمی‌کنیم."
            red "    اسکریپت اینجا می‌ایستد تا خودتان تصمیم بگیرید."
            exit 1
        fi
    fi
done

SITE_FILE="/etc/nginx/sites-available/$SITE_NAME"
SKIP_NGINX=0
if [[ -e "$SITE_FILE" ]]; then
    if grep -q "ssl_certificate" "$SITE_FILE" 2>/dev/null; then
        # certbot این فایل را برای HTTPS بازنویسی کرده. بازنویسی دوباره‌ی آن
        # با قالب HTTP، گواهی را از پیکربندی حذف می‌کند و سایت از HTTPS
        # می‌افتد. پس دست نمی‌زنیم.
        SKIP_NGINX=1
        info "سایت «$SITE_NAME» گواهی SSL دارد (کار certbot) — دست‌نخورده می‌ماند."
        info "اگر عمداً می‌خواهید از نو ساخته شود: mv $SITE_FILE $SITE_FILE.bak"
        info "و بعد از اجرای دوباره‌ی این اسکریپت، certbot را دوباره بزنید."
    else
        info "سایت nginx به نام «$SITE_NAME» از قبل هست — بازنویسی می‌شود (فقط همین فایل)."
    fi
fi

for f in /etc/nginx/sites-enabled/*; do
    [[ -e "$f" ]] || continue
    [[ "$(basename "$f")" == "$SITE_NAME" ]] && continue
    if grep -qE "server_name[^;]*\b${DOMAIN//./\\.}\b" "$f" 2>/dev/null; then
        red "⛔ دامنه $DOMAIN از قبل در سایت دیگری تعریف شده: $f"
        red "    به آن فایل دست نمی‌زنیم. دامنه‌ی دیگری انتخاب کنید یا خودتان بررسی کنید."
        exit 1
    fi
done
green "تداخلی پیدا نشد."

# ---------- ۱. نسخه PHP ----------
step "۱. تشخیص نسخه PHP"
if compgen -G "/etc/php/*/fpm" >/dev/null 2>&1; then
    PHP_VER=$(basename "$(ls -d /etc/php/*/fpm 2>/dev/null | sort -V | tail -1 | xargs dirname)")
    green "php-fpm نسخه $PHP_VER پیدا شد."
elif command -v php >/dev/null 2>&1; then
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    info "php-fpm نصب نیست ولی PHP $PHP_VER هست. لازم است نصب شود:"
    info "  sudo apt install php${PHP_VER}-fpm php${PHP_VER}-mysql php${PHP_VER}-gd php${PHP_VER}-mbstring php${PHP_VER}-zip"
    [[ $APPLY -eq 1 ]] && { red "اول php-fpm را نصب کنید، بعد دوباره اجرا کنید."; exit 1; }
else
    red "PHP نصب نیست. اول نصبش کنید (نسخه ۸.۰ به بالا):"
    red "  sudo apt install php-fpm php-mysql php-gd php-mbstring php-zip"
    exit 1
fi

# ---------- ۲. کاربر سیستمی ----------
step "۲. کاربر سیستمی اختصاصی «$APP_USER»"
if id -u "$APP_USER" >/dev/null 2>&1; then
    green "کاربر $APP_USER از قبل هست."
else
    info "ساخته می‌شود — کاربر بدون shell، فقط برای اجرای این اپ."
    run "useradd --system --home-dir '$APP_DIR' --shell /usr/sbin/nologin '$APP_USER'"
fi

# ---------- ۳. فایل‌های پروژه ----------
step "۳. فایل‌های پروژه در $APP_DIR"
assert_allowed "$APP_DIR"
if [[ -d "$APP_DIR/.git" ]]; then
    green "مخزن از قبل هست — با ./deploy.sh به‌روزش کنید."
elif [[ $STAGING -eq 1 && -z "$REPO_URL" && -d "$PROD_DIR/.git" ]]; then
    # ⛔ از روی مخزنِ خودِ سرور کپی می‌شود و بعد origin به همان آدرسِ سایتِ
    #    اصلی برمی‌گردد — همراهِ همان کلیدِ استقرار (core.sshCommand). پس
    #    staging دقیقاً همان کدی را می‌گیرد که سایتِ اصلی می‌گیرد، بی‌آنکه
    #    کلید یا دسترسیِ تازه‌ای به گیت‌هاب لازم باشد.
    info "کپی از مخزنِ سایتِ اصلی ($PROD_DIR) — همان origin و همان کلیدِ استقرار."
    run "git clone --quiet '$PROD_DIR' '$APP_DIR'"
    run "git -C '$APP_DIR' remote set-url origin \"\$(git -C '$PROD_DIR' remote get-url origin)\""
    run "__ssh=\"\$(git -C '$PROD_DIR' config --get core.sshCommand || true)\"; [ -n \"\$__ssh\" ] && git -C '$APP_DIR' config core.sshCommand \"\$__ssh\" || true"
    run "git -C '$APP_DIR' fetch --quiet origin"
elif [[ -n "$REPO_URL" ]]; then
    run "mkdir -p '$APP_DIR'"
    run "git clone '$REPO_URL' '$APP_DIR'"
else
    info "آدرس مخزن را با --repo بدهید، یا فایل‌ها را دستی در $APP_DIR بگذارید."
fi
# مدل مالکیت:
#   کد        → root، فقط‌خواندنی برای اپ. یعنی PHP نمی‌تواند کد خودش را
#                عوض کند، و git هم بدون خطای «مالکیت مشکوک» کار می‌کند.
#   uploads   → کاربر اپ، چون تنها جایی است که باید در آن بنویسد.
#   config.php → root:hesab با 640؛ اپ می‌خواند، کاربران دیگر سرور نه.
run "mkdir -p '$APP_DIR/uploads/avatars'"
run "chown -R root:root '$APP_DIR'"
run "find '$APP_DIR' -type d -not -path '*/.git/*' -exec chmod 755 {} +"
run "find '$APP_DIR' -type f -not -path '*/.git/*' -exec chmod 644 {} +"
# بیت اجرای اسکریپت‌ها باید برگردد: گیت مود فایل را ردیابی می‌کند و
# بدون این، هر git pull بعدی با «تغییرات محلی» شکست می‌خورد.
run "find '$APP_DIR' -name '*.sh' -not -path '*/.git/*' -exec chmod 755 {} +"
# ⚠ hesabland پسوندِ .sh ندارد؛ بدونِ این خط غیرِاجرایی می‌ماند و گیت آن را
#   «تغییرِ محلی» می‌بیند (در اولین استقرارِ staging دقیقاً همین دیده شد).
run "chmod 755 '$APP_DIR/hesabland' '$APP_DIR/deploy.sh' 2>/dev/null || true"
run "chown -R '$APP_USER':'$APP_USER' '$APP_DIR/uploads'"
run "chmod 755 '$APP_DIR/uploads' '$APP_DIR/uploads/avatars'"
# پوشه‌ی نشست‌ها — فقط کاربر اپ، هیچ‌کس دیگر
run "mkdir -p '$APP_DIR/var/sessions'"
run "chown -R '$APP_USER':'$APP_USER' '$APP_DIR/var'"
run "chmod 700 '$APP_DIR/var/sessions'"

# ---------- ۴. دیتابیس ----------
step "۴. دیتابیس"
info "دسترسی کاربر دیتابیس فقط روی «$DB_NAME» است — نه روی دیتابیس ربات‌ها."
if [[ $STAGING -eq 1 ]]; then
    # ⛔ در staging خودکار است، نه چند دستورِ چاپ‌شده: پیستِ چندخطی روی
    #    SSH موبایل به هم می‌ریزد (درسِ --verify-pass). و فقط روی نام‌هایی
    #    که نگهبانِ بالا سنجیده: GRANT فقط روی `$DB_NAME`.*، نه *.*.
    STG_CONF="$APP_DIR/config/config.php"
    if [[ $APPLY -eq 1 && -f "$STG_CONF" ]]; then
        info "config.php این نسخه از قبل هست — دیتابیس و رمزش دست‌نخورده می‌مانند."
    elif [[ $APPLY -eq 1 ]]; then
        STG_DB_PASS="$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 28)"
        mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;"
        mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$STG_DB_PASS';"
        mysql -e "ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$STG_DB_PASS';"
        mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
        mysql -e "FLUSH PRIVILEGES;"
        green "  دیتابیسِ $DB_NAME و کاربرِ $DB_USER آماده شد."

        # ⛔ config از روی config.example.php ساخته می‌شود، **نه** از کانفیگِ
        #    سایتِ اصلی: آن یکی کلیدِ رمزنگاری، کلیدِ پنلِ پیامک، رمزِ SMTP و
        #    توکنِ فروشگاه دارد، و هیچ‌کدام نباید در نسخه‌ی آزمایشی باشند.
        #    ایمیل و پیامک خاموش‌اند، پس از staging هیچ پیامی به کسی نمی‌رود.
        mkdir -p "$APP_DIR/config"
        STG_SECRET="$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
        php -r '
            [$f, $out, $db, $user, $pass, $url, $secret] = array_slice($argv, 1);
            $s = file_get_contents($f);
            $set = ["DB_NAME" => $db, "DB_USER" => $user, "DB_PASSWORD" => $pass,
                    "APP_URL" => $url, "APP_SECRET_KEY" => $secret, "APP_ENV" => "staging",
                    "APP_ENCRYPTION_KEY" => "", "MAIL_METHOD" => "", "SMS_METHOD" => "",
                    "STORE_API_URL" => "", "STORE_API_TOKEN" => ""];
            foreach ($set as $k => $v) {
                $s = preg_replace("/define\\(\x27" . $k . "\x27,\\s*\x27[^\x27]*\x27\\);/",
                                  "define(\x27" . $k . "\x27, " . var_export($v, true) . ");", $s, 1);
            }
            $s = preg_replace("/define\\(\x27APP_FORCE_HTTPS\x27,\\s*false\\);/", "define(\x27APP_FORCE_HTTPS\x27, true);", $s, 1);
            file_put_contents($out, $s);
        ' "$APP_DIR/config/config.example.php" "$STG_CONF" "$DB_NAME" "$DB_USER" "$STG_DB_PASS" \
          "https://$DOMAIN" "$STG_SECRET"
        chown root:"$APP_USER" "$STG_CONF" && chmod 640 "$STG_CONF"
        sudo -u "$APP_USER" test -r "$STG_CONF" || chmod 644 "$STG_CONF"
        green "  نوشته شد: $STG_CONF  (APP_ENV=staging، ایمیل و پیامک خاموش)"
    else
        printf '  \033[0;90m→ دیتابیسِ %s و کاربرِ %s را با رمزِ تصادفی می‌سازد\033[0m\n' "$DB_NAME" "$DB_USER"
        printf '  \033[0;90m→ می‌نویسد: %s  (از config.example.php، نه کانفیگِ سایتِ اصلی)\033[0m\n' "$APP_DIR/config/config.php"
    fi
else
cat <<SQL

  # این‌ها را خودتان اجرا کنید (رمز را جای YOUR_STRONG_PASSWORD بگذارید):
  sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;"
  sudo mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY 'YOUR_STRONG_PASSWORD';"
  sudo mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
  sudo mysql -e "FLUSH PRIVILEGES;"

  # توجه: GRANT فقط روی $DB_NAME.* است، نه *.* — این عمدی است.
SQL
fi

# ---------- ۵. pool اختصاصی php-fpm ----------
if [[ $STAGING -eq 1 ]]; then
    # staging را فقط شما باز می‌کنید: پروسه‌ها تا درخواستِ بعدی نمی‌مانند،
    # پس روزهایی که کسی نگاهش نمی‌کند هیچ حافظه‌ای از سایتِ اصلی نمی‌گیرد.
    POOL_PM="pm = ondemand
pm.max_children = 3
pm.process_idle_timeout = 60s
pm.max_requests = 500"
else
    POOL_PM="pm = dynamic
pm.max_children = 12
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500"
fi
step "۵. pool اختصاصی php-fpm"
info "این pool تازه است و pool های موجود (اگر باشند) دست‌نخورده می‌مانند."
info "با open_basedir، کد PHP این اپ حتی به‌طور تصادفی هم نمی‌تواند بیرون از $APP_DIR را بخواند."
write_file "/etc/php/${PHP_VER}/fpm/pool.d/${SITE_NAME}.conf" <<POOL
; pool اختصاصی «حساب لند»
; این فایل فقط به این پروژه مربوط است و روی pool های دیگر اثری ندارد.
[${SITE_NAME}]
user  = ${APP_USER}
group = ${APP_USER}

listen = /run/php/php-${SITE_NAME}.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660

; چرا dynamic و نه ondemand:
;
; با ondemand و max_children=5، پروسه‌ها بعد از ۳۰ ثانیه بی‌کاری کشته
; می‌شدند و درخواست بعدی باید منتظر ساخته‌شدن پروسه می‌ماند. بدتر از آن،
; یک بارگذاری صفحه چند درخواست PHP هم‌زمان داشت و ۵ تا زود پر می‌شد —
; بقیه در صف می‌ماندند و کاربر می‌دید سایت چند ثانیه قفل کرد.
;
; حالا دو پروسه همیشه گرم می‌مانند (حافظه‌ی ناچیز) و سقف بالاتر است.
; ضمناً CSS/JS اصلاً از PHP نمی‌گذرند، پس هر بارگذاری صفحه فقط یک
; پروسه می‌خواهد نه چهار تا.
${POOL_PM}

; حصار امنیتی: PHP این اپ فقط همین مسیرها را می‌بیند.
; هر تلاشی برای خواندن مسیر ربات‌ها یا هر جای دیگر سرور، خطا می‌دهد.
php_admin_value[open_basedir] = ${APP_DIR}:/tmp:/usr/share/php
php_admin_value[upload_tmp_dir] = /tmp

; نشست‌ها داخل خود پروژه ذخیره می‌شوند، نه در /var/lib/php/sessions که
; بین همه‌ی اپ‌های PHP سرور مشترک است. دو دلیل:
;   ۱. مسیر پیش‌فرض بیرون از open_basedir است و session_start شکست می‌خورد
;   ۲. جداسازی: کوکی نشست کاربران این اپ در قلمرو خودش می‌ماند
php_admin_value[session.save_path] = ${APP_DIR}/var/sessions
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
php_admin_value[upload_max_filesize] = 12M
php_admin_value[post_max_size] = 14M
php_admin_value[memory_limit] = 192M
php_admin_flag[expose_php] = off
php_admin_value[date.timezone] = Asia/Tehran
POOL

# ---------- ۶. سایت nginx ----------
STAGING_NGINX=''
if [[ $STAGING -eq 1 ]]; then
    # ⛔ «satisfy any» + allow 127.0.0.1: رمز برای همه جز خودِ سرور.
    #    `hesabland staging` سلامت را از 127.0.0.1 می‌سنجد (--resolve)؛ بدونِ
    #    این استثنا هر سنجش ۴۰۱ می‌گرفت و «سایت خراب است» می‌گفت. پشتِ
    #    کلادفلر هم کسی نمی‌تواند 127.0.0.1 باشد.
    # ⚠ چالشِ certbot (/.well-known/acme-challenge) با `return` جواب داده
    #   می‌شود که در فازِ rewrite و پیش از رمز اجرا می‌شود — گواهی می‌گیرد.
    STAGING_NGINX="
    # ⛔ نسخه‌ی آزمایشی: رمز جلوی همه، ایندکس نشود.
    satisfy any;
    allow 127.0.0.1;
    allow ::1;
    deny all;
    auth_basic \"HesabLand staging\";
    auth_basic_user_file /etc/nginx/${SITE_NAME}.htpasswd;
    add_header X-Robots-Tag \"noindex, nofollow\" always;
"
fi
step "۶. سایت nginx"
if [[ $SKIP_NGINX -eq 1 ]]; then
    info "رد شد — سایت گواهی‌دار موجود دست‌نخورده ماند."
else
info "فقط یک server block تازه برای $DOMAIN اضافه می‌شود."
write_file "/etc/nginx/sites-available/${SITE_NAME}" <<NGINX
# حساب لند — این فایل فقط به همین پروژه مربوط است.
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${APP_DIR};
    index index.php;

    # پس از گرفتن گواهی، certbot خودش این بلاک را به HTTPS ارتقا می‌دهد:
    #   sudo certbot --nginx -d ${DOMAIN}

    gzip on;
    gzip_vary on;
    gzip_min_length 512;
    gzip_comp_level 5;
    gzip_types text/plain text/css text/javascript application/javascript
               application/json application/manifest+json image/svg+xml;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    # ⛔ Content-Security-Policy — تا امروز اصلاً وجود نداشت.
    #
    # این اپ برای CSP وضعِ خوبی دارد: Chart.js و فونت در خودِ مخزن‌اند و
    # هیچ CDN ای در کار نیست (همه‌ی میزبان‌های بیرونی — کاوه‌نگار،
    # ملی‌پیامک، sms.ir — سمتِ **سرور** صدا زده می‌شوند، نه از مرورگر).
    # پس \`default-src 'self'\` هیچ چیزی را نمی‌شکند.
    #
    # ⚠ \`'unsafe-inline'\` عمدی است و حدِ این کار را صادقانه می‌گوید:
    #   ده‌ها \`<script>\` درون‌صفحه‌ای در قالب‌ها هست (نمودارها شنونده‌شان
    #   را همان‌جا ثبت می‌کنند، به دلیلی که کنارِ \`registerThemedChart\`
    #   نوشته شده). برداشتنش یعنی بازنویسیِ همه‌ی آن‌ها با nonce، و یک
    #   قابلیتِ نیمه‌کار که صفحه‌ها را بی‌صدا از کار بیندازد از نبودنش
    #   بدتر است. همین حالا هم \`object-src 'none'\` و \`base-uri 'self'\`
    #   و \`form-action 'self'\` سه بردارِ واقعی را می‌بندند.
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'" always;

    client_max_body_size 12M;
${STAGING_NGINX}
    # ---- api/v1 : آدرس تمیز برای اپ‌های موبایل ----
    # بدون این قاعده، اپ باید /api/v1/index.php/... صدا بزند (که کار
    # می‌کند و عمداً هم پشتیبانی می‌شود). با آن، /api/v1/transactions.
    #
    # ⛔ اینجا هرگز ^~ نگذارید. ^~ ارزیابیِ location های regex را متوقف
    # می‌کند — از جمله \`location ~ \.php$\` — پس nginx فایل PHP را به‌جای
    # اجرا، به‌صورت فایل ثابت تحویل می‌دهد و **سورس لو می‌رود**. یک بار
    # همین اتفاق روی سرور افتاد: /api/v1/ping متنِ خامِ index.php را داد.
    # با prefix ساده، regex ها ارزیابی می‌شوند و .php به fastcgi می‌رسد.
    location /api/v1/ {
        try_files \$uri \$uri/ /api/v1/index.php\$is_args\$args;
    }

    # سرویس‌ورکر باید پیش از قاعده‌ی عمومیِ .js بیاید و هرگز کش نشود.
    # قاعده‌ی پایین «یک سال، immutable» می‌دهد که برای فایل‌های نسخه‌دار
    # درست است ولی برای sw.js یعنی نسخه‌ی تازه ممکن است هرگز نرسد و
    # مرورگر تا مدت‌ها با سرویس‌ورکر قدیمی کار کند.
    # location = دقیق است و بر همه‌ی regex ها اولویت دارد.
    location = /sw.js {
        add_header Cache-Control "no-cache";
        try_files \$uri =404;
    }

    location ~* \.(css|js|woff2?|png|jpe?g|webp|svg|ico)\$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files \$uri =404;
    }

    location ^~ /uploads/ {
        location ~ \.php\$ { deny all; }
        expires 30d;
        add_header Cache-Control "private";
    }

    # deploy/ ابزار خط فرمان دارد (از جمله بازنشانی رمز) — از وب مسدود
    location ^~ /deploy/ { deny all; return 404; }
    location ^~ /tests/  { deny all; return 404; }
    # ⛔ var/ لاگِ JSONِ روزانه (نامش قابلِ حدس است: web-YYYY-MM-DD.log)،
    # فایل‌های نشست، کلیدِ VAPIDِ اعلانِ گوشی و نشانه‌های cron را دارد.
    # بدونِ این خط همه‌شان از وب دانلودشدنی بودند (nginx-var.sh).
    location ^~ /var/    { deny all; return 404; }
    # mobile/ سورس اپ فلاتر است. deploy.sh کل مخزن را روی سرور می‌ریزد،
    # پس این پوشه هم آنجاست؛ سروِ شدنش از وب فقط پهنای باند می‌برد و
    # ساختار داخلی اپ را بی‌دلیل نشان می‌دهد.
    location ^~ /mobile/ { deny all; return 404; }

    location ~ ^/(config|includes|\.git)/ { deny all; return 404; }
    location ~ /\.(?!well-known)  { deny all; return 404; }
    location ~ \.(sql|md|sh)\$    { deny all; return 404; }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-${SITE_NAME}.sock;
        fastcgi_read_timeout 60;
    }

    location / { try_files \$uri \$uri/ =404; }
}
NGINX

if [[ $STAGING -eq 1 ]]; then
    HTPASS="/etc/nginx/${SITE_NAME}.htpasswd"
    assert_allowed "$HTPASS"
    if [[ $APPLY -eq 1 && ! -s "$HTPASS" ]]; then
        # ⛔ رمز فقط حروفِ کوچک و رقم است و نامِ کاربری با «H»ِ بزرگ هم پذیرفته
        #    می‌شود: کادرِ ورودِ سافاری روی آیفون حرفِ اول را خودش بزرگ می‌کند، پس
        #    «hesab» به «Hesab» تبدیل می‌شد و رمزِ درست هم رد می‌شد (اولین ورودِ
        #    واقعی همین بود). حروفِ شبیه‌به‌هم (l، o، 0، 1) هم عمداً نیستند.
        STG_WEB_PASS="$(head -c 64 /dev/urandom | tr -dc 'a-km-np-z2-9' | head -c 10)"
        __h="$(openssl passwd -apr1 "$STG_WEB_PASS")"
        printf 'hesab:%s\nHesab:%s\n' "$__h" "$__h" > "$HTPASS"
        chown root:www-data "$HTPASS"; chmod 640 "$HTPASS"
        green "  رمزِ ورودِ staging ساخته شد (پایین چاپ می‌شود)."
    elif [[ $APPLY -eq 0 ]]; then
        printf '  \033[0;90m→ می‌نویسد: %s  (نام: hesab، رمزِ تصادفی)\033[0m\n' "$HTPASS"
    fi
fi

assert_allowed "/etc/nginx/sites-enabled/${SITE_NAME}"
run "ln -sfn '/etc/nginx/sites-available/${SITE_NAME}' '/etc/nginx/sites-enabled/${SITE_NAME}'"
fi

# ---------- ۷. اعمال ----------
step "۷. اعمال تغییرات"
info "reload می‌کنیم نه restart — سرویس‌های در حال کار قطع نمی‌شوند."
run "nginx -t"
run "systemctl reload nginx"
# ⛔ php-fpm هم مثلِ nginx پیش از reload سنجیده می‌شود: reloadِ pool خراب
#    کلِ آن php-fpm را می‌خواباند — که شاید سایت‌های دیگرِ این سرور هم روی
#    آن باشند. با set -e شکستِ سنجش همین‌جا می‌ایستد و reload نمی‌شود.
run "php-fpm${PHP_VER} -t 2>/dev/null || /usr/sbin/php-fpm${PHP_VER} -t"
run "systemctl reload php${PHP_VER}-fpm"

# ---------- ۸. باقی‌مانده ----------
step "۸. کارهایی که خودتان باید انجام دهید"
if [[ $STAGING -eq 1 ]]; then
cat <<NEXT

  ۱. در کلادفلر یک رکورد A بسازید:  ${DOMAIN%%.*}  →  آی‌پیِ همین سرور
     (مثلِ سایتِ اصلی، ابرِ نارنجی و SSL روی Full (strict)).

  ۲. گواهی:
       sudo certbot --nginx -d $DOMAIN

  ۳. اولین استقرار (ساختارِ دیتابیس هم ساخته می‌شود):
       cd $PROD_DIR && sudo ./hesabland staging

  ۴. https://$DOMAIN/setup.php را باز کنید و یک مدیر بسازید.

  نام کاربریِ صفحه‌ی رمز: hesab
  رمز:                   ${STG_WEB_PASS:-(از قبل ساخته شده — برای رمزِ تازه: sudo ./hesabland staging --password)}

NEXT
else
cat <<NEXT

  ۱. تنظیمات:
       sudo -u $APP_USER cp $APP_DIR/config/config.example.php $APP_DIR/config/config.php
       sudo nano $APP_DIR/config/config.php
     مقادیر: DB_NAME=$DB_NAME  DB_USER=$DB_USER  DB_PASSWORD=…
             APP_BASE_PATH=''   APP_FORCE_HTTPS=true
             APP_SECRET_KEY را با این عوض کنید:  $(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' 2>/dev/null || echo 'openssl rand -hex 32')
       sudo chown root:$APP_USER $APP_DIR/config/config.php
       sudo chmod 640 $APP_DIR/config/config.php

  ۲. انتقال داده از هاست فعلی — بخش «انتقال از هاست اشتراکی» در DEPLOY.md

  ۳. گواهی SSL:
       sudo certbot --nginx -d $DOMAIN

  ۴. آزمایش: آدرس https://$DOMAIN را باز کنید.

NEXT
fi

if [[ $APPLY -eq 0 ]]; then
    printf '\033[1;33mهیچ تغییری اعمال نشد (حالت نمایشی).\033[0m\n\n'
else
    green "✅ انجام شد."
fi
