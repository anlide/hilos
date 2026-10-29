import { expect, test, type Page } from '@playwright/test'

import {
  createTestUser,
  CIRCLE_ONLINE,
  MAINTENANCE_CIRCLE_PATH,
  MAINTENANCE_PATH,
  MANUAL_MAINTENANCE_OPERATION,
  addToMaintenanceCircle,
  clearMaintenanceCircle,
  clearCustomSetting,
  clickSubmit,
  deliveryObject,
  dismissToasts,
  expectAdmittedToManualMaintenance,
  expectDocumentUnchanged,
  expectHeldByManualMaintenance,
  expectMaintenanceCodeIssued,
  expectMaintenanceOpen,
  expectMaintenanceWatchdogThresholds,
  expectManualMaintenanceOn,
  expectNoProtectedModeAgentStops,
  issueMaintenanceCode,
  maintenanceCircleOnline,
  maintenanceCircleRow,
  markClusterNodeLogs,
  openSettingEdit,
  presentMaintenanceCode,
  readClusterBrowserStand,
  setCustomSetting,
  shownByTestId,
  turnOffMaintenance,
  turnOnMaintenance,
  watchClusterMasterUpgrades,
  watchDeliveryFrames,
  watchSocketFrames,
  type DeliveryFrameLog,
  type SocketFrame,
  expectPageReady,
  expectSelfReload,
  gotoMaintenance,
  gotoPage,
  markDocument,
  grantAdminToSelf,
  sessionToken,
  typeInto,
} from '../../../../../../framework/frontend/e2e/index.js'
import {
  CLUSTER_FOLLOWER,
  CLUSTER_LEADER,
  CLUSTER_MASTERS,
  clusterInspect,
  expectLeaderUnmoved,
  newMasterPage,
  nodeHost,
  onMaster,
} from '../../helpers/cluster.js'
import {
  closeProtectedMode,
  enterProtectedMode,
  inspectProtectedMode,
  leaveProtectedMode,
  mintProtectedModePass,
  openProtectedMode,
  openProtectedModeIfAny,
} from '../../../../../../framework/frontend/scripts/protectedMode.mjs'

const OPERATION = 'cluster-e2e-freeze'
const STUB_TITLE = 'Maintenance in progress'
const BANNER_MESSAGE = 'Maintenance has finished and is being verified.'

test.afterEach(async () => {
  await openProtectedModeIfAny()
  for (const node of CLUSTER_MASTERS) {
    await expect
      .poll(() => inspectProtectedMode(undefined, nodeHost(node)), {
        message: `Protected mode cleared on ${node}`,
      })
      .toMatchObject({ phase: 'inactive', passCount: 0 })
  }
})

/** @param page A circle member's tab that the verification window admitted. */
async function expectInsideMainPage(page: Page): Promise<void> {
  await expect(page.getByTestId('maintenance')).toHaveCount(0)
  await expect(page.getByTestId('protected-mode-banner')).toContainText(
    BANNER_MESSAGE,
  )
  await expectPageReady(expect, page)
  await expect(page.getByTestId('self-user')).toBeVisible()
}

/**
 * @param page The follower tab showing the code form.
 * @param code Minted pass.
 */
async function presentCode(page: Page, code: string): Promise<void> {
  const field = page.getByTestId('maintenance-pass')
  await typeInto(field, code)
  const submit = page.getByTestId('maintenance-pass-submit')
  await submit.scrollIntoViewIfNeeded()
  await expect(submit).toBeVisible()
  await expect(submit).toBeEnabled()
  await submit.focus()
  await submit.click()
  await expect(page.getByTestId('maintenance-pass-form')).toHaveCount(0)
}

