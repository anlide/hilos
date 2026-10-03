<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\WebAuthn\AssertionVerifier;
use Hilos\Auth\WebAuthn\AttestationVerifier;
use Hilos\Auth\WebAuthn\ClientData;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Auth\WebAuthn\WebAuthnConfig;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\PasskeyCredentials as ObjectPasskeyCredentials;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tests\Unit\Auth\WebAuthn\WebAuthnTestVectors;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Each declared algorithm enrolls and signs in through its stored row (HIL-659).
 *
 * The unit verifiers prove RS256 in separate pieces, while the CDP e2e device always
 * enrolls ES256. Here the algorithm used by TPM-backed Windows Hello crosses the
 * registration, database and assertion boundaries together, on genuine key material.
 */
final class PasskeyAlgorithmRoundTripIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @var list<string> Tables in foreign-key dependency order */
    private const array TABLES = [
        'hilos_user',
        'hilos_identity',
        'hilos_passkey_credential',
    ];

    private const string PERSON_AGENT = 'passkey-algorithm-person-agent';
    private const int USER_ID = 1;
    private const string ORIGIN = 'http://localhost';
    private const string REGISTRATION_CHALLENGE = 'passkey-registration-challenge';
    private const string ASSERTION_CHALLENGE = 'passkey-assertion-challenge';
    private const int INITIAL_SIGN_COUNT = 7;

    private ?DbContext $previousDb = null;

    /**
     * @return list<array{PasskeyAlgorithm}> Every algorithm offered by the framework
     */
    public static function declaredAlgorithms(): array
    {
        return array_map(static fn(PasskeyAlgorithm $algorithm): array => [$algorithm], PasskeyAlgorithm::cases());
    }

    /**
     * @param PasskeyAlgorithm $algorithm Algorithm used for both ceremonies
     * @throws HilosException When verification, persistence or the writing claim fails
     */
    #[DataProvider('declaredAlgorithms')]
    public function testAKeyOfEachDeclaredAlgorithmSignsInThroughItsStoredRow(PasskeyAlgorithm $algorithm): void
    {
        $vectors = new WebAuthnTestVectors(algorithm: $algorithm);
        $config = new WebAuthnConfig(
            'localhost', 'Hilos', [self::ORIGIN], 300, WebAuthnConfig::USER_VERIFICATION_REQUIRED, 60000, 'secret',
        );
        $registration = new AttestationVerifier($config)->verify(
            self::REGISTRATION_CHALLENGE,
            $vectors->clientDataJson(self::REGISTRATION_CHALLENGE, self::ORIGIN),
            $vectors->attestationObject($vectors->authenticatorData(
                WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED
                    | WebAuthnTestVectors::FLAG_ATTESTED_CREDENTIAL_DATA,
                self::INITIAL_SIGN_COUNT,
                $vectors->attestedCredentialData('round-trip-credential', hex2bin('0102030405060708090a0b0c0d0e0f10')),
            )),
        );

        $identityId = $this->identities()->createPasskeyIdentity(self::USER_ID, $registration->credentialId)->id;
        self::assertNotNull($identityId);
        $created = $this->passkeyCredentials()->createFromRegistration(
            $identityId,
            self::USER_ID,
            $registration->credentialId,
            $registration->publicKeyPem,
            $registration->algorithm,
            $registration->signCount,
            null,
            $registration->aaguid,
            'round-trip-user-handle',
            null,
        );

        // listByUser keeps existing objects: discard them so the ceremony uses the stored row.
        $this->passkeyCredentials()->clearInMemory();
        $stored = $this->passkeyCredentials()->listByUser(self::USER_ID);
        self::assertCount(1, $stored);
        [$row] = $stored;
        self::assertNotSame($created, $row);
        self::assertSame($algorithm->value, $row->algorithm);
        self::assertSame($registration->publicKeyPem, $row->publicKey);
        self::assertSame(self::INITIAL_SIGN_COUNT, $row->signCount);

        TruthSourceRegistry::register(
            HilosDbContext::passkeyCredentials,
            TruthSourceKeys::set((string)self::USER_ID),
            self::PERSON_AGENT,
        );
        ExecutionContext::setCurrentAgentId(self::PERSON_AGENT);

        $authData = $vectors->authenticatorData(
            WebAuthnTestVectors::FLAG_USER_PRESENT | WebAuthnTestVectors::FLAG_USER_VERIFIED,
            self::INITIAL_SIGN_COUNT + 1,
        );
        $clientDataJson = $vectors->clientDataJson(self::ASSERTION_CHALLENGE, self::ORIGIN, ClientData::TYPE_GET);
        $row->verifyAssertion(
            new AssertionVerifier($config),
            self::ASSERTION_CHALLENGE,
            $clientDataJson,
            $authData,
            $vectors->sign($authData, $clientDataJson),
        );

        $this->passkeyCredentials()->clearInMemory();
        $afterSignIn = $this->passkeyCredentials()->listByUser(self::USER_ID);
        self::assertCount(1, $afterSignIn);
        self::assertNotSame($row, $afterSignIn[0]);
        self::assertSame(self::INITIAL_SIGN_COUNT + 1, $afterSignIn[0]->signCount);
        self::assertNotNull($afterSignIn[0]->lastUsedAt);
    }

    /**
     * @throws HilosException When stub tables or the database context cannot be prepared
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::runStubs(down: true);
        self::runStubs(down: false);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Fixture person')", [self::USER_ID]);
        $this->previousDb = Hilos::$db;
        $db = new PasskeyAlgorithmRoundTripTestDbContext();
        $db->configure();
        Hilos::$db = $db;
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::PERSON_AGENT);
        Hilos::$db = $this->previousDb;
        self::runStubs(down: true);
        parent::tearDown();
    }

    /**
     * @return ObjectIdentities Identity persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function identities(): ObjectIdentities
    {
        /** @var ObjectIdentities $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::identities);

        return $collection;
    }

    /**
     * @return ObjectPasskeyCredentials Passkey persistence primitives
     * @throws HilosException When the collection is unavailable
     */
    private function passkeyCredentials(): ObjectPasskeyCredentials
    {
        /** @var ObjectPasskeyCredentials $collection */
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::passkeyCredentials);

        return $collection;
    }

    /**
     * @param bool $down Drop tables in reverse dependency order instead of creating them
     * @throws HilosException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the up stub's filename carries no suffix
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/** Framework collections only: neither table needs a project's user schema. */
final class PasskeyAlgorithmRoundTripTestDbContext extends HilosDbContext
{
}
