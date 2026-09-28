<?php

declare(strict_types=1);

namespace Hilos\Auth\Registration;

use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Core\Exception\ValidationException;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalConsentProjector;
use Hilos\Legal\LegalDocument;

/** Validates the document-to-revision map carried from consent to account creation. */
final class RegistrationConsent
{
    public const string PAYLOAD_KEY = 'acceptedRevisions';

    /**
     * DTOs read the optional field with their inherited optionalArray() before this validation.
     *
     * @param ?array<array-key, mixed> $accepted Submitted boundary map, or null when omitted
     * @return ?array<string, string> Document values mapped to nonempty revision ids
     * @throws ValidationException When a key is not a document or a revision is not a nonempty string
     */
    public static function readPayload(?array $accepted): ?array
    {
        if ($accepted === null) {
            return null;
        }
        foreach ($accepted as $document => $revision) {
            if (!is_string($document) || LegalDocument::tryFrom($document) === null
                || !is_string($revision) || $revision === '') {
                throw new ValidationException('Accepted revisions must map legal documents to nonempty revision ids');
            }
        }

        return $accepted;
    }

    /**
     * @param ?array<string, string> $accepted Submitted or reserved document-to-revision boundary map
     * @return ?string Refusal code, or null when every declared current revision was accepted
     * @throws LegalException When the catalog cannot be read
     */
    public static function refusalCode(?array $accepted): ?string
    {
        $current = LegalConsentProjector::acceptance();
        if ($current === []) {
            return AuthFlowOutcome::CODE_TERMS_UNPUBLISHED;
        }
        if ($accepted === null) {
            return AuthFlowOutcome::CODE_CONSENT_REQUIRED;
        }
        if (count($accepted) !== count($current) || array_diff_assoc($current, $accepted) !== []) {
            return AuthFlowOutcome::CODE_CONSENT_REVISED;
        }

        return null;
    }

    /**
     * @param ?array<string, string> $accepted Submitted or reserved document-to-revision boundary map
     * @return ?AuthFlowOutcome Consent step refusal, or null when the first send may proceed
     * @throws LegalException When the catalog cannot be read
     */
    public static function refusal(?array $accepted): ?AuthFlowOutcome
    {
        $code = self::refusalCode($accepted);
        if ($code === null) {
            return null;
        }

        return AuthFlowOutcome::rejectTo($code, AuthFlowStep::CONSENT, AuthFlowIntent::REGISTER, match ($code) {
            AuthFlowOutcome::CODE_TERMS_UNPUBLISHED => AuthMessages::TERMS_UNPUBLISHED,
            AuthFlowOutcome::CODE_CONSENT_REVISED => AuthMessages::CONSENT_REVISED,
            default => null,
        });
    }
}