// FIXME(F-016, 2026-10-18): parked on the owner's word (HOTFIX), without a diagnosis.
// Both tests red in 2 of the 6 full runs since 1268 (1271, 1277, both on seven lanes), each
// time green alone on the same sha (1272, 1278). Every failure is a browser sign-up after
// the daemon phase lost the leader and recreated s1: the code is accepted, the registration
// never completes. On 1274 and 1277 the auth throttle also went silent for 8-9 s and
// refused the first registration. Node journals:
// runs/<n>/steps/artifacts/binance-btc-tracker-cluster-e2e/nodes/ on the shared test box.
test(
  'every master shows the stub while the mode is on, and a person signed in on a follower master comes back as the same person',
  { tag: '@quarantine:F-016' },
  async ({ browser }) => {
    // Multiple masters settle a freeze, verification window and self-reload in this test.
    test.slow()
    const before = await clusterInspect(CLUSTER_FOLLOWER)
    await expectLeaderUnmoved(before)
    const pages = await Promise.all(
      CLUSTER_MASTERS.map((node) => newMasterPage(browser, node)),
    )
    try {
      for (const page of pages) {
        await gotoPage(expect, page, '/')
        await expect(page.getByTestId('conn-state')).toHaveText('connected')
        await expect(page.getByTestId('maintenance')).toBeHidden()
      }
      const follower = pages[CLUSTER_MASTERS.indexOf(CLUSTER_FOLLOWER)]
      const person = await createTestUser(expect, follower)
      expect(await enterProtectedMode(OPERATION)).toBe('active')
      for (const page of pages) {
        await expect(page.getByTestId('maintenance')).toBeVisible()
        await expect(page.getByTestId('maintenance-title')).toHaveText(
          STUB_TITLE,
        )
      }
      expect(await leaveProtectedMode()).toBe('verifying')
      for (const page of pages) {
        await expect(page.getByTestId('maintenance')).toBeVisible()
        await markDocument(page)
      }
      expect(await openProtectedMode()).toBe('inactive')
      for (const page of pages) {
        await expectSelfReload(page, test.info().project.use.navigationTimeout)
        await expect(page.getByTestId('maintenance')).toBeHidden()
        await expect(page.getByTestId('conn-state')).toHaveText('connected')
      }
      await expect(follower.getByTestId('self-user')).toHaveText(person.name)
      await expect(follower.getByTestId('self-user-id')).toHaveText(
        String(person.userId),
      )
    } finally {
      await Promise.all(pages.map((page) => page.context().close()))
      await expectLeaderUnmoved(before)
    }
  },
)

