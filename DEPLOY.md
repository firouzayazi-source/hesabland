# راه‌اندازی روی VPS

سروری که این پروژه رویش می‌نشیند، از قبل سرویس‌های دیگری دارد (دو ربات).
تمام این راهنما بر یک اصل بنا شده:

> **حساب لند کاملاً مستقل است و هیچ‌چیز آن نباید به سرویس‌های دیگر سرور دست بزند.**

---

## قانون جداسازی

این پروژه فقط اجازه‌ی این منابع را دارد:

| منبع | مقدار |
|---|---|
| پوشه | `/opt/hesab/app` |
| کاربر سیستمی | `hesab` (بدون shell) — فقط مالک `uploads/` |
| سایت nginx | `/etc/nginx/sites-{available,enabled}/hesab` |
| pool مربوط به PHP | `/etc/php/<نسخه>/fpm/pool.d/hesab.conf` |
| سوکت PHP | `/run/php/php-hesab.sock` |
| پوشه بکاپ | `/opt/hesab/backups` (بیرون از ریشه وب) |
| زمان‌بندی بکاپ | `/etc/cron.d/hesab-backup` |
| دیتابیس | `hesab_db` |
| کاربر دیتابیس | `'hesab_user'@'localhost'` — فقط با دسترسی روی `hesab_db.*` |

و اجازه‌ی این‌ها را **ندارد**:

- دست زدن به هر سایت nginx دیگر، یا به `/etc/nginx/nginx.conf`
- دست زدن به هر سرویس systemd دیگر — نه stop، نه restart، نه disable
- دست زدن به `php.ini` سراسری یا pool های دیگر
- خواندن یا نوشتن در پوشه‌ی ربات‌ها
- دسترسی به دیتابیس ربات‌ها (`GRANT` عمداً روی `hesab.*` است، نه `*.*`)
- تغییر قوانین فایروال موجود

سه لایه این قانون را عملاً تضمین می‌کنند:

1. **`deploy/vps-setup.sh`** یک نگهبان مسیر دارد؛ هر نوشتن خارج از فهرست بالا اسکریپت را کامل متوقف می‌کند.
2. **pool اختصاصی PHP** با `open_basedir` محدود به `/opt/hesab/app` است — کد PHP این اپ حتی اگر بخواهد هم نمی‌تواند فایل‌های ربات‌ها را بخواند. `exec`, `shell_exec`, `system` و مشابه‌ها هم غیرفعال‌اند.
3. **کاربر جدا** — PHP این اپ با کاربر `hesab` اجرا می‌شود، نه `www-data` و نه کاربر ربات‌ها.

هیچ‌جای این راهنما `systemctl restart` روی سرویس مشترک نیست؛ فقط `reload` آن هم بعد از `nginx -t` موفق.

---

## Cloudflare — قبل از شروع بخوانید

DNS این پروژه روی Cloudflare است. ترتیب کار مهم است، وگرنه مرحله‌ی گواهی SSL شکست می‌خورد.

### رکورد DNS

در پنل Cloudflare، در بخش DNS یک رکورد بسازید:

| نوع | Name | Content | Proxy |
|---|---|---|---|
| `A` | `hesab` | `43.225.90.204` | **DNS only (ابر خاکستری)** |

**در شروع حتماً ابر را خاکستری بگذارید، نه نارنجی.** دلیلش این است که `certbot` برای صدور گواهی باید مستقیم به سرور شما وصل شود؛ با پروکسی روشن این مرحله ناپایدار می‌شود و معمولاً شکست می‌خورد.

بررسی پخش‌شدن رکورد (از کامپیوتر خودتان، نه سرور):

```bash
dig +short hesab.stland.ir
# باید 43.225.90.204 برگرداند — نه آی‌پی‌های Cloudflare
```

با ابر خاکستری معمولاً کمتر از یک دقیقه طول می‌کشد.

### بعد از اینکه سایت با HTTPS بالا آمد

حالا اگر خواستید می‌توانید پروکسی را روشن کنید (ابر نارنجی). سودش: آی‌پی سرور پنهان می‌شود و جلوی حملات ساده گرفته می‌شود. شرطش این دو تنظیم است:

