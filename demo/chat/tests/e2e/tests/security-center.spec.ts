import { expect, test } from '@playwright/test'

import {
  SECURITY_PATH,
  SECURITY_PROVIDER_PAGE,
  SECURITY_SECTIONS,
  clearCustomSetting,
  createTestAdmin,
  deliveryObject,
  expectPageReady,
  expectSecurityFirstReply,
  expectSecurityIdentity,
  expectSecurityOverview,
  expectSecurityOverviewData,
  expectSecurityProviderFirstHead,
  expectSecurityProviderHead,
  expectSecuritySectionHead,
  gotoPage,
  securityPageReplies,
  shownByTestId,
  toggleSecurityOperation,
  typeInto,
  watchDeliveryFrames,
} from '../../../../../framework/frontend/e2e/index.js'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

// Real Vue Security pages through the built Chat artifact and the live daemon.
// Each first response belongs to the subscription bookmarked before navigation;
// raw physical packets and all their batch inners are retained in attachments.

test.afterEach(() => setAdminViewMode(false))

test('dashboard opens Security with its complete first answer and all six section links', async ({
  page,
}) => {
  const log = watchDeliveryFrames(page)
  try {
    await createTestAdmin(expect, page)
    await gotoPage(expect, page, '/hilos')
    const opening = log.sent.length
    const card = page.getByTestId('dashboard-card-hilos_security')
    await expect(card).toHaveAttribute('href', SECURITY_PATH)
    await card.click()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(SECURITY_PATH)
    const first = await expectSecurityFirstReply(
      expect,
      log,
      opening,
      'hilos_security',
    )
    expectSecurityIdentity(expect, first)
    const overview = expectSecurityOverviewData(expect, first)
    expect(first.payload).not.toHaveProperty('windows')
    expect(first.payload).not.toHaveProperty('tables')
    await expectSecurityOverview(expect, page, overview)
    const requests = log.sent
      .slice(opening)
      .map(({ wire }) => deliveryObject(wire))
    expect(
      requests
        .filter((wire) => wire.type === 'page_subscribe')
        .map((wire) => wire.page),
    ).toEqual(['hilos_security'])
    expect(
      requests.filter(
        (wire) =>
          wire.type === 'action' || String(wire.type).startsWith('table_'),
      ),
    ).toEqual([])

    for (const section of SECURITY_SECTIONS.slice(1)) {
      await page.getByTestId(`hilos-page-tab-${section.key}`).click()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(section.path)
      await expectSecuritySectionHead(expect, page, section.key)
    }
    await page.getByTestId('hilos-page-tab-hilos_security').click()
    await expectPageReady(expect, page)
    await expectSecurityOverview(expect, page, overview)
  } finally {
    await test.info().attach('security-section-wire', {
      body: JSON.stringify({ sent: log.sent, packets: log.packets }),
      contentType: 'application/json',
    })
    log.stop()
  }
})

for (const section of [SECURITY_SECTIONS[0], SECURITY_SECTIONS[2]]) {
  test(`${section.label} supports direct entry, F5 and browser Back/Forward`, async ({
    page,
  }) => {
    const log = watchDeliveryFrames(page)
    try {
      await createTestAdmin(expect, page)
      let opening = log.sent.length
      await gotoPage(expect, page, section.path)
      let first = await expectSecurityFirstReply(
        expect,
        log,
        opening,
        section.key,
      )
      expectSecurityIdentity(expect, first)
      await expectSecuritySectionHead(expect, page, section.key)
      if (section.key === 'hilos_security')
        await expectSecurityOverview(
          expect,
          page,
          expectSecurityOverviewData(expect, first),
        )
      opening = log.sent.length
      await page.reload()
      await expectPageReady(expect, page)
      first = await expectSecurityFirstReply(expect, log, opening, section.key)
      expectSecurityIdentity(expect, first)
      await expectSecuritySectionHead(expect, page, section.key)
      if (section.key === 'hilos_security')
        await expectSecurityOverview(
          expect,
          page,
          expectSecurityOverviewData(expect, first),
        )

      const other =
        section.key === 'hilos_security'
          ? SECURITY_SECTIONS[2]
          : SECURITY_SECTIONS[0]
      await page.getByTestId(`hilos-page-tab-${other.key}`).click()
      await expectPageReady(expect, page)
      await expectSecuritySectionHead(expect, page, other.key)
      opening = log.sent.length
      await page.goBack()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(section.path)
      await expectSecurityFirstReply(expect, log, opening, section.key)
      await expectSecuritySectionHead(expect, page, section.key)
      opening = log.sent.length
      await page.goForward()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(other.path)
      await expectSecurityFirstReply(expect, log, opening, other.key)
      await expectSecuritySectionHead(expect, page, other.key)
    } finally {
      await test.info().attach('security-history-wire', {
        body: JSON.stringify({ sent: log.sent, packets: log.packets }),
        contentType: 'application/json',
      })
      log.stop()
    }
  })
}

