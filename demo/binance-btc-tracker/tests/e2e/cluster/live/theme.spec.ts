import {
  expect,
  test,
  type Browser,
  type BrowserContext,
  type Page,
} from '@playwright/test'

import {
  APPEARANCE_PATH,
  THEME_DEFAULT_KEY,
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
  THEME_SWITCHING_KEY,
  armSocketDrop,
  chooseHeaderTheme,
  clearCustomSetting,
  countSentFrames,
  dropSocket,
  emulateSystemTheme,
  expectAppearanceRows,
  expectThemeHeader,
  expectThemeMode,
  openAppearanceSetting,
  readClusterBrowserStand,
  saveAppearanceDefaultTheme,
  saveAppearanceSwitching,
  watchClusterMasterUpgrades,
  watchThemeFrames,
  type ThemeFrameLog,
  type ThemeTestPick,
  type ThemeTestPosition,
} from '../../../../../../framework/frontend/e2e/index.js'
import {
  grantAdminToSelf,
  sessionToken,
  setAdmin,
} from '../../helpers/adminGrant.js'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_LEADER,
  CLUSTER_MASTERS,
  clusterInspect,
  expectLeaderUnmoved,
  newMasterPage,
} from '../../helpers/cluster.js'
import { gotoPage, PAGE_READY } from '../../helpers/page.js'
import { login, openSignIn, signUpPerson } from '../../helpers/session.js'

interface ThemeSettings {
  switchingEnabled: boolean
  defaultTheme: ThemeTestPosition
}

type UpgradeWatcher = Awaited<ReturnType<typeof watchClusterMasterUpgrades>>

interface ThemeBrowser {
  page: Page
  node: string
  system: 'light' | 'dark'
  pick: ThemeTestPick
  userId: number | null
  initialized?: boolean
  frames: ThemeFrameLog[]
  upgrades: UpgradeWatcher
  counts: { loads: number; sockets: number }
}

/** A malformed payload must fail here rather than disappear in a frame filter. */
function object(value: unknown): Record<string, unknown> {
  expect(value).not.toBeNull()
  expect(typeof value).toBe('object')
  expect(Array.isArray(value)).toBe(false)
  return value as Record<string, unknown>
}

function frameSettings(frame: ThemeFrameLog): unknown {
  const data = object(frame.data)
  return frame.type === 'hilos_theme_settings'
    ? data
    : object(data.data).themeSettings
}

function expectIdentity(frame: ThemeFrameLog, client: ThemeBrowser): void {
  const user = object(object(frame.data).entities).currentUser
  if (client.userId === null) {
    expect(user, `${client.node}: guest handshake`).toBeNull()
  } else {
    expect(object(user)).toMatchObject({
      id: client.userId,
      themePick: client.pick,
      ...(client.initialized === undefined
        ? client.pick === null
          ? {}
          : { themePickInitialized: true }
        : { themePickInitialized: client.initialized }),
    })
  }
}

async function expectBrowserTheme(
  client: ThemeBrowser,
  settings: ThemeSettings,
): Promise<void> {
  const position = settings.switchingEnabled
    ? (client.pick ?? settings.defaultTheme)
    : settings.defaultTheme
  await expectThemeMode(
    expect,
    client.page,
    position === 'system' ? client.system : position,
  )
  if (settings.switchingEnabled) {
    await expectThemeHeader(expect, client.page, position, client.pick === null)
  } else {
    await expect(client.page.getByTestId('nav-theme')).toHaveCount(0)
  }
  await expect
    .poll(() =>
      client.page.evaluate(
        (key) => JSON.parse(localStorage.getItem(key) ?? 'null') as unknown,
        THEME_SETTINGS_STORAGE_KEY,
      ),
    )
    .toEqual(settings)
  expect(
    await client.page.evaluate(
      (key) => localStorage.getItem(key),
      THEME_PICK_STORAGE_KEY,
    ),
  ).toBe(client.pick)
  await client.upgrades.assert()
}

function expectInterval(
  recipients: readonly ThemeBrowser[],
  starts: readonly number[],
  settings: ThemeSettings,
): void {
  for (const [index, client] of recipients.entries()) {
    for (const frame of client.frames.slice(starts[index])) {
      expect(frameSettings(frame), frame.raw).toEqual(settings)
      if (frame.type === 'handshake_response') expectIdentity(frame, client)
    }
  }
}

