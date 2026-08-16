<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\MagicLink;

use Hilos\Auth\MagicLink\MagicLinkUrl;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the clickable address a magic-link letter carries (HIL-417).
 *
 * The link is assembled once, before either deliverer sees it, so what these lock
 * is the only thing a recipient ever gets: the configured return screen with the
 * address and its token appended - whatever the screen's own shape, and whatever
 * characters the address holds.
 */
final class MagicLinkUrlTest extends TestCase
{
    public function testAppendsTheParamsToAPlainReturnScreen(): void
    {
        $url = MagicLinkUrl::build('https://app.example.com/auth/magic', 'user@example.com', 'abc123');

        self::assertSame('https://app.example.com/auth/magic?email=user%40example.com&token=abc123', $url);
    }

    public function testKeepsAQueryTheReturnScreenAlreadyCarries(): void
    {
        $url = MagicLinkUrl::build('https://app.example.com/?page=magic', 'user@example.com', 'abc123');

        self::assertSame('https://app.example.com/?page=magic&email=user%40example.com&token=abc123', $url);
    }

    public function testEscapesAnAddressWhoseCharactersWouldRewriteTheQuery(): void
    {
        $url = MagicLinkUrl::build('https://app.example.com/auth/magic', 'a+b&token=evil@example.com', 'abc123');

        self::assertSame(
            'https://app.example.com/auth/magic?email=a%2Bb%26token%3Devil%40example.com&token=abc123',
            $url,
        );
    }
}
