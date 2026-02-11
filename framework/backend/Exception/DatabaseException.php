<?php

namespace Hilos\Exception;

/**
 * Base SQL exception class
 */
class DatabaseException extends HilosException
{
    protected int $mysqlErrorCode = 0;
    protected string $mysqlErrorMessage = '';
    protected string $query = '';

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function setMysqlError(int $errorCode, string $errorMessage): self
    {
        $this->mysqlErrorCode = $errorCode;
        $this->mysqlErrorMessage = $errorMessage;
        return $this;
    }

    public function setQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function getMysqlErrorCode(): int
    {
        return $this->mysqlErrorCode;
    }

    public function getMysqlErrorMessage(): string
    {
        return $this->mysqlErrorMessage;
    }

    public function getQuery(): string
    {
        return $this->query;
    }
}

