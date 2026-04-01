<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class MultiCartStorefrontItemService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MultiCartPriceCalculatorService $priceCalculator
    ) {
    }

    /**
     * @return array{cartId: string, cartName: string, itemCount: int, total: float}
     */
    public function addProductToCart(
        string $cartId,
        string $customerId,
        SalesChannelContext $salesChannelContext,
        string $productId,
        int $quantity,
        ?string $fallbackProductName
    ): array {
        $cartName = $this->getCartName($cartId, $customerId, $salesChannelContext->getSalesChannelId());

        if ($cartName === null) {
            throw new \InvalidArgumentException('The selected cart could not be found.');
        }

        $productData = $this->getProductData($productId, $salesChannelContext->getCurrencyId(), $fallbackProductName);
        $normalizedQuantity = max(1, $quantity);
        $unitPrice = $productData['unitPrice'];
        $existingItemId = $this->getExistingItemId($cartId, $productId);

        if ($existingItemId !== null) {
            $existingQuantity = $this->getExistingQuantity($existingItemId);
            $newQuantity = $existingQuantity + $normalizedQuantity;

            $this->connection->update(
                'ictech_multi_cart_item',
                [
                    'quantity' => $newQuantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $newQuantity,
                    'product_number' => $productData['productNumber'],
                    'product_name' => $productData['productName'],
                    'updated_at' => $this->now(),
                ],
                ['id' => $this->toBinary($existingItemId)]
            );
        } else {
            $this->connection->insert('ictech_multi_cart_item', [
                'id' => $this->toBinary((string) Uuid::uuid4()->getHex()),
                'multi_cart_id' => $this->toBinary($cartId),
                'product_id' => $this->toBinary($productId),
                'product_number' => $productData['productNumber'],
                'product_name' => $productData['productName'],
                'quantity' => $normalizedQuantity,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $normalizedQuantity,
                'payload' => json_encode([
                    'productId' => $productId,
                    'productName' => $productData['productName'],
                ], JSON_THROW_ON_ERROR),
                'created_at' => $this->now(),
                'updated_at' => null,
            ]);
        }

        return $this->refreshCartTotals($cartId, $cartName, $salesChannelContext);
    }

    private function getCartName(string $cartId, string $customerId, string $salesChannelId): ?string
    {
        /** @var mixed $value */
        $value = $this->connection->fetchOne(
            'SELECT name
             FROM ictech_multi_cart
             WHERE id = UNHEX(:cartId)
               AND customer_id = UNHEX(:customerId)
               AND sales_channel_id = UNHEX(:salesChannelId)
             LIMIT 1',
            [
                'cartId' => $cartId,
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelId,
            ]
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{productNumber: string, productName: string, unitPrice: float}
     */
    private function getProductData(string $productId, string $currencyId, ?string $fallbackProductName): array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT product_number AS productNumber, price
             FROM product
             WHERE id = UNHEX(:productId)
             LIMIT 1',
            ['productId' => $productId]
        );

        if ($row === false) {
            throw new \InvalidArgumentException('The selected product could not be found.');
        }

        $productNumber = is_string($row['productNumber'] ?? null) ? $row['productNumber'] : $productId;
        $productName = $fallbackProductName !== null && trim($fallbackProductName) !== '' ? trim($fallbackProductName) : $productNumber;

        return [
            'productNumber' => $productNumber,
            'productName' => $productName,
            'unitPrice' => $this->extractUnitPrice($row['price'] ?? null, $currencyId),
        ];
    }

    private function extractUnitPrice(mixed $rawPrice, string $currencyId): float
    {
        if (!is_string($rawPrice) || $rawPrice === '') {
            return 0.0;
        }

        $decodedPrice = json_decode($rawPrice, true);

        if (!is_array($decodedPrice)) {
            return 0.0;
        }

        foreach ($decodedPrice as $priceDefinition) {
            if (!is_array($priceDefinition)) {
                continue;
            }

            if (($priceDefinition['currencyId'] ?? null) === $currencyId && is_numeric($priceDefinition['gross'] ?? null)) {
                return (float) $priceDefinition['gross'];
            }
        }

        foreach ($decodedPrice as $priceDefinition) {
            if (!is_array($priceDefinition)) {
                continue;
            }

            if (is_numeric($priceDefinition['gross'] ?? null)) {
                return (float) $priceDefinition['gross'];
            }
        }

        return 0.0;
    }

    private function getExistingItemId(string $cartId, string $productId): ?string
    {
        /** @var mixed $value */
        $value = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id))
             FROM ictech_multi_cart_item
             WHERE multi_cart_id = UNHEX(:cartId)
               AND product_id = UNHEX(:productId)
             LIMIT 1',
            [
                'cartId' => $cartId,
                'productId' => $productId,
            ]
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function getExistingQuantity(string $itemId): int
    {
        /** @var mixed $value */
        $value = $this->connection->fetchOne(
            'SELECT quantity
             FROM ictech_multi_cart_item
             WHERE id = UNHEX(:itemId)
             LIMIT 1',
            ['itemId' => $itemId]
        );

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function toBinary(string $value): string
    {
        $binaryValue = hex2bin($value);

        if ($binaryValue === false) {
            throw new \InvalidArgumentException('Invalid hexadecimal identifier provided.');
        }

        return $binaryValue;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
    }

    /**
     * @return array{cartId: string, cartName: string, itemCount: int, total: float}
     */
    private function refreshCartTotals(string $cartId, string $cartName, SalesChannelContext $salesChannelContext): array
    {
        /** @var array<string, mixed>|false $totals */
        $totals = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(total_price), 0) AS totalAmount,
                    COALESCE(SUM(quantity), 0) AS totalQuantity
             FROM ictech_multi_cart_item
             WHERE multi_cart_id = UNHEX(:cartId)',
            ['cartId' => $cartId]
        );

        $total = 0.0;
        $itemCount = 0;

        if (is_array($totals)) {
            $total = is_numeric($totals['totalAmount'] ?? null) ? (float) $totals['totalAmount'] : 0.0;
            $itemCount = is_numeric($totals['totalQuantity'] ?? null) ? (int) $totals['totalQuantity'] : 0;
        }

        $this->priceCalculator->recalculateCart($cartId, $salesChannelContext);

        /** @var array<string, mixed>|false $cartTotals */
        $cartTotals = $this->connection->fetchAssociative(
            'SELECT total
             FROM ictech_multi_cart
             WHERE id = UNHEX(:cartId)
             LIMIT 1',
            ['cartId' => $cartId]
        );

        if (is_array($cartTotals) && is_numeric($cartTotals['total'] ?? null)) {
            $total = (float) $cartTotals['total'];
        }

        return [
            'cartId' => $cartId,
            'cartName' => $cartName,
            'itemCount' => $itemCount,
            'total' => $total,
        ];
    }
}
