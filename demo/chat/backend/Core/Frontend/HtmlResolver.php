<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Frontend;

/**
 * Resolves request path and Accept-Language to HTML file path and HTTP status.
 */
class HtmlResolver
{
    private const LOCALES = ['en', 'ru'];
    private const DEFAULT_LOCALE = 'en';

    /** @var array<string, int> Map path prefix to HTTP status (e.g. admin -> 403) */
    private array $statusOverrides = [];

    /**
     * Resolve path and Accept-Language to file path and status.
     *
     * @param string $path Request path (e.g. /privacy)
     * @param string $acceptLanguage Accept-Language header value
     * @return array{path: string, locale: string, status: int}
     */
    public function resolve(string $path, string $acceptLanguage = ''): array
    {
        $path = trim($path, '/');
        $locale = $this->parseLocale($acceptLanguage);

        $status = 200;
        $resolvedPath = $path === '' ? 'index' : $path;

        if (isset($this->statusOverrides[$resolvedPath])) {
            $status = $this->statusOverrides[$resolvedPath];
        }

        return [
            'path' => $resolvedPath,
            'locale' => $locale,
            'status' => $status,
        ];
    }

    /**
     * @param string $path Path to use 403 for (e.g. admin)
     */
    public function setStatusOverride(string $path, int $status): void
    {
        $this->statusOverrides[$path] = $status;
    }

    private function parseLocale(string $acceptLanguage): string
    {
        if ($acceptLanguage === '') {
            return self::DEFAULT_LOCALE;
        }
        $parts = array_map('trim', explode(',', $acceptLanguage));
        foreach ($parts as $part) {
            $lang = explode(';', $part)[0];
            $code = strtolower(explode('-', $lang)[0]);
            if (in_array($code, self::LOCALES, true)) {
                return $code;
            }
        }
        return self::DEFAULT_LOCALE;
    }
}
