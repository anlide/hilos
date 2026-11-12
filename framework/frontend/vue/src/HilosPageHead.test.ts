import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, shallowRef, type VNode } from 'vue'

import HilosPageHead from './HilosPageHead.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

import {
  SECTION,
  resolvePath,
  type PageHeadInput,
} from '../../test-support/pageHeadFixtures.js'
import {
  registerPageHeadBlockCases,
  type PageHeadView,
} from '../../test-support/pageHeadBlockCases.js'
import { registerPageHeadNavigationCases } from '../../test-support/pageHeadNavigationCases.js'
import { registerPageHeadCenteringCases } from '../../test-support/pageHeadCenteringCases.js'

type HeadProps = InstanceType<typeof HilosPageHead>['$props']

/** Every head a test mounted and has not unmounted itself. */
const mounted = new Set<VueWrapper>()

function mountHead(
  props: Partial<HeadProps> = {},
  options: {
    slots?: Record<string, string | (() => VNode[])>
    attach?: boolean
  } = {},
): VueWrapper {
  const wrapper = mount(HilosPageHead, {
    props: {
      identity: SECTION,
      page: 'demo_section',
      params: {},
      resolvePath,
      ...props,
    },
    slots: options.slots,
    attachTo: options.attach ? document.body : undefined,
  })
  mounted.add(wrapper)

  return wrapper
}

afterEach(() => {
  for (const head of mounted) {
    head.unmount()
  }
  mounted.clear()
  vi.unstubAllGlobals()
})

/** Translate the caller content to real slots inside one Vue application. */
async function mountBlock(
  input: PageHeadInput,
  copies: 1 | 2 = 1,
): Promise<PageHeadView> {
  const current = shallowRef(input)
  const app = createApp({
    render() {
      const { content, ...props } = current.value
      delete props.router
      const slots = Object.fromEntries(
        Object.entries(content ?? {}).map(([name, part]) => [
          name === 'skeletonVisual' ? 'skeleton-visual' : name,
          () =>
            h(
              name === 'description' ? 'p' : 'span',
              {
                'data-id': part.dataId,
              },
              part.text,
            ),
        ]),
      )
      return Array.from({ length: copies }, (_, key) =>
        h(HilosPageHead, { ...props, key }, slots),
      )
    },
  })
  if (input.router !== undefined) app.provide(hilosRouterKey, input.router)
  const host = document.body.appendChild(document.createElement('div'))
  try {
    app.mount(host)
    await nextTick()
    return {
      root: host,
      async update(next) {
        current.value = { ...current.value, ...next }
        await nextTick()
      },
      async unmount() {
        try {
          app.unmount()
        } finally {
          host.remove()
        }
      },
    }
  } catch (error) {
    try {
      app.unmount()
    } finally {
      host.remove()
    }
    throw error
  }
}

registerPageHeadBlockCases('Vue', { describe, it, expect }, mountBlock)
registerPageHeadNavigationCases('Vue', { describe, it, expect, vi }, mountBlock)
registerPageHeadCenteringCases('Vue', { describe, it, expect, vi }, mountBlock)

describe('HilosPageHead', () => {
  it('calls real slots only after the owner answers', async () => {
    const skeletonVisual = vi.fn(() => [
      h('span', { 'data-id': 'probe-skeleton-visual' }),
    ])
    const visual = vi.fn(() => [h('span', { 'data-id': 'probe-real-visual' })])
    const head = mountHead(
      {
        identity: undefined,
        kind: 'entity',
        action: { page: 'demo_section', label: 'Back' },
      },
      {
        slots: {
          'skeleton-visual': skeletonVisual,
          visual,
          meta: '<span data-id="probe-code">us</span>',
          badges: '<span data-id="probe-badge">Custom</span>',
          description: '<p data-id="probe-description">Currency</p>',
        },
      },
    )

    // Vue-specific: slots are callbacks and must stay lazy until the answer.
    expect(skeletonVisual).toHaveBeenCalledOnce()
    expect(visual).not.toHaveBeenCalled()
    await head.setProps({ identity: SECTION })
    expect(skeletonVisual).toHaveBeenCalledOnce()
    expect(visual).toHaveBeenCalledOnce()
  })
})
