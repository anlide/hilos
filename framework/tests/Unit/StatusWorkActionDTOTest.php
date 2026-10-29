<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Pages\Status\AbstractHilosStatusWorksPage;
use Hilos\Pages\Status\DTO\HilosStatusScheduleWorkActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusChangeWorkActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusCancelWorkActionDTO;
use Hilos\Status\DTO\StatusScheduleWorkSignalData;
use Hilos\Status\DTO\StatusChangeWorkSignalData;
use Hilos\Status\DTO\StatusCancelWorkSignalData;
use Hilos\Status\StatusAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusWorkActionDTOTest extends TestCase
{
    /** @return iterable<string, array{class-string, array<string, mixed>}> Exact approved business and handover shapes */
    public static function payloads(): iterable
    {
        $content = ['title' => '  Upgrade  ', 'description' => null, 'componentKeys' => ['service'],
            'startsAt' => 1900000000000, 'endsAt' => 1900003600000];
        $target = ['workId' => 7, 'publicId' => str_repeat('a', 32), 'expectedVersion' => 3];
        $metadata = ['replySignal' => HilosSignalConstants::HILOS_STATUS_WORK_WRITE_DONE, 'acceptKey' => 'ak-status',
            'requestId' => 'req-7'];
        foreach ([['Schedule', $content], ['Change', $target + $content], ['Cancel', $target]] as [$operation, $payload]) {
            $actionClass = match ($operation) {
                'Schedule' => HilosStatusScheduleWorkActionDTO::class,
                'Change' => HilosStatusChangeWorkActionDTO::class,
                'Cancel' => HilosStatusCancelWorkActionDTO::class,
            };
            $signalClass = match ($operation) {
                'Schedule' => StatusScheduleWorkSignalData::class,
                'Change' => StatusChangeWorkSignalData::class,
                'Cancel' => StatusCancelWorkSignalData::class,
            };
            yield $operation . ' page' => [$actionClass, $payload];
            yield $operation . ' owner' => [$signalClass, $payload + $metadata + [
                'action' => $actionClass::fromArray($payload)->getAction(), 'successMessage' => null,
            ]];
        }
    }

    /**
     * @param class-string $class Exact approved DTO
     * @param array<string, mixed> $payload Complete shape
     */
    #[DataProvider('payloads')]
    public function testRoundtripAndStrictFieldTypes(string $class, array $payload): void
    {
        self::assertSame($payload, $class::fromJson($class::fromArray($payload)->toJson())->toArray());
        foreach ($payload as $key => $value) {
            $missing = $payload;
            unset($missing[$key]);
            if ($key !== 'description' || is_a($class, HandoverAskInterface::class, true)) {
                $this->refuses($class, $missing);
            }
            $this->refuses($class, array_replace($payload, [$key => is_string($value) || $value === null ? 1 : 'wrong type']));
        }
        if (isset($payload['componentKeys'])) {
            $this->refuses($class, array_replace($payload, ['componentKeys' => ['key' => 'service']]));
            $this->refuses($class, array_replace($payload, ['componentKeys' => [1]]));
        }
        if (isset($payload['workId'])) {
            foreach (['workId' => 0, 'publicId' => 'stale', 'expectedVersion' => 0] as $key => $invalid) {
                $this->refuses($class, array_replace($payload, [$key => $invalid]));
            }
        }
    }

    public function testBusinessPayloadDoesNotCaptureTheTrackedEnvelopeAndAdminActionsStayOnPage(): void
    {
        $payload = iterator_to_array(self::payloads())['Schedule page'][1];
        $dto = HilosStatusScheduleWorkActionDTO::fromArray(['data' => $payload, 'requestId' => 'outer']);
        self::assertSame($payload, $dto->toArray());
        unset($payload['description']);
        self::assertNull(HilosStatusScheduleWorkActionDTO::fromArray($payload)->description);
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosStatusWorksPage::ACCESS_LEVEL);
        foreach (AbstractHilosStatusWorksPage::ACTIONS as $action => $class) {
            self::assertSame([], $class::SECRET_FIELDS);
            self::assertArrayNotHasKey($action, StatusAgent::AGENT_ACTIONS);
            self::assertNotContains($action, AbstractHilosStatusWorksPage::READING_ACTIONS);
        }
    }

    /**
     * @param class-string $class Parsed DTO class
     * @param array<string, mixed> $payload Deliberately malformed request
     */
    private function refuses(string $class, array $payload): void
    {
        try {
            $class::fromArray($payload);
            self::fail('Malformed work payload must refuse');
        } catch (InvalidFormatException) {
            self::assertTrue(true);
        }
    }
}
