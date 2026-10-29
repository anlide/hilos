<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Database\View\Item\StatusWork;
use Hilos\Status\Exception\StatusException;

/** Detached public announcement; its identity never contains a database primary key. */
final readonly class StatusPublicWork
{
    /** @var list<string> Selected catalog set in canonical string order */
    public array $componentKeys;

    /**
     * @param string $id Opaque public work identity
     * @param string $title Public announcement title
     * @param ?string $description Public text, or null
     * @param list<string> $componentKeys Selected catalog keys
     * @param int $startsAt Inclusive scheduled start in UTC epoch milliseconds
     * @param int $endsAt Exclusive scheduled end in UTC epoch milliseconds
     * @throws StatusException When identity, keys or interval are invalid
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        array $componentKeys,
        public int $startsAt,
        public int $endsAt,
    ) {
        StatusPublicJson::text($id);
        StatusPublicJson::text($title);
        $this->componentKeys = StatusPublicJson::componentKeys($componentKeys);
        if ($startsAt < 0 || $endsAt <= $startsAt) {
            throw new StatusException('Public work requires a nonempty scheduled interval.');
        }
    }

    /**
     * @param StatusWork $work Temporary View source; no live references survive
     * @return self Sanitized public announcement
     * @throws StatusException When public values are invalid
     */
    public static function fromWork(StatusWork $work): self
    {
        $keys = [];
        foreach ($work->components as $component) {
            $keys[] = $component->componentKey;
        }
        return new self($work->publicId, $work->title, $work->description, $keys, $work->startsAt, $work->endsAt);
    }

    /**
     * @param array<string, mixed> $data Exact decoded public fields
     * @return self Detached announcement
     * @throws StatusException When a public value is invalid
     */
    public static function fromArray(array $data): self
    {
        return new self(
            StatusPublicJson::text($data['id']), StatusPublicJson::text($data['title']),
            $data['description'] === null ? null : StatusPublicJson::text($data['description'], true),
            StatusPublicJson::componentKeys($data['componentKeys']),
            StatusPublicJson::integer($data['startsAt']), StatusPublicJson::integer($data['endsAt']),
        );
    }

    /** @return array<string, mixed> Complete public v2 work fields */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'description' => $this->description,
            'componentKeys' => $this->componentKeys, 'startsAt' => $this->startsAt, 'endsAt' => $this->endsAt,
        ];
    }
}
