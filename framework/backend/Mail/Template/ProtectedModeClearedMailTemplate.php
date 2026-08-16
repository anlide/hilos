<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;
use Hilos\ProtectedMode\ProtectedModeWatchdog;

/**
 * Renders the all-clear for a freeze an alert was raised about (HIL-482).
 *
 * Sent once, and only to close an alarm that was actually raised: a freeze that never worried
 * anybody has nothing to be told about. It says how long the node was out and how much of that
 * time had nothing running behind it, because those two numbers are what an operator writes down
 * afterwards, and it ends by promising silence - the alert it closes was the one that repeated.
 *
 * "Lifted by" names the operator command rather than a param: a leader reaches inactive by exactly
 * one route, the disable behind `protected-mode:open`, and a value passed in could only ever repeat
 * that or be wrong ({@see ProtectedModeWatchdog}).
 */
final class ProtectedModeClearedMailTemplate implements MailTemplate
{
    /** Template param: id or hostname of the node that came back. */
    public const string PARAM_NODE_ID = 'nodeId';

    /** Template param: operation the freeze protected. */
    public const string PARAM_OPERATION = 'operation';

    /** Template param: when the freeze was lifted, rendered as a UTC timestamp. */
    public const string PARAM_LIFTED_AT = 'liftedAt';

    /** Template param: how long the freeze held in total, rendered for a human. */
    public const string PARAM_HELD_FOR = 'heldFor';

    /** Template param: how much of that time was spent under an alarm, rendered for a human. */
    public const string PARAM_STUCK_FOR = 'stuckFor';

    /**
     * @param array<string, mixed> $params Template params; every PARAM_* above is read
     * @param ?string $locale Target locale, ignored today (reserved for i18n)
     * @return EmailContent Rendered subject and text body
     * @throws MailTemplateParamMissingException When the params name no node
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        $nodeId = $params[self::PARAM_NODE_ID] ?? null;
        if (!is_scalar($nodeId) || (string)$nodeId === '') {
            throw new MailTemplateParamMissingException(
                'Protected mode cleared mail template needs a non-empty ' . self::PARAM_NODE_ID . ' param',
            );
        }

        $nodeId = (string)$nodeId;
        $indent = '    ';
        $body = "The freeze on {$nodeId} was lifted at " . self::text($params, self::PARAM_LIFTED_AT) . '.'
            . ' It had held for ' . self::text($params, self::PARAM_HELD_FOR) . ', '
            . self::text($params, self::PARAM_STUCK_FOR) . " of them with nothing running behind it.\n"
            . "\n"
            . $indent . 'Operation: ' . self::text($params, self::PARAM_OPERATION) . "\n"
            . $indent . "Lifted by: protected-mode:open\n"
            . "\n"
            . 'No further alerts will be sent for this freeze.';

        return new EmailContent("Node {$nodeId} is out of maintenance", $body);
    }

    /**
     * Reads one rendered param, tolerating an absent one rather than refusing to send.
     *
     * @param array<string, mixed> $params Template params
     * @param string $key Param to read
     * @return string Rendered value, or a placeholder when the param is absent
     */
    private static function text(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        return is_scalar($value) && (string)$value !== '' ? (string)$value : 'unknown';
    }
}
