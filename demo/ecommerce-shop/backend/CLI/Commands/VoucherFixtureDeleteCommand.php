<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\Commands;

use Demo\EcommerceShop\CLI\DTO\VoucherFixtureCodeCommandData;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AbstractCommandChannelTestCommand;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Exception\CommandException;
use JsonException;

/** Delete a voucher fixture through the vouchers library on a test stand. */
final class VoucherFixtureDeleteCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    use VoucherFixtureCommandChannel;

    public const array OPTIONS = [];

    /** @return string Test-only command name */
    public function getName(): string
    {
        return EcommerceShopCommandConstants::VOUCHER_FIXTURE_DELETE;
    }

    /** @return string Help listing description */
    public function getDescription(): string
    {
        return 'Delete a voucher fixture (test-only)';
    }

    /** @return string Usage and environment requirement */
    public function getHelp(): string
    {
        return 'php cli.php ' . $this->getName() . " <code>\nRequires APP_ENV=test.\n";
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
        if (count($args) !== 1) {
            echo $this->getHelp();

            return ExitCode::INVALID_ARGUMENT;
        }

        return $this->sendVoucherFixture(new VoucherFixtureCodeCommandData($args[0]));
    }
}
