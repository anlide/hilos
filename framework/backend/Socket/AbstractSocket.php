<?php

declare(strict_types=1);

namespace Hilos\Socket;

use Hilos\Constants\SocketConstants;
use Hilos\Socket\Exception\Base\AccessDeniedException;
use Hilos\Socket\Exception\Base\AddressFamilyNotSupportedException;
use Hilos\Socket\Exception\Base\AddressInUseException;
use Hilos\Socket\Exception\Base\AddressNotAvailableException;
use Hilos\Socket\Exception\Base\AlreadyConnectedException;
use Hilos\Socket\Exception\Base\BrokenPipeException;
use Hilos\Socket\Exception\Base\ConnectionAbortedException;
use Hilos\Socket\Exception\Base\ConnectionRefusedException;
use Hilos\Socket\Exception\Base\ConnectionResetException;
use Hilos\Socket\Exception\Base\ConnectionTimeoutException;
use Hilos\Socket\Exception\Base\HostUnreachableException;
use Hilos\Socket\Exception\Base\InterruptedException;
use Hilos\Socket\Exception\Base\InvalidSocketException;
use Hilos\Socket\Exception\Base\MessageTooLongException;
use Hilos\Socket\Exception\Base\NetworkDownException;
use Hilos\Socket\Exception\Base\NetworkResetException;
use Hilos\Socket\Exception\Base\NetworkUnreachableException;
use Hilos\Socket\Exception\Base\NoBufferSpaceException;
use Hilos\Socket\Exception\Base\NotConnectedException;
use Hilos\Socket\Exception\Base\OperationNotPermittedException;
use Hilos\Socket\Exception\Base\OutOfMemoryException;
use Hilos\Socket\Exception\Base\TooManyOpenFilesException;
use Hilos\Socket\Exception\SocketAcceptException;
use Hilos\Socket\Exception\SocketBindException;
use Hilos\Socket\Exception\SocketCloseException;
use Hilos\Socket\Exception\SocketConnectException;
use Hilos\Socket\Exception\SocketCreateException;
use Hilos\Socket\Exception\SocketGetPeerNameException;
use Hilos\Socket\Exception\SocketListenException;
use Hilos\Socket\Exception\SocketReadException;
use Hilos\Socket\Exception\SocketSetNonBlockException;
use Hilos\Socket\Exception\SocketSetOptionException;
use Hilos\Socket\Exception\SocketWriteException;

/**
 * AbstractSocket - Abstract base class for socket operations.
 *
 * Provides common socket error handling for both clients and servers.
 */
abstract class AbstractSocket
{
    /** @var resource|object|null Socket resource (client socket for clients, server socket for servers) */
    protected $socket = null;

    /** Socket error code constants (Linux/Unix codes) */
    protected const int ERR_NO_ERROR            = 0;    // No error
    protected const int ERR_PERM                = 1;    // EPERM
    protected const int ERR_INTERRUPTED         = 4;    // EINTR
    protected const int ERR_BAD_FD              = 9;    // EBADF
    protected const int ERR_WOULDBLOCK          = 11;   // EAGAIN/EWOULDBLOCK
    protected const int ERR_NO_MEM              = 12;   // ENOMEM
    protected const int ERR_ACCESS              = 13;   // EACCES
    protected const int ERR_TOO_MANY_FILES_SYS  = 23;   // ENFILE (system-wide limit)
    protected const int ERR_TOO_MANY_FILES      = 24;   // EMFILE (per-process limit)
    protected const int ERR_BROKEN_PIPE         = 32;   // EPIPE
    protected const int ERR_MSG_TOO_LONG        = 90;   // EMSGSIZE
    protected const int ERR_AFNOSUPPORT         = 97;   // EAFNOSUPPORT
    protected const int ERR_ADDR_IN_USE         = 98;   // EADDRINUSE
    protected const int ERR_ADDR_NOT_AVAIL      = 99;   // EADDRNOTAVAIL
    protected const int ERR_NET_DOWN            = 100;  // ENETDOWN
    protected const int ERR_NET_UNREACH         = 101;  // ENETUNREACH
    protected const int ERR_NET_RESET           = 102;  // ENETRESET
    protected const int ERR_CONN_ABORTED        = 103;  // ECONNABORTED
    protected const int ERR_CONN_RESET          = 104;  // ECONNRESET
    protected const int ERR_NO_BUFS             = 105;  // ENOBUFS
    protected const int ERR_ALREADY_CONN        = 106;  // EISCONN
    protected const int ERR_NOT_CONN            = 107;  // ENOTCONN
    protected const int ERR_TIMEDOUT            = 110;  // ETIMEDOUT
    protected const int ERR_CONN_REFUSED        = 111;  // ECONNREFUSED
    protected const int ERR_HOST_UNREACH        = 113;  // EHOSTUNREACH
    protected const int ERR_INPROGRESS          = 115;  // EINPROGRESS

