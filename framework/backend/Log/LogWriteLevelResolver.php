<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\LogLevel;
use Throwable;

/**
 * Reads the write level out of the settings, with the environment beneath them (HIL-761).
 *
 * Built on the same two ways down as {@see LogSettingsResolver}, and they are still not the same
 * thing. A project that never folded {@see LogSettingsCatalog} into its catalog has no such key,
 * so the settings are not consulted and nothing is wrong - that is the plain env installation.
 * Trouble is the other way: the settings layer is not initialized in this process, the read
 * throws, or the stored value does not name a level. Then the environment answers instead, and a
 * line is owed to the journal - how loudly to log is not something to stop over.
 *
 * The complaint is raised when the outcome CHANGES, not on every read, for the reason its
 * neighbor gives: the same failing value asked about on every incoming settings frame would
 * flood the very journal this class configures. A recovery clears the memory silently, so a
 * fault that comes back is reported again.
 */
final class LogWriteLevelResolver
{
    /**
     * Source name for a level the settings layer answered with.
     *
     * Including the case where no row is written and the answer is the catalog default - which is
     * the environment value. The pair of names separates "the settings answered" from "the
     * settings could not answer", because that is the only distinction a journal line can act on:
     * an installation with no row and one whose row repeats its environment are the same
     * installation, and neither has anything to be told about.
     */
    public const string SOURCE_SETTING = 'setting';

    /** Source name for a level the environment answered with because the settings could not. */
    public const string SOURCE_ENV = 'env';

    /** @var string Where the level handed out by the last resolve() came from */
    private string $source = self::SOURCE_ENV;

    /** @var ?string What went wrong during the read in progress */
    private ?string $pending = null;

    /** @var ?string Last trouble text, so an unchanged fault stays silent */
    private ?string $lastTrouble = null;

    /** @var list<string> Complaints raised and not yet taken by the caller */
    private array $complaints = [];

    /**
     * Reads the level the environment names, falling back to INFO when it cannot answer.
     *
     * Static and public because it is also the level a process starts on, before it has any way
     * to reach the settings: {@see LogWriteLevelApplier::applyFromEnv()} asks the same question
     * this class's own fallback does, and two readings of one variable are one waiting to differ.
     *
     * A name that is not a level raises no complaint of its own: the value has already been
     * through the environment catalog, and INFO is what an installation that configured nothing
     * has always used.
     *
     * @return LogLevel Level from the environment, or INFO
     */
    public static function fromEnv(): LogLevel
    {
        $env = Hilos::$env;
        if ($env === null) {
            return LogLevel::Info;
        }

        try {
            $name = $env->string(EnvConstants::LOG_WRITE_LEVEL);
        } catch (EnvException) {
            return LogLevel::Info;
        }

        return LogLevel::fromName($name) ?? LogLevel::Info;
    }

    /**
     * Resolves the level in force: the setting, or the environment when it cannot be used.
     *
     * @return LogLevel Level to write from
     */
    public function resolve(): LogLevel
    {
        $setting = $this->settingLevel();
        if ($setting !== null) {
            $this->source = self::SOURCE_SETTING;
            $this->conclude();

            return $setting;
        }

        $this->source = self::SOURCE_ENV;
        $level = self::fromEnv();
        $this->conclude();

        return $level;
    }

    /**
     * Where the level handed out by the last {@see resolve()} came from.
     *
     * Asked so the journal line about a level change can name its source truthfully even when
     * the settings were consulted and could not answer - the road taken and the road intended
     * are two different facts, and only the first one is worth writing down.
     *
     * @return string Either {@see SOURCE_SETTING} or {@see SOURCE_ENV}
     */
    public function lastSource(): string
    {
        return $this->source;
    }

    /**
     * Hands over one complaint raised since the last call, oldest first.
     *
     * @return ?string Line for the journal, or null when there is nothing new to say
     */
    public function takeComplaint(): ?string
    {
        return array_shift($this->complaints);
    }

    /**
     * Reads the level the settings hold, or reports why it cannot be used.
     *
     * @return ?LogLevel Level from the settings, or null when the environment should answer
     */
    private function settingLevel(): ?LogLevel
    {
        $settings = Hilos::$setting;
        if ($settings === null) {
            $this->trouble('settings are not initialized in this process');

            return null;
        }

        // A project that never folded the log fragment into its catalog is not in trouble:
        // it is the installation configured by environment alone, and it stays that way.
        if (!isset($settings[LogSettingsCatalog::WRITE_LEVEL])) {
            return null;
        }

        try {
            $value = $settings->effectiveValueFor(LogSettingsCatalog::WRITE_LEVEL);
        } catch (Throwable $exception) {
            $this->trouble('setting could not be read: ' . $exception->getMessage());

            return null;
        }

        $refusal = LogWriteLevelRule::validate($value);
        if ($refusal !== null) {
            $this->trouble("setting is refused by its own rule ({$refusal})");

            return null;
        }

        return LogLevel::fromName((string)$value);
    }

    /**
     * Notes what went wrong while the level was being read.
     *
     * @param string $text What is wrong, in one line
     */
    private function trouble(string $text): void
    {
        $this->pending = $text;
    }

    /**
     * Closes a read: one complaint when its outcome differs from the previous one.
     *
     * A read that went cleanly forgets the fault, so one that returns later is reported again
     * rather than swallowed as "already said".
     */
    private function conclude(): void
    {
        $trouble = $this->pending;
        $this->pending = null;

        if ($trouble === null) {
            $this->lastTrouble = null;

            return;
        }

        if ($this->lastTrouble !== $trouble) {
            $this->complaints[] = 'Log write level setting unusable, falling back to env: ' . $trouble;
        }

        $this->lastTrouble = $trouble;
    }
}
