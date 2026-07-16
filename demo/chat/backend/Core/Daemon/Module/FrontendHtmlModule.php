<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Daemon\Module;

use Demo\Chat\Core\Frontend\HtmlCache;
use Demo\Chat\Core\Frontend\HtmlResolver;
use Demo\Chat\Core\Socket\Server\FrontendHtmlServer;
use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Module\DaemonModule;
use Hilos\Environment\Exception\EnvException;

/**
 * Serves the built frontend HTML from dist. Active only when a built dist directory is
 * present, so a backend-only run (no frontend build) never binds the frontend-html port.
 */
final class FrontendHtmlModule implements DaemonModule
{
    /**
     * @param string $distPath Resolved frontend dist directory to serve from
     */
    public function __construct(
        private readonly string $distPath,
    ) {
    }

    /**
     * @return bool True when a built dist directory is present
     */
    public function isActive(): bool
    {
        return is_dir($this->distPath);
    }

    /**
     * Builds the frontend-html server over the dist directory and registers it.
     *
     * @param DaemonManager $daemon Daemon to register the frontend-html server on
     * @param DaemonContext $context Resolved path context (unused; dist path is held on the module)
     * @throws EnvException When a frontend-html env value cannot be read
     */
    public function register(DaemonManager $daemon, DaemonContext $context): void
    {
        $frontendHtmlServer = new FrontendHtmlServer(
            Hilos::$env[EnvConstants::FRONTEND_HTML_HOST],
            Hilos::$env->int(EnvConstants::FRONTEND_HTML_PORT),
            new HtmlResolver(),
            new HtmlCache($this->distPath),
        );
        $daemon->registerServer($frontendHtmlServer);
    }
}
