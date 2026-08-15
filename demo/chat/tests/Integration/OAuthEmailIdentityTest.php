<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\OAuthAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Actions\Item\UserActions;
use Demo\Chat\Hilos;
use Hilos\Auth\OAuth\OAuthUserInfo;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\View\Collection\Identities;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use Hilos\Utils\Helpers\RandomHelper;
use ReflectionMethod;

/**
 * Integration tests for OAuth verified-email persistence (HIL-405) and account
 * naming (HIL-573).
 *
 * Two surfaces: the framework write primitive
 * {@see Identities::createMagicLinkIdentity()}
 * (verified, lowercased, secret-less, duplicate-guarded), and the demo call site
 * {@see OAuthAgent::completeOAuthLogin()} which, on a new-user sign-up with a
 * provider email, persists that email as a verified `magic_link` identity
 * alongside the `oauth` identity so {@see findVerifiedEmailByUser()} resolves it.
 * A withheld email persists the oauth identity only; a colliding verified email
 * is diverted to the re-auth path before the create-path runs, so no email
 * identity is written. The name of the created account comes from the provider
 * whenever it fits the profile rename frame, and from `provider:subject`
 * otherwise — never from the address. Requires the test DB reset before run
 * (composer run test:db-reset).
 */
final class OAuthEmailIdentityTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string PROVIDER = 'oauth:github';

    /**
     * The primitive writes a verified, secret-less identity with a lowercased identifier.
     *
     * @throws HilosException When the write fails
     */
    public function testCreateMagicLinkIdentityIsVerifiedLowercasedAndSecretless(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Magic Writer')->id;
        $email = 'Mixed.Case+' . $this->unique() . '@Example.Test';

        $identity = Hilos::$db->identities->createMagicLinkIdentity($userId, $email);

        $this->assertSame($userId, $identity->userId);
        $this->assertSame(IdentityType::MAGIC_LINK, $identity->type);
        $this->assertSame(mb_strtolower($email), $identity->identifier);
        $this->assertTrue($identity->verified);

        Database::sql(
            'SELECT `' . EntityIdentity::secret . '` AS s FROM `' . EntityIdentity::_table . '` WHERE `'
            . EntityIdentity::id . '` = ?',
            [(int)$identity->id],
        );
        $row = Database::row();
        $this->assertNotNull($row);
        $this->assertNull($row['s']);
    }

    /**
     * A second write for the same email is refused by the (magic_link, identifier) uniqueness.
     *
     * @throws HilosException When setup fails
     */
    public function testCreateMagicLinkIdentityDuplicateIsRejected(): void
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Magic Dup')->id;
        $email = 'dup-' . $this->unique() . '@example.test';

        Hilos::$db->identities->createMagicLinkIdentity($userId, $email);

        $this->expectException(DuplicateValueException::class);
        Hilos::$db->identities->createMagicLinkIdentity($userId, $email);
    }

    /**
     * A new-user sign-up with a non-empty email persists BOTH the oauth and email identities.
     *
     * @throws HilosException When setup or the completion fails
     */
    public function testCompleteOAuthLoginPersistsOauthAndMagicLinkIdentities(): void
    {
        $agent = $this->bootAgent();
        $subject = 'sub-' . $this->unique();
        $email = 'New.User+' . $this->unique() . '@Example.Test';

        try {
            $this->completeLogin($agent, $subject, $email, 'octocat');

            $oauth = Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject);
            $this->assertNotNull($oauth);
            $userId = $oauth->userId;
            $this->assertNotNull($userId);

            $magicLink = Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, mb_strtolower($email));
            $this->assertNotNull($magicLink);
            $this->assertSame($userId, $magicLink->userId);
            $this->assertTrue($magicLink->verified);

            $this->assertSame(mb_strtolower($email), Hilos::$db->identities->findVerifiedEmailByUser($userId));

            // The address is present and still takes no part in the name (HIL-573).
            $this->assertSame('octocat', Hilos::$db->users[$userId]?->name);
        } finally {
            $this->drainSignals();
        }
    }

    /**
     * A new-user sign-up with no provider email persists the oauth identity only, and is still named.
     *
     * @throws HilosException When setup or the completion fails
     */
    public function testCompleteOAuthLoginWithoutEmailPersistsOauthOnly(): void
    {
        $agent = $this->bootAgent();
        $subject = 'sub-noemail-' . $this->unique();

        try {
            $this->completeLogin($agent, $subject, null, 'nameless-mail');

            $oauth = Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject);
            $this->assertNotNull($oauth);
            $userId = $oauth->userId;
            $this->assertNotNull($userId);

            $this->assertNull(Hilos::$db->identities->findVerifiedEmailByUser($userId));
            $this->assertSame('nameless-mail', Hilos::$db->users[$userId]?->name);
        } finally {
            $this->drainSignals();
        }
    }

    /**
     * A provider that gives neither address nor name still names the account, from the identity.
     *
     * @throws HilosException When setup or the completion fails
     */
    public function testCompleteOAuthLoginWithoutEmailOrNameFallsBackToProviderAndSubject(): void
    {
        $agent = $this->bootAgent();
        $subject = 'sub-bare-' . $this->unique();

        try {
            $this->completeLogin($agent, $subject, null, null);

            $this->assertSame(self::PROVIDER . ':' . $subject, $this->createdUserName($subject));
        } finally {
            $this->drainSignals();
        }
    }

    /**
     * A provider name outside the profile rename frame is refused in favour of the same fallback.
     *
     * @throws HilosException When setup or the completion fails
     */
    public function testCompleteOAuthLoginRefusesAProviderNameOutsideTheRenameFrame(): void
    {
        $agent = $this->bootAgent();
        $tooLongSubject = 'sub-long-' . $this->unique();
        $tooShortSubject = 'sub-short-' . $this->unique();

        try {
            $this->completeLogin($agent, $tooLongSubject, null, str_repeat('a', UserActions::NAME_MAX_LENGTH + 1));
            $this->completeLogin($agent, $tooShortSubject, null, 'a');

            $this->assertSame(self::PROVIDER . ':' . $tooLongSubject, $this->createdUserName($tooLongSubject));
            $this->assertSame(self::PROVIDER . ':' . $tooShortSubject, $this->createdUserName($tooShortSubject));
        } finally {
            $this->drainSignals();
        }
    }

    /**
     * A colliding verified email diverts to re-auth before the create-path, writing no email identity.
     *
     * @throws HilosException When setup or the completion fails
     */
    public function testCompleteOAuthLoginWithCollidingEmailWritesNoEmailIdentity(): void
    {
        $agent = $this->bootAgent();
        $subject = 'sub-collide-' . $this->unique();
        $email = 'owner-' . $this->unique() . '@example.test';

        try {
            $ownerId = (int)Hilos::$db->users->actions->createWithName('Email Owner')->id;
            Hilos::$db->identities->createMagicLinkIdentity($ownerId, $email);

            $this->completeLogin($agent, $subject, $email, 'colliding-octo');

            $this->assertNull(
                Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject),
            );

            $owners = EntityIdentity::get([
                EntityIdentity::type => IdentityType::MAGIC_LINK,
                EntityIdentity::identifier => $email,
            ]);
            $this->assertCount(1, $owners);
            $owner = $owners->first();
            $this->assertNotNull($owner);
            $this->assertSame($ownerId, $owner->user_id);
        } finally {
            $this->drainSignals();
        }
    }

    /**
     * Boots the signal router and agent id the completion path needs, then builds the agent.
     *
     * @return OAuthAgent Agent under test
     * @throws HilosException When runtime setup fails
     */
    private function bootAgent(): OAuthAgent
    {
        ExecutionContext::setCurrentAgentId(self::TEST_AGENT_ID);
        Hilos::initSignalRouter(new ChatSignalRouter());

        return new OAuthAgent();
    }

    /**
     * Drives the protected completion for a fresh (provider, subject) with a given email and name.
     *
     * @param OAuthAgent $agent Agent under test
     * @param string $subject Provider-immutable account subject
     * @param ?string $email Provider-reported email, or null when the provider withheld it
     * @param ?string $name Provider-reported display name, or null when the provider withheld it
     * @throws HilosException When the completion fails
     */
    private function completeLogin(OAuthAgent $agent, string $subject, ?string $email, ?string $name): void
    {
        $op = OAuthPendingLogin::create('ak-' . $subject, 'session-' . $subject, self::PROVIDER, 'code', 0.0);
        $method = new ReflectionMethod(OAuthAgent::class, 'completeOAuthLogin');
        $method->invoke($agent, $op, new OAuthUserInfo($subject, $email, $name));
    }

    /**
     * Reads back the display name of the account a completed login created.
     *
     * @param string $subject Provider-immutable account subject the login ran for
     * @return ?string Display name on the created user row
     * @throws HilosException When the identity or user cannot be read
     */
    private function createdUserName(string $subject): ?string
    {
        $oauth = Hilos::$db->identities->findByIdentity(IdentityType::OAUTH, self::PROVIDER . ':' . $subject);
        $this->assertNotNull($oauth);
        $userId = $oauth->userId;
        $this->assertNotNull($userId);

        return Hilos::$db->users[$userId]?->name;
    }

    /**
     * Drains any signals the completion queued so they do not bleed into the next test.
     *
     * @throws HilosException When the queue cannot be read
     */
    private function drainSignals(): void
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
            continue;
        }
    }

    /**
     * Builds a unique suffix for one test's identifiers.
     *
     * @return string Unique hex suffix
     */
    private function unique(): string
    {
        return RandomHelper::hex(6);
    }
}
