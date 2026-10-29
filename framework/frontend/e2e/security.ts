import type { Expect, Page } from '@playwright/test'

import type { HilosSecurityOverview } from '../core/src/admin/security/hilosSecurityOverview.js'
import type { HilosOAuthProviderHead } from '../core/src/admin/security/hilosSecurityOauthHead.js'
import {
  deliveryObject as object,
  type DeliveryFrameLog,
  type DeliveryPacket,
  type DeliveryRequest,
} from './deliveryFrames.js'
import { clickSubmit } from './input.js'
import type { SocketFrame } from './socket.js'
import { shownByTestId } from './table.js'
import { dismissToasts } from './toasts.js'

export const SECURITY_PATH = '/hilos/security'
export const SECURITY_PROVIDER_PAGE = 'hilos_security_oauth_provider'
export const SECURITY_LEAD =
  'Sign-in methods, two-factor authentication and OAuth login providers.'
export const SECURITY_SECTIONS = [
  { key: 'hilos_security', label: 'Overview', path: SECURITY_PATH },
  {
    key: 'hilos_security_2fa',
    label: 'Two-factor',
    path: `${SECURITY_PATH}/2fa`,
  },
  {
    key: 'hilos_security_step_up',
    label: 'Operations that ask for confirmation',
    path: `${SECURITY_PATH}/step-up`,
  },
  {
    key: 'hilos_security_oauth',
    label: 'OAuth providers',
    path: `${SECURITY_PATH}/oauth`,
  },
  {
    key: 'hilos_security_sign_in_methods',
    label: 'Sign-in methods',
    path: `${SECURITY_PATH}/sign-in-methods`,
  },
  {
    key: 'hilos_security_impersonation',
    label: 'Impersonation',
    path: `${SECURITY_PATH}/impersonation`,
  },
] as const

/** A reply retains its request, physical packet and inner frame as evidence. */
export interface SecurityPageReply {
  request: DeliveryRequest
  packet: DeliveryPacket
  frame: SocketFrame
  payload: Record<string, unknown>
  data: Record<string, unknown>
}

/** Closed catalog sources are declared by the fixture, independently of identity. */
export type SecurityOverviewHiddenField =
  | 'twoFactor.enabled'
  | 'twoFactor.required'
  | 'stepUp.enabledCount'
  | 'signInMethods.enabledCount'
  | 'signInMethods.unready'
  | 'impersonation.allowed'
  | 'impersonation.scope'

/**
 * Read one opening, bookmarked by sent index before navigation. The route lives
 * in page_subscribe, not in the reply: use its socket and lifetime, ending at the
 * next subscription on that socket. Never merge first and later responses.
 */
export function securityPageReplies(
  log: DeliveryFrameLog,
  afterSent: number,
  pageKey: string,
  params: Record<string, string> = {},
): SecurityPageReply[] {
  const requestIndex = log.sent.findIndex((request, index) => {
    const wire = object(request.wire)
    const route = object(wire.params)
    return (
      index >= afterSent &&
      wire.type === 'page_subscribe' &&
      wire.page === pageKey &&
      Object.keys(route).length === Object.keys(params).length &&
      Object.entries(params).every(([key, value]) => route[key] === value)
    )
  })
  if (requestIndex < 0) return []
  const request = log.sent[requestIndex]!
  const next = log.sent.slice(requestIndex + 1).find((candidate) => {
    return (
      candidate.socket === request.socket &&
      object(candidate.wire).type === 'page_subscribe'
    )
  })
  return log.packets.flatMap((packet) => {
    if (
      packet.socket !== request.socket ||
      packet.at < request.at ||
      (next !== undefined && packet.at >= next.at)
    ) {
      return []
    }
    return packet.frames.flatMap((frame) => {
      const wire = object(frame.wire)
      const response = object(wire.data)
      // An undecodable physical message inside this subscription cannot be
      // assigned to another page. Refuse that evidence instead of letting a
      // later good answer hide it; the canonical decoder retains its raw text.
      const opaqueFailure =
        frame.failure !== undefined && frame.wire === undefined
      if (
        !opaqueFailure &&
        (wire.type !== 'page_response' || response.page !== pageKey)
      )
        return []
      // A decoder failure is evidence too: keep the original frame and let the
      // check report it, rather than silently selecting a later valid response.
      const payload = object(response.payload)
      return [{ request, packet, frame, payload, data: object(payload.data) }]
    })
  })
}

