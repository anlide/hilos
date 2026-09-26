<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Fs\ChatFsContext;
use Hilos\Fs\Context\FsContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the chat filesystem context (HIL-336).
 *
 * The files registry keeps its files where chat attachments are published, so that moving
 * attachments onto the registry (HIL-144) moves no byte and the web server keeps serving from
 * the same place. Two names for one directory is the whole promise, and a path computed twice
 * is where it would quietly break.
 */
final class ChatFsContextTest extends TestCase
{
    public function testTheFilesDirectoryIsThePublishedDirectory(): void
    {
        $context = new ChatFsContext();
        $context->configure();

        self::assertTrue($context->hasDirectory(FsContext::FILES));
        self::assertSame($context->published->getPath(), $context->files->getPath());
    }
}
