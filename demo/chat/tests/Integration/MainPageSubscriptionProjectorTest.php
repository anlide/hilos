<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Frontend\FrontendStateCollectionKey;
use Demo\Chat\Frontend\MainPageSubscriptionProjector;
use Demo\Chat\Frontend\SelfConnectionFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for main-page subscription projection payloads.
 */
final class MainPageSubscriptionProjectorTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testSelfConnectionIsDeliveredAsFrontendState(): void
    {
        RtTruthSourceRegistry::register(RtChatContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        try {
            $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));
            Hilos::$rt->connections->actions->register('subscription-ak', $user->id);

            $payload = MainPageSubscriptionProjector::forConnection(
                Hilos::$rt->connections['subscription-ak'],
            )->toArray();

            $this->assertArrayNotHasKey(SelfConnectionSignalData::selfConnection, $payload);
            $selfConnection = $payload['frontend']['full'][FrontendStateCollectionKey::SELF_CONNECTION][0] ?? [];
            $this->assertSame(SelfConnectionFrontendStateProjector::ID_SELF, $selfConnection['id'] ?? null);
            $this->assertSame($user->id, $selfConnection[SelfConnectionSignalData::userId] ?? null);
            $this->assertNull($selfConnection[SelfConnectionSignalData::fileUploadState] ?? null);
            $this->assertContains(
                FrontendStateCollectionKey::SELF_CONNECTION,
                $payload['frontend']['replaceFull'] ?? [],
            );
            $this->assertContains(
                FrontendStateCollectionKey::ATTACHMENT_DRAFTS,
                $payload['frontend']['replaceFull'] ?? [],
            );
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }
}
