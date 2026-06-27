<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration coverage for published event attachment persistence and browser representation.
 */
final class EventAttachmentsTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testPublishConnectionDraftsMovesQuarantineFilesAndReturnsMetadata(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$fs->quarantine['draft-publish.txt']->unlink();
        Hilos::$fs->published['draft-publish.txt']->unlink();

        try {
            Hilos::$rt->connections->actions->register('publish-ak', 1);
            Hilos::$fs->quarantine->create('draft-publish.txt')->append('draft-body');
            Hilos::$rt->attachmentDrafts->actions->create(
                'draft-publish',
                'publish-ak',
                1,
                'draft-publish.txt',
                'Original.txt',
                'text/plain',
                10,
                'original.txt',
                time(),
            );

            $connection = Hilos::$rt->connections['publish-ak'];
            $this->assertNotNull($connection);
            $inputs = Hilos::$rt->attachmentDrafts->actions->publishForConnection($connection);

            $this->assertNotNull($inputs);
            $items = iterator_to_array($inputs);
            $this->assertCount(1, $items);
            $this->assertSame('Original.txt', $items[0]->filename);
            $this->assertSame('text/plain', $items[0]->mimeType);
            $this->assertSame('draft-publish.txt', $items[0]->storedName);
            $this->assertFalse(Hilos::$fs->quarantine['draft-publish.txt']->exists());
            $this->assertTrue(Hilos::$fs->published['draft-publish.txt']->exists());
            $this->assertSame('draft-body', Hilos::$fs->published['draft-publish.txt']->read());
            $this->assertSame(0, count(Hilos::$rt->attachmentDrafts->forAcceptKey('publish-ak')));
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
            Hilos::$fs->quarantine['draft-publish.txt']->unlink();
            Hilos::$fs->published['draft-publish.txt']->unlink();
        }
    }

    public function testPublishConnectionDraftsReturnsNullWhenQuarantineFileIsMissing(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
        Hilos::$fs->quarantine['draft-missing.txt']->unlink();
        Hilos::$fs->published['draft-missing.txt']->unlink();

        try {
            Hilos::$rt->connections->actions->register('publish-missing-ak', 1);
            Hilos::$rt->attachmentDrafts->actions->create(
                'draft-missing',
                'publish-missing-ak',
                1,
                'draft-missing.txt',
                'Missing.txt',
                'text/plain',
                12,
                'missing.txt',
                time(),
            );

            $connection = Hilos::$rt->connections['publish-missing-ak'];
            $this->assertNotNull($connection);
            $inputs = Hilos::$rt->attachmentDrafts->actions->publishForConnection($connection);

            $this->assertNull($inputs);
            $this->assertSame(0, count(Hilos::$rt->attachmentDrafts->forAcceptKey('publish-missing-ak')));
            $this->assertFalse(Hilos::$fs->published['draft-missing.txt']->exists());
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->attachmentDrafts->actions->clear(deleteFiles: false);
            Hilos::$fs->quarantine['draft-missing.txt']->unlink();
            Hilos::$fs->published['draft-missing.txt']->unlink();
        }
    }

    /**
     * Finds a browser row by a source field.
     *
     * @param list<array<string, mixed>> $rows Browser rows
     * @param string $sourceKey Browser source key
     * @param string $field Source field name
     * @param mixed $value Expected source field value
     * @return ?array<string, mixed> Matching browser row, or null
     */
    private function findBrowserRowBySourceField(array $rows, string $sourceKey, string $field, mixed $value): ?array
    {
        foreach ($rows as $row) {
            $source = $row[BrowserPageSignalData::sources][$sourceKey] ?? null;
            if (is_array($source) && ($source[$field] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }
}
