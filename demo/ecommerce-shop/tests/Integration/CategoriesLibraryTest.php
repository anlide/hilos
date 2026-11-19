<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Action\ActionFailureReason;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Files\FileVisibility;
use Hilos\HilosException;
use Hilos\Testing\IntegrationTest;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * The real shop schema and the category actions under their library's claim; the rollback scope proves writes, not commit.
 *
 * A picture is a registry row written straight into `hilos_file`, with no file on disk: the
 * category only names it, and what the files library hears after a commit is
 * {@see CategoryPictureFilesTest}'s to prove.
 */
#[IntegrationTest]
final class CategoriesLibraryTest extends CategoriesLibraryEnvironment
{
    /** A new category is stored trimmed, first in the catalog, under the address of its name, and the next ones follow it. */
    public function testCreateStoresTheTrimmedFieldsAtTheEndOfTheCatalogUnderTheAddressOfItsName(): void
    {
        $picture = self::pictureFile();

        $roses = Hilos::$db->categories->actions->create('  Roses  ', "\tFresh cut roses \n", $picture);

        self::assertNotNull($roses->id);
        self::assertSame([[
            'name' => 'Roses',
            'description' => 'Fresh cut roses',
            'slug' => 'roses',
            'position' => 1,
            'pictureFileId' => $picture,
        ]], self::storedRows($roses->id));
        self::assertSame(['roses' => $roses->id], self::storedAddresses());

        $tulips = Hilos::$db->categories->actions->create('Tulips', 'Spring tulips', self::pictureFile());
        $lilies = Hilos::$db->categories->actions->create('Lilies', 'White lilies', self::pictureFile());

        self::assertSame(2, self::storedPosition($tulips->id));
        self::assertSame(3, self::storedPosition($lilies->id));
    }

    /** The address keeps the letters and digits of the name in its own script, and a taken one gets the first free number. */
    public function testTheAddressIsBuiltFromTheName(): void
    {
        $planters = Hilos::$db->categories->actions->create('Pots & planters', 'Pots for every plant', self::pictureFile());
        $bouquets = Hilos::$db->categories->actions->create('Букети', 'Зібрані букети', self::pictureFile());
        $blossom = Hilos::$db->categories->actions->create('🌸', 'Blossom', self::pictureFile());
        $roses = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', self::pictureFile());
        $exclaimed = Hilos::$db->categories->actions->create('Roses!', 'Roses that shout', self::pictureFile());

        self::assertSame('pots-planters', self::storedSlug($planters->id));
        self::assertSame('букети', self::storedSlug($bouquets->id));
        self::assertSame('category', self::storedSlug($blossom->id));
        self::assertSame('roses', self::storedSlug($roses->id));
        self::assertSame('roses-2', self::storedSlug($exclaimed->id));
    }

    /** A rename gives a new address and keeps the old one; renamed back, the old address is current again and nothing is added. */
    public function testARenameKeepsTheFormerAddressAndARenameBackTakesItAgain(): void
    {
        $id = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', self::pictureFile())->id;

        Hilos::$db->categories[$id]->actions->update('Red roses', 'Fresh cut roses', null);

        self::assertSame('red-roses', self::storedSlug($id));
        self::assertSame(['roses' => $id, 'red-roses' => $id], self::storedAddresses());

        Hilos::$db->categories[$id]->actions->update('Roses', 'Fresh cut roses', null);

        self::assertSame('roses', self::storedSlug($id));
        self::assertSame(['roses' => $id, 'red-roses' => $id], self::storedAddresses());
    }

    /** A rename that leaves the address of the name as it was keeps the address and adds none. */
    public function testARenameWithTheSameAddressKeepsIt(): void
    {
        $id = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', self::pictureFile())->id;

        Hilos::$db->categories[$id]->actions->update('Roses!', 'Fresh cut roses', null);

        self::assertSame('Roses!', self::storedRows($id)[0]['name']);
        self::assertSame('roses', self::storedSlug($id));
        self::assertSame(['roses' => $id], self::storedAddresses());
    }

