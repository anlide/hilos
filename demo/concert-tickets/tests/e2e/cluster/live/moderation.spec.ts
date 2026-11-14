import { expect, test as base } from '../../test.js'

import {
  PASSWORD,
  FRAMEWORK_MODERATION_KINDS,
  addModerationRule,
  changeOwnName,
  cleanupFixtures,
  clickSubmit,
  clusterCommandChannel,
  deleteModerationRule,
  expectClusterLeaderUnchanged,
  expectClusterSingleAgents,
  expectModerationRuleShown,
  expectSessionRotation,
  gotoPage,
  login,
  newClusterMasterPage,
  openSignIn,
  readClusterBrowserStand,
  reserveModerationRules,
  shownByTestId,
  uniqueEmail,
  PAGE_READY,
  watchClusterMasterUpgrades,
  type ClusterInspection,
  type TestCleanup,
} from '../../../../../../framework/frontend/e2e/index.js'
import { setAdmin } from '../../../../../../framework/frontend/scripts/adminGrant.mjs'
import {
  dictateModerationVerdict,
  forgetModelKey,
  modelKey,
  readModelRequests,
} from '../../../../../../framework/frontend/scripts/standModel.mjs'
import { seedTestUser } from '../../../../../../framework/frontend/scripts/testUser.mjs'

// spec-owner: demo — carries Concert Tickets' Angular cluster browser journeys

const test = base.extend<{ cleanup: TestCleanup }>({
  cleanup: cleanupFixtures,
})
const stand = readClusterBrowserStand()
const sendCommand = clusterCommandChannel(stand, stand.follower)
const NAME = FRAMEWORK_MODERATION_KINDS.find(
  ({ kind }) => kind === 'hilos_profile_name',
)!
const RULES_PATH = `/hilos/moderation/kind/${NAME.kind}/rules`
const SINGLE_AGENTS = ['hilos_moderator', 'hilos_moderation_rules_library']

for (const [index, sourceNode] of stand.masters.entries()) {
  const authorNode = stand.masters[(index + 1) % stand.masters.length]
  const observerNode = stand.masters[(index + 2) % stand.masters.length]

  // FIXME(F-060, 2026-11-04): Rules returns 401 after login on m3 · свой · попытка 2 · HIL-1818
  // FIXME(F-101, 2026-11-14): Through m3 the profile name still shows the old name after the rename modal closes · свой · попытка 3 · HIL-2035
  test(
    `a rule saved through ${authorNode} governs a name checked through ${sourceNode}, and ${observerNode} shows the rule live`,
    index === 0
      ? { tag: '@quarantine:F-060' }
      : index === 2
        ? { tag: '@quarantine:F-101' }
        : {},
    async ({ browser, cleanup }) => {
      expect(new Set([sourceNode, authorNode, observerNode]).size).toBe(3)
      const before = (await sendCommand(
        'test:cluster:inspect',
        {},
      )) as unknown as ClusterInspection
      await expectClusterLeaderUnchanged(expect, stand, before)
      await expectClusterSingleAgents(expect, stand, SINGLE_AGENTS)

      const participants = []
      for (const node of [sourceNode, authorNode, observerNode]) {
        const page = await newClusterMasterPage(browser, stand, node)
        cleanup.defer(() => page.context().close())
        const upgrades = await watchClusterMasterUpgrades(
          expect,
          page,
          stand,
          node,
        )
        cleanup.defer(() => upgrades.stop())
        participants.push({ page, upgrades })
      }
      const [source, author, observer] = participants
      const key = modelKey()
      cleanup.defer(() => forgetModelKey(key))
      const rule = `Allow ordinary names. Cluster policy marker ${key}.`

      await test.step('the administrator opens Rules through two different masters', async () => {
        const admin = await seedTestUser({ email: uniqueEmail() })
        await setAdmin(admin.userId, true)
        for (const page of [author.page, observer.page]) {
          await gotoPage(expect, page, '/')
          await openSignIn(expect, page)
          await expectSessionRotation(expect, page, async () => {
            await login(expect, page, admin.email, PASSWORD)
            await expect(page.getByTestId('nav-admin')).toHaveAttribute(
              'data-access',
              'full',
            )
          })
          await gotoPage(expect, page, RULES_PATH, PAGE_READY)
        }
      })

      reserveModerationRules(expect, author.page, cleanup, NAME, [rule])
      const ruleId =
        await test.step('the saved rule reaches the observer without a reload', async () => {
          const id = await addModerationRule(expect, author.page, NAME, rule)
          await expectModerationRuleShown(expect, observer.page, rule, true)
          return id
        })

      await test.step('the rule governs a name submitted through the third master', async () => {
        await dictateModerationVerdict(key, true, 'ok')
        const user = await seedTestUser({ email: uniqueEmail() })
        await gotoPage(expect, source.page, '/')
        await openSignIn(expect, source.page)
        await expectSessionRotation(expect, source.page, () =>
          login(expect, source.page, user.email, PASSWORD),
        )
        await gotoPage(expect, source.page, '/profile')
        // Only the rule carries the model key: this name cannot select its answer.
        const name = `Ordinary person ${sourceNode}`
        await changeOwnName(expect, source.page, name)
        await expect(source.page.getByTestId('modal')).toBeHidden()
        await expect(source.page.getByTestId('profile-name')).toHaveText(name)
        await expect(
          source.page.getByTestId('nav-profile-name'),
        ).toHaveAccessibleName(name)
        expect(await readModelRequests(key)).toEqual([
          { ordinal: 1, key, imageHashes: [] },
        ])
      })

      await test.step('the observer deletes the rule and it leaves the author without a reload', async () => {
        await deleteModerationRule(expect, observer.page, ruleId, rule)
        await expect(
          shownByTestId(author.page, `hilos-table-pending-remove-${ruleId}`),
        ).toBeVisible()
        await expectModerationRuleShown(expect, author.page, rule, true)
        await clickSubmit(expect, author.page.getByTestId('hilos-table-apply'))
        await expect(author.page.getByTestId('hilos-table-apply')).toHaveCount(
          0,
        )
        await expectModerationRuleShown(expect, author.page, rule, false)
      })

      for (const { upgrades } of participants) await upgrades.assert()
      await expectClusterSingleAgents(expect, stand, SINGLE_AGENTS)
      await expectClusterLeaderUnchanged(expect, stand, before)
    },
  )
}
