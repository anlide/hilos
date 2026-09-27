import {
  ActionError,
  type ActionLifecycle,
  type ActionResult,
} from '@hilos/core'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, expect, it, vi } from 'vitest'
import {
  change,
  current,
  legalContext,
} from '../../../core/test/legal/fixtures.js'
import HilosProfileAgreementsHistoryPage from './HilosProfileAgreementsHistoryPage.vue'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)

it('orders history newest first, marks current and accepted, and loads comparison only when asked', async () => {
  let resolve!: (value: ActionResult) => void
  const dispatch = vi.fn(() => ({
    done: new Promise<ActionResult>((done) => {
      resolve = done
    }),
  }))
  const world = legalContext({ dispatch } as unknown as ActionLifecycle)
  const view = mount(HilosProfileAgreementsHistoryPage, {
    attachTo: document.body,
    props: { context: world.context },
    global: { stubs: { HilosPageHeading: true } },
  })
  await flushPromises()
  expect(dispatch).not.toHaveBeenCalled()
  const revisions = view.findAll('[data-id="legal-history-revision"]')
  expect(revisions[0]!.attributes('data-revision')).toBe(current.revisionId)
  expect(revisions[0]!.find('[data-id="legal-history-current"]').exists()).toBe(
    true,
  )
  expect(
    revisions[1]!.find('[data-id="legal-history-accepted"]').text(),
  ).toContain('18 September 2026')
  expect(revisions[1]!.find('[data-id="legal-history-compare"]').exists()).toBe(
    false,
  )
  await revisions[0]!.get('[data-id="legal-history-compare"]').trigger('click')
  await flushPromises()
  expect(
    document
      .querySelector('[data-id="legal-dialog-loading"]')
      ?.classList.contains('invisible'),
  ).toBe(false)
  resolve({
    reply: {
      document: 'terms',
      fromRevisionId: '2026-09-17',
      toRevisionId: current.revisionId,
      changes: [change],
    },
  })
  await flushPromises()
  expect(
    document.querySelector('[data-id="legal-changes-modal"]')?.textContent,
  ).toContain('Old wording')
})

it('keeps the server refusal inside the open dialog', async () => {
  const world = legalContext({
    dispatch: () => ({
      done: Promise.reject(new ActionError('read', 'fail', 'No such revision')),
    }),
  } as unknown as ActionLifecycle)
  const view = mount(HilosProfileAgreementsHistoryPage, {
    attachTo: document.body,
    props: { context: world.context },
    global: { stubs: { HilosPageHeading: true } },
  })
  await flushPromises()
  await view.findAll('[data-id="legal-history-open"]')[0]!.trigger('click')
  await flushPromises()
  expect(
    document.querySelector('[data-id="legal-dialog-refusal"]')?.textContent,
  ).toContain('No such revision')
  expect(
    document.querySelector('[data-id="legal-revision-text-modal"]'),
  ).not.toBeNull()
})
