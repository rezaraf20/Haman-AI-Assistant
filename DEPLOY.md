# 🚀 Haman AI Platform — راهنمای نصب و راه‌اندازی
## Company: شرکت هامان فناوران پیشرو | Author: Reza Rafiei

---

## پیش‌نیازها (Requirements)

- Ubuntu 22.04 LTS (یا بالاتر)
- Docker Engine 24+
- Docker Compose v2+
- حداقل 4GB RAM
- دامنه با SSL (برای production)

---

## قدم ۱ — نصب Docker

```bash
curl -fsSL https://get.docker.com | bash
sudo usermod -aG docker $USER
newgrp docker
docker --version
```

---

## قدم ۲ — آپلود پروژه روی سرور

```bash
# از local به سرور
scp -r haman-platform/ user@YOUR_SERVER_IP:/opt/haman/
ssh user@YOUR_SERVER_IP
cd /opt/haman/haman-platform
```

---

## قدم ۳ — تنظیم Environment Variables

```bash
# فایل root .env را ویرایش کنید
nano .env
# DB_PASSWORD و REDIS_PASSWORD را تغییر دهید

# فایل Laravel .env را ویرایش کنید
nano laravel-backend/.env
# این مقادیر را حتماً تغییر دهید:
# APP_KEY — در قدم ۵ ساخته می‌شود
# AI_SERVICE_SECRET — باید با python-ai-service/.env یکسان باشد
# OPENAI_API_KEY — کلید OpenAI خود را وارد کنید
# DB_PASSWORD — باید با root .env یکسان باشد
# REDIS_PASSWORD — باید با root .env یکسان باشد

# فایل Python .env را ویرایش کنید
nano python-ai-service/.env
# OPENAI_API_KEY، INTERNAL_SECRET، DATABASE_URL و REDIS_URL را تنظیم کنید
```

---

## قدم ۴ — ساخت و اجرا

```bash
# Build همه services
docker compose build --no-cache

# اجرا در background
docker compose up -d

# بررسی وضعیت
docker compose ps
docker compose logs -f --tail=50
```

---

## قدم ۵ — تنظیمات Laravel

```bash
# Generate APP_KEY
docker compose exec laravel php artisan key:generate

# اجرای migrations
docker compose exec laravel php artisan migrate --force

# Seed initial data (plans)
docker compose exec laravel php artisan db:seed --force

# Cache configs
docker compose exec laravel php artisan config:cache
docker compose exec laravel php artisan route:cache

# تست health check
curl http://localhost/health
```

---

## قدم ۶ — تست کامل سیستم

```bash
# ۱. تست Laravel API
curl http://localhost/api/health

# ۲. تست Python AI Service
curl http://python_ai:8001/ai/health
# یا از خارج:
docker compose exec python_ai curl http://localhost:8001/ai/health

# ۳. ثبت‌نام tenant اول
curl -X POST http://localhost/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Business",
    "email": "admin@test.com",
    "password": "Password123!",
    "password_confirmation": "Password123!"
  }'
# → پاسخ شامل api_key است — آن را ذخیره کنید!

# ۴. ساخت chatbot
curl -X POST http://localhost/api/v1/chatbots \
  -H "Authorization: Bearer TOKEN_FROM_LOGIN" \
  -H "Content-Type: application/json" \
  -d '{"name":"My AI Bot","type":"support","welcome_message":"Hello! How can I help?"}'
```

---

## قدم ۷ — نصب WordPress Plugin

```bash
# پوشه plugin را در WordPress کپی کنید
cp -r wordpress-plugin/haman-ai-chatbot/ /path/to/wordpress/wp-content/plugins/

# یا ZIP کنید و از پنل WordPress آپلود کنید
cd wordpress-plugin
zip -r haman-ai-chatbot.zip haman-ai-chatbot/
```

