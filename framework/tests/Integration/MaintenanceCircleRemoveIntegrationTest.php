<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleRemoveActionDTO;

/**
 * Integration coverage for taking a verifier out from the maintenance section (HIL-1121).
 *
 * The action goes by the membership key the table handed out, and a key that names no row any
 * more is refused rather than answered with a silent success - the one place the section parts
 * with the backup page's remove. So the page is asked with a real database, and each case asserts
 * the rows the circle holds afterwards and the sentence the screen is handed - the success ack or
 * the refusal.
 */
final class MaintenanceCircleRemoveIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string WRITER_ID = 'maintenance-circle-remove-test';

    private const string ACCEPT_KEY = 'ak-maintenance-circle-remove';

    private const string REQUEST_ID = 'request-1';

    private const string EMAIL_TYPE = 'password';

    private const string ANN_EMAIL = 'ann@example.test';

    private const string BOB_EMAIL = 'bob@example.test';

    private const int MISSING_MEMBER_ID = 9999;

    /** @var ?SignalRouter Signal router to restore after the test */
    private ?SignalRouter $previousSignalRouter = null;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runCircleStub(down: true);
        self::runCircleStub(down: false);

        TruthSourceRegistry::register(HilosDbContext::verifierCircle, TruthSourceKeys::all(), self::WRITER_ID);
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
    }

    /**
     * @throws HilosException When dropping the stub table fails
     */
    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        Hilos::$sr = $this->previousSignalRouter;
        TruthSourceRegistry::unregister(HilosDbContext::verifierCircle, self::WRITER_ID);
        self::runCircleStub(down: true);

        parent::tearDown();
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testANamedVerifierIsTakenOut(): void
    {
        $annId = $this->nameToCircle(self::ANN_EMAIL);

        $message = $this->remove($annId);

        $this->assertSame([], self::circle());
        $this->assertSame('ann@example.test removed from the circle.', $message, 'The screen is told the address as stored');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testTakingOneOutLeavesTheOthersInPlace(): void
    {
        $annId = $this->nameToCircle(self::ANN_EMAIL);
        $this->nameToCircle(self::BOB_EMAIL);

        $this->remove($annId);

        $this->assertSame([[self::EMAIL_TYPE, self::BOB_EMAIL]], self::circle());
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAKeyThatNamesNoMembershipIsRefused(): void
    {
        $this->nameToCircle(self::ANN_EMAIL);

        $this->assertRefused(self::MISSING_MEMBER_ID);
        $this->assertSame([[self::EMAIL_TYPE, self::ANN_EMAIL]], self::circle());
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testTakingTheSameMembershipOutTwiceIsRefusedTheSecondTime(): void
    {
        $annId = $this->nameToCircle(self::ANN_EMAIL);
        $this->remove($annId);

        $this->assertRefused($annId);
        $this->assertSame([], self::circle());
    }

    /**
     * Names an address to the circle straight through the collection, past the page.
     *
     * @param string $identifier Address to name, in the form it is stored in
     * @return int Row key of the new membership
     * @throws HilosException When the circle write fails
     */
    private function nameToCircle(string $identifier): int
    {
        $memberId = Hilos::$db->verifierCircle->actions->add(self::EMAIL_TYPE, $identifier)->id;
        $this->assertNotNull($memberId, 'A stored membership has a key');

        return $memberId;
    }

    /**
     * Asserts that taking a membership out is refused as no longer in the circle, and leaves the
     * circle as it was.
     *
     * @param int $memberId Row key the table handed out
     * @throws HilosException When a step against the database fails
     */
    private function assertRefused(int $memberId): void
    {
        $before = self::circle();

        try {
            $this->remove($memberId);
            $this->fail('Expected the refusal: This verifier is no longer in the circle');
        } catch (TableActionException $e) {
            $this->assertSame('This verifier is no longer in the circle', $e->getMessage());
        }

        $this->assertSame($before, self::circle(), 'A refused key leaves the circle untouched');
    }

    /**
     * Sends one tracked remove action to the page, the way the router dispatches it.
     *
     * @param int $memberId Row key the table handed out
     * @return ?string Success sentence the ack carries to the screen
     * @throws HilosException When the page refuses the key or a lookup fails
     */
    private function remove(int $memberId): ?string
    {
        $page = new MaintenanceCircleRemoveTestPage(new MaintenanceCircleRemoveTestAgent());
        $dto = MaintenanceCircleRemoveActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [MaintenanceCircleRemoveActionDTO::memberId => $memberId],
        ]);

        $page->beginActionDispatch(self::REQUEST_ID);
        try {
            $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE, $dto);
            $page->sendActionSuccess(self::ACCEPT_KEY, HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE, self::REQUEST_ID);
        } finally {
            $page->endActionDispatch();
        }

        return self::nextSuccessMessage();
    }

    /**
     * @return ?string Success sentence from the next tracked action acknowledgement
     */
    private static function nextSuccessMessage(): ?string
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalConstants::ACTION_SUCCESS) {
                continue;
            }
            if (
                $signal->data instanceof WebSocketSignalData
                && $signal->data->data instanceof PageActionSuccessSignalData
            ) {
                return $signal->data->data->message;
            }
        }

        return null;
    }

    /**
     * Reads the circle straight from the database, past every in-memory collection.
     *
     * @return list<array{0: string, 1: string}> Identity pairs of the named, oldest membership first
     * @throws HilosException When the query fails
     */
    private static function circle(): array
    {
        Database::sql('SELECT `identity_type`, `identifier` FROM `hilos_verifier_circle` ORDER BY `id`');

        return array_map(
            static fn (array $row): array => [(string)$row['identity_type'], (string)$row['identifier']],
            Database::rows(),
        );
    }

    /**
     * Runs one direction of the circle table's stub file.
     *
     * @param bool $down Run the down (drop) stub when true, the create stub when false
     * @throws HilosException When the stub statement fails
     */
    private static function runCircleStub(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_hilos_verifier_circle{$suffix}.sql";
        Database::sqlRun((string)file_get_contents($stub));
    }
}

/**
 * Concrete maintenance page: the abstract one carries the whole behavior.
 */
final class MaintenanceCircleRemoveTestPage extends AbstractHilosMaintenancePage
{
}

/**
 * Page agent carrying only what a page may reach for: its id and its signal source.
 */
final class MaintenanceCircleRemoveTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_index';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}
