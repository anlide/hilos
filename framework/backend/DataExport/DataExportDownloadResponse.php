<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use DateTimeZone;
use Hilos\Files\Download\PrivateDownloadResponse;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;

/** Delivers an authorized archive through nginx or a bounded direct reply to a browser this node holds. */
final readonly class DataExportDownloadResponse
{
    /**
     * @param HttpReplyDTO $reply HTTP response
     * @param bool $tooLarge Whether direct transport refused the size and the operator needs nginx
     * @param bool $onAnotherNode Whether the browser's connection is on another node and the operator needs nginx
     */
    private function __construct(public HttpReplyDTO $reply, public bool $tooLarge = false, public bool $onAnotherNode = false)
    {
    }

    /**
     * The archive travels as any private attachment does ({@see PrivateDownloadResponse}); this names it
     * by its readiness date in the zone it was written in, never by the moment it is downloaded.
     *
     * @param HttpRequestDTO $request Request being answered
     * @param FsFile $file Authorized archive on disk
     * @param string $finishedAt Archive readiness date in SQL form
     * @param string $xAccelLocation Internal nginx location, or empty for direct transport
     * @param ?string $localNodeId This node's effective id (`standalone` off a cluster), null without a cluster context
     * @param DateTimeZone $timeZone Zone the archive was written in, which dates its name
     * @return self Private download or refusal
     * @throws FsException When the existing file cannot be measured
     */
    public static function forFile(
        HttpRequestDTO $request,
        FsFile $file,
        string $finishedAt,
        string $xAccelLocation,
        ?string $localNodeId,
        DateTimeZone $timeZone,
    ): self {
        $response = PrivateDownloadResponse::forFile(
            $request,
            $file,
            'application/zip',
            'your-data-' . DataExportTime::date($finishedAt, $timeZone) . '.zip',
            $xAccelLocation,
            $localNodeId,
        );

        return new self($response->reply, $response->tooLarge, $response->onAnotherNode);
    }
}
