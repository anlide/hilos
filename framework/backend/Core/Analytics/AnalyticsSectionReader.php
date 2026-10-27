<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\SqlSortDirection;
use Hilos\Hilos;
use Hilos\HilosException;

/** Exact overview aggregates and bounded detail windows over loaded analytics facts. */
final class AnalyticsSectionReader
{
    public const int MAX_PAGE_ROWS = 50;

    private const string IDENTITY_TYPE_USER_ID = 'user_id';

    private const string ACTION_MOMENT_SQL = 'a.`created_ts`';

    private const string ACTION_ID_SQL = 'a.`id`';

    private const string SESSION_MOMENT_SQL = 'b.`last_seen_ts`';

    private const string SESSION_ID_SQL = 'b.`id`';

    private const string PERSON_MOMENT_SQL = 'p.`last_seen_ts`';

    private const string PERSON_ID_SQL = 'p.`user_id`';

    private const int MAX_SAFE_USER_ID = 9007199254740991;

    private const int MAX_SAFE_SESSION_ID = 9007199254740991;

    private const int MAX_SAFE_COUNT = 9007199254740991;

    /**
     * @param int $browserSessionId Numeric analytics row key, never a browser token
     * @return ?AnalyticsSectionBrowserSession Null when the row has vanished or never existed
     * @throws HilosException When an analytics or account lookup fails
     */
    public function browserSession(int $browserSessionId): ?AnalyticsSectionBrowserSession
    {
        Database::sql(
            'SELECT b.`id`, b.`first_seen_ts`, b.`last_seen_ts`, u.`value` AS `user_agent`,
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
     * @param AnalyticsWindowOrder $order Canonical source-time or row-number order
     * @param ?AnalyticsWindowAnchor $anchor Complete row key, or an edge of the set
     * @param TableAnchorDirection $direction Side of the anchor in the chosen order
     * @param int $limit Requested number of rows, capped at MAX_PAGE_ROWS
     * @param int $skip Rows to skip from the chosen edge when there is no anchor
     * @return list<AnalyticsSectionAction> Actions in the chosen canonical order
     * @throws InvalidArgumentException When a skip accompanies an anchor or its required moment is missing
     * @throws HilosException When the analytics query fails
     */
    public function actionWindow(
        int $browserSessionId,
        AnalyticsWindowOrder $order,
        ?AnalyticsWindowAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
        int $skip = 0,
    ): array {
        if ($anchor !== null && $skip !== 0) {
            throw new InvalidArgumentException('Analytics skip requires an edge');
        }
        $params = [$browserSessionId];
        $cursor = $this->anchorClause(self::ACTION_MOMENT_SQL, self::ACTION_ID_SQL, $order, $anchor, $direction, $params);
        $limit = $this->boundedLimit($limit);
        $skip = max(0, $skip);
        if ($limit === 0 || $skip > TableConstants::COUNT_CEILING) {
            return [];
        }
        $sqlOrder = $this->windowOrder(self::ACTION_MOMENT_SQL, self::ACTION_ID_SQL, $order, $direction);
        Database::sql(
            'SELECT a.`id`, a.`created_ts`, p.`page_name`, n.`name` AS `action_name`
             FROM `hilos_analytics_user_action` a
             JOIN `hilos_analytics_ws_connection` w ON w.`id` = a.`ws_connection_id`
             JOIN `hilos_analytics_action_name` n ON n.`id` = a.`action_name_id`
             LEFT JOIN `hilos_analytics_page_session` ps ON ps.`id` = a.`page_session_id`
             LEFT JOIN `hilos_analytics_page` p ON p.`id` = ps.`page_id`
             WHERE w.`browser_session_id` = ?' . $cursor . '
             ORDER BY ' . $sqlOrder . ' LIMIT ' . $limit . ' OFFSET ' . $skip,
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

        return $direction === TableAnchorDirection::Before ? array_reverse($actions) : $actions;
    }

    /**
     * @param int $browserSessionId Numeric analytics row key
     * @param int $ceiling Exact-count ceiling; one extra row signals overflow
     * @return int Matching actions, at most ceiling plus one
     * @throws HilosException When the analytics count query fails
     */
    public function actionCount(int $browserSessionId, int $ceiling = TableConstants::COUNT_CEILING): int
    {
        return $this->boundedActionCount($browserSessionId, AnalyticsWindowOrder::NUMBER, null, $ceiling);
    }

    /**
     * @param int $browserSessionId Numeric analytics row key
     * @param AnalyticsWindowOrder $order Canonical source-time or row-number order
     * @param AnalyticsWindowAnchor $anchor Complete row key
     * @param int $ceiling Exact-count ceiling; one extra row signals overflow
     * @return int Actions preceding the anchor, at most ceiling plus one
     * @throws InvalidArgumentException When the anchor lacks its required source moment
     * @throws HilosException When the analytics count query fails
     */
    public function actionCountBefore(
        int $browserSessionId,
        AnalyticsWindowOrder $order,
        AnalyticsWindowAnchor $anchor,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->boundedActionCount($browserSessionId, $order, $anchor, $ceiling);
    }

    /**
     * @param int $userId Last signed-in account number, not the actor of each action
     * @param AnalyticsWindowOrder $order Canonical last-seen or row-number order
     * @param ?AnalyticsWindowAnchor $anchor Complete row key, or an edge of the set
     * @param TableAnchorDirection $direction Side of the anchor in the chosen order
     * @param int $limit Requested number of rows, capped at MAX_PAGE_ROWS
     * @param int $skip Rows to skip from the chosen edge when there is no anchor
     * @return list<AnalyticsSectionBrowserSession> Sessions in the chosen canonical order
     * @throws InvalidArgumentException When a skip accompanies an anchor or its required moment is missing
     * @throws HilosException When an analytics or account lookup fails
     */
    public function browserSessionWindow(
        int $userId,
        AnalyticsWindowOrder $order,
        ?AnalyticsWindowAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
        int $skip = 0,
    ): array {
        if ($anchor !== null && $skip !== 0) {
            throw new InvalidArgumentException('Analytics skip requires an edge');
        }
        $params = [self::IDENTITY_TYPE_USER_ID, (string)$userId];
        $cursor = $this->anchorClause(self::SESSION_MOMENT_SQL, self::SESSION_ID_SQL, $order, $anchor, $direction, $params);
        $limit = $this->boundedLimit($limit);
        $skip = max(0, $skip);
        if ($limit === 0 || $skip > TableConstants::COUNT_CEILING) {
            return [];
        }
        $sqlOrder = $this->windowOrder(self::SESSION_MOMENT_SQL, self::SESSION_ID_SQL, $order, $direction);
        Database::sql(
            'SELECT b.`id`, b.`first_seen_ts`, b.`last_seen_ts`, u.`value` AS `user_agent`
             FROM `hilos_analytics_browser_session` b
             LEFT JOIN `hilos_analytics_user_agent` u ON u.`id` = b.`current_user_agent_id`
             WHERE b.`user_identity_type` = ? AND b.`user_identity_value` = ?' . $cursor . '
             ORDER BY ' . $sqlOrder . ' LIMIT ' . $limit . ' OFFSET ' . $skip,
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

        return $direction === TableAnchorDirection::Before ? array_reverse($sessions) : $sessions;
    }

    /**
     * @param int $userId Last signed-in account number
     * @param int $ceiling Exact-count ceiling; one extra row signals overflow
     * @return int Matching sessions, at most ceiling plus one
     * @throws HilosException When the analytics count query fails
     */
    public function browserSessionCount(int $userId, int $ceiling = TableConstants::COUNT_CEILING): int
    {
        return $this->boundedBrowserSessionCount($userId, AnalyticsWindowOrder::NUMBER, null, $ceiling);
    }

    /**
     * Counts each session once per closed period, including guests and boundary touches.
     *
     * @param string $timeZone Captured reader zone
     * @param int $weekStartTs Validated week start in UTC epoch milliseconds
     * @param int $dayStartTs Validated day start in UTC epoch milliseconds
     * @param int $hourStartTs Validated hour start in UTC epoch milliseconds
     * @param int $asOfTs Common server upper bound in UTC epoch milliseconds
     * @return AnalyticsOverviewSnapshot Exact aggregates from one SQL read, without a table ceiling
     * @throws HilosException When the query fails or its counts cannot be represented exactly on the wire
     */
    public function overviewSnapshot(
        string $timeZone,
        int $weekStartTs,
        int $dayStartTs,
        int $hourStartTs,
        int $asOfTs,
    ): AnalyticsOverviewSnapshot {
        // TODO(owner, 2026-10-19): replace this raw-data SQL when the analytics reading model lands.
        Database::sql(
            'SELECT COUNT(*) AS `week_count`,
                    COALESCE(SUM(CASE WHEN `last_seen_ts` >= ? THEN 1 ELSE 0 END), 0) AS `day_count`,
                    COALESCE(SUM(CASE WHEN `last_seen_ts` >= ? THEN 1 ELSE 0 END), 0) AS `hour_count`
             FROM `hilos_analytics_browser_session`
             WHERE `first_seen_ts` <= ? AND `last_seen_ts` >= ?',
            [$dayStartTs, $hourStartTs, $asOfTs, $weekStartTs],
        );
        $row = Database::row();
        if ($row === null) {
            throw new DatabaseRuntimeException('Analytics overview query returned no aggregate row');
        }

        return new AnalyticsOverviewSnapshot(
            $timeZone, $weekStartTs, $dayStartTs, $hourStartTs, $asOfTs,
            $this->overviewCount($row['week_count']),
            $this->overviewCount($row['day_count']),
            $this->overviewCount($row['hour_count']),
        );
    }

    /**
     * @param int $userId Last signed-in account number
     * @param AnalyticsWindowOrder $order Canonical last-seen or row-number order
     * @param AnalyticsWindowAnchor $anchor Complete row key
     * @param int $ceiling Exact-count ceiling; one extra row signals overflow
     * @return int Sessions preceding the anchor, at most ceiling plus one
     * @throws InvalidArgumentException When the anchor lacks its required source moment
     * @throws HilosException When the analytics count query fails
     */
    public function browserSessionCountBefore(
        int $userId,
        AnalyticsWindowOrder $order,
        AnalyticsWindowAnchor $anchor,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->boundedBrowserSessionCount($userId, $order, $anchor, $ceiling);
    }

    /**
     * Aggregates all matching sessions before applying the numeric account or last-seen anchor.
     * Each query reads the facts currently loaded; successive windows are not a repeatable snapshot.
     *
     * @param AnalyticsPeriodFilter $filter Validated calendar context and selected period
     * @param AnalyticsWindowOrder $order Canonical grouped last-seen or numeric account order
     * @param ?AnalyticsWindowAnchor $anchor Account number and, for time order, its grouped last-seen moment
     * @param TableAnchorDirection $direction Side of the anchor in the chosen order
     * @param int $limit Requested number of people, capped at MAX_PAGE_ROWS
     * @param int $skip People to skip from the chosen edge when there is no anchor
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @return list<AnalyticsSectionPerson> Grouped people in canonical order, empty before context arrives
     * @throws InvalidArgumentException When a skip accompanies an anchor or its required moment is missing
     * @throws HilosException When the period is invalid, the query fails or a session count is unsafe
     */
    public function personWindow(
        AnalyticsPeriodFilter $filter,
        AnalyticsWindowOrder $order,
        ?AnalyticsWindowAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
        int $skip,
        int $asOfTs,
    ): array {
        $filter->validateAsOf($asOfTs);
        if ($anchor !== null && $skip !== 0) {
            throw new InvalidArgumentException('Analytics skip requires an edge');
        }
        $params = [];
        $groupedSql = $this->personSetSql($filter, $asOfTs, $params);
        $cursor = $this->anchorClause(self::PERSON_MOMENT_SQL, self::PERSON_ID_SQL, $order, $anchor, $direction, $params);
        $limit = $this->boundedLimit($limit);
        $skip = max(0, $skip);
        if (!$filter->hasContext() || $limit === 0 || $skip > TableConstants::COUNT_CEILING) {
            return [];
        }
        $sqlOrder = $this->windowOrder(self::PERSON_MOMENT_SQL, self::PERSON_ID_SQL, $order, $direction);
        Database::sql(
            'SELECT p.`user_id`, p.`session_count`, p.`last_seen_ts` FROM (' . $groupedSql . ') p
             WHERE 1' . $cursor . ' ORDER BY ' . $sqlOrder . ' LIMIT ' . $limit . ' OFFSET ' . $skip,
            $params,
        );

        $people = [];
        foreach (Database::rows() as $row) {
            $sessionCount = filter_var(
                $row['session_count'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_SAFE_COUNT]],
            );
            if ($sessionCount === false) {
                throw new DatabaseRuntimeException('Analytics session count is outside the exact integer range');
            }
            $people[] = new AnalyticsSectionPerson((int)$row['user_id'], $sessionCount, (int)$row['last_seen_ts']);
        }

        return $direction === TableAnchorDirection::Before ? array_reverse($people) : $people;
    }

    /**
     * @param AnalyticsPeriodFilter $filter Validated calendar context and selected period
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @param int $ceiling Exact-count ceiling; one extra person signals overflow
     * @return int Matching people, at most ceiling plus one, zero before context arrives
     * @throws HilosException When the period is invalid or the grouped count query fails
     */
    public function personCount(AnalyticsPeriodFilter $filter, int $asOfTs, int $ceiling = TableConstants::COUNT_CEILING): int
    {
        return $this->boundedPersonCount($filter, AnalyticsWindowOrder::NUMBER, null, $asOfTs, $ceiling);
    }

    /**
     * @param AnalyticsPeriodFilter $filter Validated calendar context and selected period
     * @param AnalyticsWindowOrder $order Canonical grouped last-seen or numeric account order
     * @param AnalyticsWindowAnchor $anchor Account number and, for time order, its grouped last-seen moment
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @param int $ceiling Exact-count ceiling; one extra person signals overflow
     * @return int People preceding the anchor, at most ceiling plus one, zero before context arrives
     * @throws InvalidArgumentException When the anchor lacks its required source moment
     * @throws HilosException When the period is invalid or the grouped count query fails
     */
    public function personCountBefore(
        AnalyticsPeriodFilter $filter,
        AnalyticsWindowOrder $order,
        AnalyticsWindowAnchor $anchor,
        int $asOfTs,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->boundedPersonCount($filter, $order, $anchor, $asOfTs, $ceiling);
    }

    /**
     * Reads the intersecting session set before applying the window boundary.
     * Successive requests read current facts rather than a repeatable snapshot.
     *
     * @param AnalyticsPeriodFilter $filter Validated calendar context and selected period
     * @param AnalyticsWindowOrder $order Canonical last-seen or row-number order
     * @param ?AnalyticsWindowAnchor $anchor Complete row key, or an edge of the set
     * @param TableAnchorDirection $direction Side of the anchor in the chosen order
     * @param int $limit Requested number of rows, capped at MAX_PAGE_ROWS
     * @param int $skip Rows to skip from the chosen edge when there is no anchor
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @return list<AnalyticsSectionBrowserSession> Sessions in canonical order, empty before context arrives
     * @throws InvalidArgumentException When a skip accompanies an anchor or its required moment is missing
     * @throws HilosException When the period is invalid or an analytics or mounted account read fails
     */
    public function browserSessionPeriodWindow(
        AnalyticsPeriodFilter $filter,
        AnalyticsWindowOrder $order,
        ?AnalyticsWindowAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
        int $skip,
        int $asOfTs,
    ): array {
        $filter->validateAsOf($asOfTs);
        if ($anchor !== null && $skip !== 0) {
            throw new InvalidArgumentException('Analytics skip requires an edge');
        }
        $params = [self::IDENTITY_TYPE_USER_ID];
        $periodClause = $this->browserSessionPeriodWhereSql($filter, $asOfTs, $params);
        $cursor = $this->anchorClause(self::SESSION_MOMENT_SQL, self::SESSION_ID_SQL, $order, $anchor, $direction, $params);
        $limit = $this->boundedLimit($limit);
        $skip = max(0, $skip);
        if (!$filter->hasContext() || $limit === 0 || $skip > TableConstants::COUNT_CEILING) {
            return [];
        }
        $sqlOrder = $this->windowOrder(self::SESSION_MOMENT_SQL, self::SESSION_ID_SQL, $order, $direction);
        // TODO(owner, 2026-10-19): replace this raw-data SQL when the analytics reading model lands.
        Database::sql(
            'SELECT b.`id`, b.`first_seen_ts`, b.`last_seen_ts`, u.`value` AS `user_agent`,
                    CASE WHEN b.`user_identity_type` = ? THEN b.`user_identity_value` END AS `user_id_value`
             FROM `hilos_analytics_browser_session` b
             LEFT JOIN `hilos_analytics_user_agent` u ON u.`id` = b.`current_user_agent_id`
             WHERE ' . $periodClause . $cursor . '
             ORDER BY ' . $sqlOrder . ' LIMIT ' . $limit . ' OFFSET ' . $skip,
            $params,
        );
        $rows = Database::rows();
        if ($rows === []) {
            return [];
        }

        $counts = $this->actionCounts(array_map(static fn(array $row): int => (int)$row['id'], $rows));
        $sessions = [];
        foreach ($rows as $row) {
            if (filter_var($row['id'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => self::MAX_SAFE_SESSION_ID],
            ]) === false) {
                throw new DatabaseRuntimeException('Analytics session number is outside the positive safe integer range');
            }
            $userId = null;
            if ($row['user_id_value'] !== null && ctype_digit((string)$row['user_id_value'])) {
                $parsed = filter_var(ltrim((string)$row['user_id_value'], '0'), FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => self::MAX_SAFE_USER_ID],
                ]);
                $userId = $parsed === false ? null : $parsed;
            }
            $label = $userId === null ? null : (isset(Hilos::$db->users[$userId])
                ? Hilos::$db->users[$userId]->name : 'Deleted user #' . $userId);
            $sessions[] = $this->sessionFromRow($row, $counts, $userId, $label);
        }

        return $direction === TableAnchorDirection::Before ? array_reverse($sessions) : $sessions;
    }

