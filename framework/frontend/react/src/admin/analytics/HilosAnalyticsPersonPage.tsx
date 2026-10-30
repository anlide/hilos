// HilosAnalyticsPersonPage — one person's page in the Analytics section
// (HilosPages.ANALYTICS_PERSON): the browser sessions in which they signed in
// last. The admin shell draws the one heading (the person's name and number from
// the page's answer), the lead, and the table's accessible name; the table is the
// core's browser-sessions viewport, preset to the person in the address and drawn
// once per subscription, without live updates. Each session number opens that
// session's page (HIL-1419). A viewer of the admin view mode sees Hidden in place
// of every value. The core owns the words and the table, so a project mounts this
// view by passing its HilosAnalyticsContext. Bootstrap classes only
// (styling-rules.md).
import { useContext, useEffect, useMemo } from 'react'
import {
  ANALYTICS_BROWSER_SESSION_ACTION_COUNT_FIELD,
  ANALYTICS_BROWSER_SESSION_BROWSER_FIELD,
  ANALYTICS_BROWSER_SESSION_FIRST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LAST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_ROUTE_PARAM,
  ANALYTICS_NO_VALUE,
  ANALYTICS_PERSON_ROUTE_PARAM,
  createHilosAnalyticsBrowserSessionsTable,
  formatAnalyticsSessionNumber,
  formatAnalyticsTime,
  hilosAnalyticsPerson,
  readAnalyticsRouteId,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import {
  computedSignal,
  HilosPages,
  hilosTimeZone,
  isHiddenValue,
} from '@hilos/core/base'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosAvatar } from '../../HilosAvatar.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { usePageInstanceName } from '../../usePageInstanceName.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosAnalyticsPersonPage}. */
export interface HilosAnalyticsPersonPageProps {
  /** The project's connection and page scope. */
  context: HilosAnalyticsContext
}

/**
 * The person page of the Analytics section: the browser sessions in which the
 * person in the address signed in last.
 *
 * @param props The project context (connection and page scope).
 */
export function HilosAnalyticsPersonPage({
  context,
}: HilosAnalyticsPersonPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosAnalyticsPersonPage requires a provided Hilos router.',
    )
  }

  const pageLabel = useMemo(
    () => context.scopes.pageDataSignal('pageLabel'),
    [context.scopes],
  )
  const permittedName = useMemo(
    () =>
      computedSignal(() => {
        if (context.scopes.page()?.key !== HilosPages.ANALYTICS_PERSON) {
          return undefined
        }
        const label = pageLabel.get()
        return typeof label === 'string' ? label : undefined
      }),
    [context.scopes, pageLabel],
  )
  usePageInstanceName(
    context.scopes,
    HilosPages.ANALYTICS_PERSON,
    permittedName,
  )

  const person = useMemo(
    () => hilosAnalyticsPerson(context.scopes),
    [context.scopes],
  )
  const currentPerson = useSignal(person)
  const identity = useSignal(router.pageIdentity)
  const missingName =
    currentPerson === undefined ||
    currentPerson.name === null ||
    isHiddenValue(currentPerson.name)
  const avatarName =
    currentPerson === undefined ||
    currentPerson.name === null ||
    isHiddenValue(currentPerson.name)
      ? ''
      : currentPerson.name
  const avatarPhoto =
    currentPerson === undefined ||
    currentPerson.name === null ||
    isHiddenValue(currentPerson.name) ||
    isHiddenValue(currentPerson.photo)
      ? null
      : currentPerson.photo

  const route = useSignal(router.currentRoute)
  useSignal(hilosTimeZone)
  const userId = readAnalyticsRouteId(
    route.params[ANALYTICS_PERSON_ROUTE_PARAM],
  )
  const sessions = useMemo(
    () =>
      userId === null
        ? null
        : createHilosAnalyticsBrowserSessionsTable(context, {
            page: HilosPages.ANALYTICS_PERSON,
            userId,
          }),
    [context, userId],
  )

  // Bind the person's table after the render that published its route, once
  // the router has dropped the previous page's buffered answer, and release it
  // when the number changes, disappears, or the page unmounts.
  useEffect(() => {
    if (sessions === null) {
      return
    }
    sessions.start()

    return () => sessions.dispose()
  }, [sessions])

  return (
    <HilosAdminPage
      page={HilosPages.ANALYTICS_PERSON}
      headKind="entity"
      headVisual={
        <span data-id="analytics-person-head-avatar" aria-hidden="true">
          <HilosAvatar
            name={avatarName}
            photo={avatarPhoto}
            icon={missingName ? 'bi-person' : null}
            size="md"
          />
        </span>
      }
      headDescription={
        <p
          className="text-body-secondary mb-0"
          data-id="analytics-person-head-description"
        >
          {identity?.pageHeadOwner?.lead}
        </p>
      }
      headSkeletonVisual={
        <span className="hilos-avatar-md rounded-circle bg-secondary-subtle flex-shrink-0" />
      }
    >
      {sessions !== null ? (
        <HilosViewportTable
          key={userId}
          controller={sessions.controller}
          dataId="hilos-analytics-browser-sessions-table"
          cells={{
            rowKey: (row) => {
              // A session's page, or nothing when the application has no address for it.
              const path = router.resolvePath(
                HilosPages.ANALYTICS_BROWSER_SESSION,
                {
                  [ANALYTICS_BROWSER_SESSION_ROUTE_PARAM]: String(row.rowKey),
                },
              )

              return path ? (
                <HilosLink
                  to={path}
                  data-id="hilos-analytics-session-link"
                  data-record={row.rowKey}
                >
                  {formatAnalyticsSessionNumber(row.rowKey)}
                </HilosLink>
              ) : (
                <span
                  data-id="hilos-analytics-session-link"
                  data-record={row.rowKey}
                >
                  {formatAnalyticsSessionNumber(row.rowKey)}
                </span>
              )
            },
            [ANALYTICS_BROWSER_SESSION_FIRST_SEEN_TS_FIELD]: (row) => (
              <span
                data-id="hilos-analytics-session-first-seen"
                data-record={row.rowKey}
              >
                <HilosHideable value={row.firstSeenTs}>
                  {(value) => formatAnalyticsTime(value)}
                </HilosHideable>
              </span>
            ),
            [ANALYTICS_BROWSER_SESSION_LAST_SEEN_TS_FIELD]: (row) => (
              <span
                data-id="hilos-analytics-session-last-seen"
                data-record={row.rowKey}
              >
                <HilosHideable value={row.lastSeenTs}>
                  {(value) => formatAnalyticsTime(value)}
                </HilosHideable>
              </span>
            ),
            [ANALYTICS_BROWSER_SESSION_BROWSER_FIELD]: (row) => (
              <span
                data-id="hilos-analytics-session-browser"
                data-record={row.rowKey}
                className="text-break"
              >
                <HilosHideable value={row.browser}>
                  {(value) => value ?? ANALYTICS_NO_VALUE}
                </HilosHideable>
              </span>
            ),
            [ANALYTICS_BROWSER_SESSION_ACTION_COUNT_FIELD]: (row) => (
              <span
                data-id="hilos-analytics-session-actions"
                data-record={row.rowKey}
              >
                <HilosHideable value={row.actionCount} />
              </span>
            ),
          }}
        />
      ) : null}
    </HilosAdminPage>
  )
}
