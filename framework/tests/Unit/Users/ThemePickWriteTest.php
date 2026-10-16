<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserSessionsRestateSignalData;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;
use PHPUnit\Framework\TestCase;

/** Restating a theme is an announcement; a failed announcement cannot refuse a completed write. */
final class ThemePickWriteTest extends TestCase
{
    /** A successful write announces before completing its coordinator's action. */
    public function testSuccessfulWritingRestatesThePersonAndAnswers(): void
    {
        $agent = new ThemePickWriteTestAgent('42');
        $this->write($agent);

        self::assertSame('dark', $agent->written);
        self::assertSame([HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE, 'theme_done'], array_keys($agent->sent));
        self::assertInstanceOf(UserSessionsRestateSignalData::class, $agent->sent[HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE]);
        self::assertSame(42, $agent->sent[HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE]->userId);
        self::assertInstanceOf(UserThemePickWriteDoneSignalData::class, $agent->sent['theme_done']);
        self::assertNull($agent->sent['theme_done']->error);
    }

    /** A rejected write completes as a refusal and announces nothing. */
    public function testARefusedWriteDoesNotRestate(): void
    {
        $agent = new ThemePickWriteTestAgent('42');
        $agent->refuseWrite = true;
        $this->write($agent);

        self::assertNull($agent->written);
        self::assertSame(['theme_done'], array_keys($agent->sent));
        self::assertSame('Theme refused', $agent->sent['theme_done']->error);
    }

    /** The coordinator still receives success when the restate transport rejects its frame. */
    public function testARefusedRestateIsLoggedAndKeepsTheSuccessfulAnswer(): void
    {
        $agent = new ThemePickWriteTestAgent('42');
        $agent->refuseRestate = true;
        $this->write($agent);

        self::assertSame('dark', $agent->written);
        self::assertSame(['theme_done'], array_keys($agent->sent));
        self::assertNull($agent->sent['theme_done']->error);
        self::assertCount(1, $agent->errors);
        self::assertStringContainsString('restate for #42 failed', $agent->errors[0]);
    }

    /**
     * @param ThemePickWriteTestAgent $agent Writer under test
     */
    private function write(ThemePickWriteTestAgent $agent): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(data: new UserThemePickWriteSignalData(42, 'dark', 'theme_done', 'key', null, 'theme', null)),
            '',
            HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE,
        );
    }
}

/** Replaces the write and transport, keeping the real owner's outcome handling. */
final class ThemePickWriteTestAgent extends AbstractUserAgent
{
    public ?string $written = null;
    public bool $refuseWrite = false;
    public bool $refuseRestate = false;
    /** @var array<string, SignalDataInterface> Frames queued, in sending order */
    public array $sent = [];
    /** @var list<string> Announcement failures */
    public array $errors = [];

    /**
     * @param string $signalName Frame name
     * @param SignalDataInterface $data Frame payload
     * @throws InvalidArgumentException When the restate transport is refused by this case
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        if ($this->refuseRestate && $signalName === HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE) {
            throw new InvalidArgumentException('Restate transport refused');
        }
        $this->sent[$signalName] = $data;
    }

    /**
     * @param ?string $themePick Requested choice or reset
     * @throws ValidationException When the write is refused by this case
     */
    protected function writeThemePick(?string $themePick): void
    {
        if ($this->refuseWrite) {
            throw new ValidationException('Theme refused');
        }
        $this->written = $themePick;
    }

    /** @param string $message Failure to record */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}
