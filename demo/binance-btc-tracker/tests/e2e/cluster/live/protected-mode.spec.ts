import { expect, test, type Page } from '@playwright/test'

import {
  CIRCLE_ONLINE,
  addToMaintenanceCircle,
  clearMaintenanceCircle,
  maintenanceCircleOnline,
  maintenanceCircleRow,
} from '../../../../../../framework/frontend/e2e/index.js'
import { grantAdminToSelf, sessionToken } from '../../helpers/adminGrant.js'
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
  expectPageReady,
  expectSelfReload,
  gotoMaintenance,
  gotoPage,
  markDocument,
} from '../../helpers/page.js'
import {
  closeProtectedMode,
  enterProtectedMode,
  inspectProtectedMode,
  leaveProtectedMode,
  mintProtectedModePass,
  openProtectedMode,
  openProtectedModeIfAny,
} from '../../helpers/protectedMode.js'
import { signUp, signUpPerson } from '../../helpers/session.js'

const OPERATION = 'cluster-e2e-freeze'
const STUB_TITLE = 'Maintenance in progress'
const BANNER_MESSAGE = 'Maintenance has finished and is being verified.'

test.afterEach(async () => {
  await openProtectedModeIfAny()
})

/** @param page A circle member's tab that the verification window admitted. */
async function expectInsideMainPage(page: Page): Promise<void> {
  await expect(page.getByTestId('maintenance')).toHaveCount(0)
  await expect(page.getByTestId('protected-mode-banner')).toContainText(
    BANNER_MESSAGE,
  )
  await expectPageReady(page)
  await expect(page.getByTestId('self-user')).toBeVisible()
}

/**
 * @param page The follower tab showing the code form.
 * @param code Minted pass.
 */
async function presentCode(page: Page, code: string): Promise<void> {
  const field = page.getByTestId('maintenance-pass')
  await field.fill('')
  await field.pressSequentially(code, { delay: 10 })
  const submit = page.getByTestId('maintenance-pass-submit')
  await submit.scrollIntoViewIfNeeded()
  await expect(submit).toBeVisible()
  await expect(submit).toBeEnabled()
  await submit.focus()
  await submit.click()
  await expect(page.getByTestId('maintenance-pass-form')).toHaveCount(0)
}

