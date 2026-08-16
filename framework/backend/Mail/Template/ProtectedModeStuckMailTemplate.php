<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;
use Hilos\ProtectedMode\ProtectedModeWatchdog;

/**
 * Renders the alert about a node frozen with nothing happening behind it (HIL-482).
 *
 * The wording is the one approved with real content, and it is deliberately plain: the person
 * reading it is looking at a phone at 3am and needs three things in this order - that a node is
 * serving nobody, why nothing is going to fix that by itself, and the two commands that end it.
 * Everything the body states as fact is passed in already rendered by {@see ProtectedModeWatchdog}
 * and its notifier; nothing here computes, reads a clock or reaches for a database, because the
 * database is exactly what may be broken when this is sent.
 *
 * The same body serves the first alert and every reminder after it. Only the subject differs, and
 * only by saying "still stuck" with the elapsed time in it - a reminder that changed its body would
 * read as a second incident rather than the same one continuing.
 */
final class ProtectedModeStuckMailTemplate implements MailTemplate
{
    /** Template param: id or hostname of the frozen node. */
    public const string PARAM_NODE_ID = 'nodeId';

    /** Template param: operation the freeze protects. */
    public const string PARAM_OPERATION = 'operation';

    /** Template param: phase the freeze stands on. */
    public const string PARAM_PHASE = 'phase';

    /** Template param: who froze the node, rendered as "<agent> agent on <node>". */
    public const string PARAM_INITIATOR = 'initiator';

    /** Template param: the one clause saying what is wrong, chosen by the verdict. */
    public const string PARAM_PROBLEM = 'problem';

    /** Template param: when the freeze began, rendered as a UTC timestamp. */
    public const string PARAM_FROZEN_SINCE = 'frozenSince';

    /** Template param: how long the freeze has held, rendered for a human. */
    public const string PARAM_FROZEN_FOR = 'frozenFor';

    /** Template param: how often this message repeats, rendered for a human. */
    public const string PARAM_REPEAT_EVERY = 'repeatEvery';

    /** Template param: whether this is a reminder rather than the first alert. */
    public const string PARAM_STILL = 'still';

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
                'Protected mode stuck mail template needs a non-empty ' . self::PARAM_NODE_ID . ' param',
            );
        }

        $nodeId = (string)$nodeId;
        $frozenFor = self::text($params, self::PARAM_FROZEN_FOR);
        $subject = ($params[self::PARAM_STILL] ?? false) === true
            ? "Node {$nodeId} is still stuck in maintenance ({$frozenFor})"
            : "Node {$nodeId} is stuck in maintenance";

        return new EmailContent($subject, self::body($params, $nodeId, $frozenFor));
    }

    /**
     * Builds the body both the first alert and every reminder carry.
     *
     * @param array<string, mixed> $params Template params
     * @param string $nodeId Id or hostname of the frozen node
     * @param string $frozenFor How long the freeze has held, rendered for a human
     * @return string Plain-text body
     */
    private static function body(array $params, string $nodeId, string $frozenFor): string
    {
        $indent = '    ';

        return "The node {$nodeId} has been frozen for maintenance since "
            . self::text($params, self::PARAM_FROZEN_SINCE) . " ({$frozenFor})"
            . " and it will not come back on its own.\n"
            . "\n"
            . $indent . 'Operation: ' . self::text($params, self::PARAM_OPERATION) . "\n"
            . $indent . 'Phase:     ' . self::text($params, self::PARAM_PHASE) . "\n"
            . $indent . 'Initiator: ' . self::text($params, self::PARAM_INITIATOR) . "\n"
            . $indent . 'Problem:   ' . self::text($params, self::PARAM_PROBLEM) . "\n"
            . "\n"
            . "While the freeze holds, nobody is served by this node. It will not be lifted\n"
            . "automatically: the data here may be half-written, and that decision belongs to\n"
            . "a person.\n"
            . "\n"
            . $indent . "php cli.php protected-mode:pass   - mint a one-time pass and look at the node yourself first\n"
            . $indent . "php cli.php protected-mode:open   - lift the freeze and let everyone back in\n"
            . "\n"
            . 'This message repeats every ' . self::text($params, self::PARAM_REPEAT_EVERY)
            . ' until the freeze is lifted.';
    }

    /**
     * Reads one rendered param, tolerating an absent one rather than refusing to send.
     *
     * Only the node is required ({@see render()}): a body missing one line still tells the operator
     * that a node is frozen and how to end it, while a refusal to render would leave the one alert
     * about a system nobody can reach unsent over a missing word.
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
