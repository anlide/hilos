<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Tables;

/**
 * Negative sample inside the checked zone: every construct below puts an empty
 * string next to an arrow or a colon, or spells a `?` of its own, without minting
 * a missing value — and the rule has to stay silent on all of them.
 *
 * An array element writes the empty string down as data, a named argument passes
 * it on, a return type and the alternative syntax spell a colon of their own, a
 * nullable type declaration spells the question mark, a ternary inside a
 * concatenation renders a real fragment, and a comparison only reads the value it
 * is given.
 */
final class SecondFormLookAlikes
{
    private ?string $seed = null;

    /**
     * @param string $suffix Fragment appended when the caller has one
     * @return array<string, string> Whatever the arrangement produced
     */
    public function arrange(string $suffix): array
    {
        $defaults = ['title' => '', 'subtitle' => ''];
        $rendered = $this->render(prefix: '', suffix: $suffix);

        if ($rendered === ''):
            return $defaults;
        endif;

        return ['title' => $rendered] + $defaults;
    }

    /**
     * @param ?string $title Title as the row carried it, null when the row had none
     * @return ?string Same title without the padding, still null when there was none
     */
    public function trimmed(?string $title): ?string
    {
        return $title === null ? null : trim($title);
    }

    /**
     * @param string $prefix Fragment placed in front
     * @param string $suffix Fragment placed behind
     * @return string Both fragments, joined
     */
    private function render(string $prefix, string $suffix): string
    {
        return $prefix . ($suffix !== '' ? $suffix : $this->fallback());
    }

    /**
     * @return string Fragment used when the caller passed none
     */
    private function fallback(): string
    {
        return $this->seed ?? 'none';
    }
}
