<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\Auth\OAuth\OAuthConfigField;
use Hilos\Auth\OAuth\OAuthConfigResolver;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Item\OAuthProvider;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Security\DTO\HilosOAuthProviderFieldReplyDTO;
use Hilos\Pages\Security\DTO\HilosOAuthProviderResetActionDTO;
use Hilos\Pages\Security\DTO\HilosOAuthProviderSetActionDTO;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;

/**
 * AbstractHilosSecurityOAuthProviderPage - one OAuth provider's configuration (HIL-286).
 *
 * Owns the two writes of a provider's fields: set one (client id, scope, secret) and take
 * one back to its env/recipe value. The fields are this framework's own collection,
 * hilos_oauth_provider, and the agent serving the page holds it
 * ({@see AbstractHilosIndexAgent::OWNS_DB}), so the page writes the row itself - there is
 * no other owner to ask. What the provider runs on is shown by the tables bound to the page
 * ({@see HilosSecurityOAuthProviderFieldsTable}), which redraw off the same write.
 *
 * The secret is write-only: it is accepted here and replaced, and nothing on the way back
 * carries it - the reply and the table row say only whether one is in force.
 *
 * The route's provider is not read here. The tables are narrowed to it on the screen's
 * side, and a provider the project does not declare shows as an empty screen, not as a
 * server error; an action naming one is refused like any other bad input.
 *
 * The page is an admin surface: the ADMIN access level inherited from AbstractHilosPage
 * closes its subscription and every action. Projects add a concrete subclass with a
 * `SUBSCRIPTION_AGENT_TYPE`; they add no action code of their own.
 */
