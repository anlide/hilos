<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Database;
use Hilos\HilosException;

/** Bounded SQL reads of loaded analytics facts for the admin section. */
final class AnalyticsSectionReader
{
    public const int MAX_PAGE_ROWS = 50;

    private const int BROWSER_DESCRIPTION_MAX_CHARS = 160;

    private const string IDENTITY_TYPE_USER_ID = 'user_id';

    /**
     * @param int $browserSessionId Numeric analytics row key, never a browser token
     * @return ?AnalyticsSectionBrowserSession Null when the row has vanished or never existed
     * @throws HilosException When an analytics or account lookup fails
     */
    public function browserSession(int $browserSessionId): ?AnalyticsSectionBrowserSession
    {
        Database::sql(
            'SELECT b.`id`, b.`first_seen_ts`, b.`last_seen_ts`, u.`value` AS `browser_description`,
                    CASE WHEN b.`user_identity_type` = ? THEN b.`user_identity_value` END AS `user_id_value`
             FROM `hilos_analytics_browser_session` b
             LEFT JOIN `hilos_analytics_user_agent` u ON u.`id` = b.`current_user_agent_id`
             WHERE b.`id` = ? LIMIT 1',
            [self::IDENTITY_TYPE_USER_ID, $browserSessionId],
        );
        $row = Database::row();
        if ($row === null) {
            return null;
        }

        $counts = $this->actionCounts([(int)$row['id']]);
        $userId = null;
        if ($row['user_id_value'] !== null && ctype_digit((string)$row['user_id_value'])) {
            $parsed = filter_var((string)$row['user_id_value'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $userId = $parsed === false ? null : $parsed;
        }

        return $this->sessionFromRow($row, $counts, $userId, $this->userLabel($userId));
    }

    /**
     * @param int $browserSessionId Numeric analytics row key
     * @param ?int $afterCreatedTs Source moment of the preceding window's last action
     * @param ?int $afterId Row number of the preceding window's last action
     * @param int $limit Requested number of rows, capped at MAX_PAGE_ROWS
     * @return list<AnalyticsSectionAction> Actions in source-time and row-number order
     * @throws InvalidArgumentException When only half of the cursor is supplied
     * @throws HilosException When the analytics query fails
     */
    public function actions(int $browserSessionId, ?int $afterCreatedTs, ?int $afterId, int $limit): array
    {
        $this->requireCursorPair($afterCreatedTs, $afterId);
        $params = [$browserSessionId];
        $cursor = '';
        if ($afterCreatedTs !== null) {
            $cursor = ' AND (a.`created_ts` > ? OR (a.`created_ts` = ? AND a.`id` > ?))';
            array_push($params, $afterCreatedTs, $afterCreatedTs, $afterId);
        }
        Database::sql(
            'SELECT a.`id`, a.`created_ts`, p.`page_name`, n.`name` AS `action_name`
             FROM `hilos_analytics_user_action` a
             JOIN `hilos_analytics_ws_connection` w ON w.`id` = a.`ws_connection_id`
             JOIN `hilos_analytics_action_name` n ON n.`id` = a.`action_name_id`
             LEFT JOIN `hilos_analytics_page_session` ps ON ps.`id` = a.`page_session_id`
             LEFT JOIN `hilos_analytics_page` p ON p.`id` = ps.`page_id`
             WHERE w.`browser_session_id` = ?' . $cursor . '
             ORDER BY a.`created_ts` ASC, a.`id` ASC LIMIT ' . $this->boundedLimit($limit),
            $params,
        );

        $actions = [];
        foreach (Database::rows() as $row) {
            $actions[] = new AnalyticsSectionAction(
                (int)$row['id'],
                (int)$row['created_ts'],
                $row['page_name'] === null ? null : (string)$row['page_name'],
                (string)$row['action_name'],
            );
        }

        return $actions;
    }

    /**
     * @param int $userId Last signed-in account number, not the actor of each action
     * @param ?int $beforeLastSeenTs Source moment of the preceding window's last session
     * @param ?int $beforeId Row number of the preceding window's last session
     * @param int $limit Requested number of rows, capped at MAX_PAGE_ROWS
     * @return list<AnalyticsSectionBrowserSession> Sessions in reverse last-seen and row-number order
     * @throws InvalidArgumentException When only half of the cursor is supplied
     * @throws HilosException When an analytics or account lookup fails
     */
    public function browserSessionsForUser(int $userId, ?int $beforeLastSeenTs, ?int $beforeId, int $limit): array
    {
        $this->requireCursorPair($beforeLastSeenTs, $beforeId);
        $params = [self::IDENTITY_TYPE_USER_ID, (string)$userId];
        $cursor = '';
        if ($beforeLastSeenTs !== null) {
            $cursor = ' AND (b.`last_seen_ts` < ? OR (b.`last_seen_ts` = ? AND b.`id` < ?))';
            array_push($params, $beforeLastSeenTs, $beforeLastSeenTs, $beforeId);
        }
        Database::sql(
            'SELECT b.`id`, b.`first_seen_ts`, b.`last_seen_ts`, u.`value` AS `browser_description`
             FROM `hilos_analytics_browser_session` b
             LEFT JOIN `hilos_analytics_user_agent` u ON u.`id` = b.`current_user_agent_id`
             WHERE b.`user_identity_type` = ? AND b.`user_identity_value` = ?' . $cursor . '
             ORDER BY b.`last_seen_ts` DESC, b.`id` DESC LIMIT ' . $this->boundedLimit($limit),
            $params,
        );
        $rows = Database::rows();
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $counts = $this->actionCounts($ids);
        $label = $this->userLabel($userId);
        $sessions = [];
        foreach ($rows as $row) {
            $sessions[] = $this->sessionFromRow($row, $counts, $userId, $label);
        }

        return $sessions;
    }

    /**
     * @param list<int> $browserSessionIds IDs of the selected window only
     * @return array<int, int> Number of attached actions by session ID
     * @throws HilosException When the count query fails
     */
    private function actionCounts(array $browserSessionIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($browserSessionIds), '?'));
        Database::sql(
            'SELECT w.`browser_session_id`, COUNT(a.`id`) AS `action_count`
             FROM `hilos_analytics_ws_connection` w
             JOIN `hilos_analytics_user_action` a ON a.`ws_connection_id` = w.`id`
             WHERE w.`browser_session_id` IN (' . $placeholders . ')
             GROUP BY w.`browser_session_id`',
            $browserSessionIds,
        );

        $counts = [];
        foreach (Database::rows() as $row) {
            $counts[(int)$row['browser_session_id']] = (int)$row['action_count'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row Raw session columns selected by this reader
     * @param array<int, int> $counts Action counts for the selected window
     * @param ?int $userId Last signed-in account number
     * @param ?string $label Current name or deleted-account label
     * @return AnalyticsSectionBrowserSession Typed session summary
     */
    private function sessionFromRow(array $row, array $counts, ?int $userId, ?string $label): AnalyticsSectionBrowserSession
    {
        $id = (int)$row['id'];

        return new AnalyticsSectionBrowserSession(
            $id,
            (int)$row['first_seen_ts'],
            (int)$row['last_seen_ts'],
            $row['browser_description'] === null
                ? null : mb_substr((string)$row['browser_description'], 0, self::BROWSER_DESCRIPTION_MAX_CHARS),
            $userId,
            $label,
            $counts[$id] ?? 0,
        );
    }

    /**
     * @param ?int $userId Last signed-in account number
     * @return ?string Current name, deleted-account label, or null for a guest
     * @throws HilosException When the fresh account lookup fails
     */
    private function userLabel(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        Database::sql('SELECT `name` FROM `hilos_user` WHERE `id` = ? LIMIT 1', [$userId]);
        $row = Database::row();

        return $row === null ? 'Deleted user #' . $userId : (string)$row['name'];
    }

    /**
     * @param ?int $moment Cursor source moment
     * @param ?int $id Cursor row number
     * @throws InvalidArgumentException When only half of the cursor is supplied
     */
    private function requireCursorPair(?int $moment, ?int $id): void
    {
        if (($moment === null) !== ($id === null)) {
            throw new InvalidArgumentException('Analytics cursor requires both source moment and row number');
        }
    }

    /**
     * @param int $requestedRows Requested number of rows
     * @return int Non-negative server-capped number of rows
     */
    private function boundedLimit(int $requestedRows): int
    {
        return max(0, min($requestedRows, self::MAX_PAGE_ROWS));
    }
}