    /**
     * @param AnalyticsPeriodFilter $filter Validated calendar context and selected period
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @param int $ceiling Exact-count ceiling; one extra row signals overflow
     * @return int Matching sessions, at most ceiling plus one, zero before context arrives
     * @throws HilosException When the period is invalid or the count query fails
     */
    public function browserSessionPeriodCount(
        AnalyticsPeriodFilter $filter,
        int $asOfTs,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->boundedBrowserSessionPeriodCount($filter, AnalyticsWindowOrder::NUMBER, null, $asOfTs, $ceiling);
    }

    /**
     * @param AnalyticsPeriodFilter $filter Validated calendar context and selected period
     * @param AnalyticsWindowOrder $order Canonical last-seen or row-number order
     * @param AnalyticsWindowAnchor $anchor Complete row key
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @param int $ceiling Exact-count ceiling; one extra row signals overflow
     * @return int Sessions preceding the anchor, at most ceiling plus one, zero before context arrives
     * @throws InvalidArgumentException When the anchor lacks its required source moment
     * @throws HilosException When the period is invalid or the count query fails
     */
    public function browserSessionPeriodCountBefore(
        AnalyticsPeriodFilter $filter,
        AnalyticsWindowOrder $order,
        AnalyticsWindowAnchor $anchor,
        int $asOfTs,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->boundedBrowserSessionPeriodCount($filter, $order, $anchor, $asOfTs, $ceiling);
    }