1. **SSL/TLS → Overview → حالت را روی `Full (strict)` بگذارید.**
   - `Flexible` را **هرگز** انتخاب نکنید: در آن حالت مسیر بین Cloudflare و سرور شما رمزنگاری‌نشده است، یعنی داده‌ی مالی و کوکی نشست به‌صورت متن ساده روی اینترنت می‌رود.
   - `Full (strict)` گواهی Let's Encrypt سرور را تأیید می‌کند — همان که در مرحله‌ی ۴.۵ گرفته‌اید.
2. **تمدید خودکار گواهی:** بعد از روشن‌کردن پروکسی، `certbot renew` (هر ۶۰ روز) باید همچنان بتواند از مسیر `/.well-known/acme-challenge/` رد شود. بعد از اولین روشن‌کردن پروکسی، یک بار این را اجرا کنید تا مطمئن شوید:

   ```bash
   certbot renew --dry-run
   ```

   اگر شکست خورد، ساده‌ترین راه این است که ابر را دوباره خاکستری کنید. سایت بدون پروکسی هم کاملاً سالم کار می‌کند.

### نکاتی که با Cloudflare فرق نمی‌کنند

- محدودیت حجم آپلود Cloudflare در پلن رایگان ۱۰۰ مگابایت است؛ این اپ حداکثر ۱۲ مگابایت آپلود می‌کند، پس مشکلی نیست.
- کد این اپ `REMOTE_ADDR` را نمی‌خواند، پس تغییر آی‌پی توسط Cloudflare هیچ قابلیتی را نمی‌شکند.
- `APP_FORCE_HTTPS` فقط پرچم `secure` کوکی‌ها را تنظیم می‌کند و ریدایرکت نمی‌زند؛ ریدایرکت ۸۰→۴۴۳ را خود nginx (با certbot) انجام می‌دهد.

### یک ملاحظه

با پروکسی روشن، Cloudflare خودش TLS را باز می‌کند و محتوای رد و بدل شده را می‌بیند. برای یک اپ مالی شخصی این تصمیم خودتان است. با ابر خاکستری، ترافیک مستقیم بین مرورگر شما و سرور خودتان است و کسی وسط نیست.

---

## اتصال دائمی به مخزن خصوصی (Deploy Key)

مخزن خصوصی است، پس سرور باید بتواند بدون پرسیدن رمز `git pull` بزند.

**چرا Deploy Key و نه Personal Access Token:** کلید فقط به همین یک مخزن دسترسی دارد (نه به کل حساب گیت‌هاب و نه به مخزن ربات‌ها)، فقط‌خواندنی است، و منقضی نمی‌شود.

### رعایت جداسازی

`~/.ssh/config` و `~/.ssh/known_hosts` فایل‌های مشترک سرورند و سرویس‌های دیگر هم از آن‌ها استفاده می‌کنند، پس به هیچ‌کدام دست نمی‌زنیم:

| چه چیزی | کجا |
|---|---|
| کلید خصوصی | `/root/.ssh/hesab_deploy` |
| کلید میزبان گیت‌هاب | `/root/.ssh/hesab_known_hosts` |
| تنظیم گیت | `core.sshCommand` داخل `.git/config` خودِ مخزن |

چون تنظیم داخل `.git/config` خود پروژه می‌نشیند، `git pull` و `deploy.sh` بدون هیچ پرسشی کار می‌کنند و گیتِ بقیه‌ی سرویس‌ها اصلاً خبردار نمی‌شود.

### مرحله ۱ — ساخت کلید

```bash
ssh-keygen -t ed25519 -N '' -C "hesab-deploy@$(hostname)" -f /root/.ssh/hesab_deploy
ssh-keyscan -t rsa,ecdsa,ed25519 github.com > /root/.ssh/hesab_known_hosts
cat /root/.ssh/hesab_deploy.pub
```

