<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Collection\HilosProfileFlows;

/**
 * HilosProfileFlow - how far one profile window of one browser session has got (HIL-1182).
 *
 * The record behind the email-change, password-change, account-deletion and add-sign-in windows.
 * A window used to keep its step in the tab that drew it; for email and password changes, a matched code
 * travelled with that tab from submit to submit. So a second tab of the same
 * browser started the flow from the first step, its "Send code" voided the first tab's code, and
 * a reload threw the flow away. The step and any proof now live here, in the session,
 * and every tab of it opens the window on the same step.
 *
 * ONE ROW PER SESSION AND WINDOW: the id is the hash of the session's cookie token - the form the
 * toast stack and the send-progress line use - joined to the operation key of the window
 * ({@see StepUpOperationKey}). Two windows of one session live apart: the email change in one tab
 * and the password change in another are two flows, not one; deletion and adding a sign-in
 * method each have their own operation too (HIL-1184).
 *
 * Framework-owned runtime state mounted by the sign-in feature ({@see HilosProfileFlows}) and
 * written by the session holder ({@see AbstractSessionsLibraryAgent}) and by nobody else; the users
 * library that runs the steps reports each one by frame. Runtime rather than durable on purpose:
 * the row lives as long as the code its proof stands on - minutes - and a restart that loses it
 * opens the window on the first step, which is what the code dying would have done anyway.
 *
 * The row has NO CLOCK OF ITS OWN. {@see self::$expiresAt} is the moment the code the proof stands
 * on dies, copied from that code, and the step after it is let through only while the live code of
 * the address still dies at that very moment. A new code of the same window - sent from another
 * browser, or again after the first one died - dies at another moment, and the proof dies with it.
 */
final class HilosProfileFlow extends RtState
{
    /** Runtime collection key mounted by the sign-in feature and used for RT sync. */
    public const string RT_COLLECTION = 'hilosProfileFlows';

    public const string sessionTokenHash = 'sessionTokenHash';
    public const string operation = 'operation';
    public const string userId = 'userId';
    public const string step = 'step';
    public const string address = 'address';
    public const string target = 'target';
    public const string expiresAt = 'expiresAt';

    /** Email change: a code went to the address the account holds now. */
    public const string STEP_CURRENT_SENT = 'current_sent';

    /** Email change: the code of the current address matched; the new address is next. */
    public const string STEP_CURRENT_PROVEN = 'current_proven';

    /** Email change: a code went to the new address, which {@see self::$target} names. */
    public const string STEP_NEW_SENT = 'new_sent';

    /** Password change or account deletion: a code went to the account's address. */
    public const string STEP_CODE_SENT = 'code_sent';

    /** Password change: that code matched; the new password is next. */
    public const string STEP_CODE_PROVEN = 'code_proven';

    /** Add a way to sign in: a code went to the phone number being added. */
    public const string STEP_PHONE_SENT = 'phone_sent';

    /** Add a way to sign in: a code went to the address a password is being added on. */
    public const string STEP_EMAIL_SENT = 'email_sent';

    /** Joins the session hash and the operation key into the row id; neither of them carries it. */
    private const string ID_SEPARATOR = ':';

    /** Hash of the session cookie token this flow belongs to. */
    private(set) string $sessionTokenHash = '';

    /** Operation key of the window - CHANGE_EMAIL, CHANGE_PASSWORD, DELETE_ACCOUNT or ADD_SIGN_IN_METHOD. */
    private(set) string $operation = '';

    /** The person the flow was started for; a flow read on behalf of anybody else is no proof. */
    private(set) int $userId = 0;

    /** One of the STEP_* constants: what has happened, not which screen draws it. */
    private(set) string $step = '';

    /** The account's address the proof stands on, or the address or number being added where its code went. */
    private(set) string $address = '';

    /** New email on STEP_NEW_SENT; added address or number on STEP_PHONE_SENT or STEP_EMAIL_SENT; null otherwise. */
    private(set) ?string $target = null;

    /** Epoch milliseconds the code the proof stands on dies at; the row's whole lifetime. */
    private(set) int $expiresAt = 0;

    /**
     * Names the row of one window of one session.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param string $operation Operation key of the window
     * @return string Row id, "<hash>:<operation>"
     */
    public static function idFor(string $sessionTokenHash, string $operation): string
    {
        return $sessionTokenHash . self::ID_SEPARATOR . $operation;
    }

    /**
     * Opens the record of one window on the step just reached.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param string $operation Operation key of the window
     * @param int $userId The person the flow is for
     * @param string $step One of the STEP_* constants
     * @param string $address The account's address the proof stands on
     * @param ?string $target New email on STEP_NEW_SENT, added address or number on STEP_PHONE_SENT or STEP_EMAIL_SENT, null otherwise
     * @param int $expiresAt Epoch milliseconds the code of the proof dies at
     * @return static Fresh flow row
     */
    public static function create(
        string $sessionTokenHash,
        string $operation,
        int $userId,
        string $step,
        string $address,
        ?string $target,
        int $expiresAt,
    ): static {
        $instance = new static();
        $instance->sessionTokenHash = $sessionTokenHash;
        $instance->operation = $operation;
        $instance->userId = $userId;
        $instance->step = $step;
        $instance->address = $address;
        $instance->target = $target;
        $instance->expiresAt = $expiresAt;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Flow row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the flow is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->sessionTokenHash = self::requireString($row, self::sessionTokenHash);
        $instance->operation = self::requireString($row, self::operation);
        $instance->userId = self::requireInt($row, self::userId);
        $instance->step = self::requireString($row, self::step);
        $instance->address = self::requireString($row, self::address);
        $instance->target = self::optionalString($row, self::target);
        $instance->expiresAt = self::requireInt($row, self::expiresAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * The session and the operation are not among the patchable fields: together they are the
     * id, and a row that could be re-addressed would show one browser's flow to another.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->userId = self::patchInt($diff, self::userId, $this->userId);
        $this->step = self::patchString($diff, self::step, $this->step);
        $this->address = self::patchString($diff, self::address, $this->address);
        $this->target = self::patchOptionalString($diff, self::target, $this->target);
        $this->expiresAt = self::patchInt($diff, self::expiresAt, $this->expiresAt);
    }

    /**
     * @return string Runtime collection key for profile flows
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, {@see self::idFor()} of the session and the window
     */
    public function getId(): string
    {
        return self::idFor($this->sessionTokenHash, $this->operation);
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::sessionTokenHash => $this->sessionTokenHash,
            self::operation => $this->operation,
            self::userId => $this->userId,
            self::step => $this->step,
            self::address => $this->address,
            self::target => $this->target,
            self::expiresAt => $this->expiresAt,
        ];
    }
}
