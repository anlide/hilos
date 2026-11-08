<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

use Hilos\Core\Agent\AbstractAgent;

/**
 * One exact class declaration within its containing collection's transition state.
 */
final readonly class DbWriterClaim
{
    /**
     * @param class-string<AbstractAgent> $ownerClass Registered worker class
     * @param DbWriterClaimWidth $width Declared coverage
     * @param TruthSourceOperations $operations Exact allowed operations
     */
    public function __construct(
        public string $ownerClass,
        public DbWriterClaimWidth $width,
        public TruthSourceOperations $operations,
    ) {
    }

    /**
     * Reads expanded declarations, including inherited claims and BY_KIND, without constructing an agent.
     *
     * @param class-string<AbstractAgent> $ownerClass Worker class to normalize
     * @return array<string, list<self>> Collection key => claims in each declared width
     */
    public static function declarationsOf(string $ownerClass): array
    {
        $claims = [];
        foreach (DbWriterClaimWidth::cases() as $width) {
            $collections = match ($width) {
                DbWriterClaimWidth::Whole => OwnershipDeclaration::dbCollectionsOf($ownerClass),
                DbWriterClaimWidth::Rows => OwnershipDeclaration::dbRowCollectionsOf($ownerClass),
                DbWriterClaimWidth::Set => OwnershipDeclaration::dbSetCollectionsOf($ownerClass),
            };
            foreach ($collections as $collection => $operations) {
                $claims[$collection][] = new self($ownerClass, $width, $operations);
            }
        }

        return $claims;
    }
}
