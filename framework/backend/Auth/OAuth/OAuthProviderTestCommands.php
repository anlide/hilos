<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Closure;
use Hilos\Auth\Method\DTO\AuthMethodsSignalData;
use Hilos\Constants\CliCommands;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Database;
use Hilos\Environment\NonProductionGate;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Throwable;

/** Channel-only raw cleanup, executed by the existing whole-provider owner. */
final class OAuthProviderTestCommands
{
    /**
     * Converts every refusal to a fixed sentence; raw state never enters an error.
     *
     * @param CommandRequestDTO $request Closed-channel request
     * @param Closure(AuthMethodsSignalData):void $announce Owner's all-connections sender
     * @return CommandReplyDTO One reply for the request's correlation id
     */
    public static function handle(CommandRequestDTO $request, Closure $announce): CommandReplyDTO
    {
        try {
            if (!NonProductionGate::admitted()) {
                throw new InvalidFormatException('OAuth provider snapshots are test-only.');
            }
            if ($request->command === CliCommands::OAUTH_PROVIDER_TEST_SNAPSHOT) {
                if (count($request->payload) !== 1) {
                    throw new InvalidFormatException('Invalid OAuth provider snapshot request.');
                }
                $key = $request->payload[OAuthProviderTestCommandConstants::FIELD_PROVIDER_KEY] ?? null;
                if (!is_string($key)) {
                    throw new InvalidFormatException('Invalid OAuth provider key.');
                }
                self::requireProvider($key);

                return CommandReplyDTO::ok($request->correlationId, [
                    OAuthProviderTestCommandConstants::FIELD_SNAPSHOT => self::snapshot($key)->toArray(),
                ]);
            }
            if ($request->command !== CliCommands::OAUTH_PROVIDER_TEST_RESTORE) {
                throw new InvalidFormatException('Unknown OAuth provider test command.');
            }
            $raw = $request->payload[OAuthProviderTestCommandConstants::FIELD_SNAPSHOT] ?? null;
            if (
                count($request->payload) !== 2 || !is_array($raw)
                || !array_key_exists(OAuthProviderTestCommandConstants::FIELD_CURRENT_ID, $request->payload)
            ) {
                throw new InvalidFormatException('Invalid OAuth provider restoration request.');
            }
            $currentId = $request->payload[OAuthProviderTestCommandConstants::FIELD_CURRENT_ID];
            if ($currentId !== null && (!is_int($currentId) || $currentId <= 0)) {
                throw new InvalidFormatException('Invalid OAuth provider current id.');
            }
            $snapshot = RawOAuthProviderSnapshot::fromArray($raw);
            self::requireProvider($snapshot->providerKey);
            self::restore($snapshot, $currentId, $announce);

            return CommandReplyDTO::ok($request->correlationId, [OAuthProviderTestCommandConstants::FIELD_RESTORED => true]);
        } catch (Throwable) {
            return CommandReplyDTO::error($request->correlationId, 'OAuth provider test state could not be read or restored.');
        }
    }

    /** @param string $key External directory key */
    private static function requireProvider(string $key): void
    {
        if ($key === '' || Hilos::oauthProviderDirectoryClass()::get($key) === null) {
            throw new InvalidFormatException('Unknown OAuth provider.');
        }
    }

    /**
     * @param string $key External directory key before an identity has been captured
     * @return RawOAuthProviderSnapshot Exact raw row or absence
     */
    private static function snapshot(string $key): RawOAuthProviderSnapshot
    {
        if (Hilos::$db === null) {
            throw new InvalidFormatException('OAuth provider state is unavailable.');
        }

        return isset(Hilos::$db->oauthProviders[$key])
            ? Hilos::$db->oauthProviders[$key]->rawSnapshotForTest()
            : new RawOAuthProviderSnapshot($key);
    }

    /**
     * @param RawOAuthProviderSnapshot $snapshot Original state
     * @param ?int $currentId Freshly captured current identity
     * @param Closure(AuthMethodsSignalData):void $announce Owner's sender after commit
     */
    private static function restore(RawOAuthProviderSnapshot $snapshot, ?int $currentId, Closure $announce): void
    {
        Database::transactionStart();
        try {
            // A captured row is addressed only by primary id, even after deletion/recreation.
            if ($snapshot->id !== null) {
                if (
                    !isset(Hilos::$db->oauthProviders[$snapshot->id])
                    || Hilos::$db->oauthProviders[$snapshot->id]->providerKey !== $snapshot->providerKey
                ) {
                    throw new InvalidFormatException('The captured OAuth provider row no longer exists.');
                }
                $current = Hilos::$db->oauthProviders[$snapshot->id]->rawSnapshotForTest();
            } else {
                $current = self::snapshot($snapshot->providerKey);
            }
            if ($current->toArray() !== $snapshot->toArray()) {
                if ($currentId !== $current->id || ($snapshot->id !== null && $currentId !== $snapshot->id)) {
                    throw new InvalidFormatException('The OAuth provider row was replaced.');
                }
                $before = self::offeredMethods();
                if ($snapshot->id !== null) {
                    Hilos::$db->oauthProviders[$snapshot->id]->actions->restoreRawForTest($snapshot);
                } else {
                    Hilos::$db->oauthProviders[$currentId]->actions->deleteForTest();
                }
                $after = self::offeredMethods();
                if ($before !== null && $after !== null && $before->toArray() !== $after->toArray()) {
                    Database::afterCommit(static fn() => $announce($after));
                }
            }
            Database::transactionCommit();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }
    }

    /** @return ?AuthMethodsSignalData Current offered set, or unread as on the provider page */
    private static function offeredMethods(): ?AuthMethodsSignalData
    {
        try {
            return AuthMethodsSignalData::current();
        } catch (HilosException) {
            return null;
        }
    }
}
