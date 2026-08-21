<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * Look-alikes BLOCKING-RESOLUTION has to stay silent on: gethostname(), which reads the
 * local name through uname(2) and never asks a resolver; the same names quoted in a
 * string or written in a comment, where they are text rather than calls; and a method
 * of an object that happens to be called gethostbyname.
 */
final class BlockingResolutionLookAlikes
{
    /**
     * @return string This node's own name, or a stand-in when it reports none
     */
    public function node(): string
    {
        $node = gethostname();

        return $node === false ? 'unknown' : $node;
    }

    /**
     * @param object $resolver Somebody else's object, whose method wears one of these names
     * @return array<int, mixed> Spellings that only look like a resolving call
     */
    public function lookAlikes(object $resolver): array
    {
        // gethostbyname($host) written in a comment is a mention, not a call
        return [
            'gethostbyname($host)',
            'dns_get_record',
            $resolver->gethostbyname('example.test'),
            self::checkdnsrr('example.test'),
        ];
    }

    /**
     * @param string $host Name this class answers about out of its own table
     * @return bool True when the name is one this fixture pretends to know
     */
    private static function checkdnsrr(string $host): bool
    {
        return $host !== '';
    }
}
