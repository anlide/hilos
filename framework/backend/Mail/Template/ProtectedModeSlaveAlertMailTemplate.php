<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Cluster\NodeName;
use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/** Pure, database-free words for a missed slave barrier or an unacknowledged synchronization request. */
final class ProtectedModeSlaveAlertMailTemplate implements MailTemplate
{
    /** Template param: stable id of the slave concerned. */
    public const string PARAM_NODE_ID = 'nodeId';

    /** Template param: nullable readable name of the slave. */
    public const string PARAM_NODE_NAME = 'nodeName';

    /** Template param: operation protected by the freeze. */
    public const string PARAM_OPERATION = 'operation';

    /** Template param: requested protected-mode phase. */
    public const string PARAM_PHASE = 'phase';

    /** Template param: one of the two REASON_* values. */
    public const string PARAM_REASON = 'reason';

    /** Template param: acknowledgment timeout in seconds, without reading a clock here. */
    public const string PARAM_TIMEOUT_SECONDS = 'timeoutSeconds';

    public const string REASON_MISSED_BARRIER = 'missed-barrier';

    public const string REASON_SYNC_OVERDUE = 'sync-overdue';

    /**
     * @param array<string, mixed> $params The six PARAM_* fields; nodeName may be null
     * @param ?string $locale Target locale, ignored today (reserved for i18n)
     * @return EmailContent Plain-text warning without promises about data integrity or recovery
     * @throws MailTemplateParamMissingException When required text, a supported reason or a positive timeout is missing
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        $nodeLabel = NodeName::label(self::text($params, self::PARAM_NODE_ID), $params[self::PARAM_NODE_NAME] ?? null);
        $operation = self::text($params, self::PARAM_OPERATION);
        $phase = self::text($params, self::PARAM_PHASE);
        $reason = self::text($params, self::PARAM_REASON);
        $timeoutSeconds = $params[self::PARAM_TIMEOUT_SECONDS] ?? null;
        if (!is_int($timeoutSeconds) || $timeoutSeconds < 1) {
            throw new MailTemplateParamMissingException('Protected mode slave alert mail needs a positive timeoutSeconds param');
        }

        $problem = match ($reason) {
            self::REASON_MISSED_BARRIER => "The node {$nodeLabel} was not confirmed stopped before the operation was allowed to proceed.\n"
                . "Synchronization has now been requested. Current synchronization cannot establish whether\n"
                . "this node wrote during the earlier interval. Account for this unverified interval when\n"
                . 'checking the operation result. This warning does not establish data corruption.',
            self::REASON_SYNC_OVERDUE => "The node {$nodeLabel} has not acknowledged the requested protected-mode synchronization\n"
                . "to phase {$phase} within {$timeoutSeconds} seconds. This warning concerns that request,\n"
                . 'independently of progress reported by the operation initiator.',
            default => throw new MailTemplateParamMissingException('Protected mode slave alert mail needs a supported reason param'),
        };

        return new EmailContent(
            "Protected-mode warning for node {$nodeLabel}: {$reason}",
            $problem . "\n\nOperation: {$operation}\nPhase: {$phase}\nReason: {$reason}",
        );
    }

    /**
     * @param array<string, mixed> $params Template boundary params
     * @param string $key Required nonblank text field
     * @return string Supplied technical label
     * @throws MailTemplateParamMissingException When the required text is absent, blank or mistyped
     */
    private static function text(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new MailTemplateParamMissingException('Protected mode slave alert mail needs a non-empty ' . $key . ' param');
        }

        return $value;
    }
}
