<?php

declare(strict_types=1);

namespace Hilos\I18n;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;

/**
 * Says what the project's declaration of language addresses means and which language the
 * address of a page names (HIL-2313).
 *
 * The address of a page is its Origin, not its Host: Host names the socket, and a preview or a
 * VITE_WS_URL keeps the socket on a host of its own. An Origin is compared by its host name
 * alone - without the scheme and the port, in any case and with or without a final dot.
 *
 * The declaration is read anew on every call, with no memory in the process, as the default
 * language is ({@see DefaultLanguage}); changing it takes a restart. Whether the language of an
 * address is available is not this class's question: the language ladder decides it
 * ({@see TabLanguageLadder}).
 */
final class LanguageAddresses
{
    /** @var string Separator between the code=host pairs of HILOS_LANGUAGE_HOSTS */
    public const string PAIR_SEPARATOR = ',';

    /** @var string Separator between the language code and the host inside one pair */
    public const string CODE_HOST_SEPARATOR = '=';

    /** @var string Bare host name: dot-separated labels of letters, digits and inner hyphens, read in lower case */
    private const string HOST_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/D';

    /** @var int Longest host name a declaration accepts, in characters */
    private const int MAX_HOST_CHARS = 253;

    /** @var string Origin a browser sends from an opaque origin - a sandbox, a file:// page */
    private const string OPAQUE_ORIGIN = 'null';

    /** @var string Character a fully qualified host name may end with */
    private const string HOST_FINAL_DOT = '.';

    /**
     * @return array<string, string> Host of every declared language address, keyed by the exact built-in
     *     language code; empty when nothing is declared
     * @throws EnvException When the key is absent from the environment catalog
     * @throws EnvInvalidValueException When an element is not one code=host pair, its code is not a built-in one,
     *     its host is not a bare host name, or a code or a host is declared twice
     */
    public static function hosts(): array
    {
        $declaration = trim(Hilos::$env[EnvConstants::HILOS_LANGUAGE_HOSTS]->string());
        if ($declaration === '') {
            return [];
        }
        $hosts = [];
        foreach (explode(self::PAIR_SEPARATOR, $declaration) as $element) {
            $element = trim($element);
            if ($element === '') {
                continue;
            }
            $pair = explode(self::CODE_HOST_SEPARATOR, $element);
            if (count($pair) !== 2) {
                throw self::invalid('a malformed code=host pair', $element);
            }
            $code = trim($pair[0]);
            $host = strtolower(trim($pair[1]));
            if (BuiltInI18nCatalog::language($code) === null) {
                throw self::invalid('an unknown language code', $element);
            }
            if (strlen($host) > self::MAX_HOST_CHARS || preg_match(self::HOST_PATTERN, $host) !== 1) {
                throw self::invalid('a host that is not a bare host name', $element);
            }
            if (isset($hosts[$code])) {
                throw self::invalid('a second host for one language', $element);
            }
            if (in_array($host, $hosts, true)) {
                throw self::invalid('a second language for one host', $element);
            }
            $hosts[$code] = $host;
        }

        return $hosts;
    }

    /**
     * Refuses a facade that switches i18n on with a malformed declaration of language addresses.
     *
     * The daemon preflight asks this of the facade it is starting, right after the default
     * language, so the refusal comes before any tab is given a language; a facade without I18N is
     * not asked for the variable at all.
     *
     * @param class-string<Hilos> $hilosClass Facade class whose feature declaration decides
     * @throws EnvException When the key is absent from the environment catalog
     * @throws EnvInvalidValueException When the facade declares I18N and the declaration is malformed
     */
    public static function assertConfiguredFor(string $hilosClass): void
    {
        if (in_array(HilosFeature::I18N, Hilos::featuresOf($hilosClass), true)) {
            self::hosts();
        }
    }

    /**
     * The language whose address a page was opened on. A malformed header does not throw: it names no
     * address, and the page is then on the main one.
     *
     * @param ?string $origin Value of the page's Origin header; null when the browser sent none
     * @return ?string Exact built-in code of the language declared for the Origin's host; null when there is
     *     no Origin, it is opaque, it has no host or its host is not declared
     * @throws EnvException When the key is absent from the environment catalog
     * @throws EnvInvalidValueException When the declaration is malformed, which the daemon start has refused already
     */
    public static function languageOfOrigin(?string $origin): ?string
    {
        if ($origin === null || $origin === '' || $origin === self::OPAQUE_ORIGIN) {
            return null;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $code = array_search(rtrim(strtolower($host), self::HOST_FINAL_DOT), self::hosts(), true);

        return $code === false ? null : $code;
    }

    /**
     * @param string $problem What is wrong with the element, as the refusal names it
     * @param string $element The element of the declaration that is wrong
     * @return EnvInvalidValueException Refusal that names the variable and the element
     */
    private static function invalid(string $problem, string $element): EnvInvalidValueException
    {
        return new EnvInvalidValueException(
            "Environment variable '" . EnvConstants::HILOS_LANGUAGE_HOSTS->name . "' has {$problem} in '{$element}'",
        );
    }
}
