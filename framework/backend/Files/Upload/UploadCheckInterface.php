<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\HilosException;
use Hilos\Runtime\View\Item\HilosUpload;

/**
 * One check an upload passes: once on what was declared, once on what arrived (HIL-135).
 *
 * The uploads agent runs the built-in checks first and then the target's
 * {@see AbstractUploadTarget::extraChecks()} in their order; the first refusal wins. A check
 * with nothing to say at one of the two moments answers null there.
 */
interface UploadCheckInterface
{
    /**
     * Judges the declaration, before any byte is accepted.
     *
     * @param UploadDeclaration $declaration What the browser declared, in its checked shape
     * @return ?UploadRefusal Refusal of the declaration, or null when the check lets it through
     * @throws HilosException When the check cannot read what it judges by - a setting, the registry, the uploads
     */
    public function checkDeclared(UploadDeclaration $declaration): ?UploadRefusal;

    /**
     * Judges the whole received file, before the upload becomes complete.
     *
     * @param HilosUpload $upload Upload whose bytes have all arrived, with its detected type when the target sniffs
     * @return ?UploadRefusal Refusal that fails the upload, or null when the check lets it through
     * @throws HilosException When the check cannot read what it judges by - a setting, the registry, the uploads
     */
    public function checkReceived(HilosUpload $upload): ?UploadRefusal;
}
