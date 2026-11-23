<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\CLI\DTO\VoucherFixtureCodeCommandData;
use Demo\EcommerceShop\CLI\DTO\VoucherFixtureCreateCommandData;
use Demo\EcommerceShop\CLI\DTO\VoucherFixtureSetActiveCommandData;
use Demo\EcommerceShop\CLI\DTO\VoucherFixtureUpdateCommandData;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Voucher as EntityVoucher;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Testing\VoucherFixture;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/**
 * The shop's vouchers library is the only writer of vouchers.
 *
 * One instance runs per cluster on the node chosen by the placement policy.
 * The library owns creation and updates, and may remove test fixtures on a test stand.
 * See docs/agents/architecture/entity-libraries.md.
 */
final class VouchersLibraryAgent extends AbstractAgent
{
    /** The vouchers table, owned whole with operations chosen by the environment. */
    public const array OWNS_DB = [EcommerceShopDbContext::vouchers => TruthSourceOperation::BY_KIND];

    public const string AGENT_TYPE = AgentType::VOUCHERS_LIBRARY;

    public const array AGENT_COMMANDS = [
        EcommerceShopCommandConstants::VOUCHER_FIXTURE_CREATE => VoucherFixtureCreateCommandData::class,
        EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET => VoucherFixtureCodeCommandData::class,
        EcommerceShopCommandConstants::VOUCHER_FIXTURE_UPDATE => VoucherFixtureUpdateCommandData::class,
        EcommerceShopCommandConstants::VOUCHER_FIXTURE_SET_ACTIVE => VoucherFixtureSetActiveCommandData::class,
        EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE => VoucherFixtureCodeCommandData::class,
    ];

    /** The library has no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /** @return TruthSourceOperations Add and Update everywhere, with Remove on test stands */
    public static function defaultTruthSourceOperations(): TruthSourceOperations
    {
        if (VoucherFixture::isAllowed()) {
            return TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Update, TruthSourceOperation::Remove);
        }

        return TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Update);
    }

    /**
     * The project requires the test environment even on nodes admitted by the socket's non-production gate.
     *
     * @param CommandRequestDTO $data Topology-hydrated fixture command
     * @param string $source Command source, unused
     * @param string $name Signal name, unused
     * @throws AgentException When the voucher cannot be read or written
     * @throws InvalidArgumentException When the reply cannot be queued
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if (!VoucherFixture::isAllowed()) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, VoucherFixture::ENVIRONMENT_REFUSAL));

            return;
        }

        try {
            $reply = CommandReplyDTO::ok($data->correlationId, $this->fixtureResult($data));
        } catch (ValidationException $failure) {
            $reply = CommandReplyDTO::error($data->correlationId, $failure->getMessage());
        } catch (HilosException $failure) {
            throw new AgentException('The voucher fixture command could not be completed', previous: $failure);
        }
        $this->replyToCommand($reply);
    }

    /** WorkerManager releases the claim after the library stops. */
    public function onStop(): void
    {
    }

    /**
     * @param CommandRequestDTO $request Hydrated fixture command
     * @return array{voucher: array{id: int, code: string, discountPercent: int, active: bool}|null}|array{deleted: bool} Command reply payload
     * @throws ValidationException When the command or fixture is invalid, or an edited fixture is absent
     * @throws HilosException When the voucher cannot be read or written
     */
    private function fixtureResult(CommandRequestDTO $request): array
    {
        $expected = self::AGENT_COMMANDS[$request->command] ?? null;
        $query = $request->parsedPayload;
        if ($expected === null || !$query instanceof $expected) {
            throw new ValidationException('Invalid voucher fixture command payload');
        }

        if ($query instanceof VoucherFixtureCreateCommandData) {
            return self::voucherResult(Hilos::$db->vouchers->actions->create(
                VoucherFixture::code($query->code),
                $query->discountPercent,
                $query->active,
            ));
        }

        /** @var VoucherFixtureCodeCommandData|VoucherFixtureUpdateCommandData|VoucherFixtureSetActiveCommandData $query */
        $code = VoucherFixture::code($query->code);
        $newCode = $query instanceof VoucherFixtureUpdateCommandData && $query->newCode !== null
            ? VoucherFixture::code($query->newCode)
            : $code;
        $voucher = Hilos::$db->vouchers->whereColumnIs(EntityVoucher::code, $code)->first();
        if ($request->command === EcommerceShopCommandConstants::VOUCHER_FIXTURE_GET) {
            return self::voucherResult($voucher);
        }
        if ($request->command === EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE) {
            $voucher?->actions->deleteFixture();

            return [EcommerceShopCommandConstants::deleted => $voucher !== null];
        }
        if ($voucher === null) {
            throw new ValidationException('No voucher fixture has this code');
        }

        if ($query instanceof VoucherFixtureUpdateCommandData) {
            $voucher->actions->update($newCode, $query->discountPercent ?? $voucher->discountPercent, $voucher->active);
        } elseif ($query instanceof VoucherFixtureSetActiveCommandData) {
            $voucher->actions->setActive($query->active);
        }

        return self::voucherResult($voucher);
    }

    /**
     * @param ?Voucher $voucher The voucher to return, or null for an absent code
     * @return array{voucher: array{id: int, code: string, discountPercent: int, active: bool}|null} Read or write result
     * @throws ValidationException When the voucher was not persisted
     */
    private static function voucherResult(?Voucher $voucher): array
    {
        return [
            EcommerceShopCommandConstants::voucher => $voucher === null ? null : [
                EcommerceShopCommandConstants::id => $voucher->id ?? throw new ValidationException('Voucher fixture has no ID'),
                EcommerceShopCommandConstants::code => $voucher->code,
                EcommerceShopCommandConstants::discountPercent => $voucher->discountPercent,
                EcommerceShopCommandConstants::active => $voucher->active,
            ],
        ];
    }
}
