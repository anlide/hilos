<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

/**
 * SessionIdentityRef - one (type, identifier) pair naming a person (HIL-479).
 *
 * The only thing a session carried across a database replacement may be recognized by. The
 * numeric user id is deliberately absent: the same id in the archive of another installation
 * belongs to a different human being, while a proven email or phone number is the same person
 * wherever the row lands.
 */
final class SessionIdentityRef
{
    /**
     * @param string $type Identity type (see IdentityType)
     * @param string $identifier Normalized identifier for the type
     */
    public function __construct(
        public readonly string $type,
        public readonly string $identifier,
    ) {
    }
}
