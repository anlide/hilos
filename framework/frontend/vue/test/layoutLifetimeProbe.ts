import {
  defineComponent,
  h,
  onMounted,
  onUnmounted,
  ref,
  type PropType,
} from 'vue'
import type { LayoutLifetimeEvent } from '../../test-support/layoutLifetimeFixtures.js'

/** The draft belongs to the actual slotted component, not its mount adapter. */
export const LayoutLifetimeProbe = defineComponent({
  props: {
    record: {
      type: Function as PropType<(event: LayoutLifetimeEvent) => void>,
      required: true,
    },
  },
  setup(props) {
    const draft = ref('')
    onMounted(() => props.record({ phase: 'start', instance: draft }))
    onUnmounted(() => props.record({ phase: 'stop', instance: draft }))
    return () =>
      h('div', { 'data-id': 'lifetime-page' }, [
        h('input', {
          'data-id': 'lifetime-draft',
          'aria-label': 'Local draft',
          value: draft.value,
          onInput: (event: Event) => {
            draft.value = (event.target as HTMLInputElement).value
          },
        }),
      ])
  },
})
