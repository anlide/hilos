<?php

declare(strict_types=1);

namespace Hilos\Status;

/**
 * Wire keys of the test-only Status commands (HIL-1856).
 *
 * The CLI halves build the request and print the reply; the Status owner ({@see StatusAgent})
 * reads the request and answers, so both sides name the keys from here.
 */
final class StatusTestCommandConstants
{
    /** @var string Reply key: epoch milliseconds of the heartbeat the owner published */
    public const string FIELD_ALIVE_AT = 'aliveAt';

    /** @var string Request and reply key: public identity of a maintenance announcement */
    public const string FIELD_PUBLIC_ID = 'publicId';

    /** @var string Reply key: the announcement's new start in epoch milliseconds */
    public const string FIELD_STARTS_AT = 'startsAt';

    /** @var string Reply key: the announcement's version after the move */
    public const string FIELD_VERSION = 'version';
}
