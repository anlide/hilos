<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use ArrayIterator;
use Closure;
use DateTimeImmutable;
use Hilos\Auth\IdentifierMask;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\DTO\ThrottleCheckSignalData;
use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Auth\Throttle\ThrottleIdentity;
use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\AuthThrottleFeature;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\StatusSubscribersActions;
use Hilos\Database\Actions\Item\StatusSubscriberActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\StatusSubscribers as ObjectStatusSubscribers;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Collection\StatusSubscribers;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\Template\MailTemplateCatalogConstants;
use Hilos\Mail\Template\StatusSubscriptionConfirmMailTemplate;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Status\DTO\StatusConfirmSubscriptionActionDTO;
use Hilos\Status\DTO\StatusSubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribePreviewActionDTO;
use Hilos\Status\StatusAgent;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/** Uses real commits: an always-rollback root cannot prove the confirmation mail handoff. */
final class StatusSubscriptionIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = ['hilos_user', 'hilos_session', 'hilos_setting', 'hilos_auth_block', 'hilos_status_subscriber'];
    private const string ACCEPT_KEY = 'status-guest';
    private const string SESSION_TOKEN = '0123456789abcdef0123456789abcdef';
    private const string EMAIL = 'reader@example.test';

    private StatusAgent $owner;
    private StatusGuestRuntime $runtime;
    private ?int $ownerFrame = null;
    private string $previousApp;
    /** @var array Facades restored after each real-commit case */
    private array $previousGlobals;
    /** @var array Source bus registrations restored after failure probes */
    private array $previousBus;
    /** @var array<string, string|false> Environment values restored after each case */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousGlobals = [Hilos::$db, Hilos::$rt, Hilos::$sr, Hilos::$mail, Hilos::$env, Hilos::$setting, Hilos::$browser];
        $this->previousBus = [];
        foreach (['mirrors', 'reactions', 'provenance'] as $property) {
            $this->previousBus[$property] = new ReflectionProperty(SourceChangeBus::class, $property)->getValue();
        }
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusGuestHilos::class);
        foreach ([
            EnvConstants::HILOS_STATUS_URL->name => 'https://service.example.test/status',
            EnvConstants::MAIL_FROM_NAME->name => 'Example service',
            EnvConstants::MAIL_WORKER_COUNT->name => '4',
            EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE->name => 'recipient',
            EnvConstants::HILOS_PROJECT_TIME_ZONE->name => 'UTC',
            EnvConstants::HILOS_AUTH_THROTTLE_ENABLED->name => 'true',
            EnvConstants::HILOS_AUTH_THROTTLE_MAX_SESSION->name => '20',
            EnvConstants::HILOS_AUTH_THROTTLE_MAX_IP->name => '40',
        ] as $key => $value) {
            $this->previousEnvironment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        Hilos::$setting = null;
        Hilos::$browser = null;
        foreach (array_reverse(self::TABLES) as $table) {
            $this->stub($table, true);
        }
        foreach (self::TABLES as $table) {
            $this->stub($table, false);
        }
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new StatusGuestDbContext();
        Hilos::$db->configure();
        Hilos::$sr = new StatusGuestSignalRouter();
        Hilos::$mail = new HilosMailer();
        $this->runtime = new StatusGuestRuntime();
        $this->runtime->mountFeatureRuntime([new AuthThrottleFeature()]);
        $this->runtime->configure();
        $this->runtime->bindStateCollectionNames();
        Hilos::$rt = $this->runtime;
        $this->owner = new StatusAgent();
        // Guest actions need no publisher startup; a later freeze lift rewinds its empty machine cursor.
        new ReflectionProperty(StatusAgent::class, 'components')->setValue($this->owner, new ArrayIterator());
        OwnershipDeclaration::claimDb(StatusAgent::class, $this->owner->getId());
        $this->ownerFrame = ExecutionContext::push(new ExecutionFrame(agentId: $this->owner->getId()));
        $this->sessionZone('Europe/Warsaw');
        $this->drain();
    }

    protected function tearDown(): void
    {
        SourceInterestRegistry::readsWhatItMounts();
        if ($this->ownerFrame !== null) {
            ExecutionContext::pop($this->ownerFrame);
        }
        TruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_STATUS);
        TruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_AUTH_THROTTLE);
        TruthSourceRegistry::unregisterAgent('status-session-fixture');
        RtTruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_AUTH_THROTTLE);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(HilosAgentType::HILOS_STATUS));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(HilosAgentType::HILOS_AUTH_THROTTLE));
        foreach (array_reverse(self::TABLES) as $table) {
            $this->stub($table, true);
        }
        foreach ($this->previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        foreach ($this->previousBus as $property => $value) {
            new ReflectionProperty(SourceChangeBus::class, $property)->setValue(null, $value);
        }
        [Hilos::$db, Hilos::$rt, Hilos::$sr, Hilos::$mail, Hilos::$env, Hilos::$setting, Hilos::$browser] = $this->previousGlobals;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        parent::tearDown();
    }

    public function testNewResentAndConfirmedAddressesHaveOneReplyAndOnlyPendingGetsMail(): void
    {
        $before = TimeHelper::nowMs();
        self::assertSame(['status' => 'check_inbox'], $this->act(new StatusSubscribeActionDTO(' READER@Example.Test ')));
        $first = $this->mail();
        $row = $this->rows()[0];
        self::assertSame(self::EMAIL, $row['email']);
        self::assertSame('Europe/Warsaw', $row['browser_time_zone']);
        self::assertGreaterThanOrEqual($before, (int)$row['subscribed_at']);
        self::assertLessThanOrEqual(TimeHelper::nowMs(), (int)$row['subscribed_at']);
        self::assertSame(86400000, (int)$row['confirmation_expires_at'] - (int)$row['subscribed_at']);
        self::assertSame([], Database::sql('SELECT id FROM hilos_user')->rows(), 'A guest subscription creates no account');
        self::assertSame(self::EMAIL, $first->to);
        self::assertSame(HilosMailer::shardKeyForAddress(self::EMAIL), $first->shardKey);
        self::assertNull($first->locale, 'Explicit project-default locale follows MailTemplate');
        self::assertSame(MailTemplateCatalogConstants::STATUS_SUBSCRIPTION_CONFIRM, $first->templateKey);
        self::assertNull($first->subject);
        self::assertNull($first->text);
        self::assertNull($first->html, 'The delivery channel alone owns the outer frame');
        self::assertSame([
            'projectName' => 'Example service',
            'statusUrl' => 'https://service.example.test/status',
            'confirmUrl' => 'https://service.example.test/status#status-confirm=' . $this->confirmToken($first),
            'unsubscribeUrl' => 'https://service.example.test/status#status-unsubscribe=' . $row['unsubscribe_token'],
            'expiresAtLabel' => new DateTimeImmutable('@' . intdiv((int)$row['confirmation_expires_at'], 1000))
                ->setTimezone(TimeHelper::serverTextTimeZone(browserTimeZone: 'Europe/Warsaw'))->format('Y-m-d H:i:s P (e)'),
        ], $first->params);
        self::assertSame(hash('sha256', $this->confirmToken($first)), $row['confirmation_token_hash']);

        $this->sessionZone('Asia/Tokyo');
        self::assertSame(['status' => 'check_inbox'], $this->act(new StatusSubscribeActionDTO(self::EMAIL)));
        $resent = $this->mail();
        self::assertNotSame($this->confirmToken($first), $this->confirmToken($resent));
        self::assertCount(1, $this->rows());
        self::assertSame($row['subscribed_at'], $this->rows()[0]['subscribed_at']);
        self::assertSame($row['unsubscribe_token'], $this->rows()[0]['unsubscribe_token']);
        self::assertSame('Europe/Warsaw', $this->rows()[0]['browser_time_zone']);
        $this->refused(new StatusConfirmSubscriptionActionDTO($this->confirmToken($first)));
        self::assertSame(['status' => 'subscribed'], $this->act(new StatusConfirmSubscriptionActionDTO($this->confirmToken($resent))));
        $confirmed = $this->rows();
        $this->refused(new StatusConfirmSubscriptionActionDTO($this->confirmToken($resent)));
        self::assertSame(['status' => 'check_inbox'], $this->act(new StatusSubscribeActionDTO(self::EMAIL)));
        self::assertSame($confirmed, $this->rows());
        self::assertSame([], $this->drain(), 'Confirmed repeat cannot issue another letter');
    }

    public function testPreviewDoesNotWriteAndRepeatedDeleteCannotTouchARecreatedAddress(): void
    {
        $this->act(new StatusSubscribeActionDTO(self::EMAIL));
        $firstMail = $this->mail();
        $before = $this->rows();
        $token = $before[0]['unsubscribe_token'];
        for ($repeat = 0; $repeat < 2; $repeat++) {
            self::assertSame(['maskedEmail' => IdentifierMask::mask(self::EMAIL)], $this->act(new StatusUnsubscribePreviewActionDTO($token)));
            self::assertSame($before, $this->rows());
        }
        putenv(EnvConstants::HILOS_STATUS_URL->name . '=');
        self::assertSame(['maskedEmail' => IdentifierMask::mask(self::EMAIL)], $this->act(new StatusUnsubscribePreviewActionDTO($token)));
        self::assertSame(['status' => 'subscribed'], $this->act(new StatusConfirmSubscriptionActionDTO($this->confirmToken($firstMail))));
        self::assertSame(['status' => 'unsubscribed'], $this->act(new StatusUnsubscribeActionDTO($token)));
        self::assertSame(['status' => 'unsubscribed'], $this->act(new StatusUnsubscribeActionDTO($token)));
        self::assertSame([], $this->rows());
        $this->refused(new StatusUnsubscribePreviewActionDTO($token));
        putenv(EnvConstants::HILOS_STATUS_URL->name . '=https://service.example.test/status');
        $this->act(new StatusSubscribeActionDTO(self::EMAIL));
        $replacement = $this->rows();
        self::assertNotSame($before[0]['id'], $replacement[0]['id']);
        self::assertNotSame($token, $replacement[0]['unsubscribe_token']);
        $this->refused(new StatusConfirmSubscriptionActionDTO($this->confirmToken($firstMail)));
        self::assertSame(['status' => 'unsubscribed'], $this->act(new StatusUnsubscribeActionDTO($token)));
        self::assertSame($replacement, $this->rows());
    }

    public function testExpiredAndUnknownConfirmRefuseWithoutMailOrWrites(): void
    {
        $issued = Database::transaction('primary', static fn () => Hilos::$db->statusSubscribers->actions->create(self::EMAIL, 1000));
        $before = $this->rows();
        $this->refused(new StatusConfirmSubscriptionActionDTO($issued->token));
        $this->refused(new StatusConfirmSubscriptionActionDTO(str_repeat('f', 64)));
        $this->refused(new StatusUnsubscribePreviewActionDTO(str_repeat('f', 64)));
        self::assertSame($before, $this->rows());
        self::assertSame([], $this->drain());
    }

    /** @return iterable<string, array{?string}> Every occupied freeze phase and the lost row */
    public static function frozenPhases(): iterable
    {
        foreach (['activating', 'active', 'verifying', 'deactivating', null] as $phase) {
            yield $phase ?? 'missing row' => [$phase];
        }
    }

    #[DataProvider('frozenPhases')]
    public function testEveryGuestDoorRefusesUnderFreezeOrMissingRow(?string $phase): void
    {
        $this->act(new StatusSubscribeActionDTO(self::EMAIL));
        $mail = $this->mail();
        $before = $this->rows();
        $this->runtime->phase($phase);
        foreach ([
            new StatusSubscribeActionDTO(self::EMAIL),
            new StatusConfirmSubscriptionActionDTO($this->confirmToken($mail)),
            new StatusUnsubscribePreviewActionDTO($before[0]['unsubscribe_token']),
            new StatusUnsubscribeActionDTO($before[0]['unsubscribe_token']),
        ] as $dto) {
            $this->refused($dto);
        }
        self::assertSame($before, $this->rows());
        self::assertSame([], $this->drain());
    }

    public function testMissingContextsConnectionsAndUnreadySourcesRefuseSafely(): void
    {
        Hilos::$rt = null;
        $this->refused(new StatusSubscribeActionDTO(self::EMAIL));
        Hilos::$rt = $this->runtime;
        $savedDb = Hilos::$db;
        Hilos::$db = null;
        $this->refused(new StatusUnsubscribeActionDTO(str_repeat('a', 64)));
        Hilos::$db = $savedDb;
        Hilos::$mail = null;
        $this->refused(new StatusSubscribeActionDTO(self::EMAIL));
        Hilos::$mail = new HilosMailer();
        $this->runtime->removeConnections();
        $this->refused(new StatusSubscribeActionDTO(self::EMAIL));
        $this->runtime->configure();
        SourceInterestRegistry::readsWhatIsDelivered();
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
        $this->refused(new StatusUnsubscribeActionDTO(str_repeat('a', 64)));
        SourceInterestRegistry::register(SourceChange::KIND_DB, HilosDbContext::statusSubscribers, 'status-unready');
        $this->refused(new StatusSubscribeActionDTO(self::EMAIL));
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::statusSubscribers);
        $this->refused(new StatusSubscribeActionDTO(self::EMAIL));
        SourceInterestRegistry::readsWhatItMounts();
        SourceInterestRegistry::releaseConsumer('status-unready');
        self::assertSame([], $this->rows());
        self::assertSame([], $this->drain());
    }

    public function testInvalidPublicUrlRefusesBeforeIssuanceButAbsentZoneUsesTheSharedFallback(): void
    {
        foreach (['', 'https://service.example.test/status?token=bad', 'file:///status'] as $url) {
            putenv(EnvConstants::HILOS_STATUS_URL->name . '=' . $url);
            $this->refused(new StatusSubscribeActionDTO(self::EMAIL));
            self::assertSame([], $this->rows());
            self::assertSame([], $this->drain());
        }
        putenv(EnvConstants::HILOS_STATUS_URL->name . '=https://service.example.test/status');
        putenv(EnvConstants::MAIL_FROM_NAME->name . '=');
        $this->sessionZone(null);
        $this->act(new StatusSubscribeActionDTO(self::EMAIL));
        self::assertNull($this->rows()[0]['browser_time_zone']);
        $mail = $this->mail();
        self::assertSame('Service', $mail->params['projectName']);
        self::assertStringEndsWith('+00:00 (UTC)', $mail->params['expiresAtLabel']);
    }

    public function testInvalidStoredBrowserReportIsSavedAsNullAndUsesTheCommonFallback(): void
    {
        // Simulate a legacy stored report before mounting a cold session reader.
        Database::sql('UPDATE hilos_session SET browser_time_zone = ? WHERE token = ?', ['Bad/Zone', self::SESSION_TOKEN]);
        Hilos::$db = new StatusGuestDbContext();
        Hilos::$db->configure();
        $this->act(new StatusSubscribeActionDTO(self::EMAIL));
        self::assertNull($this->rows()[0]['browser_time_zone']);
        self::assertStringEndsWith('+00:00 (UTC)', $this->mail()->params['expiresAtLabel']);
    }

    public function testRollbackDropsTheChallengeAndMailHandoff(): void
    {
        SourceChangeBus::subscribe(new StatusGuestFailureProbe(function (): void {
            self::assertSame([], $this->drain(), 'Mail cannot leave while the write can still roll back');
            throw new RuntimeException('Injected rollback');
        }));
        try {
            $this->act(new StatusSubscribeActionDTO(self::EMAIL));
            self::fail('The injected write failure must reach the caller');
        } catch (Throwable $failure) {
            self::assertStringContainsString('Injected rollback', $failure->getMessage());
        }
        self::assertSame([], $this->rows());
        self::assertNull(Hilos::$db->statusSubscribers->findByEmail(self::EMAIL), 'Rollback also removes the warmed ORM row');
        self::assertSame([], $this->drain());
    }

    public function testCommittedRecipientRemovalPreventsMailToTheOldPrimaryId(): void
    {
        SourceChangeBus::subscribe(new StatusGuestCommitProbe(function (): void {
            $subscriberId = Hilos::$db->statusSubscribers->findByEmail(self::EMAIL)?->id;
            self::assertNotNull($subscriberId);
            Hilos::$db->statusSubscribers[$subscriberId]->actions->delete();
        }));
        $this->act(new StatusSubscribeActionDTO(self::EMAIL));
        self::assertSame([], $this->rows());
        self::assertSame([], $this->drain(), 'The committed callback re-resolves its recipient by primary id');
    }

    public function testCommitReactionCanFreezeBeforeTheMailIsQueued(): void
    {
        SourceChangeBus::subscribe(new StatusGuestCommitProbe(function (): void {
            self::assertCount(1, $this->rows(), 'The row already committed before a reaction runs');
            self::assertSame([], $this->drain());
            $this->runtime->phase(ProtectedModeRuntime::PHASE_VERIFYING);
        }));
        $this->act(new StatusSubscribeActionDTO(self::EMAIL));
        self::assertCount(1, $this->rows());
        self::assertSame([], $this->drain(), 'afterCommit must recheck freeze');
        self::assertCount(1, Database::handlerEnd(), 'The committed handoff refusal is charged to its handler');
        Database::handlerStart();
    }

    public function testRealDispatcherWaitsForTheOwnersThreeScopesAndCorrelatesEveryGuestResult(): void
    {
        $gate = new AuthThrottleAgent();
        OwnershipDeclaration::claimAll($gate);
        $gate->onStart();
        $router = new PageSignalRouter(new StatusGuestPageFactory($this->owner), new ActionRouteConfig(),
            throttleVerdictSignal: StatusAgent::THROTTLE_VERDICT_SIGNAL);
        $reply = $this->dispatch($router, $gate, new StatusSubscribeActionDTO(self::EMAIL), 'subscribe', 3);
        self::assertSame(['status' => 'check_inbox'], $reply);
        $mail = $this->mail();
        $unsubscribe = $this->rows()[0]['unsubscribe_token'];
        self::assertSame(['status' => 'subscribed'], $this->dispatch($router, $gate,
            new StatusConfirmSubscriptionActionDTO($this->confirmToken($mail)), 'confirm', 2));
        self::assertSame(['maskedEmail' => IdentifierMask::mask(self::EMAIL)], $this->dispatch($router, $gate,
            new StatusUnsubscribePreviewActionDTO($unsubscribe), 'preview', 2));
        self::assertSame(['status' => 'unsubscribed'], $this->dispatch($router, $gate,
            new StatusUnsubscribeActionDTO($unsubscribe), 'unsubscribe', 2));
        $failure = $this->dispatch($router, $gate, new StatusConfirmSubscriptionActionDTO($this->confirmToken($mail)), 'used', 2, false);
        self::assertStringNotContainsString($this->confirmToken($mail), json_encode($failure, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::EMAIL, json_encode($failure, JSON_THROW_ON_ERROR));
    }

    public function testProductionThrottleDenialCarriesRetryAndDoesNotRotateThePendingChallenge(): void
    {
        putenv(EnvConstants::HILOS_AUTH_THROTTLE_MAX_SESSION->name . '=1');
        $gate = new AuthThrottleAgent();
        OwnershipDeclaration::claimAll($gate);
        $gate->onStart();
        $router = new PageSignalRouter(new StatusGuestPageFactory($this->owner), new ActionRouteConfig(),
            throttleVerdictSignal: StatusAgent::THROTTLE_VERDICT_SIGNAL);
        $this->dispatch($router, $gate, new StatusSubscribeActionDTO(self::EMAIL), 'first', 3);
        $this->mail();
        $before = $this->rows();
        $failure = $this->dispatch($router, $gate, new StatusSubscribeActionDTO(self::EMAIL), 'rate-limit', 3,
            success: false, throttled: true);
        self::assertSame('rate_limited', $failure['errorCode']);
        self::assertGreaterThan(0, $failure['retryAfter']);
        self::assertSame($before, $this->rows());
        self::assertSame([], $this->drain());
    }

    public function testMalformedProductionActionGetsACorrelatedSafeRefusalBeforeAnyWrite(): void
    {
        $router = new PageSignalRouter(new StatusGuestPageFactory($this->owner), new ActionRouteConfig(),
            throttleVerdictSignal: StatusAgent::THROTTLE_VERDICT_SIGNAL);
        foreach ([
            HilosSignalConstants::STATUS_SUBSCRIBE => ['email' => 'not-an-address'],
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM => ['token' => 'not-a-token'],
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW => ['token' => []],
            HilosSignalConstants::STATUS_UNSUBSCRIBE => [],
        ] as $action => $payload) {
            $router->dispatchAction(new WebSocketActionSignalDTO(
                acceptKey: self::ACCEPT_KEY, action: $action, data: $payload, requestId: 'malformed-' . $action,
                clientIp: '203.0.113.7', sessionIdentity: ThrottleIdentity::forSession(self::SESSION_TOKEN),
            ), SignalSource::WEBSOCKET);
            $frames = $this->drain();
            self::assertCount(1, $frames);
            self::assertSame(SignalConstants::ACTION_ERROR, $frames[0]->signalName->getName());
            self::assertSame('malformed-' . $action, $frames[0]->data->data->getEnvelopeRequestId());
            self::assertSame('fail', $frames[0]->data->data->getEnvelopeOutcome());
            self::assertStringNotContainsString('not-an-address', $frames[0]->data->data->reason);
            self::assertStringNotContainsString('not-a-token', $frames[0]->data->data->reason);
        }
        self::assertSame([], $this->rows());
    }

    /** @param ?string $zone Browser report photographed only when the subscription is created */
    private function sessionZone(?string $zone): void
    {
        TruthSourceRegistry::register(HilosDbContext::sessions, TruthSourceKeys::all(), 'status-session-fixture');
        $frame = ExecutionContext::push(new ExecutionFrame(agentId: 'status-session-fixture'));
        try {
            $session = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)
                ?? Hilos::$db->sessions->actions->createAnonymous(self::SESSION_TOKEN);
            Hilos::$db->sessions[$session->id]->actions->setBrowserTimeZone($zone);
        } finally {
            ExecutionContext::pop($frame);
        }
    }

    /**
     * @param ActionPayloadDTO $dto Parsed guest request
     * @return array<string, mixed> Approved reply
     */
    private function act(ActionPayloadDTO $dto): array
    {
        $reply = $this->owner->onAgentAction(self::ACCEPT_KEY, $dto->getAction(), $dto);
        self::assertInstanceOf(ActionReplyDTO::class, $reply);
        self::assertEquals($reply, $reply::fromArray($reply->toArray()));
        return $reply->toArray();
    }

    /** @param ActionPayloadDTO $dto Guest operation expected to refuse */
    private function refused(ActionPayloadDTO $dto): void
    {
        try {
            $this->act($dto);
            self::fail('This guest request must refuse');
        } catch (ValidationException $failure) {
            self::assertStringNotContainsString(self::EMAIL, $failure->getMessage());
            if (property_exists($dto, 'token')) {
                self::assertStringNotContainsString($dto->token, $failure->getMessage());
            }
        }
    }

    /** @return list<array<string, mixed>> Independent persisted rows, including the DB-only secrets */
    private function rows(): array
    {
        return Database::sql('SELECT * FROM hilos_status_subscriber ORDER BY id')->rows();
    }

    /** @return list<SignalDTO> Actual queued frames */
    private function drain(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (str_starts_with($frame->signalType->getType(), 'db_sync_')
                || str_starts_with($frame->signalType->getType(), 'rt_sync_')) {
                continue;
            }
            $frames[] = $frame;
        }
        return $frames;
    }

    /** @return MailSendSignalData The actual queued raw-send order */
    private function mail(): MailSendSignalData
    {
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertSame(HilosSignalConstants::HILOS_MAIL_SEND, $frames[0]->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $frames[0]->data);
        self::assertInstanceOf(MailSendSignalData::class, $frames[0]->data->data);
        return $frames[0]->data->data;
    }

    /**
     * @param MailSendSignalData $mail Queued confirmation
     * @return string Raw fragment secret
     */
    private function confirmToken(MailSendSignalData $mail): string
    {
        $prefix = 'https://service.example.test/status#status-confirm=';
        self::assertStringStartsWith($prefix, $mail->params[StatusSubscriptionConfirmMailTemplate::PARAM_CONFIRM_URL]);
        return substr($mail->params[StatusSubscriptionConfirmMailTemplate::PARAM_CONFIRM_URL], strlen($prefix));
    }

    /**
     * @param PageSignalRouter $router Production Status dispatch and parking pool
     * @param AuthThrottleAgent $gate Production single counter owner
     * @param ActionPayloadDTO $dto One actual guest action
     * @param string $requestId Correlation carried to the final ack
     * @param int $scopes Expected checks before execution
     * @param bool $success Expected domain outcome
     * @param bool $throttled Whether the session and email scopes deny a second attempt
     * @return array<string, mixed> Correlated domain reply or refusal
     */
    private function dispatch(
        PageSignalRouter $router,
        AuthThrottleAgent $gate,
        ActionPayloadDTO $dto,
        string $requestId,
        int $scopes,
        bool $success = true,
        bool $throttled = false,
    ): array {
        $router->dispatchAction(new WebSocketActionSignalDTO(
            acceptKey: self::ACCEPT_KEY, action: $dto->getAction(), data: $dto->toArray(), requestId: $requestId,
            clientIp: '203.0.113.7', sessionIdentity: ThrottleIdentity::forSession(self::SESSION_TOKEN),
        ), SignalSource::WEBSOCKET);
        $checks = $this->drain();
        self::assertCount($scopes, $checks, 'No action may pass through grace');
        $expected = $scopes === 3 ? [ThrottleScope::IP, ThrottleScope::SESSION, ThrottleScope::EMAIL] : [ThrottleScope::IP, ThrottleScope::SESSION];
        $seen = [];
        $frames = [];
        foreach ($checks as $position => $check) {
            self::assertSame(HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK, $check->signalName->getName());
            self::assertInstanceOf(ThrottleCheckSignalData::class, $check->data->data);
            self::assertSame(HilosAgentType::HILOS_STATUS, $check->data->data->agentType);
            $seen[] = $check->data->data->scope;
            $gateFrame = ExecutionContext::push(new ExecutionFrame(agentId: $gate->getId()));
            try {
                $gate->onSignalAgent($check->data, SignalSource::WORKER, HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK);
            } finally {
                ExecutionContext::pop($gateFrame);
            }
            $verdicts = $this->drain();
            self::assertCount(1, $verdicts);
            $verdict = $verdicts[0];
            self::assertSame(HilosSignalConstants::STATUS_THROTTLE_VERDICT, $verdict->signalName->getName());
            self::assertInstanceOf(ThrottleVerdictSignalData::class, $verdict->data->data);
            self::assertSame(!$throttled || $position === 0, $verdict->data->data->allowed);
            $router->dispatchAgentSignal($verdict->data, SignalSource::AGENT, HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT);
            self::assertSame([], $this->drain(), 'A foreign verdict name cannot resume Status');
            $router->dispatchAgentSignal($verdict->data, SignalSource::AGENT, HilosSignalConstants::STATUS_THROTTLE_VERDICT);
            $released = $this->drain();
            if ($position < $scopes - 1 && (!$throttled || $position === 0)) {
                self::assertSame([], $released, 'Every scope must answer before the guest handler runs');
            }
            array_push($frames, ...$released);
        }
        self::assertSame($expected, $seen);
        $ack = array_pop($frames);
        self::assertNotNull($ack);
        self::assertSame($requestId, $ack->data->data->getEnvelopeRequestId());
        self::assertSame($success ? 'success' : 'fail', $ack->data->data->getEnvelopeOutcome());
        self::assertSame($success ? SignalConstants::ACTION_SUCCESS : SignalConstants::ACTION_ERROR, $ack->signalName->getName());
        foreach ($frames as $frame) {
            Hilos::$sr->queueSignal($frame->signalSource, $frame->signalType, $frame->signalName, $frame->data);
        }
        return $success ? $ack->data->data->reply : $ack->data->data->toArray();
    }

    /**
     * @param string $table Existing framework stub
     * @param bool $down Drop rather than create
     */
    private function stub(string $table, bool $down): void
    {
        // external-boundary: the create stub has no suffix
        $suffix = $down ? '_down' : '';
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
    }
}

