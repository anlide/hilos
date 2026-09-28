<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Registration;

use Hilos\HilosException;
use Hilos\Auth\Code\DTO\AuthCodeSendSignalData;
use Hilos\Auth\Library\DTO\RegisterActionDTO;
use Hilos\Auth\Library\DTO\RequestMagicLinkActionDTO;
use Hilos\Auth\Library\DTO\RequestPhoneCodeActionDTO;
use Hilos\Auth\Registration\RegistrationConsent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Core\Exception\ValidationException;
use Hilos\Hilos;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalConsentProjector;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Consent crosses both the browser action and the phone agent's IPC boundary without losing revision ids. */
final class RegistrationConsentTest extends TestCase
{
    /**
     * Restore the facade so catalog declarations cannot leak into another case.
     *
     * @throws HilosException When the fixture, catalog or action cannot be evaluated
     */
    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();
        parent::tearDown();
    }

    /**
     * An absent catalog is empty content, rather than invented framework acceptance.
     *
     * @throws HilosException When the fixture, catalog or action cannot be evaluated
     */
    public function testAnUnpublishedProjectHasNoConsentContent(): void
    {
        Hilos::initBrowser();
        self::assertSame([], LegalConsentProjector::documents());
        self::assertSame([], LegalConsentProjector::acceptance());
    }

    /**
     * A future deadline does not delay which revision registration presents.
     *
     * @throws HilosException When the fixture, catalog or action cannot be evaluated
     */
    public function testContentCarriesBothCurrentDocumentsAndTheirStandardClauses(): void
    {
        RegistrationConsentTestHilos::initBrowser();
        $documents = LegalConsentProjector::documents();
        self::assertSame(['terms', 'privacy'], array_column($documents, 'document'));
        self::assertSame('current', $documents[0]['revision']['revisionId']);
        self::assertCount(6, $documents[0]['clauses']);
        self::assertCount(7, $documents[1]['clauses']);
        self::assertSame(['terms' => 'current', 'privacy' => 'privacy'], LegalConsentProjector::acceptance());
        self::assertNotSame('', $documents[0]['clauses'][0]['text']);
    }

    /**
     * Neither an absent nor an arbitrary map can authorize an unpublished project.
     *
     * @throws HilosException When the fixture, catalog or action cannot be evaluated
     */
    public function testRegistrationRequiresPublishedDocuments(): void
    {
        Hilos::initBrowser();
        self::assertSame(AuthFlowOutcome::CODE_TERMS_UNPUBLISHED, RegistrationConsent::refusalCode(null));
        self::assertSame(AuthFlowOutcome::CODE_TERMS_UNPUBLISHED, RegistrationConsent::refusalCode(['terms' => 'old']));
        self::assertNotNull(RegistrationConsent::refusal(null)?->message);
    }

    /**
     * @param ?array<string, string> $accepted Submitted or held map
     * @param ?string $expected Expected refusal, or null for current consent
     */
    #[DataProvider('consentMaps')]
    public function testEveryCurrentRevisionMustBeAccepted(?array $accepted, ?string $expected): void
    {
        RegistrationConsentTestHilos::initBrowser();
        self::assertSame($expected, RegistrationConsent::refusalCode($accepted));
        $refusal = RegistrationConsent::refusal($accepted);
        if ($expected === null) {
            self::assertNull($refusal);
        } else {
            self::assertSame(AuthFlowStep::CONSENT, $refusal?->step);
            self::assertSame($expected, $refusal?->code);
            self::assertSame($expected === AuthFlowOutcome::CODE_CONSENT_REQUIRED, $refusal?->message === null);
        }
    }

    /** @return iterable<string, array{?array<string, string>, ?string}> Missing, incomplete, stale and exact maps */
    public static function consentMaps(): iterable
    {
        yield 'missing' => [null, AuthFlowOutcome::CODE_CONSENT_REQUIRED];
        yield 'empty' => [[], AuthFlowOutcome::CODE_CONSENT_REVISED];
        yield 'one document missing' => [['terms' => 'current'], AuthFlowOutcome::CODE_CONSENT_REVISED];
        yield 'extra document' => [
            ['terms' => 'current', 'privacy' => 'privacy', 'other' => 'unknown'], AuthFlowOutcome::CODE_CONSENT_REVISED,
        ];
        yield 'outdated' => [['terms' => 'old', 'privacy' => 'privacy'], AuthFlowOutcome::CODE_CONSENT_REVISED];
        yield 'current, including a future deadline' => [['terms' => 'current', 'privacy' => 'privacy'], null];
        yield 'order is not acceptance' => [['privacy' => 'privacy', 'terms' => 'current'], null];
    }

    /**
     * Omission and an incomplete submitted map have different refusal meanings.
     *
     * @throws HilosException When the fixture, catalog or action cannot be evaluated
     */
    public function testAbsentAndEmptyMapsRemainDistinct(): void
    {
        self::assertNull(RegistrationConsent::readPayload(null));
        self::assertSame([], RegistrationConsent::readPayload([]));
        self::assertNull(RegisterActionDTO::fromArray(['email' => 'person@example.test'])->acceptedRevisions);
    }

    /**
     * The browser and phone IPC boundaries preserve the same exact revision ids.
     *
     * @throws HilosException When the fixture, catalog or action cannot be evaluated
     */
    public function testAcceptedRevisionsSurviveEachDeliveryRoad(): void
    {
        $accepted = ['terms' => 'terms-v2', 'privacy' => 'privacy-v1'];
        foreach ([
            RegisterActionDTO::fromArray(['email' => 'person@example.test', 'acceptedRevisions' => $accepted]),
            RequestMagicLinkActionDTO::fromArray(['email' => 'person@example.test', 'acceptedRevisions' => $accepted]),
            RequestPhoneCodeActionDTO::fromArray(['phone' => '+14155552671', 'channel' => 'sms', 'acceptedRevisions' => $accepted]),
            AuthCodeSendSignalData::fromArray([
                'acceptKey' => 'socket', 'sessionToken' => 'browser', 'identifier' => '+14155552671',
                'channel' => 'sms', 'type' => 'sms_login', 'progressTicket' => 'ticket', 'acceptedRevisions' => $accepted,
            ]),
        ] as $dto) {
            self::assertSame($accepted, $dto->acceptedRevisions);
            self::assertSame($accepted, $dto::fromArray($dto->toArray())->acceptedRevisions);
        }
    }

    /**
     * @param mixed $accepted Malformed input sent by a client
     */
    #[DataProvider('malformedMaps')]
    public function testMalformedAcceptanceIsRefusedAtTheActionBoundary(mixed $accepted): void
    {
        $this->expectException(ValidationException::class);
        RegisterActionDTO::fromArray(['email' => 'person@example.test', 'acceptedRevisions' => $accepted]);
    }

    /** @return iterable<string, array{mixed}> Invalid map shapes and entries */
    public static function malformedMaps(): iterable
    {
        yield 'scalar' => ['terms-v1'];
        yield 'boolean' => [true];
        yield 'numeric key' => [['terms-v1']];
        yield 'unknown document' => [['marketing' => 'v1']];
        yield 'empty revision' => [['terms' => '']];
        yield 'numeric revision' => [['terms' => 1]];
        yield 'null revision' => [['privacy' => null]];
        yield 'nested revision' => [['terms' => ['revision' => 'v1']]];
    }
}

/** Plain documents with a later terms revision, including its still-future deadline. */
final class RegistrationConsentTestCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Test declarations */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'old', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'current', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2099-01-01', []),
            ],
            'privacy' => [
                new LegalRevision(LegalDocument::PRIVACY, 'privacy', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            ],
        ];
    }
}

/** Binds the test's catalog exactly as a project does. */
abstract class RegistrationConsentTestHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = RegistrationConsentTestCatalog::class;
}
