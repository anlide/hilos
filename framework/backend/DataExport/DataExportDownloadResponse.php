<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\Constants\TimeConstants;
use Hilos\Files\Download\PrivateDownloadResponse;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Utils\Helpers\TimeHelper;

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
     * The archive travels as any private attachment does ({@see PrivateDownloadResponse}); this names it.
     *
     * @param HttpRequestDTO $request Request being answered
     * @param FsFile $file Authorized archive on disk
     * @param string $finishedAt Archive readiness date in SQL form
     * @param string $xAccelLocation Internal nginx location, or empty for direct transport
     * @param ?string $localNodeId This node's id, null off a cluster
     * @return self Private download or refusal
     * @throws FsException When the existing file cannot be measured
     */
    public static function forFile(
        HttpRequestDTO $request,
        FsFile $file,
        string $finishedAt,
        string $xAccelLocation,
        ?string $localNodeId,
    ): self {
        $response = PrivateDownloadResponse::forFile(
            $request,
            $file,
            'application/zip',
            'your-data-' . gmdate('Y-m-d', intdiv(TimeHelper::sqlToMs($finishedAt), TimeConstants::MS_PER_SECOND)) . '.zip',
            $xAccelLocation,
            $localNodeId,
        );

        return new self($response->reply, $response->tooLarge, $response->onAnotherNode);
    }
}
