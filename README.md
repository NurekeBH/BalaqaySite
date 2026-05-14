# baura.kz — Procurement & Payables

Қытайдан тауар сатып алушыларға арналған тапсырыс/төлем/логистика есеп жүйесі.

- **Frontend:** vanilla HTML/CSS/JS single-page app, 3 тілді (中文 / Русский / Қазақша)
- **Backend:** PHP 8.2 + MySQL (Plesk shared hosting-та жұмыс істейді)
- **Auth:** session-based, 2 рөл — `admin` (бәрі) / `user` (тек оқу)

## Архитектура

```
.
├── frontend/        Browser SPA (index.html + i18n.js)
├── php/
│   ├── api/         PHP REST API
│   │   ├── .htaccess        URL rewrite
│   │   ├── index.php        front controller
│   │   ├── db.php           PDO + session helpers
│   │   ├── auth.php         /api/auth/{login,logout,me}
│   │   ├── users.php        /api/users (admin only)
│   │   ├── orders.php       CRUD + supplier resolve + prepaid FIFO
│   │   ├── payments.php     3 kinds + FIFO + reallocate
│   │   ├── suppliers.php    CRUD + frozen-rate aggregates
│   │   ├── photos.php       multipart upload
│   │   ├── settings.php     key/value
│   │   └── config.example.php   ← copy to config.php with creds
│   ├── router.php   local dev router (PHP built-in server)
│   ├── schema.sql   one-shot DB import
│   └── DEPLOY.md    ps.kz Plesk deploy guide
```

## Жергілікті іске қосу

```bash
# 1) MySQL дайындау
mysql -u root -p < php/schema.sql       # procurement DB-ге барлық кестелер

# 2) config жасау
cp php/api/config.example.php php/api/config.php
# config.php-да DB паролін қойыңыз

# 3) PHP-нің built-in server-ін іске қосу
cd php && php -S localhost:8080 router.php

# 4) Браузерде ашу
open http://localhost:8080
```

**Бастапқы admin:** `admin` / `admin123` — кірген соң UI-ден ауыстыру керек.

## ps.kz Plesk-ке deploy

[php/DEPLOY.md](php/DEPLOY.md) — толық қадамдар.

## Негізгі мүмкіндіктер

- 👤 **Жеткізушілер** — санат бойынша, "Қатаң/Ұзақ мерзімді/Маңызды" белгілері
- 📋 **Тапсырыстар** — ай бойынша топтап, ¥ + теңге қостап
- 🚛 **Логистика** — мәртебе бойынша (延期/в пути/жөнелтілген), кешіктірілгенге назар
- 🔥 **Назар** — кешіктірілгендер + қатаң берешегі бар + үлкен берешек + жолдағылар
- 💸 **Төлем 3 түрі:** 🎯 нақты тапсырыс / 💼 жеткізуші шотына (FIFO) / 🤝 алдын ала
- 🏷 **Курс жадыда тіркеледі** — әр order/payment өз курсын сақтайды, header курсы өзгерсе тарих өзгермейді
- 🌐 **3 тіл** (中文 / Русский / Қазақша)
- 🔐 **Auth + 2 рөл:** admin (бәрі) / user (тек оқу)

## REST API

| Method | URL | Кім | Не істейді |
|---|---|---|---|
| GET    | /api/health | – | health check |
| POST   | /api/auth/login | – | { username, password } → cookie |
| POST   | /api/auth/logout | login | session destroy |
| GET    | /api/auth/me | – | current user info |
| GET    | /api/users | admin | пайдаланушылар тізімі |
| POST   | /api/users | admin | жаңа пайдаланушы |
| PUT    | /api/users/:id | admin | пароль/рөл/аты өзгерту |
| DELETE | /api/users/:id | admin | пайдаланушы өшіру |
| GET    | /api/suppliers[?supplierId=N] | login | агрегаттармен |
| POST/PUT/DELETE | /api/suppliers[/:id] | admin | – |
| GET    | /api/orders[?supplierId=N] | login | толық тізім |
| POST/PUT/DELETE | /api/orders[/:id] | admin | – |
| POST/PUT/DELETE | /api/payments[/:id] | admin | – |
| POST   | /api/photos/upload | admin | multipart |
| GET/PUT | /api/settings[/:key] | admin (PUT) | – |
