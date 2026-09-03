<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Hilos;

/**
 * Keys and entry fields of the protected-mode stub registry.
 *
 * An entry of {@see Hilos::PROTECTED_MODE_STUB} maps the operation name recorded on the
 * freeze row to the words that operation is announced with while it runs. There are two
 * audiences and therefore two kinds of field: {@see TITLE} and {@see MESSAGE} word the
 * maintenance surface a locked-out browser stands on, while {@see BANNER_MESSAGE} words the
 * banner an admitted one carries over the running application. The
 * registry is read on the daemon side when a protected-mode frame is composed, so a project
 * says what its users see by overriding the constant, not by touching the transport.
 * {@see DEFAULT_OPERATION} is the entry used when the running operation registered none of
 * its own — an operation that forgot to introduce itself still gets a stub with words on it.
 */
final class ProtectedModeStubConstants
{
    /** Stub entry field: heading of the maintenance surface. */
    public const string TITLE = 'title';

    /** Stub entry field: sentence shown under the heading. */
    public const string MESSAGE = 'message';

    /** Stub entry field: sentence the banner shows an admitted browser while the mode holds. */
    public const string BANNER_MESSAGE = 'bannerMessage';

    /** Registry key of the entry used when the running operation has none of its own. */
    public const string DEFAULT_OPERATION = 'default';
}
