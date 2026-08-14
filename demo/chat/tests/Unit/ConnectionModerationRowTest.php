<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Runtime\State\Item\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the moderation fields of a connection row crossing workers.
 *
 * The submit is authored in the page's worker and moderated in the moderator
 * agent's, so the moderation state only ever reaches the moderator as a row or a
 * diff. A message sent with attachments and no text carries an empty text on
 * purpose: read as "no message", it would make the moderator skip the connection
 * and leave its phase checking forever, so every later message would be refused
 * as one already under moderation. The reason beside it is the opposite case —
 * nothing writes an empty reason, so an empty one is a row from a node that
 * spells "none" the old way.
 */
final class ConnectionModerationRowTest extends TestCase
{
    public function testAnAttachmentOnlySubmitKeepsItsEmptyTextInARow(): void
    {
        $connection = Connection::fromRow(self::row([
            Connection::outboundModerationPhase => ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Connection::outboundModerationMessage => '',
            Connection::outboundModerationUpdatedAt => 100,
        ]));

        $this->assertSame('', $connection->outboundModerationMessage);
    }

    public function testAnAttachmentOnlySubmitKeepsItsEmptyTextInADiff(): void
    {
        $connection = Connection::fromRow(self::row([]));

        $connection->applyDiff([
            Connection::outboundModerationPhase => ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Connection::outboundModerationMessage => '',
            Connection::outboundModerationUpdatedAt => 100,
        ]);

        $this->assertSame('', $connection->outboundModerationMessage);
    }

    public function testARowCarryingNoMessageReadsAsNone(): void
    {
        $connection = Connection::fromRow(self::row([Connection::outboundModerationMessage => null]));

        $this->assertNull($connection->outboundModerationMessage);
    }

    public function testAnEmptyModerationReasonStillReadsAsNone(): void
    {
        $connection = Connection::fromRow(self::row([Connection::outboundModerationReason => '']));

        $this->assertNull($connection->outboundModerationReason);
    }

    /**
     * Builds the row the way a worker does — a whole serialized connection — and
     * then overrides the moderation fields under test. A row is written by
     * {@see Connection::toArray()} on the other side of the hop and never carries
     * a subset of the fields, so a handmade partial row would be testing a shape
     * that does not cross the wire.
     *
     * @param array<string, mixed> $moderation Moderation fields to override
     * @return array<string, mixed> Complete serialized connection row
     */
    private static function row(array $moderation): array
    {
        return array_merge(Connection::create('ak-1', null)->toArray(), $moderation);
    }
}
