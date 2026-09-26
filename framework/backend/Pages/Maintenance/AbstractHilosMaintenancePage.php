<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance;

use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\View\Item\VerifierCircleMember;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleAddActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleRemoveActionDTO;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;

/**
 * AbstractHilosMaintenancePage - Abstract base for the Hilos maintenance section (HIL-1119).
 *
 * The first admin section with no on-switch: the freeze under it is core and unconditional, so a
 * project activates the section by registering its page and binding {@see HilosVerifierCircleTable}
 * to it in the topology, with no line in FEATURES (docs/agents/architecture/admin-features.md).
 *
 * The page declares two actions: naming a verifier to the circle (HIL-1120) and taking one out
 * (HIL-1121). The circle's window rides the page response by itself because the table is bound to
 * the page; a named person arrives as a row of that table, and one taken out leaves as a row of the
 * same table. The page is served by the hilos index agent ({@see AbstractHilosIndexAgent}), which is also the one
 * that owns the circle.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Maintenance\MaintenancePage).
 */
abstract class AbstractHilosMaintenancePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MAINTENANCE;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::MAINTENANCE_CIRCLE_ADD => MaintenanceCircleAddActionDTO::class,
        HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE => MaintenanceCircleRemoveActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE,
    ];

    /**
     * Routes the verifier-circle actions of the section.
     *
     * @param string $acceptKey WebSocket accept key for the client (unused: the circle is not per connection)
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Always null: the ack carries the success sentence and the row arrives over the table
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the address cannot be named to the circle, or the membership
     *     being taken out is no longer there
     * @throws HilosException When an identity lookup, the circle read, the circle write or the delete fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::MAINTENANCE_CIRCLE_ADD:
                if (!$dto instanceof MaintenanceCircleAddActionDTO) {
                    throw new InvalidActionPayloadException($action, MaintenanceCircleAddActionDTO::class, $dto);
                }
                $this->handleCircleAdd($dto);

                break;

            case HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE:
                if (!$dto instanceof MaintenanceCircleRemoveActionDTO) {
                    throw new InvalidActionPayloadException($action, MaintenanceCircleRemoveActionDTO::class, $dto);
                }
                $this->handleCircleRemove($dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Names one more person to the verifier circle, by the address the operator typed.
     *
     * The address is brought to the form it is stored in by the rule the sign-in uses
     * ({@see IdentifierDetector::normalize()}): an address lowercased, a number reduced to E.164.
     * A number typed the way people write one - with spaces, dashes or a 00 prefix - would
     * otherwise miss the identity that carries it and be refused as proven by nobody.
     *
     * The address is then resolved to an identity rather than taken as a string, because the
     * circle is a list of people: what is stored is the (type, identifier) pair off the identity
     * that carries it, which is the same pair the freeze resolves back to a person when the
     * database under it has been replaced. An address nobody has proven names nobody, and is
     * refused with that in as many words - there is nothing to disclose here that the operator,
     * who is an administrator of this installation, could not read off the users page anyway.
     *
     * One person is one row. The freeze lets people in, not addresses
     * ({@see VerifierCircleSnapshot::capture()}), so a second address of somebody already named
     * lets nobody new in; all it adds is a second row of the same person, which inflates the count
     * of the named and carries a live mark that is re-drawn on one of the two rows only
     * ({@see HilosVerifierCircleTable}). Such an address is refused and the one already standing
     * is named in the refusal.
     *
     * That check is a read before the write, and two admin tabs naming one person by two
     * addresses in the same second both pass it; the result is two rows of one person, which the
     * section already knows how to show. No index closes it: the circle does not store who a row
     * names, because a user id would name somebody else once an archive is in place. The same
     * address twice is caught from the write as well as from the read - the UNIQUE index is the
     * only gate two tabs cannot fit between.
     *
     * Written from this page and not sent to an agent: the row is a database row, and the agent
     * that serves this page is the one that claims the table ({@see AbstractHilosIndexAgent}) - the
     * arrangement the settings page writes under.
     *
     * @param MaintenanceCircleAddActionDTO $dto Add action payload carrying the typed address
     * @throws TableActionException When the address is empty, not an address, unproven, already in
     *     the circle, or belongs to somebody already in the circle
     * @throws HilosException When an identity lookup or the circle write fails
     */
    private function handleCircleAdd(MaintenanceCircleAddActionDTO $dto): void
    {
        if ($dto->identifier === '') {
            throw new TableActionException('An address is required');
        }

        try {
            $identifier = IdentifierDetector::normalize($dto->identifier, IdentifierDetector::kindOf($dto->identifier));
        } catch (InvalidFormatException) {
            throw new TableActionException('Enter an email address or a phone number');
        }

        $identityType = Hilos::$db->identities->findVerifiedTypeByIdentifier($identifier);
        if ($identityType === null) {
            throw new TableActionException('Nobody has proven this address');
        }

        if (Hilos::$db->verifierCircle->findByIdentity($identityType, $identifier) !== null) {
            throw new TableActionException('This address is already in the circle');
        }

        $ownerId = Hilos::$db->identities->findByIdentity($identityType, $identifier)?->userId;
        if ($ownerId === null) {
            throw new TableActionException('Nobody has proven this address');
        }

        $member = $this->memberOf($ownerId);
        if ($member !== null) {
            throw new TableActionException("This person is already in the circle as {$member->identifier}");
        }

        try {
            Hilos::$db->verifierCircle->actions->add($identityType, $identifier);
        } catch (DuplicateValueException) {
            throw new TableActionException('This address is already in the circle');
        }

        $this->setActionSuccessMessage("{$identifier} added to the circle.");
    }

    /**
     * Takes one person out of the verifier circle, by the key the table handed out.
     *
     * The membership goes by its key, not by the address printed beside it: the key names the
     * row the operator looked at, whatever the screen happened to display.
     *
     * A key that names no row is refused, and that differs from the backup page on purpose
     * (AbstractHilosBackupPage::handleCircleRemove() answers it with a silent success, HIL-643).
     * The key is gone because somebody else took the membership out, or took it out and named the
     * same address again under a new key. A success would credit this operator with another's
     * work, and in the second case would call removed an address that stands in the circle. The
     * confirmation dialog holds its row in focus and says the row is gone before anybody presses
     * anything, so the refusal answers only a press that overtook that frame.
     *
     * Written from this page and not sent to an agent, for the reason adding is: the agent that
     * serves this page is the one that claims the table ({@see AbstractHilosIndexAgent}), so the
     * read and the delete are one turn of that agent with nothing between them.
     *
     * @param MaintenanceCircleRemoveActionDTO $dto Remove action payload carrying the membership key
     * @throws TableActionException When the membership is no longer in the circle
     * @throws HilosException When the circle lookup or the delete fails
     */
    private function handleCircleRemove(MaintenanceCircleRemoveActionDTO $dto): void
    {
        $member = Hilos::$db->verifierCircle[$dto->memberId] ?? null;
        if ($member === null) {
            throw new TableActionException('This verifier is no longer in the circle');
        }

        $identifier = $member->identifier;
        $member->actions->delete();

        $this->setActionSuccessMessage("{$identifier} removed from the circle.");
    }

    /**
     * Finds the circle row a person already stands in, by any of their addresses.
     *
     * The person's addresses are walked in the order their identities were stored, and the first
     * one the circle names is the answer - the walk the circle table re-draws a live mark by.
     *
     * @param int $userId Person whose addresses are looked up
     * @return ?VerifierCircleMember The row naming the person, or null when none of their addresses is in the circle
     * @throws HilosException When the identity or circle lookup fails
     */
    private function memberOf(int $userId): ?VerifierCircleMember
    {
        foreach (Hilos::$db->identities->listByUser($userId) as $identity) {
            $member = Hilos::$db->verifierCircle->findByIdentity($identity->type, $identity->identifier);
            if ($member !== null) {
                return $member;
            }
        }

        return null;
    }
}
