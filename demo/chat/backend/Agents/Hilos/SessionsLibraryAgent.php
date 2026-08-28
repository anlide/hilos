<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Agents\ChatAgent;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;

/**
 * The chat demo's sessions library - and it is empty on purpose (HIL-710).
 *
 * Everything a session is went into {@see AbstractSessionsLibraryAgent} whole: resolving a
 * handshake cookie, rotating a token, raising a session to a person and reverting it. The
 * one seam a project can be asked to answer is minting the first administrator, and this
 * demo has a login of its own to do it through.
 *
 * {@see CliCommands::ADMIN_CREATE} is still mounted here - the mount stands on the abstract
 * class, so every subclass inherits it - and an operator who types it at this installation
 * gets the refusing default. That refusal is the point rather than a gap: it is the honest
 * answer to a command aimed at a demo that mints its administrators through its own sign-in.
 *
 * What stayed in {@see ChatAgent} is the other half of the seam: who is on the wire, what
 * that person is called, and the tab that has to be told. The library says what a session
 * became; the chat agent says it out loud.
 *
 * Registered under {@see HilosAgentType::HILOS_SESSIONS_LIBRARY} by the chat's own topology,
 * which is also what makes the handshake arrive here rather than in the chat agent.
 */
final class SessionsLibraryAgent extends AbstractSessionsLibraryAgent
{
}