سپس در WordPress:
1. **Plugins → Activate** → Haman AI Chatbot
2. **Haman AI → Settings**:
   - API Key: کلیدی که در قدم ۶ دریافت کردید
   - Chatbot ID: UUID چت‌بات ساخته‌شده
   - Webhook Secret: از داشبورد Haman
3. **Run Full Sync Now** را بزنید

---

## قدم ۸ — تنظیم دامنه و SSL

```bash
# نصب Certbot
apt install certbot python3-certbot-nginx -y

# دریافت SSL
certbot --nginx -d api.yourdomain.com

# ویرایش nginx config
nano nginx/conf.d/api.conf
# server_name را به دامنه خود تغییر دهید
docker compose restart nginx
```

---

## نگهداری روزانه

```bash
# مشاهده logs
docker compose logs laravel   -f --tail=100
docker compose logs python_ai -f --tail=100
docker compose logs horizon   -f --tail=100

# Restart یک service
docker compose restart laravel

# Update پروژه
git pull
docker compose build laravel python_ai
docker compose up -d
docker compose exec laravel php artisan migrate --force
docker compose exec laravel php artisan config:cache
# ری‌استارت nginx لازم نیست. قبلاً با بازساخته‌شدن کانتینر laravel آی‌پی‌اش
# عوض می‌شد و nginx آدرس قدیمی را کش‌کرده نگه می‌داشت، و همه‌چیز ۵۰۲ می‌شد.
# حالا nginx مقصد را در هر درخواست دوباره resolve می‌کند (nginx/conf.d/api.conf:
# resolver + set $upstream_laravel) پس آی‌پی تازه خودبه‌خود پیدا می‌شود.

# Backup database — شبانه خودکار اجرا می‌شود (۰۲:۳۰) و ۰۳:۴۵ واقعاً بازیابی
# و بررسی می‌شود. این‌ها فقط برای اجرای دستی‌اند:
docker compose exec laravel php artisan haman:backup-database --kind=manual
docker compose exec laravel php artisan haman:verify-backup
```

---

## مشکلات رایج

| مشکل | راه‌حل |
|------|--------|
| Laravel 500 error | `docker compose exec laravel php artisan config:clear && php artisan cache:clear` |
| 502 روی همه‌ی مسیرها بعد از دیپلوی | دیگر نباید پیش بیاید — nginx در هر درخواست دوباره resolve می‌کند. اگر دیدید، `docker compose exec nginx nginx -t` و بررسی کنید `resolver 127.0.0.11` هنوز در `nginx/conf.d/api.conf` هست |
| AI service unavailable | `docker compose restart python_ai` + بررسی OPENAI_API_KEY |
| Migration failed | بررسی اتصال postgres: `docker compose exec postgres psql -U haman_user -d haman_saas -c '\l'` |
| Widget not showing | در WordPress: بررسی Chatbot ID + Domain whitelist در داشبورد |
| Embeddings stuck | `docker compose logs horizon` برای مشاهده خطای job |
| ایمیج postgres build نمی‌شود | دیگر build نمی‌شود و نباید بشود — از ایمیج رسمی `pgvector/pgvector` با digest ثابت استفاده می‌کنیم. اگر `docker compose up --build` روی postgres خطا داد، یعنی جایی `build:` برگشته؛ به docker-compose.yml نگاه کنید |

---

## Port های مورد استفاده

| Service | Port | توضیح |
|---------|------|-------|
| Nginx | 80, 443 | ورودی اصلی |
| Laravel FPM | 9000 | داخلی |
| Python AI | 8001 | داخلی |
| PostgreSQL | 5432 | دیتابیس |
| Redis | 6379 | صف و کش |

---

## تغییرات پرریسک (High-risk changes)

هر تغییری که «منبع اتصال» را عوض می‌کند — نام دیتابیس، نقش، رمز، آدرس Redis،
نام شبکه‌ی داکر — از این الگو پیروی کند. این از یک قطعی ده‌دقیقه‌ای واقعی
درآمده، نه از احتیاط تئوریک.

### ۱) اول بکاپ، و **تأیید restore**