    /** Windows socket error code constants (WSA codes) */
    protected const int WSA_WOULDBLOCK      = 10035;   // WSAEWOULDBLOCK
    protected const int WSA_NOT_SOCK        = 10038;   // WSAENOTSOCK
    protected const int WSA_MSG_TOO_LONG    = 10040;   // WSAEMSGSIZE
    protected const int WSA_AFNOSUPPORT     = 10047;   // WSAEAFNOSUPPORT
    protected const int WSA_ADDR_IN_USE     = 10048;   // WSAEADDRINUSE
    protected const int WSA_ADDR_NOT_AVAIL  = 10049;   // WSAEADDRNOTAVAIL
    protected const int WSA_NET_DOWN        = 10050;   // WSAENETDOWN
    protected const int WSA_NET_UNREACH     = 10051;   // WSAENETUNREACH
    protected const int WSA_NET_RESET       = 10052;   // WSAENETRESET
    protected const int WSA_CONN_ABORTED    = 10053;   // WSAECONNABORTED
    protected const int WSA_CONN_RESET      = 10054;   // WSAECONNRESET
    protected const int WSA_NO_BUFS         = 10055;   // WSAENOBUFS
    protected const int WSA_ALREADY_CONN    = 10056;   // WSAEISCONN
    protected const int WSA_NOT_CONN        = 10057;   // WSANOTCONN
    protected const int WSA_SHUTDOWN        = 10058;   // WSAESHUTDOWN
    protected const int WSA_TIMEDOUT        = 10060;   // WSAETIMEDOUT
    protected const int WSA_CONN_REFUSED    = 10061;   // WSAECONNREFUSED
    protected const int WSA_HOST_UNREACH    = 10065;   // WSAEHOSTUNREACH

