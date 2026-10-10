<?php

declare(strict_types=1);

namespace Hilos\Core\Action;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageSignalRouter;

/**
 * Actions open in all three look-only states: an ADMIN-page viewer, a view-only takeover and a frozen account (HIL-1290).
 *
 * An action is open when its owner declares it reading or it belongs to the session's own
 * actions below. Each state's exits are its own and do not live here. The same question is
 * asked by {@see PageAccessGate::assertAction()}, {@see PageSignalRouter::assertActionAuthorized()}
 * and {@see PageSignalRouter::assertTakeover()}. Session actions inside a takeover leave no
 * impersonated_action journal line.
 */
final class LookOnlyActions
{
    /** @var list<string> The framework session's own actions, open in every look-only state. */
    public const array SESSION_ACTIONS = [
        HilosSignalConstants::HILOS_LOGOUT,
        HilosSignalConstants::HILOS_IMPERSONATE_STOP,
        HilosSignalConstants::HILOS_BROWSER_ERASE,
        HilosSignalConstants::HILOS_DISMISS_SESSION_ACK,
        HilosSignalConstants::HILOS_DISMISS_ACCOUNT_BLOCKED,
        HilosSignalConstants::HILOS_TOAST_DISMISS,
        HilosSignalConstants::HILOS_TOAST_EXPIRED,
        HilosSignalConstants::HILOS_TOAST_READING,
        HilosSignalConstants::HILOS_PROFILE_FLOW_CANCEL,
    ];

    /**
     * @param string $action Dispatched action name
     * @param list<string> $readingActions Reading actions declared by its owner
     * @return bool Whether the action is open in every look-only state
     */
    public static function opens(string $action, array $readingActions): bool
    {
        return in_array($action, $readingActions, true) || in_array($action, self::SESSION_ACTIONS, true);
    }
}