```bash
docker compose exec laravel php artisan haman:backup-database
docker compose exec laravel php artisan haman:verify-backup
```

تا وقتی `VERIFIED:` را ندیده‌اید شروع نکنید. بکاپ تست‌نشده بکاپ نیست.

### ۲) ترتیب درست: **اول `.env`، بعد خود منبع**

این مهم‌ترین درس است و برعکسش سایت را پایین می‌آورد.

`docker-entrypoint.sh` قبل از هر چیز منتظر دیتابیس می‌ماند و **بعد** از آن
`config:cache` را اجرا می‌کند. یعنی حلقه‌ی انتظار با **کانفیگ کش‌شده‌ی قبلی**
کار می‌کند. اگر اسم دیتابیس را عوض کنید ولی `.env` هنوز اسم قدیم را داشته
باشد — یا برعکس، کش هنوز قدیمی باشد — کانتینر تا ابد
`Waiting for database...` چاپ می‌کند و nginx روی همه‌ی مسیرها ۵۰۲ می‌دهد.

ترتیب درست:

```bash
# ۱. .env ها را به مقدار جدید تغییر دهید (هنوز چیزی در دیتابیس عوض نشده)
# ۲. اپ را متوقف کنید، منبع را تغییر دهید
docker compose stop laravel horizon scheduler python_ai nginx
docker compose exec postgres psql -U <role> -d postgres -c "ALTER DATABASE ... RENAME TO ...;"
# ۳. کانتینرها را **بازسازی** کنید، نه فقط start
docker compose up -d --force-recreate laravel horizon scheduler python_ai
```

### ۳) `docker compose start` کافی نیست — `--force-recreate` لازم است

`start` همان کانتینر قبلی را با همان لایه‌ی نوشتنی برمی‌گرداند، و کش کانفیگ
در `bootstrap/cache` همان‌جاست. کانتینر دوباره با مقدار قدیمی بالا می‌آید.
`--force-recreate` کانتینر را از ایمیج می‌سازد، بدون هیچ کش قدیمی.

### ۴) راه برگشت

قبل از شروع بنویسید که دقیقاً چطور برمی‌گردید:

- **تغییر نام دیتابیس/نقش:** با همان دستور معکوس برگردد
  (`ALTER DATABASE haman_saas RENAME TO ...`)، بعد `.env` را به عقب برگردانید،
  بعد `--force-recreate`.
- **اگر خود دیتابیس آسیب دید:** بکاپ تأییدشده‌ی مرحله‌ی ۱ را restore کنید.
- **همیشه:** یک ترمینال باز نگه دارید که در آن دیتابیس هنوز در دسترس است،
  تا اگر اتصال شکست بتوانید تشخیص دهید مشکل از دیتابیس است یا از اپ.

### ۵) بعدش سلامت را واقعاً چک کنید

```bash
docker compose ps
for p in / /admin/login /portal/login; do curl -sS -o /dev/null -w "$p %{http_code}\n" https://api.arshanweb.ir$p; done
```

---

## چرا این پروژه عمداً زنجیره‌ی build فرانت‌اند ندارد

نه `package.json` دارد، نه `vite.config.js`، نه Tailwind. این انتخاب است نه
فراموشی.

پنل‌ها Filament هستند و اسم‌های خودشان را از پیش‌ساخته می‌آورند
(`php artisan filament:assets`). تنها صفحه‌ی عمومی، لندینگ پیج است که CSS
دستی با متغیرهای CSS، پشتیبانی RTL و layout ریسپانسیو دارد و در همان Blade
زندگی می‌کند.

افزودن Tailwind یعنی افزودن Node و npm و یک مرحله‌ی build به ایمیج
پروداکشن — برای یک صفحه. هزینه‌اش یک وابستگی جدید است که می‌تواند از کار
بیفتد؛ همان‌طور که ایمیج postgres از کار افتاد وقتی Alpine بسته‌های
`clang19` و `llvm19-dev` را حذف کرد و دیتابیس یک `docker system prune` با
غیرقابل‌بازسازی شدن فاصله داشت. هر toolchain که در مسیر build باشد همین
ریسک را دارد.

