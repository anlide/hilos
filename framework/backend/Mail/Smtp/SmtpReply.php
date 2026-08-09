<?php

declare(strict_types=1);

namespace Hilos\Mail\Smtp;

/**
 * SmtpReply - one parsed SMTP server reply (HIL-197).
 *
 * An SMTP reply is a three-digit status code and its text, possibly spread over several
 * continuation lines (`250-CAP` ... `250 CAP`). {@see parse()} consumes exactly one
 * complete reply from the front of a read buffer, joining continuation lines, and returns
 * null while the buffer still holds only a partial reply — so the transport can call it
 * on every read and act only once a whole reply has arrived.
 */
final class SmtpReply
{
    /** Digits of the status code every reply line opens with (RFC 5321). */
    private const int CODE_LENGTH = 3;

    /** Offset of the character telling a continuation line from the last one. */
    private const int SEPARATOR_OFFSET = self::CODE_LENGTH;

    /** Offset the reply text starts at, past the code and its separator. */
    private const int TEXT_OFFSET = self::CODE_LENGTH + 1;

    /**
     * @param int $code Three-digit status code
     * @param string $text Reply text, continuation lines joined by "\n"
     */
    public function __construct(
        public readonly int $code,
        public readonly string $text,
    ) {
    }

    /**
     * Consumes one complete reply from the front of a read buffer.
     *
     * @param string $buffer Read buffer; the consumed reply is stripped from its front
     * @return ?self The parsed reply, or null while the buffer holds only a partial reply
     */
    public static function parse(string &$buffer): ?self
    {
        $lines = [];
        $offset = 0;
        while (true) {
            $eol = strpos($buffer, "\r\n", $offset);
            if ($eol === false) {
                return null;
            }

            $line = substr($buffer, $offset, $eol - $offset);
            $offset = $eol + 2;
            $code = (int)substr($line, 0, self::CODE_LENGTH);
            $lines[] = trim(substr($line, self::TEXT_OFFSET));

            if (strlen($line) <= self::CODE_LENGTH || $line[self::SEPARATOR_OFFSET] === ' ') {
                $buffer = substr($buffer, $offset);
                return new self($code, implode("\n", $lines));
            }
        }
    }
}
