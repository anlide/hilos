<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\Commands;

use Demo\EcommerceShop\CLI\DTO\VoucherFixtureUpdateCommandData;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Database\Actions\VoucherFields;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AbstractCommandChannelTestCommand;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use JsonException;

/** Change the code or discount of a voucher fixture through the vouchers library on a test stand. */
final class VoucherFixtureUpdateCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    use VoucherFixtureCommandChannel;

    public const array OPTIONS = [self::OPTION_NEW_CODE, self::OPTION_DISCOUNT_PERCENT];

    private const string OPTION_NEW_CODE = 'new-code';
    private const string OPTION_DISCOUNT_PERCENT = 'discount-percent';

    /** @return string Test-only command name */
    public function getName(): string
    {
        return EcommerceShopCommandConstants::VOUCHER_FIXTURE_UPDATE;
    }

    /** @return string Help listing description */
    public function getDescription(): string
    {
        return 'Change the code or discount of a voucher fixture (test-only)';
    }

    /** @return string Usage and environment requirement */
    public function getHelp(): string
    {
        return 'php cli.php ' . $this->getName() . " <code> [--new-code=<code>] [--discount-percent=<1..100>]\nRequires APP_ENV=test.\n";
    }

    /**
     * @param array<string, mixed> $options Parsed command flags
     * @param list<string> $args One fixture code
     * @return int CLI exit code
     * @throws CommandException When the command name is not test-only
     * @throws InvalidFormatException When the payload requests no change
     * @throws JsonException When the confirmed reply cannot be encoded
     */
    protected function run(array $options, array $args): int
    {
        $newCode = $options[self::OPTION_NEW_CODE] ?? null;
        $discount = $options[self::OPTION_DISCOUNT_PERCENT] ?? null;
        if (count($args) !== 1 || ($newCode === null && $discount === null)
            || ($newCode !== null && !is_string($newCode)) || ($discount !== null && !is_string($discount))) {
            echo $this->getHelp();

            return ExitCode::INVALID_ARGUMENT;
        }
        try {
            $discountPercent = $discount === null ? null : VoucherFields::discountPercentFromInput($discount);
        } catch (ValidationException $failure) {
            $this->writeToStandardError($failure->getMessage());

            return ExitCode::INVALID_ARGUMENT;
        }

        return $this->sendVoucherFixture(new VoucherFixtureUpdateCommandData($args[0], $newCode, $discountPercent));
    }
}
