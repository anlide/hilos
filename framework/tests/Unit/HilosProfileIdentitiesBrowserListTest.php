<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Object\Item\Identity;
use Hilos\Database\Object\Item\PasskeyCredential;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Hilos\Runtime\State\Item\HilosConnection;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the profile linked-identities read-list (HIL-1277).
 *
 * The list is the only surface that projects a user's identities to the client,
 * so its config carries two security-critical invariants: the `secret` hash is
 * never a selectable field, and the projection is scoped to the subscribing user
 * (self-connection anchor by accept key, identities joined by owner id). These
 * are asserted at the config level; the live projection behavior rides the
 * generic BrowserContext MANY/VIA machinery and the profile e2e scenarios.
 */
final class HilosProfileIdentitiesBrowserListTest extends TestCase
{
    /**
     * Returns the identities item config (the MANY DB source), the row that
     * carries the projected fields.
     *
     * @return array<string, mixed> Identities item config
     */
    private function identitiesItem(): array
    {
        foreach (HilosProfileIdentitiesBrowserList::BROWSER[BrowserListConfigKey::ITEMS] as $item) {
            if (($item[BrowserListFieldKey::SOURCE] ?? null) === HilosProfileIdentitiesBrowserList::DB_IDENTITIES) {
                return $item;
            }
        }

        $this->fail('HilosProfileIdentitiesBrowserList has no DB_IDENTITIES item');
    }

    /**
     * Returns the self-connection anchor item config (the RT source).
     *
     * @return array<string, mixed> Anchor item config
     */
    private function anchorItem(): array
    {
        foreach (HilosProfileIdentitiesBrowserList::BROWSER[BrowserListConfigKey::ITEMS] as $item) {
            if (($item[BrowserListFieldKey::SOURCE] ?? null) === HilosProfileIdentitiesBrowserList::RT_CONNECTIONS) {
                return $item;
            }
        }

        $this->fail('HilosProfileIdentitiesBrowserList has no RT_CONNECTIONS anchor');
    }

    public function testProjectedFieldsAreTheNonSecretIdentityFields(): void
    {
        $this->assertSame(
            [
                Identity::id,
                Identity::userId,
                Identity::type,
                Identity::identifier,
                Identity::provider,
                Identity::verified,
            ],
            $this->identitiesItem()[BrowserListFieldKey::FIELDS],
        );
    }

    public function testSecretIsNeverAProjectedField(): void
    {
        $this->assertNotContains(
            EntityIdentity::secret,
            $this->identitiesItem()[BrowserListFieldKey::FIELDS],
            'The identity secret hash must never be projected to the client',
        );
    }

    public function testAnchorIsScopedToTheSelfConnectionByAcceptKey(): void
    {
        $anchor = $this->anchorItem();

        $this->assertSame(HilosConnection::userId, $anchor[BrowserListFieldKey::ITEM_KEY]);
        $this->assertSame(
            [HilosConnection::acceptKey => HilosProfileIdentitiesBrowserList::TABLE_ACCEPT_KEY],
            $anchor[BrowserListFieldKey::WHERE],
        );
    }

    public function testIdentitiesJoinToTheOwnerConnectionAsMany(): void
    {
        $item = $this->identitiesItem();

        $this->assertTrue($item[BrowserListFieldKey::MANY]);
        $this->assertSame(
            [Identity::userId => HilosConnection::userId],
            $item[BrowserListFieldKey::VIA],
        );
    }

    public function testIdentitiesItemKeyIsTheOwnerForeignKey(): void
    {
        // BrowserContext matches each MANY source item by comparing its ITEM_KEY
        // field against the anchor row key (the self-connection userId), so the
        // key must be the owner FK (Identity::userId), not Identity::id — the
        // latter only ever matches by coincidence and empties the list.
        $this->assertSame(Identity::userId, $this->identitiesItem()[BrowserListFieldKey::ITEM_KEY]);
    }

    public function testPasskeySidecarsJoinByOwnerWithoutKeyMaterial(): void
    {
        foreach (HilosProfileIdentitiesBrowserList::BROWSER[BrowserListConfigKey::ITEMS] as $item) {
            if (($item[BrowserListFieldKey::SOURCE] ?? null) !== HilosProfileIdentitiesBrowserList::DB_PASSKEY_CREDENTIALS) {
                continue;
            }

            $this->assertTrue($item[BrowserListFieldKey::MANY]);
            $this->assertSame(PasskeyCredential::userId, $item[BrowserListFieldKey::ITEM_KEY]);
            $this->assertSame([PasskeyCredential::userId => HilosConnection::userId], $item[BrowserListFieldKey::VIA]);
            $this->assertSame(
                [
                    PasskeyCredential::id,
                    PasskeyCredential::userId,
                    PasskeyCredential::identityId,
                    PasskeyCredential::label,
                    PasskeyCredential::createdAt,
                ],
                $item[BrowserListFieldKey::FIELDS],
            );

            return;
        }

        $this->fail('HilosProfileIdentitiesBrowserList has no passkey sidecar item');
    }

    public function testListRequiresTheAcceptKeyParam(): void
    {
        $param = HilosProfileIdentitiesBrowserList::BROWSER[BrowserListConfigKey::PARAMS][BrowserRuntimeParam::ACCEPT_KEY];

        $this->assertTrue($param[BrowserParamKey::REQUIRED]);
    }

    public function testBindingFillsAcceptKeyFromTheConnection(): void
    {
        $this->assertSame(
            HilosProfileIdentitiesBrowserList::ACCEPT_KEY,
            HilosProfileIdentitiesBrowserList::BINDING[BrowserParamKey::PARAMS][BrowserRuntimeParam::ACCEPT_KEY],
        );
        $this->assertSame('connections', HilosProfileIdentitiesBrowserList::CONNECTIONS);
        $this->assertSame(
            HilosProfileIdentitiesBrowserList::CONNECTIONS,
            HilosProfileIdentitiesBrowserList::RT_CONNECTIONS[BrowserSourceKey::KEY],
        );
    }
}
