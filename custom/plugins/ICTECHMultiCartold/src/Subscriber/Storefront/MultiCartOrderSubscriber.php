<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Subscriber\Storefront;

use Doctrine\DBAL\Connection;
use ICTECHMultiCart\Service\MultiCartCheckoutService;
use ICTECHMultiCart\Service\MultiCartStorefrontContextService;
use Ramsey\Uuid\Uuid;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class MultiCartOrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MultiCartCheckoutService $checkoutService,
        private readonly MultiCartStorefrontContextService $contextService
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onCheckoutOrderPlaced',
        ];
    }

    public function onCheckoutOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $payload = $this->checkoutService->consumePreparedCheckout(
            $event->getSalesChannelId(),
            $event->getCustomerId()
        );

        if ($payload === null || $payload['cartNames'] === []) {
            return;
        }

        $order = $event->getOrder();
        $price = $order->getPrice();
        $promotionDiscount = abs($price->getPositionPrice() - $price->getTotalPrice());
        $primaryCartId = $payload['cartIds'][0] ?? null;
        $promotionCodeSnapshot = $promotionDiscount > 0.0
            ? $this->buildPromotionCodeSnapshot($payload['promotionCodes'])
            : null;

        $this->connection->executeStatement(
            'DELETE FROM ictech_multi_cart_order WHERE order_id = UNHEX(:orderId)',
            ['orderId' => $order->getId()]
        );

        $this->connection->insert('ictech_multi_cart_order', [
            'id' => $this->toBinary((string) Uuid::uuid4()->getHex()),
            'multi_cart_id' => $primaryCartId !== null ? $this->toBinary($primaryCartId) : null,
            'order_id' => $this->toBinary($order->getId()),
            'cart_name_snapshot' => implode(', ', $payload['cartNames']),
            'promotion_code_snapshot' => $promotionCodeSnapshot,
            'discount_snapshot' => $promotionDiscount,
            'ordered_at' => $this->now(),
        ]);

        foreach ($payload['cartIds'] as $cartId) {
            if ($promotionCodeSnapshot === null) {
                $this->connection->update('ictech_multi_cart', [
                    'promotion_code' => null,
                    'promotion_discount' => 0.0,
                    'updated_at' => $this->now(),
                ], [
                    'id' => $this->toBinary($cartId),
                ]);
            }

            $this->contextService->deleteOrResetCompletedCart(
                $cartId,
                $payload['customerId'],
                $payload['salesChannelId']
            );
        }
    }

    /**
     * @param list<string> $promotionCodes
     */
    private function buildPromotionCodeSnapshot(array $promotionCodes): ?string
    {
        $validPromotionCodes = array_values(array_filter(
            $promotionCodes,
            static fn (string $promotionCode): bool => trim($promotionCode) !== ''
        ));

        if ($validPromotionCodes === []) {
            return null;
        }

        return implode(', ', array_values(array_unique($validPromotionCodes)));
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
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
    }
}
