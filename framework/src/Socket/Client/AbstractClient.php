<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Exception\MissingEnvironmentVariableException;
use Hilos\Exception\Socket\Base\AccessDeniedException;
use Hilos\Exception\Socket\Base\AddressFamilyNotSupportedException;
use Hilos\Exception\Socket\Base\AddressInUseException;
use Hilos\Exception\Socket\Base\AddressNotAvailableException;
use Hilos\Exception\Socket\Base\AlreadyConnectedException;
use Hilos\Exception\Socket\Base\BrokenPipeException;
use Hilos\Exception\Socket\Base\ConnectionAbortedException;
use Hilos\Exception\Socket\Base\ConnectionRefusedException;
use Hilos\Exception\Socket\Base\ConnectionResetException;
use Hilos\Exception\Socket\Base\ConnectionTimeoutException;
use Hilos\Exception\Socket\Base\HostUnreachableException;
use Hilos\Exception\Socket\Base\InterruptedException;
use Hilos\Exception\Socket\Base\InvalidSocketException;
use Hilos\Exception\Socket\Base\MessageTooLongException;
use Hilos\Exception\Socket\Base\NetworkDownException;
use Hilos\Exception\Socket\Base\NetworkResetException;
use Hilos\Exception\Socket\Base\NetworkUnreachableException;
use Hilos\Exception\Socket\Base\NoBufferSpaceException;
use Hilos\Exception\Socket\Base\NotConnectedException;
use Hilos\Exception\Socket\Base\OperationNotPermittedException;
use Hilos\Exception\Socket\Base\OutOfMemoryException;
use Hilos\Exception\Socket\Base\TooManyOpenFilesException;
use Hilos\Exception\Socket\SocketCloseException;
use Hilos\Exception\Socket\SocketGetPeerNameException;
use Hilos\Exception\Socket\SocketReadException;
use Hilos\Exception\Socket\SocketWriteException;
use Hilos\Exception\SocketException;
use Hilos\Socket\SocketOperation;
use Hilos\Utils\Constants\EnvConstants;
use Hilos\Utils\Constants\HttpConstants;
use Hilos\Utils\Env;

/**
 * AbstractClient - Abstract base class for client implementations
 *
 * Provides common functionality for all client types.
 */
abstract class AbstractClient implements ClientInterface
{
    /** Read buffer size in bytes - can be overridden in child classes or via config */
    protected int $readBufferSize;

    /** Socket error code constants (Linux/Unix codes) */
    private const int ERR_NO_ERROR            = 0;    // No error
    private const int ERR_PERM                = 1;    // EPERM
    private const int ERR_INTERRUPTED         = 4;    // EINTR
    private const int ERR_BAD_FD              = 9;    // EBADF
    private const int ERR_WOULDBLOCK          = 11;   // EAGAIN/EWOULDBLOCK
    private const int ERR_NO_MEM              = 12;   // ENOMEM
    private const int ERR_ACCESS              = 13;   // EACCES
    private const int ERR_TOO_MANY_FILES_SYS  = 23;   // ENFILE (system-wide limit)
    private const int ERR_TOO_MANY_FILES      = 24;   // EMFILE (per-process limit)
    private const int ERR_BROKEN_PIPE         = 32;   // EPIPE
    private const int ERR_MSG_TOO_LONG        = 90;   // EMSGSIZE
    private const int ERR_AFNOSUPPORT         = 97;   // EAFNOSUPPORT
    private const int ERR_ADDR_IN_USE         = 98;   // EADDRINUSE
    private const int ERR_ADDR_NOT_AVAIL      = 99;   // EADDRNOTAVAIL
    private const int ERR_NET_DOWN            = 100;  // ENETDOWN
    private const int ERR_NET_UNREACH         = 101;  // ENETUNREACH
    private const int ERR_NET_RESET           = 102;  // ENETRESET
    private const int ERR_CONN_ABORTED        = 103;  // ECONNABORTED
    private const int ERR_CONN_RESET          = 104;  // ECONNRESET
    private const int ERR_NO_BUFS             = 105;  // ENOBUFS
    private const int ERR_ALREADY_CONN        = 106;  // EISCONN
    private const int ERR_NOT_CONN            = 107;  // ENOTCONN
    private const int ERR_TIMEDOUT            = 110;  // ETIMEDOUT
    private const int ERR_CONN_REFUSED        = 111;  // ECONNREFUSED
    private const int ERR_HOST_UNREACH        = 113;  // EHOSTUNREACH

