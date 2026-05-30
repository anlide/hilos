<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guard tests for framework page subscription handler contracts.
 */
final class PageSubscriptionContractTest extends TestCase
{
    public function testBrowserPagesDoNotUseLegacyEmptySubscribePayloads(): void
    {
        $violations = [];
        foreach ($this->frameworkPageFiles() as $file) {
            $contents = file_get_contents($file->getPathname());
            if ($contents === false || !$this->usesLegacyEmptySubscribePayload($contents)) {
                continue;
            }

            $violations[] = $this->relativePath($file->getPathname());
        }

        $this->assertSame(
            [],
            $violations,
            "BROWSER pages must not send legacy empty subscribe payloads in onSubscribe(). "
                . "Remove the override or delegate to parent::onSubscribe():\n"
                . implode("\n", $violations),
        );
    }

    /**
     * Detects legacy subscribe handlers that ack with empty SignalData/BrowserPageSignalData.
     */
    private function usesLegacyEmptySubscribePayload(string $contents): bool
    {
        if (!str_contains($contents, 'public const array BROWSER')) {
            return false;
        }
        if (!str_contains($contents, 'function onSubscribe')) {
            return false;
        }

        return str_contains($contents, 'new SignalData()')
            || str_contains($contents, 'new BrowserPageSignalData()');
    }

    /**
     * Iterates framework backend page PHP files.
     *
     * @return iterable<SplFileInfo>
     */
    private function frameworkPageFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/backend/Pages'),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }

    /**
     * Converts an absolute test path to a repository-local display path.
     */
    private function relativePath(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