    /**
     * Handle socket error (unified method for all socket operations)
     *
     * Handles 23 most common socket error codes that can occur under high load.
     * All other errors are handled by generic exceptions specific to each operation.
     *
     * Note: Error codes differ between Linux/Unix and Windows (WSA codes).
     * This implementation uses Linux/Unix codes, but also handles common Windows codes.
     *
     * Note: For socket_create, $this->socket may be null, so we use socket_last_error() without parameter.
     * For other operations, $this->socket should be set.
     *
     * @param SocketOperation $operation Operation type
     * @throws SocketException When socket error occurs (various subclasses based on error code)
     */
    protected function handleSocketError(SocketOperation $operation): void
    {
        // For socket_create, use socket_last_error() without socket parameter
        // For other operations, use socket_last_error($this->socket)
        // Check if socket is still a valid resource before calling socket_last_error
        $errorCode = 0;
        if ($this->socket !== null && (is_resource($this->socket) || is_object($this->socket))) {
            try {
                $errorCode = socket_last_error($this->socket);
            } catch (\Throwable $e) {
                // Socket may be already closed, use global error
                $errorCode = socket_last_error();
            }
        } else {
            $errorCode = socket_last_error();
        }
        $errorMessage = socket_strerror($errorCode);

        // Reset error state
        if ($this->socket !== null && (is_resource($this->socket) || is_object($this->socket))) {
            try {
                socket_clear_error($this->socket);
            } catch (\Throwable $e) {
                // Socket may be already closed, use global clear
                socket_clear_error();
            }
        } else {
            socket_clear_error();
        }

        // Handle specific error codes
        switch ($errorCode) {
            case self::ERR_NO_ERROR:
                // No error
                return;

            case self::ERR_WOULDBLOCK:
            case self::WSA_WOULDBLOCK:
                // EAGAIN/EWOULDBLOCK (11) / WSAEWOULDBLOCK (10035) - operation would block
                // In non-blocking mode, this is normal - just return
                return;

            case self::ERR_INPROGRESS:
                // EINPROGRESS (115) - connection in progress (normal for non-blocking connect)
                // In non-blocking mode, this means connection started but not yet complete
                return;

            case self::ERR_CONN_RESET:
            case self::WSA_CONN_RESET:
                // ECONNRESET (104) / WSAECONNRESET (10054) - connection reset by peer
                $this->markShouldClose();
                throw new ConnectionResetException();

            case self::ERR_BROKEN_PIPE:
            case self::WSA_SHUTDOWN:
                // EPIPE (32) / WSAESHUTDOWN (10058) - broken pipe
                $this->markShouldClose();
                throw new BrokenPipeException();

            case self::ERR_NOT_CONN:
            case self::WSA_NOT_CONN:
                // ENOTCONN (107) / WSANOTCONN (10057) - not connected
                $this->markShouldClose();
                throw new NotConnectedException();

            case self::ERR_BAD_FD:
            case self::WSA_NOT_SOCK:
                // EBADF (9) / WSAENOTSOCK (10038) - bad file descriptor
                $this->markShouldClose();
                throw new InvalidSocketException();

            case self::ERR_INTERRUPTED:
                // EINTR (4) - interrupted
                $this->markShouldClose();
                throw new InterruptedException();

            case self::ERR_ADDR_IN_USE:
            case self::WSA_ADDR_IN_USE:
                // EADDRINUSE (98) / WSAEADDRINUSE (10048) - address already in use
                $this->markShouldClose();
                throw new AddressInUseException();

            case self::ERR_ADDR_NOT_AVAIL:
            case self::WSA_ADDR_NOT_AVAIL:
                // EADDRNOTAVAIL (99) / WSAEADDRNOTAVAIL (10049) - address not available
                $this->markShouldClose();
                throw new AddressNotAvailableException();

            case self::ERR_ALREADY_CONN:
            case self::WSA_ALREADY_CONN:
                // EISCONN (106) / WSAEISCONN (10056) - socket is already connected
                $this->markShouldClose();
                throw new AlreadyConnectedException();

            case self::ERR_TIMEDOUT:
            case self::WSA_TIMEDOUT:
                // ETIMEDOUT (110) / WSAETIMEDOUT (10060) - connection timed out
                $this->markShouldClose();
                throw new ConnectionTimeoutException();

            case self::ERR_CONN_REFUSED:
            case self::WSA_CONN_REFUSED:
                // ECONNREFUSED (111) / WSAECONNREFUSED (10061) - connection refused
                $this->markShouldClose();
                throw new ConnectionRefusedException();

            case self::ERR_HOST_UNREACH:
            case self::WSA_HOST_UNREACH:
                // EHOSTUNREACH (113) / WSAEHOSTUNREACH (10065) - host unreachable
                $this->markShouldClose();
                throw new HostUnreachableException();

            case self::ERR_MSG_TOO_LONG:
            case self::WSA_MSG_TOO_LONG:
                // EMSGSIZE (90) / WSAEMSGSIZE (10040) - message too long
                $this->markShouldClose();
                throw new MessageTooLongException();

            case self::ERR_NET_DOWN:
            case self::WSA_NET_DOWN:
                // ENETDOWN (100) / WSAENETDOWN (10050) - network is down
                $this->markShouldClose();
                throw new NetworkDownException();

            case self::ERR_NET_UNREACH:
            case self::WSA_NET_UNREACH:
                // ENETUNREACH (101) / WSAENETUNREACH (10051) - network unreachable
                $this->markShouldClose();
                throw new NetworkUnreachableException();

            case self::ERR_NO_BUFS:
            case self::WSA_NO_BUFS:
                // ENOBUFS (105) / WSAENOBUFS (10055) - no buffer space available
                $this->markShouldClose();
                throw new NoBufferSpaceException();

            case self::ERR_AFNOSUPPORT:
            case self::WSA_AFNOSUPPORT:
                // EAFNOSUPPORT (97) / WSAEAFNOSUPPORT (10047) - address family not supported
                $this->markShouldClose();
                throw new AddressFamilyNotSupportedException();

            case self::ERR_CONN_ABORTED:
            case self::WSA_CONN_ABORTED:
                // ECONNABORTED (103) / WSAECONNABORTED (10053) - connection aborted
                $this->markShouldClose();
                throw new ConnectionAbortedException();

            case self::ERR_NET_RESET:
            case self::WSA_NET_RESET:
                // ENETRESET (102) / WSAENETRESET (10052) - network dropped connection on reset
                $this->markShouldClose();
                throw new NetworkResetException();

            case self::ERR_ACCESS:
                // EACCES (13) - permission denied
                $this->markShouldClose();
                throw new AccessDeniedException();

            case self::ERR_TOO_MANY_FILES:
            case self::ERR_TOO_MANY_FILES_SYS:
                // EMFILE (24) / ENFILE (23) - too many open files
                $this->markShouldClose();
                throw new TooManyOpenFilesException();

            case self::ERR_PERM:
                // EPERM (1) - operation not permitted
                $this->markShouldClose();
                throw new OperationNotPermittedException();

            case self::ERR_NO_MEM:
                // ENOMEM (12) - out of memory
                $this->markShouldClose();
                throw new OutOfMemoryException();

            default:
                // Other errors (there are 100+ possible socket error codes)
                // Throw generic exception based on operation type
                match ($operation) {
                    SocketOperation::READ => throw new SocketReadException($errorCode, $errorMessage),
                    SocketOperation::WRITE => throw new SocketWriteException($errorCode, $errorMessage),
                    SocketOperation::CLOSE => throw new SocketCloseException($errorCode, $errorMessage),
                    SocketOperation::GETPEERNAME => throw new SocketGetPeerNameException($errorCode, $errorMessage),
                    SocketOperation::CREATE => throw new SocketCreateException($errorCode, $errorMessage),
                    SocketOperation::SET_OPTION => throw new SocketSetOptionException($errorCode, $errorMessage),
                    SocketOperation::SET_NONBLOCK => throw new SocketSetNonBlockException($errorCode, $errorMessage),
                    SocketOperation::CONNECT => throw new SocketConnectException($errorCode, $errorMessage),
                    SocketOperation::BIND => throw new SocketBindException($errorCode, $errorMessage),
                    SocketOperation::LISTEN => throw new SocketListenException($errorCode, $errorMessage),
                    SocketOperation::ACCEPT => throw new SocketAcceptException($errorCode, $errorMessage),
                };
        }
    }

