<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/** Test:* boundary, injected by the same master that owns the production pool. */
interface UploadMemoryTestDoor
{
    /**
     * @param CommandRequestDTO $request Request already admitted by the server environment gate
     * @return CommandReplyDTO One exact four-field snapshot or a refusal
     */
    public function answerUploadPoolTest(CommandRequestDTO $request): CommandReplyDTO;
}
