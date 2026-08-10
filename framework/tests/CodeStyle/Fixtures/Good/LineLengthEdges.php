<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * Look-alikes LINE-LENGTH has to stay silent on: a line that stops exactly at the
 * limit, a line that stops there in columns while running past it in bytes, and a
 * line inside a heredoc or a nowdoc body, where a break would land in the string
 * itself.
 *
 * Both doc forms are seeded, even though PHP spells the opening of a nowdoc with
 * the same `T_START_HEREDOC` token: the rule promises the exemption for both, and
 * a promise the fixtures do not hold is a promise the next change can drop.
 */
final class LineLengthEdges
{
    /**
     * @return array<int, string> The four shapes a width check is not allowed to report
     */
    public function samples(): array
    {
        $exactlyAtTheLimit = 'a line that stops exactly at the hundred and fiftieth column, one character short of a hit, and is not reported at all';

        $dashesWeighThreeBytesEach = 'an en dash — the kind an English comment uses — weighs three bytes, so a byte count and a column count differ.';

        return [$exactlyAtTheLimit, $dashesWeighThreeBytesEach, $this->prompt(), $this->template()];
    }

    /**
     * @return string A prompt whose body line runs past the limit and is none of the rule's business
     */
    private function prompt(): string
    {
        return <<<PROMPT
            You are reviewing one file at a time. Answer with the single line that best describes what the change does, and never wrap that answer onto a second line.
            PROMPT;
    }

    /**
     * @return string A nowdoc whose body line runs past the limit and is exempt for the same reason
     */
    private function template(): string
    {
        return <<<'TEMPLATE'
            Dear $recipient, the report you asked for on $date is attached, and the figures in it cover every hour of the window you named rather than the working day alone.
            TEMPLATE;
    }
}
