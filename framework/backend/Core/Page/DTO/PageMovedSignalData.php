<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\Exception\PageMovedException;
use Hilos\Core\Router\SignalDataInterface;
use stdClass;

/**
 * PageMovedSignalData - The page_moved answer to a page subscription.
 *
 * Sent instead of page_response when the page judged the address it was opened at and found
 * that it lives at another one ({@see PageMovedException}), or when the row an open page is
 * about changed the column its address is read from. Names the page and params that were
 * judged, and the page and EVERY param of the address the subscription has to move to.
 */
final class PageMovedSignalData extends BaseDTO implements SignalDataInterface
{
    /** Wire key for the page that was judged. */
    public const string page = 'page';

    /** Wire key for the params that were judged, as the subscription held them. */
    public const string params = 'params';

    /** Wire key for the page the address moved to. */
    public const string targetPage = 'targetPage';

    /** Wire key for every route param of the new address. */
    public const string targetParams = 'targetParams';

    /**
     * Creates page moved signal data.
     *
     * @param string $page Page that answered the subscription
     * @param array<string, mixed> $params Params that were judged, as the subscription holds them
     * @param string $targetPage Page the address moved to
     * @param array<string, string> $targetParams Every route param of the new address
     */
    public function __construct(
        public readonly string $page,
        public readonly array $params,
        public readonly string $targetPage,
        public readonly array $targetParams,
    ) {
    }

    /**
     * Converts the answer to array for transport.
     *
     * Both maps travel as objects, so an empty one stays a map through JSON.
     *
     * @return array<string, mixed> Moved answer as array
     */
    public function toArray(): array
    {
        return [
            self::page => $this->page,
            self::params => (object) $this->params,
            self::targetPage => $this->targetPage,
            self::targetParams => (object) $this->targetParams,
        ];
    }

    /**
     * Creates the answer from array.
     *
     * All four keys are required. Both pages must be non-empty text, the judged params a map,
     * and the target params a map of non-empty strings.
     *
     * @param array<string, mixed> $data Source data
     * @return static Moved answer
     * @throws InvalidFormatException When a field is missing, mistyped or empty, or a map is not a map
     */
    public static function fromArray(array $data): static
    {
        // toArray() keeps an empty map as {} through JSON, and the typed IPC envelope also
        // roundtrips the array directly, before JSON has turned the object into an array.
        foreach ([self::params, self::targetParams] as $mapKey) {
            if (($data[$mapKey] ?? null) instanceof stdClass) {
                $data[$mapKey] = (array) $data[$mapKey];
            }
        }

        $page = self::requireString($data, self::page);
        $targetPage = self::requireString($data, self::targetPage);
        if ($page === '' || $targetPage === '') {
            throw new InvalidFormatException('Page moved answer must name a non-empty page and target page');
        }

        $params = self::requireArray($data, self::params);
        if ($params !== [] && array_is_list($params)) {
            throw new InvalidFormatException('Page moved answer must carry its judged params as a map');
        }

        $targetParams = self::requireStringMap($data, self::targetParams);
        if ($targetParams !== [] && array_is_list($targetParams)) {
            throw new InvalidFormatException('Page moved answer must carry its target params as a map');
        }
        if (in_array('', $targetParams, true)) {
            throw new InvalidFormatException('Page moved answer target params must be non-empty strings');
        }

        return new static(
            page: $page,
            params: $params,
            targetPage: $targetPage,
            targetParams: $targetParams,
        );
    }
}
