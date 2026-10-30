<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\OAuth;

use Hilos\Auth\Library\DTO\OAuthLoginReadySignalData;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Auth\OAuth\OAuthProviderRegistry;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Provider facts survive the sender and IPC independently of project account naming. */
final class OAuthLoginReadySignalDataTest extends TestCase
{
    /** @return array<string, array{?string, string}> Provider name and independently expected project name */
    public static function providerNames(): array
    {
        return ['withheld' => [null, 'oauth:github:42'], 'reported' => ['李 | Łukasz 👩‍💻', 'Project Account']];
    }

    /**
     * @param ?string $providerName Provider fact
     * @param string $displayName Expected project name or framework fallback
     */
    #[DataProvider('providerNames')]
    public function testSenderAndRoundTripKeepProviderNameSeparate(?string $providerName, string $displayName): void
    {
        $agent = new class extends AbstractOAuthAgent {
            /** @var ?OAuthLoginReadySignalData Last sent completion */
            public ?OAuthLoginReadySignalData $ready = null;

            /** @return OAuthProviderRegistry No network exchange in this completion test */
            protected function buildProviderRegistry(): OAuthProviderRegistry
            {
                return new OAuthProviderRegistry([]);
            }

            /**
             * @param OAuthPendingLogin $op Exchange being completed
             * @param OAuthUserInfo $info Provider response
             */
            public function completeOAuthLogin(OAuthPendingLogin $op, OAuthUserInfo $info): void
            {
                parent::completeOAuthLogin($op, $info);
            }

            /**
             * @param OAuthPendingLogin $op Exchange being completed
             * @param OAuthUserInfo $info Provider facts the project may name differently
             * @return string Project account name
             */
            protected function displayNameFor(OAuthPendingLogin $op, OAuthUserInfo $info): string
            {
                return $info->name === null ? parent::displayNameFor($op, $info) : 'Project Account';
            }

            /**
             * @param string $signalName Completion signal
             * @param SignalDataInterface $data Completion payload
             */
            public function sendToAgent(string $signalName, SignalDataInterface $data): void
            {
                TestCase::assertSame(HilosSignalConstants::HILOS_OAUTH_LOGIN_READY, $signalName);
                TestCase::assertInstanceOf(OAuthLoginReadySignalData::class, $data);
                $this->ready = $data;
            }
        };
        $op = OAuthPendingLogin::create('accept', 'session', 'oauth:github', 'code', 'trip');
        $agent->completeOAuthLogin($op, new OAuthUserInfo('42', null, $providerName));
        self::assertNotNull($agent->ready);
        $wire = $agent->ready->toArray();
        self::assertSame([
            'provider' => 'oauth:github', 'subject' => '42', 'email' => null, 'displayName' => $displayName,
            'acceptKey' => 'accept', 'sessionToken' => 'session', 'tripKeyHash' => 'trip',
            'requestId' => null, 'action' => null, 'providerName' => $providerName,
        ], $wire);
        self::assertSame($wire, OAuthLoginReadySignalData::fromArray($wire)->toArray());
    }

    public function testLegacySenderMayOmitProviderName(): void
    {
        $legacy = new OAuthLoginReadySignalData('oauth:github', '42', null, 'oauth:github:42', 'accept', 'session', 'trip');
        $wire = $legacy->toArray();
        unset($wire['providerName']);
        self::assertNull(OAuthLoginReadySignalData::fromArray($wire)->providerName);
    }

    public function testMistypedOptionalNameIsRefused(): void
    {
        $wire = new OAuthLoginReadySignalData('oauth:github', '42', null, 'Person', 'accept', 'session', 'trip')->toArray();
        $wire['providerName'] = 42;
        $this->expectException(InvalidFormatException::class);
        OAuthLoginReadySignalData::fromArray($wire);
    }
}
