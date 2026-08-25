<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guard tests for browser-owned page subscription payload contracts.
 */
final class BrowserPageSubscriptionContractTest extends TestCase
{
    public function testBrowserPageSubscribeHandlersDoNotInstantiateLegacyChatEventDto(): void
    {
        $violations = [];
        foreach ($this->backendPageFiles() as $file) {
            $contents = file_get_contents($file->getPathname());
            if (
                $contents === false
                || !str_contains($contents, 'public const array BROWSER')
                || !$this->declaresSubscribeHook($contents)
                || !str_contains($contents, 'new ChatEventSignalDTO')
            ) {
                continue;
            }

            $violations[] = $this->relativePath($file->getPathname());
        }

        $this->assertSame(
            [],
            $violations,
            "BROWSER page subscribe handlers must not instantiate ChatEventSignalDTO:\n"
                . implode("\n", $violations),
        );
    }

    public function testBrowserPagesDoNotUseLegacyEmptySubscribePayloads(): void
    {
        $violations = [];
        foreach ($this->backendPageFiles() as $file) {
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
        if (!$this->declaresSubscribeHook($contents)) {
            return false;
        }

        return str_contains($contents, 'new SignalData()')
            || str_contains($contents, 'new BrowserPageSignalData()');
    }

    /**
     * Detects a page that puts work into either subscribe hook. AbstractPage::onSubscribe()
     * is final, so a page's subscribe work lives in one of these two and nowhere else.
     */
    private function declaresSubscribeHook(string $contents): bool
    {
        return str_contains($contents, 'function onSubscribeBeforeResponse(')
            || str_contains($contents, 'function onSubscribeAfterResponse(');
    }

    /**
     * Iterates chat demo backend page PHP files.
     *
     * @return iterable<SplFileInfo>
     */
    private function backendPageFiles(): iterable
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
