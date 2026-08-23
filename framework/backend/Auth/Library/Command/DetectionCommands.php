<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Library\DTO\DetectIdentifierActionDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\HilosException;

/**
 * The one read behind the single identifier field (HIL-414, HIL-622).
 *
 * A group of one command, and it stays its own group because it is the only sign-in
 * command that writes nothing and sends nothing: everything else here proves something or
 * spends something. Keeping it apart is what makes that visible.
 */
final class DetectionCommands extends AbstractLibraryCommands
{
    /**
     * Looks a typed identifier up and answers what the surface should offer for it.
     *
     * The person types, and this says whether that address or number signs in, registers,
     * or is already waiting on a code somebody asked for earlier. Nothing is written and
     * nothing is sent, so it is safe to ask on every keystroke the debounce lets through -
     * what makes asking expensive is the throttle window this action is listed in, which
     * is what stands in for the generic answers this epic gave up.
     *
     * The methods it may name are the PROJECT's, not every method the framework knows:
     * naming one the project has no handler for would put a button on the surface whose
     * submit is refused.
     *
     * The session goes with the question because the only hold that may show up in the
     * answer is this browser's own (HIL-608): another browser registering the address
     * does not take it, and reporting that would make this an oracle for who is signing
     * up right now.
     *
     * @param string $acceptKey Accept key the action arrived on
     * @param DetectIdentifierActionDTO $dto Parsed lookup payload (identifier)
     * @return IdentifierDetection What is behind the identifier and what can be done with it
     * @throws ItemNotFoundForUpdateException When the acting connection has no session
     * @throws InvalidFormatException When the identifier is neither an email address nor a phone number
     * @throws HilosException When the identity or reservation lookup fails
     */
    public function detectIdentifier(string $acceptKey, DetectIdentifierActionDTO $dto): IdentifierDetection
    {
        $acting = $this->acting($acceptKey);

        return $this->library->authMethods()->detect($dto->identifier, $acting->sessionToken);
    }
}
