<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Testing;

use Demo\EcommerceShop\Database\Actions\VoucherFields;
use Demo\EcommerceShop\Hilos;
use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Environment\Exception\EnvException;

/** The environment and code space reserved for voucher fixtures. */
final class VoucherFixture
{
    public const string ENVIRONMENT_REFUSAL = 'Voucher fixtures require APP_ENV=test';

    private const string CODE_PATTERN = '/\AZZE2EV[0-9A-F]{24}\z/';

    /** @return bool Whether the loaded environment resolves to test */
    public static function isAllowed(): bool
    {
        $env = Hilos::$env;
        if ($env === null) {
            return false;
        }

        try {
            return AppEnv::fromString($env[EnvConstants::APP_ENV]->string()) === AppEnv::TEST;
        } catch (EnvException) {
            return false;
        }
    }

    /**
     * @param string $value Code as entered
     * @return string Canonical code in the reserved fixture space
     * @throws EmptyValueException When the code is empty
     * @throws ValueTooLongException When the code exceeds its column width
     * @throws ValidationException When the code falls outside the fixture space
     */
    public static function code(string $value): string
    {
        $code = VoucherFields::code($value);
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new ValidationException('A voucher fixture code must be ZZE2EV followed by 24 hexadecimal digits');
        }

        return $code;
    }
}
