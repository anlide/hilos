import { DOMWrapper, mount, type VueWrapper } from '@vue/test-utils'
import { h, nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import type {
  TableBarInput,
  TableBarView,
} from '../../test-support/tableBarFixtures.js'
import { withTableBarResources } from '../../test-support/tableBarFixtures.js'
import { registerTableBarBasicCases } from '../../test-support/tableBarBasicCases.js'
import { registerTableBarListActionCases } from '../../test-support/tableBarListActionCases.js'
import { registerTableBarOrderCases } from '../../test-support/tableBarOrderCases.js'
import { registerTableBarSelectionCases } from '../../test-support/tableBarSelectionCases.js'
import { registerTableBarViewCases } from '../../test-support/tableBarViewCases.js'

import HilosModal from './HilosModal.vue'
import HilosTableBar from './HilosTableBar.vue'

interface VueBarView extends TableBarView {
  readonly wrapper: VueWrapper
}

/** Retain the wrapper before the shell first renders the real Bar. */
async function mountTableBar(input: TableBarInput): Promise<VueBarView> {
  const host = document.createElement('div')
  const ready = ref(false)
  let wrapper: VueWrapper | undefined
  const renderErrors: unknown[] = []
  function checkErrors(): void {
    if (renderErrors.length === 1) throw renderErrors.shift()
    if (renderErrors.length > 1) {
      const errors = renderErrors.splice(0)
      throw new AggregateError(errors, 'Vue table bar failed', {
        cause: errors[0],
      })
    }
  }
  let released = false
  async function unmount(): Promise<void> {
    if (released) return
    released = true
    const errors: unknown[] = []
    for (const release of [
      () => wrapper?.unmount(),
      () => nextTick(),
      checkErrors,
      () => host.remove(),
    ]) {
      try {
        await release()
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length === 1) throw errors[0]
    if (errors.length > 1)
      throw new AggregateError(errors, 'Vue table bar cleanup failed', {
        cause: errors[0],
      })
  }
  try {
    document.body.appendChild(host)
    wrapper = mount(
      {
        render: () =>
          ready.value
            ? h(HilosTableBar, {
                controller: input.controller,
                titleId: input.titleId,
              })
            : null,
      },
      {
        attachTo: host,
        global: {
          config: { errorHandler: (error) => renderErrors.push(error) },
        },
      },
    )
    ready.value = true
    await nextTick()
    checkErrors()
    return {
      root: host,
      wrapper,
      unmount,
      async change(action) {
        action()
        await nextTick()
        checkErrors()
      },
      async type(field, value) {
        await new DOMWrapper(field).setValue(value)
        checkErrors()
      },
    }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Vue table bar setup and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

registerTableBarBasicCases('Vue', { describe, it, expect }, mountTableBar)
registerTableBarListActionCases(
  'Vue',
  { describe, it, expect, vi },
  mountTableBar,
)
registerTableBarOrderCases('Vue', { describe, it, expect }, mountTableBar)
registerTableBarViewCases('Vue', { describe, it, expect, vi }, mountTableBar)
registerTableBarSelectionCases('Vue', { describe, it, expect }, mountTableBar)

// Native API exception: DOM absence cannot detect a mounted CLOSED Vue Modal.
it('N02 has no mounted Modal component without declared filters', async () => {
  let native: VueBarView | undefined
  await withTableBarResources(
    { describe, it, expect },
    async (input) => {
      native = await mountTableBar(input)
      return native
    },
    async (resources) => {
      await resources.mount(
        resources.create({
          title: 'Backups',
          search: {},
          columns: [{ key: 'name', label: 'Name' }],
        }),
      )
      expect(native!.wrapper.findComponent(HilosModal).exists()).toBe(false)
    },
  )
})
