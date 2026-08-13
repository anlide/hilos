import { test, expect } from '@playwright/test'

import { gotoPage } from '../helpers/page'
import {
  enterProtectedMode,
  inspectProtectedMode,
  leaveProtectedMode,
  openProtectedMode,
  openProtectedModeIfAny,
} from '../helpers/protectedMode'

// Protected-mode e2e (HIL-344): the debt HIL-268 and HIL-522 were closed with —
// the freeze and its browser stub had never been driven from a browser at all,
// because there was no way to enter the mode without a real restore. There is one
// now, and it is the production entry: the CLI asks an initiator agent, the agent
// asks its daemon. Nothing here forces a runtime row.
//
// The whole node freezes for the duration, so this spec must never leave one
// behind: the runner is serialized (CI=1 → workers: 1), and the teardown lifts
// unconditionally.

// The framework default from Hilos::PROTECTED_MODE_STUB, which this demo does not
// override.
const STUB_TITLE = 'Maintenance in progress'
const STUB_MESSAGE =
  'The application is briefly unavailable while a maintenance operation finishes.' +
  ' It will come back on its own.'

const OPERATION = 'e2e-freeze'

test.afterEach(async () => {
  // Unconditional, and an open rather than a leave: an enter can be refused and
  // still land afterwards, a failed assertion can strand the node in any phase,
  // and only the open lifts from all of them.
  await openProtectedModeIfAny()
})

test('a live window shows the maintenance stub while the mode is on', async ({
  page,
}) => {
  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await expect(page.getByTestId('maintenance')).toBeHidden()

  // Entering with no accept key is the main scenario: a freeze asked for from a
  // terminal has no browser connection to keep alive, so this window is locked
  // out like every other.
  expect(await enterProtectedMode(OPERATION)).toBe('active')

  const maintenance = page.getByTestId('maintenance')
  await expect(maintenance).toBeVisible()
  await expect(page.getByTestId('maintenance-title')).toHaveText(STUB_TITLE)
  await expect(page.getByTestId('maintenance-message')).toHaveText(STUB_MESSAGE)

  // This attribute is what proves the words came from the backend rather than
  // from the frontend last resort: the two copies are deliberately kept identical
  // (PROTECTED_MODE_FALLBACK_COPY mirrors the framework default), so the text
  // alone cannot tell them apart — but the operation name has no frontend default
  // at all, and this one was chosen by the caller a moment ago.
  await expect(maintenance).toHaveAttribute('data-operation', OPERATION)

  // The operation ending does NOT let this window back in: it lands in the
  // verification window, where only a presented pass is admitted, and this one
  // has none. That is the whole leaf — nothing reopens by finishing its own work.
  expect(await leaveProtectedMode()).toBe('verifying')
  await expect(page.getByTestId('maintenance')).toBeVisible()

  expect(await openProtectedMode()).toBe('inactive')

  await expect(page.getByTestId('maintenance')).toBeHidden()
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
})

test('a finished operation lands in the verification window, and only the open ends it', async () => {
  // The ladder as the master reports it: the window is a phase of the same freeze,
  // so the start gate stays closed and no pass is outstanding until one is minted.
  await enterProtectedMode(OPERATION)
  expect(await leaveProtectedMode()).toBe('verifying')

  const verifying = await inspectProtectedMode()
  expect(verifying.phase).toBe('verifying')
  expect(verifying.operation).toBe(OPERATION)
  expect(verifying.passCount).toBe(0)

  await openProtectedMode()

  const lifted = await inspectProtectedMode()
  expect(lifted.phase).toBe('inactive')
  expect(lifted.passCount).toBe(0)
})

test('the inspector answers mid-freeze, when every other agent is stopped', async () => {
  // The reason the inspector is answered by the master and not by an agent: at
  // this moment there is no agent left to ask but the initiator.
  await enterProtectedMode(OPERATION)

  const frozen = await inspectProtectedMode()
  expect(frozen.rtMounted).toBe(true)
  expect(frozen.phase).toBe('active')
  expect(frozen.operation).toBe(OPERATION)
  expect(frozen.agentStartGateClosed).toBe(true)
  expect(frozen.initiatorAgentType).not.toBeNull()

  await openProtectedMode()

  const lifted = await inspectProtectedMode()
  expect(lifted.phase).toBe('inactive')
  expect(lifted.agentStartGateClosed).toBe(false)
  expect(lifted.operation).toBeNull()
})

test('entering twice is refused with a reason instead of timing out', async () => {
  await enterProtectedMode(OPERATION)

  // The core drops a repeat enable with a warning and answers nobody, so without
  // the agent's pre-check this would be a mute timeout.
  await expect(enterProtectedMode(OPERATION)).rejects.toThrow(/already active/)
})
