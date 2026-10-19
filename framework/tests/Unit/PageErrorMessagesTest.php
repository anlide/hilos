<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\PageErrorMessages;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageErrorMessagesTest extends TestCase
{
    #[DataProvider('statusTexts')]
    public function testExactPublicWording(int $status, string $title, string $message): void
    {
        $text = PageErrorMessages::forStatus($status);
        $this->assertSame($title, $text->title);
        $this->assertSame($message, $text->message);
        $this->assertSame([
            'page' => 'test_page',
            'httpCode' => $status,
            'errorCode' => 'test_refusal',
            'title' => $title,
            'message' => $message,
        ], PageSubscriptionErrorSignalData::forStatus('test_page', $status, 'test_refusal')->toArray());
    }

    /** @return iterable<string, array{int, string, string}> Literal status wording */
    public static function statusTexts(): iterable
    {
        yield '400' => [400, 'Bad Request', 'The request could not be understood.'];
        yield '401' => [401, 'Unauthorized', 'Authentication is required.'];
        yield '403' => [403, 'Forbidden', 'You do not have access to this page.'];
        yield '404' => [404, 'Page Not Found', 'The page you are looking for does not exist.'];
        yield '500' => [500, 'Internal Server Error', 'An unexpected error occurred.'];
        yield '502' => [502, 'Bad Gateway', 'The server received an invalid response.'];
        yield '503' => [503, 'Service Unavailable', 'The service is temporarily unavailable.'];
        yield '504' => [504, 'Gateway Timeout', 'The gateway timed out.'];
        yield '409' => [409, 'Error', 'An error occurred.'];
        yield '410' => [410, 'Error', 'An error occurred.'];
        yield '418' => [418, 'Error', 'An error occurred.'];
    }
}
