<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Security;

use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Pages\Security\AbstractHilosSecurityOAuthProviderPage;
use Hilos\Pages\Security\DTO\HilosOAuthProviderResetActionDTO;
use Hilos\Pages\Security\DTO\HilosOAuthProviderSetActionDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what the OAuth provider page refuses before it touches a row (HIL-286).
 *
 * Every refusal here is a domain phrase the screen shows in a toast, and every one of them
 * is decided without a database: a provider the project does not declare, a field that is
 * not a provider field, an empty value, and a value wider than its column.
 */
final class HilosSecurityOAuthProviderPageTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-oauth-provider-page';

    public function testAProviderTheProjectDoesNotDeclareIsRefused(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown OAuth provider: oauth:gitlab');

        $this->set('oauth:gitlab', 'client_id', 'x');
    }

    public function testAFieldThatIsNotAProviderFieldIsRefused(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown field: authorize_url');

        $this->set(OAuthProviderPreset::GITHUB->value, 'authorize_url', 'https://evil.example');
    }

    public function testAnEmptyValueIsRefusedWithTheWayBack(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Client secret cannot be empty. Reset it to fall back to the environment value.');

        $this->set(OAuthProviderPreset::GITHUB->value, 'client_secret', "  \n");
    }

    public function testAValueWiderThanItsColumnIsRefused(): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Client ID cannot be longer than 255 characters.');

        $this->set(OAuthProviderPreset::GITHUB->value, 'client_id', str_repeat('a', 256));
    }

    public function testAResetOfAnUnknownFieldIsRefused(): void
    {
        $this->expectException(TableActionException::class);

        new ProviderPageTestPage(new ProviderPageTestAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SECURITY_OAUTH_PROVIDER_RESET,
            new HilosOAuthProviderResetActionDTO(OAuthProviderPreset::GITHUB->value, 'redirect_uri'),
        );
    }

    /**
     * Sends one set action to the page.
     *
     * @param string $providerKey Provider key
     * @param string $field Field name
     * @param string $value Value as sent
     * @throws TableActionException When the page refuses it
     */
    private function set(string $providerKey, string $field, string $value): void
    {
        new ProviderPageTestPage(new ProviderPageTestAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SECURITY_OAUTH_PROVIDER_SET,
            new HilosOAuthProviderSetActionDTO($providerKey, $field, $value),
        );
    }
}

/**
 * Concrete provider page over the test directory: the abstract one carries the whole behavior.
 */
final class ProviderPageTestPage extends AbstractHilosSecurityOAuthProviderPage
{
    /**
     * @return class-string<OAuthProviderDirectory> The test directory
     */
    protected function providerDirectoryClass(): string
    {
        return ProviderPageTestDirectory::class;
    }
}

/**
 * A directory with GitHub alone.
 */
final class ProviderPageTestDirectory extends OAuthProviderDirectory
{
    /**
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected static function providers(): array
    {
        return array_replace(parent::providers(), [
            OAuthProviderPreset::GITHUB->value => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GITHUB, 'GitHub'),
        ]);
    }
}

/**
 * Page agent carrying only what a page may reach for: its id and its signal source.
 */
final class ProviderPageTestAgent implements PageAgentInterface
{
    public function getId(): string
    {
        return 'hilos_index';
    }

    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}