    /** Windows socket error code constants (WSA codes) */
    private const int WSA_WOULDBLOCK     = 10035;   // WSAEWOULDBLOCK
    private const int WSA_NOT_SOCK        = 10038;   // WSAENOTSOCK
    private const int WSA_MSG_TOO_LONG    = 10040;   // WSAEMSGSIZE
    private const int WSA_AFNOSUPPORT    = 10047;   // WSAEAFNOSUPPORT
    private const int WSA_ADDR_IN_USE     = 10048;   // WSAEADDRINUSE
    private const int WSA_ADDR_NOT_AVAIL  = 10049;   // WSAEADDRNOTAVAIL
    private const int WSA_NET_DOWN        = 10050;   // WSAENETDOWN
    private const int WSA_NET_UNREACH     = 10051;   // WSAENETUNREACH
    private const int WSA_NET_RESET       = 10052;   // WSAENETRESET
    private const int WSA_CONN_ABORTED    = 10053;   // WSAECONNABORTED
    private const int WSA_CONN_RESET      = 10054;   // WSAECONNRESET
    private const int WSA_NO_BUFS         = 10055;   // WSAENOBUFS
    private const int WSA_ALREADY_CONN    = 10056;   // WSAEISCONN
    private const int WSA_NOT_CONN        = 10057;   // WSANOTCONN
    private const int WSA_SHUTDOWN        = 10058;   // WSAESHUTDOWN
    private const int WSA_TIMEDOUT        = 10060;   // WSAETIMEDOUT
    private const int WSA_CONN_REFUSED    = 10061;   // WSAECONNREFUSED
    private const int WSA_HOST_UNREACH    = 10065;   // WSAEHOSTUNREACH

    /** @var resource Client socket resource */
    protected $socket;

    /** @var string Buffer for incoming data */
    protected string $readBuffer = '';

    /** @var string Buffer for outgoing data */
    protected string $writeBuffer = '';

    /** @var bool Flag indicating if client should be closed */
    protected bool $shouldClose = false;

    /**
     * AbstractClient constructor
     *
     * @param resource $socket Client socket
     */
    public function __construct($socket)
    {
        $this->socket = $socket;
        
        // Read buffer size from config with default fallback
        try {
            $this->readBufferSize = Env::getInt(EnvConstants::SOCKET_READ_BUFFER_SIZE, 8192);
        } catch (MissingEnvironmentVariableException) {
            // Default buffer size if not configured
            $this->readBufferSize = 8192;
        }
    }

    /**
     * Get client socket
     *
     * @return resource|object|null Socket resource (may be null after close)
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Read data from client socket
     * @throws SocketException
     */
    public function read(): void
    {
        // Skip if already marked for closing to prevent redundant processing
        if ($this->shouldClose) {
            return;
        }

        $data = socket_read($this->socket, $this->readBufferSize, PHP_BINARY_READ);
        
        // Empty string means connection closed gracefully
        if ($data === '') {
            $this->shouldClose = true;
            return;
        }

        // False means error occurred
        if ($data === false) {
            $this->handleSocketError(SocketOperation::READ);
            return;
        }

        $this->readBuffer .= $data;
        $this->processReadBuffer();
    }

    /**
     * Write buffered data to socket
     * @throws SocketException
     */
    public function write(): void
    {
        if ($this->writeBuffer === '') {
            return;
        }

        if ($this->shouldClose) {
            return;
        }

        $written = socket_write($this->socket, $this->writeBuffer);
        
        if ($written === false) {
            $this->handleSocketError(SocketOperation::WRITE);
            return;
        }

        $this->writeBuffer = substr($this->writeBuffer, $written);
    }

