<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Security;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Pages\Security\AbstractHilosSecuritySignInMethodsPage;
use Hilos\Pages\Security\DTO\HilosPasskeyUnprovenSetActionDTO;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestHilos;
use Hilos\Tests\Unit\Auth\Method\Fixtures\AuthMethodTestUnservedHilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what the sign-in methods page refuses before it asks for a write (HIL-1105).
 *
 * The passkey policy decides nothing in a project that wired no passkey, so the page refuses
 * its switch there, with the words the method switch uses for a method nobody wired. The write
 * itself runs through the settings library and is covered where a database is.
 */
final class HilosSecuritySignInMethodsPageTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-sign-in-methods-page';

    protected function tearDown(): void
    {
        AuthMethodTestHilos::unmount();

        parent::tearDown();
    }

    public function testThePasskeyPolicyIsRefusedWhereNoPasskeyIsWired(): void
    {
        AuthMethodTestUnservedHilos::mount(null);

        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown sign-in method: passkey');

        new SignInMethodsPageTestPage(new SignInMethodsPageTestAgent())->onAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::SECURITY_PASSKEY_UNPROVEN_SET,
            new HilosPasskeyUnprovenSetActionDTO(true),
        );
    }

    public function testThePayloadCarriesTheFlagAsSent(): void
    {
        $dto = HilosPasskeyUnprovenSetActionDTO::fromArray([HilosPasskeyUnprovenSetActionDTO::allowed => true]);

        self::assertTrue($dto->allowed);
        self::assertSame(HilosSignalConstants::SECURITY_PASSKEY_UNPROVEN_SET, $dto->getAction());
        self::assertSame([HilosPasskeyUnprovenSetActionDTO::allowed => true], $dto->toArray());
    }
}

/**
 * Concrete sign-in methods page: the abstract one carries the whole behavior.
 */
final class SignInMethodsPageTestPage extends AbstractHilosSecuritySignInMethodsPage
{
}

/**
 * Page agent carrying only what a page may reach for: its id and its signal source.
 */
final class SignInMethodsPageTestAgent implements PageAgentInterface
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
