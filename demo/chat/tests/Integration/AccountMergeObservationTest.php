<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\ChatBrowserData;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Demo\Chat\Pages\UserPage;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosUserTableRow;
use Hilos\Users\AccountMergeAvailability;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Users\AccountMergeSummary;
use Hilos\Users\AccountStandingKind;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\AdminCommandConstants;
use Hilos\Users\DTO\AdminRenameSignalData;
use Hilos\Utils\Helpers\RandomHelper;

/** Observers and protected actions after a live owner-coordinated merge, plus the independent passkey repair. */
final class AccountMergeObservationTest extends IntegrationTestCase
{
    use AccountMergeFrames;

    /** Repair played by hand, the way the migration runner would split the file. */
    private const string MIGRATION_FILE = '/backend/Database/Migration/Schema/069_repoint_merged_passkey_credentials.sql';

    /** Public key a seeded device key carries; the repair never verifies it. */
    private const string PUBLIC_KEY_PEM = "-----BEGIN PUBLIC KEY-----\nstub\n-----END PUBLIC KEY-----\n";

    /** Gives both the merge and the readers their production signal router. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new ChatSignalRouter());
    }

    /** The live command reports both moved identities and the message, then tombstones and blocks the loser. */
    public function testMergeMovesContentAndTombstonesLoser(): void
    {
        $survivorId = (int)Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int)Hilos::$db->users->actions->createWithName('Loser')->id;
        $emailA = 'loser-a-' . RandomHelper::hex(6) . '@example.test';
        $emailB = 'loser-b-' . RandomHelper::hex(6) . '@example.test';
        $identityA = Hilos::$db->identities->createMagicLinkIdentity($loserId, $emailA)->id;
        $identityB = Hilos::$db->identities->createMagicLinkIdentity($loserId, $emailB)->id;
        $messageId = (int)Hilos::$db->events->actions->addMessage('hello from loser', userId: $loserId)->id;

        $summary = $this->mergeOk($survivorId, $loserId);

