<?php

declare(strict_types=1);

namespace Demo\Chat\Auth;

use Hilos\Auth\StepUp\StepUpOperation;
use Hilos\Auth\StepUp\StepUpOperationDirectory;
use Hilos\Core\Exception\InvalidArgumentException;

/**
 * Protected operations the chat demo adds to the framework directory (HIL-495).
 */
final class ChatStepUpOperationDirectory extends StepUpOperationDirectory
{
    /**
     * @return array<string, StepUpOperation> Framework operations followed by chat-owned operations
     * @throws InvalidArgumentException When an operation key is malformed
     */
    protected static function operations(): array
    {
        return [
            ...parent::operations(),
            ChatStepUpOperationKey::CHANGE_NAME => new StepUpOperation(
                ChatStepUpOperationKey::CHANGE_NAME,
                'Change name',
                'change your name',
                false,
            ),
        ];
    }
}
