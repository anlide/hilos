<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp;

use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
use Hilos\Auth\StepUp\DTO\StepUpOpeningReplyDTO;
use Hilos\Auth\StepUp\DTO\StepUpPasskeyAnswer;
use Hilos\Auth\StepUp\DTO\StepUpStartActionDTO;
use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two step-up actions and their opening reply (HIL-495).
 */
final class StepUpActionDTOTest extends TestCase
{
    public function testStartRoundTripKeepsTheOperation(): void
    {
        $dto = StepUpStartActionDTO::fromArray(['operation' => ' change_email ']);

        self::assertSame('change_email', $dto->operation);
        self::assertSame(['operation' => 'change_email'], $dto->toArray());
    }

    public function testConfirmRoundTripKeepsEveryProofField(): void
    {
        $payload = [
            'operation' => 'change_name',
            'method' => StepUpMethod::PASSKEY,
            'code' => '',
            'backupCode' => false,
            'password' => '',
            'passkey' => [
                'signedChallenge' => 'signed',
                'credentialId' => 'credential',
                'authenticatorData' => 'authenticator',
                'clientDataJson' => 'client',
                'signature' => 'signature',
                'userHandle' => null,
            ],
        ];

        self::assertSame($payload, StepUpConfirmActionDTO::fromArray($payload)->toArray());
    }

    public function testConfirmRequiresTheNullablePasskeyFieldToBePresent(): void
    {
        $this->expectException(InvalidFormatException::class);

        StepUpConfirmActionDTO::fromArray([
            'operation' => 'change_email',
            'method' => StepUpMethod::PASSWORD,
            'code' => '',
            'backupCode' => false,
            'password' => 'secret',
        ]);
    }

    public function testOpeningReplyOmitsAbsentMethodDetailsAndRoundTrips(): void
    {
        $reply = new StepUpOpeningReplyDTO(false, 'change your email');

        self::assertSame(
            ['required' => false, 'purpose' => 'change your email'],
            StepUpOpeningReplyDTO::fromArray($reply->toArray())->toArray(),
        );
    }

    public function testOpeningReplyCarriesPasskeyOptions(): void
    {
        $reply = new StepUpOpeningReplyDTO(
            required: true,
            purpose: 'change your name',
            method: StepUpMethod::PASSKEY,
            signedChallenge: 'signed',
            publicKeyOptions: ['challenge' => 'challenge'],
        );

        self::assertSame($reply->toArray(), StepUpOpeningReplyDTO::fromArray($reply->toArray())->toArray());
    }

    public function testPasskeyAnswerRejectsMissingAssertionFields(): void
    {
        $this->expectException(InvalidFormatException::class);

        StepUpPasskeyAnswer::fromArray(['credentialId' => 'credential']);
    }
}
