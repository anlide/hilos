<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\ActingSession;
use Hilos\Auth\Library\Command\OAuthCommands;
use Hilos\Auth\Library\DTO\LinkOAuthAfterReauthActionDTO;
use Hilos\Auth\Library\DTO\OAuthCreateAccountActionDTO;
use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripEndedSignalData;
use Hilos\Auth\OAuth\OAuthAccountTokenSigner;
use Hilos\Auth\OAuth\OAuthLinkTokenSigner;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthService;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Database\Database;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Collection\Identities;
use Hilos\Hilos;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Users\DTO\UserIdentityProviderNameWriteSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/** Provider metadata is created with the identity and refreshed only by its person's owner. */
final class OAuthProviderNameIntegrationTest extends ProfileIntegrationTestCase
{
    private const string SECRET = 'oauth-provider-name-test-secret';
    private const string PROVIDER = 'oauth:github';
    private const string EMAIL = 'provider-person@example.test';
    private const string NAME = '李 | Łukasz 👩‍💻';

    /** @var class-string<Hilos> Facade restored after each case */
    private string $previousApp;

    /** Prepares the existing profile environment and one legal revision for first sign-in. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, OAuthProviderNameIntegrationHilos::class);
        $this->library->oauthService = $this->service();
    }

    /** Restores the project facade before releasing the profile environment. */
    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        parent::tearDown();
    }

    /** @return array<string, array{?string, ?string}> Incoming and persisted provider names */
    public static function names(): array
    {
        return ['trimmed' => ['  ' . self::NAME . "\t", self::NAME], 'withheld' => [null, null], 'blank' => [" \t\n", null]];
    }

    /**
     * @param ?string $reported Provider fact carried through the consent token
     * @param ?string $expected Name stored on the created identity
     */
    #[DataProvider('names')]
    public function testConsentCreatesIdentityWithNameInItsFirstAnnouncement(?string $reported, ?string $expected): void
    {
        $service = $this->service();
        $library = $this->getMockBuilder(AbstractUsersLibraryAgent::class)->onlyMethods(['oauthService', 'grantSession'])->getMock();
        $library->method('oauthService')->willReturn($service);
        $library->expects(self::once())->method('grantSession')->with(
            self::callback(static fn (ActingSession $acting): bool => $acting->sessionToken === self::ANONYMOUS_SESSION_TOKEN),
            self::greaterThan(self::ADMIN_USER_ID),
            'auth_registered',
            self::anything(),
        );
        $commands = new OAuthCommands($library);
        $commands->completeLogin($this->ready($reported, self::EMAIL));
        $ending = $this->ending();
        self::assertSame(OAuthResultSignalData::REASON_CONSENT_REQUIRED, $ending->reason);
        self::assertNotNull($ending->accountToken);
        $reaction = new OAuthProviderNameBirthReaction();
        SourceChangeBus::subscribe($reaction);

        self::assertNull($commands->createAccount(
            self::ANONYMOUS_ACCEPT_KEY,
            new OAuthCreateAccountActionDTO($ending->accountToken, ['terms' => 'one']),
        ));

        $row = $this->oauthRow();
        self::assertSame($expected, $row['provider_name']);
        self::assertSame('Project Account', Database::sql('SELECT `name` FROM `hilos_user` WHERE `id` = ?', [$row['user_id']])
            ->rows()[0]['name']);
        self::assertSame(self::PROVIDER, $row['provider']);
        self::assertSame(1, (int)$row['verified']);
        self::assertNull($row['secret']);
        self::assertCount(1, $reaction->changes);
        self::assertSame(TableMutationType::Create, $reaction->changes[0]->mutationType);
        self::assertArrayHasKey('providerName', $reaction->changes[0]->row);
        self::assertSame($expected, $reaction->changes[0]->row['providerName']);
        $created = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof DbSyncCreatedSignalData && $signal->data->collectionKey === HilosDbContext::identities
                && $signal->data->row['type'] === IdentityType::OAUTH) {
                $created[] = $signal->data;
            }
        }
        self::assertCount(1, $created);
        self::assertArrayHasKey('provider_name', $created[0]->row);
        self::assertSame($expected, $created[0]->row['provider_name']);
        $email = Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, self::EMAIL);
        self::assertNotNull($email);
        self::assertSame((int)$row['user_id'], $email->userId);
        self::assertNull($email->providerName);
        self::assertSame(
            $expected,
            Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':subject')?->providerName,
        );
    }

    /**
     * @param ?string $reported Provider fact carried through reauthentication
     * @param ?string $expected Persisted provider name
     */
    #[DataProvider('names')]
    public function testReauthLinkCarriesTheProviderNameAndReplayStillRefuses(?string $reported, ?string $expected): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $commands = new OAuthCommands($this->library);
        $commands->completeLogin($this->ready($reported, self::EMAIL));
        $ending = $this->ending();
        self::assertSame(OAuthResultSignalData::REASON_REAUTH_REQUIRED, $ending->reason);
        self::assertNotNull($ending->linkToken);
        $commands->linkAfterReauth(self::ACCEPT_KEY, new LinkOAuthAfterReauthActionDTO($ending->linkToken));
        self::assertSame($expected, $this->oauthRow()['provider_name']);
        self::assertSame(self::USER_ID, (int)$this->oauthRow()['user_id']);

        $this->expectException(ValidationException::class);
        $commands->linkAfterReauth(self::ACCEPT_KEY, new LinkOAuthAfterReauthActionDTO($ending->linkToken));
    }

    public function testReauthLinkStillRefusesAnotherEmailsOwner(): void
    {
        self::seedIdentity(self::OTHER_USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $token = $this->service()->issueLinkToken(self::PROVIDER, 'subject', self::EMAIL, self::NAME);
        try {
            new OAuthCommands($this->library)->linkAfterReauth(self::ACCEPT_KEY, new LinkOAuthAfterReauthActionDTO($token));
            self::fail('The signed name cannot authorize a foreign account link');
        } catch (ValidationException) {
            self::assertSame([], Database::sql("SELECT `id` FROM `hilos_identity` WHERE `type` = 'oauth'")->rows());
        }
    }

    public function testLegacyLinkTokenCreatesAnIdentityWithoutAProviderName(): void
    {
        self::seedIdentity(self::USER_ID, IdentityType::MAGIC_LINK, self::EMAIL);
        $payload = 'oauth-link|' . self::PROVIDER . '|subject|' . self::EMAIL . '|' . (time() + 600);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, self::SECRET, true)), '+/', '-_'), '=');
        new OAuthCommands($this->library)->linkAfterReauth(
            self::ACCEPT_KEY,
            new LinkOAuthAfterReauthActionDTO($encoded . '.' . $signature),
        );
        self::assertNull($this->oauthRow()['provider_name']);
    }

    public function testLegacyAccountTokenCreatesAnIdentityWithoutCopyingItsProjectName(): void
    {
        $payload = json_encode([
            'tag' => 'oauth-account', 'provider' => self::PROVIDER, 'subject' => 'subject', 'email' => null,
            'name' => 'Legacy Project Name', 'session' => ProtectedModeRuntime::hashSessionToken(self::ANONYMOUS_SESSION_TOKEN),
            'expires' => time() + 600,
        ], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, self::SECRET, true)), '+/', '-_'), '=');
        $library = $this->getMockBuilder(AbstractUsersLibraryAgent::class)->onlyMethods(['oauthService', 'grantSession'])->getMock();
        $library->method('oauthService')->willReturn($this->service());
        $library->expects(self::once())->method('grantSession');

        self::assertNull(new OAuthCommands($library)->createAccount(
            self::ANONYMOUS_ACCEPT_KEY,
            new OAuthCreateAccountActionDTO($encoded . '.' . $signature, ['terms' => 'one']),
        ));
        $row = $this->oauthRow();
        self::assertNull($row['provider_name']);
        self::assertSame('Legacy Project Name', Database::sql('SELECT `name` FROM `hilos_user` WHERE `id` = ?', [$row['user_id']])
            ->rows()[0]['name']);
    }

    /**
     * @param ?string $reported Name carried by the completed exchange
     * @param bool $queueFailure Whether metadata cannot leave the library
     */
    #[DataProvider('raceExchanges')]
    public function testDuplicateRaceRollsBackNewAccountAndGrantsTheWinner(?string $reported, bool $queueFailure): void
    {
        $realDb = Hilos::$db;
        $winner = $realDb->identities->createOauthIdentity(self::USER_ID, self::PROVIDER, 'subject');
        $identities = $this->getMockBuilder(Identities::class)->onlyMethods(['findByIdentity', 'createOauthIdentity'])->getMock();
        $identities->method('findByIdentity')->willReturnOnConsecutiveCalls(null, $winner);
        $identities->expects(self::once())->method('createOauthIdentity')->with(
            self::greaterThan(self::ADMIN_USER_ID), self::PROVIDER, 'subject', $reported,
        )->willThrowException(new DuplicateValueException('oauth account already linked'));
        $db = $this->getMockBuilder(HilosDbContext::class)->onlyMethods(['__get'])->getMock();
        $db->method('__get')->willReturnCallback(static fn (string $key): mixed => $key === 'identities' ? $identities : $realDb->$key);
        Hilos::$db = $db;
        $methods = ['oauthService', 'grantSession'];
        if ($queueFailure) {
            $methods[] = 'sendToAgent';
        }
        $library = $this->getMockBuilder(AbstractUsersLibraryAgent::class)->onlyMethods($methods)->getMock();
        $library->method('oauthService')->willReturn($this->service());
        $library->expects(self::once())->method('grantSession')->with(self::anything(), self::USER_ID);
        if ($queueFailure) {
            $library->expects(self::once())->method('sendToAgent')->with(
                HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE,
                self::isInstanceOf(UserIdentityProviderNameWriteSignalData::class),
            )->willThrowException(new RuntimeException('Metadata queue unavailable'));
        }
        $token = $this->service()->issueAccountToken(
            self::PROVIDER, 'subject', null, 'Project Account', self::ANONYMOUS_SESSION_TOKEN, $reported,
        );
        try {
            self::assertNull(new OAuthCommands($library)->createAccount(
                self::ANONYMOUS_ACCEPT_KEY,
                new OAuthCreateAccountActionDTO($token, ['terms' => 'one']),
            ));
        } finally {
            Hilos::$db = $realDb;
        }
        self::assertSame(3, (int)Database::sql('SELECT COUNT(*) AS `n` FROM `hilos_user`')->rows()[0]['n']);
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_legal_acceptance`')->rows());
        self::assertSame(self::USER_ID, (int)$this->oauthRow()['user_id']);
        $reports = $this->providerNameReports();
        self::assertCount(!$queueFailure && $reported !== null && trim($reported) !== '' ? 1 : 0, $reports);
        self::assertNull($this->oauthRow()['provider_name']);
        foreach ($reports as $report) {
            self::assertSame($winner->id, $report->data->data->identityId);
            $this->deliverToPerson($report);
            self::assertSame(self::NAME, $this->oauthRow()['provider_name']);
        }
    }

    /** @return array<string, array{?string, bool}> Duplicate-race provider facts and transport outcomes */
    public static function raceExchanges(): array
    {
        return [
            'name' => [self::NAME, false], 'withheld' => [null, false], 'blank' => [" \t\n", false],
            'queue failure' => [self::NAME, true],
        ];
    }

    /** @return array<string, array{bool}> Absent or inherited receipt */
    public static function receiptStates(): array
    {
        return ['autonomous' => [false], 'incoming' => [true]];
    }

    /** @return iterable<string, array{bool, ?string, bool}> Existing-identity entrances and metadata facts */
    public static function existingExchanges(): iterable
    {
        foreach (['login' => false, 'consent race' => true] as $flow => $consent) {
            yield "{$flow} changed" => [$consent, '  ' . self::NAME . "\t", false];
            yield "{$flow} repeated" => [$consent, 'Known name', false];
            yield "{$flow} withheld" => [$consent, null, false];
            yield "{$flow} blank" => [$consent, " \t\n", false];
            yield "{$flow} queue failure" => [$consent, self::NAME, true];
        }
    }

    /**
     * @param bool $consent Whether consent redemption finds an identity created by a racing exchange
     * @param ?string $reported Provider fact from the completed exchange
     * @param bool $queueFailure Whether reporting fails before metadata is queued
     */
    #[DataProvider('existingExchanges')]
    public function testExistingSignInReportsOnlyMetadataAndTheOwnerPublishesTheChange(
        bool $consent,
        ?string $reported,
        bool $queueFailure,
    ): void {
        $identity = Hilos::$db->identities->createOauthIdentity(self::USER_ID, self::PROVIDER, 'subject', 'Known name');
        $this->providerNameReports();
        $methods = ['oauthService', 'grantSession'];
        if ($queueFailure) {
            $methods[] = 'sendToAgent';
        }
        $library = $this->getMockBuilder(AbstractUsersLibraryAgent::class)->onlyMethods($methods)->getMock();
        $library->method('oauthService')->willReturn($this->service());
        $library->expects(self::once())->method('grantSession')->with(self::anything(), self::USER_ID);
        if ($queueFailure) {
            $library->expects(self::once())->method('sendToAgent')->with(
                HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE,
                self::isInstanceOf(UserIdentityProviderNameWriteSignalData::class),
            )->willThrowException(new RuntimeException('Metadata queue unavailable'));
        }
        ExecutionContext::run(new ExecutionFrame(agentId: $library->getId()), function () use ($library, $consent, $reported): void {
            $commands = new OAuthCommands($library);
            if ($consent) {
                $token = $this->service()->issueAccountToken(
                    self::PROVIDER, 'subject', null, 'Project Account', self::ANONYMOUS_SESSION_TOKEN, $reported,
                );
                self::assertNull($commands->createAccount(self::ANONYMOUS_ACCEPT_KEY, new OAuthCreateAccountActionDTO($token, [])));
            } else {
                $commands->completeLogin($this->ready($reported));
            }
        });
        self::assertSame('Known name', $this->oauthRow()['provider_name'], 'The library cannot edit existing identities');
        $reports = $this->providerNameReports();
        self::assertCount(!$queueFailure && $reported !== null && trim($reported) !== '' ? 1 : 0, $reports);
        foreach ($reports as $report) {
            self::assertSame([
                'userId' => self::USER_ID, 'identityId' => $identity->id, 'providerName' => $reported,
            ], $report->data->data->toArray());
            $this->deliverToPerson($report);
        }
        $changed = !$queueFailure && $reported !== null && trim($reported) === self::NAME;
        self::assertSame($changed ? self::NAME : 'Known name', $this->oauthRow()['provider_name']);
        $updates = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof DbSyncUpdatedSignalData && $frame->data->collectionKey === HilosDbContext::identities) {
                $updates[] = $frame->data;
            }
            self::assertFalse($frame->data instanceof AgentSignalData, 'Metadata never sends a reply');
        }
        self::assertCount($changed ? 1 : 0, $updates);
        if ($changed) {
            self::assertSame((string)$identity->id, $updates[0]->idString);
            self::assertSame(['provider_name' => self::NAME], $updates[0]->row);
        }
    }

    public function testADeletedIdentityReportDoesNotEditItsReplacement(): void
    {
        Hilos::$db->identities->createMagicLinkIdentity(self::USER_ID, self::EMAIL);
        $old = Hilos::$db->identities->createOauthIdentity(self::USER_ID, self::PROVIDER, 'subject', 'Old name');
        $capturedId = $old->id;
        $this->asPerson(self::USER_ID, static fn () => Hilos::$db->identities->deleteIdentity(self::USER_ID, $capturedId));
        $replacement = Hilos::$db->identities->createOauthIdentity(self::USER_ID, self::PROVIDER, 'subject', 'Replacement');
        self::assertNotSame($capturedId, $replacement->id);
        $this->library->sendToAgent(HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE,
            new UserIdentityProviderNameWriteSignalData(self::USER_ID, $capturedId, self::NAME));
        $this->deliverToPerson($this->providerNameReports()[0]);
        self::assertSame('Replacement', $this->oauthRow()['provider_name']);
        $this->library->sendToAgent(HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE,
            new UserIdentityProviderNameWriteSignalData(self::USER_ID, $replacement->id, self::NAME));
        $this->deliverToPerson($this->providerNameReports()[0]);
        self::assertSame(self::NAME, $this->oauthRow()['provider_name']);
    }

    public function testARefusedOwnerWriteKeepsTheSignInAndTheNextExchangeReportsAgain(): void
    {
        Hilos::$db->identities->createOauthIdentity(self::USER_ID, self::PROVIDER, 'subject', 'Known name');
        $library = $this->getMockBuilder(AbstractUsersLibraryAgent::class)->onlyMethods(['grantSession'])->getMock();
        $library->expects(self::exactly(2))->method('grantSession')->with(self::anything(), self::USER_ID);
        $commands = new OAuthCommands($library);
        $commands->completeLogin($this->ready(self::NAME));
        $reports = $this->providerNameReports();
        self::assertCount(1, $reports);
        $owner = $this->personAgent(self::USER_ID);
        TruthSourceRegistry::unregisterAgent($owner->getId());
        $this->deliverToPerson($reports[0]);
        self::assertSame('Known name', $this->oauthRow()['provider_name']);
        OwnershipDeclaration::claimAll($owner);
        $commands->completeLogin($this->ready(self::NAME));
        $reports = $this->providerNameReports();
        self::assertCount(1, $reports);
        $this->deliverToPerson($reports[0]);
        self::assertSame(self::NAME, $this->oauthRow()['provider_name']);
    }

    /** @param bool $incomingReceipt Whether the report is processed under an existing web receipt */
    #[DataProvider('receiptStates')]
    public function testOwnerKeepsAnIncomingReceiptOrOpensAnAgentReceipt(bool $incomingReceipt): void
    {
        Hilos::$db->identities->createOauthIdentity(self::USER_ID, self::PROVIDER, 'subject');
        $primary = Database::getConnectionConfig(0);
        Database::configure(1, $primary->host, $primary->user, $primary->password,
            ChangeLogDatabase::configuredName(), $primary->port, $primary->charset);
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $tables = ['hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
            'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table'];
        foreach ($tables as $table) {
            Database::sqlRun("DROP TABLE IF EXISTS {$journal}.`{$table}`");
        }
        Database::sqlRun(str_replace(JournalTriggerRenderer::DATABASE_TOKEN, $journal,
            file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_change_log.sql')));
        Schema::reset();
        Schema::initialize();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, OAuthProviderNameJournalHilos::class);
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        $reaction = new class implements SourceChangeSubscriberInterface {
            /** @var list<array<string, mixed>> Receipt attribution at the moment of the metadata write */
            public array $receipts = [];

            /**
             * @param SourceChange $change Local identity update
             * @param SourceChangeProvenance $provenance Source origin
             */
            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === HilosDbContext::identities && $change->mutationType === TableMutationType::Update) {
                    $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
                    $this->receipts[] = Database::sql("SELECT * FROM {$journal}.`hilos_change_log_receipt` WHERE `id` = ?",
                        [JournalReceiptScope::currentId()])->rows()[0];
                }
            }
        };
        SourceChangeBus::subscribe($reaction);
        $outerId = null;
        $library = $this->getMockBuilder(AbstractUsersLibraryAgent::class)->onlyMethods(['grantSession'])->getMock();
        $library->expects(self::once())->method('grantSession')->with(self::anything(), self::USER_ID);
        $signIn = fn () => new OAuthCommands($library)->completeLogin($this->ready(self::NAME));
        try {
            if ($incomingReceipt) {
                JournalReceiptScope::run(JournalReceiptData::web(self::USER_ID, null, null, 'oauth_login', 'users-library'),
                    function () use ($signIn, &$outerId): void {
                        $outerId = JournalReceiptScope::currentId();
                        $signIn();
                        self::assertSame($outerId, JournalReceiptScope::currentId());
                    });
            } else {
                $signIn();
            }
            $reports = $this->providerNameReports();
            self::assertCount(1, $reports);
            $report = $reports[0];
            self::assertSame($outerId, $report->data->receiptId);
            $wire = new SignalDTO($report->signalSource, $report->signalType, $report->signalName,
                AgentSignalData::fromArray($report->data->toArray()));
            if ($wire->data->receiptId === null) {
                $this->deliverToPerson($wire);
            } else {
                JournalReceiptScope::join($wire->data->receiptId, $this->personAgent(self::USER_ID)->getId(),
                    fn () => $this->deliverToPerson($wire));
            }
            self::assertNull(JournalReceiptScope::currentId());
            self::assertSame(self::NAME, $this->oauthRow()['provider_name']);
            self::assertCount(1, $reaction->receipts);
            $receipt = $reaction->receipts[0];
            self::assertSame($incomingReceipt ? 'web' : 'agent', $receipt['channel']);
            self::assertSame($incomingReceipt ? 'oauth_login' : HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE, $receipt['action']);
            self::assertSame($incomingReceipt ? null : $this->personAgent(self::USER_ID)->getId(), $receipt['source']);
            if ($incomingReceipt) {
                self::assertSame($outerId, (int)$receipt['id']);
            }
        } finally {
            foreach ($tables as $table) {
                Database::sqlRun("DROP TABLE IF EXISTS {$journal}.`{$table}`");
            }
        }
    }

    /**
     * @param ?string $reported Provider fact from the direct exchange
     * @param ?string $expected Persisted provider name
     */
    #[DataProvider('names')]
    public function testDirectProfileLinkStoresTheProviderNameWithOnlyCreateRights(?string $reported, ?string $expected): void
    {
        $agent = new class extends AbstractOAuthAgent {
            /** @return OAuthProviderRegistry No exchange needed to exercise its resolved completion */
            protected function buildProviderRegistry(): OAuthProviderRegistry
            {
                return new OAuthProviderRegistry([]);
            }
        };
        $agent->onStart();
        $id = $agent->getId();
        OwnershipDeclaration::claimDb($agent::class, $id);
        $op = OAuthPendingLogin::create(
            self::ACCEPT_KEY, self::SESSION_TOKEN, self::PROVIDER, 'code', 'trip', OAuthPendingLogin::MODE_LINK, self::USER_ID,
        );
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: $id), static function () use ($agent, $op, $reported): void {
                new ReflectionMethod(AbstractOAuthAgent::class, 'succeedOp')->invoke($agent, $op, new OAuthUserInfo('subject', null, $reported));
            });
            self::assertSame($expected, $this->oauthRow()['provider_name']);
            self::assertSame(OAuthResultSignalData::REASON_LINK_OK, $this->ending()->reason);
        } finally {
            $agent->onStop();
            TruthSourceRegistry::unregisterAgent($id);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($id));
        }
    }

    public function testThreeArgumentCreationRemainsValid(): void
    {
        Hilos::$db->identities->createOauthIdentity(self::USER_ID, self::PROVIDER, 'subject');
        self::assertNull($this->oauthRow()['provider_name']);
    }

    /**
     * @param ?string $name Provider name
     * @param ?string $email Optional colliding address
     * @return OAuthLoginReadySignalData Completed exchange, after the IPC roundtrip
     */
    private function ready(?string $name, ?string $email = null): OAuthLoginReadySignalData
    {
        return OAuthLoginReadySignalData::fromArray(new OAuthLoginReadySignalData(
            self::PROVIDER, 'subject', $email, 'Project Account', self::ANONYMOUS_ACCEPT_KEY, self::ANONYMOUS_SESSION_TOKEN, 'trip',
            providerName: $name,
        )->toArray());
    }

    /** @return list<SignalDTO> Provider metadata reports, with unrelated setup frames drained */
    private function providerNameReports(): array
    {
        $reports = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->signalName->getName() === HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE) {
                self::assertInstanceOf(AgentSignalData::class, $frame->data);
                self::assertInstanceOf(UserIdentityProviderNameWriteSignalData::class, $frame->data->data);
                $reports[] = $frame;
            }
        }

        return $reports;
    }

    /** @return OAuthTripEndedSignalData The sole trip ending on the queue */
    private function ending(): OAuthTripEndedSignalData
    {
        $endings = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof OAuthTripEndedSignalData) {
                self::assertSame(HilosSignalConstants::HILOS_OAUTH_TRIP_ENDED, $signal->signalName->getName());
                $endings[] = $signal->data->data;
            }
        }
        self::assertCount(1, $endings);

        return $endings[0];
    }

    /** @return array<string, mixed> Persisted OAuth row, independent of the ORM */
    private function oauthRow(): array
    {
        $rows = Database::sql('SELECT * FROM `hilos_identity` WHERE `type` = ? AND `identifier` = ?', [
            IdentityType::OAUTH, self::PROVIDER . ':subject',
        ])->rows();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** @return OAuthService Real signers without a provider network */
    private function service(): OAuthService
    {
        return new OAuthService(
            new OAuthProviderRegistry([]), new OAuthStateSigner(self::SECRET), 600,
            new OAuthLinkTokenSigner(self::SECRET), 600, new OAuthAccountTokenSigner(self::SECRET), 600,
        );
    }
}

/** Observes the creation fact before any later metadata edit could fill a missing name. */
final class OAuthProviderNameBirthReaction implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> OAuth identity announcements */
    public array $changes = [];

    /**
     * @param SourceChange $change Source fact
     * @param SourceChangeProvenance $provenance Local or replicated origin
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind === SourceChange::KIND_DB && $change->sourceKey === HilosDbContext::identities
            && ($change->row['type'] ?? null) === IdentityType::OAUTH) {
            $this->changes[] = $change;
        }
    }
}

/** One published revision permits the real consent and acceptance writes. */
final class OAuthProviderNameIntegrationCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Registration's current terms */
    public static function revisions(): array
    {
        return ['terms' => [
            new LegalRevision(LegalDocument::TERMS, 'one', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
        ]];
    }
}

/** Binds only the consent catalog for this fixture. */
abstract class OAuthProviderNameIntegrationHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = OAuthProviderNameIntegrationCatalog::class;
}

/** The same profile environment with the framework's receipt collections mounted. */
abstract class OAuthProviderNameJournalHilos extends OAuthProviderNameIntegrationHilos
{
    public const array FEATURES = [HilosFeature::CHANGE_LOG];
}
