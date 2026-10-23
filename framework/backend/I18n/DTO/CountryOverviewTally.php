<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

/** Current installation country counts, classified by catalog membership. */
final readonly class CountryOverviewTally
{
    public const string ENABLED_COUNT = 'enabledCount';
    public const string TOTAL_COUNT = 'totalCount';
    public const string BUILT_IN_COUNT = 'builtInCount';
    public const string OWN_COUNT = 'ownCount';

    /**
     * @param int $enabledCount Switched-on country count
     * @param int $totalCount All installed countries
     * @param int $builtInCount Installed countries whose codes belong to the catalog
     * @param int $ownCount Installed countries outside the catalog
     */
    public function __construct(
        public int $enabledCount,
        public int $totalCount,
        public int $builtInCount,
        public int $ownCount,
    ) {
    }

    /**
     * @return array{enabledCount: int, totalCount: int, builtInCount: int, ownCount: int} Browser fields
     */
    public function toArray(): array
    {
        return [
            self::ENABLED_COUNT => $this->enabledCount,
            self::TOTAL_COUNT => $this->totalCount,
            self::BUILT_IN_COUNT => $this->builtInCount,
            self::OWN_COUNT => $this->ownCount,
        ];
    }
}
