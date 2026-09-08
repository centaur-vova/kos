<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Behat\Context\Context;
use RuntimeException;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

final class FeatureContext implements Context
{
    private array $order = [];
    private array $lastResponse = [];

    /**
     * @BeforeScenario
     */
    public function prepareEnvironment(): void
    {
        // Сброс остатков
        $this->apiRequest('POST', '/reset-stock');

        // Сброс провайдеров
        $this->configureProvider('A', [
            'error_rate' => 0,
            'timeout_rate' => 0,
            'dishonest_rate' => 0,
            'blocked_skus' => [],
        ]);
        $this->configureProvider('B', [
            'error_rate' => 0,
            'timeout_rate' => 0,
            'dishonest_rate' => 0,
            'blocked_skus' => [],
        ]);
    }
    /**
     * @Given /^покупатель создал заказ с товарами "([^"]*)"$/
     */
    public function buyerCreatedOrder(string $skus): void
    {
        $skuList = array_map('trim', explode(',', $skus));
        $items = array_map(static fn (string $sku) => ['sku' => $sku], $skuList);

        $this->lastResponse = $this->apiRequest('POST', '/orders', [
            'user_id' => 'behat_test',
            'items' => $items,
        ]);

        $this->order = $this->lastResponse['data']['order'] ?? [];
    }

    /**
     * @Given /^покупатель создал заказ с товарами "([^"]*)", "([^"]*)"$/
     */
    public function buyerCreatedOrderWithTwoProducts(string $sku1, string $sku2): void
    {
        $this->buyerCreatedOrder("{$sku1},{$sku2}");
    }

    /**
     * @Given /^покупатель создал заказ с товарами "([^"]*)", "([^"]*)", "([^"]*)"$/
     */
    public function buyerCreatedOrderWithThreeProducts(string $sku1, string $sku2, string $sku3): void
    {
        $this->buyerCreatedOrder("{$sku1},{$sku2},{$sku3}");
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
     * @Given /^поставщик A не может выдать "([^"]*)"$/
     */
    public function providerACannotDeliver(string $sku): void
    {
        $this->configureProvider('A', [
            'error_rate' => 0,
            'timeout_rate' => 0,
            'dishonest_rate' => 0,
            'blocked_skus' => [$sku],
        ]);
    }

    /**
     * @Given /^ни один поставщик не может выдать "([^"]*)"$/
     */
    public function noProviderCanDeliver(string $sku): void
    {
        $this->configureProvider('A', [
            'error_rate' => 0,
            'timeout_rate' => 0,
            'dishonest_rate' => 0,
            'blocked_skus' => [$sku],
        ]);
        $this->configureProvider('B', [
            'error_rate' => 0,
            'timeout_rate' => 0,
            'dishonest_rate' => 0,
            'blocked_skus' => [$sku],
        ]);
    }

    /**
     * @Given /^все поставщики не могут выдать "([^"]*)"$/
     */
    public function allProvidersCannotDeliver(string $sku): void
    {
        $this->configureProvider('A', ['error_rate' => 100, 'timeout_rate' => 0, 'dishonest_rate' => 0]);
        $this->configureProvider('B', ['error_rate' => 100, 'timeout_rate' => 0, 'dishonest_rate' => 0]);
    }

    /**
     * @Given /^все поставщики недоступны$/
     */
    public function allProvidersUnavailable(): void
    {
        $this->configureProvider('A', ['error_rate' => 100, 'timeout_rate' => 0, 'dishonest_rate' => 0]);
        $this->configureProvider('B', ['error_rate' => 100, 'timeout_rate' => 0, 'dishonest_rate' => 0]);
    }

    /**
     * @When /^система обрабатывает выдачу$/
     */
    public function systemProcessesDelivery(): void
    {
        run(function () {
            Coroutine::sleep(3.0);
        });
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

        if (count($order['items']) !== 1) {
            throw new RuntimeException("Expected 1 item, got " . count($order['items']));
        }

        $item = $order['items'][0];

        if ($item['status'] !== $status) {
            throw new RuntimeException("Item {$item['sku']} has status {$item['status']}, expected {$status}");
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

        throw new RuntimeException("Refund not found for SKU {$sku}");
    }

    /**
     * @Then /^создан возврат на полную сумму$/
     */
    public function refundCreatedForFullAmount(): void
    {
        $order = $this->getOrder();

        if (empty($order['refunds'])) {
            throw new RuntimeException("No refunds found");
        }

        $refundSum = array_reduce(
            $order['refunds'],
            static fn (float $sum, array $refund) => $sum + (float)$refund['amount_cents'] / 100,
            0.0,
        );

        if (abs($refundSum - (float)$order['price']) > 0.01) {
            throw new RuntimeException("Refund sum {$refundSum} != order price {$order['price']}");
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
            throw new RuntimeException("Delivered sum {$deliveredSum} != paid {$order['price']}");
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
            throw new RuntimeException("Delivered {$deliveredSum} + refund {$refundSum} != paid {$order['price']}");
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

        throw new RuntimeException("Item {$sku} not found");
    }

    private function configureProvider(string $providerName, array $config): void
    {
        $providersConfig = json_decode((string)getenv('PROVIDERS_CONFIG'), true);
        $provider = $providersConfig[$providerName] ?? null;

        if (!$provider) {
            throw new RuntimeException("Provider {$providerName} not found in PROVIDERS_CONFIG");
        }

        $this->apiRequest('POST', '/configure', $config, "http://{$provider['host']}:{$provider['port']}");
    }

    private function apiRequest(string $method, string $path, array $data = [], ?string $baseUrl = null): array
    {
        $baseUrl ??= 'http://127.0.0.1:8080';
        $parsedUrl = parse_url($baseUrl);

        $host = $parsedUrl['host'] ?? '127.0.0.1';
        $port = $parsedUrl['port'] ?? 80;

        $responseBody = '';

        run(function () use ($method, $path, $data, $host, $port, &$responseBody) {
            $client = new Coroutine\Http\Client($host, $port);
            $client->set(['timeout' => 5.0]);

            if ($method === 'POST') {
                $client->post($path, json_encode($data));
            } else {
                $client->get($path);
            }

            $responseBody = $client->body;
            $client->close();
        });

        $decoded = json_decode($responseBody, true);
        return is_array($decoded) ? $decoded : [];
    }
}
