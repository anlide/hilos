<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Database\View\Item\AccountDeletion;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Hilos;
use Hilos\Pages\Users\AccountStandingAudience;
use Hilos\Users\AccountMergeProgressChangeSubscriber;
use Hilos\Users\AccountMergeProgressResolver;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;

require_once __DIR__ . '/AccountMergeCoordinatorIntegrationTest.php';

/** The existing real coordinator exchange, scoped to a table case and restored before its ordinary cleanup. */
trait MergeTableOwnerFrames
{
    use PersonAgentFrames;

    private MergeCoordinatorHolder $mergeHolder;

    /**
     * @template T
     * @param callable(): T $work Table scenario over real personal and holder commits
     * @return T Scenario result
     */
    private function withMergeOwners(callable $work): mixed
    {
        $previousApp = Hilos::appClass();
        $previousRouter = Hilos::$sr;
        $previousDb = Hilos::$db;
        $previousAgent = ExecutionContext::currentAgentId();
        $this->mergeTableStubs(true);
        $this->mergeTableStubs(false);
        Database::sqlRun('CREATE TABLE `' . AccountMergeProjectNote::_table . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `user_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`id`))');
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new MergeCoordinatorContext();
        Hilos::$db->configure();
        MergeCoordinatorHilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new AccountMergeProgressChangeSubscriber());
        AccountMergeProgressResolver::forgetAll();
        AccountStandingAudience::reset();
        $this->mergeHolder = new MergeCoordinatorHolder();
        OwnershipDeclaration::claimAll($this->mergeHolder);
        try {
            return $work();
        } finally {
            AccountStandingAudience::reset();
            AccountMergeProgressResolver::forgetAll();
            Database::handlerEnd();
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent($this->mergeHolder->getId());
            RtTruthSourceRegistry::unregisterAgent($this->mergeHolder->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->mergeHolder->getId()));
            Hilos::$sr = $previousRouter;
            $previousApp::initBrowser();
            Hilos::resetBrowser();
            SourceChangeBus::reset();
            Hilos::$db = $previousDb;
            ExecutionContext::setCurrentAgentId($previousAgent);
            Database::sqlRun('DROP TABLE IF EXISTS `' . AccountMergeProjectNote::_table . '`');
            $this->mergeTableStubs(true);
            Schema::reset();
        }
    }

    /**
     * @param int $survivor Survivor account
     * @param int $loser Loser account
     * @param ?AccountMergeChoice $choice Saved choices
     * @return AccountOperation Real opening with its queued preparations
     */
    private function openTableMerge(int $survivor, int $loser, ?AccountMergeChoice $choice = null): AccountOperation
    {
        return $this->asMergeHolder(fn (): AccountOperation => $this->mergeHolder->openMerge(
            $survivor, $loser, $choice ?? new AccountMergeChoice(null, null, null, null),
        ));
    }

    /**
     * @param int $userId Account whose request the canonical creator records
     * @return AccountDeletion Scheduled request before its personal owner begins erasure
     */
    private function requestTableDeletion(int $userId): AccountDeletion
    {
        $creator = 'table-users-library';
        OwnershipDeclaration::claimDb(AbstractUsersLibraryAgent::class, $creator);
        try {
            return ExecutionContext::run(new ExecutionFrame(agentId: $creator),
                static fn () => Hilos::$db->accountDeletions->actions->request($userId, 1893456000000));
        } finally {
            TruthSourceRegistry::unregisterAgent($creator);
        }
    }

    /** @return list<SignalDTO> Queued owner asks, answers and handovers */
    private function mergeFrames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData
                && ($frame->data->data instanceof AccountOperationAskSignalData
                    || $frame->data->data instanceof AccountOperationAnswerSignalData
                    || $frame->data->data instanceof AccountMergeHandoverSignalData)) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /** @param SignalDTO $queued Real envelope, serialized before its receiving production handler runs */
    private function deliverMergeFrame(SignalDTO $queued): void
    {
        $frame = SignalDTO::fromArray(json_decode(json_encode($queued->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $data = Hilos::$sr->createAgentSignalPayloadDTO($frame->signalName->getName(), $frame->data);
        $sender = SignalSource::describe($frame->signalSource);
        if ($data->data instanceof AccountOperationAnswerSignalData) {
            $this->asMergeHolder(fn () => $this->mergeHolder->onSignalAgent($data, $sender, $frame->signalName->getName()));
        } else {
            $this->asPerson((int) $data->data->ownerIndex, static fn (AbstractUserAgent $agent) =>
                $agent->onSignalAgent($data, $sender, $frame->signalName->getName()));
        }
    }

    /**
     * @template T
     * @param callable(): T $work Existing holder mutation
     * @return T Handler result
     */
    private function asMergeHolder(callable $work): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->mergeHolder->getId()), $work);
    }

    /**
     * @param string $userId Personal owner address
     * @return AbstractUserAgent Registered owner with the production handlers
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        return new MergeCoordinatorUser($userId);
    }

    /** @param bool $down Whether to drop supporting schema before the parent fixture */
    private function mergeTableStubs(bool $down): void
    {
        $tables = ['hilos_passkey_credential', 'hilos_account_merge_copy'];
        foreach ($down ? array_reverse($tables) : $tables as $table) {
            // external-boundary: migration stub filenames carry the direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string) file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_'
                . $table . $suffix . '.sql'));
        }
    }
}
