<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\CodeChannel;

use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\CodeChannel\CodeChannelRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the LSB loss on the code-channel registry accessor (HIL-855).
 *
 * The same defect HIL-275 fixed for the backup catalog, HIL-489 for the notification
 * registries and HIL-697 for the protected-mode stub: {@see Hilos::codeChannelRegistryClass()}
 * resolves `CODE_CHANNEL_REGISTRY` from a bare `Hilos::` call-site, so it would bind to
 * the abstract base and answer the framework's empty registry however the project declared
 * itself. Here that means the code a person needs to sign in leaves by no channel at all.
 *
 * Two cases, kept apart so a failure names its layer: the bare accessor, and the live
 * seam {@see AuthCodeAgent::resolveChannel()} that reads it. The seam matters because the
 * only other test driving the agent, the integration test of the probe/mint/send order,
 * legitimately overrides `resolveChannel()` with a channel of its own - so before this
 * file no test executed the seam, even though one looked like it did.
 */
final class CodeChannelRegistryLsbResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs in one process and the captured facade class is global state: a
        // neighbor that mounted its own facade and never unmounted it would turn the
        // "empty before" sanity below into an assertion about someone else's leftovers.
        Hilos::initBrowser();
        Hilos::resetBrowser();
    }

    protected function tearDown(): void
    {
        // Restore the captured facade class to the base default for later cases.
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testTheBareAccessorResolvesTheProjectRegistryAfterInit(): void
    {
        // Sanity: without a project facade captured, the base accessor sees the empty base.
        // An empty registry is a legitimate project state, so "empty before" has to be
        // pinned or "present after" proves nothing about where the channels came from.
        self::assertSame(CodeChannelRegistry::class, Hilos::codeChannelRegistryClass());
        self::assertSame([], Hilos::codeChannelRegistryClass()::all());
        self::assertNull(Hilos::codeChannelRegistryClass()::get('sms'));

        CodeChannelLsbTestHilos::initBrowser();

        // The bug: this bare `Hilos::` call used to bind to the abstract base.
        self::assertSame(CodeChannelLsbTestRegistry::class, Hilos::codeChannelRegistryClass());

        // The registry builds its descriptors anew on every call, so instances are compared
        // by class, never by identity.
        self::assertInstanceOf(CodeChannelLsbTestChannel::class, Hilos::codeChannelRegistryClass()::get('sms'));
        self::assertSame(
            ['sms', 'telegram'],
            array_keys(Hilos::codeChannelRegistryClass()::all()),
            'Registry order is what the surface draws in, and it proves the parent merge lost nothing',
        );
    }

    public function testTheCodeAgentResolvesTheProjectChannelThroughTheLiveSeam(): void
    {
        $agent = new CodeChannelLsbTestAgent();

        // Sanity: before the project facade is captured, the agent finds no channel.
        self::assertNull($agent->resolveRegisteredChannel('sms'));

        CodeChannelLsbTestHilos::initBrowser();

        self::assertInstanceOf(CodeChannelLsbTestChannel::class, $agent->resolveRegisteredChannel('sms'));
        self::assertNull(
            $agent->resolveRegisteredChannel('signal'),
            'A channel the project never registered must not resolve - this null is what refuses a crafted payload',
        );
    }
}

/**
 * Project facade fixture pointing the code-channel registry constant at its own subclass.
 */
final class CodeChannelLsbTestHilos extends Hilos
{
    protected const string CODE_CHANNEL_REGISTRY = CodeChannelLsbTestRegistry::class;

    /**
     * Creates a no-op DB context for the abstract facade contract.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new CodeChannelLsbTestDbContext();
    }
}

/**
 * No-op DB context so the abstract facade fixture is instantiable.
 */
final class CodeChannelLsbTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for the LSB resolution fixture.
     */
    public function configure(): void
    {
    }
}

/**
 * Project registry composing two channels onto the empty base, the way a real project does.
 */
final class CodeChannelLsbTestRegistry extends CodeChannelRegistry
{
    /**
     * @return array<string, CodeChannel> Channel descriptors keyed by name
     */
    protected static function channels(): array
    {
        return array_replace(parent::channels(), [
            'sms' => new CodeChannelLsbTestChannel('sms'),
            'telegram' => new CodeChannelLsbTestChannel('telegram'),
        ]);
    }
}

/**
 * A channel under a real project name but of a class the framework never ships, so
 * "the project's channel arrived" cannot be satisfied by a framework one.
 */
final class CodeChannelLsbTestChannel extends CodeChannel
{
    /**
     * @param string $name Registry name of this channel
     */
    public function __construct(private readonly string $name)
    {
    }

    /**
     * @return string The registry name this channel was built under
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @param string $type Verification type (see VerificationType)
     * @return bool True for the SMS-delivered types, as befits a phone channel
     */
    public function supportsType(string $type): bool
    {
        return VerificationType::isSms($type);
    }
}

/**
 * The agent with its registry lookup left intact and merely opened for the test.
 *
 * Deliberately NOT what the integration test's agent fixture does: that one overrides
 * `resolveChannel()` with a channel of its own, which is right for a test about the
 * probe/mint/send order and is exactly what hides the seam. Simplify this fixture into
 * that shape and both cases above keep passing while proving nothing.
 */
final class CodeChannelLsbTestAgent extends AuthCodeAgent
{
    /**
     * @param string $channel Channel name to resolve
     * @return ?CodeChannel What the agent's own lookup answers for that name
     */
    public function resolveRegisteredChannel(string $channel): ?CodeChannel
    {
        return $this->resolveChannel($channel);
    }
}
