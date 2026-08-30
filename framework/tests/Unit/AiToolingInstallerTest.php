<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** One run of the installer: everything it printed, and what it exited with. */
final class InstallerRun
{
    public function __construct(
        public readonly string $output,
        public readonly int $exitCode,
    ) {
    }
}

/**
 * Drives the real `scripts/install-ai-tooling.php` against a throwaway repository
 * root (HIL-842).
 *
 * The script is copied into `<tmp>/scripts/`, which makes its own `dirname(__DIR__)`
 * the sandbox, and every case runs it in a subprocess. In-process execution is
 * impossible twice over: the script ends in `exit()`, and this suite runs under
 * `failOnWarning="true"`, so the very warnings the leaf is about would fail the run
 * that reproduces them.
 *
 * Fixtures make a write impossible STRUCTURALLY — a directory where a file belongs,
 * a file where a parent directory belongs — because the suite runs as root in
 * `hilos-cli-test`, where a chmod-based fixture lets every write through and proves
 * nothing. The two cases that need real ownership say so by dropping privileges,
 * and skip themselves off a root runner, which cannot stage a root-owned target.
 */
final class AiToolingInstallerTest extends TestCase
{
    /** Unprivileged uid/gid present in the test image; `nobody` owns nothing the suite needs. */
    private const int NOBODY_ID = 65534;

    /** @var list<string> Sandbox roots created by the running test, removed in teardown */
    private array $sandboxes = [];

    protected function tearDown(): void
    {
        foreach ($this->sandboxes as $sandbox) {
            $this->removeTree($sandbox);
        }
        $this->sandboxes = [];
        parent::tearDown();
    }

    public function testAppliesEveryArtifactInACleanSandbox(): void
    {
        $sandbox = $this->makeSandbox();

        $run = $this->runInstaller($sandbox);

        self::assertSame(0, $run->exitCode, "a clean sandbox must install cleanly:\n{$run->output}");
        self::assertStringContainsString('[created ] .claude/skills/hilos-demo', $run->output);
        self::assertStringContainsString('[created ] .agents/skills/hilos-demo', $run->output);
        self::assertStringContainsString('[created ] GEMINI.md', $run->output);
        self::assertStringContainsString('Done: 5 change(s) applied.', $run->output);
        self::assertFileExists($sandbox . '/.claude/skills/hilos-demo/references/notes.md');
    }

    public function testRefusesASkillWhoseFileCannotBeWrittenAndLeavesTheTreeUntouched(): void
    {
        $sandbox = $this->makeSandbox();
        mkdir($sandbox . '/.claude/skills/hilos-demo/SKILL.md', 0755, true);

        $run = $this->runInstaller($sandbox);

        self::assertSame(1, $run->exitCode, "a blocked artifact must make the pass fail:\n{$run->output}");
        self::assertStringContainsString(
            '[blocked ] .claude/skills/hilos-demo  (cannot overwrite .claude/skills/hilos-demo/SKILL.md:'
                . ' target is a directory where a file is required',
            $run->output,
        );
        self::assertStringNotContainsString('[created ] .claude/skills/hilos-demo', $run->output);
        self::assertStringNotContainsString('[updated ] .claude/skills/hilos-demo', $run->output);
        self::assertFileDoesNotExist(
            $sandbox . '/.claude/skills/hilos-demo/references/notes.md',
            'a skill is all-or-nothing: the writable half must not land either',
        );
    }

    public function testRefusesWhenTheSkillsRootIsAFile(): void
    {
        $sandbox = $this->makeSandbox();
        mkdir($sandbox . '/.claude', 0755, true);
        file_put_contents($sandbox . '/.claude/skills', "not a directory\n");

        $run = $this->runInstaller($sandbox);

        self::assertSame(1, $run->exitCode, "a blocked artifact must make the pass fail:\n{$run->output}");
        self::assertStringContainsString(
            '[blocked ] .claude/skills  (cannot overwrite .claude/skills:'
                . ' .claude/skills is a file where a directory is required',
            $run->output,
        );
    }

    public function testCheckModeNamesTheBlockAndDropsTheAdviceThatCannotFix(): void
    {
        $sandbox = $this->makeSandbox();
        mkdir($sandbox . '/.claude', 0755, true);
        file_put_contents($sandbox . '/.claude/skills', "not a directory\n");
        $this->runInstaller($sandbox);

        $run = $this->runInstaller($sandbox, '--check');

        self::assertSame(1, $run->exitCode, "drift must still be reported as drift:\n{$run->output}");
        self::assertStringContainsString('[blocked ] .claude/skills', $run->output);
        self::assertStringContainsString(
            'Drift detected: 1 blocked — `composer ai:install` cannot fix these; resolve them first.',
            $run->output,
        );
        self::assertStringNotContainsString(
            'Run `composer ai:install`',
            $run->output,
            'the summary must stop advising the run that cannot fix a block',
        );
    }

