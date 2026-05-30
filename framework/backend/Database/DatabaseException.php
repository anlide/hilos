<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\HilosException;
use Throwable;

/**
 * Base SQL exception carrying MySQL error details and the failing query.
 */
class DatabaseException extends HilosException
{
    /** @var int Maximum SQL query preview length in error messages */
    public const int QUERY_PREVIEW_MAX_LENGTH = 200;

    /** @var int MySQL error code (0 when unset) */
    protected int $mysqlErrorCode = 0;

    /** @var string MySQL error message (empty when unset) */
    protected string $mysqlErrorMessage = '';

    /** @var string SQL query that caused the error (empty when unset) */
    protected string $query = '';

    /**
     * @param string $message Exception message
     * @param int $code Exception code
     * @param ?Throwable $previous Chained exception
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param int $errorCode MySQL error code
     * @param string $errorMessage MySQL error message
     * @return self Fluent setter
     */
    public function setMysqlError(int $errorCode, string $errorMessage): self
    {
        $this->mysqlErrorCode = $errorCode;
        $this->mysqlErrorMessage = $errorMessage;
        return $this;
    }

    /**
     * @param string $query SQL query string
     * @return self Fluent setter
     */
    public function setQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    /**
     * @return int MySQL error code (0 when unset)
     */
    public function getMysqlErrorCode(): int
    {
        return $this->mysqlErrorCode;
    }

    /**
     * @return string MySQL error message (empty when unset)
     */
    public function getMysqlErrorMessage(): string
    {
        return $this->mysqlErrorMessage;
    }

    /**
     * @return string SQL query string (empty when unset)
     */
    public function getQuery(): string
    {
        return $this->query;
    }
}