/** Wait for delivery, then inspect every relevant frame since the action boundary. */
async function expectDelivery(
  recipients: readonly ThemeBrowser[],
  starts: readonly number[],
  settings: ThemeSettings,
): Promise<void> {
  await Promise.all(
    recipients.map(async (client, index) => {
      await expect
        .poll(
          () =>
            client.frames
              .slice(starts[index])
              .filter((frame) => frame.type === 'hilos_theme_settings').length,
          { message: `Fresh settings frame on ${client.node}` },
        )
        .toBeGreaterThan(0)
    }),
  )
  for (const client of recipients) await expectBrowserTheme(client, settings)
  expectInterval(recipients, starts, settings)
}

interface ThemeProof {
  third: string
  clients: ThemeBrowser[]
  admin?: ThemeBrowser
  fresh(node: string, system: 'light' | 'dark'): Promise<ThemeBrowser>
  tab(account: ThemeBrowser): Promise<ThemeBrowser>
  close(client: ThemeBrowser, wholeContext?: boolean): Promise<void>
}

/** Keep every observer ahead of navigation and retain the original failure on cleanup. */
async function withThemeProof(
  browser: Browser,
  scenario: (proof: ThemeProof) => Promise<void>,
): Promise<void> {
  const stand = readClusterBrowserStand()
  expect(CLUSTER_MASTERS).toHaveLength(3)
  expect(new Set(CLUSTER_MASTERS).size).toBe(3)
  const third = CLUSTER_MASTERS.find(
    (node) => node !== CLUSTER_FOLLOWER && node !== CLUSTER_LEADER,
  )
  if (!third) throw new Error('The theme proof needs a third master')
  const before = await clusterInspect(CLUSTER_FOLLOWER)
  await expectLeaderUnmoved(before)
  const contexts = new Set<BrowserContext>()
  const watchers = new Set<UpgradeWatcher>()
  const clients: ThemeBrowser[] = []
  let failed = false
  let failure: unknown
  const cleanupErrors: unknown[] = []

  async function observe(
    page: Page,
    node: string,
    system: 'light' | 'dark',
  ): Promise<ThemeBrowser> {
    contexts.add(page.context())
    const frames = watchThemeFrames(page)
    const counts = { loads: 0, sockets: 0 }
    page.on('load', () => {
      counts.loads += 1
    })
    page.on('websocket', () => {
      counts.sockets += 1
    })
    const upgrades = await watchClusterMasterUpgrades(expect, page, stand, node)
    watchers.add(upgrades)
    await emulateSystemTheme(page, system)
    return {
      page,
      node,
      system,
      pick: null,
      userId: null,
      frames,
      upgrades,
      counts,
    }
  }

  async function fresh(
    node: string,
    system: 'light' | 'dark',
  ): Promise<ThemeBrowser> {
    return observe(await newMasterPage(browser, node), node, system)
  }

  const proof: ThemeProof = {
    third,
    clients,
    fresh,
    async tab(account) {
      const tab = await observe(
        await account.page.context().newPage(),
        account.node,
        account.system,
      )
      tab.userId = account.userId
      tab.pick = account.pick
      tab.initialized = account.initialized
      return tab
    },
    async close(client, wholeContext = false) {
      await client.upgrades.assert()
      await client.upgrades.stop()
      watchers.delete(client.upgrades)
      if (wholeContext) {
        await client.page.context().close()
        contexts.delete(client.page.context())
      } else {
        await client.page.close()
      }
    },
  }
  try {
    await scenario(proof)
  } catch (error) {
    failed = true
    failure = error
  } finally {
    // Restore network even when a stale-session assertion failed while offline.
    const onlineResults = await Promise.allSettled(
      [...contexts].map((context) => context.setOffline(false)),
    )
    for (const result of onlineResults) {
      if (result.status === 'rejected') cleanupErrors.push(result.reason)
    }
    const admin = proof.admin
    if (admin) {
      for (const key of [THEME_DEFAULT_KEY, THEME_SWITCHING_KEY]) {
        try {
          await gotoPage(admin.page, '/hilos/settings', PAGE_READY)
          const search = admin.page.getByTestId('hilos-table-search')
          await search.fill('')
          await search.pressSequentially(key, { delay: 10 })
          await expect(
            admin.page.getByTestId(`hilos-table-row-${key}`),
          ).toBeVisible()
          const cached = object(
            await admin.page.evaluate(
              (storageKey) =>
                JSON.parse(
                  localStorage.getItem(storageKey) ?? 'null',
                ) as unknown,
              THEME_SETTINGS_STORAGE_KEY,
            ),
          )
          const settings: ThemeSettings = {
            switchingEnabled:
              key === THEME_SWITCHING_KEY
                ? true
                : (cached.switchingEnabled as boolean),
            defaultTheme: 'system',
          }
          const recipients = clients.filter((client) => client !== admin)
          const starts = recipients.map((client) => client.frames.length)
          await clearCustomSetting(admin.page, key)
          if (
            cached.switchingEnabled !== settings.switchingEnabled ||
            cached.defaultTheme !== settings.defaultTheme
          ) {
            await expectDelivery(recipients, starts, settings)
          }
        } catch (error) {
          cleanupErrors.push(error)
        }
      }
      try {
        for (const client of clients) {
          await expectBrowserTheme(client, {
            switchingEnabled: true,
            defaultTheme: 'system',
          })
        }
      } catch (error) {
        cleanupErrors.push(error)
      }
    }
    const results = await Promise.allSettled([
      ...[...watchers].map((watcher) =>
        watcher.assert().finally(() => watcher.stop()),
      ),
      expectLeaderUnmoved(before),
    ])
    results.push(
      ...(await Promise.allSettled(
        [...contexts].map((context) => context.close()),
      )),
    )
    for (const result of results) {
      if (result.status === 'rejected') cleanupErrors.push(result.reason)
    }
  }
  if (failed) {
    if (cleanupErrors.length > 0)
      console.error('Theme proof cleanup also failed:', cleanupErrors)
    throw failure
  }
  if (cleanupErrors.length > 0)
    throw new AggregateError(cleanupErrors, 'Theme proof cleanup failed')
}