/** Await this route's first response and reject a failed or opaque inner. */
export async function expectSecurityFirstReply(
  expect: Expect,
  log: DeliveryFrameLog,
  afterSent: number,
  pageKey: string,
  params: Record<string, string> = {},
): Promise<SecurityPageReply> {
  await expect
    .poll(() => securityPageReplies(log, afterSent, pageKey, params).length)
    .toBeGreaterThan(0)
  const reply = securityPageReplies(log, afterSent, pageKey, params)[0]!
  expect(reply.frame.failure).toBeUndefined()
  expect(reply.frame.raw).toEqual(expect.any(String))
  return reply
}

/** Validate public tallies and explicit catalog masks; open sources are the default. */
export function expectSecurityOverviewData(
  expect: Expect,
  reply: SecurityPageReply,
  hiddenFields: readonly SecurityOverviewHiddenField[] = [],
): HilosSecurityOverview {
  const setting = (field: SecurityOverviewHiddenField, visible: unknown) =>
    hiddenFields.includes(field) ? { _hidden: true } : visible
  const value = reply.data.securityOverview as HilosSecurityOverview
  expect(reply.frame.failure).toBeUndefined()
  expect(reply.frame.raw).toEqual(expect.any(String))
  expect(value).toEqual({
    twoFactor: {
      enabled: setting('twoFactor.enabled', expect.any(Boolean)),
      required: setting(
        'twoFactor.required',
        expect.stringMatching(/^(none|admins|everyone)$/),
      ),
    },
    stepUp: {
      enabledCount: setting('stepUp.enabledCount', expect.any(Number)),
      totalCount: expect.any(Number),
    },
    oauth: {
      configuredCount: expect.any(Number),
      totalCount: expect.any(Number),
      unconfigured: expect.any(Array),
    },
    signInMethods: {
      enabledCount: setting('signInMethods.enabledCount', expect.any(Number)),
      totalCount: expect.any(Number),
      unready: setting('signInMethods.unready', expect.any(Array)),
    },
    impersonation: {
      allowed: setting('impersonation.allowed', expect.any(Boolean)),
      scope: setting(
        'impersonation.scope',
        expect.stringMatching(/^(view|act)$/),
      ),
    },
  })
  for (const number of [
    value.stepUp.totalCount,
    value.signInMethods.totalCount,
    value.oauth.totalCount,
    value.oauth.configuredCount,
    ...(!hiddenFields.includes('stepUp.enabledCount')
      ? [value.stepUp.enabledCount]
      : []),
    ...(!hiddenFields.includes('signInMethods.enabledCount')
      ? [value.signInMethods.enabledCount]
      : []),
  ]) {
    expect(Number.isInteger(number)).toBe(true)
    expect(number).toBeGreaterThanOrEqual(0)
  }
  expect(value.oauth.configuredCount).toBeLessThanOrEqual(
    value.oauth.totalCount,
  )
  expect(value.oauth.unconfigured).toHaveLength(
    value.oauth.totalCount - value.oauth.configuredCount,
  )
  for (const provider of value.oauth.unconfigured) {
    expect(provider).toEqual({
      providerKey: expect.any(String),
      label: expect.any(String),
      missingFields: expect.any(Number),
    })
    expect([1, 2]).toContain(provider.missingFields)
  }
  if (!hiddenFields.includes('stepUp.enabledCount'))
    expect(value.stepUp.enabledCount).toBeLessThanOrEqual(
      value.stepUp.totalCount,
    )
  if (!hiddenFields.includes('signInMethods.enabledCount'))
    expect(value.signInMethods.enabledCount).toBeLessThanOrEqual(
      value.signInMethods.totalCount,
    )
  if (!hiddenFields.includes('signInMethods.unready')) {
    for (const method of value.signInMethods.unready as readonly unknown[]) {
      expect(method).toEqual({
        methodKey: expect.any(String),
        label: expect.any(String),
      })
    }
  }
  return value
}

