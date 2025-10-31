<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

/**
 * AbstractServer - Abstract base class for server implementations
 *
 * Provides common functionality for all server types.
 */
abstract class AbstractServer implements ServerInterface
{
    /** @var ?resource Server socket resource */
    protected $serverSocket = null;

    /** @var array Active client connections */
    protected array $clients = [];

    /** @var string Server host */
    protected string $host;

    /** @var int Server port */
    protected int $port;

    /** @var bool Server running state */
    protected bool $isRunning = false;

    /**
     * AbstractServer constructor
     *
     * @param string $host Host to bind
     * @param int $port Port to bind
     */
    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Start server - create and bind socket
     *
     * @return bool True on success
     */
    public function start(): bool
    {
        if ($this->isRunning) {
            return true;
        }

        $this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->serverSocket === false) {
            return false;
        }

        socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_nonblock($this->serverSocket);

        if (!socket_bind($this->serverSocket, $this->host, $this->port)) {
            return false;
        }

        if (!socket_listen($this->serverSocket, $this->getBacklogSize())) {
            return false;
        }

        $this->isRunning = true;
        return true;
    }

    /**
     * Stop server
     */
    public function stop(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];

        if ($this->serverSocket !== null) {
            socket_close($this->serverSocket);
            $this->serverSocket = null;
        }

        $this->isRunning = false;
    }

    /**
     * Check if server is running
     *
     * @return bool Running state
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * Get server socket for select
     *
     * @return ?resource Server socket
     */
    public function getSocket()
    {
        return $this->serverSocket;
    }

    /**
     * Remove client from server
     *
     * @param mixed $client Client to remove
     */
    public function removeClient($client): void
    {
        $key = array_search($client, $this->clients, true);
        if ($key !== false) {
            unset($this->clients[$key]);
        }
    }

    /**
     * Accept new connection - common implementation
     *
     * Handles socket_accept and socket_set_nonblock.
     * Child classes should implement createClient() to create specific client type.
     *
     * @return mixed New client or null
     */
    public function acceptConnection()
    {
        if (!$this->isRunning) {
            return null;
        }

        $clientSocket = @socket_accept($this->serverSocket);
        if ($clientSocket === false) {
            return null;
        }

        socket_set_nonblock($clientSocket);
        $client = $this->createClient($clientSocket);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * Create client instance from socket
     *
     * Must be implemented by child classes to create specific client type.
     *
     * @param resource $socket Client socket
     * @return mixed Client instance
     */
    abstract protected function createClient($socket);

    /**
     * Get backlog size for listen
     *
     * @return int Backlog size
     */
    abstract protected function getBacklogSize(): int;

    /**
     * Get server name for logging
     *
     * @return string Server name
     */
    abstract public function getServerName(): string;
}

