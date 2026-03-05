# ТМИГ — сайт для родителей школьников

Полноценный проект: Next.js + Prisma + PostgreSQL + email + платежный адаптер.

## Важно про вопросы теста
Вопросы загружаются из источника:
- https://psytests.org/typo/tmig-bl.html
- https://psytests.org/typo/tmig-run.html

Скрипт `scripts/import-questions.ts` пытается скачать HTML, распарсить и сохранить `scripts/questions-cache.json`. Если загрузка невозможна, используется локальный fallback seed.

## Запуск
```bash
docker compose up
```
Или локально:
```bash
npm install
cp .env.example .env
npx prisma migrate dev
npm run import:questions
npm run db:seed
npm run dev
```

## Маршруты
- `/` лендинг
- `/checkout` регистрация и оплата
- `/test/[token]` тест
- `/admin` админка
- `/policy`, `/offer`, `/contacts`

## API
- `POST /api/checkout/start`
- `POST /api/webhooks/yookassa`
- `GET /api/test/session?token=...`
- `POST /api/test/answer`
- `POST /api/test/finish`
- `POST /api/admin/send-result`

## Тесты
```bash
npm test
```