    public function testNamesTheOwnerAndTheChownCommandWhenPermissionIsTheCause(): void
    {
        $this->skipUnlessRoot();

        $sandbox = $this->makeSandbox();
        $this->runInstaller($sandbox);
        file_put_contents($sandbox . '/skills/hilos-demo/SKILL.md', "# demo skill, second edition\n");
        file_put_contents($sandbox . '/skills/hilos-demo/references/notes.md', "notes, second edition\n");
        $this->chownTree($sandbox, self::NOBODY_ID);
        $this->chownTree($sandbox . '/.claude/skills/hilos-demo', 0);

        $run = $this->runInstallerAsNobody($sandbox);

        self::assertSame(1, $run->exitCode, "a root-owned target must block the pass:\n{$run->output}");
        self::assertStringContainsString('[blocked ] .claude/skills/hilos-demo', $run->output);
        self::assertStringContainsString('cannot write 2 file(s): permission denied (owner root)', $run->output);
        self::assertStringContainsString('sudo chown -R ', $run->output);
        self::assertStringContainsString(' .claude/skills/hilos-demo)', $run->output);
        self::assertSame(
            "# demo skill\n",
            (string) file_get_contents($sandbox . '/.claude/skills/hilos-demo/SKILL.md'),
            'the copy the installer refused to make must not have happened',
        );
    }

    public function testWarnsWhenRunningAsRootInSomeoneElsesCheckout(): void
    {
        $this->skipUnlessRoot();

        $sandbox = $this->makeSandbox();
        $this->chownTree($sandbox, self::NOBODY_ID);

        $run = $this->runInstaller($sandbox, '--check');

        self::assertStringContainsString('  warning:  running as root while nobody owns this checkout', $run->output);
        self::assertStringContainsString('a later run as nobody will be refused', $run->output);
    }

    /**
     * Builds a repository root the installer can be pointed at: its own copy of the
     * script under `scripts/`, a canonical `agents.md`, and one skill with a nested
     * file, which is what makes the atomicity assertion possible.
     *
     * @return string Absolute path of the sandbox root
     */
    private function makeSandbox(): string
    {
        $sandbox = sys_get_temp_dir() . '/hilos-ai-tooling-' . uniqid('', true);
        $this->sandboxes[] = $sandbox;

        mkdir($sandbox . '/scripts', 0755, true);
        mkdir($sandbox . '/skills/hilos-demo/references', 0755, true);
        copy(dirname(__DIR__, 3) . '/scripts/install-ai-tooling.php', $sandbox . '/scripts/install-ai-tooling.php');
        file_put_contents($sandbox . '/agents.md', "# Demo Framework\n");
        file_put_contents($sandbox . '/skills/hilos-demo/SKILL.md', "# demo skill\n");
        file_put_contents($sandbox . '/skills/hilos-demo/references/notes.md', "notes\n");

        return $sandbox;
    }

    /**
     * `--copy-only` is passed everywhere so a case never depends on whether the
     * runner's filesystem supports symlinks.
     *
     * @param string $sandbox Absolute path of the sandbox root
     * @param string ...$options Extra installer options
     * @return InstallerRun What the run printed and exited with
     */
    private function runInstaller(string $sandbox, string ...$options): InstallerRun
    {
        return $this->capture(sprintf(
            '%s %s --copy-only %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($sandbox . '/scripts/install-ai-tooling.php'),
            implode(' ', array_map(escapeshellarg(...), $options)),
        ));
    }

    /**
     * Runs the installer as an unprivileged user without leaving the process tree:
     * a one-line launcher drops group and user and then `pcntl_exec`s the installer
     * in place, so its output and its exit code are the ones read back.
     *
     * @param string $sandbox Absolute path of the sandbox root
     * @return InstallerRun What the run printed and exited with
     */
    private function runInstallerAsNobody(string $sandbox): InstallerRun
    {
        $launcher = $sandbox . '/drop-privileges.php';
        file_put_contents($launcher, sprintf(
            "<?php\nposix_setgid(%d);\nposix_setuid(%d);\npcntl_exec(%s, [%s, '--copy-only']);\n",
            self::NOBODY_ID,
            self::NOBODY_ID,
            var_export(PHP_BINARY, true),
            var_export($sandbox . '/scripts/install-ai-tooling.php', true),
        ));
        chmod($launcher, 0755);

        return $this->capture(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($launcher));
    }

    /**
     * @param string $command Shell command to run
     * @return InstallerRun Combined output and exit code of that command
     */
    private function capture(string $command): InstallerRun
    {
        $lines = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $lines, $exitCode);

        return new InstallerRun(implode("\n", $lines) . "\n", $exitCode);
    }

    /**
     * Walks with `scandir()` rather than `glob()`: every artifact of this installer
     * is a dotfile, and a glob would silently skip all of them.
     *
     * @param string $path Absolute path of the tree whose ownership changes
     * @param int $id Uid and gid the tree is handed to
     */
    private function chownTree(string $path, int $id): void
    {
        chown($path, $id);
        chgrp($path, $id);
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->chownTree($path . '/' . $entry, $id);
        }
    }

    /** @param string $path Absolute path of the tree to delete */
    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    /** Skips the running test where the ownership fixture it needs cannot be staged. */
    private function skipUnlessRoot(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('staging a root-owned target needs a root runner');
        }
    }
}
