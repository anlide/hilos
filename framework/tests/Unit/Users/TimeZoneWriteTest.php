<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserSessionsRestateSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteSignalData;
use PHPUnit\Framework\TestCase;

/** The owner writes before announcing; announcement failure cannot refuse a completed write. */
final class TimeZoneWriteTest extends TestCase
{
    /** The sequence and the address survive both an explicit choice and an automatic reset. */
    public function testSuccessfulWritingRestatesThePersonAndReturnsTheUnchangedAsk(): void
    {
        foreach (['Europe/Warsaw', null] as $timeZone) {
            $agent = new TimeZoneWriteTestAgent('42');
            $ask = $this->ask($timeZone);
            $before = $ask->toArray();
            $this->write($agent, $ask);

            self::assertSame($timeZone, $agent->written);
            self::assertSame(['write', HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE, 'time_zone_done'], $agent->events);
            self::assertSame([HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE, 'time_zone_done'], array_keys($agent->sent));
            self::assertInstanceOf(UserSessionsRestateSignalData::class, $agent->sent[HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE]);
            self::assertSame(42, $agent->sent[HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE]->userId);
            self::assertInstanceOf(UserTimeZoneWriteDoneSignalData::class, $agent->sent['time_zone_done']);
            self::assertNull($agent->sent['time_zone_done']->error);
            self::assertNull($agent->sent['time_zone_done']->errorType);
            self::assertNull($agent->sent['time_zone_done']->errorDetail);
            self::assertSame($ask, $agent->sent['time_zone_done']->ask);
            self::assertSame($before, $agent->sent['time_zone_done']->ask->toArray());
        }
    }

    /** A rejected write answers the same ask with public words and announces nothing. */
    public function testARefusedWriteDoesNotRestate(): void
    {
        $agent = new TimeZoneWriteTestAgent('42');
        $agent->refuseWrite = true;
        $ask = $this->ask('Europe/Warsaw');
        $this->write($agent, $ask);

        self::assertNull($agent->written);
        self::assertSame(['write', 'time_zone_done'], $agent->events);
        self::assertSame(['time_zone_done'], array_keys($agent->sent));
        self::assertSame('Time zone refused', $agent->sent['time_zone_done']->error);
        self::assertSame($ask, $agent->sent['time_zone_done']->ask);
    }

    /** A failed restate attempt is logged, then the coordinator still receives success. */
    public function testARefusedRestateIsLoggedAndKeepsTheSuccessfulAnswer(): void
    {
        $agent = new TimeZoneWriteTestAgent('42');
        $agent->refuseRestate = true;
        $ask = $this->ask('Europe/Warsaw');
        $this->write($agent, $ask);

        self::assertSame('Europe/Warsaw', $agent->written);
        self::assertSame(['write', HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE, 'log', 'time_zone_done'], $agent->events);
        self::assertSame(['time_zone_done'], array_keys($agent->sent));
        self::assertNull($agent->sent['time_zone_done']->error);
        self::assertSame($ask, $agent->sent['time_zone_done']->ask);
        self::assertSame(['Time zone restate for #42 failed: Restate transport refused'], $agent->errors);
    }

    /** An incompatible DTO cannot reach the writer. */
    public function testTheWriteSignalRefusesAnotherPayloadType(): void
    {
        $agent = new TimeZoneWriteTestAgent('42');
        try {
            $agent->onSignalAgent(
                new AgentSignalData(data: new UserThemePickWriteSignalData(42, 'dark', 'theme_done', 'key', null, 'theme', null)),
                '',
                HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE,
            );
            self::fail('The time-zone signal must reject a theme DTO');
        } catch (InvalidAgentSignalPayloadException) {
            self::assertSame([], $agent->events);
            self::assertSame([], $agent->sent);
        }
    }

    /** A mismatched address is refused before any write or answer. */
    public function testAnotherPersonCannotRetargetTheOwner(): void
    {
        $agent = new TimeZoneWriteTestAgent('41');
        try {
            $this->write($agent, $this->ask('Europe/Warsaw'));
            self::fail('The owner must reject an ask naming another person');
        } catch (AgentException $refusal) {
            self::assertStringContainsString('user #42 reached the agent of user #41', $refusal->getMessage());
            self::assertSame([], $agent->events);
            self::assertSame([], $agent->sent);
        }
    }

    /**
     * @param ?string $timeZone Requested choice or reset
     * @return UserTimeZoneWriteSignalData Ask with the coordinator's continuation intact
     */
    private function ask(?string $timeZone): UserTimeZoneWriteSignalData
    {
        return new UserTimeZoneWriteSignalData(42, $timeZone, 'time_zone_done', 'key', 'request-1', 'time_zone', 'Saved');
    }

    /**
     * @param TimeZoneWriteTestAgent $agent Owner under test
     * @param UserTimeZoneWriteSignalData $ask Requested choice and continuation
     */
    private function write(TimeZoneWriteTestAgent $agent, UserTimeZoneWriteSignalData $ask): void
    {
        $agent->onSignalAgent(new AgentSignalData(data: $ask), '', HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE);
    }
}

/** Replaces persistence and transport while retaining the production owner's outcome handling. */
final class TimeZoneWriteTestAgent extends AbstractUserAgent
{
    public ?string $written = null;
    public bool $refuseWrite = false;
    public bool $refuseRestate = false;
    /** @var array<string, SignalDataInterface> Frames successfully queued, in order */
    public array $sent = [];
    /** @var list<string> Write, transport and logging attempts, in order */
    public array $events = [];
    /** @var list<string> Announcement failures */
    public array $errors = [];

    /**
     * @param string $signalName Frame name
     * @param SignalDataInterface $data Frame payload
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        $this->events[] = $signalName;
        if ($this->refuseRestate && $signalName === HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE) {
            throw new InvalidArgumentException('Restate transport refused');
        }
        $this->sent[$signalName] = $data;
    }

    /** @param ?string $timeZone Requested choice or reset */
    protected function writeTimeZone(?string $timeZone): void
    {
        $this->events[] = 'write';
        if ($this->refuseWrite) {
            throw new ValidationException('Time zone refused');
        }
        $this->written = $timeZone;
    }

    /** @param string $message Failure to record */
    protected function logAgentError(string $message): void
    {
        $this->events[] = 'log';
        $this->errors[] = $message;
    }
}