/** The complete section identity comes with the first page answer. */
export function expectSecurityIdentity(
  expect: Expect,
  reply: SecurityPageReply,
): void {
  expect(reply.data).toMatchObject({
    pageHeadOwner: {
      page: 'hilos_security',
      label: 'Security Center',
      lead: SECURITY_LEAD,
      icon: 'bi-shield-lock',
    },
    pageTabs: SECURITY_SECTIONS.map(({ key, label }) => ({ page: key, label })),
    pageChildren: [],
  })
  expect(reply.data.pageBreadcrumb).toEqual([
    { page: 'hilos', label: 'Hilos' },
    { page: 'hilos_security', label: 'Security Center' },
  ])
}

/** Check all native links and the single current section tab, without navigating. */
export async function expectSecuritySectionHead(
  expect: Expect,
  page: Page,
  current = 'hilos_security',
): Promise<void> {
  await expect(page.getByTestId('hilos-admin-page')).toHaveAttribute(
    'data-page',
    current,
  )
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveAttribute('data-id', 'hilos-page-head-title')
  await expect(heading).toHaveText('Security Center')
  const tabs = page.getByTestId('hilos-page-tabs')
  await expect(tabs).toHaveRole('navigation')
  await expect(tabs).toHaveAccessibleName('Security Center pages')
  await expect(tabs.getByRole('link')).toHaveCount(6)
  for (const section of SECURITY_SECTIONS) {
    const link = tabs.getByTestId(`hilos-page-tab-${section.key}`)
    await expect(link).toHaveRole('link')
    await expect(link).toHaveAccessibleName(section.label)
    await expect(link).toHaveAttribute('href', section.path)
  }
  await expect(tabs.locator('[aria-current="page"]')).toHaveCount(1)
  await expect(tabs.getByTestId(`hilos-page-tab-${current}`)).toHaveAttribute(
    'aria-current',
    'page',
  )
  await expect(page.getByTestId('hilos-admin-children')).toHaveCount(0)
  await expect(
    page.getByTestId('hilos-page-head-block').locator('p'),
  ).toHaveText(SECURITY_LEAD)
  await expect(page.getByTestId('hilos-breadcrumb').locator('li')).toHaveText([
    'Hilos',
    'Security Center',
  ])
}

