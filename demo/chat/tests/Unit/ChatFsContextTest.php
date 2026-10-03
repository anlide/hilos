<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Fs\ChatFsContext;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the chat filesystem context (HIL-336, HIL-144).
 *
 * The files registry keeps the chat's attachments where they were published before it, so the
 * attachments published then became registry rows without a byte moving and the web server
 * keeps serving from the same place. The chat's own quarantine and published names are gone
 * with the page upload: chunks are the uploads agent's, in tmp.
 */
final class ChatFsContextTest extends TestCase
{
    public function testTheFilesDirectoryKeepsThePathAttachmentsWerePublishedTo(): void
    {
        $context = new ChatFsContext();
        $context->configure();

        self::assertTrue($context->hasDirectory(FsContext::FILES));
        self::assertStringEndsWith('/chat_attachments/published', $context->files->getPath());
    }

    /**
     * Tmp and the analytics journal are the node's (HIL-1154), the files and the exports the
     * cluster's (HIL-1240, the acceptance exports HIL-1234), and the start accepts that.
     */
    public function testEveryDirectoryDeclaresItsOwner(): void
    {
        $context = new ChatFsContext();
        $context->configure();

        self::assertSame(DirectoryScope::NODE, $context->tmp->getScope());
        self::assertSame(
            [
                FsContext::FILES => DirectoryScope::CLUSTER,
                FsContext::DATA_EXPORT => DirectoryScope::CLUSTER,
                FsContext::LEGAL_EXPORT => DirectoryScope::CLUSTER,
                FsContext::ANALYTICS_JOURNAL => DirectoryScope::NODE,
            ],
            array_map(static fn(FsDirectory $directory): DirectoryScope => $directory->getScope(), $context->getDirectories()),
        );
        self::assertSame([], $context->declarationErrors());
    }
}
