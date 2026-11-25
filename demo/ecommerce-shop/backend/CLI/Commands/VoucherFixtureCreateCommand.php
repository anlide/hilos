<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\CLI\Commands;

use Demo\EcommerceShop\CLI\DTO\VoucherFixtureCreateCommandData;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Database\Actions\VoucherFields;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AbstractCommandChannelTestCommand;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Exception\ValidationException;
use JsonException;

/** Create a voucher fixture through the vouchers library on a test stand. */
final class VoucherFixtureCreateCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    use VoucherFixtureCommandChannel;

    public const array OPTIONS = [self::OPTION_DISCOUNT_PERCENT, self::OPTION_ACTIVE];

    private const string OPTION_DISCOUNT_PERCENT = 'discount-percent';
    private const string OPTION_ACTIVE = 'active';

    /** @return string Test-only command name */
    public function getName(): string
    {
        return EcommerceShopCommandConstants::VOUCHER_FIXTURE_CREATE;
    }

    /** @return string Help listing description */
    public function getDescription(): string
    {
        return 'Create a voucher fixture (test-only)';
    }

    /** @return string Command usage */
    public function getHelp(): string
    {
        return 'php cli.php ' . $this->getName() . " <code> --discount-percent=<1..100> --active=<on|off>\n";
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
        $discount = $options[self::OPTION_DISCOUNT_PERCENT] ?? null;
        $active = match ($options[self::OPTION_ACTIVE] ?? null) {
            'on' => true,
            'off' => false,
            default => null,
        };
        if (count($args) !== 1 || !is_string($discount) || $active === null) {
            echo $this->getHelp();

            return ExitCode::INVALID_ARGUMENT;
        }
        try {
            $discountPercent = VoucherFields::discountPercentFromInput($discount);
        } catch (ValidationException $failure) {
            $this->writeToStandardError($failure->getMessage());

            return ExitCode::INVALID_ARGUMENT;
        }

        return $this->sendVoucherFixture(new VoucherFixtureCreateCommandData($args[0], $discountPercent, $active));
    }
}