abstract class AbstractHilosSecurityOAuthProviderPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::SECURITY_OAUTH_PROVIDER_SET => HilosOAuthProviderSetActionDTO::class,
        HilosSignalConstants::SECURITY_OAUTH_PROVIDER_RESET => HilosOAuthProviderResetActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY_OAUTH_PROVIDER,
    ];

    /** Longest value a field holds: the width of its column in hilos_oauth_provider. */
    private const int VALUE_MAX_LENGTH = 255;

    /**
     * Routes the provider field set and reset actions to typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Where the written field stands now
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the provider or field is unknown, or the value is refused
     * @throws HilosException Whatever reading or writing the provider's row raises
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::SECURITY_OAUTH_PROVIDER_SET:
                if (!$dto instanceof HilosOAuthProviderSetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosOAuthProviderSetActionDTO::class, $dto);
                }

                return $this->handleSet($dto);

            case HilosSignalConstants::SECURITY_OAUTH_PROVIDER_RESET:
                if (!$dto instanceof HilosOAuthProviderResetActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosOAuthProviderResetActionDTO::class, $dto);
                }

                return $this->handleReset($dto);

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Stores one field of one provider, bringing the provider's row into being if it has none.
     *
     * @param HilosOAuthProviderSetActionDTO $dto Set action payload
     * @return HilosOAuthProviderFieldReplyDTO Where the field stands now
     * @throws TableActionException When the provider or field is unknown, or the value is empty or too long
     * @throws HilosException Whatever reading or writing the provider's row raises
     */
    private function handleSet(HilosOAuthProviderSetActionDTO $dto): HilosOAuthProviderFieldReplyDTO
    {
        $descriptor = $this->requireProvider($dto->providerKey);
        $field = $this->requireField($dto->field);

        $value = trim($dto->value);
        if ($value === '') {
            throw new TableActionException("{$field->label()} cannot be empty. Reset it to fall back to the environment value.");
        }
        if (mb_strlen($value) > self::VALUE_MAX_LENGTH) {
            throw new TableActionException("{$field->label()} cannot be longer than " . self::VALUE_MAX_LENGTH . ' characters.');
        }

        $this->write($this->providerRow($descriptor) ?? $this->db()->oauthProviders->actions->add($descriptor->key), $field, $value);
        $this->setActionSuccessMessage("{$descriptor->label} {$field->label()} saved.");

        return $this->reply($descriptor, $field);
    }

    /**
     * Takes one field of one provider back to its env/recipe value.
     *
     * A provider with no row has nothing stored to clear, and the reset answers as done.
     *
     * @param HilosOAuthProviderResetActionDTO $dto Reset action payload
     * @return HilosOAuthProviderFieldReplyDTO Where the field stands now
     * @throws TableActionException When the provider or field is unknown
     * @throws HilosException Whatever reading or writing the provider's row raises
     */
    private function handleReset(HilosOAuthProviderResetActionDTO $dto): HilosOAuthProviderFieldReplyDTO
    {
        $descriptor = $this->requireProvider($dto->providerKey);
        $field = $this->requireField($dto->field);

        $row = $this->providerRow($descriptor);
        if ($row !== null) {
            $this->write($row, $field, null);
        }
        $this->setActionSuccessMessage("{$descriptor->label} {$field->label()} is back to its default.");

        return $this->reply($descriptor, $field);
    }

    /**
     * Writes one field of a provider's row, or clears it with null.
     *
     * @param OAuthProvider $row Provider row
     * @param OAuthConfigField $field Field to write
     * @param ?string $value New value, or null to clear it
     * @throws HilosException Whatever the row's write raises
     */
    private function write(OAuthProvider $row, OAuthConfigField $field, ?string $value): void
    {
        match ($field) {
            OAuthConfigField::CLIENT_ID => $row->actions->updateClientId($value),
            OAuthConfigField::SCOPE => $row->actions->updateScope($value),
            OAuthConfigField::CLIENT_SECRET => $row->actions->writeClientSecret($value),
        };
    }

    /**
     * Resolves where a field stands after a write, for the reply.
     *
     * @param OAuthProviderDescriptor $descriptor Provider the field belongs to
     * @param OAuthConfigField $field Field that was written
     * @return HilosOAuthProviderFieldReplyDTO Where the field stands now (value null for the secret)
     * @throws HilosException Whatever resolving the field raises
     */
    private function reply(OAuthProviderDescriptor $descriptor, OAuthConfigField $field): HilosOAuthProviderFieldReplyDTO
    {
        $resolved = new OAuthConfigResolver()->resolve($descriptor, $field);

        return new HilosOAuthProviderFieldReplyDTO(
            providerKey: $descriptor->key,
            field: $field->value,
            source: $resolved->source->value,
            value: $resolved->value,
            setState: $resolved->isSet,
        );
    }

    /**
     * Resolves a declared provider by key.
     *
     * @param string $providerKey Provider key
     * @return OAuthProviderDescriptor Provider descriptor
     * @throws TableActionException When the project does not declare the provider
     */
    private function requireProvider(string $providerKey): OAuthProviderDescriptor
    {
        return $this->providerDirectoryClass()::get($providerKey)
            ?? throw new TableActionException("Unknown OAuth provider: {$providerKey}");
    }

    /**
     * The directory the page takes its providers from.
     *
     * A seam the framework reads from the project facade; tests bind their own directory.
     *
     * @return class-string<OAuthProviderDirectory> Directory class
     */
    protected function providerDirectoryClass(): string
    {
        return Hilos::oauthProviderDirectoryClass();
    }

    /**
     * Resolves a provider field by name.
     *
     * @param string $field Field name
     * @return OAuthConfigField Field
     * @throws TableActionException When no provider field carries the name
     */
    private function requireField(string $field): OAuthConfigField
    {
        return OAuthConfigField::tryFrom($field) ?? throw new TableActionException("Unknown field: {$field}");
    }

    /**
     * Reads a provider's row, or null when nothing has been stored for it yet.
     *
     * @param OAuthProviderDescriptor $descriptor Provider
     * @return ?OAuthProvider Provider row, or null
     * @throws TableActionException When no framework database is mounted
     * @throws HilosException Whatever the row lookup raises
     */
    private function providerRow(OAuthProviderDescriptor $descriptor): ?OAuthProvider
    {
        return $this->db()->oauthProviders[$descriptor->key];
    }

    /**
     * The framework database context the provider rows live in.
     *
     * @return HilosDbContext Database context
     * @throws TableActionException When no framework database is mounted
     */
    private function db(): HilosDbContext
    {
        $db = Hilos::$db;
        if (!$db instanceof HilosDbContext) {
            throw new TableActionException('OAuth provider configuration is not available');
        }

        return $db;
    }
}
