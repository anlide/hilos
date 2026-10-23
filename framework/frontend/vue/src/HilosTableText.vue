<script setup lang="ts">
import { computed, inject, onScopeDispose, shallowRef, watch } from 'vue'
import {
  hilosTableTextClass,
  isHiddenValue,
  type Hideable,
} from '@hilos/core/base'
import HilosHideable from './HilosHideable.vue'
import {
  hilosTableTextKey,
  TABLE_TEXT_COPY,
  useTableTextReading,
} from './hilosTableText.js'

const props = defineProps<{
  /** The main text of a parts value, including the server's hidden mark. */
  value: Hideable<string>
}>()
defineSlots<Record<string, never>>()
const context = inject(hilosTableTextKey, null)
if (!context || context.value.heightContent !== 'parts')
  throw new TypeError('HilosTableText requires a heightContent: parts context.')
watch(
  () => context.value.heightContent,
  (mode) => {
    if (mode !== 'parts')
      throw new TypeError(
        'HilosTableText requires a heightContent: parts context.',
      )
  },
)
onScopeDispose(context.register())
const target = shallowRef<HTMLElement | null>(null)
const content = shallowRef<HTMLElement | null>(null)
const hidden = computed(() => isHiddenValue(props.value))
const reserveClass = computed(() => hilosTableTextClass(context.policy()))
const {
  expanded,
  contentId,
  limited,
  textClass,
  caption,
  accessibleName,
  showControl,
  focused,
  toggle,
} = useTableTextReading(
  context,
  target,
  content,
  () =>
    !hidden.value &&
    (context.policy().mode === 'content' || context.regions.value === 1),
)
</script>

<template>
  <div v-if="hidden" class="hilos-stack">
    <div :class="[reserveClass, 'is-open']" aria-hidden="true" inert></div>
    <div><HilosHideable :value="props.value" /></div>
  </div>
  <div v-else>
    <div
      :id="contentId"
      ref="target"
      :class="[textClass, { 'is-open': expanded }]"
    >
      <div ref="content"><HilosHideable :value="props.value" /></div>
    </div>
    <div v-if="limited" class="hilos-stack">
      <span class="invisible" aria-hidden="true" inert>
        <span class="btn btn-link btn-sm p-0">{{
          TABLE_TEXT_COPY.expand
        }}</span>
      </span>
      <div>
        <button
          v-if="showControl"
          type="button"
          class="btn btn-link btn-sm p-0"
          data-id="hilos-table-text-toggle"
          :aria-label="accessibleName"
          :aria-expanded="expanded"
          :aria-controls="contentId"
          @click.stop="toggle"
          @keydown.enter.stop
          @keydown.space.stop
          @focus="focused = true"
          @blur="focused = false"
        >
          {{ caption }}
        </button>
      </div>
    </div>
  </div>
</template>
