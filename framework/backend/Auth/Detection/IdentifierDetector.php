<?php

declare(strict_types=1);

namespace Hilos\Auth\Detection;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\PhoneNumber;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Hilos;

/**
 * IdentifierDetector - answers what a typed identifier means (HIL-414).
 *
 * The mechanism behind the identifier-first surface: one field is offered, and
 * what appears under it - a password, a code, a way to register - is decided by
 * looking the identifier up while it is being typed. This is that lookup. It is a
 * pure READ: it neither creates nor extends a registration hold, and it sends no
 * letter and no code, so a surface may call it on every keystroke the debounce
 * lets through.
 *
 * The order of the two questions matters and is not an implementation detail:
 * the account is asked about FIRST and a hold only after, because an account can
 * appear on top of a live hold (an OAuth sign-in with the same address), and
 * answering `pending` then would park a person who already has a way in on a code
 * screen for a registration that will never complete.
 *
 * The hold it asks about is the ASKING BROWSER's, never anybody else's (HIL-608):
 * a registration somebody else started does not take the address, and reporting it
 * would turn this endpoint into an oracle for "is anyone signing up with this
 * address right now" - a question the account lookup below is deliberately open
 * about and this one has no reason to answer at all.
 *
 * What a project ENABLES is an input, not a decision made here: the constructor
 * takes the method keys this project offers and every answer is intersected with
 * them, so a method switched off (or never wired) cannot be named to a surface
 * that has nowhere to send it. The registry of enabled methods is HIL-427; until
 * it exists a project assembles the set itself.
 *
 * One class of key is dropped no matter what a project enables: `oauth:*` never
 * appears in an answer (HIL-419). Provider buttons live on an empty field and are
 * gone by the first typed character, so naming them once an identifier exists
 * told nobody anything - except whoever typed somebody else's address, who was
 * told which provider its owner uses. The rationale in full, and why it locks no
 * one out, sits on {@see self::accountMethods}.
 *
 * This endpoint IS the account-enumeration surface, deliberately so - the epic
 * traded anti-enumeration for a usable single field. What keeps that trade honest
 * is the throttle layer (HIL-420) on the action in front of it, not vagueness
 * here.
 */
final class IdentifierDetector
{
    /**
     * @param list<string> $enabledMethodKeys Method keys this project offers, in the order a surface should show them (see AuthMethodKey)
     */
    public function __construct(private readonly array $enabledMethodKeys)
    {
    }

    /**
     * Classifies a submitted identifier as an address or a number.
     *
     * An address is decided first: it is the only one of the two whose shape is
     * unambiguous, and no valid address survives the phone normalizer anyway.
     *
     * Static and public because the question outlives the lookup that first asked
     * it (HIL-486): the handshake tells a returning tab what KIND of identifier its
     * unfinished registration is on, and the only honest answer is the one this
     * surface gave when the identifier was typed. A second copy of these two lines
     * elsewhere would be a second opinion about the same string.
     *
     * @param string $identifier Identifier as submitted
     * @return string Classification (see IdentifierDetection::KIND_*)
     * @throws InvalidFormatException When the identifier is neither
     */
    public static function kindOf(string $identifier): string
    {
        $trimmed = trim($identifier);
        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false) {
            return IdentifierDetection::KIND_EMAIL;
        }
        if (PhoneNumber::normalize($trimmed) !== null) {
            return IdentifierDetection::KIND_PHONE;
        }