    /** The former address of another category is never given again: a new category of that name gets the next number. */
    public function testTheFormerAddressOfAnotherCategoryIsNotGivenAgain(): void
    {
        $first = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', self::pictureFile())->id;
        Hilos::$db->categories[$first]->actions->update('Red roses', 'Fresh cut roses', null);

        $second = Hilos::$db->categories->actions->create('Roses', 'Garden roses', self::pictureFile())->id;

        self::assertSame('roses-2', self::storedSlug($second));
        self::assertSame(['roses' => $first, 'red-roses' => $first, 'roses-2' => $second], self::storedAddresses());
    }

    /** An edit with a new file shows it instead; an edit without one keeps the picture it had. */
    public function testAnEditChangesThePictureOnlyWhenGivenANewFile(): void
    {
        $former = self::pictureFile();
        $id = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', $former)->id;
        $next = self::pictureFile();

        Hilos::$db->categories[$id]->actions->update('Roses', 'Fresh cut roses', $next);

        self::assertSame($next, self::storedRows($id)[0]['pictureFileId']);

        Hilos::$db->categories[$id]->actions->update('Roses', 'Garden roses', null);

        self::assertSame($next, self::storedRows($id)[0]['pictureFileId']);
        self::assertSame('Garden roses', self::storedRows($id)[0]['description']);
    }

    /** Moving a category up swaps it with the one right before it; the first one cannot move, and a gap is stepped over. */
    public function testMoveUpSwapsWithThePreviousCategory(): void
    {
        $first = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', self::pictureFile())->id;
        $second = Hilos::$db->categories->actions->create('Tulips', 'Spring tulips', self::pictureFile())->id;
        $third = Hilos::$db->categories->actions->create('Lilies', 'White lilies', self::pictureFile())->id;

        Hilos::$db->categories[$third]->actions->moveUp();

        self::assertSame([1, 3, 2], [self::storedPosition($first), self::storedPosition($second), self::storedPosition($third)]);

        self::assertRefused(
            ValidationException::class,
            'This category is already first',
            static fn () => Hilos::$db->categories[$first]->actions->moveUp(),
        );

        self::assertSame([1, 3, 2], [self::storedPosition($first), self::storedPosition($second), self::storedPosition($third)]);
    }

    /** With the middle category deleted, the last one moves up past the gap and swaps with the first. */
    public function testMoveUpStepsOverTheGapOfADeletedCategory(): void
    {
        $first = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', self::pictureFile())->id;
        $second = Hilos::$db->categories->actions->create('Tulips', 'Spring tulips', self::pictureFile())->id;
        $third = Hilos::$db->categories->actions->create('Lilies', 'White lilies', self::pictureFile())->id;
        Hilos::$db->categories[$second]->actions->delete();

        self::assertSame([1, 3], [self::storedPosition($first), self::storedPosition($third)]);

        Hilos::$db->categories[$third]->actions->moveUp();

        self::assertSame([3, 1], [self::storedPosition($first), self::storedPosition($third)]);
    }

    /** A deleted category leaves no row, its addresses stay with nobody, and a new category of its name gets the next number. */
    public function testDeleteRemovesTheRowAndRetiresItsAddresses(): void
    {
        $id = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', self::pictureFile())->id;
        Hilos::$db->categories[$id]->actions->update('Red roses', 'Fresh cut roses', null);

        Hilos::$db->categories[$id]->actions->delete();

        self::assertSame([], self::storedRows($id));
        self::assertSame(['roses' => null, 'red-roses' => null], self::storedAddresses());

        $again = Hilos::$db->categories->actions->create('Roses', 'Garden roses', self::pictureFile())->id;

        self::assertSame('roses-2', self::storedSlug($again));
    }

