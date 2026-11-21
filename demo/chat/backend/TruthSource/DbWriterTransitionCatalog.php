<?php

declare(strict_types=1);

namespace Demo\Chat\TruthSource;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Database\ChatDbContext;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\TruthSource\DbWriterClaimWidth;
use Hilos\Core\TruthSource\DbWriterTransitionCatalog as FrameworkDbWriterTransitionCatalog;
use Hilos\Core\TruthSource\DbWriterTransitionState;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Hilos;

/** Chat's four history collections and their terminal transitions. */
final class DbWriterTransitionCatalog extends FrameworkDbWriterTransitionCatalog
{
    /**
     * @param class-string<Hilos> $hilosClass Inspected chat topology
     * @return array<string, list<DbWriterTransitionState>> Framework transitions extended with chat history
     */
    protected function buildDefinitions(string $hilosClass): array
    {
        $definitions = parent::buildDefinitions($hilosClass);
        $chat = $this->role($hilosClass, ChatAgent::AGENT_TYPE);
        $users = $this->role($hilosClass, HilosAgentType::HILOS_USERS_LIBRARY);
        foreach ([ChatDbContext::events, ChatDbContext::eventUserRegistrations,
            ChatDbContext::eventMessages, ChatDbContext::eventAttachments] as $collection) {
            $base = $this->claim($chat, DbWriterClaimWidth::Whole, ...TruthSourceOperation::ALL);
            if (in_array($collection, [ChatDbContext::events, ChatDbContext::eventUserRegistrations], true)) {
                $base = [...$base, ...$this->claim($users, DbWriterClaimWidth::Whole, TruthSourceOperation::Add)];
            }
            $definitions[$collection] = $this->states($base, []);
        }

        return $definitions;
    }
}
