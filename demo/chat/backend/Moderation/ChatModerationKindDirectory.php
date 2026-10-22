<?php

declare(strict_types=1);

namespace Demo\Chat\Moderation;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;

/**
 * Moderation kinds the chat demo adds to the framework directory (HIL-1623).
 */
final class ChatModerationKindDirectory extends ModerationKindDirectory
{
    /**
     * @return list<ModerationKind> Framework kinds followed by chat-owned kinds
     * @throws InvalidArgumentException When a kind declaration is malformed
     */
    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            new ModerationKind(
                ChatModerationKindKey::MESSAGE,
                'Chat message',
                ModerationInput::TEXT_WITH_FILES,
                ['insult', 'threat', 'hate_speech', 'sexual', 'spam'],
                [
                    'Default policy: allow benign messages.',
                    'Block only explicit insults, threats, hate speech, sexual content, and obvious spam.',
                    'If uncertain, return allow=true.',
                ],
                AgentType::CHAT,
            ),
            new ModerationKind(
                ChatModerationKindKey::MESSAGE_PICTURE,
                'Chat message picture',
                ModerationInput::IMAGE,
                ['nudity', 'violence', 'hate'],
                [
                    'Default policy: allow ordinary pictures, screenshots and drawings.',
                    'Block only nudity or sexual content, graphic violence, and hate symbols.',
                    'If uncertain, return allow=true.',
                ],
                AgentType::CHAT,
            ),
        ];
    }
}
