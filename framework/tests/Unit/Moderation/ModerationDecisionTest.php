<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Moderation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Moderation\ModerationDecision;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A model's answer read against the kind it was asked about (HIL-1625).
 *
 * Only two answers are verdicts: allowed with ok, refused with a reason the kind declares. An
 * answer of the wrong shape is a format failure, a well-formed answer that contradicts itself or
 * the kind is a refusal of a domain rule, and neither ever turns into a permission.
 */
final class ModerationDecisionTest extends TestCase
{
    public function testAnAllowedAnswerWithOkIsAPermission(): void
    {
        $decision = ModerationDecision::fromModelOutput('{"allow":true,"reason":"ok"}', self::kind());

        self::assertTrue($decision->allow);
        self::assertSame(ModerationReason::OK, $decision->reason);
    }

    public function testARefusalWithAReasonOfTheKindIsARefusal(): void
    {
        $decision = ModerationDecision::fromModelOutput('{"allow":false,"reason":"violence"}', self::kind());

        self::assertFalse($decision->allow);
        self::assertSame('violence', $decision->reason);
    }

    public function testTheObjectIsFoundInsideOtherText(): void
    {
        $decision = ModerationDecision::fromModelOutput("Verdict:\n{\"allow\":false,\"reason\":\"nudity\"}\nDone.", self::kind());

        self::assertFalse($decision->allow);
        self::assertSame('nudity', $decision->reason);
    }

    /** @return iterable<string, array{string}> Answers whose shape is not a verdict */
    public static function malformedAnswers(): iterable
    {
        yield 'no JSON at all' => ['The photo looks fine to me.'];
        yield 'an empty answer' => [''];
        yield 'a broken object' => ['{"allow":true,"reason":"ok"'];
        yield 'allow as a string' => ['{"allow":"true","reason":"ok"}'];
        yield 'allow missing' => ['{"reason":"ok"}'];
        yield 'allow null' => ['{"allow":null,"reason":"ok"}'];
        yield 'reason missing on a permission' => ['{"allow":true}'];
        yield 'reason missing on a refusal' => ['{"allow":false}'];
        yield 'reason as a number' => ['{"allow":false,"reason":1}'];
    }

    #[DataProvider('malformedAnswers')]
    public function testAnAnswerOfTheWrongShapeIsAFormatFailure(string $answer): void
    {
        $this->expectException(InvalidFormatException::class);

        ModerationDecision::fromModelOutput($answer, self::kind());
    }

    /** @return iterable<string, array{string}> Well-formed answers that are no verdict on this kind */
    public static function inconsistentAnswers(): iterable
    {
        yield 'allowed with a refusal reason' => ['{"allow":true,"reason":"nudity"}'];
        yield 'allowed with an empty reason' => ['{"allow":true,"reason":""}'];
        yield 'refused with ok' => ['{"allow":false,"reason":"ok"}'];
        yield 'refused with a reason of no kind' => ['{"allow":false,"reason":"spam"}'];
        yield 'refused with an empty reason' => ['{"allow":false,"reason":""}'];
        yield 'refused as unavailable' => ['{"allow":false,"reason":"service_unavailable"}'];
    }

    #[DataProvider('inconsistentAnswers')]
    public function testAnAnswerThatContradictsItselfOrTheKindIsRefused(string $answer): void
    {
        try {
            ModerationDecision::fromModelOutput($answer, self::kind());
        } catch (ValidationException $e) {
            self::assertSame(ValidationException::class, $e::class);

            return;
        }
        self::fail('An answer that is no verdict on the kind must be refused');
    }

    public function testUnavailableIsARefusalForNoContentReason(): void
    {
        $decision = ModerationDecision::unavailable();

        self::assertFalse($decision->allow);
        self::assertSame(ModerationReason::SERVICE_UNAVAILABLE, $decision->reason);
    }

    /**
     * @return ModerationKind An image kind with two refusal reasons
     */
    private static function kind(): ModerationKind
    {
        return new ModerationKind(
            'unit_photo',
            'Photo',
            ModerationInput::IMAGE,
            ['nudity', 'violence'],
            ['Allow ordinary photos.'],
            'unit_photo_recipient',
        );
    }
}
