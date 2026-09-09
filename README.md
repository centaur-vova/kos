# Game Shop Backend — этап 2

![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat&logo=php&logoColor=white)
![Swoole](https://img.shields.io/badge/Swoole-6.2-8DD6F9?style=flat&logo=swoole&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat&logo=postgresql&logoColor=white)

Ядро магазина цифровых товаров для геймеров: мультитоварные заказы, saga-паттерн, защита от недобросовестных поставщиков, event sourcing.

## Технологии

- PHP 8.4 + Swoole 6.2 (корутины, shared memory)
- PostgreSQL 16 (ACID, транзакции, JSONB)
- Swoole Table (идемпотентность, блокировки)
- Docker + Docker Compose
- Behat (Gherkin-сценарии)

## Что реализовано

### Первый этап (база)

- ✅ Ядро API: заказы, вебхуки, каталог
- ✅ Exactly-once под гонками (50 параллельных вебхуков → одна выдача)
- ✅ Устойчивые интеграции: таймауты, fallback A→B
- ✅ Сверка, наблюдаемость, восстановление
- ✅ Каталог под нагрузкой (индексы, EXPLAIN)

### Второй этап (сложные сценарии)

- ✅ **Задача 1** — Мультитоварный заказ: частичный сбой, поштучные возвраты
- ✅ **Задача 2** — Недобросовестный поставщик: защита от дублей, валидация, fallback
- ✅ **Задача 4** — Event Sourcing: восстановление состояния на любой момент
- ⏭️ **Задача 3** — Лимит поставщика (бонусная, не реализована)

## Быстрый старт

```
cp .env.example .env
docker compose up -d
curl http://localhost:8080/health
```

## Тестирование

Все сценарии покрыты тестами — unit (PHPUnit) и Behat (Gherkin), 21 сценарий, 116 шагов.

```bash
make test
```

## API

### Создать заказ (мультитоварный)

```
POST /orders
{
"user_id": "user1",
"items": [
{"sku": "KEY-CS2-PRIME"},
{"sku": "KEY-GTA5"}
]
}
```

### Получить заказ

```
GET /orders/{order_code}
```

### Вебхук оплаты

```
POST /webhook/payment
{
"event*id": "evt_1",
"order_id": "ord*...",
"status": "paid",
"amount": 1290,
"currency": "RUB"
}
```

### Сверка

```
GET /reconciliation
```

### Запуск восстановления

```
POST /recovery
```

### Состояние на дату (Event Sourcing)

```
GET /orders/{order_code}/state?until=2026-09-09T15:00:00Z
```

### Лента событий

```
GET /orders/{order_code}/events
```

### Финансовый отчет за период

```
GET /orders/financial-report?from=2026-09-01T00:00:00Z&to=2026-09-09T23:59:59Z
```

### Каталог

```
GET /catalog?limit=100&offset=0
```

## Структура

```
├── features/ # Behat-сценарии (гонки, фрод, мультизаказы, event sourcing)
├── migrations/ # SQL-миграции (001_tables ... 006_cleanup)
├── mock/ # Заглушка поставщика
├── public/ # Точка входа index.php
├── scripts/ # Тестовые скрипты (каталог)
├── src/
│ ├── Application.php # Инициализация роутов
│ ├── Bootstrap.php # Загрузка конфигурации
│ ├── Container.php # DI-контейнер (PHP-DI)
│ ├── Database.php # Пул соединений Swoole + транзакции
│ ├── Router.php # Маршрутизатор
│ ├── Config/ # Конфигурация
│ ├── Controller/ # HTTP контроллеры
│ ├── Domain/
│ │ └── Repository/ # Интерфейсы репозиториев
│ ├── DTO/ # Data Transfer Objects
│ ├── Enum/ # Перечисления (статусы, события)
│ ├── Exception/ # Доменные исключения
│ ├── Http/ # ApiResponse
│ ├── Infrastructure/
│ │ └── Persistence/ # Реализации репозиториев
│ ├── Service/ # Бизнес-логика
│ ├── Storage/ # Swoole Table (shared memory)
│ └── Support/ # Логгер
└── tests/ # Behat-контекст
```

## Известные ограничения MVP

- **Задача 3** (лимит поставщика) — бонусная, не реализована
- **Event Bus** — события пишутся напрямую в БД, без outbox pattern
- **Rate limiting** — для очередей поставщиков потребуется Redis
- **Деньги как float** — в проде moneyphp/money

Подробнее о ключевых решениях — в [SOLUTION.md](SOLUTION.md).
