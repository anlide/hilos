<?php

declare(strict_types=1);

namespace Hilos\Auth\Code;

use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * CodeSendTicket - the name of one send, and the only thing a transport learns about it
 * (HIL-826).
 *
 * The opaque handle that lets the mail subsystem report progress without learning anything
 * about auth. Whoever orders a code mints one, keeps it on the session's line
 * ({@see HilosCodeSendAttempt::ticket}) and puts it on the order; the transport hands it back
 * untouched with every step, and the owner of the line uses it to decide whether the step is
 * still about the send being watched.
 *
 * Reporting by RECIPIENT ADDRESS instead was the live alternative and was rejected: it would
 * need a rule for which letters count as codes, and it would hand one browser's progress to
 * every browser parked on the same address.
 *
 * NOT a secret. It never travels to a client, and it addresses nothing a browser could ask
 * for - the line is fetched by session, never by ticket. Sixteen hex characters is simply
 * enough that two live sends never collide, and the form is the toast key's for want of a
 * reason to invent a second one.
 */
final class CodeSendTicket
{
    /** @var int Byte length of a ticket; the ticket is these bytes in hex */
    private const int TICKET_RANDOM_BYTES = 8;

    /**
     * Mints the name of one send.
     *
     * Per SEND, not per session and not per address: that is what makes a stale report
     * recognizable, since a resend gets a name of its own and the report of the attempt it
     * replaced no longer matches anything.
     *
     * @return string Sixteen lowercase hex characters
     * @throws RandomException When the platform CSPRNG refuses
     */
    public static function mint(): string
    {
        return RandomHelper::secureHex(self::TICKET_RANDOM_BYTES);
    }
}
