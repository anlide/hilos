<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Auth\CodeChannel\CodeChannel;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Database\Verification\VerificationType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Mail\MailTransportConfig;

/**
 * CodeDeliveryAvailability - what this installation can deliver a one-time code to (HIL-830).
 *
 * The question a deployment with no mail relay and no phone channel could not be
 * asked before: it knew nothing was configured only after a person typed an address,
 * pressed a button and waited at a code screen for a letter that was never going to
 * arrive. This answers the same thing up front, and cheaply enough to ride the
 * handshake every browser opens.
 *
 * The answer is PER IDENTIFIER KIND and not one installation-wide flag. Mail wired
 * with no phone channel, and a phone channel with no relay, are both ordinary
 * deployments, and a single boolean would be wrong on one of them - so an address and
 * a number are asked about separately, and the surface withdraws only the path that
 * cannot finish.
 *
 * It is derived from the transports the framework already owns, never declared by the
 * project. What a project declares is which methods it OFFERS; whether the plumbing
 * under an offered method exists is a fact the framework reads from its own mail
 * config and its own code-channel registry, and asking a project to restate it would
 * invite the two to disagree.
 *
 * Every answer here fails OPEN. An installation whose configuration cannot be read
 * counts as able to deliver, because the cost of the two mistakes is not symmetric: a
 * wrong yes offers a registration that may stall, which is what every deployment did
 * before this class existed, while a wrong no silently withdraws registration from an
 * installation that works.
 */
final class CodeDeliveryAvailability
{
    /** Wire key for whether a code can be delivered to an email address. */
    private const string FIELD_EMAIL = 'email';

    /** Wire key for whether a code can be delivered to a phone number. */
    private const string FIELD_PHONE = 'phone';

    /**
     * Whether a one-time code sent to an identifier of this kind would reach anybody.
     *
     * The two kinds are the whole of what an identifier can classify as - there is no
     * `unknown` one to fall through to - so an address is asked about by name and
     * everything else is the number.
     *
     * @param string $kind Identifier kind (see IdentifierDetection::KIND_*)
     * @return bool True when this installation has a transport for that kind
     */
    public function canDeliverTo(string $kind): bool
    {
        return $kind === IdentifierDetection::KIND_EMAIL
            ? $this->mailReachesARelay()
            : $this->aPhoneChannelIsConfigured();
    }

    /**
     * The whole answer in the shape the handshake carries it.
     *
     * @return array{email: bool, phone: bool} Deliverability per identifier kind
     */
    public function toArray(): array
    {
        return [
            self::FIELD_EMAIL => $this->canDeliverTo(IdentifierDetection::KIND_EMAIL),
            self::FIELD_PHONE => $this->canDeliverTo(IdentifierDetection::KIND_PHONE),
        ];
    }

    /**
     * Whether the resolved mail transport talks to a relay rather than writing a file.
     *
     * The file transport is the framework's fallback for a checkout with no relay: it
     * writes a .eml a guest will never open, and with no directory configured it
     * writes nothing at all. Either way an address is not a way to reach the person in
     * front of the form (owner's decision, 06.09.2026).
     *
     * Unless the mode is DECLARED, in which case the letter is readable and the ceremony
     * is meant to run (HIL-827): an installation that wrote out `MAIL_TRANSPORT=file`
     * with a directory mails nobody on purpose, the code screen says the letter was only
     * written, and the person reads the digits out of the artifact. Withdrawing
     * registration there would close the one flow the mode exists to keep working.
     *
     * @return bool True when mail leaves this installation, or is deliberately kept at home
     */
    private function mailReachesARelay(): bool
    {
        try {
            $config = MailTransportConfig::fromEnv();

            return !$config->usesFileTransport() || $config->isTestMode();
        } catch (HilosException) {
            return true;
        }
    }

    /**
     * Whether at least one registered code channel could carry a registration code.
     *
     * Three questions, all of them asked already elsewhere: the channel is configured,
     * it serves the verification type a phone registration mints, and it addresses a
     * number at all. A channel failing any of them is not a way for THIS flow to
     * reach a phone, however useful it is for another.
     *
     * @return bool True when a phone code has somewhere to go
     */
    private function aPhoneChannelIsConfigured(): bool
    {
        foreach (Hilos::codeChannelRegistryClass()::all() as $channel) {
            if ($this->carriesRegistrationCodes($channel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one channel is a live way to deliver a phone registration code.
     *
     * @param CodeChannel $channel Registered channel descriptor
     * @return bool True when the channel is configured and serves this flow's phone codes
     */
    private function carriesRegistrationCodes(CodeChannel $channel): bool
    {
        return $channel->isConfigured()
            && $channel->supportsType(VerificationType::SMS_LOGIN)
            && in_array(IdentifierDetection::KIND_PHONE, $channel->identifierKinds(), true);
    }
}
