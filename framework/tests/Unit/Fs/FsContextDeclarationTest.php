<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs;

use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Every $fs directory says whose it is - its node's or the cluster's (HIL-1240).
 *
 * The declaration is read back from a directory and from tmp, the context lists its directories,
 * and the start refuses three faults: tmp declared NODE, a reserved directory declared with the
 * wrong owner, and one path declared by two owners. Paths need not exist: rules compare written paths.
 */
final class FsContextDeclarationTest extends TestCase
{
    public function testTheOwnerIsReadFromADirectoryAndFromTmp(): void
    {
        $context = (new DeclaringFsContext())
            ->declareTmp('/srv/node/tmp', DirectoryScope::NODE)
            ->declareDirectory(FsContext::FILES, '/srv/shared/files', DirectoryScope::CLUSTER);

        self::assertSame(DirectoryScope::NODE, $context->getTmp()->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::FILES)->getScope());
    }

    public function testDirectoriesAreListedByNameInRegistrationOrder(): void
    {
        $context = (new DeclaringFsContext())
            ->declareDirectory('quarantine', '/srv/shared/quarantine', DirectoryScope::CLUSTER)
            ->declareDirectory(FsContext::DATA_EXPORT, '/srv/shared/exports', DirectoryScope::CLUSTER)
            ->declareDirectory('drafts', '/srv/node/drafts', DirectoryScope::NODE);

        self::assertSame(['quarantine', FsContext::DATA_EXPORT, 'drafts'], array_keys($context->getDirectories()));
        self::assertSame('/srv/node/drafts', $context->getDirectories()['drafts']->getPath());
    }

    /**
     * The directories whose marker a cluster node reads (HIL-1242): tmp first when it is the
     * cluster's, then the cluster's directories in registration order, two names of one path both.
     */
    public function testTheClusterDirectoriesAreTmpFirstThenTheOthersInRegistrationOrder(): void
    {
        $context = (new DeclaringFsContext())
            ->declareDirectory('published', '/srv/shared/published', DirectoryScope::CLUSTER)
            ->declareDirectory('drafts', '/srv/node/drafts', DirectoryScope::NODE)
            ->declareDirectory(FsContext::FILES, '/srv/shared/published', DirectoryScope::CLUSTER)
            ->declareTmp('/srv/shared/tmp', DirectoryScope::CLUSTER);

        self::assertSame(
            [
                FsContext::TMP => '/srv/shared/tmp',
                'published' => '/srv/shared/published',
                FsContext::FILES => '/srv/shared/published',
            ],
            $context->clusterDirectories(),
        );
    }

    public function testANodeTmpIsNoClusterDirectory(): void
    {
        $context = (new DeclaringFsContext())
            ->declareTmp('/srv/node/tmp', DirectoryScope::NODE)
            ->declareDirectory(FsContext::DATA_EXPORT, '/srv/shared/exports', DirectoryScope::CLUSTER);

        self::assertSame([FsContext::DATA_EXPORT => '/srv/shared/exports'], $context->clusterDirectories());
    }

    public function testACorrectDeclarationHasNoFaults(): void
    {
        $context = (new DeclaringFsContext())
            ->declareTmp('/srv/shared/tmp', DirectoryScope::CLUSTER)
            ->declareDirectory('published', '/srv/shared/published', DirectoryScope::CLUSTER)
            ->declareDirectory(FsContext::FILES, '/srv/shared/published', DirectoryScope::CLUSTER)
            ->declareDirectory(FsContext::DATA_EXPORT, '/srv/shared/exports', DirectoryScope::CLUSTER);

        self::assertSame([], $context->declarationErrors());
    }

    public function testAReservedDirectoryDeclaredNodeIsNamed(): void
    {
        $context = (new DeclaringFsContext())
            ->declareDirectory(FsContext::FILES, '/srv/node/files', DirectoryScope::NODE)
            ->declareDirectory(FsContext::DATA_EXPORT, '/srv/node/exports', DirectoryScope::NODE)
            ->declareDirectory(FsContext::LEGAL_EXPORT, '/srv/node/legal-exports', DirectoryScope::NODE);

        self::assertSame(
            [
                "FS directory [files] is the cluster's: register it with DirectoryScope::CLUSTER",
                "FS directory [data_export] is the cluster's: register it with DirectoryScope::CLUSTER",
                "FS directory [legal_export] is the cluster's: register it with DirectoryScope::CLUSTER",
            ],
            $context->declarationErrors(),
        );
    }

    public function testATmpDeclaredNodeIsNamedFirst(): void
    {
        $context = (new DeclaringFsContext())
            ->declareTmp('/srv/node/tmp', DirectoryScope::NODE)
            ->declareDirectory(FsContext::FILES, '/srv/node/files', DirectoryScope::NODE);

        self::assertSame(
            [
                "FS tmp directory is the cluster's: set it with DirectoryScope::CLUSTER",
                "FS directory [files] is the cluster's: register it with DirectoryScope::CLUSTER",
            ],
            $context->declarationErrors(),
        );
    }

    public function testOnePathWithTwoOwnersNamesBoth(): void
    {
        $context = (new DeclaringFsContext())
            ->declareDirectory('published', '/srv/shared/published', DirectoryScope::CLUSTER)
            ->declareDirectory('drafts', '/srv/shared/published', DirectoryScope::NODE);

        self::assertSame(
            [
                'FS directories [published, drafts] share the path /srv/shared/published'
                . ' but declare different owners: published CLUSTER, drafts NODE',
            ],
            $context->declarationErrors(),
        );
    }

    public function testTmpOnTheDirectoryPathWithAnotherOwnerIsNamedTmp(): void
    {
        $context = (new DeclaringFsContext())
            ->declareDirectory('drafts', '/srv/shared/drafts', DirectoryScope::NODE)
            ->declareTmp('/srv/shared/drafts', DirectoryScope::CLUSTER);

        self::assertSame(
            [
                'FS directories [tmp, drafts] share the path /srv/shared/drafts'
                . ' but declare different owners: tmp CLUSTER, drafts NODE',
            ],
            $context->declarationErrors(),
        );
    }

    public function testATrailingSeparatorDoesNotMakeAnotherPath(): void
    {
        $context = (new DeclaringFsContext())
            ->declareDirectory('published', '/srv/shared/published', DirectoryScope::CLUSTER)
            ->declareDirectory('drafts', '/srv/shared/published/', DirectoryScope::NODE);

        self::assertSame(
            [
                'FS directories [published, drafts] share the path /srv/shared/published'
                . ' but declare different owners: published CLUSTER, drafts NODE',
            ],
            $context->declarationErrors(),
        );
    }

    public function testTheStartRefusesAMisdeclaredDirectoryByName(): void
    {
        $previousFs = Hilos::$fs;
        Hilos::$fs = (new DeclaringFsContext())
            ->declareDirectory(FsContext::DATA_EXPORT, '/srv/node/exports', DirectoryScope::NODE);
        try {
            $this->expectException(InvalidTopologyException::class);
            $this->expectExceptionMessage('FS directory [data_export] is the cluster\'s');
            DirectoryOwnersHilos::checkDirectories();
        } finally {
            Hilos::$fs = $previousFs;
        }
    }

    public function testTheStartRefusesANodeTmp(): void
    {
        $previousFs = Hilos::$fs;
        Hilos::$fs = (new DeclaringFsContext())
            ->declareTmp('/srv/node/tmp', DirectoryScope::NODE);
        try {
            $this->expectException(InvalidTopologyException::class);
            $this->expectExceptionMessage("FS tmp directory is the cluster's");
            DirectoryOwnersHilos::checkDirectories();
        } finally {
            Hilos::$fs = $previousFs;
        }
    }

    public function testTheStartPassesACorrectDeclaration(): void
    {
        $previousFs = Hilos::$fs;
        Hilos::$fs = (new DeclaringFsContext())
            ->declareTmp('/srv/shared/tmp', DirectoryScope::CLUSTER)
            ->declareDirectory(FsContext::DATA_EXPORT, '/srv/shared/exports', DirectoryScope::CLUSTER);
        try {
            $this->expectNotToPerformAssertions();
            DirectoryOwnersHilos::checkDirectories();
        } finally {
            Hilos::$fs = $previousFs;
        }
    }

    public function testTheStartIsSilentWithoutAnFsContext(): void
    {
        $previousFs = Hilos::$fs;
        Hilos::$fs = null;
        try {
            $this->expectNotToPerformAssertions();
            DirectoryOwnersHilos::checkDirectories();
        } finally {
            Hilos::$fs = $previousFs;
        }
    }
}

/** A context the test declares call by call instead of in configure(). */
final class DeclaringFsContext extends FsContext
{
    /** Declares nothing: each test declares its own directories. */
    public function configure(): void
    {
    }

    /**
     * @param string $path Tmp path, never created
     * @param DirectoryScope $scope Declared owner
     * @return self This context, for chaining
     */
    public function declareTmp(string $path, DirectoryScope $scope): self
    {
        $this->setTmpPath($path, $scope);

        return $this;
    }

    /**
     * @param string $name Logical directory name
     * @param string $path Directory path, never created
     * @param DirectoryScope $scope Declared owner
     * @return self This context, for chaining
     */
    public function declareDirectory(string $name, string $path, DirectoryScope $scope): self
    {
        $this->registerDirectory($name, $path, $scope);

        return $this;
    }
}

abstract class DirectoryOwnersHilos extends Hilos
{
    /**
     * @throws InvalidTopologyException When the fixture context misdeclares a directory
     */
    public static function checkDirectories(): void
    {
        static::refuseMisdeclaredDirectories();
    }
}
