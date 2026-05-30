<?php

declare(strict_types=1);

namespace Hilos\Core\Topology;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Computes page-owned signal route maps from registered page classes.
 */
final class PageSignalRouteRegistry
{
    /**
     * Returns page-owned non-action signal routes declared by page classes.
     *
     * @param array $pages Page registry
     * @return array<string, string|array<string, string>> Page route keyed by signal type, then signal name
     */
    public static function routes(array $pages): array
    {
        $signalRoutes = [];
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            foreach ($pageClass::SIGNALS as $signalType => $signalNames) {
                if (!is_string($signalType) || $signalType === '' || !is_array($signalNames)) {
                    continue;
                }

                if ($signalNames === []) {
                    if (!array_key_exists($signalType, $signalRoutes)) {
                        $signalRoutes[$signalType] = $page;
                    }
                    continue;
                }

                if (isset($signalRoutes[$signalType]) && is_string($signalRoutes[$signalType])) {
                    continue;
                }

                if (!isset($signalRoutes[$signalType])) {
                    $signalRoutes[$signalType] = [];
                }

                foreach ($signalNames as $key => $entry) {
                    $signalName = self::resolveRouteName($key, $entry);
                    if ($signalName === null) {
                        continue;
                    }

                    $signalRoutes[$signalType][$signalName] = $page;
                }
            }
        }

        return $signalRoutes;
    }

    /**
     * Returns page-owned named signal inner payload DTO classes.
     *
     * @param array $pages Page registry
     * @return array<string, array<string, class-string<SignalDataInterface>>> DTO class keyed by signal type, then signal name
     */
    public static function dtoRoutes(array $pages): array
    {
        $dtoRoutes = [];
        foreach ($pages as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            foreach ($pageClass::SIGNALS as $signalType => $signalNames) {
                if (!is_string($signalType) || $signalType === '' || !is_array($signalNames) || $signalNames === []) {
                    continue;
                }

                foreach ($signalNames as $key => $entry) {
                    if (!is_string($key) || $key === '' || !is_string($entry) || $entry === '') {
                        continue;
                    }

                    if (!isset($dtoRoutes[$signalType])) {
                        $dtoRoutes[$signalType] = [];
                    }

                    $dtoRoutes[$signalType][$key] = $entry;
                }
            }
        }

        return $dtoRoutes;
    }

    /**
     * Resolves a named page signal route entry to its signal name.
     *
     * @param int|string $key SIGNALS entry key
     * @param mixed $entry SIGNALS entry value
     * @return ?string Signal name when the entry declares a named route
     */
    public static function resolveRouteName(int|string $key, mixed $entry): ?string
    {
        if (is_int($key)) {
            return is_string($entry) && $entry !== '' ? $entry : null;
        }

        if (is_string($key) && $key !== '') {
            return $key;
        }

        return null;
    }
}
