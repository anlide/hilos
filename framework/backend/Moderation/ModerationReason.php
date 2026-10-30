<?php

declare(strict_types=1);

namespace Hilos\Moderation;

/**
 * Reason codes of a verdict that belong to no kind (HIL-1625).
 *
 * A refusal of the content names a reason its kind declares ({@see ModerationKind::$reasons}).
 * These codes say that content passed, that no verdict could be had, or that the local renderer
 * could not draw a requested image copy. The last two permit nothing and are never model reasons.
 */
final class ModerationReason
{
    /** The content passed. */
    public const string OK = 'ok';

    /** No verdict could be had; the content is neither allowed nor refused for what it is. */
    public const string SERVICE_UNAVAILABLE = 'service_unavailable';

    /** The local renderer could not draw the requested image copy; never a model reason. */
    public const string UNREADABLE = 'unreadable';
}
