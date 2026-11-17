<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\OAuth\RawOAuthProviderSnapshot;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Topology\AgentCommandRouteRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use PHPUnit\Framework\TestCase;

/** Protocol, gate and dispatch checks; database effects belong to the integration case. */
final class OAuthProviderSnapshotCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?HilosDbContext $previousDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousDb = Hilos::$db;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        Hilos::$db = null;
        putenv(EnvConstants::APP_ENV->name . '=test');
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$db = $this->previousDb;
        parent::tearDown();
    }

    public function testNonProductionGateRefusesBothCommandsBeforeReadingState(): void
    {
        foreach (['production', 'unrecognized'] as $environment) {
            putenv(EnvConstants::APP_ENV->name . '=' . $environment);
            foreach ([CliCommands::OAUTH_PROVIDER_TEST_SNAPSHOT, CliCommands::OAUTH_PROVIDER_TEST_RESTORE] as $command) {
                self::assertFalse($this->command($command, [])->isOk());
            }
        }
        Hilos::$env = null;
        self::assertFalse($this->command(CliCommands::OAUTH_PROVIDER_TEST_SNAPSHOT, ['providerKey' => 'oauth:github'])->isOk());
    }

    public function testUnknownProviderIsRefusedWithoutAStoredRowOrEnvCredentials(): void
    {
        self::assertFalse($this->command(CliCommands::OAUTH_PROVIDER_TEST_SNAPSHOT, ['providerKey' => 'oauth:unknown'])->isOk());
        self::assertFalse($this->command(CliCommands::OAUTH_PROVIDER_TEST_RESTORE, [
            'snapshot' => ['providerKey' => 'oauth:unknown', 'row' => null], 'currentId' => null,
        ])->isOk());
    }

    public function testMalformedSnapshotsAndStrictIdsRefuseBeforeAnyWrite(): void
    {
        $original = [
            'providerKey' => 'oauth:github',
            'row' => ['id' => 7, 'clientId' => '', 'clientSecret' => null, 'scope' => null, 'createdAt' => -3, 'updatedAt' => 0],
        ];
        self::assertSame($original, RawOAuthProviderSnapshot::fromArray($original)->toArray());
        $invalid = [
            array_replace($original, ['extra' => true]),
            ['providerKey' => 'oauth:github'],
            ['providerKey' => '', 'row' => null],
        ];
        foreach (['7', 0, -1, 1.5, true, null] as $id) {
            $invalid[] = array_replace($original, ['row' => array_replace($original['row'], ['id' => $id])]);
        }
        foreach (['clientId' => 7, 'clientSecret' => false, 'scope' => [], 'createdAt' => '3', 'updatedAt' => 3.5, 'extra' => 1] as $key => $value) {
            $invalid[] = array_replace($original, ['row' => array_replace($original['row'], [$key => $value])]);
        }
        foreach (array_keys($original['row']) as $key) {
            $row = $original['row'];
            unset($row[$key]);
            $invalid[] = array_replace($original, ['row' => $row]);
        }
        foreach ($invalid as $snapshot) {
            try {
                RawOAuthProviderSnapshot::fromArray($snapshot);
                self::fail('Malformed snapshot was accepted');
            } catch (InvalidFormatException) {
            }
            self::assertFalse($this->command(CliCommands::OAUTH_PROVIDER_TEST_RESTORE, [
                'snapshot' => $snapshot, 'currentId' => 7,
            ])->isOk());
        }
        foreach (['7', 0, false, 1.5] as $currentId) {
            self::assertFalse($this->command(CliCommands::OAUTH_PROVIDER_TEST_RESTORE, [
                'snapshot' => $original, 'currentId' => $currentId,
            ])->isOk());
        }
    }

    public function testBothNamesRouteOnlyToTheIndexOwnerWithoutPayloadOrReceiptAddressRoutes(): void
    {
        $agents = [HilosAgentType::HILOS_INDEX => [AgentRegistryKey::WORKER => SnapshotCommandIndexAgent::class]];
        $routes = AgentCommandRouteRegistry::routes($agents);
        foreach ([CliCommands::OAUTH_PROVIDER_TEST_SNAPSHOT, CliCommands::OAUTH_PROVIDER_TEST_RESTORE] as $command) {
            self::assertSame(HilosAgentType::HILOS_INDEX, $routes[$command]);
            self::assertArrayNotHasKey($command, AgentCommandRouteRegistry::dtoRoutes($agents));
            self::assertArrayNotHasKey($command, AgentCommandRouteRegistry::addressRoutes($agents));
            self::assertFalse($this->command($command, [])->isOk());
        }
    }

    public function testErrorsNeverEchoRawFieldsOrThePayload(): void
    {
        $secret = 'synthetic-private-provider-value';
        $reply = $this->command(CliCommands::OAUTH_PROVIDER_TEST_RESTORE, [
            'snapshot' => ['providerKey' => $secret, 'row' => ['clientSecret' => $secret]], 'currentId' => 7,
        ]);
        self::assertFalse($reply->isOk());
        self::assertFalse(str_contains($reply->toJson(), $secret));
        self::assertSame(['message'], array_keys($reply->payload));
    }

    public function testEchoAndExistingIndexCommandDispatchArePreserved(): void
    {
        $reply = $this->command(CliCommands::COMMAND_TEST_ECHO, ['echo' => 'unchanged']);
        self::assertTrue($reply->isOk());
        self::assertSame(['echo' => 'unchanged'], $reply->payload);
        self::assertFalse($this->command('unknown', [])->isOk());
        foreach ([
            CliCommands::PROTECTED_MODE_TEST_ENTER, CliCommands::PROTECTED_MODE_TEST_LEAVE,
            CliCommands::PROTECTED_MODE_TEST_OPEN, CliCommands::PROTECTED_MODE_TEST_PASS, CliCommands::PROTECTED_MODE_TEST_CLOSE,
            CliCommands::MAINTENANCE_ENABLE, CliCommands::MAINTENANCE_DISABLE, CliCommands::MAINTENANCE_PASS,
        ] as $command) {
            self::assertContains($command, AbstractHilosIndexAgent::AGENT_COMMANDS);
        }
    }

    /**
     * @param string $name Command under test
     * @param array<string, mixed> $payload Closed-channel data
     * @return CommandReplyDTO Exactly one correlated reply
     */
    private function command(string $name, array $payload): CommandReplyDTO
    {
        $agent = new SnapshotCommandIndexAgent();
        $agent->onSignalCommand(new CommandRequestDTO('oauth-snapshot-unit', $name, $payload), 'cli', $name);
        self::assertCount(1, $agent->replies);
        self::assertSame('oauth-snapshot-unit', $agent->replies[0]->correlationId);

        return $agent->replies[0];
    }
}

/** Captures the real handler's reply without a transport or a daemon. */
final class SnapshotCommandIndexAgent extends AbstractHilosIndexAgent
{
    /** @var list<CommandReplyDTO> Correlated replies */
    public array $replies = [];

    public function replyToCommand(CommandReplyDTO $reply): void
    {
        $this->replies[] = $reply;
    }
}
