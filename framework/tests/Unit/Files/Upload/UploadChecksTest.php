<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Upload;

use Hilos\Files\Upload\Check\AllowedContentCheck;
use Hilos\Files\Upload\Check\DeclaredMimeCheck;
use Hilos\Files\Upload\Check\SizeLimitCheck;
use Hilos\Files\Upload\UploadDeclaration;
use Hilos\Files\Upload\UploadFailureCode;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Item\HilosUpload;
use PHPUnit\Framework\TestCase;

/**
 * Tests the three built-in upload checks (HIL-135).
 *
 * Each check speaks at one moment only - on the declaration or on the received file - and is
 * silent at the other; the silent half is asserted too, because the agent runs every check at
 * both moments.
 */
final class UploadChecksTest extends TestCase
{
    private const int LIMIT = 1000;

    public function testSizeLimitRefusesAnEmptyFile(): void
    {
        $refusal = (new SizeLimitCheck(self::LIMIT))->checkDeclared($this->declaration(size: 0));

        $this->assertNotNull($refusal);
        $this->assertSame(SizeLimitCheck::CODE_EMPTY, $refusal->code);
        $this->assertSame('File is empty', $refusal->message);
    }

    public function testSizeLimitAcceptsExactlyTheLimit(): void
    {
        $this->assertNull((new SizeLimitCheck(self::LIMIT))->checkDeclared($this->declaration(size: self::LIMIT)));
    }

    public function testSizeLimitRefusesOneByteAboveTheLimit(): void
    {
        $refusal = (new SizeLimitCheck(self::LIMIT))->checkDeclared($this->declaration(size: self::LIMIT + 1));

        $this->assertNotNull($refusal);
        $this->assertSame(SizeLimitCheck::CODE_TOO_LARGE, $refusal->code);
        $this->assertSame('File is larger than the allowed size', $refusal->message);
    }

    public function testDeclaredMimeRefusesAMalformedTypeEvenWithoutAList(): void
    {
        $refusal = (new DeclaredMimeCheck([]))->checkDeclared($this->declaration(mimeType: 'png'));

        $this->assertNotNull($refusal);
        $this->assertSame(DeclaredMimeCheck::CODE, $refusal->code);
        $this->assertSame('This file type is not allowed', $refusal->message);
    }

    public function testDeclaredMimeRefusesATypeOutsideTheList(): void
    {
        $refusal = (new DeclaredMimeCheck(['image/*']))->checkDeclared($this->declaration(mimeType: 'text/plain'));

        $this->assertNotNull($refusal);
        $this->assertSame(DeclaredMimeCheck::CODE, $refusal->code);
    }

    public function testDeclaredMimeAcceptsAnyWellFormedTypeWithAnEmptyList(): void
    {
        $this->assertNull((new DeclaredMimeCheck([]))->checkDeclared($this->declaration(mimeType: 'text/plain')));
        $this->assertNull((new DeclaredMimeCheck(['image/*']))->checkDeclared($this->declaration(mimeType: 'image/png')));
    }

    public function testAllowedContentRefusesContentOutsideTheList(): void
    {
        $refusal = (new AllowedContentCheck(['image/png']))->checkReceived($this->received('text/plain'));

        $this->assertNotNull($refusal);
        $this->assertSame(UploadFailureCode::CONTENT_MISMATCH, $refusal->code);
        $this->assertSame('File content does not match an allowed type', $refusal->message);
    }

    public function testAllowedContentRefusesContentWhoseTypeIsUnknown(): void
    {
        $this->assertNotNull((new AllowedContentCheck(['image/png']))->checkReceived($this->received(null)));
    }

    public function testAllowedContentAcceptsContentInTheListOrAnyWithoutOne(): void
    {
        $this->assertNull((new AllowedContentCheck(['image/*']))->checkReceived($this->received('image/png')));
        $this->assertNull((new AllowedContentCheck([]))->checkReceived($this->received('text/plain')));
    }

    public function testEachCheckIsSilentAtTheMomentItDoesNotJudge(): void
    {
        $received = $this->received('text/plain');

        $this->assertNull((new SizeLimitCheck(self::LIMIT))->checkReceived($received));
        $this->assertNull((new DeclaredMimeCheck(['image/png']))->checkReceived($received));
        $this->assertNull((new AllowedContentCheck(['image/png']))->checkDeclared($this->declaration(mimeType: 'text/plain')));
    }

    /**
     * @param int $size Declared size
     * @param string $mimeType Declared, normalized type
     * @return UploadDeclaration Declaration of one file
     */
    private function declaration(int $size = 10, string $mimeType = 'image/png'): UploadDeclaration
    {
        return new UploadDeclaration('accept-key', 'u1', 'avatar', null, 'a.png', $mimeType, $size);
    }

    /**
     * @param ?string $detectedMimeType Type read from the content, or null when none was read
     * @return HilosUpload Upload whose bytes have all arrived
     */
    private function received(?string $detectedMimeType): HilosUpload
    {
        return new HilosUpload(StateHilosUpload::fromRow([
            StateHilosUpload::acceptKey => 'accept-key',
            StateHilosUpload::clientUploadId => 'u1',
            StateHilosUpload::target => 'avatar',
            StateHilosUpload::userId => null,
            StateHilosUpload::filename => 'a.png',
            StateHilosUpload::mimeType => 'image/png',
            StateHilosUpload::declaredSize => 10,
            StateHilosUpload::receivedBytes => 10,
            StateHilosUpload::tmpIndex => 'abc',
            StateHilosUpload::phase => UploadPhase::UPLOADING->value,
            StateHilosUpload::detectedMimeType => $detectedMimeType,
            StateHilosUpload::errorCode => null,
            StateHilosUpload::errorMessage => null,
            StateHilosUpload::updatedAt => 1,
        ]));
    }
}