اگر روزی چند صفحه‌ی عمومی دیگر اضافه شد و CSS دستی واقعاً سنگین شد، این
تصمیم قابل بازبینی است. تا آن موقع، صفحه‌ای که کار می‌کند بدون زنجیره‌ی
build، بهتر از صفحه‌ای است که با آن کار می‌کند.

---

## چرا این سه اسم عمداً «Hamman» مانده‌اند

برند «Haman» با یک «م» است و کل کد در تاریخ ۲۰۲۶-۰۹-۱۵ تغییر نام داد. سه چیز
عمداً دست‌نخورده ماند، چون ریسکشان واقعی است و ارزششان صفر:

| چه چیزی | مقدار فعلی | چرا نه |
|---|---|---|
| نقش دیتابیس | `hamman_user` | هیچ مشتری و هیچ APIای این را نمی‌بیند. Postgres اجازه نمی‌دهد نقشی که با آن وصل شده‌اید تغییر نام دهد، پس یک superuser موقت لازم است؛ و rename رمز md5 را باطل می‌کند چون نام نقش نمکِ آن است. یعنی همان دسته‌ای از تغییر که بالا باعث قطعی شد. |
| پوشه‌ی سرور | `/opt/hamman-platform` | نام پروژه‌ی compose از نام پوشه می‌آید، و نام volumeها از نام پروژه. تغییرش یعنی `hamman-platform_postgres_data` دیگر پیدا نمی‌شود و یک volume **خالی** ساخته می‌شود. |
| نام volumeها | `hamman-platform_*` | همان دلیل بالا. کپی کردن volume دیتابیس پروداکشن فقط برای زیبایی اسم، معامله‌ی بدی است. |

چیزهایی که **تغییر کردند:** نام دیتابیس (`haman_saas`)، دیتابیس تست
(`haman_test`) و نقشش، همه‌ی کانتینرها (`haman_laravel` و بقیه)، شبکه
(`haman_net`)، رمز Redis، همه‌ی کامندهای artisan (`haman:*`)، و کل کد.

اسم قدیمی فقط در سه فایل سازگاری باقی است — `Haman_Legacy` در افزونه،
`PluginLegacy` در Laravel، `plugin_legacy` در سرویس پایتون — که کلیدهای
option و namespace نسخه‌ی ۱.x را تطبیق می‌دهند و هر سه `TODO(2027-03-01)`
دارند.

---

## اجرای تست‌ها روی سرور

`docker compose exec laravel php artisan test` را اجرا نکنید. آن دستور تست‌ها را روی
دیتابیس **واقعی** (`haman_saas`) اجرا می‌کند، نه روی دیتابیس تست. دو دلیل دارد:
`docker-entrypoint.sh` دستور `config:cache` را اجرا می‌کند و بعد از آن مقادیر `env()`
اصلاً خوانده نمی‌شوند؛ و حتی بدون کش، PHPUnit مقادیر `<env>` را در `$_ENV` می‌نویسد
درحالی‌که Laravel اول `$_SERVER` را می‌خواند و متغیرهای داکر آنجا هستند. چون این
مجموعه از `RefreshDatabase` استفاده می‌کند، یعنی خطر پاک شدن همه‌ی جدول‌ها.
(`tests/TestCase.php` حالا قبل از هر تست بررسی می‌کند نام دیتابیس به `_test` ختم شود
و در غیر این صورت اجرا نمی‌شود.)

روش درست — یک کانتینر یک‌بارمصرف با دیتابیس تست:

```bash
docker compose run --rm --no-deps \
  -e APP_ENV=testing \
  -e DB_DATABASE=haman_test -e DB_USERNAME=haman_test -e DB_PASSWORD=haman_test \
  -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array \
  --entrypoint sh laravel -c "php artisan config:clear >/dev/null 2>&1; php artisan test"
```

