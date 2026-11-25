<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\SessionsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UsersLibraryAgent;
use Demo\EcommerceShop\CLI\DTO\VoucherFixtureCreateCommandData;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\AppEnv;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Environment\EnvAccessor;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;

/** Exercises the real library's command DTOs, reply, and writes in a rollback scope. */
#[IntegrationTest]
final class VoucherFixtureCommandsTest extends VouchersLibraryEnvironment
{
    /** The five commands return current fields, preserve the voucher ID, and remove the fixture. */
    public function testVoucherFixtureLifecycle(): void
    {
        $code = $this->unusedCode();
        $newCode = $this->unusedCode();
        $created = $this->command(
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_CREATE,
            new VoucherFixtureCreateCommandData(' ' . strtolower($code) . ' ', 25, true)->toArray(),
        );
        self::assertTrue($created->isOk());
        $id = $created->payload['voucher']['id'] ?? null;
        self::assertIsInt($id);
        $row = ['id' => $id, 'code' => $code, 'discountPercent' => 25, 'active' => true];
        self::assertSame(['voucher' => $row], $created->payload);
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET, ['code' => $code], ['voucher' => $row]);

        $row['code'] = $newCode;
        $this->assertResult(
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_UPDATE,
            ['code' => $code, 'newCode' => strtolower($newCode)],
            ['voucher' => $row],
        );
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET, ['code' => $code], ['voucher' => null]);

        $row['discountPercent'] = 100;
        $this->assertResult(
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_UPDATE,
            ['code' => $newCode, 'newCode' => null, 'discountPercent' => 100],
            ['voucher' => $row],
        );
        $row['active'] = false;
        $this->assertResult(
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_SET_ACTIVE,
            ['code' => $newCode, 'active' => false],
            ['voucher' => $row],
        );
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET, ['code' => $newCode], ['voucher' => $row]);
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE, ['code' => $newCode], ['deleted' => true]);
        self::assertFalse(isset(Hilos::$db->vouchers[$id]));
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE, ['code' => $newCode], ['deleted' => false]);
    }

    /** Editing a missing fixture is a refusal, and every command checks the fixture code space. */
    public function testCommandsDistinguishMissingAndNonFixtureCodes(): void
    {
        $missing = $this->unusedCode();
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE, ['code' => $missing], ['deleted' => false]);
        foreach ([
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_UPDATE => ['discountPercent' => 45],
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_SET_ACTIVE => ['active' => false],
        ] as $command => $fields) {
            self::assertRefusal($this->command($command, ['code' => $missing, ...$fields]), 'No voucher fixture has this code');
        }

        $ordinaryCode = 'SPRING' . strtoupper(RandomHelper::hex(12));
        $ordinary = Hilos::$db->vouchers->actions->create($ordinaryCode, 15, true);
        $fixtureCodeRefusal = 'A voucher fixture code must be ZZE2EV followed by 24 hexadecimal digits';
        foreach ([
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_CREATE => ['discountPercent' => 25, 'active' => true],
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET => [],
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_UPDATE => ['discountPercent' => 45],
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_SET_ACTIVE => ['active' => false],
            EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE => [],
        ] as $command => $fields) {
            self::assertRefusal($this->command($command, ['code' => $ordinaryCode, ...$fields]), $fixtureCodeRefusal);
        }

        $fixtureCode = $this->unusedCode();
        $created = $this->command(EcommerceShopCommandConstants::VOUCHER_FIXTURE_CREATE, [
            'code' => $fixtureCode,
            'discountPercent' => 25,
            'active' => true,
        ]);
        self::assertTrue($created->isOk());
        self::assertRefusal($this->command(EcommerceShopCommandConstants::VOUCHER_FIXTURE_UPDATE, [
            'code' => $fixtureCode,
            'newCode' => $ordinaryCode,
        ]), $fixtureCodeRefusal);
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET, ['code' => $fixtureCode], $created->payload);
        self::assertSame(15, $ordinary->discountPercent);
        self::assertTrue($ordinary->active);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($fixtureCodeRefusal);
        $ordinary->actions->deleteFixture();
    }

    /** The update request must include at least one replacement. */
    public function testUpdateWithoutAnyReplacementIsMalformed(): void
    {
        $this->expectException(InvalidCommandPayloadException::class);
        $this->command(EcommerceShopCommandConstants::VOUCHER_FIXTURE_UPDATE, ['code' => $this->unusedCode()]);
    }

    /** The fixture stays referenced by a pending cart even after its session is swept. */
    public function testDeleteRefusesAVoucherHeldByACart(): void
    {
        $code = $this->unusedCode();
        $created = $this->command(EcommerceShopCommandConstants::VOUCHER_FIXTURE_CREATE, [
            'code' => $code,
            'discountPercent' => 35,
            'active' => true,
        ]);
        self::assertTrue($created->isOk());
        $voucherId = $created->payload['voucher']['id'] ?? null;
        self::assertIsInt($voucherId);

        $session = self::asOwner(
            new SessionsLibraryAgent(),
            static fn () => Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint()),
        );
        $sessionId = $session->id;
        self::assertIsInt($sessionId);
        self::asOwner(
            new GuestCartsLibraryAgent(),
            static fn () => Hilos::$db->sessionVoucherCarts->actions->create($sessionId, 0, $voucherId),
        );

        self::assertRefusal(
            $this->command(EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE, ['code' => $code]),
            'This voucher is in a cart and cannot be deleted',
        );
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET, ['code' => $code], $created->payload);
        self::assertSame($voucherId, Hilos::$db->sessionVoucherCarts->storedVoucherId($sessionId, 0));

        $recipient = self::asOwner(new UsersLibraryAgent(), static fn () => Hilos::$db->users->actions->createWithName('Voucher recipient'));
        self::asOwner(new SessionsLibraryAgent(), static function () use ($session, $recipient): void {
            $session->actions->bindUser($recipient->id);
            $session->actions->delete();
        });
        $carts = new GuestCartsLibraryAgent();
        self::asOwner($carts, static fn () => $carts->onSignalAgent(
            new AgentSignalData(new SessionsSweptSignalData([$session->token], [$sessionId])),
            '',
            HilosSignalConstants::HILOS_SESSIONS_SWEPT,
        ));

        self::assertNull(Hilos::$db->sessions->findByToken($session->token));
        self::assertSame($voucherId, Hilos::$db->sessionVoucherCarts->storedVoucherId($sessionId, 0));
        $this->assertRefusal(
            $this->command(EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE, ['code' => $code]),
            'This voucher is in a cart and cannot be deleted',
        );
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET, ['code' => $code], $created->payload);
    }

    /** A dev node can pass the shared socket gate and is still refused by the fixture handler. */
    public function testDirectCommandRequestsAreRefusedInDev(): void
    {
        $code = $this->unusedCode();
        $previousEnv = Hilos::$env;
        $env = $this->createPartialMock(EnvAccessor::class, ['effectiveValueFor']);
        $env->method('effectiveValueFor')->with(EnvConstants::APP_ENV->name)->willReturn(AppEnv::DEV->value);
        Hilos::$env = $env;
        try {
            foreach ([
                EcommerceShopCommandConstants::VOUCHER_FIXTURE_CREATE => ['discountPercent' => 25, 'active' => true],
                EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET => [],
                EcommerceShopCommandConstants::VOUCHER_FIXTURE_UPDATE => ['discountPercent' => 45],
                EcommerceShopCommandConstants::VOUCHER_FIXTURE_SET_ACTIVE => ['active' => false],
                EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE => [],
            ] as $command => $fields) {
                self::assertRefusal($this->command($command, ['code' => $code, ...$fields]), 'Voucher fixtures require APP_ENV=test');
            }
        } finally {
            Hilos::$env = $previousEnv;
        }
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET, ['code' => $code], ['voucher' => null]);
    }

    /** @return string Random fixture code verified absent before use */
    private function unusedCode(): string
    {
        $code = 'ZZE2EV' . strtoupper(RandomHelper::hex(12));
        $this->assertResult(EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET, ['code' => $code], ['voucher' => null]);

        return $code;
    }

    /**
     * @param string $name Command channel name
     * @param array<string, mixed> $payload Request payload
     * @return CommandReplyDTO The only reply for this request
     */
    private function command(string $name, array $payload): CommandReplyDTO
    {
        $previousRouter = Hilos::$sr;
        $router = new EcommerceShopSignalRouter();
        Hilos::initSignalRouter($router);
        try {
            $request = $router->createCommandPayloadDTO($name, new CommandRequestDTO($name, $name, $payload));
            $this->vouchersLibrary()->onSignalCommand($request, 'command', $name);
            $replies = [];
            while (($signal = $router->getNextQueuedSignal()) !== null) {
                if ($signal->data instanceof CommandReplyDTO) {
                    $replies[] = $signal->data;
                }
            }
        } finally {
            Hilos::$sr = $previousRouter;
        }
        self::assertCount(1, $replies);
        self::assertSame($name, $replies[0]->correlationId);

        return $replies[0];
    }

    /**
     * @param string $name Command channel name
     * @param array<string, mixed> $payload Request payload
     * @param array<string, mixed> $expected Confirmed reply payload
     */
    private function assertResult(string $name, array $payload, array $expected): void
    {
        $reply = $this->command($name, $payload);
        self::assertTrue($reply->isOk(), $reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'Expected a successful fixture reply');
        self::assertSame($expected, $reply->payload);
    }

    /**
     * @param CommandReplyDTO $reply Library's answer
     * @param string $message Expected refusal
     */
    private static function assertRefusal(CommandReplyDTO $reply, string $message): void
    {
        self::assertFalse($reply->isOk());
        self::assertSame([CommandConstants::FIELD_MESSAGE => $message], $reply->payload);
    }

    /**
     * @template T
     * @param AbstractAgent $owner Library that owns the fixture
     * @param callable(): T $write Fixture operation
     * @return T Result from the operation
     */
    private static function asOwner(AbstractAgent $owner, callable $write): mixed
    {
        try {
            OwnershipDeclaration::claimAll($owner);

            return ExecutionContext::run(new ExecutionFrame(agentId: $owner->getId()), $write);
        } finally {
            TruthSourceRegistry::unregisterAgent($owner->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($owner->getId()));
        }
    }
}
