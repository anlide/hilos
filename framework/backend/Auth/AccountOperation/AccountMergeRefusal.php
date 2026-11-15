<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;

/** Merge refusal codes in the priority order used to choose a refusal sentence. */
enum AccountMergeRefusal: string
{
    case Missing = 'missing';
    case SurvivorMerged = 'survivor_merged';
    case LoserMerged = 'loser_merged';
    case BeingDeleted = 'being_deleted';
    case Occupied = 'occupied';
    case ProtectionChanged = 'protection_changed';
    case SecondFactorFateRequired = 'second_factor_fate_required';
    case LoserWithoutSecondFactor = 'loser_without_second_factor';
    case SurvivorWithoutSecondFactor = 'survivor_without_second_factor';
    case LoserPendingRemoval = 'loser_pending_removal';
    case SurvivorPendingRemoval = 'survivor_pending_removal';

    /** @return string Refusal template whose account labels are supplied by the caller */
    public function template(): string
    {
        return match ($this) {
            self::Missing => 'No such user: %d',
            self::SurvivorMerged => AbstractSessionsLibraryAgent::ACCOUNT_MERGE_SURVIVOR_MERGED_MESSAGE,
            self::LoserMerged => AbstractSessionsLibraryAgent::ACCOUNT_MERGE_ALREADY_MERGED_MESSAGE,
            self::BeingDeleted => AbstractSessionsLibraryAgent::ACCOUNT_MERGE_BEING_DELETED_MESSAGE,
            self::Occupied => AbstractSessionsLibraryAgent::ACCOUNT_MERGE_OCCUPIED_MESSAGE,
            self::ProtectionChanged => 'Second-factor protection changed. Review the accounts and choose again.',
            self::SecondFactorFateRequired => 'Choose second-factor protection: survivor or both (--second-factor on the console).',
            self::LoserWithoutSecondFactor => 'The loser has no confirmed authenticator. Remove the second-factor choice.',
            self::SurvivorWithoutSecondFactor => "The survivor has no confirmed authenticator. Transfer the loser's protection or cancel.",
            self::LoserPendingRemoval => 'The loser has a pending second-factor removal. Cancel it or wait for it to finish.',
            self::SurvivorPendingRemoval => 'The survivor has a pending second-factor removal. Cancel it or wait for it to finish.',
        };
    }
}
