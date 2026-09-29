<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Chat demo binding of the framework current-user profile page.
 *
 * The framework owns the page identity (key, route, subscription signal) and its answer - the
 * person's sections and the danger zone (HIL-1169). This concrete binds the chat agent, whose
 * browser data carries the self-connection the live name is read from, and the browser lists
 * behind the summaries only chat knows: the ways in, the active sign-ins and the push devices.
 *
 * It is a READING surface (HIL-771). Every submit that writes a person lives where those tables
 * are owned: the rename on {@see UsersLibraryAgent}, the ways in and the email change on the
 * framework's users library (HIL-1137). Starting a provider link belongs to ProfileSignInPage.
 *
 * @property ChatAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    /**
     * @var list<string> The framework page's list and the sessions the active sign-ins summary
     *     counts; it replaces the parent's, so it carries the parent's whole.
     */
    public const array READS_DB = [...parent::READS_DB, ChatDbContext::sessions];

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;
}