اثر انگشت کلید میزبان را با [فهرست رسمی گیت‌هاب](https://docs.github.com/authentication/keeping-your-account-secure/githubs-ssh-key-fingerprints) مقایسه کنید:

```bash
ssh-keygen -lf /root/.ssh/hesab_known_hosts
```

### مرحله ۲ — ثبت در گیت‌هاب

خروجی `cat` را کامل کپی کنید و در `Settings → Deploy keys → Add deploy key` مخزن ثبت کنید.

**تیک `Allow write access` را نزنید.** سرور فقط باید بخواند.

### مرحله ۳ — کلون

```bash
export GIT_SSH_COMMAND="ssh -i /root/.ssh/hesab_deploy -o IdentitiesOnly=yes -o UserKnownHostsFile=/root/.ssh/hesab_known_hosts"
git clone git@github.com:firouzayazi-source/sthesab.git /opt/hesab/app
git -C /opt/hesab/app config core.sshCommand "$GIT_SSH_COMMAND"
```

خط آخر مهم است: بدون آن، `git pull` های بعدی کلید را پیدا نمی‌کنند.

از این به بعد در `/opt/hesab/app` فقط `git pull` کافی است.

اگر بعداً خواستید همین کار را دوباره انجام دهید (مثلاً سرور تازه)، اسکریپت `deploy/setup-deploy-key.sh` همه‌ی این مراحل را با هم انجام می‌دهد.

---

## مرحله ۰ — گزارش وضعیت سرور (اجباری، فقط خواندنی)

پیش از هر کاری:

```bash
bash deploy/vps-preflight.sh
```

این اسکریپت هیچ چیزی نصب/تغییر/حذف نمی‌کند. فقط گزارش می‌دهد: نسخه‌ی سیستم، پورت‌های اشغال، سایت‌های nginx موجود، pool های PHP، دیتابیس‌های موجود، و سرویس‌های در حال اجرا.

**مهم‌ترین چیزی که باید ببینید: پورت ۸۰ و ۴۴۳ دست کیست.**
اگر یکی از ربات‌ها مستقیم روی پورت ۸۰ webhook دارد، nginx نمی‌تواند آن را بگیرد و باید اول تکلیف آن روشن شود. `vps-setup.sh` هم خودش همین را بررسی می‌کند و اگر پورت دست چیزی غیر از nginx باشد، متوقف می‌شود و ربات را دست نمی‌زند.

---

## مرحله ۱ — پیش‌نیازها

فقط چیزهایی را نصب کنید که preflight گفت نیست:

```bash
sudo apt update
sudo apt install -y nginx mariadb-server git \
     php8.3-fpm php8.3-mysql php8.3-gd php8.3-zip php8.3-mbstring php8.3-curl
```

> نصب پکیج تازه به ربات‌ها کاری ندارد. اما اگر `nginx` از قبل نصب است، دوباره نصبش نکنید.
> `php-gd` برای کوچک‌سازی عکس پروفایل لازم است.

---

## مرحله ۲ — مخزن

بخش «اتصال دائمی به مخزن خصوصی» بالا را انجام دهید. نتیجه‌اش این است که کد در `/opt/hesab/app` نشسته و `git pull` بدون رمز کار می‌کند.

> `config/config.php` و `uploads/` به‌خاطر `.gitignore` وارد گیت نمی‌شوند — عمدی است.

## مرحله ۳ — نصب

اول در حالت نمایشی، تا ببینید دقیقاً چه می‌خواهد بکند:

```bash
bash deploy/vps-setup.sh --domain hesab.stland.ir
```

خروجی را کامل بخوانید. هر خط خاکستری یعنی «این دستور اجرا می‌شود». وقتی راضی بودید:

```bash
sudo bash deploy/vps-setup.sh --domain hesab.stland.ir --apply
```

اسکریپت این‌ها را انجام می‌دهد: کاربر `hesab`، دسترسی فایل‌ها، pool اختصاصی PHP، سایت nginx، و `reload`. دیتابیس را عمداً خودش نمی‌سازد — دستورهایش را چاپ می‌کند تا خودتان با رمز دلخواه اجرا کنید.

### دیتابیس

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`hesab_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'hesab_user'@'localhost' IDENTIFIED BY 'یک-رمز-قوی';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`hesab_db\`.* TO 'hesab_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

`GRANT` روی `hesab_db.*` است و نه `*.*` — یعنی این کاربر حتی اگر لو برود، به دیتابیس ربات‌ها دسترسی ندارد.

### تنظیمات

```bash
sudo cp /opt/hesab/app/config/config.example.php /opt/hesab/app/config/config.php
sudo nano /opt/hesab/app/config/config.php
```

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'hesab_db');
define('DB_USER', 'hesab_user');
define('DB_PASSWORD', 'همان رمز قوی');
define('APP_BASE_PATH', '');        // روی ریشه‌ی دامنه است، پس خالی
define('APP_FORCE_HTTPS', true);
define('APP_SECRET_KEY', '...');    // با openssl rand -hex 32 بسازید
```

```bash
sudo chown hesab:hesab /opt/hesab/app/config/config.php
sudo chmod 640 /opt/hesab/app/config/config.php
```

---

## مرحله ۴ — انتقال داده از هاست اشتراکی

این مرحله را **پیش از تغییر DNS** انجام دهید تا سایت جدید را کامل تست کنید و بعد سوییچ کنید.

### ۴.۱ دیتابیس

از phpMyAdmin هاست فعلی: `Export` → فرمت `SQL` → کل دیتابیس. سپس روی سرور:

```bash
mysql -u hesab_user -p hesab_db < backup.sql
```

بعد بررسی کنید همه‌چیز آمده:

```bash
mysql -u hesab_user -p hesab_db -e "SELECT COUNT(*) AS tx FROM transactions; SELECT COUNT(*) AS users FROM users;"
```

عدد تراکنش‌ها باید با چیزی که در هاست فعلی می‌بینید یکی باشد.

### ۴.۲ فایل‌های آپلودشده

پوشه‌ی `uploads/` (رسیدها و عکس‌های پروفایل) در گیت نیست و باید دستی منتقل شود.
از هاست دانلودش کنید (FTP یا File Manager → فشرده کنید و بگیرید)، بعد:

```bash
sudo -u hesab mkdir -p /opt/hesab/app/uploads/avatars
sudo unzip uploads.zip -d /opt/hesab/app/
sudo chown -R hesab:hesab /opt/hesab/app/uploads
sudo chmod -R 755 /opt/hesab/app/uploads
```

### ۴.۳ تکمیل ساختار دیتابیس

دیتابیس هاست اشتراکی ممکن است از کد فعلی عقب‌تر باشد. بعد از ایمپورت بکاپ:

```bash
cd /opt/hesab/app
bash deploy/migrate.sh              # وضعیت را نشان می‌دهد، چیزی اجرا نمی‌کند
```

اگر گفت «این دیتابیس از قبل جدول‌های اپ را دارد ولی هیچ migration ثبت نشده»،
یعنی ساختار از هاست آمده و کامل است. یک بار این را بزنید:

```bash
bash deploy/migrate.sh --baseline   # همه را «اجراشده» علامت می‌زند، بدون اجرا
```

از این پس فقط `bash deploy/migrate.sh --apply` لازم است و فقط migration های
تازه اجرا می‌شوند.

### ۴.۴ تست پیش از سوییچ DNS

روی کامپیوتر خودتان (نه سرور) در فایل hosts یک خط اضافه کنید تا فقط برای خودتان دامنه به سرور جدید اشاره کند:

```
IP_SERVER    hesab.stland.ir
```

سایت را باز کنید، وارد شوید، چند تراکنش و گزارش را چک کنید. وقتی مطمئن شدید، خط را بردارید و DNS واقعی را عوض کنید.

### ۴.۵ گواهی SSL

فقط وقتی `dig +short hesab.stland.ir` آی‌پی سرور را برگرداند (نه آی‌پی Cloudflare):

```bash
sudo certbot --nginx -d hesab.stland.ir
```

certbot فقط سایت `hesab` را تغییر می‌دهد و ریدایرکت ۸۰→۴۴۳ را خودش اضافه می‌کند؛ به سایت‌های دیگر nginx کاری ندارد.

> پس از گرفتن گواهی، فایل سایت را certbot در اختیار گرفته است. `vps-setup.sh` این را تشخیص می‌دهد (با دیدن `ssl_certificate`) و دیگر آن را بازنویسی نمی‌کند — وگرنه اجرای دوباره‌اش گواهی را از پیکربندی حذف می‌کرد و سایت از HTTPS می‌افتاد. اگر روزی عمداً خواستید سایت از نو ساخته شود، اول `mv /etc/nginx/sites-available/hesab{,.bak}` و بعد از اجرای اسکریپت، `certbot` را دوباره بزنید.

بعد از آن، `APP_FORCE_HTTPS` را در `config/config.php` روی `true` بگذارید تا کوکی‌ها فقط روی HTTPS بروند.

## کار روزمره

**روی کامپیوتر/گوشی:**

```bash
git add .
git commit -m "توضیح تغییر"
git push
```

**روی سرور:**

```bash
cd /opt/hesab/app && ./deploy.sh
```

`deploy.sh` آخرین نسخه را می‌گیرد، دسترسی‌ها را درست می‌کند، pool مربوط به PHP را reload می‌کند و اگر migration جدیدی بود هشدار می‌دهد. `config/config.php` و `uploads/` را دست نمی‌زند.

### با نسخه‌ی آزمایشی (staging) — روالِ پیشنهادی

وقتی staging ساخته شده باشد، هیچ چیزی بی‌آنکه دیده شود روی سایتِ اصلی نمی‌رود:

```bash
cd /opt/hesab/app
sudo ./hesabland staging     # ۱. کدِ تازه روی staging.hesab.stland.ir
                              #    (روی گوشی باز کنید؛ بالای صفحه «نسخه‌ی آزمایشی · abc1234»)
sudo ./hesabland release     # ۲. اگر درست بود: دقیقاً همان abc1234 روی سایتِ اصلی
```

- `release` فقط **همان کامیتی** را منتشر می‌کند که آخرین بار روی staging سبز شد. کامیت‌های تازه‌تری که هنوز در staging دیده نشده‌اند منتشر نمی‌شوند و صریح گفته می‌شوند.
- migration ای که در staging اعمال شده، در `release` هم (بعد از بکاپ) اعمال می‌شود.
- `sudo ./hesabland` ی معمولی هم کار می‌کند، ولی اگر نسخه‌اش در staging دیده نشده باشد اول می‌پرسد.
- `sudo ./hesabland status` می‌گوید staging و سایتِ اصلی هر کدام روی کدام نسخه‌اند.

**فعلاً مستقیم روی سایتِ اصلی (staging محفوظ می‌ماند):**

```bash
cd /opt/hesab/app && sudo ./hesabland staging --pause
```

از آن به بعد `sudo ./hesabland` بی‌پرسش مستقیم روی سایتِ اصلی می‌رود. برگشت به روالِ staging:

```bash
cd /opt/hesab/app && sudo ./hesabland staging --resume
```

**ساختنِ staging (یک بار):**

```bash
cd /opt/hesab/app
sudo bash deploy/vps-setup.sh --staging --domain staging.hesab.stland.ir           # اول فقط نگاه کنید
sudo bash deploy/vps-setup.sh --staging --domain staging.hesab.stland.ir --apply   # بعد اجرا
```

بعدش سه کار: در کلادفلر رکوردِ A برای `staging.hesab` (ابرِ نارنجی، مثلِ سایتِ اصلی)، `sudo certbot --nginx -d staging.hesab.stland.ir`، و `sudo ./hesabland staging`. بعد `https://staging.hesab.stland.ir/setup.php` یک مدیر می‌سازد. رمزِ صفحه‌ی ورودِ staging (نام: `hesab`) در پایانِ `--apply` چاپ می‌شود؛ رمزِ تازه: `sudo ./hesabland staging --password`.

staging همه‌چیزش جداست: پوشه‌ی `/opt/hesab/staging`، کاربرِ `hesabstg`، دیتابیسِ `hesab_staging`، و ایمیل و پیامک و cron ندارد. برای آزمایش با داده‌ی واقعی: `sudo ./hesabland staging --clone-db` (آخرین بکاپِ سایتِ اصلی).

---

## اگر چیزی خراب شد

همه‌ی اثر این پروژه روی سرور، همان فهرست «قانون جداسازی» بالاست. برای برگرداندن کامل:

```bash
sudo rm -f /etc/nginx/sites-enabled/hesab /etc/nginx/sites-available/hesab
sudo rm -f /etc/php/*/fpm/pool.d/hesab.conf
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl reload php8.3-fpm
```

سرویس‌های دیگر سرور از این کار هیچ اثری نمی‌بینند.

---

## نکته درباره‌ی سرعت

جابه‌جایی به VPS **مشکل کپی دستی فایل را قطعاً حل می‌کند**، اما تضمینی نیست که تأخیر شبکه کم شود — آن به مسیر شبکه بین شما و دیتاسنتر بستگی دارد، نه به قدرت سرور. بعد از راه‌اندازی، زمان بارگذاری را با هاست فعلی مقایسه کنید.

قطعاً به دست می‌آورید: استقرار با یک دستور، کنترل کامل روی nginx و PHP (فشرده‌سازی و کش درست)، تاریخچه‌ی کامل تغییرات، و بدون محدودیت منابع اشتراکی.
در مقابل، نگهداری سرور (به‌روزرسانی امنیتی، پشتیبان‌گیری، فایروال) به عهده‌ی خودتان است.