        self::assertSame(2, $summary->identitiesMoved);
        self::assertSame([ChatCommandConstants::ROWS_MOVED_MESSAGES => 1], $summary->rowsMoved);
        self::assertSame(PasswordFate::NONE, $summary->passwordKept);
        foreach ([$emailA => $identityA, $emailB => $identityB] as $email => $identityId) {
            $identity = Hilos::$db->identities->findByIdentity(IdentityType::MAGIC_LINK, $email);
            self::assertNotNull($identity);
            self::assertSame($identityId, $identity->id);
            self::assertSame($survivorId, $identity->userId);
            $stored = Database::sql('SELECT `user_id` FROM `hilos_identity` WHERE `id` = ?', [$identityId])->firstRow();
            self::assertNotNull($stored);
            self::assertSame($survivorId, (int)$stored['user_id']);
        }
        self::assertSame($survivorId, Hilos::$db->eventMessages[$messageId]->authorUserId);
        $message = Database::sql('SELECT `author_user_id` FROM `event_message` WHERE `event_id` = ?', [$messageId])->firstRow();
        self::assertNotNull($message);
        self::assertSame($survivorId, (int)$message['author_user_id']);
        self::assertSame([], Database::sql('SELECT `event_id` FROM `event_message` WHERE `author_user_id` = ?', [$loserId])->rows());
        self::assertCount(1, Database::sql('SELECT `event_id` FROM `event_message` WHERE `author_user_id` = ?', [$survivorId])->rows());
        self::assertSame($survivorId, Hilos::$db->userMerges[$loserId]->survivorUserId);
        self::assertTrue(Hilos::$db->users[$loserId]->block);
    }

    /** The final command summary reports no password when the explicitly selected survivor has none. */
    public function testMergeReportsTheOutcomeAndNotTheWordThatWasTyped(): void
    {
        $survivorId = (int)Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int)Hilos::$db->users->actions->createWithName('Loser')->id;
        Hilos::$db->identities->createPasswordIdentity($loserId, 'loser-' . RandomHelper::hex(6) . '@example.test', 'loser secret')
            ->markVerified();

        $summary = $this->mergeOk($survivorId, $loserId, PasswordFate::SURVIVOR);

        self::assertSame(PasswordFate::NONE, $summary->passwordKept);
        self::assertNull(Hilos::$db->identities->findPasswordByUser($survivorId));
        self::assertSame([], Database::sql('SELECT `id` FROM `hilos_identity` WHERE `user_id` = ? AND `type` = ?',
            [$survivorId, IdentityType::PASSWORD])->rows());
    }

    /**
     * A merged administrator stays out of the administrators' circle once an administrator lifts
     * the block the merge left: the row still says admin, and the person it named is the
     * survivor now. The framework's circle leaves a merged account out by its merge table
     * (HIL-1199).
     *
     * @throws HilosException When setup or the merge fails
     */
    public function testAMergedAdministratorStaysOutOfTheCircleOnceUnblocked(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Merged administrator')->id;
        Hilos::$db->users[$loserId]->actions->setAdmin(true);
        $standingId = (int) Hilos::$db->users->actions->registerAdmin()->id;

        $this->mergeOk($survivorId, $loserId);
        Hilos::$db->users[$loserId]->actions->setBlock(false);

        $circle = Hilos::adminAudienceClass()::all();
        $this->assertContains($standingId, $circle);
        $this->assertNotContains($loserId, $circle);
    }

    /**
     * The repair hands an earlier merge's device key to the survivor and leaves a sound key alone.
     *
     * The broken row is the state from before HIL-1132: the anchor already names the survivor and
     * the key still names the loser. Read back through SQL, past the collection cache the insert
     * left behind.
     *
     * @throws HilosException When a seed or the repair fails
     */
    public function testTheRepairHandsAnEarlierMergesPasskeysToTheSurvivor(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;
        $thirdId = (int) Hilos::$db->users->actions->createWithName('Third')->id;
        $brokenId = $this->seedPasskey($survivorId, $loserId);
        $soundId = $this->seedPasskey($thirdId, $thirdId);

        $this->runMigration();

        $repaired = $this->passkeyUserId($brokenId);
        $untouched = $this->passkeyUserId($soundId);
        $this->assertSame($survivorId, $repaired);
        $this->assertSame($thirdId, $untouched);

        $this->runMigration();

        $this->assertSame($repaired, $this->passkeyUserId($brokenId));
        $this->assertSame($untouched, $this->passkeyUserId($soundId));
    }

    /**
     * After a merge the loser's standing says merged and where it went (HIL-1292): the survivor by
     * id and by name. A second merge of the survivor moves the loser's standing to the chain's
     * live end, and a rename of that account moves the name - with nobody telling the resolver to
     * forget anything: the writes themselves are the announcement.
     *
     * @throws HilosException When setup or a merge fails
     */
    public function testAMergedAccountsStandingNamesWhereItWentAndFollowsTheChain(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;
        $thirdId = (int) Hilos::$db->users->actions->createWithName('Third')->id;
        AccountStandingResolver::forgetAll();
        $this->assertSame(AccountStandingKind::NONE, AccountStandingResolver::of($loserId)->shown);

        $this->mergeOk($survivorId, $loserId);

        $standing = AccountStandingResolver::of($loserId);
        $this->assertSame(AccountStandingKind::MERGED, $standing->shown);
        $this->assertTrue($standing->blocked, 'The merge closed the sign-in with the block flag, and the flag stays named');
        $this->assertSame($survivorId, $standing->mergedInto);
        $this->assertSame('Survivor', $standing->mergedIntoName);
        $this->assertSame(AccountStandingKind::NONE, AccountStandingResolver::of($survivorId)->shown);

        $this->mergeOk($thirdId, $survivorId);
        $this->assertSame($thirdId, AccountStandingResolver::of($loserId)->mergedInto, 'The chain is followed to its live end');
        $this->assertSame('Third', AccountStandingResolver::of($loserId)->mergedIntoName);

        Hilos::$db->users[$thirdId]->actions->rename('Third Renamed');
        $this->assertSame('Third Renamed', AccountStandingResolver::of($loserId)->mergedIntoName);
    }

    /**
     * A merged account is neither renamed nor taken over (HIL-1292). Both refusals speak the merge
     * refusal's words: the rename as the exception the card turns into its fail ack, the takeover
     * as the operator's error reply - before any setting of the takeover is asked.
     *
     * @throws HilosException When setup or the merge fails
     */
    public function testTheRenameAndTheTakeoverOfAMergedAccountAreRefused(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;
        $admin = Hilos::$db->users->actions->createWithName('Administrator');
        $admin->actions->setAdmin(true);
        $adminId = (int) $admin->id;
        $this->mergeOk($survivorId, $loserId);

        $this->drainSignals();
        $this->usersLibrary()->onSignalAgent(
            new AgentSignalData(data: new AdminRenameSignalData(
                userId: $loserId,
                name: 'Somebody Else',
                replySignal: HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
                acceptKey: 'merge-rename-ak',
                requestId: null,
                action: HilosSignalConstants::HILOS_USER_UPDATE,
                successMessage: null,
                adminUserId: $adminId,
            )),
            '',
            HilosSignalConstants::HILOS_USER_ADMIN_RENAME,
        );
        // The library refuses before the hop, so the card is answered and the agent is not asked.
        $this->assertSame(0, $this->deliverPersonAgentFrames());
        $answer = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof HandoverAnswerSignalData) {
                $answer = $signal->data->data;
            }
        }
        $this->assertNotNull($answer, 'The card is answered');
        $this->assertSame('Failed to update user: ' . AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE, $answer->error);
        $this->assertSame('Loser', Hilos::$db->users[$loserId]->name);

        $token = RandomHelper::hex(16);
        Hilos::$db->sessions->actions->createAnonymous($token)->actions->bindUser($adminId);
        $library = $this->sessionsLibrary();
        ExecutionContext::run(
            new ExecutionFrame(agentId: $library->getId()),
            static function () use ($library, $token, $loserId): void {
                $library->onSignalCommand(new CommandRequestDTO(
                    correlationId: RandomHelper::hex(8),
                    command: CliCommands::IMPERSONATE_START,
                    payload: [
                        AdminCommandConstants::FIELD_SESSION_TOKEN => $token,
                        AdminCommandConstants::FIELD_TARGET_USER_ID => $loserId,
                    ],
                ), '', '');
            },
        );
        $reply = $this->consumeMergeReply();
        $this->assertFalse($reply->isOk(), 'The takeover of a merged account went through');
        $this->assertSame(
            AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE,
            $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    /**
     * The people list marks a merged account in its own inline merge slot and nowhere else
     * (HIL-1292): the flag is read off the merge table when the row is built, so the row rebuilt
     * for the block flag's change already says merged, and the users slot stays the person's row.
     *
     * @throws HilosException When setup or the merge fails
     */
    public function testThePeopleListMarksTheMergedAccountInItsMergeSlot(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;
        $this->mergeOk($survivorId, $loserId);

        $table = Hilos::$table->hilosUsers;
        $loserRow = $table->rowFromUser(Hilos::$db->users[$loserId]);
        $this->assertTrue($loserRow->merged);
        $this->assertFalse($table->rowFromUser(Hilos::$db->users[$survivorId])->merged);

        $sources = $table->browserRow($loserRow)[BrowserPageSignalData::sources];
        $this->assertSame([
            HilosUserTableRow::merged => true,
            HilosUserTableRow::FIELD_ACCOUNT_MERGE_AVAILABILITY => [
                AccountMergeAvailability::reserved => false,
                AccountMergeAvailability::available => false,
            ],
        ], $sources[AbstractHilosUsersTable::SLOT_MERGE]);
        $this->assertArrayNotHasKey(HilosUserTableRow::merged, $sources[AbstractHilosUsersTable::SLOT_USER]);
    }

    /**
     * The public page of a person carries the merge as data of its own (HIL-1292): nothing for an
     * account never merged, and for a merged one the tombstone's key, the live end of the chain and
     * that account's name - what the page draws in place of the sessions and the activity.
     *
     * @throws HilosException When setup, the merge or the subscription fails
     */
    public function testTheUserPageDataSaysWhereAMergedAccountWent(): void
    {
        $survivorId = (int) Hilos::$db->users->actions->createWithName('Survivor')->id;
        $loserId = (int) Hilos::$db->users->actions->createWithName('Loser')->id;
        Hilos::initBrowser();
        try {
            $this->assertNull($this->userMergeData($loserId));

            $this->mergeOk($survivorId, $loserId);

            $merge = $this->userMergeData($loserId);
            $this->assertNotNull($merge);
            $this->assertSame($loserId, $merge[ObjectUserMerge::userId]);
            $this->assertSame($survivorId, $merge[HilosUserTableRow::FIELD_MERGED_INTO]);
            $this->assertSame('Survivor', $merge[HilosUserTableRow::FIELD_MERGED_INTO_NAME]);
            $this->assertNull($this->userMergeData($survivorId), 'The survivor was folded into nobody');
        } finally {
            Hilos::initBrowser();
        }
    }

    /**
     * Subscribes a fresh connection to the public page of a person and reads the merge data its
     * answer carries.
     *
     * @param int $userId Person whose page is opened
     * @return ?array<string, mixed> The `userMerge` data, or null when the page carries none
     * @throws HilosException When the subscription fails
     */
    private function userMergeData(int $userId): ?array
    {
        $acceptKey = 'account-merge-page-' . $userId . '-' . RandomHelper::hex(4);
        $params = [UserPageSubscribeParams::USER_ID => (string) $userId];
        Hilos::$sr->subscribeToPage(UserPage::PAGE, new WebSocketPageSubscribeSignalDTO($acceptKey, UserPage::PAGE, $params));
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey, $params): void {
            new UserPage(new ChatAgent())->onSubscribe($acceptKey, new PageRouteParams($params));
        });
        $data = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE || !$signal->data instanceof WebSocketSignalData) {
                continue;
            }
            $data ??= $signal->data->data->toArray()[PageResponseSignalData::payload][PagePayload::data] ?? null;
        }
        $this->assertIsArray($data, 'The page answered without its data section');
        $merge = $data[ChatBrowserData::USER_MERGE] ?? null;

        return is_array($merge) && $merge !== [] ? $merge : null;
    }

    /**
     * Runs a merge that must go through and reads the summary back off the reply.
     *
     * @param int $survivorId Survivor user id that absorbs the loser
     * @param int $loserId Loser user id folded into the survivor
     * @param ?PasswordFate $passwordFate Whose password to keep, or null when nobody names one
     * @return AccountMergeSummary What the merge reported moving
     * @throws HilosException When the command handler fails or the reply is unreadable
     */
    private function mergeOk(int $survivorId, int $loserId, ?PasswordFate $passwordFate = null): AccountMergeSummary
    {
        $reply = $this->runOwnerMerge($survivorId, $loserId, $passwordFate);
        $this->assertTrue($reply->isOk(), 'The merge was refused: ' . json_encode($reply->payload));

        $this->assertSame('completed', $reply->payload[AccountMergeCommandConstants::FIELD_MERGE]['outcome']);

        return AccountMergeSummary::fromArray($reply->payload[AccountMergeCommandConstants::FIELD_SUMMARY]);
    }

    /** @return CommandReplyDTO The one refusal from the impersonation command */
    private function consumeMergeReply(): CommandReplyDTO
    {
        $replies = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        $this->assertCount(1, $replies);

        return $replies[0];
    }

    /**
     * Stores a device key whose anchor names one person and whose row names another.
     *
     * @param int $anchorUserId Person the sign-in anchor belongs to
     * @param int $keyUserId Person the key row is recorded for
     * @return string Credential id
     * @throws HilosException When the anchor or the key cannot be stored
     */
    private function seedPasskey(int $anchorUserId, int $keyUserId): string
    {
        $credentialId = RandomHelper::hex(16);
        $anchorId = Hilos::$db->identities->createPasskeyIdentity($anchorUserId, $credentialId)->id;
        $this->assertNotNull($anchorId);
        Hilos::$db->passkeyCredentials->createFromRegistration(
            $anchorId,
            $keyUserId,
            $credentialId,
            self::PUBLIC_KEY_PEM,
            PasskeyAlgorithm::Es256,
            0,
            null,
            null,
            RandomHelper::hex(16),
            null,
        );

        return $credentialId;
    }

    /**
     * Reads which account a device key belongs to, past the collection cache.
     *
     * @param string $credentialId Credential id to resolve
     * @return int Owning user id
     * @throws DatabaseException When the query fails
     */
    private function passkeyUserId(string $credentialId): int
    {
        Database::sql(
            'SELECT `user_id` FROM `hilos_passkey_credential` WHERE `credential_id` = ?',
            [$credentialId],
        );
        $row = Database::row();
        $this->assertNotNull($row);

        return (int)$row['user_id'];
    }

    /**
     * Runs the repair migration file statement by statement, as the migration runner splits it.
     *
     * @throws DatabaseException When a statement fails
     */
    private function runMigration(): void
    {
        $statement = '';
        foreach (explode("\n", (string)file_get_contents(dirname(__DIR__, 2) . self::MIGRATION_FILE)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }

            $statement .= $line . "\n";
            if (str_ends_with($line, ';')) {
                Database::sql(rtrim(trim($statement), ';'));
                $statement = '';
            }
        }
    }
}
