<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1700000002AddUpdatedAtToBlacklist extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1700000002;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `ictech_multi_cart_blacklist` LIKE "updated_at"');

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement('ALTER TABLE `ictech_multi_cart_blacklist` ADD COLUMN `updated_at` DATETIME(3) NULL AFTER `created_at`');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