یک تست روی سرور همیشه رد می‌شود و ایراد کد نیست: `zarinpal reports configured once it
has a merchant id`. چون `ZARINPAL_MERCHANT_ID` واقعی در `.env` سرور هست، آن تنظیم از
قبل «پیکربندی‌شده» دیده می‌شود. در CI این متغیر وجود ندارد و تست سبز است.

---

## چهار دامنه، یک اپلیکیشن

```
hamanai.com        لندینگ و ثبت‌نام
app.hamanai.com    پنل مشتری و ادمین
api.hamanai.com    API و ویجت
api.arshanweb.ir   همان API — برای همیشه زنده، هرگز ریدایرکت نمی‌شود
```

هر چهار نام به یک اپلیکیشن می‌رسند. تفکیک با «مسیر» است نه با سرور؛ nginx داخل
داکر `server_name _` است و همه‌ی هاست‌ها را یکسان سرو می‌کند. مقادیر در
`config/haman.php` زیر کلید `domains` است.

**`api_public` عمداً از `api` جداست.** آدرسی که به مشتری داده می‌شود و در
options وردپرس هر سایتی ذخیره می‌شود، هنوز `api.arshanweb.ir` است. سایتی که
هرگز افزونه‌اش را به‌روز نکند تا همیشه با همان آدرس کار می‌کند. انتقال به
`api.hamanai.com` فقط بعد از چند ماه ترافیک واقعی، و با تغییر دو مقدار:
`haman.domains.api_public` اینجا و `HAMAN_API_BASE` در افزونه.

### تله‌ای که دو بار وقت گرفت: `<VirtualHost *:443>` روی این سرور کار نمی‌کند

این سرور DirectAdmin دارد و vhostهایش را به `128.140.54.86:443` بایند می‌کند،
نه به `*:443`. آپاچی همیشه vhostی را ترجیح می‌دهد که آدرسش دقیق‌تر است — مستقل
از ترتیب لود شدن فایل‌ها. بنابراین یک بلاک `*:443`:

- بدون خطا parse می‌شود،
- در `apachectl -S` دیده می‌شود،
- و **هیچ درخواستی را سرو نمی‌کند.**

`hamman-proxy.conf` از روز اول همین‌طور بوده. چیزی که واقعاً
`api.arshanweb.ir` را سرو می‌کند، vhost خود DirectAdmin است به‌علاوه‌ی یک
`RewriteRule ... [P]` داخل `.htaccess` در docroot. برای اثباتش به لاگ نگاه
کنید — درخواست‌ها در `/var/log/httpd/domains/arshanweb.ir.api.log` می‌افتند،
نه جای دیگر.

`deploy/apache/haman-domains.conf` به همین دلیل به IP بایند شده است.

### چرا اولین بلاک آن فایل کپی چیز دیگری است

اولین vhost هر «سطل آدرس»، همانی است که آپاچی برای درخواستی که هیچ Host ی را
match نمی‌کند استفاده می‌کند. فایل ما قبل از DirectAdmin لود می‌شود (خط ۱۶۳ در
برابر ۲۱۹)، پس اولین بلاکش این نقش را به ارث می‌برد — و روی این سرور حدود ۱۷
سایت مشتری دیگر هست. برای همین دو بلاک اول عیناً کپی
`httpd-vhosts.conf` هستند تا رفتار fallback ذره‌ای عوض نشود.
**هیچ بلاک hamanai.com را بالای آن دو نگذارید.**

### کوکی سشن: چرا `SESSION_DOMAIN` در `.env` ست نشده

ثبت‌نام روی `hamanai.com` انجام می‌شود و در پنل روی `app.hamanai.com` تمام
می‌شود. برای اینکه کاربر لاگین‌شده برسد، کوکی باید `Domain=.hamanai.com`
باشد.

