<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Environment;

use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use PHPUnit\Framework\TestCase;

/**
 * Pins the complete framework classification, including keys left closed to viewers.
 */
final class EnvCatalogMetadataTest extends TestCase
{
    public function testFrameworkClassificationsAreExact(): void
    {
        $env = new EnvAccessor(EnvCatalogStub::class);
        $sensitive = [];
        $perNode = [];

        foreach (array_keys(EnvCatalogStub::getCatalog()) as $key) {
            if ($env->sensitiveFor($key)) {
                $sensitive[] = $key;
            }
            if ($env->perNodeFor($key)) {
                $perNode[] = $key;
            }
            $this->assertFalse($env->visibleInAdminViewMode($key), $key);
        }

        $expectedSensitive = [
            'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'DB_SECONDARY_PASSWORD',
            'LLM_EXTERNAL_API_KEY', 'BACKUP_SHIP_SSH_KEY', 'HILOS_WEBAUTHN_CHALLENGE_SECRET',
            'MAIL_SMTP_PASSWORD', 'WATCHDOG_ALERT_SMTP_PASSWORD', 'TELEGRAM_GATEWAY_TOKEN',
            'SMS_API_KEY', 'SMS_API_PASSWORD', 'VAPID_PRIVATE',
        ];
        $expectedPerNode = [
            'HILOS_DAEMON_HOST', 'HTTP_STATUS_HOST', 'WORKER_COMM_HOST', 'COMMAND_HOST',
            'DAEMON_LOG_FILE', 'DAEMON_ERROR_LOG_FILE', 'DOCKER_DAEMON_IP', 'WEBSOCKET_HOST',
            'FRONTEND_HTML_HOST', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE',
            'CLUSTER_NODE_CAPABILITIES', 'CLUSTER_PEER_HOST', 'CLUSTER_PEER_ADVERTISE',
            'CLUSTER_TLS_CERT_FILE',
        ];
        sort($sensitive);
        sort($perNode);
        sort($expectedSensitive);
        sort($expectedPerNode);

        $this->assertSame($expectedSensitive, $sensitive);
        $this->assertSame($expectedPerNode, $perNode);
    }
}