/** The root shows the whole snapshot without mounting any of its child tables. */
export async function expectSecurityOverview(
  expect: Expect,
  page: Page,
  overview: HilosSecurityOverview,
  hiddenFields: readonly SecurityOverviewHiddenField[] = [],
): Promise<void> {
  const hidden = (field: SecurityOverviewHiddenField) =>
    hiddenFields.includes(field)
  await expectSecuritySectionHead(expect, page)
  const root = page.getByTestId('security-overview')
  await expect(root).toBeVisible()
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  for (const tile of [
    'two-factor',
    'step-up',
    'oauth',
    'sign-in-methods',
    'impersonation',
  ])
    await expect(root.getByTestId(`security-overview-${tile}`)).toBeVisible()
  for (const [tile, count, total] of [
    ['step-up', overview.stepUp.enabledCount, overview.stepUp.totalCount],
    [
      'sign-in-methods',
      overview.signInMethods.enabledCount,
      overview.signInMethods.totalCount,
    ],
    ['oauth', overview.oauth.configuredCount, overview.oauth.totalCount],
  ] as const) {
    await expect(
      root.getByTestId(`security-overview-${tile}`).locator('.fs-4'),
    ).toHaveText(
      `${(tile === 'step-up' && hidden('stepUp.enabledCount')) || (tile === 'sign-in-methods' && hidden('signInMethods.enabledCount')) ? 'Hidden' : count} of ${total}`,
    )
  }
  const oauth = root.getByRole('region', {
    name: 'OAuth configuration warnings',
    includeHidden: true,
  })
  for (const provider of overview.oauth.unconfigured)
    await expect(oauth).toContainText(
      `${provider.label}: ${provider.missingFields} required field(s) not set`,
    )
  if (overview.oauth.unconfigured.length === 0)
    await expect(oauth).toHaveAttribute('aria-hidden', 'true')
  const twoFactor = root.getByTestId('security-overview-two-factor')
  const hiddenTwoFactor =
    hidden('twoFactor.enabled') ||
    (overview.twoFactor.enabled && hidden('twoFactor.required'))
  await expect(twoFactor.getByTestId('hilos-hidden')).toHaveCount(
    hiddenTwoFactor ? 1 : 0,
  )
  if (hiddenTwoFactor) {
    await expect(twoFactor).not.toContainText(/Disabled|Required;|Nobody/)
  } else {
    const required = overview.twoFactor.required as
      'none' | 'admins' | 'everyone'
    await expect(twoFactor.locator('.fs-4')).toHaveText(
      overview.twoFactor.enabled
        ? { none: 'Nobody', admins: 'Administrators', everyone: 'Everyone' }[
            required
          ]
        : 'Disabled',
    )
  }
  const impersonation = root.getByTestId('security-overview-impersonation')
  if (hidden('impersonation.allowed')) {
    await expect(impersonation.getByTestId('hilos-hidden')).toHaveCount(1)
    await expect(impersonation).not.toContainText(
      /Disabled|Allowed|View only|View and act/,
    )
  } else {
    await expect(impersonation.locator('.fs-4')).toHaveText(
      overview.impersonation.allowed ? 'Allowed' : 'Disabled',
    )
    const hiddenScope =
      overview.impersonation.allowed && hidden('impersonation.scope')
    await expect(impersonation.getByTestId('hilos-hidden')).toHaveCount(
      hiddenScope ? 1 : 0,
    )
    if (hiddenScope)
      await expect(impersonation).not.toContainText(/View only|View and act/)
    else if (overview.impersonation.allowed)
      await expect(impersonation).toContainText(
        overview.impersonation.scope === 'act' ? 'View and act' : 'View only',
      )
  }
  const signIn = root.getByTestId('security-overview-sign-in-methods')
  await expect(signIn.getByTestId('hilos-hidden')).toHaveCount(
    Number(hidden('signInMethods.enabledCount')) +
      Number(hidden('signInMethods.unready')),
  )
  await expect(
    root.getByTestId('security-overview-step-up').getByTestId('hilos-hidden'),
  ).toHaveCount(Number(hidden('stepUp.enabledCount')))
  if (hidden('signInMethods.unready')) {
    await expect(
      root.getByRole('region', {
        name: 'Sign-in delivery warnings',
        includeHidden: true,
      }),
    ).toHaveCount(0)
  } else {
    for (const method of overview.signInMethods.unready as readonly {
      label: string
    }[])
      await expect(
        root.getByRole('region', { name: 'Sign-in delivery warnings' }),
      ).toContainText(`${method.label} has nothing to send with`)
  }
  const attributes = await root
    .locator('[title], [aria-label]')
    .evaluateAll((nodes) =>
      nodes
        .flatMap((node) => [
          node.getAttribute('title'),
          node.getAttribute('aria-label'),
        ])
        .filter(Boolean),
    )
  expect(attributes).toEqual([
    'OAuth configuration warnings',
    ...(hidden('signInMethods.unready') ? [] : ['Sign-in delivery warnings']),
  ])
}

