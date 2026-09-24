<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

/**
 * The `otpauth://` address an authenticator app reads from a QR code (HIL-494).
 *
 * The de-facto format every authenticator app understands: the label names the issuer and
 * the account so the entry is recognizable in a list of many, and the parameters repeat
 * what {@see Totp} computes with, so an app that does not assume the defaults computes the
 * same codes. The issuer is one name for the whole installation - the project's name, the
 * same one a passkey is shown under.
 */
final class OtpAuthUri
{
    /** Scheme and type of the address. */
    private const string PREFIX = 'otpauth://totp/';

    /** Separator between the issuer and the account in the label. */
    private const string LABEL_SEPARATOR = ':';

    /** Name of the hash parameter's value, as the format spells it. */
    private const string ALGORITHM = 'SHA1';

    /** Query parameters of the format. */
    private const string PARAM_SECRET = 'secret';
    private const string PARAM_ISSUER = 'issuer';
    private const string PARAM_ALGORITHM = 'algorithm';
    private const string PARAM_DIGITS = 'digits';
    private const string PARAM_PERIOD = 'period';

    /**
     * Builds the address.
     *
     * @param string $issuer Installation name the entry is listed under
     * @param string $account The account's identifier - its address or phone number
     * @param string $secretBase32 Base32 secret
     * @return string The `otpauth://totp/...` address
     */
    public static function build(string $issuer, string $account, string $secretBase32): string
    {
        return self::PREFIX . rawurlencode($issuer) . self::LABEL_SEPARATOR . rawurlencode($account) . '?'
            . http_build_query([
                self::PARAM_SECRET => $secretBase32,
                self::PARAM_ISSUER => $issuer,
                self::PARAM_ALGORITHM => self::ALGORITHM,
                self::PARAM_DIGITS => Totp::DIGITS,
                self::PARAM_PERIOD => Totp::PERIOD_SEC,
            ], '', '&', PHP_QUERY_RFC3986);
    }
}
