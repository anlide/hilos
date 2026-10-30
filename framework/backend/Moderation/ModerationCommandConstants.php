<?php

declare(strict_types=1);

namespace Hilos\Moderation;

/** Read-only test fence carried by the ordinary command channel. */
final class ModerationCommandConstants
{
    public const string TEST_OBSERVE = 'test:moderation:observe';
    public const string checkRequestId = 'checkRequestId';
    public const string activeRequestId = 'activeRequestId';
    public const string waitingForUploadHold = 'waitingForUploadHold';
}
