<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Core\Router;

/**
 * Deliberately broken sample: a `match` whose `default` arm hands back the empty
 * string names every unknown case "no name", which is the sentinel again. The
 * `switch` below spells `default` too and is silent, because its arm is a label.
 */
final class MatchDefault
{
    /**
     * @param string $signal Signal name as it arrived
     * @return string Whatever the mapping produced
     */
    public function group(string $signal): string
    {
        return match ($signal) {
            'chat_message' => 'chat',
            'chat_typing' => 'chat',
            default => '',
        };
    }

    /**
     * @param string $signal Signal name as it arrived
     * @return string Whatever the mapping produced
     */
    public function label(string $signal): string
    {
        switch ($signal) {
            case 'chat_message':
                return 'message';
            default:
                return 'unknown';
        }
    }
}
