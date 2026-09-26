<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HttpConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Topology\AgentHttpRouteRegistry;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests AgentHttpRouteRegistry aggregation of AGENT_HTTP_ROUTES into method -> path -> agent type.
 */
final class AgentHttpRouteRegistryTest extends TestCase
{
    public function testRoutesAggregateEveryDeclaredAddressToItsAgentType(): void
    {
        $this->assertSame([
            HttpConstants::METHOD_GET => [
                '/_test/download' => 'download_agent',
                '/_test/preview' => 'download_agent',
                '/_test/status' => 'webhook_agent',
            ],
            HttpConstants::METHOD_POST => ['/_test/hook' => 'webhook_agent'],
        ], AgentHttpRouteRegistry::routes([
            'download_agent' => [AgentRegistryKey::WORKER => HttpDownloadRouteAgent::class],
            'webhook_agent' => [AgentRegistryKey::WORKER => HttpWebhookRouteAgent::class],
            'silent_agent' => [AgentRegistryKey::WORKER => HttpSilentRouteAgent::class],
        ]));
    }

    public function testRoutesSkipNonAgentRegistryEntries(): void
    {
        $this->assertSame([], AgentHttpRouteRegistry::routes([
            'not_agent' => [AgentRegistryKey::WORKER => HttpRequestDTO::class],
            0 => [AgentRegistryKey::WORKER => HttpDownloadRouteAgent::class],
        ]));
    }
}

final class HttpDownloadRouteAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'download_agent';

    public const array AGENT_HTTP_ROUTES = [HttpConstants::METHOD_GET => ['/_test/download', '/_test/preview']];

    public function onStop(): void
    {
    }
}

final class HttpWebhookRouteAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'webhook_agent';

    public const array AGENT_HTTP_ROUTES = [
        HttpConstants::METHOD_POST => ['/_test/hook'],
        HttpConstants::METHOD_GET => ['/_test/status'],
    ];

    public function onStop(): void
    {
    }
}

final class HttpSilentRouteAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'silent_agent';

    public function onStop(): void
    {
    }
}
