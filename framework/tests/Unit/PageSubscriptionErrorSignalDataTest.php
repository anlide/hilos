<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageBadRequestException;
use Hilos\Core\Page\Exception\PageConflictException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageGoneException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\PageErrorMessages;
use PHPUnit\Framework\Attributes\DataProvider;
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
            title: 'A server title',
            message: 'A server description',
        );

        $this->assertSame('test_page', $data->page);
        $this->assertSame(404, $data->httpCode);
        $this->assertSame('not_found', $data->errorCode);
        $this->assertSame('A server title', $data->title);
        $this->assertSame('A server description', $data->message);
        $this->assertNull($data->transitionMessage);
        $this->assertArrayNotHasKey(
            PageSubscriptionErrorSignalData::transitionMessage,
            $data->toArray(),
        );
        $this->assertSame($data->toArray(), PageSubscriptionErrorSignalData::fromArray($data->toArray())->toArray());
        $this->assertSame($data->toArray(), PageSubscriptionErrorSignalData::fromJson($data->toJson())->toArray());
    }

    #[DataProvider('malformedText')]
    public function testRejectsMalformedPublicText(string $field, bool $present, mixed $value): void
    {
        $payload = [
            'page' => 'test_page',
            'httpCode' => 404,
            'errorCode' => 'not_found',
            'title' => 'Server title',
            'message' => 'Server description',
        ];
        if ($present) {
            $payload[$field] = $value;
        } else {
            unset($payload[$field]);
        }

        $this->expectException(InvalidFormatException::class);
        PageSubscriptionErrorSignalData::fromArray($payload);
    }

    /**
     * An arbitrary deletion line survives the wire, and a 404 without one does not grow the key.
     */
    public function testTransitionMessageRoundTripsOnlyWhenTheCallerPassesIt(): void
    {
        $this->assertSame('It was deleted just now.', PageErrorMessages::RESOURCE_DELETED_MESSAGE);

        $ordinary = PageSubscriptionErrorSignalData::forStatus('hilos_i18n_country', 404, 'not_found');
        $this->assertNull($ordinary->transitionMessage);
        $this->assertArrayNotHasKey(PageSubscriptionErrorSignalData::transitionMessage, $ordinary->toArray());

        $phrase = 'Moved on purpose.';
        $data = new PageSubscriptionErrorSignalData(
            page: 'test_page',
            httpCode: 404,
            errorCode: 'not_found',
            title: 'A server title',
            message: 'A server description',
            transitionMessage: $phrase,
        );
        $this->assertSame($phrase, $data->transitionMessage);
        $this->assertSame($data->toArray(), PageSubscriptionErrorSignalData::fromArray($data->toArray())->toArray());
        $this->assertSame($data->toArray(), PageSubscriptionErrorSignalData::fromJson($data->toJson())->toArray());

        $live = PageSubscriptionErrorSignalData::forStatus(
            'hilos_i18n_country',
            404,
            'not_found',
            PageErrorMessages::RESOURCE_DELETED_MESSAGE,
        );
        $this->assertSame([
            'page' => 'hilos_i18n_country',
            'httpCode' => 404,
            'errorCode' => 'not_found',
            'title' => 'Page Not Found',
            'message' => 'The page you are looking for does not exist.',
            'transitionMessage' => 'It was deleted just now.',
        ], $live->toArray());
        $names = PageSubscriptionErrorSignalData::forStatus(
            'hilos_i18n_country_names',
            404,
            'not_found',
            PageErrorMessages::RESOURCE_DELETED_MESSAGE,
        );
        $this->assertSame('It was deleted just now.', $names->toArray()['transitionMessage']);
    }

    /**
     * A missing deletion line is a normal refusal, not a broken payload.
     */
    public function testTransitionMessageMayBeAbsent(): void
    {
        $data = PageSubscriptionErrorSignalData::fromArray([
            'page' => 'test_page',
            'httpCode' => 404,
            'errorCode' => 'not_found',
            'title' => 'Server title',
            'message' => 'Server description',
        ]);

        $this->assertNull($data->transitionMessage);
        $this->assertArrayNotHasKey(PageSubscriptionErrorSignalData::transitionMessage, $data->toArray());
    }

    #[DataProvider('malformedTransitionMessage')]
    public function testRejectsMalformedTransitionMessage(mixed $value): void
    {
        $this->expectException(InvalidFormatException::class);
        PageSubscriptionErrorSignalData::fromArray([
            'page' => 'test_page',
            'httpCode' => 404,
            'errorCode' => 'not_found',
            'title' => 'Server title',
            'message' => 'Server description',
            PageSubscriptionErrorSignalData::transitionMessage => $value,
        ]);
    }

    /** @return iterable<string, array{mixed}> Present null, mistyped and empty deletion lines */
    public static function malformedTransitionMessage(): iterable
    {
        yield 'null' => [null];
        yield 'integer' => [404];
        yield 'boolean' => [false];
        yield 'array' => [[]];
        yield 'empty' => [''];
    }

    /** @return iterable<string, array{string, bool, mixed}> Missing, mistyped and empty public text */
    public static function malformedText(): iterable
    {
        foreach (['title', 'message'] as $field) {
            yield $field . ' missing' => [$field, false, null];
            yield $field . ' null' => [$field, true, null];
            yield $field . ' integer' => [$field, true, 404];
            yield $field . ' boolean' => [$field, true, false];
            yield $field . ' array' => [$field, true, []];
            yield $field . ' empty' => [$field, true, ''];
        }
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
