<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Users\ProfileNameRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Only the six declared content refusals may describe a rejected name or notify its owner. */
final class ProfileNameRefusalTest extends TestCase
{
    /**
     * @param string $reason Declared content reason
     * @param string $expected Existing public refusal sentence
     */
    #[DataProvider('contentReasons')]
    public function testDeclaredReasonsKeepTheirPublicWordsAndNotify(string $reason, string $expected): void
    {
        self::assertSame($expected, ProfileNameRefusal::forReason($reason));
        self::assertTrue(ProfileNameRefusal::isAboutTheName($reason));
    }

    /** @return iterable<string, array{string, string}> All declared name refusal reasons */
    public static function contentReasons(): iterable
    {
        yield 'insult' => ['insult', 'This name was not accepted because it appears to contain an insult.'];
        yield 'threat' => ['threat', 'This name was not accepted because it appears to contain a threat.'];
        yield 'hate speech' => ['hate_speech', 'This name was not accepted because it appears to contain hate speech.'];
        yield 'sexual content' => ['sexual', 'This name was not accepted because it appears to contain sexual content.'];
        yield 'spam' => ['spam', 'This name was not accepted because it appears to be spam.'];
        yield 'impersonation' => ['impersonation', 'This name was not accepted because it appears to impersonate someone else.'];
    }

    /** @param string $reason Unavailable or unrecognized reason, including provider prose */
    #[DataProvider('unavailableReasons')]
    public function testUnavailableAndUnknownReasonsExposeNoProviderWordsAndDoNotNotify(string $reason): void
    {
        self::assertSame('Names cannot be checked right now.', ProfileNameRefusal::forReason($reason));
        self::assertFalse(ProfileNameRefusal::isAboutTheName($reason));
    }

    /** @return iterable<string, array{string}> Outcomes which carry no content verdict */
    public static function unavailableReasons(): iterable
    {
        yield 'unavailable' => ['service_unavailable'];
        yield 'unknown' => ['unknown'];
        yield 'unknown code' => ['provider_internal_error'];
        yield 'provider prose' => ['This name violates an undisclosed provider policy.'];
        yield 'empty' => [''];
        yield 'old generic refusal' => ['blocked'];
        yield 'old policy refusal' => ['policy'];
        yield 'old unknown label' => ['unknown_label'];
        yield 'blank' => ['   '];
        yield 'old natural language reason' => ['contains copyrighted brand'];
        yield 'old reason with underscore' => ['contains bad_words'];
        yield 'multiline reason' => ["multiline\nreason text"];
        yield 'allowed reason is not a refusal' => ['ok'];
        yield 'wrong case' => ['SPAM'];
        yield 'reason with whitespace' => [' spam '];
    }
}
