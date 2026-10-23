<?php

declare(strict_types=1);

namespace Hilos\Status;

use DateTimeImmutable;
use Hilos\Constants\TimeConstants;
use Hilos\HilosException;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Private Status owner work, photographed at candidate creation without retaining a DB View.
 * This is no shared source or durable outbox (framework-first.md -> runtime/rt-context.md).
 */
final class StatusCandidateNotice
{
    private const string TYPE = 'status.possible_outage';
    private const string TITLE = 'Possible outage: %s';
    private const string BODY = 'Detected at %s. Publication scheduled for %s. See %s.';
    private const string URL = '/hilos/status';
    private const string DATE_FORMAT = 'Y-m-d H:i:s P (e)';

    /**
     * @param int $incidentId Newly committed candidate's primary id
     * @param string $componentKey Permanent public component key
     * @param string $componentName Public name at detection
     * @param int $detectedAt Detection moment in epoch milliseconds UTC
     * @param int $publishAt Assigned deadline in epoch milliseconds UTC
     */
    public function __construct(
        private readonly int $incidentId,
        private readonly string $componentKey,
        private readonly string $componentName,
        private readonly int $detectedAt,
        private readonly int $publishAt,
    ) {
    }

    /**
     * @param int $userId Current administrator receiving this ordinary notification
     * @return NotificationDraft Bell plus email narrowed through the normal delivery policy
     * @throws HilosException When recipient storage or server-text time-zone configuration cannot be read
     */
    public function draftFor(int $userId): NotificationDraft
    {
        $zone = TimeHelper::serverTextTimeZone($userId);
        return new NotificationDraft(
            userId: $userId,
            type: self::TYPE,
            title: sprintf(self::TITLE, $this->componentName),
            severity: NotificationSeverity::WARNING,
            body: sprintf(
                self::BODY,
                new DateTimeImmutable('@' . intdiv($this->detectedAt, TimeConstants::MS_PER_SECOND))
                    ->setTimezone($zone)->format(self::DATE_FORMAT),
                new DateTimeImmutable('@' . intdiv($this->publishAt, TimeConstants::MS_PER_SECOND))
                    ->setTimezone($zone)->format(self::DATE_FORMAT),
                self::URL,
            ),
            data: [
                'url' => self::URL, 'incidentId' => $this->incidentId, 'componentKey' => $this->componentKey,
                'detectedAt' => $this->detectedAt, 'publishAt' => $this->publishAt,
            ],
            channels: [MailDeliveryChannel::NAME],
        );
    }
}
