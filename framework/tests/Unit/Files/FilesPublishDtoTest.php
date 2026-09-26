<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\DTO\FilePublishItemData;
use Hilos\Files\DTO\FilePublishSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\FileVisibility;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the three frames of a publication and its answer (HIL-136).
 *
 * The request is read back at the door, so what its reader refuses is what the door refuses; the
 * frame to the library carries the same list; the answer has to say one thing only - published
 * with an id for each upload, or refused with none.
 */
final class FilesPublishDtoTest extends TestCase
{
    private const string ACCEPT_KEY = 'accept-key';

    private const string REPLY = 'gallery_published';

    public function testThePublishRequestTravelsWhole(): void
    {
        $request = UploadPublishSignalData::fromArray(self::request());

        $this->assertSame(['u1', 'u2'], $request->clientUploadIds);
        $this->assertSame(self::request(), $request->toArray());
        $this->assertEquals($request, UploadPublishSignalData::fromArray($request->toArray()));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}> Requests the reader refuses
     */
    public static function malformedRequests(): iterable
    {
        yield 'no upload id' => [[UploadPublishSignalData::clientUploadIds => []] + self::request()];
        yield 'an id twice' => [[UploadPublishSignalData::clientUploadIds => ['u1', 'u1']] + self::request()];
        yield 'an id off the wire alphabet' => [[UploadPublishSignalData::clientUploadIds => ['u 1']] + self::request()];
        yield 'an id that is not a string' => [[UploadPublishSignalData::clientUploadIds => [7]] + self::request()];
        yield 'ids that are not a list' => [[UploadPublishSignalData::clientUploadIds => ['a' => 'u1']] + self::request()];
        yield 'an unknown visibility' => [[UploadPublishSignalData::visibility => 'friends'] + self::request()];
        yield 'an empty target' => [[UploadPublishSignalData::target => ''] + self::request()];
        yield 'an empty reply name' => [[UploadPublishSignalData::replySignal => ''] + self::request()];
        yield 'no accept key' => [array_diff_key(self::request(), [UploadPublishSignalData::acceptKey => true])];
    }

    /**
     * @param array<string, mixed> $payload Request the reader refuses
     */
    #[DataProvider('malformedRequests')]
    public function testAMalformedPublishRequestIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        UploadPublishSignalData::fromArray($payload);
    }

    public function testTheFrameToTheLibraryTravelsWhole(): void
    {
        $frame = FilePublishSignalData::fromArray(self::libraryFrame());

        $this->assertCount(2, $frame->files);
        $this->assertSame('tmp-u2', $frame->files[1]->tmpIndex);
        $this->assertSame(self::libraryFrame(), $frame->toArray());
    }

    public function testTheFrameToTheLibraryCarriesAFileForEachId(): void
    {
        $payload = self::libraryFrame();
        $payload[FilePublishSignalData::files] = [self::item('u1')];

        $this->expectException(InvalidFormatException::class);
        FilePublishSignalData::fromArray($payload);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}> Files the reader refuses
     */
    public static function malformedItems(): iterable
    {
        yield 'no owner' => [[FilePublishItemData::ownerUserId => 0] + self::item('u1')];
        yield 'a fingerprint in upper case' => [[FilePublishItemData::contentHash => strtoupper(hash('sha256', 'u1'))] + self::item('u1')];
        yield 'a fingerprint too short' => [[FilePublishItemData::contentHash => 'abc'] + self::item('u1')];
        yield 'no size' => [array_diff_key(self::item('u1'), [FilePublishItemData::size => true])];
    }

    /**
     * @param array<string, mixed> $item File the reader refuses
     */
    #[DataProvider('malformedItems')]
    public function testAMalformedFileIsRefused(array $item): void
    {
        $this->expectException(InvalidFormatException::class);
        FilePublishItemData::fromArray($item);
    }

    public function testAPublishedAnswerCarriesAnIdForEachUploadAndIsBoundToTheConnection(): void
    {
        $answer = FilesPublishedSignalData::published(FilePublishSignalData::fromArray(self::libraryFrame()), [11, 12]);

        $this->assertSame(self::ACCEPT_KEY, $answer->getAcceptKey());
        $this->assertSame([
            FilesPublishedSignalData::acceptKey => self::ACCEPT_KEY,
            FilesPublishedSignalData::clientUploadIds => ['u1', 'u2'],
            FilesPublishedSignalData::fileIds => [11, 12],
            FilesPublishedSignalData::error => null,
        ], $answer->toArray());
        $this->assertEquals($answer, FilesPublishedSignalData::fromArray($answer->toArray()));
    }

    public function testARefusedAnswerCarriesTheSentenceAndNoId(): void
    {
        $answer = FilesPublishedSignalData::refused(self::ACCEPT_KEY, ['u1'], 'Sign in to keep this file');

        $this->assertSame([], $answer->fileIds);
        $this->assertSame('Sign in to keep this file', $answer->error);
        $this->assertEquals($answer, FilesPublishedSignalData::fromArray($answer->toArray()));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}> Answers the reader refuses
     */
    public static function malformedAnswers(): iterable
    {
        $published = [
            FilesPublishedSignalData::acceptKey => self::ACCEPT_KEY,
            FilesPublishedSignalData::clientUploadIds => ['u1', 'u2'],
            FilesPublishedSignalData::fileIds => [11, 12],
            FilesPublishedSignalData::error => null,
        ];
        yield 'published with an id missing' => [[FilesPublishedSignalData::fileIds => [11]] + $published];
        yield 'refused with ids' => [[FilesPublishedSignalData::error => 'No'] + $published];
        yield 'no error key at all' => [array_diff_key($published, [FilesPublishedSignalData::error => true])];
        yield 'an id that is not positive' => [[FilesPublishedSignalData::fileIds => [11, 0]] + $published];
    }

    /**
     * @param array<string, mixed> $payload Answer the reader refuses
     */
    #[DataProvider('malformedAnswers')]
    public function testAMalformedAnswerIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        FilesPublishedSignalData::fromArray($payload);
    }

    /**
     * @return array<string, mixed> A well-formed publish request of two uploads
     */
    private static function request(): array
    {
        return [
            UploadPublishSignalData::acceptKey => self::ACCEPT_KEY,
            UploadPublishSignalData::target => 'gallery',
            UploadPublishSignalData::clientUploadIds => ['u1', 'u2'],
            UploadPublishSignalData::visibility => FileVisibility::OWNER->value,
            UploadPublishSignalData::replySignal => self::REPLY,
        ];
    }

    /**
     * @return array<string, mixed> A well-formed frame to the library of two files
     */
    private static function libraryFrame(): array
    {
        return [
            FilePublishSignalData::acceptKey => self::ACCEPT_KEY,
            FilePublishSignalData::clientUploadIds => ['u1', 'u2'],
            FilePublishSignalData::visibility => FileVisibility::OWNER->value,
            FilePublishSignalData::replySignal => self::REPLY,
            FilePublishSignalData::files => [self::item('u1'), self::item('u2')],
        ];
    }

    /**
     * @param string $clientUploadId Upload the file came from
     * @return array<string, mixed> A well-formed file of the frame
     */
    private static function item(string $clientUploadId): array
    {
        return [
            FilePublishItemData::tmpIndex => 'tmp-' . $clientUploadId,
            FilePublishItemData::filename => $clientUploadId . '.png',
            FilePublishItemData::mimeType => 'image/png',
            FilePublishItemData::size => 10,
            FilePublishItemData::ownerUserId => 7,
            FilePublishItemData::contentHash => hash('sha256', $clientUploadId),
        ];
    }
}
