<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Security;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\OAuthProvider as EntityOAuthProvider;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTableRow;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestHilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the sign-in methods table (HIL-427).
 *
 * The directories are the demo-shaped fixture's; nothing is configured, so no provider has
 * a client pair and the delivery knobs are pinned per case. The assertions cover the row
 * projection in directory order, the switched-off state, readiness per method, and which
 * source changes the table answers.
 */
final class HilosSecuritySignInMethodsTableTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(EnvConstants::MAIL_TRANSPORT->name);
        putenv(EnvConstants::MAIL_SMTP_HOST->name);
        AuthMethodTestHilos::unmount();

        parent::tearDown();
    }

    /**
     * One row per wired method, in directory order, named for the screen.
     */
    public function testSnapshotProjectsOneRowPerWiredMethod(): void
    {
        AuthMethodTestHilos::mount(null);

        $rows = $this->rows();

        self::assertSame([
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GITHUB->value,
            OAuthProviderPreset::GOOGLE->value,
        ], array_map(static fn(HilosSecuritySignInMethodsTableRow $row): string => $row->methodKey, $rows));
        self::assertSame(
            ['Password', 'Passkey', 'Email link', 'Phone code', 'GitHub', 'Google'],
            array_map(static fn(HilosSecuritySignInMethodsTableRow $row): string => $row->label, $rows),
        );
    }

    /**
     * A switched-off method reads off; the rest read on.
     */
    public function testASwitchedOffMethodReadsOff(): void
    {
        AuthMethodTestHilos::mount(AuthMethodKey::SMS . ',' . OAuthProviderPreset::GOOGLE->value);

        $enabled = [];
        foreach ($this->rows() as $row) {
            $enabled[$row->methodKey] = $row->enabled;
        }

        self::assertFalse($enabled[AuthMethodKey::SMS]);
        self::assertFalse($enabled[OAuthProviderPreset::GOOGLE->value]);
        self::assertTrue($enabled[AuthMethodKey::PASSWORD]);
        self::assertTrue($enabled[OAuthProviderPreset::GITHUB->value]);
    }

    /**
     * A password and a passkey need nothing; a provider with no client pair is not ready and links to its screen.
     */
    public function testReadinessFollowsWhatEachMethodNeeds(): void
    {
        AuthMethodTestHilos::mount(null);

        $rows = [];
        foreach ($this->rows() as $row) {
            $rows[$row->methodKey] = $row;
        }

        self::assertTrue($rows[AuthMethodKey::PASSWORD]->ready);
        self::assertTrue($rows[AuthMethodKey::PASSKEY]->ready);
        self::assertNull($rows[AuthMethodKey::PASSWORD]->providerKey);
        self::assertFalse($rows[OAuthProviderPreset::GITHUB->value]->ready);
        self::assertSame(OAuthProviderPreset::GITHUB->value, $rows[OAuthProviderPreset::GITHUB->value]->providerKey);
    }

    /**
     * The mailed link is ready exactly when a code can be delivered to an address.
     */
    public function testTheMailedLinkIsReadyWhenMailReachesARelay(): void
    {
        AuthMethodTestHilos::mount(null);
        putenv(EnvConstants::MAIL_TRANSPORT->name . '=smtp');
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=relay.example.invalid');
        self::assertTrue($this->row(AuthMethodKey::MAGIC_LINK)->ready);

        putenv(EnvConstants::MAIL_TRANSPORT->name);
        putenv(EnvConstants::MAIL_SMTP_HOST->name . '=');
        self::assertFalse($this->row(AuthMethodKey::MAGIC_LINK)->ready);
    }

    /**
     * The row rides the single method slot, keyed by the method.
     */
    public function testBrowserRowRidesTheMethodSlot(): void
    {
        AuthMethodTestHilos::mount(null);
        $row = $this->row(AuthMethodKey::PASSWORD);

        $envelope = new HilosSecuritySignInMethodsTable()->browserRow($row);

        self::assertSame(AuthMethodKey::PASSWORD, $envelope[BrowserPageSignalData::rowKey]);
        self::assertSame(['method' => $row->toArray()], $envelope[BrowserPageSignalData::sources]);
    }

    /**
     * A provider's own row changing redraws that provider's method row.
     */
    public function testAProviderRowChangeRedrawsItsMethodRow(): void
    {
        AuthMethodTestHilos::mount(null);

        $mutation = new HilosSecuritySignInMethodsTable()->buildMutationForSourceEvent(new SourceChange(
            SourceChange::KIND_DB,
            HilosDbContext::oauthProviders,
            '1',
            TableMutationType::Update,
            [EntityOAuthProvider::provider_key => OAuthProviderPreset::GITHUB->value],
        ));

        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertSame(OAuthProviderPreset::GITHUB->value, $mutation->rowKey);
    }

    /**
     * The method setting moves many rows at once, so the table leaves it to the live set; other sources are ignored.
     */
    public function testTheMethodSettingAndOtherSourcesAreNotRowChanges(): void
    {
        AuthMethodTestHilos::mount(null);
        $table = new HilosSecuritySignInMethodsTable();

        self::assertNull($table->buildMutationForSourceEvent(new SourceChange(
            SourceChange::KIND_DB,
            HilosDbContext::settings,
            '1',
            TableMutationType::Update,
            [EntitySetting::key => 'auth.methods.disabled'],
        )));
        self::assertNull($table->buildMutationForSourceEvent(new SourceChange(
            SourceChange::KIND_DB,
            HilosDbContext::oauthProviders,
            '1',
            TableMutationType::Update,
            [EntityOAuthProvider::provider_key => 'oauth:acme'],
        )));
    }

    /**
     * @return list<HilosSecuritySignInMethodsTableRow> Snapshot rows
     */
    private function rows(): array
    {
        $rows = [];
        foreach (new HilosSecuritySignInMethodsTable()->getFullSnapshot()->rows as $row) {
            self::assertInstanceOf(HilosSecuritySignInMethodsTableRow::class, $row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param string $methodKey Method to find
     * @return HilosSecuritySignInMethodsTableRow Its snapshot row
     */
    private function row(string $methodKey): HilosSecuritySignInMethodsTableRow
    {
        foreach ($this->rows() as $row) {
            if ($row->methodKey === $methodKey) {
                return $row;
            }
        }

        self::fail("No row for {$methodKey}");
    }
}
