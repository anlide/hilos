<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Tests\Unit;

use Demo\ConcertTickets\Constants\ConcertTicketsEnvConstants;
use Demo\ConcertTickets\Environment\ConcertTicketsEnvCatalog;
use Demo\ConcertTickets\Fs\ConcertTicketsFsContext;
use Demo\ConcertTickets\Hilos;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the concert-tickets filesystem context (HIL-1240).
 *
 * Files, tmp and exports belong to the cluster; the analytics journal belongs to each node.
 */
final class ConcertTicketsFsContextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> Published path override and effective path
     */
    public static function publishedPathProvider(): iterable
    {
        yield 'default' => ['', dirname(__DIR__, 2) . '/data/files/published'];
        yield 'override' => ['/shared/concert-tickets-published', '/shared/concert-tickets-published'];
    }

    /**
     * @param string $override Published directory env value
     * @param string $publishedPath Expected effective published path
     */
    #[DataProvider('publishedPathProvider')]
    public function testSharedFilesAndExportsKeepTheirDeclaredPathsAndOwners(string $override, string $publishedPath): void
    {
        $previousEnv = Hilos::$env;
        $env = $this->getMockBuilder(EnvAccessor::class)
            ->setConstructorArgs([ConcertTicketsEnvCatalog::class])
            ->onlyMethods(['effectiveValueFor'])
            ->getMock();
        $env->expects(self::once())->method('effectiveValueFor')
            ->with(ConcertTicketsEnvConstants::CONCERT_TICKETS_FILES_PUBLISHED_DIR)->willReturn($override);
        Hilos::$env = $env;

        try {
            $context = new ConcertTicketsFsContext();
            $context->configure();

            self::assertSame([
                FsContext::FILES,
                FsContext::ANALYTICS_JOURNAL,
                FsContext::DATA_EXPORT,
            ], array_keys($context->getDirectories()));
            self::assertSame($publishedPath, $context->getDirectory(FsContext::FILES)->getPath());
            self::assertSame(dirname(__DIR__, 2) . '/data/files/tmp', $context->tmp->getPath());
            self::assertSame(DirectoryScope::CLUSTER, $context->tmp->getScope());
            self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::FILES)->getScope());
            self::assertSame(DirectoryScope::NODE, $context->getDirectory(FsContext::ANALYTICS_JOURNAL)->getScope());
            self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::DATA_EXPORT)->getScope());
            self::assertSame([], $context->declarationErrors());
        } finally {
            Hilos::$env = $previousEnv;
        }
    }

    public function testStatusAndLegalExportAreNotRegistered(): void
    {
        $context = new ConcertTicketsFsContext();
        $context->configure();

        self::assertFalse($context->hasDirectory(FsContext::STATUS));
        self::assertFalse($context->hasDirectory(FsContext::LEGAL_EXPORT));
    }
}
