<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\Actions\Collection\SettingsActions;
use Hilos\Database\Actions\Item\SettingActions;
use Hilos\Database\Context\FrameworkExtension;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\CollectionAlreadyMountedException;
use Hilos\Database\Exception\FrameworkExtensionException;
use Hilos\Database\Object\Collection\VerifierCircleMembers as ObjectVerifierCircleMembers;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\AccountDeletions as DbCollectionAccountDeletions;
use Hilos\Database\View\Collection\AuthBlocks as DbCollectionAuthBlocks;
use Hilos\Database\View\Collection\DataExports as DbCollectionDataExports;
use Hilos\Database\View\Collection\Files as DbCollectionFiles;
use Hilos\Database\View\Collection\FileVariants as DbCollectionFileVariants;
use Hilos\Database\View\Collection\Identities as DbCollectionIdentities;
use Hilos\Database\View\Collection\LegalAcceptances as DbCollectionLegalAcceptances;
use Hilos\Database\View\Collection\NotificationDeliveries as DbCollectionNotificationDeliveries;
use Hilos\Database\View\Collection\NotificationPreferences as DbCollectionNotificationPreferences;
use Hilos\Database\View\Collection\Notifications as DbCollectionNotifications;
use Hilos\Database\View\Collection\OAuthProviders as DbCollectionOAuthProviders;
use Hilos\Database\View\Collection\PasskeyCredentials as DbCollectionPasskeyCredentials;
use Hilos\Database\View\Collection\PushSubscriptions as DbCollectionPushSubscriptions;
use Hilos\Database\View\Collection\RegistrationReservations as DbCollectionRegistrationReservations;
use Hilos\Database\View\Collection\SecondFactorBackupCodes as DbCollectionSecondFactorBackupCodes;
use Hilos\Database\View\Collection\SecondFactorResets as DbCollectionSecondFactorResets;
use Hilos\Database\View\Collection\SecondFactors as DbCollectionSecondFactors;
use Hilos\Database\View\Collection\SecondFactorSettings as DbCollectionSecondFactorSettings;
use Hilos\Database\View\Collection\SecondFactorTrusts as DbCollectionSecondFactorTrusts;
use Hilos\Database\View\Collection\Sessions as DbCollectionSessions;
use Hilos\Database\View\Collection\Settings as DbCollectionSettings;
use Hilos\Database\View\Collection\StepUps as DbCollectionStepUps;
use Hilos\Database\View\Collection\UserRenames as DbCollectionUserRenames;
use Hilos\Database\View\Collection\Users as DbCollectionUsers;
use Hilos\Database\View\Collection\UserVerifications as DbCollectionUserVerifications;
use Hilos\Database\View\Collection\VerifierCircleMembers as DbCollectionVerifierCircleMembers;
use Hilos\HilosException;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMemberObjects;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMembers;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMembersActions;
use Hilos\Tests\Unit\Database\Fixtures\Extension\NotedMembersDbContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The substitution point of a framework key: what a project's declaration mounts, what a context
 * without one mounts, and what the mount refuses.
 *
 * No database is needed. configure() only constructs the collections - a lazy one loads nothing
 * and an eager one loads on its first read - so every case here builds a context the way the
 * facade does and asks what is mounted under each key.
 */
