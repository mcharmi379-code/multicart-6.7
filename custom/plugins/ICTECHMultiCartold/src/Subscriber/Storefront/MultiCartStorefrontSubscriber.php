<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Subscriber\Storefront;

use ICTECHMultiCart\Service\MultiCartStorefrontContextService;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Pagelet\Header\HeaderPageletLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class MultiCartStorefrontSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MultiCartStorefrontContextService $storefrontContextService
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CustomerLoginEvent::class => 'onCustomerLogin',
            CustomerRegisterEvent::class => 'onCustomerRegister',
            StorefrontRenderEvent::class => 'onStorefrontRender',
            HeaderPageletLoadedEvent::class => 'onHeaderPageletLoaded',
        ];
    }

    public function onCustomerLogin(CustomerLoginEvent $event): void
    {
        $this->storefrontContextService->bootstrapCustomerCarts($event->getSalesChannelContext());
    }

    public function onCustomerRegister(CustomerRegisterEvent $event): void
    {
        $this->storefrontContextService->bootstrapCustomerCarts($event->getSalesChannelContext());
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $event->setParameter(
            'ictechMultiCart',
            $this->storefrontContextService->buildStruct($event->getSalesChannelContext())
        );

        $parameters = $event->getParameters();
        $page = $parameters['page'] ?? null;
        $orderIds = [];

        if (is_object($page) && method_exists($page, 'getOrder')) {
            $order = $page->getOrder();

            if ($order instanceof OrderEntity) {
                $orderIds[] = $order->getId();
            }
        }

        if (is_object($page) && method_exists($page, 'getOrders')) {
            $orders = $page->getOrders();

            if (is_iterable($orders)) {
                foreach ($orders as $order) {
                    if ($order instanceof OrderEntity) {
                        $orderIds[] = $order->getId();
                    }
                }
            }
        }

        $event->setParameter(
            'ictechMultiCartOrders',
            $this->storefrontContextService->getOrderMetaMap(array_values(array_unique($orderIds)))
        );
    }

    public function onHeaderPageletLoaded(HeaderPageletLoadedEvent $event): void
    {
        $event->getPagelet()->addExtension(
            'ictechMultiCart',
            $this->storefrontContextService->buildStruct($event->getSalesChannelContext())
        );
    }
}
