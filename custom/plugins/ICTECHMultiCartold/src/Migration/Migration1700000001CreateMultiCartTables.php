<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1700000001CreateMultiCartTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1700000001;
    }

    public function update(Connection $connection): void
    {
        $this->createMultiCartTable($connection);
        $this->createMultiCartItemTable($connection);
        $this->createMultiCartOrderTable($connection);
        $this->createMultiCartConfigTable($connection);
        $this->createMultiCartBlacklistTable($connection);
    }

    private function createMultiCartTable(Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `ictech_multi_cart` (
                `id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `notes` VARCHAR(500) NULL,
                `status` VARCHAR(50) NOT NULL,
                `cart_token` VARCHAR(255) NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 0,
                `shipping_address_id` BINARY(16) NULL,
                `billing_address_id` BINARY(16) NULL,
                `payment_method_id` VARCHAR(255) NULL,
                `shipping_method_id` VARCHAR(255) NULL,
                `promotion_code` VARCHAR(100) NULL,
                `promotion_discount` DECIMAL(10, 2) NULL,
                `subtotal` DECIMAL(10, 2) NOT NULL DEFAULT 0,
                `total` DECIMAL(10, 2) NOT NULL DEFAULT 0,
                `currency_iso` VARCHAR(10) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.ictech_multi_cart.cart_token` (`cart_token`),
                KEY `idx.ictech_multi_cart.customer_id` (`customer_id`),
                KEY `idx.ictech_multi_cart.sales_channel_id` (`sales_channel_id`),
                KEY `idx.ictech_multi_cart.shipping_address_id` (`shipping_address_id`),
                KEY `idx.ictech_multi_cart.billing_address_id` (`billing_address_id`),
                CONSTRAINT `fk.ictech_multi_cart.customer_id`
                    FOREIGN KEY (`customer_id`)
                    REFERENCES `customer` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT `fk.ictech_multi_cart.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`)
                    REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT `fk.ictech_multi_cart.shipping_address_id`
                    FOREIGN KEY (`shipping_address_id`)
                    REFERENCES `customer_address` (`id`)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE,
                CONSTRAINT `fk.ictech_multi_cart.billing_address_id`
                    FOREIGN KEY (`billing_address_id`)
                    REFERENCES `customer_address` (`id`)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL
        );
    }

    private function createMultiCartItemTable(Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `ictech_multi_cart_item` (
                `id` BINARY(16) NOT NULL,
                `multi_cart_id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NULL,
                `product_number` VARCHAR(255) NOT NULL,
                `product_name` VARCHAR(255) NOT NULL,
                `quantity` INT(11) NOT NULL,
                `unit_price` DECIMAL(10, 2) NOT NULL DEFAULT 0,
                `total_price` DECIMAL(10, 2) NOT NULL DEFAULT 0,
                `payload` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.ictech_multi_cart_item.multi_cart_id` (`multi_cart_id`),
                KEY `idx.ictech_multi_cart_item.product_id` (`product_id`),
                CONSTRAINT `fk.ictech_multi_cart_item.multi_cart_id`
                    FOREIGN KEY (`multi_cart_id`)
                    REFERENCES `ictech_multi_cart` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT `fk.ictech_multi_cart_item.product_id`
                    FOREIGN KEY (`product_id`)
                    REFERENCES `product` (`id`)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL
        );
    }

    private function createMultiCartOrderTable(Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `ictech_multi_cart_order` (
                `id` BINARY(16) NOT NULL,
                `multi_cart_id` BINARY(16) NULL,
                `order_id` BINARY(16) NOT NULL,
                `cart_name_snapshot` VARCHAR(255) NOT NULL,
                `promotion_code_snapshot` VARCHAR(100) NULL,
                `discount_snapshot` DECIMAL(10, 2) NULL,
                `ordered_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.ictech_multi_cart_order.order_id` (`order_id`),
                KEY `idx.ictech_multi_cart_order.multi_cart_id` (`multi_cart_id`),
                CONSTRAINT `fk.ictech_multi_cart_order.multi_cart_id`
                    FOREIGN KEY (`multi_cart_id`)
                    REFERENCES `ictech_multi_cart` (`id`)
                    ON DELETE SET NULL
                    ON UPDATE CASCADE,
                CONSTRAINT `fk.ictech_multi_cart_order.order_id`
                    FOREIGN KEY (`order_id`)
                    REFERENCES `order` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL
        );
    }

    private function createMultiCartConfigTable(Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `ictech_multi_cart_config` (
                `id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `plugin_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `max_carts_per_user` INT(11) NOT NULL DEFAULT 10,
                `checkout_prefs_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `promotions_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `multi_payment_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `conflict_resolution` VARCHAR(50) NOT NULL DEFAULT 'allow_override',
                `ui_style` VARCHAR(20) NOT NULL DEFAULT 'popup',
                `after_order_action` VARCHAR(20) NOT NULL DEFAULT 'clear',
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.ictech_multi_cart_config.sales_channel_id` (`sales_channel_id`),
                CONSTRAINT `fk.ictech_multi_cart_config.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`)
                    REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL
        );
    }

    private function createMultiCartBlacklistTable(Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `ictech_multi_cart_blacklist` (
                `id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `reason` VARCHAR(500) NULL,
                `created_by` VARCHAR(255) NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.ictech_multi_cart_blacklist.customer_sales_channel` (`customer_id`, `sales_channel_id`),
                KEY `idx.ictech_multi_cart_blacklist.customer_id` (`customer_id`),
                KEY `idx.ictech_multi_cart_blacklist.sales_channel_id` (`sales_channel_id`),
                CONSTRAINT `fk.ictech_multi_cart_blacklist.customer_id`
                    FOREIGN KEY (`customer_id`)
                    REFERENCES `customer` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT `fk.ictech_multi_cart_blacklist.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`)
                    REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