    /**
     * Handle socket error (unified method for read/write/close errors)
     *
     * Handles 23 most common socket error codes that can occur under high load.
     * All other errors are handled by generic SocketReadException/SocketWriteException/SocketCloseException.
     *
     * Note: Error codes differ between Linux/Unix and Windows (WSA codes).
     * This implementation uses Linux/Unix codes, but also handles common Windows codes.
     *
     * @param SocketOperation $operation Operation type
     * @throws SocketException Base exception class for all socket errors
     */
    private function handleSocketError(SocketOperation $operation): void
    {
        $errorCode = socket_last_error($this->socket);
        $errorMessage = socket_strerror($errorCode);

        // Reset error state
        socket_clear_error($this->socket);

        // Handle specific error codes
        switch ($errorCode) {
            case self::ERR_NO_ERROR:
                // No error, connection closed gracefully
                $this->shouldClose = true;
                break;

            case self::ERR_WOULDBLOCK:
            case self::WSA_WOULDBLOCK:
                // EAGAIN/EWOULDBLOCK (11) / WSAEWOULDBLOCK (10035) - operation would block
                // In non-blocking mode, this is normal - just return
                return;

            case self::ERR_CONN_RESET:
            case self::WSA_CONN_RESET:
                // ECONNRESET (104) / WSAECONNRESET (10054) - connection reset by peer
                $this->shouldClose = true;
                throw new ConnectionResetException();

            case self::ERR_BROKEN_PIPE:
            case self::WSA_SHUTDOWN:
                // EPIPE (32) / WSAESHUTDOWN (10058) - broken pipe
                $this->shouldClose = true;
                throw new BrokenPipeException();

            case self::ERR_NOT_CONN:
            case self::WSA_NOT_CONN:
                // ENOTCONN (107) / WSANOTCONN (10057) - not connected
                $this->shouldClose = true;
                throw new NotConnectedException();

            case self::ERR_BAD_FD:
            case self::WSA_NOT_SOCK:
                // EBADF (9) / WSAENOTSOCK (10038) - bad file descriptor
                $this->shouldClose = true;
                throw new InvalidSocketException();

            case self::ERR_INTERRUPTED:
                // EINTR (4) - interrupted
                $this->shouldClose = true;
                throw new InterruptedException();

            case self::ERR_ADDR_IN_USE:
            case self::WSA_ADDR_IN_USE:
                // EADDRINUSE (98) / WSAEADDRINUSE (10048) - address already in use
                $this->shouldClose = true;
                throw new AddressInUseException();

            case self::ERR_ADDR_NOT_AVAIL:
            case self::WSA_ADDR_NOT_AVAIL:
                // EADDRNOTAVAIL (99) / WSAEADDRNOTAVAIL (10049) - address not available
                $this->shouldClose = true;
                throw new AddressNotAvailableException();

            case self::ERR_ALREADY_CONN:
            case self::WSA_ALREADY_CONN:
                // EISCONN (106) / WSAEISCONN (10056) - socket is already connected
                $this->shouldClose = true;
                throw new AlreadyConnectedException();

            case self::ERR_TIMEDOUT:
            case self::WSA_TIMEDOUT:
                // ETIMEDOUT (110) / WSAETIMEDOUT (10060) - connection timed out
                $this->shouldClose = true;
                throw new ConnectionTimeoutException();

            case self::ERR_CONN_REFUSED:
            case self::WSA_CONN_REFUSED:
                // ECONNREFUSED (111) / WSAECONNREFUSED (10061) - connection refused
                $this->shouldClose = true;
                throw new ConnectionRefusedException();

            case self::ERR_HOST_UNREACH:
            case self::WSA_HOST_UNREACH:
                // EHOSTUNREACH (113) / WSAEHOSTUNREACH (10065) - host unreachable
                $this->shouldClose = true;
                throw new HostUnreachableException();

            case self::ERR_MSG_TOO_LONG:
            case self::WSA_MSG_TOO_LONG:
                // EMSGSIZE (90) / WSAEMSGSIZE (10040) - message too long
                $this->shouldClose = true;
                throw new MessageTooLongException();

            case self::ERR_NET_DOWN:
            case self::WSA_NET_DOWN:
                // ENETDOWN (100) / WSAENETDOWN (10050) - network is down
                $this->shouldClose = true;
                throw new NetworkDownException();

            case self::ERR_NET_UNREACH:
            case self::WSA_NET_UNREACH:
                // ENETUNREACH (101) / WSAENETUNREACH (10051) - network unreachable
                $this->shouldClose = true;
                throw new NetworkUnreachableException();

            case self::ERR_NO_BUFS:
            case self::WSA_NO_BUFS:
                // ENOBUFS (105) / WSAENOBUFS (10055) - no buffer space available
                $this->shouldClose = true;
                throw new NoBufferSpaceException();

            case self::ERR_AFNOSUPPORT:
            case self::WSA_AFNOSUPPORT:
                // EAFNOSUPPORT (97) / WSAEAFNOSUPPORT (10047) - address family not supported
                $this->shouldClose = true;
                throw new AddressFamilyNotSupportedException();

            case self::ERR_CONN_ABORTED:
            case self::WSA_CONN_ABORTED:
                // ECONNABORTED (103) / WSAECONNABORTED (10053) - connection aborted
                $this->shouldClose = true;
                throw new ConnectionAbortedException();

            case self::ERR_NET_RESET:
            case self::WSA_NET_RESET:
                // ENETRESET (102) / WSAENETRESET (10052) - network dropped connection on reset
                $this->shouldClose = true;
                throw new NetworkResetException();

            case self::ERR_ACCESS:
                // EACCES (13) - permission denied
                $this->shouldClose = true;
                throw new AccessDeniedException();

            case self::ERR_TOO_MANY_FILES:
            case self::ERR_TOO_MANY_FILES_SYS:
                // EMFILE (24) / ENFILE (23) - too many open files
                $this->shouldClose = true;
                throw new TooManyOpenFilesException();

            case self::ERR_PERM:
                // EPERM (1) - operation not permitted
                $this->shouldClose = true;
                throw new OperationNotPermittedException();

            case self::ERR_NO_MEM:
                // ENOMEM (12) - out of memory
                $this->shouldClose = true;
                throw new OutOfMemoryException();

            default:
                // Other errors (there are 100+ possible socket error codes)
                // Mark as should close and throw generic exception
                $this->shouldClose = true;
                match ($operation) {
                    SocketOperation::READ => throw new SocketReadException($errorCode, $errorMessage),
                    SocketOperation::WRITE => throw new SocketWriteException($errorCode, $errorMessage),
                    SocketOperation::CLOSE => throw new SocketCloseException($errorCode, $errorMessage),
                    SocketOperation::GETPEERNAME => throw new SocketGetPeerNameException($errorCode, $errorMessage),
                };
        }
    }