test('provider row opens a complete first head before filtered asks and keeps route identity through unknown/Back/F5', async ({
  page,
}) => {
  const log = watchDeliveryFrames(page)
  try {
    await createTestAdmin(expect, page)
    await gotoPage(expect, page, `${SECURITY_PATH}/oauth`)
    for (const providerKey of ['oauth:github', 'oauth:google']) {
      const link = shownByTestId(
        page,
        `hilos-oauth-provider-open-${providerKey}`,
      )
      const path = await link.getAttribute('href')
      expect(path).toBeTruthy()
      const opening = log.sent.length
      await link.click()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(path!)
      const first = await expectSecurityFirstReply(
        expect,
        log,
        opening,
        SECURITY_PROVIDER_PAGE,
        { providerId: providerKey },
      )
      const head = await expectSecurityProviderFirstHead(
        expect,
        log,
        first,
        providerKey,
      )
      await expectSecurityProviderHead(expect, page, head)
      await expect(page).toHaveTitle(`${head.label} · Hilos Chat`)
      expect(
        log.sent
          .slice(opening)
          .filter(({ wire }) => deliveryObject(wire).type === 'action'),
      ).toEqual([])
      await page.getByTestId('hilos-breadcrumb-hilos_security_oauth').click()
      await expectPageReady(expect, page)
      await expect(page).toHaveURL(`${SECURITY_PATH}/oauth`)
      await expectSecuritySectionHead(expect, page, 'hilos_security_oauth')
    }

    await shownByTestId(page, 'hilos-oauth-provider-open-oauth:google').click()
    await expectPageReady(expect, page)
    const providerPath = new URL(page.url()).pathname
    let opening = log.sent.length
    await gotoPage(expect, page, `${SECURITY_PATH}/oauth/oauth:unknown`)
    const unknown = await expectSecurityFirstReply(
      expect,
      log,
      opening,
      SECURITY_PROVIDER_PAGE,
      { providerId: 'oauth:unknown' },
    )
    expect(unknown.data.oauthProviderHead).toBeNull()
    await expect(
      page.getByTestId('hilos-oauth-provider-not-found'),
    ).toContainText('oauth:unknown')
    await expect(page.getByTestId('oauth-provider-head-key')).toHaveCount(0)
    await expect(page.getByTestId('oauth-provider-head-state')).toHaveCount(0)
    await expect(page.getByTestId('hilos-page-head-title')).toHaveText(
      'OAuth provider',
    )
    opening = log.sent.length
    await page.goBack()
    await expectPageReady(expect, page)
    await expect(page).toHaveURL(providerPath)
    let first = await expectSecurityFirstReply(
      expect,
      log,
      opening,
      SECURITY_PROVIDER_PAGE,
      { providerId: 'oauth:google' },
    )
    let head = await expectSecurityProviderFirstHead(
      expect,
      log,
      first,
      'oauth:google',
    )
    await expectSecurityProviderHead(expect, page, head)
    opening = log.sent.length
    await page.reload()
    await expectPageReady(expect, page)
    first = await expectSecurityFirstReply(
      expect,
      log,
      opening,
      SECURITY_PROVIDER_PAGE,
      { providerId: 'oauth:google' },
    )
    head = await expectSecurityProviderFirstHead(
      expect,
      log,
      first,
      'oauth:google',
    )
    await expectSecurityProviderHead(expect, page, head)
  } finally {
    await test.info().attach('security-provider-wire', {
      body: JSON.stringify({ sent: log.sent, packets: log.packets }),
      contentType: 'application/json',
    })
    log.stop()
  }
})

