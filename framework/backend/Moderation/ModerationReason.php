<?php

declare(strict_types=1);

namespace Hilos\Moderation;

/**
 * Reason codes of a verdict that belong to no kind (HIL-1625).
 *
 * A refusal of the content names a reason its kind declares ({@see ModerationKind::$reasons}). These
 * two are the moderator's own: one says the content passed, the other that no verdict could be
 * had - no model, no file, no rules, an answer that is not a verdict - which permits nothing and
 * says nothing about the content.
 */
final class ModerationReason
{
    /** The content passed. */
    public const string OK = 'ok';

    /** No verdict could be had; the content is neither allowed nor refused for what it is. */
    public const string SERVICE_UNAVAILABLE = 'service_unavailable';
}
