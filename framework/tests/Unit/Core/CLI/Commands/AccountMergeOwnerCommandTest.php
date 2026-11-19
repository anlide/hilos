<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AccountMergeOwnerCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\CommandExecutionSite;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Users\AccountMergeProgress;
use Hilos\Users\AccountMergeSummary;
use Hilos\Users\SecondFactorFate;
use Hilos\Users\SecondFactorOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Argument boundary, declarations, and reply rendering of the unregistered account:merge client.
 *
 * Invalid input returns before the command channel is opened. Rendering cases pin the prepared
 * reply: a strict completed tally, an open snapshot at exit 6, a terminal refusal at exit 1,
 * and an unknown outcome when no reply arrived.
 */
final class AccountMergeOwnerCommandTest extends TestCase
{
    private const string CANCEL_MESSAGE = 'The account merge was canceled before any accounts were merged.';

    /** @return array<string, array{array<string, mixed>, list<string>}> Forms that must not be sent */
    public static function invalidInvocations(): array
    {
        $operation = [AccountMergeCommandConstants::OPTION_OPERATION => '4'];
        $observe = [AccountMergeCommandConstants::OPTION_OBSERVE => true];
        $pair = ['7', '9'];

        return [
            'no arguments' => [[], []],
            'one argument' => [[], ['7']],
            'three arguments' => [[], ['7', '9', '11']],
            'word' => [[], ['7', 'nine']],
            'zero' => [[], ['0', '9']],
            'negative' => [[], ['-1', '9']],
            'blank' => [[], ['', '9']],
            'operation with pair' => [$operation, $pair],
            'operation with observe' => [$operation + $observe, []],
            'operation with password' => [$operation + [AccountMergeCommandConstants::OPTION_PASSWORD => PasswordFate::SURVIVOR->value], []],
            'operation with second factor' => [
                $operation + [AccountMergeCommandConstants::OPTION_SECOND_FACTOR => SecondFactorFate::BOTH->value],
                [],
            ],
            'observe with password' => [$observe + [AccountMergeCommandConstants::OPTION_PASSWORD => PasswordFate::NONE->value], $pair],
            'observe with second factor' => [
                $observe + [AccountMergeCommandConstants::OPTION_SECOND_FACTOR => SecondFactorFate::SURVIVOR->value],
                $pair,
            ],
            'observe without pair' => [$observe, []],
            'observe with one id' => [$observe, ['7']],
            'observe with a value' => [[AccountMergeCommandConstants::OPTION_OBSERVE => 'yes'], $pair],
            'bad password' => [[AccountMergeCommandConstants::OPTION_PASSWORD => 'keep'], $pair],
            'bad second factor' => [[AccountMergeCommandConstants::OPTION_SECOND_FACTOR => 'loser'], $pair],
            'operation word' => [[AccountMergeCommandConstants::OPTION_OPERATION => 'abc'], []],
            'operation zero' => [[AccountMergeCommandConstants::OPTION_OPERATION => '0'], []],
            'operation flag' => [[AccountMergeCommandConstants::OPTION_OPERATION => true], []],
            'unknown option' => [[AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_START], $pair],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $args
     */
    #[DataProvider('invalidInvocations')]
    public function testRejectsAFormThatIsNotOneOfTheThree(array $options, array $args): void
    {
        $command = new AccountMergeOwnerCommandSpy();
        $this->expectOutputRegex('/^Usage:/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute($options, $args));
        self::assertNull($command->sentPayload);
        self::assertSame(0, $command->sends);
    }

    /** @return array<string, array{string, bool}> Option name and whether OPTIONS declares it */
    public static function declaredOptions(): array
    {
        return [
            'password' => [AccountMergeCommandConstants::OPTION_PASSWORD, true],
            'second factor' => [AccountMergeCommandConstants::OPTION_SECOND_FACTOR, true],
            'observe' => [AccountMergeCommandConstants::OPTION_OBSERVE, true],
            'operation' => [AccountMergeCommandConstants::OPTION_OPERATION, true],
            'mode' => [AccountMergeCommandConstants::FIELD_MODE, false],
        ];
    }

    #[DataProvider('declaredOptions')]
    public function testOptionsSiteNameAndDatabaseFree(string $option, bool $declared): void
    {
        $command = new AccountMergeOwnerCommand();

        self::assertSame($declared, in_array($option, $command::OPTIONS, true));
        self::assertSame(CliCommands::ACCOUNT_MERGE, $command->getName());
        self::assertSame(CommandExecutionSite::DAEMON, $command->execution()->site);
        self::assertNull($command->execution()->reason);
        self::assertInstanceOf(DatabaseFreeCommand::class, $command);
    }

    /** @return array<string, array{array<string, mixed>, list<string>, array<string, int|string>}> */
    public static function requestShapes(): array
    {
        return [
            'start with fates' => [
                [
                    AccountMergeCommandConstants::OPTION_PASSWORD => PasswordFate::LOSER->value,
                    AccountMergeCommandConstants::OPTION_SECOND_FACTOR => SecondFactorFate::BOTH->value,
                ],
                ['7', '9'],
                [
                    AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_START,
                    AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => 7,
                    AccountMergeCommandConstants::FIELD_LOSER_USER_ID => 9,
                    AccountMergeCommandConstants::FIELD_PASSWORD_FATE => PasswordFate::LOSER->value,
                    AccountMergeCommandConstants::FIELD_SECOND_FACTOR_FATE => SecondFactorFate::BOTH->value,
                ],
            ],
            'start unnamed' => [
                [],
                ['7', '9'],
                [
                    AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_START,
                    AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => 7,
                    AccountMergeCommandConstants::FIELD_LOSER_USER_ID => 9,
                ],
            ],
            'observe operation' => [
                [AccountMergeCommandConstants::OPTION_OPERATION => '15'],
                [],
                [
                    AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
                    AccountMergeCommandConstants::FIELD_OPERATION_ID => 15,
                ],
            ],
            'observe pair' => [
                [AccountMergeCommandConstants::OPTION_OBSERVE => true],
                ['7', '9'],
                [
                    AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_OBSERVE,
                    AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => 7,
                    AccountMergeCommandConstants::FIELD_LOSER_USER_ID => 9,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $args
     * @param array<string, int|string> $payload
     */
    #[DataProvider('requestShapes')]
    public function testSendsTheThreeRequestShapes(array $options, array $args, array $payload): void
    {
        $command = new AccountMergeOwnerCommandSpy();
        $this->expectOutputString('');
        self::assertSame(ExitCode::ERROR, $command->execute($options, $args));
        self::assertSame(CliCommands::ACCOUNT_MERGE, $command->sentCommand);
        self::assertSame($payload, $command->sentPayload);
        self::assertSame(1, $command->sends);
    }

    /** @return array<string, array{array<string, mixed>, list<string>, CommandReplyDTO, string}> */
    public static function completedReplies(): array
    {
        $loserStayed = self::summary(2, ['messages' => 4], PasswordFate::LOSER, SecondFactorOutcome::BOTH);
        $noneLeft = self::summary(3, [], PasswordFate::NONE, SecondFactorOutcome::SURVIVOR);
        $survivorStayed = self::summary(1, ['notes' => 2], PasswordFate::SURVIVOR, SecondFactorOutcome::SURVIVOR);

        return [
            'actual loser fate' => [
                [],
                ['7', '9'],
                self::replied(self::progress(
                    AccountMergeProgress::END,
                    AccountMergeStage::Go->value,
                    AccountOperationOutcome::Completed->value,
                    PasswordFate::SURVIVOR,
                    null,
                    null,
                    [self::part(true), self::part(true, 7)],
                    [],
                    given: true,
                    received: true,
                ), $loserStayed->toArray()),
                self::identity(PasswordFate::SURVIVOR->value, null)
                    . "Reply (ok): merged user #9 into #7 (2 identities, 4 messages moved)\n"
                    . "Password: the loser's password stayed and now opens the account\n"
                    . 'Second factor: ' . SecondFactorOutcome::BOTH->describe() . "\n",
            ],
            'unnamed choice is not none' => [
                [],
                ['7', '9'],
                self::replied(self::progress(
                    AccountMergeProgress::END,
                    AccountMergeStage::Go->value,
                    AccountOperationOutcome::Completed->value,
                    null,
                    null,
                    null,
                    [self::part(true)],
                    [],
                    given: true,
                    received: true,
                ), $noneLeft->toArray()),
                self::identity(null, null)
                    . "Reply (ok): merged user #9 into #7 (3 identities moved)\n"
                    . "Password: the account has no password; it can be set in the profile\n"
                    . 'Second factor: ' . SecondFactorOutcome::SURVIVOR->describe() . "\n",
            ],
            'observe by id' => [
                [AccountMergeCommandConstants::OPTION_OPERATION => '15'],
                [],
                self::replied(self::progress(
                    AccountMergeProgress::END,
                    AccountMergeStage::Go->value,
                    AccountOperationOutcome::Completed->value,
                    PasswordFate::SURVIVOR,
                    SecondFactorFate::BOTH,
                    null,
                    [self::part(true)],
                    [],
                    given: true,
                    received: true,
                ), $survivorStayed->toArray()),
                self::identity(PasswordFate::SURVIVOR->value, SecondFactorFate::BOTH->value)
                    . "Reply (ok): merged user #9 into #7 (1 identities, 2 notes moved)\n"
                    . "Password: the survivor's password stayed\n"
                    . 'Second factor: ' . SecondFactorOutcome::SURVIVOR->describe() . "\n",
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $args
     */
    #[DataProvider('completedReplies')]
    public function testACompletedEndPrintsTheTallyAndTheActualFates(
        array $options,
        array $args,
        CommandReplyDTO $reply,
        string $output,
    ): void {
        $command = new AccountMergeOwnerCommandSpy();
        $command->result = self::channel($reply);
        $this->expectOutputString($output);
        self::assertSame(ExitCode::SUCCESS, $command->execute($options, $args));
        self::assertSame('', $command->standardError);
        self::assertStringNotContainsString('password none', $output);
        self::assertStringNotContainsString('The latest recorded merge', $output);
    }

    /** @return array<string, array{CommandReplyDTO, int, string, string}> Pair observations of operation 15 */
    public static function pairObservations(): array
    {
        $lead = "The latest recorded merge for this pair is operation 15.\n";
        $unnamed = self::identity(null, null);
        $choices = self::identity(PasswordFate::SURVIVOR->value, SecondFactorFate::BOTH->value);

        return [
            'completed tally' => [
                self::replied(self::progress(
                    AccountMergeProgress::END,
                    AccountMergeStage::Go->value,
                    AccountOperationOutcome::Completed->value,
                    PasswordFate::SURVIVOR,
                    SecondFactorFate::BOTH,
                    null,
                    [self::part(true)],
                    [],
                    given: true,
                    received: true,
                ), self::summary(1, ['notes' => 2], PasswordFate::SURVIVOR, SecondFactorOutcome::SURVIVOR)->toArray()),
                ExitCode::SUCCESS,
                $lead . $choices
                    . "Reply (ok): merged user #9 into #7 (1 identities, 2 notes moved)\n"
                    . "Password: the survivor's password stayed\n"
                    . 'Second factor: ' . SecondFactorOutcome::SURVIVOR->describe() . "\n",
                '',
            ],
            'open snapshot' => [
                self::replied(self::progress(
                    AccountMergeProgress::PREPARING,
                    AccountMergeStage::Preparing->value,
                    null,
                )),
                ExitCode::TIMEOUT,
                $lead . $unnamed . "preparing: 0 of 1 parts done\nObserve it with account:merge --operation=15\n",
                '',
            ],
            'not started' => [
                self::replied(self::progress(
                    AccountMergeProgress::END,
                    AccountMergeStage::Cancelling->value,
                    AccountOperationOutcome::NotStarted->value,
                ), null, self::CANCEL_MESSAGE),
                ExitCode::ERROR,
                $lead . $unnamed,
                'Refused: ' . self::CANCEL_MESSAGE . "\n",
            ],
        ];
    }

    #[DataProvider('pairObservations')]
    public function testAnObservedPairNamesTheLatestRecordedMerge(
        CommandReplyDTO $reply,
        int $exitCode,
        string $output,
        string $standardError,
    ): void {
        $command = new AccountMergeOwnerCommandSpy();
        $command->result = self::channel($reply);
        $this->expectOutputString($output);
        self::assertSame(
            $exitCode,
            $command->execute([AccountMergeCommandConstants::OPTION_OBSERVE => true], ['7', '9']),
        );
        self::assertSame($standardError, $command->standardError);
        self::assertStringContainsString('operation 15', $output);
    }

    /** @return array<string, array{CommandReplyDTO, string}> Open snapshots asked by the pair 7 9 */
    public static function openReplies(): array
    {
        $unnamed = self::identity(null, null);

        return [
            'preparing' => [
                self::replied(self::progress(
                    AccountMergeProgress::PREPARING,
                    AccountMergeStage::Preparing->value,
                    null,
                )),
                $unnamed . "preparing: 0 of 1 parts done\nObserve it with account:merge --operation=15\n",
            ],
            'wait' => [
                self::replied(self::progress(
                    AccountMergeProgress::WAIT,
                    AccountMergeStage::Go->value,
                    null,
                    PasswordFate::SURVIVOR,
                    SecondFactorFate::BOTH,
                    null,
                    [self::part(true), self::part(false)],
                    [self::wait()],
                )),
                self::identity(PasswordFate::SURVIVOR->value, SecondFactorFate::BOTH->value)
                    . "wait go: 1 of 2 parts done; waiting for notes (user 9)\n"
                    . "Observe it with account:merge --operation=15\n",
            ],
            'cancelling' => [
                self::replied(self::progress(
                    AccountMergeProgress::WAIT,
                    AccountMergeStage::Cancelling->value,
                    null,
                )),
                $unnamed . "wait cancelling: 0 of 1 parts done\nObserve it with account:merge --operation=15\n",
            ],
            'refusal before release' => [
                self::replied(self::progress(
                    AccountMergeProgress::REFUSAL,
                    AccountMergeStage::Go->value,
                    null,
                    refusal: self::refusal(),
                )),
                $unnamed . "refusal go: 0 of 1 parts done\n"
                    . AccountMergeRefusal::PasswordFateRequired->template() . "\n"
                    . "Observe it with account:merge --operation=15\n",
            ],
        ];
    }

    #[DataProvider('openReplies')]
    public function testAnOpenSnapshotAsksToBeObserved(CommandReplyDTO $reply, string $output): void
    {
        $command = new AccountMergeOwnerCommandSpy();
        $command->result = self::channel($reply);
        $this->expectOutputString($output);
        self::assertSame(ExitCode::TIMEOUT, $command->execute([], ['7', '9']));
        self::assertSame('', $command->standardError);
        self::assertStringNotContainsString('Refused:', $output);
        self::assertStringNotContainsString('The latest recorded merge', $output);
    }

    /** @return array<string, array{array<string, mixed>, list<string>, CommandReplyDTO, string, string}> */
    public static function terminalReplies(): array
    {
        $identity = self::identity(null, null);
        $cancel = self::replied(self::progress(
            AccountMergeProgress::END,
            AccountMergeStage::Cancelling->value,
            AccountOperationOutcome::NotStarted->value,
        ), null, self::CANCEL_MESSAGE);
        $refused = self::replied(self::progress(
            AccountMergeProgress::REFUSAL,
            AccountMergeStage::Cancelling->value,
            AccountOperationOutcome::NotStarted->value,
            refusal: self::refusal(),
        ));

        return [
            'canceled before it started' => [
                [],
                ['7', '9'],
                $cancel,
                $identity,
                'Refused: ' . self::CANCEL_MESSAGE,
            ],
            'terminal business refusal' => [
                [],
                ['7', '9'],
                $refused,
                $identity,
                'Refused: ' . AccountMergeRefusal::PasswordFateRequired->template(),
            ],
            'daemon error reply' => [
                [AccountMergeCommandConstants::OPTION_OPERATION => '15'],
                [],
                CommandReplyDTO::error('cid', 'No saved merge has that operation id.'),
                '',
                'Refused: No saved merge has that operation id.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $args
     */
    #[DataProvider('terminalReplies')]
    public function testATerminalRefusalExitsWithTheDaemonSentence(
        array $options,
        array $args,
        CommandReplyDTO $reply,
        string $output,
        string $standardError,
    ): void {
        $command = new AccountMergeOwnerCommandSpy();
        $command->result = self::channel($reply);
        $this->expectOutputString($output);
        self::assertSame(ExitCode::ERROR, $command->execute($options, $args));
        self::assertSame($standardError . "\n", $command->standardError);
        self::assertStringNotContainsString('identities', $output);
        self::assertStringNotContainsString('The latest recorded merge', $output);
    }

    /** @return array<string, array{?CommandChannelResult, array<string, mixed>, list<string>, int, string, string}> */
    public static function lostReplies(): array
    {
        $seconds = (int)CommandChannelWindows::CALLER_WAIT_SECONDS;
        $unknown = "The merge outcome is unknown; the channel failure did not cancel it.\n";
        $timedOut = "The daemon did not answer account:merge within {$seconds}s\n" . $unknown;
        $unreachable = "Cannot reach the daemon command channel at 127.0.0.1:8094\n" . $unknown;

        return [
            'start timed out' => [
                CommandChannelResult::timedOut('127.0.0.1:8094'),
                [],
                ['7', '9'],
                ExitCode::ERROR,
                $timedOut . "account:merge 7 9 --observe\n",
                '',
            ],
            'start unreachable' => [
                CommandChannelResult::unreachable('127.0.0.1:8094'),
                [],
                ['7', '9'],
                ExitCode::ERROR,
                $unreachable . "account:merge 7 9 --observe\n",
                '',
            ],
            'operation timed out' => [
                CommandChannelResult::timedOut('127.0.0.1:8094'),
                [AccountMergeCommandConstants::OPTION_OPERATION => '15'],
                [],
                ExitCode::ERROR,
                $timedOut . "account:merge --operation=15\n",
                '',
            ],
            'operation unreachable' => [
                CommandChannelResult::unreachable('127.0.0.1:8094'),
                [AccountMergeCommandConstants::OPTION_OPERATION => '15'],
                [],
                ExitCode::ERROR,
                $unreachable . "account:merge --operation=15\n",
                '',
            ],
            'missing channel' => [
                null,
                [],
                ['7', '9'],
                ExitCode::CONFIG_ERROR,
                '',
                "Error: missing command channel\n",
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $args
     */
    #[DataProvider('lostReplies')]
    public function testALostReplyLeavesTheOutcomeUnknown(
        ?CommandChannelResult $result,
        array $options,
        array $args,
        int $exitCode,
        string $standardError,
        string $output,
    ): void {
        $command = new AccountMergeOwnerCommandSpy();
        if ($result === null) {
            $command->envFailure = new EnvException('missing command channel');
        } else {
            $command->result = $result;
        }
        $this->expectOutputString($output);
        self::assertSame($exitCode, $command->execute($options, $args));
        self::assertSame($standardError, $command->standardError);
        self::assertSame(1, $command->sends);
        self::assertStringNotContainsString('merged', $command->standardError);
        self::assertStringNotContainsString('canceled', $command->standardError);
    }

    /** @return array<string, array{array<string, mixed>, list<string>, CommandReplyDTO, string}> */
    public static function unreadableReplies(): array
    {
        $pair = ['7', '9'];
        $start = [];
        $unreadable = 'The account merge reply cannot be read.';
        $different = 'The account merge reply names a different operation.';
        $completed = self::progress(
            AccountMergeProgress::END,
            AccountMergeStage::Go->value,
            AccountOperationOutcome::Completed->value,
            given: true,
            received: true,
        );
        $broken = $completed->toArray();
        $broken[AccountMergeProgress::outcome] = null;
        $hidden = $completed->toArray();
        $hidden['hidden'] = true;
        $badSummary = self::summary(2, ['messages' => 1], PasswordFate::SURVIVOR, SecondFactorOutcome::NONE)->toArray();
        $badSummary[AccountMergeCommandConstants::FIELD_IDENTITIES_MOVED] = '2';

        return [
            'malformed merge' => [
                $start,
                $pair,
                CommandReplyDTO::ok('cid', [
                    AccountMergeCommandConstants::FIELD_MERGE => 'hidden',
                    AccountMergeCommandConstants::FIELD_SUMMARY => null,
                ]),
                $unreadable,
            ],
            'hidden field' => [
                $start,
                $pair,
                CommandReplyDTO::ok('cid', [
                    AccountMergeCommandConstants::FIELD_MERGE => $hidden,
                    AccountMergeCommandConstants::FIELD_SUMMARY => null,
                ]),
                $unreadable,
            ],
            'inconsistent outcome' => [
                $start,
                $pair,
                CommandReplyDTO::ok('cid', [
                    AccountMergeCommandConstants::FIELD_MERGE => $broken,
                    AccountMergeCommandConstants::FIELD_SUMMARY => null,
                ]),
                $unreadable,
            ],
            'completed without summary' => [
                $start,
                $pair,
                self::replied($completed, null),
                $unreadable,
            ],
            'summary is not strict' => [
                $start,
                $pair,
                self::replied($completed, $badSummary),
                $unreadable,
            ],
            'summary on an open merge' => [
                $start,
                $pair,
                self::replied(
                    self::progress(AccountMergeProgress::PREPARING, AccountMergeStage::Preparing->value, null),
                    self::summary(0, [], PasswordFate::NONE, SecondFactorOutcome::NONE)->toArray(),
                ),
                $unreadable,
            ],
            'missing summary field' => [
                $start,
                $pair,
                CommandReplyDTO::ok('cid', [
                    AccountMergeCommandConstants::FIELD_MERGE => $completed->toArray(),
                ]),
                $unreadable,
            ],
            'pair mismatch' => [
                $start,
                $pair,
                self::replied(self::progress(
                    AccountMergeProgress::END,
                    AccountMergeStage::Go->value,
                    AccountOperationOutcome::Completed->value,
                    loserUserId: 8,
                    given: true,
                    received: true,
                ), self::summary(2, [], PasswordFate::SURVIVOR, SecondFactorOutcome::NONE)->toArray()),
                $different,
            ],
            'operation mismatch' => [
                [AccountMergeCommandConstants::OPTION_OPERATION => '16'],
                [],
                self::replied($completed, self::summary(2, [], PasswordFate::SURVIVOR, SecondFactorOutcome::NONE)->toArray()),
                $different,
            ],
            'completed with a message' => [
                $start,
                $pair,
                self::replied(
                    $completed,
                    self::summary(2, ['notes' => 1], PasswordFate::SURVIVOR, SecondFactorOutcome::NONE)->toArray(),
                    'finished',
                ),
                $unreadable,
            ],
            'negative identity count' => [
                $start,
                $pair,
                self::replied(
                    $completed,
                    self::summary(-1, ['notes' => 1], PasswordFate::SURVIVOR, SecondFactorOutcome::NONE)->toArray(),
                ),
                $unreadable,
            ],
            'negative row count' => [
                $start,
                $pair,
                self::replied(
                    $completed,
                    self::summary(1, ['notes' => -1], PasswordFate::SURVIVOR, SecondFactorOutcome::NONE)->toArray(),
                ),
                $unreadable,
            ],
            'unknown key' => [
                $start,
                $pair,
                CommandReplyDTO::ok('cid', [
                    AccountMergeCommandConstants::FIELD_MERGE => $completed->toArray(),
                    AccountMergeCommandConstants::FIELD_SUMMARY => self::summary(
                        1,
                        [],
                        PasswordFate::NONE,
                        SecondFactorOutcome::NONE,
                    )->toArray(),
                    'hidden' => true,
                ]),
                $unreadable,
            ],
            'message on an open refusal' => [
                $start,
                $pair,
                self::replied(
                    self::progress(
                        AccountMergeProgress::REFUSAL,
                        AccountMergeStage::Go->value,
                        null,
                        refusal: self::refusal(),
                    ),
                    null,
                    'still open',
                ),
                $unreadable,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $args
     */
    #[DataProvider('unreadableReplies')]
    public function testAnUnreadableOrForeignReplyFailsClosed(
        array $options,
        array $args,
        CommandReplyDTO $reply,
        string $standardError,
    ): void {
        $command = new AccountMergeOwnerCommandSpy();
        $command->result = self::channel($reply);
        $this->expectOutputString('');
        self::assertSame(ExitCode::ERROR, $command->execute($options, $args));
        self::assertSame($standardError . "\n", $command->standardError);
        self::assertStringNotContainsString('identities', $command->standardError);
    }

    /** @return array<string, array{string}> Phrases the help owes */
    public static function helpPhrases(): array
    {
        return [
            'bounded wait' => [(int)CommandChannelWindows::CALLER_WAIT_SECONDS . 's'],
            'distributed' => ['distributed'],
            'preparing' => ['preparing'],
            'refusal' => ['refusal'],
            'cancelling' => ['cancelling'],
            'durable end' => ['durable end'],
            'ctrl+c' => ['Ctrl+C'],
            'operation' => ['--operation'],
            'observe' => ['--observe'],
            'does not cancel' => ['does not cancel'],
        ];
    }

    #[DataProvider('helpPhrases')]
    public function testHelpDescribesTheBoundWaitAndTheDistributedEnd(string $phrase): void
    {
        $help = (new AccountMergeOwnerCommand())->getHelp();

        self::assertStringContainsString($phrase, $help);
        self::assertStringNotContainsString('one transaction', $help);
        self::assertStringNotContainsString('--mode', $help);
    }

    /**
     * @param list<array{participant: string, owner: string, userId: int, done: bool}>|null $parts
     * @param list<array{participant: string, userId: int}>|null $waits
     * @param ?array{code: string, reason: string} $refusal
     */
    private static function progress(
        string $state,
        string $stage,
        ?string $outcome,
        ?PasswordFate $password = null,
        ?SecondFactorFate $secondFactor = null,
        ?array $refusal = null,
        ?array $parts = null,
        ?array $waits = null,
        int $operationId = 15,
        int $survivorUserId = 7,
        int $loserUserId = 9,
        bool $given = false,
        bool $received = false,
    ): AccountMergeProgress {
        return new AccountMergeProgress(
            $operationId,
            $survivorUserId,
            $loserUserId,
            $state,
            $stage,
            $outcome,
            $given,
            $received,
            new AccountMergeChoice($password, $secondFactor, null, null),
            $refusal,
            $parts ?? [self::part(false)],
            $waits ?? [],
        );
    }

    /** @param array<string, int> $rowsMoved */
    private static function summary(
        int $identitiesMoved,
        array $rowsMoved,
        PasswordFate $passwordKept,
        SecondFactorOutcome $secondFactorKept,
    ): AccountMergeSummary {
        return new AccountMergeSummary($identitiesMoved, $rowsMoved, $passwordKept, $secondFactorKept);
    }

    /**
     * @param ?array<string, mixed> $summary
     */
    private static function replied(AccountMergeProgress $progress, ?array $summary = null, ?string $message = null): CommandReplyDTO
    {
        $payload = [
            AccountMergeCommandConstants::FIELD_MERGE => $progress->toArray(),
            AccountMergeCommandConstants::FIELD_SUMMARY => $summary,
        ];
        if ($message !== null) {
            $payload[CommandConstants::FIELD_MESSAGE] = $message;
        }

        return CommandReplyDTO::ok('cid', $payload);
    }

    private static function channel(CommandReplyDTO $reply): CommandChannelResult
    {
        return CommandChannelResult::replied($reply, '127.0.0.1:8094');
    }

    private static function identity(?string $password, ?string $secondFactor): string
    {
        $passwordWord = $password ?? 'not specified';
        $secondFactorWord = $secondFactor ?? 'not specified';

        return "Operation 15, pair 7 9, password {$passwordWord}, second factor {$secondFactorWord}\n";
    }

    /** @return array{participant: string, owner: string, userId: int, done: bool} */
    private static function part(bool $done, int $userId = 9): array
    {
        return [
            AccountMergeProgress::participant => 'notes',
            AccountMergeProgress::owner => 'library',
            AccountMergeProgress::userId => $userId,
            AccountMergeProgress::done => $done,
        ];
    }

    /** @return array{participant: string, userId: int} */
    private static function wait(int $userId = 9): array
    {
        return [
            AccountMergeProgress::participant => 'notes',
            AccountMergeProgress::userId => $userId,
        ];
    }

    /** @return array{code: string, reason: string} */
    private static function refusal(): array
    {
        return [
            AccountMergeProgress::code => AccountMergeRefusal::PasswordFateRequired->value,
            AccountMergeProgress::reason => AccountMergeRefusal::PasswordFateRequired->template(),
        ];
    }
}

/**
 * The command with its one outward call stood in: what it would have put on the wire is captured
 * instead, a canned round-trip comes back, and stderr is kept for the case to read.
 */
final class AccountMergeOwnerCommandSpy extends AccountMergeOwnerCommand
{
    /** @var ?string Wire name the command sent, or null when it refused before sending */
    public ?string $sentCommand = null;

    /** @var ?array<string, mixed> Payload the command sent, or null when it refused before sending */
    public ?array $sentPayload = null;

    /** @var int How many times the command opened the channel */
    public int $sends = 0;

    /** @var ?CommandChannelResult Round-trip handed back to the command */
    public ?CommandChannelResult $result = null;

    /** @var ?EnvException Failure raised instead of a round-trip */
    public ?EnvException $envFailure = null;

    /** @var string Text sent to stderr by the shared printers and the command */
    public string $standardError = '';

    /**
     * Captures the round-trip instead of opening a socket.
     *
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @param ?float $waitSeconds Wait budget (ignored)
     * @return CommandChannelResult Canned round-trip
     * @throws EnvException When the case stands in for a missing channel address
     */
    protected function sendCommand(
        string $command,
        array $payload,
        ?float $waitSeconds = null,
    ): CommandChannelResult {
        $this->sends++;
        $this->sentCommand = $command;
        $this->sentPayload = $payload;
        if ($this->envFailure !== null) {
            throw $this->envFailure;
        }

        return $this->result ?? CommandChannelResult::unreachable('127.0.0.1:8094');
    }

    /** Captures stderr output instead of writing it during the suite. */
    protected function writeToStandardError(string $text): void
    {
        $this->standardError .= $text . "\n";
    }
}
