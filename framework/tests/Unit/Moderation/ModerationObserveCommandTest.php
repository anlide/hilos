<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Moderation;

use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\ModerationTestObserveCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Moderation\DTO\ModerationObserveCommandData;
use Hilos\Moderation\ModerationAgent;
use Hilos\Moderation\ModerationCommandConstants;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Schema, generic hydration, registration and environment latch of the moderator fence. */
final class ModerationObserveCommandTest extends TestCase
{
    public function testRouteAndHydrationUseTheDeclaredTypedDto(): void
    {
        $this->assertSame([ModerationCommandConstants::TEST_OBSERVE => ModerationAgent::AGENT_TYPE],
            ModerationObserveCommandTestHilos::getCommandAgentRoutes());
        $this->assertSame([ModerationCommandConstants::TEST_OBSERVE => ModerationObserveCommandData::class],
            ModerationObserveCommandTestHilos::getCommandDtoRoutes());
        $request = new CommandRequestDTO('observe', ModerationCommandConstants::TEST_OBSERVE, ['acceptKey' => 'ak', 'kind' => 'photo']);
        $parsed = new ModerationObserveCommandTestRouter()->createCommandPayloadDTO($request->command, $request);
        $this->assertInstanceOf(ModerationObserveCommandData::class, $parsed->parsedPayload);
        $this->assertSame($request->payload, $parsed->parsedPayload->toArray());
        $this->assertSame($request->payload, ModerationObserveCommandData::fromJson($parsed->parsedPayload->toJson())->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed coordinates */
    public static function malformed(): iterable
    {
        yield 'missing acceptKey' => [['kind' => 'photo']];
        yield 'missing kind' => [['acceptKey' => 'ak']];
        foreach (['', null, false, 7, ['ak']] as $index => $value) {
            yield 'acceptKey ' . $index => [['acceptKey' => $value, 'kind' => 'photo']];
            yield 'kind ' . $index => [['acceptKey' => 'ak', 'kind' => $value]];
        }
    }

    /** @param array<string, mixed> $payload Malformed coordinates */
    #[DataProvider('malformed')]
    public function testInvalidCoordinatesAreRefusedByGenericHydration(array $payload): void
    {
        $this->expectException(InvalidCommandPayloadException::class);
        new ModerationObserveCommandTestRouter()->createCommandPayloadDTO(ModerationCommandConstants::TEST_OBSERVE,
            new CommandRequestDTO('bad', ModerationCommandConstants::TEST_OBSERVE, $payload));
    }

    public function testEmptyConstructorCoordinatesAreRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        new ModerationObserveCommandData('', 'photo');
    }

    public function testCliRegistrationIsDatabaseFreeAndTestOnlyWithExactFlags(): void
    {
        $manager = new CliManager([]);
        $this->assertTrue($manager->hasCommand(ModerationCommandConstants::TEST_OBSERVE));
        $this->assertFalse($manager->requiresDatabase(ModerationCommandConstants::TEST_OBSERVE));
        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly(ModerationCommandConstants::TEST_OBSERVE));
        $this->assertSame(['accept-key', 'kind'], ModerationTestObserveCommand::OPTIONS);
        $this->expectOutputRegex('/--accept-key=<acceptKey> --kind=<kind>/');
        $this->assertSame(2, new ModerationTestObserveCommand()->execute(['accept-key' => 'ak'], []));
    }

    public function testCliRefusesWhenEnvironmentIsUnavailableBeforeOpeningAChannel(): void
    {
        $env = Hilos::$env;
        Hilos::$env = null;
        try {
            $this->expectException(TestOnlyCommandOnProductionException::class);
            new ModerationTestObserveCommand()->execute(['accept-key' => 'ak', 'kind' => 'photo'], []);
        } finally {
            Hilos::$env = $env;
        }
    }
}

abstract class ModerationObserveCommandTestHilos extends Hilos
{
    public const array AGENTS = [ModerationAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ModerationAgent::class]];
}

final class ModerationObserveCommandTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Facade with the real agent declaration */
    protected function hilosClass(): string
    {
        return ModerationObserveCommandTestHilos::class;
    }
}