/** The entity's first head predates the table's subsequent filtered window ask. */
export async function expectSecurityProviderFirstHead(
  expect: Expect,
  log: DeliveryFrameLog,
  reply: SecurityPageReply,
  providerKey: string,
): Promise<HilosOAuthProviderHead> {
  const head = reply.data.oauthProviderHead as HilosOAuthProviderHead
  expect(head).toEqual({
    providerKey,
    label: expect.any(String),
    configured: expect.any(Boolean),
    missingFields: expect.any(Number),
  })
  expect([0, 1, 2]).toContain(head.missingFields)
  expect(head.configured).toBe(head.missingFields === 0)
  expect(reply.data.pageTabs).toEqual([
    { page: SECURITY_PROVIDER_PAGE, label: 'Overview' },
  ])
  expect(reply.data.pageBreadcrumb).toEqual([
    { page: 'hilos', label: 'Hilos' },
    { page: 'hilos_security', label: 'Security Center' },
    { page: 'hilos_security_oauth', label: 'OAuth providers' },
    { page: SECURITY_PROVIDER_PAGE, label: 'OAuth provider' },
  ])
  const filtered = () =>
    log.sent.find((request) => {
      const wire = object(request.wire)
      return (
        request.socket === reply.request.socket &&
        request.at >= reply.request.at &&
        wire.type === 'table_viewport' &&
        wire.page === SECURITY_PROVIDER_PAGE &&
        object(wire.filter).provider === providerKey
      )
    })
  await expect.poll(filtered).toBeDefined()
  expect(reply.packet.at).toBeLessThanOrEqual(filtered()!.at)
  return head
}

/** The live entity name reaches heading, trail and table without a singleton tab. */
export async function expectSecurityProviderHead(
  expect: Expect,
  page: Page,
  head: HilosOAuthProviderHead,
): Promise<void> {
  await expect(page.getByTestId('hilos-admin-page')).toHaveAttribute(
    'data-page',
    SECURITY_PROVIDER_PAGE,
  )
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByTestId('hilos-page-head-title')).toHaveText(head.label)
  await expect(page.getByTestId('oauth-provider-head-key')).toHaveText(
    head.providerKey,
  )
  await expect(page.getByTestId('oauth-provider-head-state')).toHaveText(
    head.configured ? 'Configured' : `${head.missingFields} missing`,
  )
  await expect(page.getByTestId('hilos-page-tabs')).toHaveCount(0)
  await expect(page.getByTestId('hilos-breadcrumb').locator('li')).toHaveText([
    'Hilos',
    'Security Center',
    'OAuth providers',
    head.label,
  ])
  await expect(
    page.getByTestId('hilos-breadcrumb-hilos_security_oauth'),
  ).toHaveAttribute('href', `${SECURITY_PATH}/oauth`)
  await expect(page.getByTestId('hilos-oauth-provider-not-found')).toHaveCount(
    0,
  )
}

/** Toggle a real operation and settle its correlated acknowledgement and row echo. */
export async function toggleSecurityOperation(
  expect: Expect,
  page: Page,
  log: DeliveryFrameLog,
  operationKey: string,
  enabled: boolean,
): Promise<void> {
  await dismissToasts(page)
  const before = log.sent.length
  const control = shownByTestId(page, `hilos-step-up-switch-${operationKey}`)
  await expect(control).toBeChecked({ checked: !enabled })
  await clickSubmit(expect, control)
  await expect(control).toBeEnabled()
  const sentRequest = () =>
    log.sent
      .slice(before)
      .find(
        ({ wire }) => object(wire).action === 'security_step_up_operation_set',
      )
  await expect.poll(sentRequest).toBeDefined()
  const request = sentRequest()!
  expect(object(request!.wire).data).toEqual({ operationKey, enabled })
  const requestId = object(request!.wire).requestId
  expect(requestId).toEqual(expect.any(String))
  await expect
    .poll(() =>
      log.packets
        .flatMap(({ frames }) => frames)
        .some(({ wire }) => {
          const ack = object(wire)
          return (
            ack.requestId === requestId &&
            ack.type === 'action_success' &&
            ack.outcome === 'success'
          )
        }),
    )
    .toBe(true)
  await expect(control).toBeChecked({ checked: enabled })
}
