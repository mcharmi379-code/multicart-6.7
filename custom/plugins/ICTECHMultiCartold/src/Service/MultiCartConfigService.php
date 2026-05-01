<?php

declare(strict_types=1);

namespace ICTECHMultiCart\Service;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class MultiCartConfigService
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array{
     *     pluginEnabled: bool,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string,
     *     uiStyle: string
     * }
     */
    public function getConfig(string $salesChannelId): array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT plugin_enabled, max_carts_per_user, checkout_prefs_enabled, promotions_enabled, multi_payment_enabled, conflict_resolution, ui_style
             FROM ictech_multi_cart_config
             WHERE sales_channel_id = UNHEX(:salesChannelId)
             LIMIT 1',
            ['salesChannelId' => $salesChannelId]
        );

        if ($row === false) {
            return $this->getDefaultConfig();
        }

        return $this->normalizeConfigRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStoredConfig(string $salesChannelId): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT HEX(id) AS id, HEX(sales_channel_id) AS salesChannelId, plugin_enabled AS pluginEnabled, max_carts_per_user AS maxCartsPerUser, checkout_prefs_enabled AS checkoutPrefsEnabled, promotions_enabled AS promotionsEnabled, multi_payment_enabled AS multiPaymentEnabled, conflict_resolution AS conflictResolution, ui_style AS uiStyle
             FROM ictech_multi_cart_config
             WHERE sales_channel_id = UNHEX(:salesChannelId)
             LIMIT 1',
            ['salesChannelId' => $salesChannelId]
        );

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     pluginEnabled: bool,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string,
     *     uiStyle: string
     * }
     */
    public function saveConfig(string $salesChannelId, array $payload): array
    {
        $config = $this->normalizeConfigRow($payload);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $this->connection->executeStatement(
            'INSERT INTO ictech_multi_cart_config (id, sales_channel_id, plugin_enabled, max_carts_per_user, checkout_prefs_enabled, promotions_enabled, multi_payment_enabled, conflict_resolution, ui_style, created_at, updated_at)
             VALUES (UNHEX(:id), UNHEX(:salesChannelId), :pluginEnabled, :maxCartsPerUser, :checkoutPrefsEnabled, :promotionsEnabled, :multiPaymentEnabled, :conflictResolution, :uiStyle, :createdAt, :updatedAt)
             ON DUPLICATE KEY UPDATE
                 plugin_enabled = VALUES(plugin_enabled),
                 max_carts_per_user = VALUES(max_carts_per_user),
                 checkout_prefs_enabled = VALUES(checkout_prefs_enabled),
                 promotions_enabled = VALUES(promotions_enabled),
                 multi_payment_enabled = VALUES(multi_payment_enabled),
                 conflict_resolution = VALUES(conflict_resolution),
                 ui_style = VALUES(ui_style),
                 updated_at = VALUES(updated_at)',
            [
                'id' => Uuid::uuid4()->getHex(),
                'salesChannelId' => $salesChannelId,
                'pluginEnabled' => (int) $config['pluginEnabled'],
                'maxCartsPerUser' => $config['maxCartsPerUser'],
                'checkoutPrefsEnabled' => (int) $config['checkoutPrefsEnabled'],
                'promotionsEnabled' => (int) $config['promotionsEnabled'],
                'multiPaymentEnabled' => (int) $config['multiPaymentEnabled'],
                'conflictResolution' => $config['conflictResolution'],
                'uiStyle' => $config['uiStyle'],
                'createdAt' => $now,
                'updatedAt' => $now,
            ]
        );

        return $config;
    }

    /**
     * @return array{
     *     pluginEnabled: bool,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string,
     *     uiStyle: string
     * }
     */
    public function getDefaultConfig(): array
    {
        return [
            'pluginEnabled' => false,
            'maxCartsPerUser' => 10,
            'checkoutPrefsEnabled' => true,
            'promotionsEnabled' => true,
            'multiPaymentEnabled' => true,
            'conflictResolution' => 'allow_override',
            'uiStyle' => 'popup',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     pluginEnabled: bool,
     *     maxCartsPerUser: int,
     *     checkoutPrefsEnabled: bool,
     *     promotionsEnabled: bool,
     *     multiPaymentEnabled: bool,
     *     conflictResolution: string,
     *     uiStyle: string
     * }
     */
    private function normalizeConfigRow(array $row): array
    {
        return [
            'pluginEnabled' => $this->toBool($row['pluginEnabled'] ?? $row['plugin_enabled'] ?? null, true),
            'maxCartsPerUser' => $this->toInt($row['maxCartsPerUser'] ?? $row['max_carts_per_user'] ?? null, 10),
            'checkoutPrefsEnabled' => $this->toBool($row['checkoutPrefsEnabled'] ?? $row['checkout_prefs_enabled'] ?? null, true),
            'promotionsEnabled' => $this->toBool($row['promotionsEnabled'] ?? $row['promotions_enabled'] ?? null, true),
            'multiPaymentEnabled' => $this->toBool($row['multiPaymentEnabled'] ?? $row['multi_payment_enabled'] ?? null, true),
            'conflictResolution' => $this->toString($row['conflictResolution'] ?? $row['conflict_resolution'] ?? null, 'allow_override'),
            'uiStyle' => $this->normalizeUiStyle($this->toString($row['uiStyle'] ?? $row['ui_style'] ?? null, 'popup')),
        ];
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalizedValue = strtolower(trim($value));

            if (in_array($normalizedValue, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalizedValue, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    private function toInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function toString(mixed $value, string $default): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return $default;
    }

    private function normalizeUiStyle(string $value): string
    {
        return in_array($value, ['popup', 'drawer'], true) ? $value : 'popup';
    }
}
