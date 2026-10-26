<?php

declare(strict_types=1);

namespace Hilos\Mail\Exception;

/** Invalid project or legacy settings HTML, with no template data in its message. */
final class MailLetterFrameInvalidException extends MailException
{
    public const string REASON = 'Letter frame is invalid.';

    /** Always exposes the same domain sentence, without the frame or message content. */
    public function __construct()
    {
        parent::__construct(self::REASON);
    }
}
