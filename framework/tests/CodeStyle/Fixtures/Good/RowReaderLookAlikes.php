<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * Negative sample: none of these is a finding, and the rule has to stay silent on
 * every one of them. The `optional*` family read where a whole row is handed over,
 * the `patch*` family read where a diff is, a key kept by hand with
 * `array_key_exists()`, absence spelled as null or as an empty section, one
 * occurrence named in place by its marker, a name of the `optional*` family
 * written in a diff body without being called, and the same fallback in a method
 * whose name is not on the list at all.
 */
final class RowReaderLookAlikes
{
    /** Keys the row may leave empty, named once so both readers below stay in step. */
    private const array OPTIONAL_KEYS = ['note'];

    private string $id = 'row';

    private int $jobsDone = 0;

    private string $title = 'none';

    private ?string $note = null;

    /** @var array<string, mixed> */
    private array $payload = [];

    /**
     * @param array<string, mixed> $row Runtime row to read
     * @return self Sample built from the row
     */
    public static function fromRow(array $row): self
    {
        $state = new self();
        $state->id = self::requireString($row, 'id');
        foreach (self::OPTIONAL_KEYS as $key) {
            $state->note = self::optionalString($row, $key);
        }

        $state->payload = $row['payload'] ?? [];

        return $state;
    }

    /**
     * @param array<string, mixed> $row Runtime row to read
     */
    public function hydrateOwn(array $row): void
    {
        $this->note = $row['note'] ?? null;
        // external-boundary: the title column is NOT NULL, so the driver always hands its stored value over
        $this->title = (string)($row['title'] ?? '');
    }

    /**
     * @param array<string, mixed> $diff Fields the update carries
     */
    public function applyDiff(array $diff): void
    {
        $this->jobsDone = self::patchInt($diff, 'jobsDone', $this->jobsDone);
        foreach (self::OPTIONAL_KEYS as $key) {
            $this->note = self::patchOptionalString($diff, $key, $this->note);
        }

        if (array_key_exists('title', $diff)) {
            $this->title = self::requireString($diff, 'title');
        }
    }

    /**
     * @param array<string, mixed> $cache Row as the cache kept it
     */
    public function hydrateFromCache(array $cache): void
    {
        $this->jobsDone = (int)($cache['jobsDone'] ?? 0);
    }
}