    /** A name or a description of nothing but spaces is refused in words the person reads, and nothing is stored. */
    public function testAnEmptyNameOrDescriptionIsRefusedAndNothingIsStored(): void
    {
        $picture = self::pictureFile();
        $before = self::storedCount();

        self::assertRefused(
            EmptyValueException::class,
            'Category name cannot be empty',
            static fn () => Hilos::$db->categories->actions->create('   ', 'Fresh cut roses', $picture),
        );
        self::assertRefused(
            EmptyValueException::class,
            'Category description cannot be empty',
            static fn () => Hilos::$db->categories->actions->create('Roses', '   ', $picture),
        );

        self::assertSame($before, self::storedCount());
        self::assertSame([], self::storedAddresses());
    }

    /** A name longer than 100 characters or a description longer than 255 is refused, and nothing is stored. */
    public function testATooLongNameOrDescriptionIsRefused(): void
    {
        $picture = self::pictureFile();
        $before = self::storedCount();

        self::assertRefused(
            ValueTooLongException::class,
            'Category name exceeds maximum length of 100 characters',
            static fn () => Hilos::$db->categories->actions->create(str_repeat('n', 101), 'Fresh cut roses', $picture),
        );
        self::assertRefused(
            ValueTooLongException::class,
            'Category description exceeds maximum length of 255 characters',
            static fn () => Hilos::$db->categories->actions->create('Roses', str_repeat('d', 256), $picture),
        );

        self::assertSame($before, self::storedCount());
        self::assertSame([], self::storedAddresses());
    }

    /** An edit with a good name and an empty description is refused whole: neither the row nor the collection moves. */
    public function testAnEditWithAnEmptyDescriptionKeepsTheOldValues(): void
    {
        $picture = self::pictureFile();
        $id = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', $picture)->id;

        self::assertRefused(
            EmptyValueException::class,
            'Category description cannot be empty',
            static fn () => Hilos::$db->categories[$id]->actions->update('Red roses', '   ', self::pictureFile()),
        );

        self::assertStoredUnchanged($id, 1, $picture);
    }

    /** A name already taken is refused on creation even when written in another case, and no address is recorded for it. */
    public function testATakenNameIsRefusedOnCreateInAnyCase(): void
    {
        $id = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', self::pictureFile())->id;
        $picture = self::pictureFile();
        $before = self::storedCount();

        self::assertRefused(
            DuplicateValueException::class,
            'A category with this name already exists',
            static fn () => Hilos::$db->categories->actions->create('roses', 'Garden roses', $picture),
        );

        self::assertSame($before, self::storedCount());
        self::assertSame(['roses' => $id], self::storedAddresses());
    }

    /** Renaming into a taken name in another case is refused, and the row, the collection and the addresses stay as they were. */
    public function testRenamingIntoATakenNameIsRefusedAndKeepsTheOldValues(): void
    {
        $taken = Hilos::$db->categories->actions->create('Tulips', 'Spring tulips', self::pictureFile())->id;
        $picture = self::pictureFile();
        $id = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', $picture)->id;

        self::assertRefused(
            DuplicateValueException::class,
            'A category with this name already exists',
            static fn () => Hilos::$db->categories[$id]->actions->update('TULIPS', 'Garden roses', self::pictureFile()),
        );

        self::assertStoredUnchanged($id, 2, $picture);
        self::assertSame(['tulips' => $taken, 'roses' => $id], self::storedAddresses());
    }

    /** An agent other than the library may neither create a category nor edit, move or delete one. */
    public function testAWriterOtherThanTheLibraryIsRefused(): void
    {
        $picture = self::pictureFile();
        $id = Hilos::$db->categories->actions->create('Roses', 'Fresh cut roses', $picture)->id;
        $next = self::pictureFile();
        $before = self::storedCount();

        self::assertRefusedToAnotherWriter(
            CreateNotAllowedException::class,
            static fn () => Hilos::$db->categories->actions->create('Lilies', 'White lilies', $next),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->categories[$id]->actions->update('Red roses', 'Garden roses', $next),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->categories[$id]->actions->moveUp(),
        );
        self::assertRefusedToAnotherWriter(
            WriteNotAllowedException::class,
            static fn () => Hilos::$db->categories[$id]->actions->delete(),
        );

        self::assertSame($before, self::storedCount());
        self::assertStoredUnchanged($id, 1, $picture);
    }

