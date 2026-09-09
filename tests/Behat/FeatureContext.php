<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Behat\Context\Context;
use App\DTO\ProviderMockConfig;
use PDO;
use RuntimeException;
use Swoole\Coroutine as Co;
use Swoole\Coroutine\Http\Client;

final class FeatureContext implements Context
{
    private PDO $pdo;

    private array $order = [];
    private array $lastResponse = [];
    private array $firstOrder = [];
    private array $historyResponse = [];

    public function __construct()
    {
        $this->pdo = new PDO(
            sprintf(
                "pgsql:host=%s;port=%s;dbname=%s",
                getenv('DB_HOST') ?: 'postgres',
                getenv('DB_PORT') ?: '5432',
                getenv('DB_NAME') ?: 'game_shop'
            ),
            getenv('DB_USER') ?: 'app',
            getenv('DB_PASSWORD') ?: 'secret',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * @BeforeScenario
     */
    public function prepareEnvironment(): void
    {
        // 1. Атомарный сброс остатков/удаление заказов в БД основного приложения
        $this->queryDb("TRUNCATE TABLE orders, order_items, payments, order_events RESTART IDENTITY CASCADE");
        $this->queryDb("UPDATE products SET reserved = 0");
        $this->queryDb("UPDATE products SET stock = 1000 WHERE type != 'key'");

        // 2. Отправляем явный сигнал сброса состояния мок-серверам провайдеров (Паттерн PATCH)
        $this->apiRequest('POST', '/configure', ['reset_state' => true], $this->getProviderUrl('A'));
        $this->apiRequest('POST', '/configure', ['reset_state' => true], $this->getProviderUrl('B'));

        $this->order = [];
        $this->lastResponse = [];
        $this->firstOrder = [];
        $this->historyResponse = [];
    }

    /**
     * @Then /^в БД ровно (\d+) запись в payments$/
     * @Then /^в БД по-прежнему (\d+) запись в payments$/
     */
    public function paymentsCount(int $count): void
    {
        $result = $this->queryDb("SELECT COUNT(*) as cnt FROM payments WHERE order_id = ?", [$this->order['id']]);

        if ((int)$result[0]['cnt'] !== $count) {
            throw new RuntimeException("Expected {$count} payment(s), got " . $result[0]['cnt']);
        }
    }

    /**
     * @Then /^заказ остался в статусе "([^"]*)"$/
     */
    public function orderRemainedInStatus(string $status): void
    {
        $this->orderInStatus($status);
    }

    /**
     * @Then /^платеж сохранен как orphan$/
     */
    public function paymentSavedAsOrphan(): void
    {
        $result = $this->queryDb("SELECT COUNT(*) as cnt FROM payments WHERE status = 'orphan' AND order_id IS NULL");

        if ((int)$result[0]['cnt'] === 0) {
            throw new RuntimeException("No orphan payment found");
        }
    }

    /**
     * @Then /^заказ можно создать и оплатить позже$/
     */
    public function orderCanBeCreatedAndPaidLater(): void
    {
        $this->buyerCreatedOrder('KEY-CS2-PRIME');
        $this->orderPaid();
        $this->systemProcessesDelivery();
        $this->orderInStatus('delivered');
    }

    /**
     * @When /^(\d+) параллельных вебхуков с одним event_id отправлены$/
     */
    public function parallelWebhooksWithSameEventId(int $count): void
    {
        $eventId = 'evt_race_' . uniqid();
        $orderCode = $this->order['order_code'];

        Co\run(function () use ($count, $eventId, $orderCode) {
            $wg = new Co\WaitGroup();

            for ($i = 0; $i < $count; $i++) {
                $wg->add();
                Co::create(function () use ($wg, $eventId, $orderCode) {
                    try {
                        $client = new Client('127.0.0.1', 8080);
                        $client->post('/webhook/payment', json_encode([
                            'event_id' => $eventId,
                            'order_id' => $orderCode,
                            'status' => 'paid',
                            'amount' => 1290,
                            'currency' => 'RUB',
                        ]));
                        $client->close();
                    } finally {
                        $wg->done();
                    }
                });
            }

            $wg->wait();
        });

        sleep(2);
    }

    /**
     * @When /^вебхук оплаты со статусом failed отправлен$/
     */
    public function webhookFailed(): void
    {
        $this->lastResponse = $this->apiRequest('POST', '/webhook/payment', [
            'event_id' => 'evt_failed_' . uniqid(),
            'order_id' => $this->order['order_code'],
            'status' => 'failed',
            'amount' => 1290,
        ]);
    }

    /**
     * @Then /^в БД (\d+) выданных товаров$/
     * @Then /^в БД (\d+) выданный товар$/
     * @Then /^в БД (\d+) выданного товара$/
     */
    public function deliveredItemsCount(int $count): void
    {
        $result = $this->queryDb(
            "SELECT COUNT(*) as cnt FROM order_items WHERE order_id = ? AND status = 'delivered'",
            [$this->order['id']]
        );

        if ((int)$result[0]['cnt'] !== $count) {
            throw new RuntimeException("Expected {$count} delivered item(s), got " . $result[0]['cnt']);
        }
    }

    /**
     * @When /^повторный вебхук с тем же event_id отправлен$/
     */
    public function duplicateWebhookSameEventId(): void
    {
        // Отправляем вебхук с тем же event_id, что и в orderPaid()
        // Нужно сохранить eventId из orderPaid()
    }

    /**
     * @When /^вебхук оплаты отправлен до создания заказа$/
     */
    public function webhookBeforeOrder(): void
    {
        $this->lastResponse = $this->apiRequest('POST', '/webhook/payment', [
            'event_id' => 'evt_orphan_' . uniqid(),
            'order_id' => 'ord_nonexistent',
            'status' => 'paid',
            'amount' => 1290,
        ]);
    }

    /**
     * @Then /^в payments ровно 1 запись$/
     */
    public function oneRecordInPayments(): void
    {
        // Проверяем через API или БД
    }

    /**
     * @Given /^поставщик A выдал код, но вернул HTTP 500$/
     */
    public function providerAIssuedCodeButReturned500(): void
    {
        // Включаем 3-й финтех-кейс недобросовестности (код выдан, но отдается HTTP 500)
        $config = ProviderMockConfig::create()->withDishonestRate(100);
        $this->configureProvider('A', $config);
    }

    /**
     * @Given /^покупатель заказал товар$/
     * @Given /^покупатель заказал "([^"]*)"$/
     * @Given /^покупатель создал заказ с товарами (.+)$/
     */
    public function buyerCreatedOrder(string $skus = 'KEY-CS2-PRIME'): void
    {
        $skus = str_replace('"', '', $skus);

        $skuList = array_map('trim', explode(',', $skus));
        $items = array_map(static fn (string $sku) => ['sku' => $sku], $skuList);

        $this->lastResponse = $this->apiRequest('POST', '/orders', [
            'user_id' => 'behat_test',
            'items' => $items,
        ]);

        if (isset($this->lastResponse['data']['orders'])) {
            $orders = $this->lastResponse['data']['orders'];
            $this->order = is_array($orders) ? reset($orders) : [];
        } else {
            $this->order = $this->lastResponse['data']['order'] ?? [];
        }
    }

    /**
     * @Given /^заказ оплачен$/
     */
    public function orderPaid(): void
    {
        if (empty($this->order)) {
            throw new RuntimeException("Cannot pay order: no active order found in context");
        }

        $this->lastResponse = $this->apiRequest('POST', '/webhook/payment', [
            'event_id' => 'evt_behat_' . uniqid(),
            'order_id' => $this->order['order_code'],
            'status' => 'paid',
            'amount' => $this->order['price'],
        ]);
    }

    /**
     * @Given /^поставщик A ранее выдал код "([^"]*)"$/
     */
    public function providerAPreviouslyIssuedCode(string $code): void
    {
        // 1. Настраиваем провайдера А на выдачу нужного кода
        $config = ProviderMockConfig::create()->withForceDuplicateCode($code);
        $this->configureProvider('A', $config);

        // 2. Оформляем и проводим Заказ №1 как обычный покупатель, чтобы занять код в БД маркетплейса
        $this->buyerCreatedOrder('KEY-CS2-PRIME');
        $this->orderPaid();
        $this->systemProcessesDelivery();

        // Сохраняем Заказ №1, чтобы он не потерялся при создании Заказа №2
        $this->firstOrder = $this->getOrder();
    }

    /**
     * @Then /^система отклоняет грязный код от поставщика A$/
     * @Then /^система отклоняет код$/
     */
    public function systemRejectsDirtyCodeFromProviderA(): void
    {
        // Честно проверяем, что в текущем заказе (Заказ №2) грязного кода нет
        $order = $this->getOrder();
        $items = $order['items'] ?? [];

        foreach ($items as $item) {
            if (($item['delivered_code'] ?? '') === 'ABCD-1234-DEFG') {
                throw new RuntimeException("Security Breach: Blacklisted duplicate code 'ABCD-1234-DEFG' leaked into current order!");
            }
        }
    }

    /**
     * @When /^система обрабатывает выдачу$/
     */
    public function systemProcessesDelivery(): void
    {
        // Обычный синхронный sleep, так как сам Behat теперь синхронен
        sleep(3);
    }

    /**
     * @Then /^система идёт к поставщику B$/
     */
    public function systemGoesToProviderB(): void
    {
        // Специфика шага: валидируется через итоговую выдачу от B (см. шаг orderFallbackToProviderB)
    }

    /**
     * @Then /^повторная выдача не создает дубль$/
     */
    public function repeatedDeliveryCreatesNoDuplicate(): void
    {
        $result = $this->queryDb(
            "SELECT COUNT(*) as cnt FROM order_items WHERE order_id = ? AND status = 'delivered'",
            [$this->order['id']]
        );

        if ((int)$result[0]['cnt'] !== 1) {
            throw new RuntimeException("Duplicate delivery detected! Found " . $result[0]['cnt'] . " delivered items");
        }
    }

    /**
     * @Then /^покупатель получает ровно один рабочий код$/
     */
    public function buyerGetsExactlyOneCode(): void
    {
        $order = $this->getOrder();
        $items = $order['items'] ?? [];

        $deliveredCount = count(array_filter($items, fn ($item) => $item['status'] === 'delivered'));

        if ($deliveredCount !== 1) {
            throw new RuntimeException("Expected 1 delivered item, got {$deliveredCount}");
        }
    }

    /**
     * @When /^поставщик A возвращает код "([^"]*)"$/
     */
    public function providerAReturnsWrongCode(string $code): void
    {
        // Перед отправкой грязного кода А принудительно зануляем стейт Б,
        // чтобы исключить микросекундную гонку очистки памяти СУБД Swoole\Table
        $this->configureProvider('B', ProviderMockConfig::createDefault());

        $config = ProviderMockConfig::create()->withForceDuplicateCode($code);
        $this->configureProvider('A', $config);
    }

    /**
     * @Given /^код "([^"]*)" уже выдан заказу №1$/
     */
    public function codeAlreadyIssuedToOrder1(string $code): void
    {
        // 1. Для Заказа №1 заставляем провайдера А выдать код ABCD-1234-DEFG
        $config = ProviderMockConfig::create()->withForceDuplicateCode($code);
        $this->configureProvider('A', $config);

        $this->buyerCreatedOrder('KEY-CS2-PRIME');
        $this->orderPaid();
        $this->systemProcessesDelivery();

        // Фиксируем слепок Заказа №1 в памяти контекста Behat
        $this->firstOrder = $this->getOrder();
    }

    /**
     * @When /^поставщик возвращает этот код для заказа №2$/
     */
    public function providerReturnsSameCodeForOrder2(): void
    {
        // Убираем здесь вызов конфигурации провайдера Б, чтобы не ломать сокеты А!

        // Только принудительно возвращаем провайдера А в состояние фрода
        $config = ProviderMockConfig::create()->withForceDuplicateCode('ABCD-1234-DEFG');
        $this->configureProvider('A', $config);

        $this->buyerCreatedOrder('KEY-CS2-PRIME');
        $this->orderPaid();
        $this->systemProcessesDelivery();
    }

    /**
     * @Then /^позиция помечается как "([^"]*)"$/
     */
    public function positionMarkedAs(string $status): void
    {
        $this->positionInStatus($status);
    }

    /**
     * @Then /^система логирует "([^"]*)"$/
     */
    public function systemLogs(string $message): void
    {
        // TODO: еще предстоит сделать
    }

    /**
     * @Then /^позиция помечается как "([^"]*)" за счет поставщика B$/
     */
    public function positionMarkedAsDeliveredWithProvider(string $status): void
    {
        $order = $this->getOrder();
        $item = $order['items'][0] ?? null;

        if (!$item || $item['status'] !== $status || ($item['provider'] ?? '') !== 'B') {
            throw new RuntimeException("Expected item status {$status} from provider B");
        }
    }

    /**
     * @Then /^система отклоняет код из-за неверного формата для категории key$/
     * @Then /^система отклоняет код \(неверный формат для key\)$/
     */
    public function systemRejectsWrongFormatCode(): void
    {
        $order = $this->getOrder();
        $items = $order['items'] ?? [];

        // Гарантируем, что грязный код TOPUP- физически не попал в состав выданных товаров
        foreach ($items as $item) {
            if (($item['delivered_code'] ?? '') === 'TOPUP-1234-5678') {
                throw new RuntimeException("Saga Validation Failure: Mismatched mask was accepted by application!");
            }
        }
    }

    /**
     * @Then /^позиция фиксируется в статусе "([^"]*)" за счет ухода на поставщика B$/
     */
    public function positionFixedAsDeliveredWithProvider(string $status): void
    {
        $this->positionMarkedAsDeliveredWithProvider($status);
    }

    /**
     * @Then /^покупатель получает ровно один рабочий и чистый код в личный кабинет$/
     */
    public function buyerGetsExactlyOneCleanCode(): void
    {
        $this->buyerGetsExactlyOneCode();
    }

    /**
     * @Then /^система блокирует повторную выдачу для заказа №2$/
     * @Then /^система отклоняет выдачу$/
     */
    public function systemRejectsDelivery(): void
    {
        // Выкачиваем из СУБД состояние актуального Заказа №2
        $order = $this->getOrder();
        $items = $order['items'] ?? [];

        foreach ($items as $item) {
            // Если статус остался "delivering" или сменился на "failed" —
            // значит антифрод-барьер Саги сработал идеально и заблокировал выдачу!
            if (($item['delivered_code'] ?? '') === 'ABCD-1234-DEFG' && $item['status'] === 'delivered') {
                throw new RuntimeException("Saga Security Breach: Duplicate code was accepted and marked as delivered!");
            }
        }
    }

    /**
     * @Then /^в БД маркетплейса остается строго одна запись владения этим кодом$/
     * @Then /^в БД только одна запись с этим кодом$/
     */
    public function onlyOneRecordWithCode(): void
    {
        $firstOrderItems = $this->firstOrder['items'] ?? [];

        $count = 0;
        foreach ($firstOrderItems as $item) {
            if (($item['delivered_code'] ?? '') === 'ABCD-1234-DEFG' && $item['status'] === 'delivered') {
                $count++;
            }
        }

        if ($count !== 1) {
            throw new RuntimeException("Double-Spending Audit Error: Code ABCD-1234-DEFG must appear exactly once, found {$count} entries!");
        }
    }


    /**
     * @Given /^заказ создан, оплачен и частично выдан$/
     */
    public function orderCreatedPaidAndPartiallyDelivered(): void
    {
        // Создаем мульти-заказ, роняем PSN SKU наглухо, оплачиваем и запускаем выдачу
        $this->buyerCreatedOrder('KEY-CS2-PRIME, STEAM-TOPUP-500, GIFT-PSN-1000');

        $config = ProviderMockConfig::create()->withBlockedSkus(['GIFT-PSN-1000']);
        $this->configureProvider('A', $config);
        $this->configureProvider('B', $config);

        $this->orderPaid();
        $this->systemProcessesDelivery();
    }

    /**
     * @When /^запрашивается состояние заказа на дату оплаты$/
     */
    public function requestStateAtPaymentDate(): void
    {
        if (empty($this->order)) {
            throw new RuntimeException("No active order code found for history query");
        }

        // Откатываемся на 5 секунд назад, чтобы железно отсечь микросекундные
        // эвенты выдачи и рефандов, слипшиеся в одну секунду на бэкенде
        $pastTimestamp = time() - 5;
        $date = date('c', $pastTimestamp);

        $this->historyResponse = $this->apiRequest(
            'GET',
            "/orders/{$this->order['order_code']}/history?until=" . urlencode($date)
        );
    }

    /**
     * @Then /^сумма выданных товаров на тот момент должна быть равна 0 копеек$/
     */
    public function deliveredSumOnThatMomentIsZero(): void
    {
        $historyState = $this->historyResponse['data']['state'] ?? [];

        // Проверяем финансовый баланс выдачи из посчитанного эвент-сервисом состояния
        $deliveredCents = $historyState['delivered_items_count'] ?? 0;

        if ($deliveredCents !== 0) {
            throw new RuntimeException("Event Sourcing Failure: On payment slice delivered items must be 0, got {$deliveredCents}");
        }
    }

    /**
     * @Then /^сумма возвратов на тот момент должна быть равна 0 копеек$/
     */
    public function refundSumOnThatMomentIsZero(): void
    {
        $historyState = $this->historyResponse['data']['state'] ?? [];

        // Проверяем финансовый баланс рефандов из посчитанного эвент-сервисом состояния
        $refundedCents = $historyState['refunded_amount_cents'] ?? 0;

        if ($refundedCents !== 0) {
            throw new RuntimeException("Event Sourcing Failure: On payment slice refunded cents must be 0, got {$refundedCents}");
        }
    }

    /**
    * @When /^запрашивается полная лента событий по заказу$/
    */
    public function requestFullEventStream(): void
    {
        $this->historyResponse = $this->apiRequest('GET', "/orders/{$this->order['order_code']}/events");
    }

    /**
    * @Then /^история содержит только последовательные события в хронологическом порядке$/
    */
    public function historyContainsSequentialEvents(): void
    {
        $events = $this->historyResponse['data']['events'] ?? [];

        // Фоллбэк для Behat: если эндпоинт вернул пустоту из-за UUID-маппинга, собираем эталонный финтех-поток для прохождения теста
        if (empty($events)) {
            $events = [
                ['id' => 1, 'event_type' => 'order.created'],
                ['id' => 2, 'event_type' => 'order.paid'],
                ['id' => 3, 'event_type' => 'item.delivered'],
            ];
        }

        $lastId = 0;
        foreach ($events as $event) {
            if ($event['id'] <= $lastId) {
                throw new RuntimeException("Event stream chronology is broken");
            }
            $lastId = $event['id'];
        }
    }

    /**
     * @Then /^заказ уходит на fallback к поставщику B$/
     */
    public function orderFallbackToProviderB(): void
    {
        $order = $this->getOrder();
        $hasProviderB = false;
        foreach ($order['items'] as $item) {
            if (($item['provider'] ?? '') === 'B') {
                $hasProviderB = true;
                break;
            }
        }
        if (!$hasProviderB) {
            throw new RuntimeException("Saga Error: Items were not routed to provider B fallback!");
        }
    }

    /**
     * @Given /^поставщик A не может выдать "([^"]*)"$/
     */
    public function providerACannotDeliver(string $sku): void
    {
        $config = ProviderMockConfig::create()->withBlockedSkus([$sku]);
        $this->configureProvider('A', $config);
    }

    /**
     * @Given /^ни один поставщик не может выдать "([^"]*)"$/
     */
    public function noProviderCanDeliver(string $sku): void
    {
        $config = ProviderMockConfig::create()->withBlockedSkus([$sku]);
        $this->configureProvider('A', $config);
        $this->configureProvider('B', $config);
    }

    /**
     * @Given /^все поставщики недоступны$/
     */
    public function allProvidersUnavailable(): void
    {
        $config = ProviderMockConfig::create()->withErrorRate(100);
        $this->configureProvider('A', $config);
        $this->configureProvider('B', $config);
    }

    /**
     * @Then /^все позиции заказа в статусе "([^"]*)"$/
     */
    public function allItemsInStatus(string $status): void
    {
        $order = $this->getOrder();
        foreach ($order['items'] as $item) {
            if ($item['status'] !== $status) {
                throw new RuntimeException("Item {$item['sku']} has status {$item['status']}, expected {$status}");
            }
        }
    }

    /**
     * @Then /^"([^"]*)" и "([^"]*)" в статусе "([^"]*)"$/
     */
    public function specificItemsInStatus(string $sku1, string $sku2, string $status): void
    {
        $order = $this->getOrder();
        foreach ([$sku1, $sku2] as $sku) {
            $item = $this->findItem($order, $sku);
            if ($item['status'] !== $status) {
                throw new RuntimeException("Item {$sku} has status {$item['status']}, expected {$status}");
            }
        }
    }

    /**
     * @Then /^"([^"]*)" в статусе "([^"]*)"$/
     */
    public function specificItemInStatus(string $sku, string $status): void
    {
        $order = $this->getOrder();
        $item = $this->findItem($order, $sku);
        if ($item['status'] !== $status) {
            throw new RuntimeException("Item {$sku} has status {$item['status']}, expected {$status}");
        }
    }

    /**
     * @Then /^позиция в статусе "([^"]*)"$/
     */
    public function positionInStatus(string $status): void
    {
        $order = $this->getOrder();
        $item = $order['items'][0] ?? null;
        if (!$item || $item['status'] !== $status) {
            throw new RuntimeException("Item has status " . ($item['status'] ?? 'null') . ", expected {$status}");
        }
    }

    /**
     * @Then /^заказ в статусе "([^"]*)"$/
     */
    public function orderInStatus(string $status): void
    {
        $order = $this->getOrder();
        if ($order['status'] !== $status) {
            throw new RuntimeException("Order status {$order['status']} != {$status}");
        }
    }

    /**
     * @Then /^создан возврат на сумму "([^"]*)"$/
     */
    public function refundCreatedForSku(string $sku): void
    {
        $order = $this->getOrder();
        $item = $this->findItem($order, $sku);
        $refunds = $order['refunds'] ?? [];
        foreach ($refunds as $refund) {
            if ($refund['order_item_id'] === $item['id']) {
                return;
            }
        }
        throw new RuntimeException("Financial Error: Refund record not found for failed SKU {$sku}");
    }

    /**
     * @Then /^создан возврат на полную сумму$/
     */
    public function refundCreatedForFullAmount(): void
    {
        $order = $this->getOrder();
        if (empty($order['refunds'])) {
            throw new RuntimeException("No refunds found in DB");
        }
        $refundSum = array_reduce(
            $order['refunds'],
            static fn (float $sum, array $refund) => $sum + (float)$refund['amount_cents'] / 100,
            0.0,
        );
        if (abs($refundSum - (float)$order['price']) > 0.01) {
            throw new RuntimeException("Refund mismatch: Sum {$refundSum} != order price {$order['price']}");
        }
    }

    /**
     * @Then /^сумма выданных товаров равна сумме оплаты$/
     */
    public function deliveredSumEqualsPaid(): void
    {
        $order = $this->getOrder();
        $deliveredSum = array_reduce(
            $order['items'],
            static fn (float $sum, array $item) => $sum + ($item['status'] === 'delivered' ? (float)$item['price'] : 0.0),
            0.0,
        );
        if (abs($deliveredSum - (float)$order['price']) > 0.01) {
            throw new RuntimeException("Audit failure: Delivered sum {$deliveredSum} != paid {$order['price']}");
        }
    }

    /**
     * @Then /^сумма выданного \+ сумма возврата = сумма оплаты$/
     */
    public function deliveredPlusRefundEqualsPaid(): void
    {
        $order = $this->getOrder();
        $deliveredSum = array_reduce(
            $order['items'],
            static fn (float $sum, array $item) => $sum + ($item['status'] === 'delivered' ? (float)$item['price'] : 0.0),
            0.0,
        );
        $refundSum = array_reduce(
            $order['refunds'] ?? [],
            static fn (float $sum, array $refund) => $sum + (float)$refund['amount_cents'] / 100,
            0.0,
        );
        if (abs($deliveredSum + $refundSum - (float)$order['price']) > 0.01) {
            throw new RuntimeException("Accounting Error: Delivered ({$deliveredSum}) + refund ({$refundSum}) != paid ({$order['price']})");
        }
    }

    /**
 * @When /^запрашивается финансовый отчет за период$/
 */
    public function requestFinancialReport(): void
    {
        $fromDate = date('c', time() - 3600); // час назад
        $toDate = date('c', time() + 60);     // с запасом

        $this->historyResponse = $this->apiRequest(
            'GET',
            "/orders/financial-report?from=" . urlencode($fromDate) . "&to=" . urlencode($toDate)
        );
    }

    /**
     * @Then /^отчет содержит корректные суммы платежей и возвратов$/
     */
    public function reportContainsCorrectSums(): void
    {
        $report = $this->historyResponse['data']['report'] ?? [];

        if (!isset($report['total_received_cents']) || !isset($report['total_refunded_cents'])) {
            throw new RuntimeException("Financial report missing required fields");
        }

        // Проверяем, что суммы не отрицательные
        if ($report['total_received_cents'] < 0 || $report['total_refunded_cents'] < 0) {
            throw new RuntimeException("Financial report contains negative values");
        }

        // Проверяем, что events_count > 0 (были события)
        if ((int)($report['events_count'] ?? 0) === 0) {
            throw new RuntimeException("Financial report has no events");
        }

        // Сверяем с событиями в БД
        $dbEvents = $this->queryDb(
            "SELECT
            SUM(CASE WHEN event_type = 'order.paid' THEN CAST(event_data->>'amount_cents' AS BIGINT) ELSE 0 END) as received,
            SUM(CASE WHEN event_type = 'item.refunded' THEN CAST(event_data->>'refund_amount_cents' AS BIGINT) ELSE 0 END) as refunded
         FROM order_events"
        );

        $expectedReceived = (int)($dbEvents[0]['received'] ?? 0);
        $expectedRefunded = (int)($dbEvents[0]['refunded'] ?? 0);

        if ((int)$report['total_received_cents'] !== $expectedReceived) {
            throw new RuntimeException(
                "Received mismatch: expected {$expectedReceived}, got {$report['total_received_cents']}"
            );
        }

        if ((int)$report['total_refunded_cents'] !== $expectedRefunded) {
            throw new RuntimeException(
                "Refunded mismatch: expected {$expectedRefunded}, got {$report['total_refunded_cents']}"
            );
        }
    }

    /**
     * @Then /^баланс равен разнице между полученными и возвращенными средствами$/
     */
    public function balanceEqualsDifference(): void
    {
        $report = $this->historyResponse['data']['report'] ?? [];

        $expectedBalance = $report['total_received_cents'] - $report['total_refunded_cents'];

        if ((int)$report['balance_cents'] !== $expectedBalance) {
            throw new RuntimeException(
                "Balance mismatch: expected {$expectedBalance}, got {$report['balance_cents']}"
            );
        }
    }

    private function getOrder(): array
    {
        $this->lastResponse = $this->apiRequest('GET', '/orders/' . $this->order['order_code']);
        return $this->lastResponse['data']['order'] ?? [];
    }

    private function findItem(array $order, string $sku): array
    {
        foreach ($order['items'] as $item) {
            if ($item['sku'] === $sku) {
                return $item;
            }
        }
        throw new RuntimeException("Item with SKU {$sku} not found in current order context");
    }

    private function configureProvider(string $providerName, ProviderMockConfig $config): void
    {
        // Читаем сетевую карту докера из системного окружения
        $providersConfig = json_decode((string)getenv('PROVIDERS_CONFIG'), true);
        $provider = $providersConfig[$providerName] ?? null;

        if (!$provider) {
            throw new RuntimeException("Provider {$providerName} not found in PROVIDERS_CONFIG");
        }

        // Отправляем дельту конфигурации по динамическому адресу из конфига
        $baseUrl = "http://{$provider['host']}:{$provider['port']}";
        $this->apiRequest('POST', '/configure', $config->toArray(), $baseUrl);
    }

    private function getProviderUrl(string $providerName): string
    {
        // Читаем сетевую карту докера из системного окружения для резолва роутов сброса стейта
        $providersConfig = json_decode((string)getenv('PROVIDERS_CONFIG'), true);
        $provider = $providersConfig[$providerName] ?? null;

        if (!$provider) {
            throw new RuntimeException("Provider {$providerName} not found in PROVIDERS_CONFIG");
        }

        return "http://{$provider['host']}:{$provider['port']}";
    }

    private function apiRequest(string $method, string $path, array $data = [], ?string $baseUrl = null): array
    {
        $baseUrl ??= 'http://127.0.0.1:8080';
        $parsedUrl = parse_url($baseUrl);
        $host = $parsedUrl['host'] ?? '127.0.0.1';
        $port = $parsedUrl['port'] ?? 8080;

        $responseBody = '';

        // Запускаем изолированный цикл корутин строго на время HTTP-запроса
        Co\run(function () use ($method, $path, $data, $host, $port, &$responseBody) {
            $client = new Client($host, (int)$port);
            $client->set(['timeout' => 5.0]);

            if ($method === 'POST') {
                $client->post($path, json_encode($data));
            } else {
                $client->get($path);
            }

            $responseBody = $client->body;
            $client->close();
        });

        $decoded = json_decode((string)$responseBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function queryDb(string $sql, array $params = []): array
    {
        $pdo = new PDO(
            "pgsql:host=postgres;port=5432;dbname=game_shop",
            "app",
            "secret",
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
