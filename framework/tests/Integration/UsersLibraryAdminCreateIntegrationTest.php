<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Users\DTO\UserAdminCreateSignalData;
use Hilos\Users\DTO\UserAdminCreateDoneSignalData;

/** Real administrator creation runs under the users library's declared Add right. */
final class UsersLibraryAdminCreateIntegrationTest extends HilosSessionIntegrationTestCase
{
    public function testCreatesExactlyOneAdministratorAndAnswersWithItsId(): void
    {
        $previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $library = new AdminCreateIntegrationUsersLibrary();
        OwnershipDeclaration::claimDb($library::class, $library->getId());
        $token = ExecutionContext::push(new ExecutionFrame(agentId: $library->getId()));
        try {
            $ask = new UserAdminCreateSignalData('corr-1', 'token-1', false);
            $library->onSignalAgent(new AgentSignalData($ask), '', HilosSignalConstants::HILOS_USER_ADMIN_CREATE);

            $answers = [];
            while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($signal->signalName->getName() === HilosSignalConstants::HILOS_USER_ADMIN_CREATE_DONE) {
                    self::assertInstanceOf(AgentSignalData::class, $signal->data);
                    self::assertInstanceOf(UserAdminCreateDoneSignalData::class, $signal->data->data);
                    $answers[] = $signal->data->data;
                }
            }
            self::assertCount(1, $answers);
            self::assertNull($answers[0]->error);
            self::assertSame($ask, $answers[0]->request);
            $rows = Database::sql('SELECT `id`, `name`, `admin` FROM `hilos_user`')->rows();
            self::assertCount(1, $rows);
            self::assertSame($answers[0]->userId, (int)$rows[0]['id']);
            self::assertSame(1, (int)$rows[0]['admin']);
            self::assertMatchesRegularExpression('/^Admin\d{4}$/', $rows[0]['name']);
        } finally {
            ExecutionContext::pop($token);
            TruthSourceRegistry::unregisterAgent($library->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($library->getId()));
            Hilos::$sr = $previousRouter;
        }
    }
}

final class AdminCreateIntegrationUsersLibrary extends AbstractUsersLibraryAgent
{
}
