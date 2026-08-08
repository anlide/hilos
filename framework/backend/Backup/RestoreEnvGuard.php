<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\Constants\AppEnv;

/**
 * RestoreEnvGuard - the environment permission matrix for restoring a backup.
 *
 * A restore moves data between environments, and the two directions that matter are
 * both about production: production data leaking PII into a lesser environment, and a
 * lesser environment's data overwriting production. The matrix (owner decision,
 * 2026-07-08, refined on the HIL-274 interview):
 *
 * - prod archive -> prod target: allowed as-is - this is disaster recovery.
 * - prod archive -> non-prod target: requires anonymization (PII must not leave prod).
 * - non-prod archive -> prod target: refused, always.
 * - non-prod archive -> non-prod target: allowed.
 * - unknown archive env -> prod target: refused unless `--force` (legacy sidecars may
 *   predate the env field; the operator vouches with the flag).
 * - unknown archive env -> non-prod target: allowed.
 *
 * "Production" means exactly {@see AppEnv::PROD} on both sides: staging is treated as a
 * non-production target (safer for PII - a prod archive still needs anonymization to
 * land there) and as a non-production source (staging data must not overwrite prod).
 *
 * Pure function of its inputs: no facade reads, no filesystem. The CLI runs it for a
 * fast refusal before anything is touched, and the agent repeats it before anything
 * destructive - both sides must reach the same answer from the same inputs.
 */
final class RestoreEnvGuard
{
    /**
     * Decides whether an archive from one environment may be restored into another.
     *
     * @param ?AppEnv $archiveEnv Environment recorded in the archive sidecar; null when unknown/legacy
     * @param AppEnv $targetEnv Environment the restore would write into
     * @param bool $force Operator's `--force`; overrides only the unknown-archive-into-prod refusal
     * @return RestoreEnvDecisionResult Decision with the operator-facing reason where not plainly allowed
     */
    public static function decide(?AppEnv $archiveEnv, AppEnv $targetEnv, bool $force): RestoreEnvDecisionResult
    {
        if ($archiveEnv === null) {
            if ($targetEnv === AppEnv::PROD && !$force) {
                return new RestoreEnvDecisionResult(
                    RestoreEnvDecision::REFUSE,
                    'archive environment is unknown; restoring into production requires --force',
                    requiresForce: true,
                );
            }

            return new RestoreEnvDecisionResult(RestoreEnvDecision::ALLOW);
        }

        if ($archiveEnv === AppEnv::PROD && $targetEnv !== AppEnv::PROD) {
            return new RestoreEnvDecisionResult(
                RestoreEnvDecision::REQUIRE_ANONYMIZATION,
                'production archive must be anonymized before restoring into a non-production environment',
            );
        }

        if ($archiveEnv !== AppEnv::PROD && $targetEnv === AppEnv::PROD) {
            return new RestoreEnvDecisionResult(
                RestoreEnvDecision::REFUSE,
                'a non-production archive must not overwrite production',
            );
        }

        return new RestoreEnvDecisionResult(RestoreEnvDecision::ALLOW);
    }
}
