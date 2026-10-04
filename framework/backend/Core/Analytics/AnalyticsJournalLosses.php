<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Core\Exception\InvalidFormatException;

/** Open loss episodes of one node, grouped by reason and saved beside its journal. */
final class AnalyticsJournalLosses
{
    public const int QUIET_MS = 60000;

    private const int STATE_VERSION = 1;
    private const string KEY_VERSION = 'v';
    private const string KEY_EPISODES = 'episodes';

    /** @var array<string, AnalyticsLossCount> Open episodes by reason */
    private array $episodes = [];

    /** @var array<string, int> Last addition moment for each open episode */
    private array $lastAtMs = [];

    private bool $changed = false;

    /**
     * Adds a count to its episode.
     *
     * @param AnalyticsLossCount $count Count from a source or this node
     * @param int $nowMs Moment this node learned of the loss
     * @return bool Whether the episode just opened
     */
    public function add(AnalyticsLossCount $count, int $nowMs): bool
    {
        if ($count->events <= 0) {
            return false;
        }

        $key = $count->reason->value;
        $previous = $this->episodes[$key] ?? null;
        $this->episodes[$key] = new AnalyticsLossCount(
            $count->reason,
            ($previous?->events ?? 0) + $count->events,
            $previous === null ? $count->fromTs : min($previous->fromTs, $count->fromTs),
            $previous === null ? $count->toTs : max($previous->toTs, $count->toTs),
        );
        $this->lastAtMs[$key] = $nowMs;
        $this->changed = true;

        return $previous === null;
    }

    /**
     * @param int $nowMs Current moment
     * @return list<AnalyticsLossCount> Episodes quiet for at least a minute
     */
    public function closeQuiet(int $nowMs): array
    {
        $closed = [];
        foreach ($this->lastAtMs as $key => $lastAtMs) {
            if ($nowMs - $lastAtMs < self::QUIET_MS) {
                continue;
            }

            $closed[] = $this->episodes[$key];
            unset($this->episodes[$key], $this->lastAtMs[$key]);
            $this->changed = true;
        }

        return $closed;
    }

    /**
     * @return list<AnalyticsLossCount> All still-open episodes
     */
    public function closeAll(): array
    {
        $closed = array_values($this->episodes);
        $this->episodes = [];
        $this->lastAtMs = [];
        if ($closed !== []) {
            $this->changed = true;
        }

        return $closed;
    }

    /**
     * @return bool Whether there are no open episodes
     */
    public function isEmpty(): bool
    {
        return $this->episodes === [];
    }

    /**
     * @return bool Whether open episodes changed since the last saved state
     */
    public function hasChanges(): bool
    {
        return $this->changed;
    }

    /** Marks the current open episodes as saved. */
    public function markSaved(): void
    {
        $this->changed = false;
    }

    /**
     * @return string Versioned JSON state for the journal directory
     */
    public function toJson(): string
    {
        return (string)json_encode([
            self::KEY_VERSION => self::STATE_VERSION,
            self::KEY_EPISODES => array_map(
                static fn(AnalyticsLossCount $count): array => $count->toArray(),
                array_values($this->episodes),
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param string $json Saved state
     * @return self Restored episodes, or empty state when the file is malformed
     */
    public static function fromJson(string $json): self
    {
        $state = json_decode($json, true);
        $losses = new self();
        if (!is_array($state) || ($state[self::KEY_VERSION] ?? null) !== self::STATE_VERSION
            || !is_array($state[self::KEY_EPISODES] ?? null)
            || !array_is_list($state[self::KEY_EPISODES])) {
            return $losses;
        }

        try {
            foreach ($state[self::KEY_EPISODES] as $episode) {
                if (!is_array($episode)) {
                    return new self();
                }

                $count = AnalyticsLossCount::fromArray($episode);
                $losses->add($count, $count->toTs);
            }
        } catch (InvalidFormatException) {
            return new self();
        }

        $losses->markSaved();

        return $losses;
    }
}
