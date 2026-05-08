import { createApp, nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import HilosSettingsValueCell from '@/views/Hilos/HilosSettingsValueCell.vue'

const mountCell = async (props: {
  value: string | null
  type: string
  valueSource?: string
  defaultReferenceKey?: string
}) => {
  const root = document.createElement('div')
  document.body.appendChild(root)
  const app = createApp(HilosSettingsValueCell, props)
  app.mount(root)
  await nextTick()

  return {
    root,
    unmount: () => {
      app.unmount()
      root.remove()
    },
  }
}

describe('HilosSettingsValueCell', () => {
  it('shows the referenced default source key', async () => {
    const { root, unmount } = await mountCell({
      value: 'qwen2.5:3b',
      type: 'string',
      valueSource: 'reference',
      defaultReferenceKey: 'default_bot_model',
    })

    try {
      expect(root.textContent).toContain('qwen2.5:3b')
      expect(root.textContent).toContain('default_bot_model')
      expect(root.querySelector('[title="Default from default_bot_model"]')).not.toBeNull()
    } finally {
      unmount()
    }
  })
})
