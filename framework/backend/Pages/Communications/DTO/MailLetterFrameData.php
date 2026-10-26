<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Frame\MailLetterFrameSettings;
use Hilos\Mail\Frame\ResolvedMailLetterFrame;

/**
 * Unmasked letterFrame data with a shared field map for initial and live projection.
 * Viewer projection may replace html and previewHtml with HiddenValue on the wire.
 */
final class MailLetterFrameData extends BaseDTO
{
    public const string DATA = 'letterFrame';
    public const string channel = 'channel';
    public const string html = 'html';
    public const string source = 'source';
    public const string previewHtml = 'previewHtml';

    /**
     * @param string $channel Email channel key
     * @param string $html Effective frame HTML before placeholder expansion
     * @param string $source Settings, project or framework default
     * @param string $previewHtml Frame composed with the synthetic framework sample
     * @throws InvalidFormatException When the channel or source is outside the frame contract
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $html,
        public readonly string $source,
        public readonly string $previewHtml,
    ) {
        if ($channel !== MailDeliveryChannel::NAME) {
            throw new InvalidFormatException('Letter frame data requires the email channel.');
        }
        if (!in_array($source, [
            ResolvedMailLetterFrame::SOURCE_SETTINGS,
            ResolvedMailLetterFrame::SOURCE_PROJECT,
            ResolvedMailLetterFrame::SOURCE_DEFAULT,
        ], true)) {
            throw new InvalidFormatException('Letter frame data has an unknown source.');
        }
    }

    /**
     * @param array<string, mixed> $data Unmasked frame payload
     * @return static Frame data without value coercion
     * @throws InvalidFormatException When a field is missing, mistyped or outside the frame contract
     */
    public static function fromArray(array $data): static
    {
        return new static(
            channel: self::requireString($data, self::channel),
            html: self::requireString($data, self::html),
            source: self::requireString($data, self::source),
            previewHtml: self::requireString($data, self::previewHtml),
        );
    }

    /** @return array<string, mixed> Complete unmasked frame object */
    public function toArray(): array
    {
        return [
            self::channel => $this->channel,
            self::html => $this->html,
            self::source => $this->source,
            self::previewHtml => $this->previewHtml,
        ];
    }

    /** @return array<string, WireField> Setting-controlled HTML and public channel/source metadata */
    public static function wireFields(): array
    {
        return [
            self::channel => WireField::notPersonal(),
            self::html => WireField::setting(MailLetterFrameSettings::SETTING_KEY),
            self::source => WireField::notPersonal(),
            self::previewHtml => WireField::settings([MailLetterFrameSettings::SETTING_KEY]),
        ];
    }
}