    /**
     * @param AnalyticsPeriodFilter $filter Validated calendar context and selected period
     * @param AnalyticsWindowOrder $order Canonical window order
     * @param ?AnalyticsWindowAnchor $anchor Key to count before, or null for the whole set
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @param int $ceiling Exact-count ceiling
     * @return int Matching sessions, capped at ceiling plus one
     * @throws InvalidArgumentException When the anchor lacks its required source moment
     * @throws HilosException When the period is invalid or the count query fails
     */
    private function boundedBrowserSessionPeriodCount(
        AnalyticsPeriodFilter $filter,
        AnalyticsWindowOrder $order,
        ?AnalyticsWindowAnchor $anchor,
        int $asOfTs,
        int $ceiling,
    ): int {
        $filter->validateAsOf($asOfTs);
        $params = [];
        $periodClause = $this->browserSessionPeriodWhereSql($filter, $asOfTs, $params);
        $cursor = $this->anchorClause(
            self::SESSION_MOMENT_SQL, self::SESSION_ID_SQL, $order, $anchor, TableAnchorDirection::Before, $params,
        );
        if (!$filter->hasContext()) {
            return 0;
        }
        $limit = max(0, min($ceiling, TableConstants::COUNT_CEILING)) + 1;
        // TODO(owner, 2026-10-19): replace this raw-data SQL when the analytics reading model lands.
        Database::sql(
            'SELECT COUNT(*) AS `total_count` FROM (SELECT 1 FROM `hilos_analytics_browser_session` b
             WHERE ' . $periodClause . $cursor . ' LIMIT ' . $limit . ') capped',
            $params,
        );