test('cluster theme settings reach every master without reloading', async ({
  browser,
}) => {
  // Two registrations and password logins, six live recipients and five saves.
  test.slow()
  await withThemeProof(browser, async (proof) => {
    const { fresh, clients, third } = proof
    const admin = await fresh(CLUSTER_FOLLOWER, 'light')
    admin.userId = await grantAdminToSelf(admin.page)
    await gotoPage(admin.page, APPEARANCE_PATH, PAGE_READY)
    await expectAppearanceRows(expect, admin.page)
    proof.admin = admin
    clients.push(admin)

    for (const [node, system] of [
      [CLUSTER_FOLLOWER, 'light'],
      [CLUSTER_LEADER, 'light'],
      [third, 'dark'],
    ] as const) {
      const guest = await fresh(node, system)
      clients.push(guest)
      await gotoPage(guest.page, '/', PAGE_READY)
      if (node === CLUSTER_FOLLOWER) {
        await chooseHeaderTheme(expect, guest.page, 'dark')
        guest.pick = 'dark'
      }
    }

    for (const [node, pick, system] of [
      [CLUSTER_LEADER, 'light', 'dark'],
      [third, null, 'light'],
    ] as const) {
      // Registration is preparation on a disposable browser. Each recipient then
      // signs in by password with its own cookie and StorageArea, never a copy.
      const seed = await fresh(CLUSTER_FOLLOWER, 'light')
      const person = await signUpPerson(seed.page)
      await proof.close(seed, true)
      const account = await fresh(node, system)
      clients.push(account)
      await gotoPage(account.page, '/', PAGE_READY)
      await openSignIn(account.page)
      await login(account.page, person.email)
      await expect(account.page.getByTestId('self-user')).toHaveText(
        person.name,
      )
      account.userId = person.userId
      if (pick !== null) await chooseHeaderTheme(expect, account.page, pick)
      account.pick = pick
    }

    const tokens = await Promise.all(
      clients.map((client) => sessionToken(client.page)),
    )
    expect(new Set(tokens).size).toBe(clients.length)
    for (const client of clients) {
      const handshakes = client.frames.filter(
        (frame) => frame.type === 'handshake_response',
      )
      expect(handshakes.length).toBeGreaterThan(0)
      expectIdentity(handshakes[handshakes.length - 1], client)
      for (const frame of client.frames) {
        expect(frameSettings(frame), frame.raw).toEqual({
          switchingEnabled: true,
          defaultTheme: 'system',
        })
      }
      await expectBrowserTheme(client, {
        switchingEnabled: true,
        defaultTheme: 'system',
      })
    }
    // Auth and navigation are finished. Only the intentionally new tabs below
    // may create documents or connections during settings delivery.
    const baseline = clients.map((client) => ({ ...client.counts }))
    let previousStarts = clients.map((client) => client.frames.length)
    let previousSettings: ThemeSettings = {
      switchingEnabled: true,
      defaultTheme: 'system',
    }

    for (const [key, settings] of [
      [THEME_DEFAULT_KEY, { switchingEnabled: true, defaultTheme: 'dark' }],
      [THEME_SWITCHING_KEY, { switchingEnabled: false, defaultTheme: 'dark' }],
      [THEME_DEFAULT_KEY, { switchingEnabled: false, defaultTheme: 'light' }],
      [THEME_SWITCHING_KEY, { switchingEnabled: true, defaultTheme: 'light' }],
      [THEME_DEFAULT_KEY, { switchingEnabled: true, defaultTheme: 'system' }],
    ] as const) {
      expectInterval(clients, previousStarts, previousSettings)
      const starts = clients.map((client) => client.frames.length)
      if (key === THEME_SWITCHING_KEY) {
        await openAppearanceSetting(expect, admin.page, THEME_SWITCHING_KEY)
        await admin.page
          .getByTestId('appearance-switching-input')
          .setChecked(settings.switchingEnabled)
        await saveAppearanceSwitching(
          expect,
          admin.page,
          settings.switchingEnabled,
        )
      } else {
        await openAppearanceSetting(expect, admin.page, THEME_DEFAULT_KEY)
        await admin.page
          .getByTestId(`appearance-default-theme-${settings.defaultTheme}`)
          .check()
        await saveAppearanceDefaultTheme(
          expect,
          admin.page,
          settings.defaultTheme,
        )
      }
      await expectAppearanceRows(
        expect,
        admin.page,
        settings.switchingEnabled,
        settings.defaultTheme,
      )
      await expectDelivery(clients, starts, settings)
      previousStarts = starts
      previousSettings = settings
      for (const [index, client] of clients.entries()) {
        expect(
          client.counts,
          `No reload or reconnect on ${client.node}`,
        ).toEqual(baseline[index])
      }
      if (!settings.switchingEnabled) {
        // These contexts have never visited the site. Each new tab must receive
        // the pair in its first handshake, with no cached settings as evidence.
        for (const node of CLUSTER_MASTERS) {
          const tab = await fresh(node, 'dark')
          await gotoPage(tab.page, '/', PAGE_READY)
          await expect.poll(() => tab.frames.length).toBeGreaterThan(0)
          expect(tab.frames[0].type).toBe('handshake_response')
          expectIdentity(tab.frames[0], tab)
          for (const frame of tab.frames)
            expect(frameSettings(frame), frame.raw).toEqual(settings)
          await expectBrowserTheme(tab, settings)
          expect(tab.counts).toEqual({ loads: 1, sockets: 1 })
          // A failed check leaves this context to the outer cleanup, which
          // retains that failure even when stopping a watcher also fails.
          await proof.close(tab, true)
        }
      }
    }
    expectInterval(clients, previousStarts, previousSettings)
    for (const [index, client] of clients.entries()) {
      expect(client.counts, `No reload or reconnect on ${client.node}`).toEqual(
        baseline[index],
      )
    }
  })
})

