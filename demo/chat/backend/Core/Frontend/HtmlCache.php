<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Frontend;

/**
 * In-memory cache for prerendered HTML files.
 *
 * Loads files from dist directory on first access.
 */
class HtmlCache
{
    /** @var string Default locale fallback */
    private const string DEFAULT_LOCALE = 'en';

    /** @var array<string, string> path+locale -> content */
    private array $cache = [];

    /**
     * Create HTML cache with dist directory path.
     *
     * @param string $distPath Path to dist directory containing prerendered HTML
     */
    public function __construct(
        private string $distPath
    ) {
    }

    /**
     * Get HTML content for path and locale.
     *
     * @param string $path Path (e.g. 'index', 'privacy')
     * @param string $locale Locale code (e.g. 'en', 'ru')
     * @return ?string HTML content or null if file not found
     */
    public function get(string $path, string $locale): ?string
    {
        $key = "{$path}:{$locale}";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $filePath = ($path === 'index' || $path === '')
            ? $this->distPath . '/index.' . $locale . '.html'
            : $this->distPath . '/' . $path . '/index.' . $locale . '.html';

        if (!is_readable($filePath) && $locale !== self::DEFAULT_LOCALE) {
            $filePath = ($path === 'index' || $path === '')
                ? $this->distPath . '/index.' . self::DEFAULT_LOCALE . '.html'
                : $this->distPath . '/' . $path . '/index.' . self::DEFAULT_LOCALE . '.html';
        }

        if (!is_readable($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $this->cache[$key] = $content;
        return $content;
    }

    /**
     * Preload HTML files into cache (optional optimization).
     *
     * @param list<string> $paths Paths to preload (e.g. ['', 'privacy', '404'])
     */
    public function preload(array $paths): void
    {
        foreach ($paths as $path) {
            foreach (['en', 'ru'] as $locale) {
                $this->get($path ?: 'index', $locale);
            }
        }
    }
}
