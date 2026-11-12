import { defineComponent, h, inject, onMounted, type PropType } from 'vue'
import { useAdminViewMode } from '../src/hilosAdminViewMode.js'
import { hilosPageHeadingIdKey } from '../src/hilosPageHeading.js'

/** The modal slot consumes providers inside the real shell and counts mounts. */
export const AdminPageDeletionModalsProbe = defineComponent({
  props: {
    mounted: { type: Function as PropType<() => void>, required: true },
  },
  setup(props) {
    const headingId = inject(hilosPageHeadingIdKey)
    const viewMode = useAdminViewMode()
    onMounted(() => props.mounted())
    return () =>
      h('span', { 'data-id': 'modals-probe' }, `${headingId} ${viewMode.value}`)
  },
})