/** A handshake must arrive after this boundary, regardless of whether it is valid. */
async function expectAccountDelivery(
  accounts: readonly ThemeBrowser[],
  starts: readonly number[],
  settings: ThemeSettings,
): Promise<void> {
  for (const [index, account] of accounts.entries()) {
    await expect
      .poll(
        () =>
          account.frames
            .slice(starts[index])
            .filter((frame) => frame.type === 'handshake_response').length,
        { message: `Fresh account handshake on ${account.node}` },
      )
      .toBeGreaterThan(0)
    await expectBrowserTheme(account, settings)
  }
  expectInterval(accounts, starts, settings)
}

/** A new socket in each original context must start with the current account state. */
async function expectAccountTabs(
  proof: ThemeProof,
  accounts: readonly ThemeBrowser[],
  settings: ThemeSettings,
): Promise<void> {
  for (const account of accounts) {
    const tab = await proof.tab(account)
    await gotoPage(tab.page, '/', PAGE_READY)
    await expect.poll(() => tab.frames.length).toBeGreaterThan(0)
    expect(tab.frames[0].type).toBe('handshake_response')
    expectIdentity(tab.frames[0], tab)
    expectInterval([tab], [0], settings)
    await expectBrowserTheme(tab, settings)
    expect(tab.counts).toEqual({ loads: 1, sockets: 1 })
    await proof.close(tab)
  }
}

