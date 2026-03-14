<?php

namespace Hilos\Database;

use Hilos\HilosException;

/**
 * Base SQL exception class.
 *
 * Carries MySQL error details and query for debugging.
 */
class DatabaseException extends HilosException
{
    protected int $mysqlErrorCode = 0;
    protected string $mysqlErrorMessage = '';
    protected string $query = '';

    /**
     * Creates database exception.
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param ?\Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Sets MySQL error code and message from failed query.
     *
     * @param int $errorCode MySQL error code
     * @param string $errorMessage MySQL error message
     * @return self For chaining
     */
    public function setMysqlError(int $errorCode, string $errorMessage): self
    {
        $this->mysqlErrorCode = $errorCode;
        $this->mysqlErrorMessage = $errorMessage;
        return $this;
    }

    /**
     * Sets SQL query that caused the error.
     *
     * @param string $query SQL query string
     * @return self For chaining
     */
    public function setQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    /**
     * Gets MySQL error code.
     *
     * @return int MySQL error code (0 if not set)
     */
    public function getMysqlErrorCode(): int
    {
        return $this->mysqlErrorCode;
    }

    /**
     * Gets MySQL error message.
     *
     * @return string MySQL error message (empty if not set)
     */
    public function getMysqlErrorMessage(): string
    {
        return $this->mysqlErrorMessage;
    }

    /**
     * Gets SQL query that caused the error.
     *
     * @return string SQL query string (empty if not set)
     */
    public function getQuery(): string
    {
        return $this->query;
    }
}