        return (int)Database::field('total_count');
    }

    /**
     * @param AnalyticsPeriodFilter $filter Validated calendar context and selected period
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @param list<mixed> $params SQL parameters extended by the period selection
     * @return string Closed-interval predicate, without an anchor or limit
     */
    private function browserSessionPeriodWhereSql(AnalyticsPeriodFilter $filter, int $asOfTs, array &$params): string
    {
        $params[] = $asOfTs;
        $periodClause = 'b.`first_seen_ts` <= ?';
        if ($filter->startTs() !== null) {
            $periodClause .= ' AND b.`last_seen_ts` >= ?';
            $params[] = $filter->startTs();
        }
        return $periodClause;
    }

    /**
     * @param AnalyticsPeriodFilter $filter Validated calendar context and selected period
     * @param AnalyticsWindowOrder $order Canonical grouped window order
     * @param ?AnalyticsWindowAnchor $anchor Key to count before, or null for the whole set
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @param int $ceiling Exact-count ceiling
     * @return int Matching groups, capped at ceiling plus one
     * @throws InvalidArgumentException When the anchor lacks its required source moment
     * @throws HilosException When the period is invalid or the grouped count query fails
     */
    private function boundedPersonCount(
        AnalyticsPeriodFilter $filter,
        AnalyticsWindowOrder $order,
        ?AnalyticsWindowAnchor $anchor,
        int $asOfTs,
        int $ceiling,
    ): int {
        $filter->validateAsOf($asOfTs);
        $params = [];
        $groupedSql = $this->personSetSql($filter, $asOfTs, $params);
        $cursor = $this->anchorClause(
            self::PERSON_MOMENT_SQL, self::PERSON_ID_SQL, $order, $anchor, TableAnchorDirection::Before, $params,
        );
        if (!$filter->hasContext()) {
            return 0;
        }
        $limit = max(0, min($ceiling, TableConstants::COUNT_CEILING)) + 1;
        Database::sql(
            'SELECT COUNT(*) AS `total_count` FROM (SELECT 1 FROM (' . $groupedSql . ') p
             WHERE 1' . $cursor . ' LIMIT ' . $limit . ') capped',
            $params,
        );

        return (int)Database::field('total_count');
    }

    /**
     * @param AnalyticsPeriodFilter $filter Validated calendar context and selected period
     * @param int $asOfTs Server upper bound in UTC epoch milliseconds
     * @param list<mixed> $params SQL parameters extended by the period selection
     * @return string Grouped SQL set without a window limit or anchor predicate
     */
    private function personSetSql(AnalyticsPeriodFilter $filter, int $asOfTs, array &$params): string
    {
        array_push($params, self::IDENTITY_TYPE_USER_ID, self::MAX_SAFE_USER_ID, $asOfTs);
        $periodClause = '';
        if ($filter->startTs() !== null) {
            $periodClause = ' AND b.`last_seen_ts` >= ?';
            $params[] = $filter->startTs();
        }

        // TODO(owner, 2026-10-19): replace this raw-data SQL when the analytics reading model lands.
        return 'SELECT CAST(b.`user_identity_value` AS UNSIGNED) AS `user_id`,
                       COUNT(*) AS `session_count`, MAX(b.`last_seen_ts`) AS `last_seen_ts`
                FROM `hilos_analytics_browser_session` b
                WHERE b.`user_identity_type` = ? AND b.`user_identity_value` NOT REGEXP \'[^0-9]\'
                  AND CAST(b.`user_identity_value` AS UNSIGNED) BETWEEN 1 AND ?
                  AND b.`first_seen_ts` <= ?' . $periodClause . '
                GROUP BY `user_id`';
    }

    /**
     * @param mixed $value SQL aggregate at the storage boundary
     * @return int Exact nonnegative JavaScript-safe count
     * @throws DatabaseRuntimeException When the aggregate exceeds the wire's exact integer range
     */
    private function overviewCount(mixed $value): int
    {
        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_SAFE_COUNT]]);
        if ($count === false) {
            throw new DatabaseRuntimeException('Analytics overview count is outside the exact integer range');
        }

        return $count;
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
            $row['user_agent'] === null ? null : AnalyticsBrowserLabel::fromUserAgent((string)$row['user_agent']),
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
     * @param string $momentColumn Trusted SQL source-moment column
     * @param string $idColumn Trusted SQL row-number column
     * @param AnalyticsWindowOrder $order Canonical window order
     * @param ?AnalyticsWindowAnchor $anchor Complete row key, or null for an edge
     * @param TableAnchorDirection $direction Side of the key in canonical order
     * @param list<mixed> $params Raw SQL parameters extended by this predicate
     * @return string SQL predicate including leading AND, empty at an edge
     * @throws InvalidArgumentException When the anchor lacks its required source moment
     */
    private function anchorClause(
        string $momentColumn,
        string $idColumn,
        AnalyticsWindowOrder $order,
        ?AnalyticsWindowAnchor $anchor,
        TableAnchorDirection $direction,
        array &$params,
    ): string {
        if ($anchor === null) {
            return '';
        }
        $descending = ($order === AnalyticsWindowOrder::TIME_DESC) === ($direction === TableAnchorDirection::After);
        $operator = $descending ? '<' : '>';
        if ($order === AnalyticsWindowOrder::NUMBER) {
            $params[] = $anchor->id;
            return " AND {$idColumn} {$operator} ?";
        }
        if ($anchor->moment === null) {
            throw new InvalidArgumentException('Analytics time anchor requires a source moment');
        }
        array_push($params, $anchor->moment, $anchor->moment, $anchor->id);

        return " AND ({$momentColumn} {$operator} ? OR ({$momentColumn} = ? AND {$idColumn} {$operator} ?))";
    }

    /**
     * @param string $momentColumn Trusted SQL source-moment column
     * @param string $idColumn Trusted SQL row-number column
     * @param AnalyticsWindowOrder $order Canonical window order
     * @param TableAnchorDirection $direction Side the window is taken from
     * @return string SQL order, inverted when the window is taken before its anchor
     */
    private function windowOrder(
        string $momentColumn,
        string $idColumn,
        AnalyticsWindowOrder $order,
        TableAnchorDirection $direction,
    ): string {
        $descending = ($order === AnalyticsWindowOrder::TIME_DESC) === ($direction === TableAnchorDirection::After);
        $sqlDirection = $descending ? SqlSortDirection::DESC : SqlSortDirection::ASC;

        return $order === AnalyticsWindowOrder::NUMBER
            ? "{$idColumn} {$sqlDirection}" : "{$momentColumn} {$sqlDirection}, {$idColumn} {$sqlDirection}";
    }

    /**
     * @param int $browserSessionId Numeric analytics row key
     * @param AnalyticsWindowOrder $order Canonical window order
     * @param ?AnalyticsWindowAnchor $anchor Key to count before, or null for the whole set
     * @param int $ceiling Exact-count ceiling
     * @return int Matching actions, capped at ceiling plus one
     * @throws InvalidArgumentException When the anchor lacks its required source moment
     * @throws HilosException When the analytics count query fails
     */
    private function boundedActionCount(
        int $browserSessionId,
        AnalyticsWindowOrder $order,
        ?AnalyticsWindowAnchor $anchor,
        int $ceiling,
    ): int {
        $params = [$browserSessionId];
        $cursor = $this->anchorClause(
            self::ACTION_MOMENT_SQL, self::ACTION_ID_SQL, $order, $anchor, TableAnchorDirection::Before, $params,
        );
        $limit = max(0, min($ceiling, TableConstants::COUNT_CEILING)) + 1;
        Database::sql(
            'SELECT COUNT(*) AS `total_count` FROM (SELECT 1 FROM `hilos_analytics_user_action` a
             JOIN `hilos_analytics_ws_connection` w ON w.`id` = a.`ws_connection_id`
             JOIN `hilos_analytics_action_name` n ON n.`id` = a.`action_name_id`
             LEFT JOIN `hilos_analytics_page_session` ps ON ps.`id` = a.`page_session_id`
             LEFT JOIN `hilos_analytics_page` p ON p.`id` = ps.`page_id`
             WHERE w.`browser_session_id` = ?' . $cursor . ' LIMIT ' . $limit . ') capped',
            $params,
        );

        return (int)Database::field('total_count');
    }

    /**
     * @param int $userId Last signed-in account number
     * @param AnalyticsWindowOrder $order Canonical window order
     * @param ?AnalyticsWindowAnchor $anchor Key to count before, or null for the whole set
     * @param int $ceiling Exact-count ceiling
     * @return int Matching sessions, capped at ceiling plus one
     * @throws InvalidArgumentException When the anchor lacks its required source moment
     * @throws HilosException When the analytics count query fails
     */
    private function boundedBrowserSessionCount(
        int $userId,
        AnalyticsWindowOrder $order,
        ?AnalyticsWindowAnchor $anchor,
        int $ceiling,
    ): int {
        $params = [self::IDENTITY_TYPE_USER_ID, (string)$userId];
        $cursor = $this->anchorClause(
            self::SESSION_MOMENT_SQL, self::SESSION_ID_SQL, $order, $anchor, TableAnchorDirection::Before, $params,
        );
        $limit = max(0, min($ceiling, TableConstants::COUNT_CEILING)) + 1;
        Database::sql(
            'SELECT COUNT(*) AS `total_count` FROM (SELECT 1 FROM `hilos_analytics_browser_session` b
             WHERE b.`user_identity_type` = ? AND b.`user_identity_value` = ?' . $cursor . ' LIMIT ' . $limit . ') capped',
            $params,
        );

        return (int)Database::field('total_count');
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
