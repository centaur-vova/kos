# Game Shop Backend

![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat&logo=php&logoColor=white)
![Swoole](https://img.shields.io/badge/Swoole-6.2-8DD6F9?style=flat&logo=swoole&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat&logo=postgresql&logoColor=white)
![Tests](https://github.com/centaur-vova/kos/workflows/Tests/badge.svg)

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
make test-unit       # unit тесты
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
## Структура проекта
├── features/ # Behat-сценарии (event sourcing, fraud protection, multi-item)
├── migrations/ # SQL-миграции (001_tables ... 005_event_sourcing_immutability)
├── mock/ # Заглушка поставщика (mock provider)
├── public/ # Точка входа index.php
├── scripts/ # Тестовые скрипты (catalog, reconciliation, recovery)
├── src/
│ ├── Application.php # Инициализация роутов и обработка запросов
│ ├── Bootstrap.php # Загрузка конфигурации и контейнера
│ ├── Container.php # DI-контейнер (PHP-DI)
│ ├── Database.php # Пул соединений Swoole + транзакции
│ ├── Router.php # Маршрутизатор
│ ├── Config/ # Конфигурация (Options, ProviderConfig, ConfigLoader)
│ ├── Controller/ # HTTP контроллеры (Order, Payment, Catalog, Reconciliation, Webhook)
│ ├── Domain/
│ │ ├── Entity/ # Доменные сущности
│ │ └── Repository/ # Интерфейсы репозиториев
│ ├── DTO/ # Data Transfer Objects (PaymentWebhook, OrderResponse, OrderItem...)
│ ├── Enum/ # Перечисления (OrderStatus, OrderEventType, OrderItemStatus...)
│ ├── Exception/ # Доменные исключения + исключения провайдеров
│ ├── Http/ # ApiResponse (формат ответов)
│ ├── Infrastructure/
│ │ └── Persistence/ # Реализации репозиториев на PostgreSQL
│ ├── Service/ # Бизнес-логика (PaymentService, DeliveryService, EventSourcing...)
│ ├── Storage/ # Хранилище in-memory (Swoole Table)
│ └── Support/ # Логгер (StdoutLogger)
└── tests/ # Unit-тесты + Behat-контекст
```

## Известные ограничения MVP

Подробнее о ключевых решениях — в [SOLUTION.md](SOLUTION.md).
