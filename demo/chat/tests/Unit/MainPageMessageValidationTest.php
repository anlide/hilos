<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Page\DTO\MessageActionDTO;
use Demo\Chat\Pages\MainPage;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for main-page message validation before runtime-backed draft checks.
 */
final class MainPageMessageValidationTest extends TestCase
{
    public function testRejectsEmptyMessageWhenSessionIsMissing(): void
    {
        $this->expectException(ItemNotFoundForUpdateException::class);

        (new MainPage(new ChatAgent()))->onAction(
            'missing-ak',
            ChatSignalConstants::MESSAGE,
            new MessageActionDTO(''),
        );
    }

    public function testRejectsWhitespaceOnlyMessageWhenSessionIsMissing(): void
    {
        $this->expectException(ItemNotFoundForUpdateException::class);

        (new MainPage(new ChatAgent()))->onAction(
            'missing-ak',
            ChatSignalConstants::MESSAGE,
            new MessageActionDTO('   '),
        );
    }
}