اما `SESSION_DOMAIN` یک مقدار ثابت است و روی **همه‌ی** هاست‌ها اعمال می‌شود —
از جمله `api.arshanweb.ir` که دامنه‌ی ثبتی دیگری است و مرورگر کوکی را آنجا
دور می‌اندازد. نتیجه: سشن ساخته نمی‌شود، هر فرم CSRF دار ۴۱۹ می‌دهد، و هیچ
چیزی در لاگ نمی‌نویسد.

به همین دلیل این کار را میدل‌ور `ShareSessionAcrossBrandDomains` به‌صورت
per-request انجام می‌دهد. با `prepend` ثبت شده، نه `append` — چون
`StartSession` مقدار را موقع شروع سشن می‌خواند و هر جای دیرتری بی‌اثر است.

### زرین‌پال

آدرس callback هیچ‌جا ذخیره نشده؛ `route('payments.zarinpal.callback')` آن را
سر هر درخواست از روی Host می‌سازد. یعنی خودبه‌خود با هاست عوض می‌شود.
`verifyPayment` سرور-به-سرور است و اصلاً URL ندارد، پس تأیید پرداخت از این
تغییرات اثر نمی‌گیرد.

**چیزی که باید در پنل زرین‌پال باشد:** درگاه باید روی وب‌سایت `hamanai.com`
ثبت شود (زرین‌پال دامنه‌ی سایت را ثبت می‌کند نه فهرست URL؛ ساب‌دامنه‌هایش
پذیرفته می‌شوند). آدرس callback می‌شود
`https://app.hamanai.com/payments/zarinpal/callback`.

در حال حاضر `sandbox = true` است و هیچ پرداخت واقعی تا امروز کامل نشده
(هر سه تراکنش موجود sandbox و failed هستند). قبل از هر پرداخت واقعی باید
sandbox خاموش و merchant_id واقعی وارد شود.

### تمدید گواهی — چیزی که اول اشتباه تشخیص دادم

**تشخیص اول غلط بود.** نوشته بودم `.htaccess` مربوط به `api.arshanweb.ir`
چالش ACME را به لاراول می‌فرستد و تمدیدش خراب است. این‌طور نبود.

`httpd-alias.conf` یک Alias سراسری دارد:

```
Alias /.well-known/acme-challenge /var/www/html/.well-known/acme-challenge
```

این برای **همه‌ی** vhostها اجرا می‌شود و قبل از هر پروکسی‌ای در docroot،
مسیر چالش را به یک پوشه‌ی مرکزی می‌برد. DirectAdmin توکن هر دامنه را در همان
پوشه می‌نویسد. پس `api.arshanweb.ir` هیچ‌وقت خراب نبود — تست اولیه‌ی من غلط
بود، چون فایل آزمایشی را در docroot دامنه گذاشته بودم که Alias اصلاً از آن
رد می‌شود.

**چیزی که واقعاً خراب بود، کار خودم بود.** در `haman-domains.conf` نوشته
بودم:

```
Alias /.well-known/ /home/hamanai/domains/hamanai.com/public_html/.well-known/
```

یک Alias داخل vhost، Alias سراسری را برای آن vhost بی‌اثر می‌کند. یعنی برای
`hamanai.com` و دو زیردامنه‌اش، آپاچی دنبال توکن در جایی می‌گشت که هرگز
نوشته نمی‌شود. الان فقط استثنای پروکسی مانده و Alias سراسری کار خودش را
می‌کند:

```
ProxyPass /.well-known/acme-challenge !
```

روی پورت ۸۰ هم `RewriteCond` لازم است، چون mod_rewrite قبل از mod_alias
اجرا می‌شود و بدون آن ریدایرکت به https قبل از Alias شلیک می‌کند.

تأیید عملی: یک فایل در پوشه‌ی مرکزی گذاشته شد و هر پنج نام (چهار دامنه +
www) آن را با ۲۰۰ برگرداندند.

### تله‌ی دوم: تمدید انجام می‌شود ولی آپاچی گواهی قدیمی را سرو می‌کند

