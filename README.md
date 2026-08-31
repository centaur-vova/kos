# Game Shop Backend

Ядро магазина цифровых товаров для геймеров: платежи, каталог, интеграции с поставщиками, автоматическая доставка.

## Технологии

- PHP 8.4 + Swoole 6.2
- PostgreSQL 16
- Swoole Table (shared memory для идемпотентности)
- Docker + Docker Compose
- PSR-3 логирование

## Этапы

Все 5 этапов выполнены:

- ✅ **Этап 1** — Ядро API: заказы, вебхуки, выдача
- ✅ **Этап 2** — Exactly-once под гонками
- ✅ **Этап 3** — Устойчивые интеграции: таймауты, fallback AB
- ✅ **Этап 4** — Сверка, наблюдаемость, восстановление
- ✅ **Этап 5** — Каталог под нагрузкой

## Быстрый старт

```bash
cp .env.example .env
make up
curl http://localhost:8080/health
```

## Тестирование

```bash
make test
```

Или отдельные тесты:

```bash
make test-race       # 50 параллельных вебхуков
make test-timeout    # таймаут провайдера
make test-fallback   # fallback A -> B
make test-recovery   # фоновое восстановление
make test-reconciliation # сверка
make test-catalog    # каталог
```

## API

### Создать заказ

```bash
POST /orders
{"sku":"KEY-CS2-PRIME","user_id":"test_user"}
```

### Получить заказ

```bash
GET /orders/{id}
```

### Вебхук оплаты

```bash
POST /webhook/payment
{"event_id":"evt_1","order_id":"ord_...","status":"paid","amount":1290}
```

### Сверка

```bash
GET /reconciliation
```

### Каталог

```bash
GET /catalog
```

## Важно

Перед запуском отдельных тестов сбрось остатки:

```bash
make reset-stock
```

## Структура

```
src/
├── Controller/     # HTTP контроллеры
├── Service/        # Бизнес-логика
├── Storage/        # Хранилище (Swoole Table)
├── Config/         # Конфигурация
├── DTO/            # Data Transfer Objects
├── Enum/           # Перечисления
├── Exception/      # Доменные исключения
└── Support/        # Логгер
```

## Известные ограничения MVP

Подробнее о ключевых решениях — в [SOLUTION.md](SOLUTION.md).