    /**
     * Get socket resource
     *
     * @return resource|object|null Socket resource (may be null after close)
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Extract complete JSON message from buffer
     *
     * Parses JSON by tracking bracket depth, handling nested objects and arrays.
     * Safely handles JSON objects that may contain newlines in strings.
     * Returns null if message is incomplete.
     *
     * Throws SocketException if buffer exceeds maximum size or JSON depth is too high.
     *
     * @param string $readBuffer Read buffer (will be modified to remove extracted message)
     * @return ?string Complete JSON message or null if incomplete
     * @throws SocketException If buffer size or JSON depth exceeds limits
     */
    protected function extractCompleteJsonMessage(string &$readBuffer): ?string
    {
        $buffer = $readBuffer;
        $length = strlen($buffer);

        // Check buffer size limit to prevent DoS attacks
        if ($length > SocketConstants::MAX_READ_BUFFER_SIZE) {
            $this->markShouldClose();
            throw new MessageTooLongException();
        }

        $depth = 0; // Object/array depth
        $inString = false; // Whether we're inside a string
        $escapeNext = false; // Whether next character is escaped
        $startPos = -1; // Start position of JSON object

        for ($i = 0; $i < $length; $i++) {
            $char = $buffer[$i];

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }

            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            // Only process brackets when not in string
            if (!$inString) {
                if ($char === '{' || $char === '[') {
                    if ($startPos === -1) {
                        $startPos = $i; // Start of JSON object
                    }
                    $depth++;

                    // Check JSON nesting depth to prevent stack overflow
                    if ($depth > SocketConstants::MAX_JSON_DEPTH) {
                        $this->markShouldClose();
                        throw new MessageTooLongException();
                    }
                } elseif ($char === '}' || $char === ']') {
                    $depth--;
                    if ($depth === 0 && $startPos !== -1) {
                        // Complete JSON object found
                        $jsonEnd = $i + 1;

                        // Check if followed by newline
                        if ($jsonEnd < $length && $buffer[$jsonEnd] === "\n") {
                            $message = substr($buffer, $startPos, $jsonEnd - $startPos);
                            $readBuffer = substr($buffer, $jsonEnd + 1);
                            return $message;
                        }

                        // If no newline, message is incomplete
                        return null;
                    }
                }
            }
        }

        // No complete message found
        return null;
    }

    /**
     * Mark socket for closing (must be implemented by child classes)
     *
     * For clients: sets shouldClose flag to true.
     * For servers: empty implementation (servers don't auto-close on errors).
     */
    abstract public function markShouldClose(): void;
}