final class FrameworkExtensionMountTest extends TestCase
{
    /**
     * The view collection the framework mounts under each of its keys, as configure() did before
     * the substitution point existed. A context declaring no extension must mount exactly these,
     * each over the object collection its view names.
     *
     * @var array<string, class-string>
     */
    private const array FRAMEWORK_VIEWS = [
        HilosDbContext::settings => DbCollectionSettings::class,
        HilosDbContext::identities => DbCollectionIdentities::class,
        HilosDbContext::verifications => DbCollectionUserVerifications::class,
        HilosDbContext::registrationReservations => DbCollectionRegistrationReservations::class,
        HilosDbContext::passkeyCredentials => DbCollectionPasskeyCredentials::class,
        HilosDbContext::sessions => DbCollectionSessions::class,
        HilosDbContext::notifications => DbCollectionNotifications::class,
        HilosDbContext::notificationDeliveries => DbCollectionNotificationDeliveries::class,
        HilosDbContext::notificationPreferences => DbCollectionNotificationPreferences::class,
        HilosDbContext::pushSubscriptions => DbCollectionPushSubscriptions::class,
        HilosDbContext::verifierCircle => DbCollectionVerifierCircleMembers::class,
        HilosDbContext::authBlocks => DbCollectionAuthBlocks::class,
        HilosDbContext::oauthProviders => DbCollectionOAuthProviders::class,
        HilosDbContext::secondFactors => DbCollectionSecondFactors::class,
        HilosDbContext::secondFactorBackupCodes => DbCollectionSecondFactorBackupCodes::class,
        HilosDbContext::secondFactorTrusts => DbCollectionSecondFactorTrusts::class,
        HilosDbContext::secondFactorResets => DbCollectionSecondFactorResets::class,
        HilosDbContext::secondFactorSettings => DbCollectionSecondFactorSettings::class,
        HilosDbContext::stepUps => DbCollectionStepUps::class,
        HilosDbContext::legalAcceptances => DbCollectionLegalAcceptances::class,
        HilosDbContext::accountDeletions => DbCollectionAccountDeletions::class,
        HilosDbContext::dataExports => DbCollectionDataExports::class,
        HilosDbContext::files => DbCollectionFiles::class,
        HilosDbContext::fileVariants => DbCollectionFileVariants::class,
        HilosDbContext::users => DbCollectionUsers::class,
        HilosDbContext::userRenames => DbCollectionUserRenames::class,
    ];

    /**
     * @throws HilosException When the context refuses to configure
     */
    public function testAProjectChainIsMountedUnderTheFrameworkKey(): void
    {
        $context = new NotedMembersDbContext();
        $context->configure();

        $view = $context->getDbItemCollection(HilosDbContext::verifierCircle);
        $objects = $context->mountedObjectCollection(HilosDbContext::verifierCircle);

        $this->assertInstanceOf(NotedMembers::class, $view);
        $this->assertInstanceOf(NotedMemberObjects::class, $objects);
        $this->assertInstanceOf(NotedMembersActions::class, $view->actions);
        $this->assertSame(
            Objects::LAZY_STRATEGY_KEY,
            $objects->getLazyStrategy(),
            'The loading strategy stays the framework\'s: how a table is read is its owner\'s decision',
        );
        $this->assertContains(NotedMemberObjects::class, $context->getObjectCollectionClasses());
        $this->assertNotContains(
            ObjectVerifierCircleMembers::class,
            $context->getObjectCollectionClasses(),
            'The chain replaces the framework\'s under the key; it does not stand beside it',
        );
    }

    /**
     * @throws HilosException When the context refuses to configure
     */
    public function testAContextWithoutDeclarationsMountsTheFrameworkChains(): void
    {
        $context = new PlainHilosDbContext();
        $context->configure();

        $mounted = [];
        foreach (array_keys(self::FRAMEWORK_VIEWS) as $key) {
            $view = $context->getDbItemCollection($key);
            $this->assertNotNull($view, "Nothing is mounted under [{$key}]");
            $mounted[$key] = $view::class;
            $this->assertSame(
                $view::OBJECT_COLLECTION_CLASS,
                $context->mountedObjectCollection($key)::class,
                "The object collection under [{$key}] is the one its view names",
            );
        }

        $this->assertSame(self::FRAMEWORK_VIEWS, $mounted);
        $this->assertCount(count(self::FRAMEWORK_VIEWS), $context->getObjectCollectionClasses());
    }

    /**
     * @throws HilosException When the context refuses to configure, which is the point
     */
    public function testADeclarationForAKeyTheFrameworkDoesNotMountIsRefused(): void
    {
        $context = new DeclaringDbContext(['nobodysKey' => new FrameworkExtension(NotedMembers::class)]);

        $this->expectException(FrameworkExtensionException::class);
        $this->expectExceptionMessage('[nobodysKey]');

        $context->configure();
    }

