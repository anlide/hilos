<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\List\ProfileIdentitiesBrowserList;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Object\Item\Identity;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the profile linked-identities read-list (HIL-297).
 *
 * The list is the only surface that projects a user's identities to the client,
 * so its config carries two security-critical invariants: the `secret` hash is
 * never a selectable field, and the projection is scoped to the subscribing user
 * (self-connection anchor by accept key, identities joined by owner id). These
 * are asserted at the config level; the live projection behavior rides the
 * generic BrowserContext MANY/VIA machinery (framework-tested) and the full
 * profile e2e is HIL-305.
 */
final class ProfileIdentitiesBrowserListTest extends TestCase
{
    /**
     * Returns the identities item config (the MANY DB source), the row that
     * carries the projected fields.
     *
     * @return array<string, mixed> Identities item config
     */
    private function identitiesItem(): array
    {
        foreach (ProfileIdentitiesBrowserList::BROWSER[BrowserListConfigKey::ITEMS] as $item) {
            if (($item[BrowserListFieldKey::SOURCE] ?? null) === ChatBrowserSource::DB_IDENTITIES) {
                return $item;
            }
        }

        $this->fail('ProfileIdentitiesBrowserList has no DB_IDENTITIES item');
    }

    /**
     * Returns the self-connection anchor item config (the RT source).
     *
     * @return array<string, mixed> Anchor item config
     */
    private function anchorItem(): array
    {
        foreach (ProfileIdentitiesBrowserList::BROWSER[BrowserListConfigKey::ITEMS] as $item) {
            if (($item[BrowserListFieldKey::SOURCE] ?? null) === ChatBrowserSource::RT_CONNECTIONS) {
                return $item;
            }
        }

        $this->fail('ProfileIdentitiesBrowserList has no RT_CONNECTIONS anchor');
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

        $this->assertSame(Connection::userId, $anchor[BrowserListFieldKey::ITEM_KEY]);
        $this->assertSame(
            [Connection::acceptKey => ChatBrowserRef::TABLE_ACCEPT_KEY],
            $anchor[BrowserListFieldKey::WHERE],
        );
    }

    public function testIdentitiesJoinToTheOwnerConnectionAsMany(): void
    {
        $item = $this->identitiesItem();

        $this->assertTrue($item[BrowserListFieldKey::MANY]);
        $this->assertSame(
            [Identity::userId => Connection::userId],
            $item[BrowserListFieldKey::VIA],
        );
    }

    public function testListRequiresTheAcceptKeyParam(): void
    {
        $param = ProfileIdentitiesBrowserList::BROWSER[BrowserListConfigKey::PARAMS][BrowserRuntimeParam::ACCEPT_KEY];

        $this->assertTrue($param[BrowserParamKey::REQUIRED]);
    }

    public function testListIsRegisteredAndBoundToTheProfilePage(): void
    {
        $this->assertSame(
            ProfileIdentitiesBrowserList::class,
            Hilos::BROWSER_LISTS[ProfileIdentitiesBrowserList::LIST],
        );

        $binding = Hilos::PAGE_LISTS[ProfilePage::PAGE][ProfileIdentitiesBrowserList::LIST];
        $this->assertSame(
            ChatBrowserRef::ACCEPT_KEY,
            $binding[BrowserParamKey::PARAMS][BrowserRuntimeParam::ACCEPT_KEY],
        );
    }
}
