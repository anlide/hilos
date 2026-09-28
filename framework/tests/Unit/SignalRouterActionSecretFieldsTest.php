<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos as HilosFacade;
use PHPUnit\Framework\TestCase;

/**
 * Which secret fields analytics masks for an action name (HIL-1187).
 *
 * The router answers by the DTO the action is routed to, found the way the action is routed -
 * an agent's AGENT_ACTIONS before a page's ACTIONS - and says null for a name the topology does
 * not route, so the collector can tell "declares nothing" from "is no action it knows".
 */
final class SignalRouterActionSecretFieldsTest extends TestCase
{
    public function testAgentActionIsAnsweredBeforeThePageActionOfTheSameName(): void
    {
        $router = new SecretFieldsTestRouter();

        $this->assertSame(['agentSecret'], $router->actionSecretFields(SecretFieldsTestPage::SHARED_ACTION));
    }

    public function testPageActionIsAnsweredWithWhatItsDtoDeclares(): void
    {
        $router = new SecretFieldsTestRouter();

        $this->assertSame(['code'], $router->actionSecretFields(SecretFieldsTestPage::SECRET_ACTION));
        $this->assertSame([], $router->actionSecretFields(SecretFieldsTestPage::PLAIN_ACTION));
    }

    public function testNameTheTopologyDoesNotRouteIsAnsweredWithNull(): void
    {
        $this->assertNull((new SecretFieldsTestRouter())->actionSecretFields('secret_fields_unknown'));
    }

    public function testDtoThatDeclaresNothingIsAnsweredWithNull(): void
    {
        $this->assertNull((new SecretFieldsTestRouter())->actionSecretFields(SecretFieldsTestPage::UNDECLARED_ACTION));
    }

    public function testEachNameIsLookedUpOnce(): void
    {
        $router = new SecretFieldsTestRouter();
        $router->actionSecretFields(SecretFieldsTestPage::SECRET_ACTION);
        $router->actionSecretFields('secret_fields_unknown');
        $reads = $router->topologyReads;

        $this->assertSame(['code'], $router->actionSecretFields(SecretFieldsTestPage::SECRET_ACTION));
        $this->assertNull($router->actionSecretFields('secret_fields_unknown'));
        $this->assertSame($reads, $router->topologyReads);
    }
}

/**
 * Fixture base: the payload is irrelevant here, only the declaration of the concrete class is read.
 */
abstract class SecretFieldsTestActionDTO extends ActionPayloadDTO
{
    /**
     * @param array<string, mixed> $data Payload data (unused)
     * @return static Empty fixture DTO
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * @return string Fixture action name
     */
    public function getAction(): string
    {
        return static::class;
    }

    /**
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }
}

final class SecretFieldsAgentActionDTO extends SecretFieldsTestActionDTO
{
    public const array SECRET_FIELDS = ['agentSecret'];
}

final class SecretFieldsPageActionDTO extends SecretFieldsTestActionDTO
{
    public const array SECRET_FIELDS = ['pageSecret'];
}

final class SecretFieldsCodeActionDTO extends SecretFieldsTestActionDTO
{
    public const array SECRET_FIELDS = ['code'];
}

final class SecretFieldsPlainActionDTO extends SecretFieldsTestActionDTO
{
    public const array SECRET_FIELDS = [];
}

final class SecretFieldsUndeclaredActionDTO extends SecretFieldsTestActionDTO
{
}

final class SecretFieldsTestPage extends AbstractPage
{
    public const string PAGE = 'secret_fields_page';

    public const string SHARED_ACTION = 'secret_fields_shared';

    public const string SECRET_ACTION = 'secret_fields_secret';

    public const string PLAIN_ACTION = 'secret_fields_plain';

    public const string UNDECLARED_ACTION = 'secret_fields_undeclared';

    public const array ACTIONS = [
        self::SHARED_ACTION => SecretFieldsPageActionDTO::class,
        self::SECRET_ACTION => SecretFieldsCodeActionDTO::class,
        self::PLAIN_ACTION => SecretFieldsPlainActionDTO::class,
        self::UNDECLARED_ACTION => SecretFieldsUndeclaredActionDTO::class,
    ];
}

final class SecretFieldsTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'secret_fields_agent';

    public const array AGENT_ACTIONS = [
        SecretFieldsTestPage::SHARED_ACTION => SecretFieldsAgentActionDTO::class,
    ];

    /**
     * Fixture agent stops with nothing to unwind.
     */
    public function onStop(): void
    {
    }
}

/**
 * DB context the fixture facade must return; no test here reaches the database.
 */
final class SecretFieldsTestDbContext extends HilosDbContext
{
    /**
     * No-op DB configuration for the secret-fields tests.
     */
    public function configure(): void
    {
    }
}

/**
 * Topology fixture: one page and one agent claiming a name of the page's as well.
 */
final class SecretFieldsTestHilos extends HilosFacade
{
    public const array PAGES = [
        SecretFieldsTestPage::PAGE => SecretFieldsTestPage::class,
    ];

    public const array AGENTS = [
        SecretFieldsTestAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => SecretFieldsTestAgent::class],
    ];

    /**
     * Creates the no-op DB context the fixture facade is built with.
     *
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new SecretFieldsTestDbContext();
    }
}

/**
 * Router over the fixture topology that counts how often it reads it.
 */
final class SecretFieldsTestRouter extends SignalRouter
{
    /** @var int Times the topology facade was asked for */
    public int $topologyReads = 0;

    /**
     * @return string Fixture Hilos facade class, counted
     */
    protected function hilosClass(): string
    {
        $this->topologyReads++;

        return SecretFieldsTestHilos::class;
    }
}
