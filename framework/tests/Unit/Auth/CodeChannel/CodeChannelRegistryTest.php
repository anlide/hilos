<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\CodeChannel;

use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelRegistry;
use Hilos\Auth\CodeChannel\SmsCodeChannel;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Database\Verification\VerificationType;
use PHPUnit\Framework\TestCase;

/**
 * The code-channel catalog and the defaults a channel inherits (HIL-492).
 *
 * What is pinned here is the shape a project composes its channels with, because
 * that shape is the whole feature: adding a channel must be a class plus a registry
 * line, so the registry has to answer for none, one and several channels without
 * anything else in the system knowing how many there are. The empty registry is a
 * real state, not a degenerate one - it is what a project that sends no phone codes
 * looks like.
 *
 * The defaults matter for the same reason: a channel that declares nothing must
 * still be usable, and what it then claims (phone identifiers, not primary, a
 * capitalized label) is a decision rather than an accident.
 */
final class CodeChannelRegistryTest extends TestCase
{
    public function testAnEmptyRegistryOffersNothingAndFindsNothing(): void
    {
        self::assertSame([], EmptyCodeChannelRegistry::all());
        self::assertNull(EmptyCodeChannelRegistry::get(SmsCodeChannel::NAME));
    }

    public function testOneChannelIsFoundByItsOwnNameAndOnlyByIt(): void
    {
        self::assertInstanceOf(SmsCodeChannel::class, SmsOnlyCodeChannelRegistry::get(SmsCodeChannel::NAME));
        self::assertNull(
            SmsOnlyCodeChannelRegistry::get('telegram'),
            'A channel the project never registered must not resolve',
        );
    }

    public function testSeveralChannelsKeepTheOrderTheProjectDeclaredThemIn(): void
    {
        self::assertSame(
            [SmsCodeChannel::NAME, TestChannelRegistryFakeChannel::NAME],
            array_keys(TwoCodeChannelRegistry::all()),
            'Registry order is what the surface draws in, so it is part of the contract',
        );
    }

    public function testAChannelDeclaringNothingServesPhonesAndIsNotPrimary(): void
    {
        $channel = new TestChannelRegistryFakeChannel();

        self::assertSame([IdentifierDetection::KIND_PHONE], $channel->identifierKinds());
        self::assertFalse($channel->isPrimary());
        self::assertSame('Fake', $channel->label());
    }

    public function testTheSmsChannelIsPrimaryAndCarriesItsOwnLabel(): void
    {
        $channel = new SmsCodeChannel();

        self::assertTrue($channel->isPrimary());
        self::assertSame('SMS', $channel->label(), 'SMS is an initialism, not a capitalized word');
        self::assertSame([IdentifierDetection::KIND_PHONE], $channel->identifierKinds());
    }

    public function testTheSmsChannelServesTheSmsTypesAndRefusesTheRest(): void
    {
        $channel = new SmsCodeChannel();

        self::assertTrue($channel->supportsType(VerificationType::SMS_LOGIN));
        self::assertTrue($channel->supportsType(VerificationType::SMS_ADD));
        self::assertFalse(
            $channel->supportsType(VerificationType::PASSWORD_RESET),
            'An email flow is not a phone channel-s business',
        );
    }

    public function testTheSmsChannelAnswersReachabilityOffTheNumberWithNoNetwork(): void
    {
        $channel = new SmsCodeChannel();

        self::assertNull($channel->probeRequest('+15551234567'), 'The SMS gateway has nothing to be asked');
        self::assertTrue($channel->reaches('+15551234567'));
        self::assertFalse($channel->reaches('not-a-number'));
    }
}

/**
 * The framework default: a project that registered no channels at all.
 */
final class EmptyCodeChannelRegistry extends CodeChannelRegistry
{
}

/**
 * The plain-SMS project: one channel, which is what the flow looked like before
 * channels existed.
 */
final class SmsOnlyCodeChannelRegistry extends CodeChannelRegistry
{
    /**
     * @return array<string, CodeChannel> Channel descriptors keyed by name
     */
    protected static function channels(): array
    {
        return array_replace(parent::channels(), [SmsCodeChannel::NAME => new SmsCodeChannel()]);
    }
}

/**
 * A project carrying two channels, declared in the order its surface draws them.
 */
final class TwoCodeChannelRegistry extends CodeChannelRegistry
{
    /**
     * @return array<string, CodeChannel> Channel descriptors keyed by name
     */
    protected static function channels(): array
    {
        return array_replace(parent::channels(), [
            SmsCodeChannel::NAME => new SmsCodeChannel(),
            TestChannelRegistryFakeChannel::NAME => new TestChannelRegistryFakeChannel(),
        ]);
    }
}

/**
 * A channel that declares the bare minimum, so the base class defaults are what
 * answer for it.
 */
final class TestChannelRegistryFakeChannel extends CodeChannel
{
    /** Registry key of this test channel. */
    public const string NAME = 'fake';

    /**
     * @return string The `fake` channel name
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param string $type Verification type (see VerificationType)
     * @return bool True for the SMS-delivered types
     */
    public function supportsType(string $type): bool
    {
        return VerificationType::isSms($type);
    }
}
