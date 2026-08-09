<?php

declare(strict_types=1);

namespace Hilos\Sms\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * SmsSendSignalData - the raw-send handoff to the SMS agent pool (HIL-285).
 *
 * The second, direct intake of the SMS channel (alongside the notification delivery
 * signal): Auth login/add verification codes are sent this way, bypassing the
 * hilos_notification tables entirely so an auth secret never persists as a durable row
 * and never surfaces in the notification centre. The recipient message is either given
 * inline ({@see text}) or named by a template ({@see templateKey}/{@see params}/{@see locale})
 * resolved agent-side. Unlike the mail raw-send, there is no subject or HTML - an SMS is a
 * single text line.
 *
 * {@see shardKey} routes the pooled SMS agent: it is derived from the E.164 recipient
 * number, so every message to one number lands on the same instance and its ordering
 * (and any future per-number rate limit) stays local to that agent.
 */
final class SmsSendSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: recipient number in E.164. */
    public const string to = 'to';

    /** Payload key: inline message text. */
    public const string text = 'text';

    /** Payload key: template key resolved agent-side. */
    public const string templateKey = 'templateKey';

    /** Payload key: template render params. */
    public const string params = 'params';

    /** Payload key: render locale. */
    public const string locale = 'locale';

    /** Payload key: pool shard key derived from the recipient number. */
    public const string shardKey = 'shardKey';

    /**
     * @param string $to Recipient number in E.164
     * @param int $shardKey Pool shard key derived from the recipient number
     * @param ?string $text Inline message text, or null when a template supplies it
     * @param ?string $templateKey Template key, or null for an inline message
     * @param array<string, mixed> $params Template render params
     * @param ?string $locale Render locale, or null for the project default
     * @throws ValidationException When the payload names neither a template nor an inline text
     */
    public function __construct(
        public readonly string $to,
        public readonly int $shardKey,
        public readonly ?string $text = null,
        public readonly ?string $templateKey = null,
        public readonly array $params = [],
        public readonly ?string $locale = null,
    ) {
        if ($this->templateKey === null && $this->text === null) {
            throw new ValidationException('SMS raw send needs a template key or an inline text');
        }
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::to => $this->to,
            self::shardKey => $this->shardKey,
            self::text => $this->text,
            self::templateKey => $this->templateKey,
            self::params => $this->params,
            self::locale => $this->locale,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws ValidationException When the payload names neither a template nor an inline text
     */
    public static function fromArray(array $data): static
    {
        $params = $data[self::params] ?? [];

        return new static(
            to: (string)($data[self::to] ?? ''),
            shardKey: (int)($data[self::shardKey] ?? 0),
            text: isset($data[self::text]) ? (string)$data[self::text] : null,
            templateKey: isset($data[self::templateKey]) ? (string)$data[self::templateKey] : null,
            params: is_array($params) ? $params : [],
            locale: isset($data[self::locale]) ? (string)$data[self::locale] : null,
        );
    }
}
