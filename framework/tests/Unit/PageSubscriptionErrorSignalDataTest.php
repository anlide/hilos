<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageBadRequestException;
use Hilos\Core\Page\Exception\PageConflictException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageGoneException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageSubscriptionErrorSignalData and PageSubscriptionException hierarchy.
 */
final class PageSubscriptionErrorSignalDataTest extends TestCase
{
    /**
     * PageSubscriptionErrorSignalData stores all properties correctly.
     */
    public function testPageSubscriptionErrorSignalDataStoresProperties(): void
    {
        $data = new PageSubscriptionErrorSignalData(
            page: 'test_page',
            httpCode: 404,
            errorCode: 'not_found',
            message: 'Resource not found',
        );

        $this->assertSame('test_page', $data->page);
        $this->assertSame(404, $data->httpCode);
        $this->assertSame('not_found', $data->errorCode);
        $this->assertSame('Resource not found', $data->message);
    }

    /**
     * PageResourceNotFoundException has correct defaults.
     */
    public function testPageResourceNotFoundExceptionHasCorrectDefaults(): void
    {
        $exception = new PageResourceNotFoundException();

        $this->assertInstanceOf(PageSubscriptionException::class, $exception);
        $this->assertSame(404, $exception->httpCode);
        $this->assertSame('not_found', $exception->errorCode);
        $this->assertSame('Resource not found', $exception->getMessage());
    }

    /**
     * PageResourceNotFoundException accepts custom message.
     */
    public function testPageResourceNotFoundExceptionAcceptsCustomMessage(): void
    {
        $exception = new PageResourceNotFoundException('User #123 not found');

        $this->assertSame(404, $exception->httpCode);
        $this->assertSame('not_found', $exception->errorCode);
        $this->assertSame('User #123 not found', $exception->getMessage());
    }

    /**
     * PageForbiddenException has correct defaults.
     */
    public function testPageForbiddenExceptionHasCorrectDefaults(): void
    {
        $exception = new PageForbiddenException();

        $this->assertSame(403, $exception->httpCode);
        $this->assertSame('forbidden', $exception->errorCode);
        $this->assertSame('Access forbidden', $exception->getMessage());
    }

    /**
     * PageUnauthorizedException has correct defaults.
     */
    public function testPageUnauthorizedExceptionHasCorrectDefaults(): void
    {
        $exception = new PageUnauthorizedException();

        $this->assertSame(401, $exception->httpCode);
        $this->assertSame('unauthorized', $exception->errorCode);
        $this->assertSame('Unauthorized', $exception->getMessage());
    }

    /**
     * PageGoneException has correct defaults.
     */
    public function testPageGoneExceptionHasCorrectDefaults(): void
    {
        $exception = new PageGoneException();

        $this->assertSame(410, $exception->httpCode);
        $this->assertSame('gone', $exception->errorCode);
        $this->assertSame('Resource gone', $exception->getMessage());
    }

    /**
     * PageInternalErrorException has correct defaults.
     */
    public function testPageInternalErrorExceptionHasCorrectDefaults(): void
    {
        $exception = new PageInternalErrorException();

        $this->assertSame(500, $exception->httpCode);
        $this->assertSame('internal_error', $exception->errorCode);
        $this->assertSame('Internal error', $exception->getMessage());
    }

    /**
     * PageBadRequestException has correct defaults.
     */
    public function testPageBadRequestExceptionHasCorrectDefaults(): void
    {
        $exception = new PageBadRequestException();

        $this->assertSame(400, $exception->httpCode);
        $this->assertSame('bad_request', $exception->errorCode);
        $this->assertSame('Bad request', $exception->getMessage());
    }

    /**
     * PageConflictException has correct defaults.
     */
    public function testPageConflictExceptionHasCorrectDefaults(): void
    {
        $exception = new PageConflictException();

        $this->assertSame(409, $exception->httpCode);
        $this->assertSame('conflict', $exception->errorCode);
        $this->assertSame('Conflict', $exception->getMessage());
    }

    /**
     * All exceptions can be caught as PageSubscriptionException.
     */
    public function testAllExceptionsCanBeCaughtAsPageSubscriptionException(): void
    {
        $exceptions = [
            new PageResourceNotFoundException(),
            new PageForbiddenException(),
            new PageUnauthorizedException(),
            new PageGoneException(),
            new PageInternalErrorException(),
            new PageBadRequestException(),
            new PageConflictException(),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(PageSubscriptionException::class, $exception);
        }
    }
}
