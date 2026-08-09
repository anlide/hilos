<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Runtime\State\Item\HilosConnection;
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
        $connection = Connection::fromRow([
            HilosConnection::acceptKey => 'ak-1',
            Connection::outboundModerationPhase => ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Connection::outboundModerationMessage => '',
            Connection::outboundModerationUpdatedAt => 100,
        ]);

        $this->assertSame('', $connection->outboundModerationMessage);
    }

    public function testAnAttachmentOnlySubmitKeepsItsEmptyTextInADiff(): void
    {
        $connection = Connection::fromRow([HilosConnection::acceptKey => 'ak-1']);

        $connection->applyDiff([
            Connection::outboundModerationPhase => ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Connection::outboundModerationMessage => '',
            Connection::outboundModerationUpdatedAt => 100,
        ]);

        $this->assertSame('', $connection->outboundModerationMessage);
    }

    public function testARowWithNoMessageAtAllReadsAsNone(): void
    {
        $connection = Connection::fromRow([HilosConnection::acceptKey => 'ak-1']);

        $this->assertNull($connection->outboundModerationMessage);
    }

    public function testAnEmptyModerationReasonStillReadsAsNone(): void
    {
        $connection = Connection::fromRow([
            HilosConnection::acceptKey => 'ak-1',
            Connection::outboundModerationReason => '',
        ]);

        $this->assertNull($connection->outboundModerationReason);
    }
}
