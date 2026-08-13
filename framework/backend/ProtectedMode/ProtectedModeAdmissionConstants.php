<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * The one name the pass travels under, shared by the browser and the master.
 *
 * Admission is decided on the 101, in the same place and by the same rule as the initiator's
 * own exemption, so the key rides the socket url rather than a frame: while the mode holds the
 * frontend is refused every outbound frame (HIL-268), and a connection that cannot speak cannot
 * ask to be let in. That makes this string a cross-layer name - the frontend appends it, the
 * master reads it - and it lives here so neither side spells it on its own.
 *
 * What travels is the clear key; what is stored is only its hash
 * ({@see ProtectedModeRuntime::$passHashes}).
 */
final class ProtectedModeAdmissionConstants
{
    /** Query parameter of the upgrade request carrying a verifier's pass. */
    public const string HILOS_PASS_QUERY_PARAM = 'hilosPass';

    /** Hash algorithm the pass is stored and compared under. */
    public const string PASS_HASH_ALGO = 'sha256';
}
