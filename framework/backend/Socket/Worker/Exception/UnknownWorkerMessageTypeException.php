<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\Exception;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\WebSocket\Exception\UnknownOpcodeException;
use Hilos\Socket\Worker\WorkerDTO;
use Throwable;

/**
 * A worker-channel frame names a message type the registry does not know.
 *
 * The frame said what it is and the wire it came over has no such kind: the same case
 * {@see UnknownOpcodeException} reports one floor below, where a WebSocket frame names
 * an opcode no reader implements. Told apart from a frame with no type at all, which
 * {@see WorkerDTO::factoryWorkerDTO()} refuses with the plain format exception, because
 * an unknown name usually means the two ends of the channel disagree about the protocol
 * while a missing one means the sender built the frame wrong.
 *
 * Extends {@see InvalidFormatException} and takes the marker for malformed input with
 * it: whichever of the two it is, the input could not be parsed into a frame this node
 * knows how to read.
 */
class UnknownWorkerMessageTypeException extends InvalidFormatException
{
    /**
     * Creates exception naming the type the registry has no entry for.
     *
     * @param string $type Message type the frame named
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $type, ?Throwable $previous = null)
    {
        parent::__construct("Unknown worker message type: {$type}", 0, $previous);
    }
}