`letsencrypt.sh renew` گواهی جدید را روی دیسک می‌نویسد و آپاچی را reload
**نمی‌کند**. بعد از تمدید دستی، دیسک گواهی جدید داشت و سیم گواهی قدیمی:

```
disk: notAfter=Dec 17 2026
wire: notAfter=Nov 20 2026
```

برای همین `CertificateExpiry` گواهی را از روی **اتصال واقعی TLS** می‌خواند،
نه از فایل. چیزی که به بازدیدکننده می‌رسد تنها چیزی است که اهمیت دارد.

### مکانیزم صدور هر دامنه

| دامنه | اعتبارسنجی | چرا |
|---|---|---|
| `hamanai.com` و `*.hamanai.com` | dns-01 | wildcard است و LE برای wildcard فقط dns-01 می‌پذیرد |
| `api.arshanweb.ir` | http-01 | یک نام معمولی است |

زون `hamanai.com` روی همین سرور است (`/var/named/hamanai.com.db`) و
ns1/ns2.arshanweb.ir هر دو به همین IP اشاره می‌کنند، پس DirectAdmin
می‌تواند رکورد TXT را خودش بنویسد (خط ۶۰۳ در `letsencrypt.sh`).

### برگشت

بکاپ هر تغییر دامنه در `/root/backups/domains-<timestamp>/` است و
`ROLLBACK.sh` کنارش. هر دو فایل `.env`، `hamman-proxy.conf` و
`httpd-includes.conf` را برمی‌گرداند، vhost جدید را حذف می‌کند، قبل از reload
ـ `configtest` می‌زند، و کانتینرها را با `--force-recreate` بالا می‌آورد —
نه `start`.

### تستی که در CI سبز است و روی سرور قرمز

`assertRedirect('/portal')` مسیر نسبی را با `url()` مطلق می‌کند، و `url()`
وقتی درخواستی در کار نباشد سراغ `APP_URL` می‌رود. پس همان تست در CI (که
`APP_URL` ندارد و به `localhost` می‌افتد) سبز می‌شود و روی سرور — که
`APP_URL=https://hamanai.com` است — قرمز.

درست شد با فرستادن درخواست به یک هاست صریح (`post("https://{$host}/signup")`)
و انتظار یک آدرس کامل. هر تستی که رفتارش به دامنه وابسته است باید هاست را
خودش بگوید، نه اینکه از محیط ارث ببرد.

به همین دلیل سبز بودن CI به‌تنهایی کافی نیست؛ سوئیت کامل باید روی سرور هم
اجرا شود. (یک تست آنجا همیشه قرمز است و ایراد کد نیست:
`zarinpal reports configured once it has a merchant id`.)

### `hamman-proxy.conf` حذف شد

از روز اول مرده بود: `<VirtualHost *:443>` روی این سرور هیچ درخواست عمومی را
نمی‌گیرد، چون DirectAdmin به IP صریح بایند می‌کند و آپاچی آدرس دقیق‌تر را
ترجیح می‌دهد. تنها جایی که پاسخ می‌داد `127.0.0.1:443` بود و هیچ‌چیز در این
پروژه آنجا وصل نمی‌شود. نسخه‌اش در
`/root/backups/domains-<timestamp>/hamman-proxy.conf` است.

### پایش گواهی‌ها

`haman:check-certificates` هر روز ۰۶:۱۵ اجرا می‌شود، گواهی هر چهار نام را از
روی اتصال واقعی می‌خواند و زیر ۲۱ روز به ادمین‌های پلتفرم اعلان می‌دهد (یک
بار در روز برای هر دامنه، وگرنه سه هفته اعلان تکراری بقیه را دفن می‌کند).
همان وضعیت در تب «سیستم» صفحه‌ی تنظیمات هم دیده می‌شود.

چرا ۲۱ روز: تمدید خودکار حدود ۳۰ روز مانده شروع می‌شود، پس هر گواهی که هنوز
زیر ۲۱ روز است یعنی یک هفته فرصت داشته و استفاده نکرده.
