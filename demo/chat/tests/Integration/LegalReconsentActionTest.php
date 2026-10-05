<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Auth\Library\DTO\LegalReconsentPreviewActionDTO;
use Hilos\Auth\Library\DTO\LegalReconsentPreviewReplyDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\HilosException;

/**
 * The administrator's preview of the "the terms have changed" screen over the chat's real catalog (HIL-500).
 *
 * The third terms revision says the demo's data may be wiped and is in force the day it is published,
 * so whoever held the second is past the deadline; the new Privacy revision declares analytics.
 */
final class LegalReconsentActionTest extends IntegrationTestCase
{
    /**
     * Bind the real chat catalog.
     *
     * @throws HilosException When the fixture cannot be prepared
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
    }

    /**
     * The terms show one changed clause - the demo's availability - to a holder of the second revision.
     *
     * @throws HilosException When the preview cannot be built
     */
    public function testTheTermsPreviewShowsTheDemoResetToAHolderOfTheSecondRevision(): void
    {
        $preview = $this->preview('terms');

        self::assertSame('lapsed', $preview->standing);
        self::assertSame('2026-10-01', $preview->deadline);
        self::assertSame('2026-09-27', $preview->held['revisionId'] ?? null);
        self::assertSame('2026-10-01', $preview->current['revisionId']);
        self::assertSame(
            [['standard.availability', 'changed', 'This is a demo: its data may be wiped at any time']],
            array_map(
                static fn (array $change): array => [$change['clauseKey'], $change['kind'], $change['after']['statement'] ?? null],
                $preview->changes,
            ),
        );
        self::assertCount(6, $preview->clauses);
    }

    /**
     * The preview shows what a holder of the earlier Privacy text must accept about analytics.
     *
     * @throws HilosException When the preview cannot be built
     */
    public function testThePrivacyPreviewNamesTheAnalyticsRevision(): void
    {
        $preview = $this->preview('privacy');

        self::assertSame('lapsed', $preview->standing);
        self::assertSame('2026-10-05', $preview->deadline);
        self::assertSame('2026-09-17', $preview->held['revisionId'] ?? null);
        self::assertSame('2026-10-05', $preview->current['revisionId']);
        self::assertSame([
            ['standard.deletion', 'changed', 'Account deletion leaves numbered analytics events and network addresses'],
            ['standard.access_log', 'changed', 'The separate access log is disabled; analytics records use and addresses'],
        ], array_map(
            static fn (array $change): array => [$change['clauseKey'], $change['kind'], $change['after']['statement'] ?? null],
            $preview->changes,
        ));
    }

    /**
     * @param string $document Document previewed
     * @return LegalReconsentPreviewReplyDTO The preview reply
     * @throws HilosException When the preview is refused
     */
    private function preview(string $document): LegalReconsentPreviewReplyDTO
    {
        $reply = $this->usersLibrary()->onAgentAction(
            'anonymous-reconsent-preview',
            HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW,
            new LegalReconsentPreviewActionDTO($document),
        );
        self::assertInstanceOf(LegalReconsentPreviewReplyDTO::class, $reply);

        return $reply;
    }
}
