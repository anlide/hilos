<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\TableProgressScope;

/**
 * TableProgressDTO - One progress bar of a table, as the table itself declares it.
 *
 * This is the body of a bar without an address: a table knows nothing of the page it is shown
 * on, and the page key and the table key are written by the fan-out that carries the bar to a
 * connection ({@see TableProgressSignalData}). The same body travels both roads a bar reaches a
 * tab by - the live frame and the snapshot in the page's own answer.
 *
 * The numbers are the server's and the client recomputes none of them: `current` and `total` are
 * done and to-do as the work itself counts them. An absent total says the work has no estimate,
 * which is a real state and not a missing field - a bar with no fraction to draw. `ended` says
 * the work stopped and the bar comes down; it does not say how it stopped, that being a fact
 * with its own road and its own reader.
 *
 * `detail` is the project's own payload for the content beside the bar - a title, a counter, a
 * link, a cancel button. The framework carries it and reads none of it.
 */
final class TableProgressDTO extends BaseDTO
{
    public const string scope = 'scope';
    public const string progressKey = 'progressKey';
    public const string rowKey = 'rowKey';
    public const string current = 'current';
    public const string total = 'total';
    public const string ended = 'ended';
    public const string detail = 'detail';

    /**
     * Creates one progress bar declaration.
     *
     * The row key is checked against the scope here and nowhere else on this side: a bar naming
     * a row while standing above the table, or a row bar naming no row at all, lies about what
     * the bar is tied to. The wire schema cannot catch it - the protocol schemas tolerate a key
     * they do not name, by design - so the strictness sits where the frame is built.
     *
     * @param TableProgressScope $scope Where the bar is drawn
     * @param string $progressKey Key of the work the bar is about; a different key replaces the bar
     * @param ?string $rowKey Row the bar is tied to, required for a row bar and refused for the other two
     * @param int $current Units of the work done so far
     * @param ?int $total Units the work adds up to, or null when it has no estimate
     * @param bool $ended Whether the work has stopped and the bar comes down
     * @param array<string, mixed> $detail Project's own payload for the content beside the bar
     * @throws InvalidArgumentException When a row bar carries no row key, or another scope carries one
     */
    public function __construct(
        public readonly TableProgressScope $scope,
        public readonly string $progressKey,
        public readonly ?string $rowKey,
        public readonly int $current,
        public readonly ?int $total,
        public readonly bool $ended = false,
        public readonly array $detail = [],
    ) {
        if ($scope === TableProgressScope::Row && $rowKey === null) {
            throw new InvalidArgumentException('A row progress bar names no row under key ' . self::rowKey);
        }
        if ($scope !== TableProgressScope::Row && $rowKey !== null) {
            throw new InvalidArgumentException(
                'A ' . $scope->value . ' progress bar names a row under key ' . self::rowKey,
            );
        }
    }

    /**
     * Converts the declaration to its wire array.
     *
     * The three keys that may be absent are absent rather than null: a missing total is what
     * says the work has no estimate, a lowered `ended` is what every bar that is still running
     * carries, and an empty detail map would reach the wire as a JSON array rather than an
     * object.
     *
     * @return array<string, mixed> Bar payload in the table-progress wire form
     */
    public function toArray(): array
    {
        $payload = [
            self::scope => $this->scope->value,
            self::progressKey => $this->progressKey,
            self::current => $this->current,
        ];
        if ($this->rowKey !== null) {
            $payload[self::rowKey] = $this->rowKey;
        }
        if ($this->total !== null) {
            $payload[self::total] = $this->total;
        }
        if ($this->ended) {
            $payload[self::ended] = true;
        }
        if ($this->detail !== []) {
            $payload[self::detail] = $this->detail;
        }

        return $payload;
    }

    /**
     * Restores the declaration from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-progress wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the work, its progress, or names a place no bar stands in
     * @throws InvalidArgumentException When the row key and the place the bar stands in disagree
     */
    public static function fromArray(array $data): static
    {
        $scope = TableProgressScope::tryFrom(self::requireString($data, self::scope));
        if ($scope === null) {
            throw new InvalidFormatException('Payload names no progress bar place under key ' . self::scope);
        }

        return new static(
            scope: $scope,
            progressKey: self::requireString($data, self::progressKey),
            rowKey: self::optionalString($data, self::rowKey),
            current: self::requireInt($data, self::current),
            total: self::optionalInt($data, self::total),
            ended: self::optionalBool($data, self::ended) ?? false,
            detail: self::optionalArray($data, self::detail) ?? [],
        );
    }
}
