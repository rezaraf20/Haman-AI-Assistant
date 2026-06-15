# 🚀 Hamman AI Platform — راهنمای نصب و راه‌اندازی
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
scp -r hamman-platform/ user@YOUR_SERVER_IP:/opt/hamman/
ssh user@YOUR_SERVER_IP
cd /opt/hamman/hamman-platform
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
cp -r wordpress-plugin/hamman-ai-chatbot/ /path/to/wordpress/wp-content/plugins/

# یا ZIP کنید و از پنل WordPress آپلود کنید
cd wordpress-plugin
zip -r hamman-ai-chatbot.zip hamman-ai-chatbot/
```

سپس در WordPress:
1. **Plugins → Activate** → Hamman AI Chatbot
2. **Hamman AI → Settings**:
   - API Key: کلیدی که در قدم ۶ دریافت کردید
   - Chatbot ID: UUID چت‌بات ساخته‌شده
   - Webhook Secret: از داشبورد Hamman
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

# Backup database
docker compose exec postgres pg_dump -U hamman_user hamman_saas > backup_$(date +%Y%m%d).sql
```

---

## مشکلات رایج

| مشکل | راه‌حل |
|------|--------|
| Laravel 500 error | `docker compose exec laravel php artisan config:clear && php artisan cache:clear` |
| AI service unavailable | `docker compose restart python_ai` + بررسی OPENAI_API_KEY |
| Migration failed | بررسی اتصال postgres: `docker compose exec postgres psql -U hamman_user -d hamman_saas -c '\l'` |
| Widget not showing | در WordPress: بررسی Chatbot ID + Domain whitelist در داشبورد |
| Embeddings stuck | `docker compose logs horizon` برای مشاهده خطای job |

---

## Port های مورد استفاده

| Service | Port | توضیح |
|---------|------|-------|
| Nginx | 80, 443 | ورودی اصلی |
| Laravel FPM | 9000 | داخلی |
| Python AI | 8001 | داخلی |
| PostgreSQL | 5432 | دیتابیس |
| Redis | 6379 | صف و کش |
