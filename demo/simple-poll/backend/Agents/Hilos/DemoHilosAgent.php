<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Agents\Hilos;

use Demo\SimplePoll\Constants\PollSignalConstants;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;

/**
 * DemoHilosAgent - Concrete Hilos index agent for the simple-poll demo.
 *
 * Owns the framework Hilos admin pages (dashboard, settings, users). Registers
 * the standalone user-rename audit collection as a truth source so the users
 * page's rename action — handled in this agent — may append audit rows.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
    /**
     * Registers the rename-audit collection as this agent's truth source.
     */
    public function onStart(): void
    {
        parent::onStart();

        $this->registerDbTruthSource(PollDbContext::userRenames);
    }

    /**
     * Writes the admin flag of one user and tells that user's open browsers.
     *
     * The framework half of the grant ends at this seam: the command is validated and
     * answered there, and what a user row is lives here. Both steps belong to one
     * operation - a flag written without the second step reaches the browser only on the
     * next reload, and until then a fresh admin is shown no way in.
     *
     * Both are legal in this worker even though the users table belongs to the demo agent:
     * the collection is registered with a lazy key strategy, and the truth-source write
     * check only guards a collection loaded whole - which is why the Hilos users page
     * already renames from here.
     *
     * @param int $userId Target user id, already validated as positive
     * @param bool $admin New admin flag
     * @throws ItemNotFoundForUpdateException When no user carries that id
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws HilosException On database failure while writing the flag
     */
    protected function applyAdminGrant(int $userId, bool $admin): void
    {
        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }

        $user->actions->setAdmin($admin);

        $response = new HandshakeResponseSignalData(
            selfId: $userId,
            selfName: $user->name,
            selfAdmin: $admin,
        );
        foreach (Hilos::$rt->connections->forUser($userId) as $connection) {
            $this->sendToUser(PollSignalConstants::HANDSHAKE_RESPONSE, $connection->acceptKey, $response);
        }
    }
}
