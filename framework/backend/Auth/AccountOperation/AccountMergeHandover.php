<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;

/** Saved private snapshots with their immutable original admission revisions. */
final readonly class AccountMergeHandover
{
    /**
     * @param AccountMergeHandoverCopy $copy Original saved business snapshots
     * @param AccountMergeAdmissionManifest $admission Original saved transfer revisions
     * @throws InvalidFormatException When the copy and admission name different rows
     */
    public function __construct(public AccountMergeHandoverCopy $copy, public AccountMergeAdmissionManifest $admission)
    {
        foreach ([AccountMergeAdmissionManifest::identities, AccountMergeAdmissionManifest::passkeyCredentials,
            AccountMergeAdmissionManifest::secondFactors, AccountMergeAdmissionManifest::secondFactorBackupCodes] as $key) {
            if (array_column($copy->{$key}, AccountMergeRowRevision::id) !== array_column($admission->{$key}, AccountMergeRowRevision::id)) {
                throw new InvalidFormatException('The merge handover copy must match its saved admission rows.');
            }
        }
    }
}