    /**
     * Check if client should be closed
     *
     * @return bool True if should close
     */
    public function shouldClose(): bool
    {
        return $this->shouldClose;
    }

    /**
     * Close client connection
     * 
     * NOTE: Socket is NOT set to null to allow getSocket() to work after close()
     * for cleanup purposes (e.g., unregistering from event loop before destruction)
     * 
     * @throws SocketException
     */
    public function close(): void
    {
        if (!is_resource($this->socket) && !is_object($this->socket)) {
            return; // Already closed or invalid
        }

        // socket_close returns void, check for errors via socket_last_error
        socket_close($this->socket);
        
        // Check if there was an error during close
        $this->handleSocketError(SocketOperation::CLOSE);

        // Call onClose callback if socket was closed successfully (no exception thrown)
        $this->onClose();

        // Keep $this->socket for event loop cleanup - garbage collector will handle it
    }

    /**
     * Get client IP address from socket
     *
     * Uses socket_getpeername to retrieve client IP address.
     * Returns empty string if unavailable (non-critical operation).
     *
     * @return string Client IP address (IPv4 or IPv6) or empty string if unavailable
     * @throws SocketException
     */
    protected function getClientIp(): string
    {
        $ip = '';

        // socket_getpeername doesn't require port parameter
        if (socket_getpeername($this->socket, $ip)) {
            return $ip;
        }

        // Check for errors (non-critical operation, just clear error state)
        $this->handleSocketError(SocketOperation::GETPEERNAME);

        return '';
    }

    /**
     * Parse cookies from Cookie header
     *
     * @param array $headers HTTP headers
     * @return array Cookies as key-value pairs
     */
    protected function parseCookies(array $headers): array
    {
        $cookies = [];

        // Cookie header format: "name1=value1; name2=value2; name3=value3"
        $cookieHeader = $headers[HttpConstants::HEADER_COOKIE] ?? '';
        if (empty($cookieHeader)) {
            return $cookies;
        }

        // Split by semicolon and parse each cookie
        $cookiePairs = explode(';', $cookieHeader);
        foreach ($cookiePairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }

            // Split name=value
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    /**
     * Process read buffer - must be implemented by child classes
     */
    abstract protected function processReadBuffer(): void;

    /**
     * Called when socket connection is successfully closed
     *
     * This method is called after socket_close() completes without errors.
     * Can be overridden in child classes to perform cleanup or logging.
     */
    abstract protected function onClose(): void;
}

