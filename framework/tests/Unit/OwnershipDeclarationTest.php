<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the combined claimAll() entrypoint.
 */
final class OwnershipDeclarationTest extends TestCase
{
    public function tearDown(): void
    {
        $agentId = OwnershipDeclarationTestAgent::AGENT_TYPE . ':7';
        RtTruthSourceRegistry::unregisterAgent($agentId);
        TruthSourceRegistry::unregisterAgent($agentId);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        SourceInterestRegistry::readsWhatItMounts();
        parent::tearDown();
    }

    public function testClaimAll(): void
    {
        $agent = new OwnershipDeclarationTestAgent('7');
        OwnershipDeclaration::claimAll($agent);

        $previous = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId($agent->getId());
        try {
            // Whole collection claims
            TruthSourceRegistry::checkCanWriteItem(
                OwnershipDeclarationTestAgent::DB_COLLECTION,
                'any',
                TruthSourceOperation::Update
            );
            RtTruthSourceRegistry::checkCanWriteState(
                OwnershipDeclarationTestAgent::RT_COLLECTION,
                'any',
                TruthSourceOperation::Update
            );

            // Row claims
            TruthSourceRegistry::checkCanWriteItem(
                OwnershipDeclarationTestAgent::DB_ROWS_COLLECTION,
                '7',
                TruthSourceOperation::Update
            );
            RtTruthSourceRegistry::checkCanWriteState(
                OwnershipDeclarationTestAgent::RT_ROWS_COLLECTION,
                '7',
                TruthSourceOperation::Update
            );

            $this->assertTrue(TruthSourceRegistry::isTruthSource(
                OwnershipDeclarationTestAgent::DB_ROWS_COLLECTION,
                ['7']
            ));
            $this->assertTrue(RtTruthSourceRegistry::isTruthSource(
                OwnershipDeclarationTestAgent::RT_ROWS_COLLECTION,
                ['7']
            ));
        } finally {
            ExecutionContext::setCurrentAgentId($previous);
        }
    }
}

/**
 * An agent holding collections across all four declaration halves.
 */
final class OwnershipDeclarationTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_ownership_declaration_test';
    public const string DB_COLLECTION = 'unit_ownership_declaration_test_db';
    public const string RT_COLLECTION = 'unit_ownership_declaration_test_rt';
    public const string DB_ROWS_COLLECTION = 'unit_ownership_declaration_test_db_rows';
    public const string RT_ROWS_COLLECTION = 'unit_ownership_declaration_test_rt_rows';

    public const array OWNS_DB = [self::DB_COLLECTION => [TruthSourceOperation::Update]];
    public const array OWNS_RT = [self::RT_COLLECTION => [TruthSourceOperation::Update]];
    public const array OWNS_DB_ROWS = [self::DB_ROWS_COLLECTION => [TruthSourceOperation::Update]];
    public const array OWNS_RT_ROWS = [self::RT_ROWS_COLLECTION => [TruthSourceOperation::Update]];

    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function ownedDbRowKeys(string $collection): array
    {
        return $collection === self::DB_ROWS_COLLECTION ? [(string)$this->agentIndex] : [];
    }

    public function ownedRtRowKeys(string $collection): array
    {
        return $collection === self::RT_ROWS_COLLECTION ? [(string)$this->agentIndex] : [];
    }

    public function onStart(): void
    {
    }

    public function onStop(): void
    {
    }
}
