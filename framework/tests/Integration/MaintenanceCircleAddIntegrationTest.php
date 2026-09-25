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
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleAddActionDTO;

/**
 * Integration coverage for naming a verifier from the maintenance section (HIL-1120).
 *
 * Every answer of the action runs through the identity table and the circle: whether the address
 * is proven, by whom, and whether that person already stands in the circle under this address or
 * another. So the page is asked with a real database, and each case asserts the rows the circle
 * holds afterwards and the sentence the screen is handed - the success ack or the refusal.
 */
final class MaintenanceCircleAddIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string WRITER_ID = 'maintenance-circle-add-test';

    private const string ACCEPT_KEY = 'ak-maintenance-circle-add';

    private const string REQUEST_ID = 'request-1';

    private const int ANN_USER_ID = 41;

    private const int BOB_USER_ID = 42;

    private const string EMAIL_TYPE = 'password';

    private const string SMS_TYPE = 'sms';

    private const string ANN_EMAIL = 'ann@example.test';

    private const string ANN_PHONE = '+79000000000';

    private const string BOB_EMAIL = 'bob@example.test';

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
    public function testAProvenAddressIsNamedInTheFormItIsStoredIn(): void
    {
        self::seedIdentity(self::ANN_USER_ID, self::EMAIL_TYPE, self::ANN_EMAIL);

        $message = $this->add('  Ann@Example.test ');

        $this->assertSame([[self::EMAIL_TYPE, self::ANN_EMAIL]], self::circle());
        $this->assertSame('ann@example.test added to the circle.', $message, 'The screen is told the address as stored');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testANumberTypedWithSeparatorsFindsTheIdentityItBelongsTo(): void
    {
        self::seedIdentity(self::ANN_USER_ID, self::SMS_TYPE, self::ANN_PHONE);

        $message = $this->add('+7 900 000-00-00');

        $this->assertSame(
            [[self::SMS_TYPE, self::ANN_PHONE]],
            self::circle(),
            'A number is brought to E.164 by the rule the sign-in uses, so the way it is written does not decide who it names',
        );
        $this->assertSame('+79000000000 added to the circle.', $message);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAnEmptyAddressIsRefused(): void
    {
        $this->assertRefused('   ', 'An address is required');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testSomethingThatIsNeitherAnAddressNorANumberIsRefused(): void
    {
        $this->assertRefused('not an address', 'Enter an email address or a phone number');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAnAddressNobodyHasIsRefused(): void
    {
        $this->assertRefused(self::ANN_EMAIL, 'Nobody has proven this address');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAnAddressItsOwnerHasNotProvenIsRefused(): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `verified`) VALUES (?, ?, ?, 0)',
            [self::ANN_USER_ID, self::EMAIL_TYPE, self::ANN_EMAIL],
        );

        $this->assertRefused(self::ANN_EMAIL, 'Nobody has proven this address');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testTheSameAddressTwiceIsRefused(): void
    {
        self::seedIdentity(self::ANN_USER_ID, self::EMAIL_TYPE, self::ANN_EMAIL);
        $this->add(self::ANN_EMAIL);

        $this->assertRefused(self::ANN_EMAIL, 'This address is already in the circle');
        $this->assertSame([[self::EMAIL_TYPE, self::ANN_EMAIL]], self::circle());
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testASecondAddressOfAPersonAlreadyNamedIsRefusedWithTheFirst(): void
    {
        self::seedIdentity(self::ANN_USER_ID, self::EMAIL_TYPE, self::ANN_EMAIL);
        self::seedIdentity(self::ANN_USER_ID, self::SMS_TYPE, self::ANN_PHONE);
        $this->add(self::ANN_EMAIL);

        $this->assertRefused(self::ANN_PHONE, 'This person is already in the circle as ann@example.test');
        $this->assertSame([[self::EMAIL_TYPE, self::ANN_EMAIL]], self::circle(), 'One person stands in the circle by one row');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAnotherPersonIsNamedBesideTheOnesAlreadyThere(): void
    {
        self::seedIdentity(self::ANN_USER_ID, self::EMAIL_TYPE, self::ANN_EMAIL);
        self::seedIdentity(self::BOB_USER_ID, self::EMAIL_TYPE, self::BOB_EMAIL);
        $this->add(self::ANN_EMAIL);

        $this->add(self::BOB_EMAIL);

        $this->assertSame([[self::EMAIL_TYPE, self::ANN_EMAIL], [self::EMAIL_TYPE, self::BOB_EMAIL]], self::circle());
    }

    /**
     * Asserts that naming an address is refused with a phrase and leaves the circle as it was.
     *
     * @param string $typed Address as the operator typed it
     * @param string $reason Refusal the screen is expected to show
     * @throws HilosException When a step against the database fails
     */
    private function assertRefused(string $typed, string $reason): void
    {
        $before = self::circle();

        try {
            $this->add($typed);
            $this->fail("Expected the refusal: {$reason}");
        } catch (TableActionException $e) {
            $this->assertSame($reason, $e->getMessage());
        }

        $this->assertSame($before, self::circle(), 'A refused address leaves the circle untouched');
    }

    /**
     * Sends one tracked add action to the page, the way the router dispatches it.
     *
     * @param string $typed Address as the operator typed it
     * @return ?string Success sentence the ack carries to the screen
     * @throws HilosException When the page refuses the address or a lookup fails
     */
    private function add(string $typed): ?string
    {
        $page = new MaintenanceCircleAddTestPage(new MaintenanceCircleAddTestAgent());
        $dto = MaintenanceCircleAddActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [MaintenanceCircleAddActionDTO::identifier => $typed],
        ]);

        $page->beginActionDispatch(self::REQUEST_ID);
        try {
            $page->onAction(self::ACCEPT_KEY, HilosSignalConstants::MAINTENANCE_CIRCLE_ADD, $dto);
            $page->sendActionSuccess(self::ACCEPT_KEY, HilosSignalConstants::MAINTENANCE_CIRCLE_ADD, self::REQUEST_ID);
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
final class MaintenanceCircleAddTestPage extends AbstractHilosMaintenancePage
{
}

/**
 * Page agent carrying only what a page may reach for: its id and its signal source.
 */
final class MaintenanceCircleAddTestAgent implements PageAgentInterface
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
