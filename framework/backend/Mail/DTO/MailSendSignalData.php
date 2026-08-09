<?php

declare(strict_types=1);

namespace Hilos\Mail\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * MailSendSignalData - the raw-send handoff to the mail agent pool (HIL-197).
 *
 * The second, direct intake of the mail channel (alongside the notification delivery
 * signal): Auth verification codes and magic links are sent this way, bypassing the
 * hilos_notification tables entirely so an auth secret never persists as a durable row
 * and never surfaces in the notification centre. The recipient message is either given
 * inline ({@see subject}/{@see text}/{@see html}) or named by a template
 * ({@see templateKey}/{@see params}/{@see locale}) resolved agent-side.
 *
 * {@see shardKey} routes the pooled mail agent: it is derived from the normalized
 * recipient address, so every message to one address lands on the same instance and its
 * ordering (and any future per-address rate limit) stays local to that agent.
 */
final class MailSendSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: recipient email address. */
    public const string to = 'to';

    /** Payload key: inline subject line. */
    public const string subject = 'subject';

    /** Payload key: inline plain-text body. */
    public const string text = 'text';

    /** Payload key: inline HTML body. */
    public const string html = 'html';

    /** Payload key: template key resolved agent-side. */
    public const string templateKey = 'templateKey';

    /** Payload key: template render params. */
    public const string params = 'params';

    /** Payload key: render locale. */
    public const string locale = 'locale';

    /** Payload key: pool shard key derived from the recipient address. */
    public const string shardKey = 'shardKey';

    /**
     * @param string $to Recipient email address
     * @param int $shardKey Pool shard key derived from the recipient address
     * @param ?string $subject Inline subject, or null when a template supplies it
     * @param ?string $text Inline plain-text body, or null when a template supplies it
     * @param ?string $html Inline HTML body, or null
     * @param ?string $templateKey Template key, or null for an inline message
     * @param array<string, mixed> $params Template render params
     * @param ?string $locale Render locale, or null for the project default
     * @throws ValidationException When the payload names neither a template nor an inline
     *                             subject and text
     */
    public function __construct(
        public readonly string $to,
        public readonly int $shardKey,
        public readonly ?string $subject = null,
        public readonly ?string $text = null,
        public readonly ?string $html = null,
        public readonly ?string $templateKey = null,
        public readonly array $params = [],
        public readonly ?string $locale = null,
    ) {
        if ($this->templateKey === null && ($this->subject === null || $this->text === null)) {
            throw new ValidationException(
                'Mail raw send needs a template key, or both an inline subject and text',
            );
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
            self::subject => $this->subject,
            self::text => $this->text,
            self::html => $this->html,
            self::templateKey => $this->templateKey,
            self::params => $this->params,
            self::locale => $this->locale,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws ValidationException When the payload names neither a template nor an inline
     *                             subject and text
     */
    public static function fromArray(array $data): static
    {
        $params = $data[self::params] ?? [];

        return new static(
            to: (string)($data[self::to] ?? ''),
            shardKey: (int)($data[self::shardKey] ?? 0),
            subject: isset($data[self::subject]) ? (string)$data[self::subject] : null,
            text: isset($data[self::text]) ? (string)$data[self::text] : null,
            html: isset($data[self::html]) ? (string)$data[self::html] : null,
            templateKey: isset($data[self::templateKey]) ? (string)$data[self::templateKey] : null,
            params: is_array($params) ? $params : [],
            locale: isset($data[self::locale]) ? (string)$data[self::locale] : null,
        );
    }
}
