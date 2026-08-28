<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Runtime\State\Item\BotAgentStatus;
use Demo\Chat\Runtime\State\Item\ChatContext;
use Demo\Chat\Runtime\State\Item\ChatUserState;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * The three chat states that used to mint a default now read their row by contract.
 *
 * Each of them is written on one worker and read on another, so a field that did not
 * survive the trip used to arrive as `0`, `0.0` or `left` - a value nobody sent, told
 * apart from a real one by nothing. What is asserted here is the refusal that replaced
 * those defaults, and the two neighbours it must not be confused with: a nullable field
 * that is legitimately absent still reads as null, and a diff that does not carry a key
 * still leaves the field alone.
 */
final class ChatStateRowContractTest extends TestCase
{
    public function testABotStatusRowMissingARequiredFieldIsRefused(): void
    {
        $row = self::botRow();
        unset($row[BotAgentStatus::updatedAt]);

        $this->expectException(InvalidFormatException::class);
        BotAgentStatus::fromRow($row);
    }

    public function testABotStatusRowHoldingAFieldOfAnotherTypeIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        BotAgentStatus::fromRow(self::botRow([BotAgentStatus::botId => '7']));
    }

    public function testABotStatusDiffWithoutAKeyLeavesTheFieldAlone(): void
    {
        $status = BotAgentStatus::fromRow(self::botRow());

        $status->applyDiff([BotAgentStatus::updatedAt => 200]);

        $this->assertSame(BotAgentStatus::STATUS_JOINED, $status->status);
        $this->assertSame(200, $status->updatedAt);
    }

    public function testAUserStateRowMissingARequiredFieldIsRefused(): void
    {
        $row = self::userRow();
        unset($row[ChatUserState::lastOutboundSubmittedAt]);

        $this->expectException(InvalidFormatException::class);
        ChatUserState::fromRow($row);
    }

    public function testAUserStateRowReadsAWholeSubmitMomentBackAsAFloat(): void
    {
        $state = ChatUserState::fromRow(self::userRow([ChatUserState::lastOutboundSubmittedAt => 0]));

        $this->assertSame(0.0, $state->lastOutboundSubmittedAt);
    }

    public function testAUserStateDiffWithoutAKeyLeavesTheSubmitMomentAlone(): void
    {
        $state = ChatUserState::fromRow(self::userRow());

        $state->applyDiff([]);

        $this->assertSame(1500.5, $state->lastOutboundSubmittedAt);
    }

    public function testAChatContextRowWithoutATopicReadsItAsNone(): void
    {
        $row = self::contextRow();
        unset($row[ChatContext::topic], $row[ChatContext::summary]);

        $context = ChatContext::fromRow($row);

        $this->assertNull($context->topic);
        $this->assertNull($context->summary);
    }

    public function testAChatContextRowMissingItsConfidenceIsRefused(): void
    {
        $row = self::contextRow();
        unset($row[ChatContext::topicConfidence]);

        $this->expectException(InvalidFormatException::class);
        ChatContext::fromRow($row);
    }

    public function testAChatContextRowReadsAWholeConfidenceBackAsAFloat(): void
    {
        $context = ChatContext::fromRow(self::contextRow([ChatContext::topicConfidence => 0]));

        $this->assertSame(0.0, $context->topicConfidence);
    }

    public function testAChatContextRowHoldingATopicOfAnotherTypeIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        ChatContext::fromRow(self::contextRow([ChatContext::topic => 7]));
    }

    public function testAChatContextDiffTellsALeftAloneTopicFromAClearedOne(): void
    {
        $context = ChatContext::fromRow(self::contextRow());

        $context->applyDiff([ChatContext::topicConfidence => 0.9]);
        $this->assertSame('tables', $context->topic);

        $context->applyDiff([ChatContext::topic => null]);
        $this->assertNull($context->topic);
    }

    /**
     * @param array<string, mixed> $overrides Keys to replace in the well-formed row
     * @return array<string, mixed> Row a bot lifecycle status is built from
     */
    private static function botRow(array $overrides = []): array
    {
        return array_replace([
            BotAgentStatus::botId => 7,
            BotAgentStatus::status => BotAgentStatus::STATUS_JOINED,
            BotAgentStatus::updatedAt => 100,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides Keys to replace in the well-formed row
     * @return array<string, mixed> Row a per-user chat state is built from
     */
    private static function userRow(array $overrides = []): array
    {
        return array_replace([
            ChatUserState::userId => 3,
            ChatUserState::lastOutboundSubmittedAt => 1500.5,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides Keys to replace in the well-formed row
     * @return array<string, mixed> Row the shared chat context is built from
     */
    private static function contextRow(array $overrides = []): array
    {
        return array_replace([
            ChatContext::topic => 'tables',
            ChatContext::topicConfidence => 0.75,
            ChatContext::summary => 'two people compared table layouts',
        ], $overrides);
    }
}
