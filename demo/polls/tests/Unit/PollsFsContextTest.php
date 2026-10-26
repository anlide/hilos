<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Unit;

use Demo\Polls\Constants\PollsEnvConstants;
use Demo\Polls\Environment\PollsEnvCatalog;
use Demo\Polls\Fs\PollsFsContext;
use Demo\Polls\Hilos;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the polls filesystem context (HIL-1240).
 *
 * Files, tmp and exports belong to the cluster; the analytics journal belongs to each node.
 */
final class PollsFsContextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> Published path override and effective path
     */
    public static function publishedPathProvider(): iterable
    {
        yield 'default' => ['', dirname(__DIR__, 2) . '/data/files/published'];
        yield 'override' => ['/shared/polls-published', '/shared/polls-published'];
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
            ->setConstructorArgs([PollsEnvCatalog::class])
            ->onlyMethods(['effectiveValueFor'])
            ->getMock();
        $env->expects(self::once())->method('effectiveValueFor')
            ->with(PollsEnvConstants::POLLS_FILES_PUBLISHED_DIR)->willReturn($override);
        Hilos::$env = $env;

        try {
            $context = new PollsFsContext();
            $context->configure();

            self::assertSame([
                FsContext::FILES,
                FsContext::ANALYTICS_JOURNAL,
                FsContext::DATA_EXPORT,
                FsContext::LEGAL_EXPORT,
                FsContext::STATUS,
            ], array_keys($context->getDirectories()));
            self::assertSame($publishedPath, $context->getDirectory(FsContext::FILES)->getPath());
            self::assertSame(dirname(__DIR__, 2) . '/data/files/tmp', $context->tmp->getPath());
            self::assertSame(DirectoryScope::CLUSTER, $context->tmp->getScope());
            self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::FILES)->getScope());
            self::assertSame(DirectoryScope::NODE, $context->getDirectory(FsContext::ANALYTICS_JOURNAL)->getScope());
            self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::DATA_EXPORT)->getScope());
            self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::LEGAL_EXPORT)->getScope());
            self::assertSame([], $context->declarationErrors());
        } finally {
            Hilos::$env = $previousEnv;
        }
    }

    public function testStatusUsesItsOwnSharedDataDirectory(): void
    {
        $context = new PollsFsContext();
        $context->configure();

        self::assertTrue($context->hasDirectory(FsContext::STATUS));
        self::assertSame(dirname(__DIR__, 2) . '/data/status', $context->status->getPath());
        self::assertSame(DirectoryScope::CLUSTER, $context->status->getScope());
        self::assertSame(dirname(__DIR__, 2) . '/data/status', $context->clusterDirectories()[FsContext::STATUS]);
    }
}
