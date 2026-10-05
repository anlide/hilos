<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;

/** Bounded, actor-keyed SQL reads of authenticated analytics for a personal archive. */
final class AnalyticsPersonExportReader
{
    public const int PART_ROWS = 500;

    /** @var list<int> The person and every account folded into them, with no repeated id */
    private array $accountIds;

    /**
     * @param int $userId Person ordering the archive
     * @throws HilosException When the merge tree cannot be read
     */
    public function __construct(int $userId)
    {
        $walked = [$userId];
        $seen = [$userId => true];
        for ($index = 0; $index < count($walked); $index++) {
            foreach (Hilos::$db->userMerges->foldedInto($walked[$index]) as $merge) {
                if (isset($seen[$merge->userId])) {
                    continue;
                }
                $seen[$merge->userId] = true;
                $walked[] = $merge->userId;
            }
        }
        $this->accountIds = $walked;
    }

    /**
     * @param ?AnalyticsPersonExportEvent $after Last row of the preceding part, or null for the first
     * @return list<AnalyticsPersonExportEvent> Up to PART_ROWS events in source-time/id order
     * @throws DatabaseException When the analytics query fails or saved page params are malformed
     */
    public function next(?AnalyticsPersonExportEvent $after = null): array
    {
        $placeholders = implode(', ', array_fill(0, count($this->accountIds), '?'));
        $params = $this->accountIds;
        $cursor = '';
        if ($after !== null) {
            $cursor = ' AND (e.`created_ts` > ? OR (e.`created_ts` = ? AND e.`id` > ?))';
            array_push($params, $after->createdTs, $after->createdTs, $after->id);
        }
        Database::sql(
            'SELECT e.`id`, e.`created_ts`, e.`event_kind`, a.`name` AS `action_name`,
                    p.`page_name`, pp.`params_json`,
                    COALESCE(INET_NTOA(e.`ipv4`), INET6_NTOA(e.`ipv6`)) AS `address`,
                    e.`session_id`, e.`browser_session_id`, e.`subject_user_id`
             FROM `hilos_analytics_person_event` e
             LEFT JOIN `hilos_analytics_action_name` a ON a.`id` = e.`action_name_id`
             LEFT JOIN `hilos_analytics_page` p ON p.`id` = e.`page_id`
             LEFT JOIN `hilos_analytics_page_params` pp ON pp.`id` = e.`page_params_id`
             WHERE e.`user_id` IN (' . $placeholders . ')' . $cursor . '
             ORDER BY e.`created_ts`, e.`id` LIMIT ' . self::PART_ROWS,
            $params,
        );

        $events = [];
        foreach (Database::rows() as $row) {
            $pageParams = null;
            if ($row['params_json'] !== null) {
                try {
                    $pageParams = json_decode((string)$row['params_json'], true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new DatabaseException('Invalid saved analytics page parameters', previous: $e);
                }
                if (!is_array($pageParams)) {
                    throw new DatabaseException('Saved analytics page parameters are not an object');
                }
            }
            $events[] = new AnalyticsPersonExportEvent(
                (int)$row['id'],
                (int)$row['created_ts'],
                (string)$row['event_kind'],
                $row['action_name'] === null ? null : (string)$row['action_name'],
                $row['page_name'] === null ? null : (string)$row['page_name'],
                $pageParams,
                $row['address'] === null ? null : (string)$row['address'],
                $row['session_id'] === null ? null : (int)$row['session_id'],
                $row['browser_session_id'] === null ? null : (int)$row['browser_session_id'],
                $row['subject_user_id'] === null ? null : (int)$row['subject_user_id'],
            );
        }

        return $events;
    }
}