test('cluster theme account choices and reset reach independent sessions on every master', async ({
  browser,
}) => {
  // Two registrations, two independent logins, five broadcasts, six new tabs
  // and an Appearance save exceed the single-client cap on an idle stand.
  test.slow()
  await withThemeProof(browser, async (proof) => {
    const { fresh, clients, third } = proof
    const settings: ThemeSettings = {
      switchingEnabled: true,
      defaultTheme: 'system',
    }
    const first = await fresh(CLUSTER_FOLLOWER, 'light')
    clients.push(first)
    const person = await signUpPerson(first.page)
    first.userId = person.userId
    first.initialized = false
    const accounts = [first]
    for (const [node, system] of [
      [CLUSTER_LEADER, 'dark'],
      [third, 'light'],
    ] as const) {
      const account = await fresh(node, system)
      clients.push(account)
      accounts.push(account)
      await gotoPage(account.page, '/', PAGE_READY)
      await openSignIn(account.page)
      await login(account.page, person.email)
      await expect(account.page.getByTestId('self-user')).toHaveText(
        person.name,
      )
      account.userId = person.userId
      account.initialized = false
    }
    const stranger = await fresh(third, 'dark')
    clients.push(stranger)
    stranger.userId = (await signUpPerson(stranger.page)).userId
    expect(stranger.userId).not.toBe(person.userId)
    await chooseHeaderTheme(expect, stranger.page, 'light')
    stranger.pick = 'light'
    stranger.initialized = true
    await setAdmin(stranger.userId, true)
    await expect(stranger.page.getByTestId('nav-admin')).toHaveAttribute(
      'data-access',
      'full',
    )
    await gotoPage(stranger.page, APPEARANCE_PATH, PAGE_READY)
    await expectAppearanceRows(expect, stranger.page)
    proof.admin = stranger

    const guest = await fresh(CLUSTER_LEADER, 'dark')
    clients.push(guest)
    await gotoPage(guest.page, '/', PAGE_READY)
    const tokens = await Promise.all(
      clients.map((client) => sessionToken(client.page)),
    )
    expect(new Set(tokens).size).toBe(clients.length)
    for (const client of clients) {
      const handshakes = client.frames.filter(
        (frame) => frame.type === 'handshake_response',
      )
      expect(handshakes.length).toBeGreaterThan(0)
      expectIdentity(handshakes[handshakes.length - 1], client)
      for (const frame of client.frames)
        expect(frameSettings(frame), frame.raw).toEqual(settings)
      await expectBrowserTheme(client, settings)
    }
    const baseline = clients.map((client) => ({ ...client.counts }))
    let previousStarts = clients.map((client) => client.frames.length)
    let darkTabsChecked = false

    // The other person's explicit Light is a real server round trip, also when
    // Light is already personal. Inspect all frames through that boundary.
    async function isolationRoundTrip(
      starts: readonly number[],
    ): Promise<void> {
      const strangerStart = stranger.frames.length
      await chooseHeaderTheme(expect, stranger.page, 'light')
      await expectAccountDelivery([stranger], [strangerStart], settings)
      for (const client of clients) await expectBrowserTheme(client, settings)
      expectInterval(clients, starts, settings)
      for (const [index, client] of clients.entries()) {
        expect(
          client.counts,
          `No reload or reconnect on ${client.node}`,
        ).toEqual(baseline[index])
      }
    }

    for (const [source, pick] of [
      [first, 'dark'],
      [accounts[2], 'light'],
      [accounts[1], 'system'],
      [first, 'dark'],
      [accounts[1], null],
    ] as const) {
      expectInterval(clients, previousStarts, settings)
      const starts = clients.map((client) => client.frames.length)
      for (const account of accounts) {
        account.pick = pick
        account.initialized = true
      }
      await chooseHeaderTheme(expect, source.page, pick)
      await expectAccountDelivery(
        accounts,
        starts.slice(0, accounts.length),
        settings,
      )
      await isolationRoundTrip(starts)
      previousStarts = starts
      if (pick === 'dark' && !darkTabsChecked) {
        await expectAccountTabs(proof, accounts, settings)
        expectInterval(clients, starts, settings)
        darkTabsChecked = true
      }
    }

    // The reset is authoritative null/true on all three independent sessions.
    // Its default badge must now follow the administrator's live setting.
    expectInterval(clients, previousStarts, settings)
    const starts = clients.map((client) => client.frames.length)
    await openAppearanceSetting(expect, stranger.page, THEME_DEFAULT_KEY)
    await stranger.page.getByTestId('appearance-default-theme-dark').check()
    await saveAppearanceDefaultTheme(expect, stranger.page, 'dark')
    settings.defaultTheme = 'dark'
    await expectAppearanceRows(expect, stranger.page, true, 'dark')
    await expectDelivery(clients, starts, settings)
    await isolationRoundTrip(starts)
    await expectAccountTabs(proof, accounts, settings)
    expectInterval(clients, starts, settings)
    for (const [index, client] of clients.entries()) {
      expect(client.counts, `No reload or reconnect on ${client.node}`).toEqual(
        baseline[index],
      )
    }
  })
})

