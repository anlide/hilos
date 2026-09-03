<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Ship\BackupArchiveEncryptor;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the seam that turns the leaving copy into ciphertext.
 *
 * Asserted by argv and by fingerprint, with no key pair and no `age` binary anywhere: the seam
 * builds a command and never runs it, so this is where a wrong flag is caught. What the command
 * actually does to an archive is proven once, by the integration run against a real receiver.
 */
final class BackupArchiveEncryptorTest extends TestCase
{
    private string $recipientsPath = '';

    protected function setUp(): void
    {
        $this->recipientsPath = sys_get_temp_dir() . '/hilos-age-' . getmypid() . '-' . uniqid() . '.txt';
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
    }

    protected function tearDown(): void
    {
        if (is_file($this->recipientsPath)) {
            unlink($this->recipientsPath);
        }
        putenv(EnvConstants::BACKUP_SHIP_ENCRYPT_RECIPIENTS->name);
    }

    public function testTheCommandSpellsOutEveryArgumentAgeWillSee(): void
    {
        $command = $this->encryptor("age1qyqszqgpqyqszqgpqyqszqgpqyqszqgpqyqszqgpqyqszqgpqyqszqgpsw3xqq\n")
            ->encryptCommand('/var/backups/full/a.tar.gz', '/var/backups/full/.tmp-shipenc-a-7/a.tar.gz');

        $this->assertSame('age', $command->binary);
        $this->assertSame([
            '-R',
            $this->recipientsPath,
            '-o',
            '/var/backups/full/.tmp-shipenc-a-7/a.tar.gz',
            '/var/backups/full/a.tar.gz',
        ], $command->args);
    }

    public function testTheFingerprintNamesTheKeysAndNotTheLayoutOfTheFile(): void
    {
        // Reordering the lines, commenting them, or padding them changes nothing about who can
        // open the copy - and a fingerprint that moved would re-send the whole store for nothing.
        $plain = $this->encryptor("age1aaa\nage1bbb\n")->fingerprint();

        $this->assertSame($plain, $this->encryptor("age1bbb\nage1aaa\n")->fingerprint());
        $this->assertSame($plain, $this->encryptor("  age1bbb  \n\n# an operator's note\nage1aaa\n")->fingerprint());
        $this->assertSame($plain, $this->encryptor("age1aaa\r\nage1bbb\r\n")->fingerprint());
    }

    public function testAChangedRecipientSetChangesTheFingerprint(): void
    {
        // The other half of the same promise: adding or dropping a key is exactly the event that
        // has to re-send the store, because who can open the copy is now a different answer.
        $one = $this->encryptor("age1aaa\n")->fingerprint();

        $this->assertNotSame($one, $this->encryptor("age1aaa\nage1bbb\n")->fingerprint());
        $this->assertNotSame($one, $this->encryptor("age1ccc\n")->fingerprint());
    }

    public function testAnUnusableFileIsConfiguredAndYetBuildsNothing(): void
    {
        // The pair the fail-closed gate is built on: "configured" and "usable" are two questions,
        // and only both together tell a clear copy leaving on purpose from one leaving by mistake.
        foreach (["\n", "# only a comment\n", '   '] as $contents) {
            file_put_contents($this->recipientsPath, $contents);
            putenv(EnvConstants::BACKUP_SHIP_ENCRYPT_RECIPIENTS->name . '=' . $this->recipientsPath);

            $this->assertTrue(BackupArchiveEncryptor::isConfigured());
            $this->assertNull(BackupArchiveEncryptor::fromEnv());
        }
    }

    public function testAFileThatIsNotThereIsConfiguredAndYetBuildsNothing(): void
    {
        putenv(EnvConstants::BACKUP_SHIP_ENCRYPT_RECIPIENTS->name . '=' . $this->recipientsPath . '-gone');

        $this->assertTrue(BackupArchiveEncryptor::isConfigured());
        $this->assertNull(BackupArchiveEncryptor::fromEnv());
    }

    public function testNoRecipientsAtAllIsTheAbsenceOfEncryptionRatherThanAThirdMode(): void
    {
        // An unset value must read as "ships in the clear, exactly as before", not as a refusal:
        // that is what keeps an installation that never configured a key working untouched.
        putenv(EnvConstants::BACKUP_SHIP_ENCRYPT_RECIPIENTS->name . '=');

        $this->assertFalse(BackupArchiveEncryptor::isConfigured());
        $this->assertNull(BackupArchiveEncryptor::fromEnv());
    }

    public function testTheStagedCiphertextCarriesTheFinalNameInsideADirectoryOfItsOwn(): void
    {
        // The push names the file on the receiver by the basename of what it sends, so the
        // ciphertext has to be called what the archive is called - and a name has room for one
        // role, which is why the pid goes on the directory instead.
        $dir = BackupArchiveEncryptor::stageDir('/var/backups/full', '20260816-test-full', 4242);

        $this->assertSame('/var/backups/full/.tmp-shipenc-20260816-test-full-4242', $dir);
        $this->assertSame(
            $dir . '/20260816-test-full.tar.gz',
            BackupArchiveEncryptor::stagedArchivePath('/var/backups/full', '20260816-test-full', 4242),
        );
        // Invisible to the store scan, which globs '*' and so never sees a dotted name, and clear
        // of the create path's own `.tmp-<base>-` sweep.
        $this->assertStringStartsWith('/var/backups/full/.tmp-', $dir);
        $this->assertStringStartsWith(
            BackupArchiveEncryptor::stageDirPrefix('/var/backups/full', '20260816-test-full'),
            $dir,
        );
    }

    /**
     * Writes a recipients file and builds the encryptor over it.
     *
     * @param string $contents Recipients file as an operator would write it
     * @return BackupArchiveEncryptor Encryptor over that set
     */
    private function encryptor(string $contents): BackupArchiveEncryptor
    {
        file_put_contents($this->recipientsPath, $contents);
        putenv(EnvConstants::BACKUP_SHIP_ENCRYPT_RECIPIENTS->name . '=' . $this->recipientsPath);

        $encryptor = BackupArchiveEncryptor::fromEnv();
        $this->assertNotNull($encryptor);

        return $encryptor;
    }
}