final class StatusGuestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_STATUS => [AgentRegistryKey::WORKER => StatusAgent::class],
        HilosAgentType::HILOS_AUTH_THROTTLE => [AgentRegistryKey::WORKER => AuthThrottleAgent::class],
    ];

    /** @return HilosDbContext The fixture's real subscriber and session chains */
    protected static function createDb(): HilosDbContext
    {
        return new StatusGuestDbContext();
    }
}

final class StatusGuestSignalRouter extends SignalRouter
{
    /** @return class-string<Hilos> Guest actions and their actual Status owner */
    protected function hilosClass(): string
    {
        return StatusGuestHilos::class;
    }
}

final class StatusGuestDbContext extends HilosDbContext
{
    /** Adds the real lazy chain without unrelated Status activation requirements. */
    public function configure(): void
    {
        parent::configure();
        $this->_objectCollections[self::statusSubscribers] = ObjectStatusSubscribers::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusSubscribers, StatusSubscribers::class, StatusSubscribersActions::class, StatusSubscriberActions::class);
    }
}

final class StatusGuestRuntime extends RtContext
{
    /** Mounts a real session-stage source with an anonymous ordering connection. */
    public function configure(): void
    {
        $connections = StatusGuestConnections::init();
        $connections->setCollectionName(StatusGuestConnections::RT_COLLECTION);
        $connections->add(StatusGuestConnection::create('status-guest', null, '0123456789abcdef0123456789abcdef'));
        $this->_stateCollections[StatusGuestConnections::RT_COLLECTION] = $connections;
        $this->phase(ProtectedModeRuntime::PHASE_INACTIVE);
    }

