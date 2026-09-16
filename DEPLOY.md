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
