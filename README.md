# 采购账款管理系统 (Procurement & Payables)

Қытайдан тауар сатып алушыларға арналған тапсырыс/төлем/логистика есеп жүйесі.
Юань↔Теңге курсы, фото-чектер, көп тілді UI (中文 / Русский / Қазақша).

## Архитектура

```
New/
├── backend/        Node.js + Express + MySQL REST API
│   ├── server.js
│   ├── db.js
│   ├── schema.sql
│   ├── routes/     orders, payments, photos, settings
│   └── uploads/    жүктелген суреттер (gitignored)
├── frontend/
│   └── index.html  Single-page UI (fetch → /api)
└── 采购账款管理系统_v8.html  Бастапқы localStorage нұсқасы (анықтама үшін)
```

## 1. MySQL дайындау

```bash
mysql -u root -p < backend/schema.sql
```

Қажет болса `backend/.env` файлында DB деректерін өзгертіңіз:

```
PORT=3001
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASSWORD=
DB_NAME=procurement
```

## 2. Backend іске қосу

```bash
cd backend
npm install
npm start          # немесе: npm run dev (autoreload)
```

Server: `http://localhost:3001`

## 3. Frontend ашу

Backend `frontend/` папкасын статикалық файл ретінде ұсынады:

➡ браузерден ашыңыз: **http://localhost:3001**

(Ескерту: `frontend/index.html`-ды тікелей `file://` арқылы ашсаңыз да жұмыс істейді — JS автоматты түрде `http://localhost:3001/api`-ге сұраныс жібереді.)

## REST API қысқаша

| Method | URL                         | Не істейді                        |
|--------|-----------------------------|-----------------------------------|
| GET    | /api/orders                 | Барлық тапсырыс + төлем + фото    |
| POST   | /api/orders                 | Жаңа тапсырыс (нести payments/photos болуы мүмкін) |
| PUT    | /api/orders/:id             | Тапсырыс өрістерін жаңарту        |
| DELETE | /api/orders/:id             | Тапсырысты өшіру (cascade)        |
| POST   | /api/payments               | Жаңа төлем                        |
| PUT    | /api/payments/:id           | Төлемді өңдеу                     |
| DELETE | /api/payments/:id           | Төлемді өшіру                     |
| POST   | /api/photos/upload          | Multipart файл → `{ url }`        |
| POST   | /api/photos/order/:orderId  | URL-ді тапсырысқа байлау          |
| DELETE | /api/photos/order/:orderId  | URL-ді тапсырыстан алып тастау    |
| GET    | /api/settings               | `{ rate: '65.5', lang: 'zh' }`    |
| PUT    | /api/settings/:key          | `{ value: '67.0' }`               |

## Әрі қарай дамыту идеялары

- Аутентификация (қазір ашық — тек жергілікті қолдану үшін)
- Жеткізушілер кестесін бөлек жасау (қайталанатын аттарды біріктіру)
- Bulk import CSV → orders
- Dashboard графиктер (Chart.js)
- Telegram/WeChat хабарлама (мерзімі өткен жүктер туралы)