    /**
     * Checks a refused write left the category of its case as it was created: the row and the collection.
     *
     * @param int $id Category id
     * @param int $position Place the category was created at
     * @param int $picture Registry file the category was created with
     */
    private static function assertStoredUnchanged(int $id, int $position, int $picture): void
    {
        self::assertSame([[
            'name' => 'Roses',
            'description' => 'Fresh cut roses',
            'slug' => 'roses',
            'position' => $position,
            'pictureFileId' => $picture,
        ]], self::storedRows($id));
        self::assertSame('Roses', Hilos::$db->categories[$id]->name);
        self::assertSame('Fresh cut roses', Hilos::$db->categories[$id]->description);
        self::assertSame('roses', Hilos::$db->categories[$id]->slug);
        self::assertSame($picture, Hilos::$db->categories[$id]->pictureFileId);
    }

    /**
     * Runs a write that must be refused, and checks the refusal reaches the person word for word.
     *
     * @param class-string<ValidationException> $expected Exact refusal class
     * @param string $sentence The sentence the person is shown
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefused(string $expected, string $sentence, callable $write): void
    {
        try {
            $write();
        } catch (ValidationException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($sentence, $e->getMessage());
            self::assertSame($sentence, ActionFailureReason::forClient($e));

            return;
        }

        self::fail("Expected {$expected}: {$sentence}");
    }

    /**
     * Runs a write as the shop's app agent, which does not own the categories, and checks it is refused.
     *
     * @param class-string<HilosException> $expected Exact refusal class
     * @param callable(): mixed $write Write to refuse
     */
    private static function assertRefusedToAnotherWriter(string $expected, callable $write): void
    {
        try {
            ExecutionContext::run(new ExecutionFrame(agentId: AgentType::ECOMMERCE_SHOP), $write);
        } catch (HilosException $e) {
            self::assertSame($expected, $e::class);

            return;
        }

        self::fail("Expected {$expected} for a writer other than the categories library");
    }

    /**
     * Writes a registry row a category may show, with no file on disk behind it.
     *
     * @return int Id of the new registry row
     */
    private static function pictureFile(): int
    {
        $storedName = RandomHelper::hex(16) . '.webp';
        Database::sql(
            'INSERT INTO hilos_file (stored_name, filename, mime_type, size, content_hash, visibility, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$storedName, 'roses.webp', 'image/webp', 4, hash('sha256', $storedName), FileVisibility::PUBLIC->value, 1000],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $id Category id
     * @return list<array{name: string, description: string, slug: string, position: int, pictureFileId: int}> The stored
     *     category read past the ORM; empty when there is none
     */
    private static function storedRows(int $id): array
    {
        $rows = Database::sql('SELECT name, description, slug, position, picture_file_id FROM category WHERE id = ?', [$id])->rows();

        return array_map(static fn (array $row): array => [
            'name' => (string)$row['name'],
            'description' => (string)$row['description'],
            'slug' => (string)$row['slug'],
            'position' => (int)$row['position'],
            'pictureFileId' => (int)$row['picture_file_id'],
        ], $rows);
    }

    /**
     * @param int $id Category id
     * @return string The stored current address of the category, read past the ORM
     */
    private static function storedSlug(int $id): string
    {
        return self::storedRows($id)[0]['slug'];
    }

    /**
     * @param int $id Category id
     * @return int The stored place of the category in the catalog, read past the ORM
     */
    private static function storedPosition(int $id): int
    {
        return self::storedRows($id)[0]['position'];
    }

    /**
     * @return array<string, ?int> Every stored address, in the order it was recorded, to its category or to null once out of use
     */
    private static function storedAddresses(): array
    {
        $addresses = [];
        foreach (Database::sql('SELECT slug, category_id FROM category_slug ORDER BY id')->rows() as $row) {
            $addresses[(string)$row['slug']] = $row['category_id'] === null ? null : (int)$row['category_id'];
        }

        return $addresses;
    }

    /**
     * @return int Number of stored categories, read past the ORM
     */
    private static function storedCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS stored FROM category')->field('stored');
    }
}