for (const viewer of [false, true]) {
  test(`committed operation changes reach an open ${viewer ? 'guest Overview with catalog-visible settings' : 'administrator Overview'} without resubscribing`, async ({
    page,
    browser,
  }) => {
    const writer = watchDeliveryFrames(page)
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use
    const context = viewer
      ? await browser.newContext({
          baseURL,
          ignoreHTTPSErrors,
          colorScheme: 'light',
        })
      : page.context()
    const readerPage = await context.newPage()
    const reader = watchDeliveryFrames(readerPage)
    let changed = false
    let operationKey = ''
    let enabled = false
    try {
      await createTestAdmin(expect, page)
      // Prove the original source before changing it; cleanup removes only the
      // overrides created by this test, through the ordinary settings forms.
      await gotoPage(expect, page, '/hilos/settings')
      await typeInto(page.getByTestId('hilos-table-search'), 'auth.step_up.')
      for (const key of ['auth.step_up.disabled', 'auth.step_up.enabled'])
        await expect(
          shownByTestId(page, `hilos-settings-edit-${key}`),
        ).toHaveAttribute('aria-label', 'Set custom value')
      if (viewer) await setAdminViewMode(true)
      const opening = reader.sent.length
      await gotoPage(expect, readerPage, SECURITY_PATH)
      const first = await expectSecurityFirstReply(
        expect,
        reader,
        opening,
        'hilos_security',
      )
      const original = expectSecurityOverviewData(expect, first)
      await expectSecurityOverview(expect, readerPage, original)
      if (viewer) {
        const handshake = reader.packets
          .filter(({ socket }) => socket === first.request.socket)
          .flatMap(({ frames }) => frames)
          .find(
            ({ wire }) => deliveryObject(wire).type === 'handshake_response',
          )!
        expect(handshake.failure).toBeUndefined()
        expect(deliveryObject(handshake.wire).data).toMatchObject({
          entities: { currentUser: null },
          data: { adminViewMode: true },
        })
        await expect(readerPage.getByTestId('nav-admin')).toHaveAttribute(
          'data-access',
          'view',
        )
        await expect(readerPage.getByTestId('view-mode-banner')).toBeVisible()
      }

      const operationsOpening = writer.sent.length
      await gotoPage(expect, page, `${SECURITY_PATH}/step-up`)
      const operationsReply = await expectSecurityFirstReply(
        expect,
        writer,
        operationsOpening,
        'hilos_security_step_up',
      )
      const window = deliveryObject(
        deliveryObject(operationsReply.payload.windows).hilosSecurityStepUp,
      )
      const rows = window.rows as {
        slots: { operation: { operationKey: string; enabled: boolean } }
      }[]
      expect(window.totalCount).toBe(original.stepUp.totalCount)
      expect(rows).toHaveLength(original.stepUp.totalCount)
      const operation = rows[0]!.slots.operation
      operationKey = operation.operationKey
      enabled = operation.enabled
      expect(typeof enabled).toBe('boolean')
      const before = rows.filter(({ slots }) => slots.operation.enabled).length
      // The clean fixture opens all seven setting-derived fields for both
      // identities. The real operation rows independently establish its tally.
      expect(original).toEqual({
        twoFactor: { enabled: true, required: 'none' },
        stepUp: { enabledCount: before, totalCount: 15 },
        oauth: { configuredCount: 2, totalCount: 2, unconfigured: [] },
        signInMethods: { enabledCount: 6, totalCount: 6, unready: [] },
        impersonation: { allowed: true, scope: 'act' },
      })
      const repliesBefore = securityPageReplies(
        reader,
        opening,
        'hilos_security',
      ).length
      changed = true
      await toggleSecurityOperation(
        expect,
        page,
        writer,
        operationKey,
        !enabled,
      )
      const after = before + (enabled ? -1 : 1)
      await expect
        .poll(
          () => securityPageReplies(reader, opening, 'hilos_security').length,
        )
        .toBeGreaterThan(repliesBefore)
      const live = securityPageReplies(reader, opening, 'hilos_security').at(
        -1,
      )!
      expect(live.packet.at).toBeGreaterThan(first.packet.at)
      expect(live.request).toBe(first.request)
      expect(live.packet.socket).toBe(first.packet.socket)
      const updated = expectSecurityOverviewData(expect, live)
      expect(updated).toEqual({
        ...original,
        stepUp: {
          ...original.stepUp,
          enabledCount: after,
        },
      })
      await expectSecurityOverview(expect, readerPage, updated)
      expect(
        reader.sent
          .slice(opening)
          .filter(({ wire }) => deliveryObject(wire).type === 'page_subscribe'),
      ).toHaveLength(1)
      expect(
        reader.sent.slice(opening).filter(({ wire }) => {
          const type = String(deliveryObject(wire).type)
          return type === 'action' || type.startsWith('table_')
        }),
      ).toEqual([])
      expect(reader.connections).toHaveLength(1)
      expect(reader.connections[0]!.closed).toBeUndefined()
      // Public policy values grant no writing controls to the guest Overview.
      await expect(
        readerPage.getByTestId('security-overview').getByRole('button'),
      ).toHaveCount(0)
      await expect(
        readerPage.getByTestId('security-overview').getByRole('checkbox'),
      ).toHaveCount(0)
    } finally {
      try {
        if (changed) {
          const control = shownByTestId(
            page,
            `hilos-step-up-switch-${operationKey}`,
          )
          await expect(control).toBeEnabled()
          if ((await control.isChecked()) !== enabled)
            await toggleSecurityOperation(
              expect,
              page,
              writer,
              operationKey,
              enabled,
            )
          await gotoPage(expect, page, '/hilos/settings')
          await typeInto(
            page.getByTestId('hilos-table-search'),
            'auth.step_up.',
          )
          for (const key of ['auth.step_up.disabled', 'auth.step_up.enabled']) {
            await clearCustomSetting(page, key)
            await expect(
              shownByTestId(page, `hilos-settings-edit-${key}`),
            ).toHaveAttribute('aria-label', 'Set custom value')
          }
        }
      } finally {
        await setAdminViewMode(false)
        await test.info().attach('security-live-wire', {
          body: JSON.stringify({
            writer: { sent: writer.sent, packets: writer.packets },
            reader: { sent: reader.sent, packets: reader.packets },
          }),
          contentType: 'application/json',
        })
        writer.stop()
        reader.stop()
        if (viewer) await context.close()
        else await readerPage.close()
      }
    }
  })
}
