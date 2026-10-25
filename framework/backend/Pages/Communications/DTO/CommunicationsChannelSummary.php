<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Notification\Delivery\DeliveryChannelSettings;

/**
 * The channelSummary object in the page_response data of all three channel pages.
 *
 * Contains descriptor metadata and source counts only, never configuration values or secrets.
 * The admin-view bridge may replace enabled with HiddenValue on the wire; this DTO
 * describes the unmasked snapshot, and its field map is shared by initial and live delivery.
 */
final class CommunicationsChannelSummary extends BaseDTO
{
    /**
     * @param string $channel Channel registry key
     * @param string $label Descriptor label, falling back to the channel key only when null
     * @param bool $enabled Effective global enablement setting
     * @param bool $configured Whether no config field resolves to DEFAULT
     * @param ?string $driver Informational transport name, or null when absent
     * @param int $missingFields Config fields whose source is DEFAULT
     * @param int $fieldCount All declared config fields, excluding enablement
     * @param int $secretFieldCount Env-only secret fields
     * @param int $overriddenFieldCount Config fields whose source is SETTINGS
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $label,
        public readonly bool $enabled,
        public readonly bool $configured,
        public readonly ?string $driver,
        public readonly int $missingFields,
        public readonly int $fieldCount,
        public readonly int $secretFieldCount,
        public readonly int $overriddenFieldCount,
    ) {
    }

    /**
     * @param array<string, mixed> $data Unmasked summary payload
     * @return static Restored summary without value coercion
     * @throws InvalidFormatException When a required field is missing or a field has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            channel: self::requireString($data, 'channel'),
            label: self::requireString($data, 'label'),
            enabled: self::requireBool($data, 'enabled'),
            configured: self::requireBool($data, 'configured'),
            driver: self::optionalString($data, 'driver'),
            missingFields: self::requireInt($data, 'missingFields'),
            fieldCount: self::requireInt($data, 'fieldCount'),
            secretFieldCount: self::requireInt($data, 'secretFieldCount'),
            overriddenFieldCount: self::requireInt($data, 'overriddenFieldCount'),
        );
    }

    /**
     * @return array<string, mixed> Complete summary wire object
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'label' => $this->label,
            'enabled' => $this->enabled,
            'configured' => $this->configured,
            'driver' => $this->driver,
            'missingFields' => $this->missingFields,
            'fieldCount' => $this->fieldCount,
            'secretFieldCount' => $this->secretFieldCount,
            'overriddenFieldCount' => $this->overriddenFieldCount,
        ];
    }

    /**
     * @return array<string, WireField> Summary projection for every viewer delivery
     */
    public static function wireFields(): array
    {
        return [
            'channel' => WireField::notPersonal(),
            'label' => WireField::notPersonal(),
            'enabled' => WireField::settingFrom(static fn (array $summary): ?string =>
                is_string($summary['channel'] ?? null) ? DeliveryChannelSettings::enabledKey($summary['channel']) : null),
            'configured' => WireField::notPersonal(),
            'driver' => WireField::notPersonal(),
            'missingFields' => WireField::notPersonal(),
            'fieldCount' => WireField::notPersonal(),
            'secretFieldCount' => WireField::notPersonal(),
            'overriddenFieldCount' => WireField::notPersonal(),
        ];
    }
}
