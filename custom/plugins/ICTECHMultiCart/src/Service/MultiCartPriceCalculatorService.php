<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class MultiCartPriceCalculatorService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactoryRegistry,
        private readonly PromotionItemBuilder $promotionItemBuilder
    ) {
    }

    /**
     * @return array{
     *     subtotal: float,
     *     total: float,
     *     discount: float,
     *     promotionCode: string,
     *     promotionApplied: bool,
     *     promotionErrors: list<array{
     *         messageKey: string,
     *         message: string,
     *         parameters: array<string, mixed>
     *     }>
     * }
     */
    public function recalculateCart(string $cartId, SalesChannelContext $salesChannelContext): array
    {
        /** @var array<string, mixed>|false $cart */
        $cart = $this->connection->fetchAssociative(
            'SELECT promotion_code
             FROM ictech_multi_cart
             WHERE id = UNHEX(:cartId)
             LIMIT 1',
            ['cartId' => $cartId]
        );

        if ($cart === false) {
            return [
                'subtotal' => 0.0,
                'total' => 0.0,
                'discount' => 0.0,
                'promotionCode' => '',
                'promotionApplied' => false,
                'promotionErrors' => [],
            ];
        }

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(product_id)) AS productId, quantity
             FROM ictech_multi_cart_item
             WHERE multi_cart_id = UNHEX(:cartId)
             ORDER BY created_at ASC',
            ['cartId' => $cartId]
        );

        $subtotal = 0.0;
        $discount = 0.0;
        $total = 0.0;
        $promotionCode = is_string($cart['promotion_code'] ?? null) ? trim((string) $cart['promotion_code']) : '';
        $promotionApplied = $promotionCode === '';
        $promotionErrors = [];

        $token = (string) Uuid::uuid4()->getHex();
        $shopwareCart = $this->cartService->createNew($token);

        if ($items !== []) {
            foreach ($items as $item) {
                $productId = is_string($item['productId'] ?? null) ? $item['productId'] : null;
                $quantity = is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0;

                if ($productId === null || $productId === '' || $quantity <= 0) {
                    continue;
                }

                $lineItem = $this->lineItemFactoryRegistry->create([
                    'id' => $productId,
                    'referencedId' => $productId,
                    'type' => 'product',
                    'quantity' => $quantity,
                    'stackable' => true,
                    'removable' => true,
                ], $salesChannelContext);

                $shopwareCart = $this->cartService->add($shopwareCart, $lineItem, $salesChannelContext);
            }
        }

        if ($promotionCode !== '') {
            $promotionItem = $this->promotionItemBuilder->buildPlaceholderItem($promotionCode);
            $shopwareCart = $this->cartService->add($shopwareCart, $promotionItem, $salesChannelContext);
        }

        $shopwareCart = $this->cartService->recalculate($shopwareCart, $salesChannelContext);

        if ($items !== []) {
            $subtotal = $this->synchronizeLineItemPrices($cartId, $items, $shopwareCart->getLineItems());
            $price = $shopwareCart->getPrice();
            $total = (float) $price->getTotalPrice();
            $discount = $this->calculatePromotionDiscount($shopwareCart);
        } else {
            $this->resetItemPrices($cartId);
        }

        $promotionErrors = $this->extractPromotionErrors($shopwareCart, $promotionCode);
        $promotionApplied = $promotionCode === ''
            || ($promotionErrors === [] && $this->hasAppliedPromotion($shopwareCart, $promotionCode));

        $this->connection->update(
            'ictech_multi_cart',
            [
                'subtotal' => $subtotal,
                'total' => $total,
                'promotion_discount' => $discount,
                'updated_at' => $this->now(),
            ],
            ['id' => $this->toBinary($cartId)]
        );

        return [
            'subtotal' => $subtotal,
            'total' => $total,
            'discount' => $discount,
            'promotionCode' => $promotionCode,
            'promotionApplied' => $promotionApplied,
            'promotionErrors' => $promotionErrors,
        ];
    }

    /**
     * @param list<string> $cartIds
     */
    public function recalculateCarts(array $cartIds, SalesChannelContext $salesChannelContext): void
    {
        $validCartIds = array_values(array_filter($cartIds, static fn (string $cartId): bool => $cartId !== ''));

        if ($validCartIds === []) {
            return;
        }

        foreach ($validCartIds as $cartId) {
            $this->recalculateCart($cartId, $salesChannelContext);
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function synchronizeLineItemPrices(string $cartId, array $items, LineItemCollection $lineItems): float
    {
        $subtotal = 0.0;
        $indexedLineItems = [];

        foreach ($lineItems->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $referencedId = $lineItem->getReferencedId();

            if (!is_string($referencedId) || $referencedId === '') {
                continue;
            }

            $indexedLineItems[strtolower($referencedId)] = $lineItem;
        }

        foreach ($items as $item) {
            $itemId = is_string($item['id'] ?? null) ? strtolower($item['id']) : '';
            $productId = is_string($item['productId'] ?? null) ? strtolower($item['productId']) : '';

            if ($itemId === '' || $productId === '') {
                continue;
            }

            $lineItem = $indexedLineItems[$productId] ?? null;
            $price = $lineItem?->getPrice();
            $unitPrice = $price !== null ? (float) $price->getUnitPrice() : 0.0;
            $totalPrice = $price !== null ? (float) $price->getTotalPrice() : 0.0;

            $this->connection->update(
                'ictech_multi_cart_item',
                [
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'updated_at' => $this->now(),
                ],
                [
                    'id' => $this->toBinary($itemId),
                    'multi_cart_id' => $this->toBinary($cartId),
                ]
            );

            $subtotal += $totalPrice;
        }

        return $subtotal;
    }

    private function resetItemPrices(string $cartId): void
    {
        $this->connection->update(
            'ictech_multi_cart_item',
            [
                'unit_price' => 0.0,
                'total_price' => 0.0,
                'updated_at' => $this->now(),
            ],
            ['multi_cart_id' => $this->toBinary($cartId)]
        );
    }

    private function calculatePromotionDiscount(\Shopware\Core\Checkout\Cart\Cart $cart): float
    {
        $discount = 0.0;

        foreach ($cart->getLineItems()->filterFlatByType(LineItem::PROMOTION_LINE_ITEM_TYPE) as $lineItem) {
            $price = $lineItem->getPrice();

            if ($price === null) {
                continue;
            }

            $discount += abs((float) $price->getTotalPrice());
        }

        return $discount;
    }

    /**
     * @return list<array{
     *     messageKey: string,
     *     message: string,
     *     parameters: array<string, mixed>
     * }>
     */
    private function extractPromotionErrors(\Shopware\Core\Checkout\Cart\Cart $cart, string $promotionCode): array
    {
        if ($promotionCode === '') {
            return [];
        }

        $errors = [];

        /** @var Error $error */
        foreach ($cart->getErrors() as $error) {
            $messageKey = $error->getMessageKey();

            if (!in_array($messageKey, [
                'promotion-not-found',
                'promotion-not-eligible',
                'promotion-excluded',
                'promotions-on-cart-price-zero-error',
            ], true)) {
                continue;
            }

            $errors[] = [
                'messageKey' => $messageKey,
                'message' => $error->getMessage(),
                'parameters' => $error->getParameters(),
            ];
        }

        return $errors;
    }

    private function hasAppliedPromotion(\Shopware\Core\Checkout\Cart\Cart $cart, string $promotionCode): bool
    {
        if ($promotionCode === '') {
            return false;
        }

        foreach ($cart->getLineItems()->filterFlatByType(LineItem::PROMOTION_LINE_ITEM_TYPE) as $lineItem) {
            $referencedId = $lineItem->getReferencedId();
            $label = $lineItem->getLabel() ?? '';

            if (
                is_string($referencedId)
                && strcasecmp($referencedId, $promotionCode) === 0
                && $label !== PromotionItemBuilder::PLACEHOLDER_PREFIX . $promotionCode
            ) {
                return true;
            }
        }

        return false;
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
}