    /** @param ?string $phase Freeze state, or null for the unavailable unconditional row */
    public function phase(?string $phase): void
    {
        if ($phase === null) {
            unset($this->_stateItems[ProtectedModeRuntime::RT_ITEM]);
            return;
        }
        $freeze = ProtectedModeRuntime::create();
        $freeze->phase = $phase;
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $freeze);
    }

    /** Simulates an unavailable session-stage source rather than an absent browser report. */
    public function removeConnections(): void
    {
        unset($this->_stateCollections[StatusGuestConnections::RT_COLLECTION]);
    }
}

/** @extends HilosSessionConnections<StatusGuestConnection> */
final class StatusGuestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'statusGuestConnections';
    public const string STATE_CLASS = StatusGuestConnection::class;
}

final class StatusGuestConnection extends HilosSessionConnection
{
    /** No project fields. */
    protected function initOwn(): void
    {
    }
    /** @param array<string, mixed> $row No project fields */
    protected function hydrateOwn(array $row): void
    {
    }
    /** @return array<string, mixed> No project fields */
    protected function ownToArray(): array
    {
        return [];
    }
    /** @param array<string, mixed> $diff No project fields */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

final class StatusGuestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Unused
     * @return AbstractPage No page is needed by an agent-owned action
     */
    protected function createPage(string $pageName): AbstractPage
    {
        throw new RuntimeException('Guest actions belong to Status');
    }
    /**
     * @param string $pageName Unused
     * @return bool No pages in this fixture
     */
    public function hasPage(string $pageName): bool
    {
        return false;
    }
}

/** Probes the rollbackable write before any reaction or mail can leave. */
final class StatusGuestFailureProbe implements SourceMirrorSubscriberInterface
{
    private bool $armed = true;

    /** @param Closure(): void $probe One injected failure */
    public function __construct(private readonly Closure $probe)
    {
    }
    /**
     * @param SourceChange $change Actual write
     * @param SourceChangeProvenance $provenance Unused origin
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->armed && $change->sourceKey === HilosDbContext::statusSubscribers) {
            $this->armed = false;
            ($this->probe)();
        }
    }
}

/** Runs after commit, ahead of the mail callback registered by the handler. */
final class StatusGuestCommitProbe implements SourceChangeSubscriberInterface
{
    private bool $armed = true;

    /** @param Closure(): void $probe Post-commit freeze transition */
    public function __construct(private readonly Closure $probe)
    {
    }
    /**
     * @param SourceChange $change Committed write
     * @param SourceChangeProvenance $provenance Unused origin
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->armed && $change->sourceKey === HilosDbContext::statusSubscribers) {
            $this->armed = false;
            ($this->probe)();
        }
    }
}