// FIXME(F-016, 2026-10-18): parked with the test above; the reason is above it.
test(
  "the named circle walks in on a follower master with the tab it already had open, the operator's tab follows its session to another master, and nobody else does",
  { tag: '@quarantine:F-016' },
  async ({ browser }) => {
    // Multiple masters settle a freeze, verification window and self-reload in this test.
    test.slow()
    const before = await clusterInspect(CLUSTER_FOLLOWER)
    await expectLeaderUnmoved(before)
    const operator = await newMasterPage(browser, CLUSTER_FOLLOWER)
    const member = await newMasterPage(browser, CLUSTER_FOLLOWER)
    const bystander = await newMasterPage(browser, CLUSTER_FOLLOWER)
    try {
      await grantAdminToSelf(expect, operator)
      await gotoPage(expect, operator, MAINTENANCE_CIRCLE_PATH)
      await clearMaintenanceCircle(operator)
      const operatorSession = await sessionToken(operator)

      const memberEmail = (await createTestUser(expect, member)).email
      await addToMaintenanceCircle(operator, memberEmail)
      await expect(maintenanceCircleRow(operator, memberEmail)).toBeVisible()
      await expect(maintenanceCircleOnline(operator, memberEmail)).toHaveText(
        CIRCLE_ONLINE,
      )
      await gotoPage(expect, operator, '/')
      await gotoPage(expect, member, '/')
      await expect(member.getByTestId('conn-state')).toHaveText('connected')
      await gotoPage(expect, bystander, '/')
      await expect(bystander.getByTestId('conn-state')).toHaveText('connected')

      expect(await enterProtectedMode(OPERATION, '', operatorSession)).toBe(
        'active',
      )
      expect(await leaveProtectedMode()).toBe('verifying')
      const inside = await inspectProtectedMode(
        undefined,
        nodeHost(CLUSTER_FOLLOWER),
      )
      expect(inside.circleSize).toBe(1)
      expect(inside.circleAdmitted).toBe(1)
      await expectInsideMainPage(operator)
      await onMaster(operator, CLUSTER_LEADER)
      await operator.reload()
      await expectInsideMainPage(operator)
      await expectInsideMainPage(member)
      await expect(bystander.getByTestId('maintenance')).toBeVisible()

      expect(await closeProtectedMode()).toBe('active')
      await expect(member.getByTestId('maintenance')).toBeVisible()
      await expect(bystander.getByTestId('maintenance')).toBeVisible()
      await markDocument(operator)
      await markDocument(member)
      await markDocument(bystander)
      expect(await openProtectedMode()).toBe('inactive')
      await expectSelfReload(
        operator,
        test.info().project.use.navigationTimeout,
      )
      await expectSelfReload(member, test.info().project.use.navigationTimeout)
      await expectSelfReload(
        bystander,
        test.info().project.use.navigationTimeout,
      )
      await gotoPage(expect, operator, MAINTENANCE_CIRCLE_PATH)
      await clearMaintenanceCircle(operator)
    } finally {
      await operator.context().close()
      await member.context().close()
      await bystander.context().close()
      await expectLeaderUnmoved(before)
    }
  },
)

test('a minted code lets a tab on a follower master in, it stays in after a reload onto another master, and a bystander there keeps the form', async ({
  browser,
}) => {
  // Multiple masters settle a freeze, verification window and self-reload in this test.
  test.slow()
  const before = await clusterInspect(CLUSTER_FOLLOWER)
  await expectLeaderUnmoved(before)
  const otherMaster = CLUSTER_MASTERS.find(
    (node) => node !== CLUSTER_LEADER && node !== CLUSTER_FOLLOWER,
  )
  if (!otherMaster)
    throw new Error('The cluster browser stand needs a third master')
  const verifier = await newMasterPage(browser, CLUSTER_FOLLOWER)
  const bystander = await newMasterPage(browser, otherMaster)
  try {
    expect(await enterProtectedMode(OPERATION)).toBe('active')
    expect(await leaveProtectedMode()).toBe('verifying')
    const pass = await mintProtectedModePass()
    await gotoMaintenance(expect, verifier, '/hilos')
    await presentCode(verifier, pass)
    await expect(verifier.getByTestId('maintenance')).toBeHidden()
    await expect(verifier.getByTestId('conn-state')).toHaveText('connected')
    await onMaster(verifier, otherMaster)
    await verifier.reload()
    await expect(verifier.getByTestId('maintenance')).toBeHidden()
    await expect(verifier.getByTestId('maintenance-pass-form')).toHaveCount(0)
    await expect(verifier.getByTestId('conn-state')).toHaveText('connected')
    await gotoMaintenance(expect, bystander, '/hilos')
    await expect(bystander.getByTestId('maintenance-pass-form')).toBeVisible()

    await markDocument(verifier)
    await markDocument(bystander)
    expect(await openProtectedMode()).toBe('inactive')
    await expectSelfReload(verifier, test.info().project.use.navigationTimeout)
    await expectSelfReload(bystander, test.info().project.use.navigationTimeout)
  } finally {
    await verifier.context().close()
    await bystander.context().close()
    await expectLeaderUnmoved(before)
  }
})