        throw new InvalidFormatException('Enter an email address or a phone number');
    }

    /**
     * Looks an identifier up and reports what the surface should offer for it.
     *
     * @param string $identifier Identifier as submitted; echoed back verbatim
     * @param string $sessionToken Session cookie token of the asking browser, whose own hold may be reported
     * @return IdentifierDetection What is behind the identifier and what can be done with it
     * @throws InvalidFormatException When the identifier is neither an email address nor a phone number
     * @throws DatabaseException When an identity or reservation query fails
     * @throws LogicException When the identities or reservations object collection is unavailable
     */
    public function detect(string $identifier, string $sessionToken): IdentifierDetection
    {
        $kind = self::kindOf($identifier);
        $normalized = $this->normalize($identifier, $kind);

        $userId = $this->findAccountId($kind, $normalized);
        if ($userId !== null) {
            return IdentifierDetection::owned($identifier, $normalized, $kind, $this->accountMethods($userId, $kind));
        }

        if (new RegistrationReservationService()->findActiveForSession($sessionToken)?->identifier === $normalized) {
            return IdentifierDetection::held($identifier, $normalized, $kind);
        }

        return IdentifierDetection::free($identifier, $normalized, $kind, $this->registerableMethods($kind));
    }

    /**
     * Reduces a classified identifier to the form the identity layer stores.
     *
     * @param string $identifier Identifier as submitted
     * @param string $kind Classification from {@see self::kindOf()}
     * @return string Lowercased address, or an E.164 number
     * @throws InvalidFormatException When a number that classified stops normalizing
     */
    private function normalize(string $identifier, string $kind): string
    {
        if ($kind === IdentifierDetection::KIND_EMAIL) {
            return mb_strtolower(trim($identifier));
        }

        return PhoneNumber::normalize($identifier)
            ?? throw new InvalidFormatException('Enter an email address or a phone number');
    }

    /**
     * Resolves the account behind a normalized identifier, or null when there is none.
     *
     * An address is somebody's by the framework's one definition of that
     * ({@see ObjectIdentities::findAccountIdByEmail()}, HIL-608) - the same question a
     * project asks before starting a registration on it, and asked here through the
     * same method so the two can no longer answer differently. A number is somebody's
     * when it carries an `sms` identity.
     *
     * @param string $kind Classification (see IdentifierDetection::KIND_*)
     * @param string $normalized Identifier in its canonical form
     * @return ?int Owning user id, or null when the identifier is free
     * @throws DatabaseException When an identity query fails
     * @throws LogicException When the identities object collection is unavailable
     */
    private function findAccountId(string $kind, string $normalized): ?int
    {
        $identities = $this->identities();

        if ($kind === IdentifierDetection::KIND_PHONE) {
            return $identities->findByIdentity(IdentityType::SMS, $normalized)?->userId;
        }

        return $identities->findAccountIdByEmail($normalized);
    }

    /**
     * Lists the enabled methods this account can be signed in with.
     *
     * The account's, not the identifier's, and the promise now says so (HIL-692). It used
     * to promise "through THIS identifier" while counting by account, and the promise was
     * the wrong half: a password belongs to the person, so whichever of their addresses
     * was typed, it is the one they answer with. Kind still gates two keys below, but a
     * kind is a shape of identifier and not a particular one.
     *
     * Kind gates two of the keys, and both gates are the mockup's
     * (`hilos-ops/mockups/framework/guest/index.html`): a number never carries a
     * password ("телефон — только код"), and a sign-in link is mailed, so it is
     * offered for an address only. Naming either under a phone would reveal an
     * affordance whose submit the backend then refuses.
     *
     * An OAuth provider is never named here, whatever a project enables (HIL-419).
     * Its buttons stand on an EMPTY field and vanish with the first character
     * typed (the visibility revision of 01.08), so by the time an identifier is
     * detectable no surface is showing them any more and the answer had no
     * reader. What it did have was a cost: telling whoever typed somebody else's
     * address which provider that person signs in with. This is a filter and not
     * a shorter project registry - the enabled set stays whole for HIL-427, and
     * dropping the keys is detection's own decision.
     *
     * No one is locked out by it: an account is found by a VERIFIED email (or a
     * phone), so `magic_link` always applies to it, and a person who has only ever
     * used a provider still gets in by the mailed link.
     *
     * @param int $userId Owning user id
     * @param string $kind Classification (see IdentifierDetection::KIND_*)
     * @return list<string> Enabled method keys the account holds, in project order
     * @throws DatabaseException When the identity query fails
     * @throws LogicException When the identities object collection is unavailable
     */
    private function accountMethods(int $userId, string $kind): array
    {
        $identities = $this->identities()->listByUser($userId);

        $methods = [];
        foreach ($this->enabledMethodKeys as $methodKey) {
            if (str_starts_with($methodKey, AuthMethodKey::OAUTH_PREFIX)) {
                continue;
            }
            if ($this->accountHasMethod($identities, $methodKey, $kind)) {
                $methods[] = $methodKey;
            }
        }

        return $methods;
    }

    /**
     * Whether one enabled method key is available to an account under this identifier kind.
     *
     * OAuth keys never reach this method - {@see self::accountMethods} drops them
     * before the question is asked - so an unknown key falling through to `false`
     * here is a project naming a method this framework does not implement.
     *
     * @param list<ObjectIdentity> $identities Every identity the account owns
     * @param string $methodKey Enabled method key (see AuthMethodKey)
     * @param string $kind Classification (see IdentifierDetection::KIND_*)
     * @return bool True when the surface may offer this method
     */
    private function accountHasMethod(array $identities, string $methodKey, string $kind): bool
    {
        return match ($methodKey) {
            // A sign-in link needs no identity of its own - it is mailed to the address
            // that was typed, and the account is already known to answer at it, so
            // whether one was ever set up is not a question that exists here.
            AuthMethodKey::MAGIC_LINK => $kind === IdentifierDetection::KIND_EMAIL,
            // Any password row the account holds answers for every address it holds: an
            // account has at most one (HIL-692), and the sign-in reads it by account too.
            AuthMethodKey::PASSWORD => $kind === IdentifierDetection::KIND_EMAIL
                && $this->holdsType($identities, IdentityType::PASSWORD),
            AuthMethodKey::SMS => $kind === IdentifierDetection::KIND_PHONE,
            default => false,
        };
    }

    /**
     * Whether an account owns an identity of a type.
     *
     * @param list<ObjectIdentity> $identities Every identity the account owns
     * @param string $type Identity type (see IdentityType)
     * @return bool True when at least one identity has the type
     */
    private function holdsType(array $identities, string $type): bool
    {
        foreach ($identities as $identity) {
            if ($identity->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lists the enabled methods a free identifier can be registered with.
     *
     * @param string $kind Classification (see IdentifierDetection::KIND_*)
     * @return list<string> Enabled method keys registration is open with, in project order
     */
    private function registerableMethods(string $kind): array
    {
        $offered = $kind === IdentifierDetection::KIND_EMAIL
            ? [AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK]
            : [AuthMethodKey::SMS];

        return array_values(array_intersect($this->enabledMethodKeys, $offered));
    }

    /**
     * Resolves the framework-owned identities object collection.
     *
     * @return ObjectIdentities Identity persistence primitives
     * @throws LogicException When the collection is missing or misconfigured
     */
    private function identities(): ObjectIdentities
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::identities);
        if (!$collection instanceof ObjectIdentities) {
            throw new LogicException('Identities object collection is not configured');
        }

        return $collection;
    }
}