// Red on every attempt in run 0806 (HIL-1218, 7 lanes), green alone in one
// lane (0807) on the same HEAD. The harness recorded leader m3 at term 1; by
// the time the test began m1 led at term 2, so expectLeaderUnmoved() refused
// it before its first step - the extra election seconds after the cluster
// starts (P-459). Parked by the owner on 04.10.2026 (HOTFIX); HIL-1286 pays
// it off.
test.fixme('every master shows the stub while the mode is on, and a person signed in on a follower master comes back as the same person', async ({
  browser,
}) => {
  // Multiple masters settle a freeze, verification window and self-reload in this test.
  test.slow()
  const before = await clusterInspect(CLUSTER_FOLLOWER)
  await expectLeaderUnmoved(before)
  const pages = await Promise.all(
    CLUSTER_MASTERS.map((node) => newMasterPage(browser, node)),
  )
  try {
    for (const page of pages) {
      await gotoPage(page, '/')
      await expect(page.getByTestId('conn-state')).toHaveText('connected')
      await expect(page.getByTestId('maintenance')).toBeHidden()
    }
    const follower = pages[CLUSTER_MASTERS.indexOf(CLUSTER_FOLLOWER)]
    const person = await signUpPerson(follower)
    expect(await enterProtectedMode(OPERATION)).toBe('active')
    for (const page of pages) {
      await expect(page.getByTestId('maintenance')).toBeVisible()
      await expect(page.getByTestId('maintenance-title')).toHaveText(STUB_TITLE)
    }
    expect(await leaveProtectedMode()).toBe('verifying')
    for (const page of pages) {
      await expect(page.getByTestId('maintenance')).toBeVisible()
      await markDocument(page)
    }
    expect(await openProtectedMode()).toBe('inactive')
    for (const page of pages) {
      await expectSelfReload(page)
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
})

// Red on every attempt in run 0806 (HIL-1218, 7 lanes), green alone in one
// lane (0807) on the same HEAD. The harness recorded leader m3 at term 1; by
// the time the test began m1 led at term 2, so expectLeaderUnmoved() refused
// it before its first step - the extra election seconds after the cluster
// starts (P-459). Parked by the owner on 04.10.2026 (HOTFIX); HIL-1286 pays
// it off.
test.fixme("the named circle walks in on a follower master with the tab it already had open, the operator's tab follows its session to another master, and nobody else does", async ({
  browser,
}) => {
  // Multiple masters settle a freeze, verification window and self-reload in this test.
  test.slow()
  const before = await clusterInspect(CLUSTER_FOLLOWER)
  await expectLeaderUnmoved(before)
  const operator = await newMasterPage(browser, CLUSTER_FOLLOWER)
  const member = await newMasterPage(browser, CLUSTER_FOLLOWER)
  const bystander = await newMasterPage(browser, CLUSTER_FOLLOWER)
  try {
    await grantAdminToSelf(operator)
    await gotoPage(operator, '/hilos/maintenance')
    await clearMaintenanceCircle(operator)
    const operatorSession = await sessionToken(operator)

    const memberEmail = await signUp(member)
    await addToMaintenanceCircle(operator, memberEmail)
    await expect(maintenanceCircleRow(operator, memberEmail)).toBeVisible()
    await expect(maintenanceCircleOnline(operator, memberEmail)).toHaveText(
      CIRCLE_ONLINE,
    )
    await gotoPage(operator, '/')
    await gotoPage(member, '/')
    await expect(member.getByTestId('conn-state')).toHaveText('connected')
    await gotoPage(bystander, '/')
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
    await expectSelfReload(operator)
    await expectSelfReload(member)
    await expectSelfReload(bystander)
    await gotoPage(operator, '/hilos/maintenance')
    await clearMaintenanceCircle(operator)
  } finally {
    await operator.context().close()
    await member.context().close()
    await bystander.context().close()
    await expectLeaderUnmoved(before)
  }
})

// Red on every attempt in run 0806 (HIL-1218, 7 lanes), green alone in one
// lane (0807) on the same HEAD. The harness recorded leader m3 at term 1; by
// the time the test began m1 led at term 2, so expectLeaderUnmoved() refused
// it before its first step - the extra election seconds after the cluster
// starts (P-459). Parked by the owner on 04.10.2026 (HOTFIX); HIL-1286 pays
// it off.
test.fixme('a minted code lets a tab on a follower master in, it stays in after a reload onto another master, and a bystander there keeps the form', async ({
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
    await gotoMaintenance(verifier, '/hilos')
    await presentCode(verifier, pass)
    await expect(verifier.getByTestId('maintenance')).toBeHidden()
    await expect(verifier.getByTestId('conn-state')).toHaveText('connected')
    await onMaster(verifier, otherMaster)
    await verifier.reload()
    await expect(verifier.getByTestId('maintenance')).toBeHidden()
    await expect(verifier.getByTestId('maintenance-pass-form')).toHaveCount(0)
    await expect(verifier.getByTestId('conn-state')).toHaveText('connected')
    await gotoMaintenance(bystander, '/hilos')
    await expect(bystander.getByTestId('maintenance-pass-form')).toBeVisible()

    await markDocument(verifier)
    await markDocument(bystander)
    expect(await openProtectedMode()).toBe('inactive')
    await expectSelfReload(verifier)
    await expectSelfReload(bystander)
  } finally {
    await verifier.context().close()
    await bystander.context().close()
    await expectLeaderUnmoved(before)
  }
})
