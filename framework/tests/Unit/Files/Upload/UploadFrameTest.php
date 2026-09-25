<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Upload;

use Hilos\Files\Upload\UploadFrame;
use PHPUnit\Framework\TestCase;

/**
 * Tests the signature every frame_binary chunk of an upload carries (HIL-135).
 *
 * A frame whose signature cannot be read must come back as null and never as a chunk of some
 * upload: the agent drops such a frame, and a lenient reader would put its bytes into a file.
 */
final class UploadFrameTest extends TestCase
{
    public function testASignedFrameYieldsItsIdAndBytes(): void
    {
        $frame = UploadFrame::parse(chr(3) . 'u-1' . "\x00\xffdata");

        $this->assertNotNull($frame);
        $this->assertSame('u-1', $frame->clientUploadId);
        $this->assertSame("\x00\xffdata", $frame->bytes);
    }

    public function testAnIdOfTheLongestLengthIsRead(): void
    {
        $id = str_repeat('a', UploadFrame::MAX_ID_LENGTH);

        $frame = UploadFrame::parse(chr(UploadFrame::MAX_ID_LENGTH) . $id . 'x');

        $this->assertNotNull($frame);
        $this->assertSame($id, $frame->clientUploadId);
        $this->assertSame('x', $frame->bytes);
    }

    public function testAnEmptyTailIsAChunkOfNoBytes(): void
    {
        $frame = UploadFrame::parse(chr(2) . 'ab');

        $this->assertNotNull($frame);
        $this->assertSame('', $frame->bytes);
    }

    public function testAZeroLengthIsNoSignature(): void
    {
        $this->assertNull(UploadFrame::parse(chr(0) . 'data'));
    }

    public function testALengthAboveTheLongestIdIsNoSignature(): void
    {
        $length = UploadFrame::MAX_ID_LENGTH + 1;

        $this->assertNull(UploadFrame::parse(chr($length) . str_repeat('a', $length) . 'data'));
    }

    public function testAFrameShorterThanItsSignatureIsNoSignature(): void
    {
        $this->assertNull(UploadFrame::parse(chr(5) . 'abc'));
        $this->assertNull(UploadFrame::parse(''));
    }

    public function testAnIdOutsideTheAlphabetIsNoSignature(): void
    {
        $this->assertNull(UploadFrame::parse(chr(3) . 'a|b' . 'data'));
        $this->assertNull(UploadFrame::parse(chr(3) . 'a b' . 'data'));
    }

    public function testTheIdAlphabetAcceptsAUuid(): void
    {
        $this->assertTrue(UploadFrame::isValidId('7f3c2a10-5b1e-4c3d-9a2b-0e1f2a3b4c5d'));
        $this->assertTrue(UploadFrame::isValidId('A_z-9'));
        $this->assertFalse(UploadFrame::isValidId(''));
        $this->assertFalse(UploadFrame::isValidId(str_repeat('a', UploadFrame::MAX_ID_LENGTH + 1)));
    }
}