test('cluster theme stale Dark session reconnects after remote reset without adoption', async ({
  browser,
}) => {
  // A registration, an independent login and two real reconnects include the
  // client's reconnect backoff and need more than the single-client cap.
  test.slow()
  await withThemeProof(browser, async (proof) => {
    const settings: ThemeSettings = {
      switchingEnabled: true,
      defaultTheme: 'system',
    }
    const first = await proof.fresh(CLUSTER_FOLLOWER, 'light')
    proof.clients.push(first)
    const person = await signUpPerson(first.page)
    first.userId = person.userId
    first.pick = 'dark'
    first.initialized = true
    const firstChoice = first.frames.length
    await chooseHeaderTheme(expect, first.page, 'dark')
    await expectAccountDelivery([first], [firstChoice], settings)

    const stale = await proof.fresh(proof.third, 'light')
    proof.clients.push(stale)
    await armSocketDrop(stale.page)
    const adoptions = countSentFrames(stale.page, 'hilos_theme_adopt')
    await gotoPage(stale.page, '/', PAGE_READY)
    await openSignIn(stale.page)
    await login(stale.page, person.email)
    await expect(stale.page.getByTestId('self-user')).toHaveText(person.name)
    stale.userId = person.userId
    stale.pick = 'dark'
    stale.initialized = true
    const handshakes = stale.frames.filter(
      (frame) => frame.type === 'handshake_response',
    )
    expect(handshakes.length).toBeGreaterThan(0)
    expectIdentity(handshakes[handshakes.length - 1], stale)
    for (const frame of stale.frames)
      expect(frameSettings(frame), frame.raw).toEqual(settings)
    await expectBrowserTheme(stale, settings)
    expect(adoptions()).toBe(0)
    expect(await sessionToken(stale.page)).not.toBe(
      await sessionToken(first.page),
    )
    const firstBaseline = { ...first.counts }
    const staleLoads = stale.counts.loads

    await stale.page.context().setOffline(true)
    await dropSocket(stale.page)
    await expect(stale.page.getByTestId('conn-state')).not.toHaveText(
      'connected',
    )
    const resetStart = first.frames.length
    const offlineStart = stale.frames.length
    first.pick = null
    await chooseHeaderTheme(expect, first.page, null)
    await expectAccountDelivery([first], [resetStart], settings)
    // The disconnected browser still has a real former account choice.
    await expectBrowserTheme(stale, settings)
    expect(stale.frames).toHaveLength(offlineStart)

    stale.pick = null
    for (let reconnect = 0; reconnect < 2; reconnect += 1) {
      if (reconnect > 0) {
        await stale.page.context().setOffline(true)
        await dropSocket(stale.page)
        await expect(stale.page.getByTestId('conn-state')).not.toHaveText(
          'connected',
        )
      }
      const start = stale.frames.length
      const sockets = stale.counts.sockets
      const reconnected = stale.page.waitForEvent('websocket')
      await stale.page.context().setOffline(false)
      await reconnected
      await expect(stale.page.getByTestId('conn-state')).toHaveText('connected')
      await expectAccountDelivery([stale], [start], settings)
      expect(stale.frames[start].type).toBe('handshake_response')
      expect(stale.counts.sockets).toBeGreaterThan(sockets)
      expect(stale.counts.loads).toBe(staleLoads)
      await expectBrowserTheme(first, settings)
      expectInterval([first], [resetStart], settings)
      expectInterval([stale], [offlineStart], settings)
      expect(first.counts).toEqual(firstBaseline)
      expect(adoptions()).toBe(0)
    }
  })
})
