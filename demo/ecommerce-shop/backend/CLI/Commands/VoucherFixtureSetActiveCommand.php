<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\Commands;

use Demo\EcommerceShop\CLI\DTO\VoucherFixtureSetActiveCommandData;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AbstractCommandChannelTestCommand;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Exception\CommandException;
use JsonException;

/** Switch a voucher fixture on or off through the vouchers library on a test stand. */
final class VoucherFixtureSetActiveCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    use VoucherFixtureCommandChannel;

    public const array OPTIONS = [self::OPTION_ACTIVE];

    private const string OPTION_ACTIVE = 'active';

    /** @return string Test-only command name */
    public function getName(): string
    {
        return EcommerceShopCommandConstants::VOUCHER_FIXTURE_SET_ACTIVE;
    }

    /** @return string Help listing description */
    public function getDescription(): string
    {
        return 'Switch a voucher fixture on or off (test-only)';
    }

    /** @return string Command usage */
    public function getHelp(): string
    {
        return 'php cli.php ' . $this->getName() . " <code> --active=<on|off>\n";
    }

    /**
     * @param array<string, mixed> $options Parsed command flags
     * @param list<string> $args One fixture code
     * @return int CLI exit code
     * @throws CommandException When the command name is not test-only
     * @throws JsonException When the confirmed reply cannot be encoded
     */
    protected function run(array $options, array $args): int
    {
        $active = match ($options[self::OPTION_ACTIVE] ?? null) {
            'on' => true,
            'off' => false,
            default => null,
        };
        if (count($args) !== 1 || $active === null) {
            echo $this->getHelp();

            return ExitCode::INVALID_ARGUMENT;
        }

        return $this->sendVoucherFixture(new VoucherFixtureSetActiveCommandData($args[0], $active));
    }
}