test('cluster manual maintenance: every master closes, another admitted admin opens, and every page reloads', async ({
  browser,
}) => {
  // Six independent sessions, real codes through two masters and six self-reloads.
  test.slow()
  const stand = readClusterBrowserStand()
  const before = await clusterInspect(CLUSTER_FOLLOWER)
  await expectLeaderUnmoved(before)
  const otherMaster = CLUSTER_MASTERS.find(
    (node) => node !== CLUSTER_LEADER && node !== CLUSTER_FOLLOWER,
  )
  if (!otherMaster)
    throw new Error('The cluster browser stand needs a third master')

  const pages: Page[] = []
  const upgrades: Awaited<ReturnType<typeof watchClusterMasterUpgrades>>[] = []
  const sessionUsers = new Map<Page, number | null>()
  const frameErrors: unknown[] = []
  const frames: Array<{ page: number; nodeId: string; frame: SocketFrame }> = []
  const deliveries: DeliveryFrameLog[] = []
  const watchdogEvidence: Record<string, unknown> = {}
  const restartPolicyKey = 'protected_mode.manual_maintenance.restart_is_normal'
  let originalRestartPolicy: { custom: boolean; value: boolean } | undefined
  let restartPolicyChanged = false
  let circlePrepared = false
  const restoreRestartPolicy = async (): Promise<void> => {
    if (!restartPolicyChanged || !originalRestartPolicy) return
    const actor = pages[1]
    await gotoPage(expect, actor, '/hilos/settings')
    await typeInto(shownByTestId(actor, 'hilos-table-search'), restartPolicyKey)
    await expect(
      shownByTestId(actor, `hilos-table-row-${restartPolicyKey}`),
    ).toBeVisible()
    if (originalRestartPolicy.custom) {
      await setCustomSetting(
        actor,
        restartPolicyKey,
        originalRestartPolicy.value,
      )
    } else {
      await clearCustomSetting(actor, restartPolicyKey)
    }
    await expect(
      shownByTestId(actor, `hilos-table-row-${restartPolicyKey}`),
    ).toContainText(originalRestartPolicy.custom ? 'custom' : 'default')
    restartPolicyChanged = false
  }
  try {
    for (const node of [
      CLUSTER_FOLLOWER,
      otherMaster,
      CLUSTER_LEADER,
      ...CLUSTER_MASTERS,
    ]) {
      const page = await newMasterPage(browser, node)
      pages.push(page)
      deliveries.push(watchDeliveryFrames(page))
      upgrades.push(await watchClusterMasterUpgrades(expect, page, stand, node))
      // The admin section has no self-user-id element: read its actual session
      // identity from the handshake, including the one after the final reload.
      watchSocketFrames(page, (observation) => {
        frames.push({
          page: pages.indexOf(page),
          nodeId: node,
          frame: observation,
        })
        if (observation.failure) frameErrors.push(observation)
        const frame = observation.wire as
          | {
              type?: string
              data?: { entities?: { currentUser?: { id: number } | null } }
            }
          | null
          | undefined
        if (frame?.type === 'handshake_response') {
          sessionUsers.set(page, frame.data?.entities?.currentUser?.id ?? null)
        }
      })
    }
    const [operator, otherAdmin, member, ...visitors] = pages
    const operatorId = await grantAdminToSelf(expect, operator)
    const otherAdminId = await grantAdminToSelf(expect, otherAdmin)
    expect(otherAdminId).not.toBe(operatorId)
    for (const admin of [operator, otherAdmin]) {
      await gotoPage(expect, admin, MAINTENANCE_PATH)
      await expectMaintenanceOpen(expect, admin)
    }
    const [operatorLog, otherAdminLog] = deliveries
    const initialThresholds = await expectMaintenanceWatchdogThresholds(
      expect,
      operator,
      operatorLog,
    )
    const maintenanceFrames = () =>
      operatorLog.packets
        .flatMap((packet) => packet.frames)
        .filter(
          ({ wire }) =>
            deliveryObject(wire).type === 'subscription_page_hilos_maintenance',
        )
    const initialPolicy = deliveryObject(
      deliveryObject(maintenanceFrames().at(-1)?.wire).data,
    ).watchdogManualRestartIsNormal as boolean
    await gotoPage(expect, otherAdmin, '/hilos/settings')
    await typeInto(
      shownByTestId(otherAdmin, 'hilos-table-search'),
      restartPolicyKey,
    )
    await openSettingEdit(otherAdmin, restartPolicyKey)
    const custom = otherAdmin.getByTestId('hilos-settings-edit-custom')
    await expect(custom).toBeVisible()
    await expect(
      otherAdmin.getByTestId('hilos-settings-edit-value'),
    ).toBeChecked({
      checked: initialPolicy,
    })
    originalRestartPolicy = {
      custom: await custom.isChecked(),
      value: initialPolicy,
    }
    await clickSubmit(
      expect,
      otherAdmin.getByTestId('hilos-settings-edit-cancel'),
    )
    await expect(custom).toHaveCount(0)
    await markDocument(operator)
    const sockets = operatorLog.sockets()
    expect(sockets).toBeGreaterThan(0)
    const changedPolicy = !initialPolicy
    const changedAfter = maintenanceFrames().length
    restartPolicyChanged = true
    await setCustomSetting(otherAdmin, restartPolicyKey, changedPolicy)
    await expect
      .poll(() =>
        maintenanceFrames()
          .slice(changedAfter)
          .map(
            ({ wire }) =>
              deliveryObject(deliveryObject(wire).data)
                .watchdogManualRestartIsNormal,
          ),
      )
      .toContain(changedPolicy)
    const changedThresholds = await expectMaintenanceWatchdogThresholds(
      expect,
      operator,
      operatorLog,
    )
    expect(changedThresholds).toEqual([
      ...initialThresholds.slice(0, 4),
      changedPolicy ? 'No mail — a log entry' : 'Send an alert',
    ])
    await expectDocumentUnchanged(expect, operator)
    expect(operatorLog.sockets()).toBe(sockets)
    await upgrades[0].assert()
    await expectLeaderUnmoved(before)
    const restoredAfter = maintenanceFrames().length
    await restoreRestartPolicy()
    await expect
      .poll(() =>
        maintenanceFrames()
          .slice(restoredAfter)
          .map(
            ({ wire }) =>
              deliveryObject(deliveryObject(wire).data)
                .watchdogManualRestartIsNormal,
          ),
      )
      .toContain(initialPolicy)
    expect(
      await expectMaintenanceWatchdogThresholds(expect, operator, operatorLog),
    ).toEqual(initialThresholds)
    await expectDocumentUnchanged(expect, operator)
    expect(operatorLog.sockets()).toBe(sockets)
    await upgrades[0].assert()
    await expectLeaderUnmoved(before)
    Object.assign(watchdogEvidence, {
      originalRestartPolicy,
      changedPolicy,
      changedAfter,
      restoredAfter,
      sockets,
      initialThresholds,
      changedThresholds,
    })
    await gotoPage(expect, otherAdmin, MAINTENANCE_PATH)
    await expectMaintenanceOpen(expect, otherAdmin)
    await gotoPage(expect, operator, MAINTENANCE_CIRCLE_PATH)
    await clearMaintenanceCircle(operator)
    circlePrepared = true
    const memberEmail = (await createTestUser(expect, member)).email
    await gotoPage(expect, member, '/')
    const memberName = await member.getByTestId('self-user').innerText()
    const memberId = await member.getByTestId('self-user-id').innerText()
    await addToMaintenanceCircle(operator, memberEmail)
    await expect(maintenanceCircleRow(operator, memberEmail)).toBeVisible()
    await expect(maintenanceCircleOnline(operator, memberEmail)).toHaveText(
      CIRCLE_ONLINE,
    )
    for (const visitor of visitors) await gotoPage(expect, visitor, '/')
    for (const page of pages) {
      await expect(page.getByTestId('conn-state')).toHaveText('connected')
    }
    await expect.poll(() => sessionUsers.get(operator)).toBe(operatorId)
    await expect.poll(() => sessionUsers.get(otherAdmin)).toBe(otherAdminId)
    for (const watcher of upgrades) await watcher.assert()

    await gotoPage(expect, operator, MAINTENANCE_PATH)
    const navigations = new Map<Page, number>()
    for (const page of [operator, member]) {
      await markDocument(page)
      navigations.set(page, 0)
      page.on('framenavigated', (frame) => {
        if (frame === page.mainFrame()) {
          navigations.set(page, (navigations.get(page) ?? 0) + 1)
        }
      })
    }
    const marks = markClusterNodeLogs(stand, CLUSTER_MASTERS)
    await turnOnMaintenance(operator)
    await expectManualMaintenanceOn(expect, operator)
    expect(
      await expectMaintenanceWatchdogThresholds(expect, operator, operatorLog),
    ).toEqual(initialThresholds)
    await expect(
      operator.getByTestId('hilos-maintenance-watchdog-alert'),
    ).toHaveCount(0)
    await expectPageReady(expect, member)
    await expect(member.getByTestId('self-user')).toHaveText(memberName)
    await expect(member.getByTestId('self-user-id')).toHaveText(memberId)
    await expect(member.getByTestId('conn-state')).toHaveText('connected')
    await expect(member.getByTestId('maintenance')).toHaveCount(0)
    await expect(member.getByTestId('protected-mode-banner')).toHaveText(
      'Manual maintenance is on: the system is closed to visitors.' +
        ' Turn it off in the Maintenance section.',
    )
    for (const outsider of [otherAdmin, ...visitors]) {
      await expectHeldByManualMaintenance(expect, outsider)
    }

    const expectWindow = async (passCount: number): Promise<void> => {
      for (const node of CLUSTER_MASTERS) {
        await expect
          .poll(() => inspectProtectedMode(undefined, nodeHost(node)), {
            message: `Manual window with ${passCount} codes on ${node}`,
          })
          .toMatchObject({
            phase: 'verifying',
            operation: MANUAL_MAINTENANCE_OPERATION,
            stoppedAgents: [],
            agentStartGateClosed: false,
            passCount,
            circleSize: 1,
            circleAdmitted: 1,
          })
      }
    }
    await expectWindow(0)
    for (const watcher of upgrades) await watcher.assert()
    await dismissToasts(operator)
    const firstCode = await issueMaintenanceCode(operator)
    await expectMaintenanceCodeIssued(expect, operator, firstCode, 1)
    await expectWindow(1)
    await presentMaintenanceCode(otherAdmin, firstCode)
    await expectAdmittedToManualMaintenance(expect, otherAdmin, 1)
    expect(
      await expectMaintenanceWatchdogThresholds(
        expect,
        otherAdmin,
        otherAdminLog,
      ),
    ).toEqual(initialThresholds)
    await expect(
      otherAdmin.getByTestId('hilos-maintenance-watchdog-alert'),
    ).toHaveCount(0)
    await expect(otherAdmin.getByTestId('conn-state')).toHaveText('connected')
    await expect.poll(() => sessionUsers.get(otherAdmin)).toBe(otherAdminId)
    await expect(otherAdmin.getByTestId('hilos-maintenance-pass')).toBeEnabled()
    await expect(
      otherAdmin.getByTestId('hilos-maintenance-disable'),
    ).toBeEnabled()
    await dismissToasts(otherAdmin)
    const secondCode = await issueMaintenanceCode(otherAdmin)
    await expectMaintenanceCodeIssued(expect, otherAdmin, secondCode, 2)
    await expectWindow(2)
    await expectAdmittedToManualMaintenance(expect, operator, 2)
    for (const visitor of visitors) {
      await expectHeldByManualMaintenance(expect, visitor)
    }
    for (const watcher of upgrades) await watcher.assert()
    for (const page of [operator, member]) {
      expect(
        navigations.get(page),
        'The admitted document never navigated',
      ).toBe(0)
    }
    for (const page of pages) await markDocument(page)
    sessionUsers.clear()
    await dismissToasts(otherAdmin)
    await turnOffMaintenance(otherAdmin)
    for (const page of pages) {
      await expectSelfReload(page, test.info().project.use.navigationTimeout)
      await expectPageReady(expect, page)
      await expect(page.getByTestId('conn-state')).toHaveText('connected')
      await expect(page.getByTestId('maintenance')).toHaveCount(0)
      await expect(page.getByTestId('protected-mode-banner')).toHaveCount(0)
    }
    for (const admin of [operator, otherAdmin]) {
      await expect(admin).toHaveURL(
        new URL(MAINTENANCE_PATH, stand.baseURL).href,
      )
      await expectMaintenanceOpen(expect, admin)
      expect(
        await expectMaintenanceWatchdogThresholds(
          expect,
          admin,
          deliveries[pages.indexOf(admin)],
        ),
      ).toEqual(initialThresholds)
      await expect(
        admin.getByTestId('hilos-maintenance-watchdog-alert'),
      ).toHaveCount(0)
    }
    await expect.poll(() => sessionUsers.get(operator)).toBe(operatorId)
    await expect.poll(() => sessionUsers.get(otherAdmin)).toBe(otherAdminId)
    expect(frameErrors).toEqual([])
    await expect(member.getByTestId('self-user')).toHaveText(memberName)
    await expect(member.getByTestId('self-user-id')).toHaveText(memberId)
    for (const node of CLUSTER_MASTERS) {
      await expect
        .poll(() => inspectProtectedMode(undefined, nodeHost(node)), {
          message: `Manual window lifted on ${node}`,
        })
        .toMatchObject({
          phase: 'inactive',
          passCount: 0,
          stoppedAgents: [],
          agentStartGateClosed: false,
        })
    }
    expectNoProtectedModeAgentStops(expect, marks)
    for (const watcher of upgrades) await watcher.assert()
    await expectLeaderUnmoved(before)
  } finally {
    // Soft cleanup failures remain visible alongside the original assertion.
    // Every resource is closed even when opening or clearing the circle fails.
    const cleanup = await Promise.allSettled([
      (async () => {
        await openProtectedModeIfAny()
        for (const node of CLUSTER_MASTERS) {
          await expect
            .poll(() => inspectProtectedMode(undefined, nodeHost(node)))
            .toMatchObject({
              phase: 'inactive',
              passCount: 0,
            })
        }
        if (circlePrepared) {
          await gotoPage(expect, pages[0], MAINTENANCE_CIRCLE_PATH)
          await dismissToasts(pages[0])
          await clearMaintenanceCircle(pages[0])
        }
        await expectLeaderUnmoved(before)
      })(),
    ])
    cleanup.push(...(await Promise.allSettled([restoreRestartPolicy()])))
    for (const log of deliveries) log.stop()
    const stopped = await Promise.allSettled(
      upgrades.map((watcher) => watcher.stop()),
    )
    const closed = await Promise.allSettled(
      pages.map((page) => page.context().close()),
    )
    await test.info().attach('manual-maintenance-frames', {
      body: JSON.stringify(frames),
      contentType: 'application/json',
    })
    await test.info().attach('manual-maintenance-watchdog', {
      body: JSON.stringify({
        ...watchdogEvidence,
        deliveries: deliveries.slice(0, 2).map(({ packets, connections }) => ({
          packets,
          connections,
        })),
      }),
      contentType: 'application/json',
    })
    for (const result of [...cleanup, ...stopped, ...closed]) {
      expect
        .soft(
          result.status === 'fulfilled' ? null : String(result.reason),
          'Manual maintenance cleanup',
        )
        .toBeNull()
    }
  }
})
