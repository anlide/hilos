<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/** Change a work announcement through the Status owner. */
final class StatusChangeWorkSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string workId = 'workId';
    public const string publicId = 'publicId';
    public const string expectedVersion = 'expectedVersion';
    public const string title = 'title';
    public const string description = 'description';
    public const string componentKeys = 'componentKeys';
    public const string startsAt = 'startsAt';
    public const string endsAt = 'endsAt';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    private const int MAX_REQUEST_ID_CHARACTERS = 128;

    /**
     * @param int $workId Captured work primary id
     * @param string $publicId Immutable public work identity
     * @param int $expectedVersion Captured domain version
     * @param string $title Public announcement title
     * @param ?string $description Optional public description
     * @param list<string> $componentKeys Selected catalog-key values
     * @param int $startsAt Inclusive start in UTC epoch milliseconds
     * @param int $endsAt Exclusive end in UTC epoch milliseconds
     * @param string $replySignal Gatekeeper reply name
     * @param string $acceptKey Initiating browser connection
     * @param ?string $requestId Envelope correlation, or null
     * @param string $action Initiating page action
     * @param ?string $successMessage Optional success sentence
     * @throws InvalidFormatException When target, list or handover fields have an invalid shape
     */
    public function __construct(
        public readonly int $workId,
        public readonly string $publicId,
        public readonly int $expectedVersion,
        public readonly string $title,
        public readonly ?string $description,
        public readonly array $componentKeys,
        public readonly int $startsAt,
        public readonly int $endsAt,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($workId <= 0 || $expectedVersion <= 0 || preg_match('/^[a-f0-9]{32}$/D', $publicId) !== 1) {
            throw new InvalidFormatException('Choose the current work announcement and its version.');
        }
        if (!array_is_list($componentKeys)) {
            throw new InvalidFormatException('Choose a list of status component keys.');
        }
        foreach ($componentKeys as $key) {
            if (!is_string($key)) {
                throw new InvalidFormatException('Every status component key must be a string.');
            }
        }
        if (trim($replySignal) === '' || trim($acceptKey) === '' || trim($action) === '') {
            throw new InvalidFormatException('Provide the handover reply, connection and action.');
        }
        if ($requestId !== null && (!mb_check_encoding($requestId, 'UTF-8')
            || mb_strlen($requestId, 'UTF-8') > self::MAX_REQUEST_ID_CHARACTERS)) {
            throw new InvalidFormatException('The status request id must contain at most 128 characters.');
        }
    }

    /**
     * @param array<string, mixed> $data Complete internal ask
     * @return static Strictly typed change request
     * @throws InvalidFormatException When a required field is missing or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            workId: self::requireInt($data, self::workId),
            publicId: self::requireString($data, self::publicId),
            expectedVersion: self::requireInt($data, self::expectedVersion),
            title: self::requireString($data, self::title),
            description: self::requireNullableString($data, self::description),
            componentKeys: self::requireArray($data, self::componentKeys),
            startsAt: self::requireInt($data, self::startsAt),
            endsAt: self::requireInt($data, self::endsAt),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::requireNullableString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::requireNullableString($data, self::successMessage),
        );
    }

    /** @return array<string, mixed> Business fields and handover metadata */
    public function toArray(): array
    {
        return [
            self::workId => $this->workId,
            self::publicId => $this->publicId,
            self::expectedVersion => $this->expectedVersion,
            self::title => $this->title,
            self::description => $this->description,
            self::componentKeys => $this->componentKeys,
            self::startsAt => $this->startsAt,
            self::endsAt => $this->endsAt,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
