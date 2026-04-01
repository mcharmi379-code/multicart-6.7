<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1700000003AddIsDefaultToMultiCart extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1700000003;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `ictech_multi_cart` LIKE "is_default"');

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement('ALTER TABLE `ictech_multi_cart` ADD COLUMN `is_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cart_token`');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