    /**
     * @throws HilosException When the context refuses to configure, which is the point
     */
    public function testADeclarationThatIsNotAFrameworkExtensionIsRefused(): void
    {
        $context = new DeclaringDbContext([HilosDbContext::verifierCircle => NotedMembers::class]);

        $this->expectException(FrameworkExtensionException::class);
        $this->expectExceptionMessage('[verifierCircle] is not a FrameworkExtension');

        $context->configure();
    }

    /**
     * @return iterable<string, array{string, FrameworkExtension, string}> Case => [key, declaration, layer named in the refusal]
     */
    public static function notExtendingProvider(): iterable
    {
        yield 'the framework\'s own view' => [
            HilosDbContext::verifierCircle,
            new FrameworkExtension(DbCollectionVerifierCircleMembers::class),
            'view collection',
        ];
        yield 'a view of another key' => [
            HilosDbContext::sessions,
            new FrameworkExtension(NotedMembers::class),
            'view collection',
        ];
        yield 'collection actions of another key' => [
            HilosDbContext::verifierCircle,
            new FrameworkExtension(NotedMembers::class, SettingsActions::class),
            'collection actions',
        ];
        yield 'item actions of another key' => [
            HilosDbContext::verifierCircle,
            new FrameworkExtension(NotedMembers::class, null, SettingActions::class),
            'item actions',
        ];
    }

    /**
     * @param string $key Framework key the declaration is made for
     * @param FrameworkExtension $extension Declaration with one layer that does not extend the framework's
     * @param string $layer The layer the refusal names
     * @throws HilosException When the context refuses to configure, which is the point
     */
    #[DataProvider('notExtendingProvider')]
    public function testAClassThatDoesNotExtendTheFrameworksLayerIsRefused(
        string $key,
        FrameworkExtension $extension,
        string $layer,
    ): void {
        $context = new DeclaringDbContext([$key => $extension]);

        $this->expectException(FrameworkExtensionException::class);
        $this->expectExceptionMessageMatches('/\[' . $key . '\] names .* as its ' . $layer . ', which does not extend/');

        $context->configure();
    }

    /**
     * @throws HilosException When the context refuses to configure, which is the point
     */
    public function testAnActionLayerTheFrameworkDoesNotRegisterIsRefused(): void
    {
        $context = new DeclaringDbContext([
            HilosDbContext::identities => new FrameworkExtension(IdentitiesOfTheTest::class, NotedMembersActions::class),
        ]);

        $this->expectException(FrameworkExtensionException::class);
        $this->expectExceptionMessage('[identities] declares collection actions, which the framework does not register');

        $context->configure();
    }

    /**
     * @throws HilosException When the context refuses to configure, which is the point
     */
    public function testASecondViewUnderAMountedKeyIsRefused(): void
    {
        $context = new OverwritingDbContext();

        $this->expectException(CollectionAlreadyMountedException::class);
        $this->expectExceptionMessage('[verifierCircle] is already mounted');

        $context->configure();
    }
}

/**
 * A context with no declaration of its own: the framework's chains and nothing else.
 */
final class PlainHilosDbContext extends HilosDbContext
{
}

/**
 * A context declaring whatever the case hands it, so that every refusal is staged from one class.
 */
final class DeclaringDbContext extends HilosDbContext
{
    /**
     * @param array<string, mixed> $declarations What frameworkExtensions() answers, whatever its shape
     */
    public function __construct(private readonly array $declarations)
    {
        parent::__construct();
    }

    /**
     * @return array<string, mixed> The declarations the case handed over, wrong shape included
     */
    protected function frameworkExtensions(): array
    {
        return $this->declarations;
    }
}

/**
 * A context re-mounting a framework key after the framework mounted it: the road the refusal in
 * setRepresent() closes.
 */
final class OverwritingDbContext extends HilosDbContext
{
    /**
     * @throws HilosException When a mount is refused - here, the second one
     */
    public function configure(): void
    {
        parent::configure();
        $this->setRepresent(self::verifierCircle, NotedMembers::class);
    }
}

/**
 * A view of the identities key that does extend the framework's, so that the action layer beside
 * it is the only thing wrong with the declaration.
 */
final class IdentitiesOfTheTest extends DbCollectionIdentities
{
}
