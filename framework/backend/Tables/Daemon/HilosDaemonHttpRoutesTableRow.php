<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One registered HTTP route or the unrouted tally in a node's daemon picture. */
final class HilosDaemonHttpRoutesTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string method = 'method';
    public const string path = 'path';
    public const string agentType = 'agentType';
    public const string requests = 'requests';
    public const string serverErrors = 'serverErrors';
    public const string slowestMs = 'slowestMs';

    /**
     * @param string $rowKey Encoded method and path, or the unrouted key
     * @param ?string $method Registered HTTP method, null for unrouted requests
     * @param ?string $path Registered path template, null for unrouted requests
     * @param ?string $agentType Answering agent, if any
     * @param int $requests Requests in the current window
     * @param int $serverErrors Answers with a 5xx status
     * @param ?int $slowestMs Longest duration, null when no requests arrived
     */
    public function __construct(
        public string $rowKey,
        public ?string $method,
        public ?string $path,
        public ?string $agentType,
        public int $requests,
        public int $serverErrors,
        public ?int $slowestMs,
    ) {
    }

    /** @return string Stable row key */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Payload key carrying the row key */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> HTTP route payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::method => $this->method,
            self::path => $this->path,
            self::agentType => $this->agentType,
            self::requests => $this->requests,
            self::serverErrors => $this->serverErrors,
            self::slowestMs => $this->slowestMs,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw HTTP route payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            method: self::optionalString($data, self::method),
            path: self::optionalString($data, self::path),
            agentType: self::optionalString($data, self::agentType),
            requests: self::requireInt($data, self::requests),
            serverErrors: self::requireInt($data, self::serverErrors),
            slowestMs: self::optionalInt($data, self::slowestMs),
        );
    }
}
