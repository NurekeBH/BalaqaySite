# Deploy → baura.kz (ps.kz Plesk)

Толық қадамдар. ~20 минут.

---

## 1. MySQL дерекқорын жасау

Plesk → **Базы данных** → **Добавить базу данных**
- Имя БД: `procurement` (Plesk автоматты префикс қосады, мысалы `baurakz_procurement`)
- Создать пользователя: иә
- Имя пользователя: `app` (префикспен `baurakz_app`)
- Пароль: 16-таңбалы күшті пароль жасап **сақтаңыз** — кейін керек

Жасалған соң → **phpMyAdmin** батырмасын басыңыз → бұл базаға кіріңіз.

## 2. Схеманы импорттау

phpMyAdmin → жасалған base-ке (мыс. `baurakz_procurement`) → **Импорт** қойындысы →
файл: `php/schema.sql` → **Перейти**.

Барлық 6 кесте жасалады: `suppliers`, `orders`, `payments`, `payment_allocations`,
`order_photos`, `settings`.

## 3. Файлдарды жүктеу

Plesk → **Файлы** → `httpdocs/` папкасына кіріңіз.

Бар `index.html` (Plesk default) — **өшіріңіз**.

Жүктеу керек:

| Источник (жергілікті) | Назначение (ps.kz) |
|---|---|
| `frontend/index.html`   | `httpdocs/index.html`  |
| `frontend/i18n.js`      | `httpdocs/i18n.js`     |
| `php/api/` папкасы тұтас | `httpdocs/api/`        |
| `php/api/config.example.php` | `httpdocs/api/config.php` (атын өзгертіңіз) |

Жаңа папка жасау: `httpdocs/uploads/` (бос, жазу құқығы 755).

## 4. `config.php`-ні баптау

Plesk файл менеджерінде `httpdocs/api/config.php` редакциялаңыз:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'baurakz_procurement');   // 1-қадамдан
define('DB_USER', 'baurakz_app');           // 1-қадамдан
define('DB_PASSWORD', 'BAS3HOnDA');         // 1-қадамдан
```

## 5. SSL қосу (тегін Let's Encrypt)

Plesk → **SSL/TLS-сертификаты** → **Let's Encrypt** → "Защитить домен"
галочкаларын қосыңыз → **Получить бесплатно**.

Сертификат шыққан соң, `https://baura.kz` ашылады.

## 6. Тексеру

1. https://baura.kz/api/health → `{"ok":true}` көрсетсе backend OK
2. https://baura.kz/ → сайт ашылады, login терезесі шығады
3. **Бастапқы admin:** `admin` / `admin123` — кіріңіз
4. ⚠️ **Бірден парольді ауыстырыңыз:** жоғарғы оң жақтан 👥 батырмасы → admin жолының жанындағы 🔑 → жаңа күшті пароль
5. Жаңа жеткізуші + тапсырыс қосып көріңіз — DB-да пайда болады

## Пайдаланушылар + рөлдер

- 🔵 **admin** — барлық CRUD (қосу/өшіру/өңдеу) + пайдаланушыларды басқарады
- ⚪ **user** — тек оқиды (барлық беттер ашық, бірақ "+", ✏, 🗑 түймелері жасырын)

Жаңа пайдаланушы қосу:
1. Admin ретінде кіріңіз
2. Header-де 👥 батырмасы → "+ Жаңа пайдаланушы" формасы
3. Username, пароль, аты, рөлді таңдау → Қосу
4. Жаңа пайдаланушыға кіру деректерін беріңіз

## 7. (Қажет болса) Жергілікті деректерді көшіру

Жергілікті DB-нан экспорт:

```bash
mysqldump -u root -p procurement \
  suppliers orders payments payment_allocations order_photos settings \
  > backup.sql
```

phpMyAdmin → жаңа DB → **Импорт** → `backup.sql`.

⚠️ `uploads/` папкасындағы фотоларды да FTP-мен `httpdocs/uploads/`-қа көшіріңіз
(URL-дар `/uploads/xxx.jpg` форматында сақталған, сондықтан path сақталу керек).

---

## Шешімі қажет проблемалар

| Проблема | Шешім |
|---|---|
| `/api/orders` 404 | `httpdocs/api/.htaccess` бар ма? Plesk-те Apache mod_rewrite қосулы ма? |
| `Connection refused` | `config.php`-да DB_HOST=`localhost` (немесе Plesk-те көрсетілген host) |
| Фото жүктелмейді | `httpdocs/uploads/` папкасының рұқсаты 755 ме? PHP `upload_max_filesize`? |
| Қытайша иероглифтер `?` болып шығады | DB charset `utf8mb4` ме? `SET NAMES utf8mb4` жүріп жатыр ма? |
| `403 Forbidden` `config.php`-ге | `.htaccess` оны блоктап тұр — дұрыс (қауіпсіздік) |
