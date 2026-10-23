<script setup lang="ts">
/// <reference types="vite/client" />
import {
  computed,
  onMounted,
  onScopeDispose,
  onUpdated,
  provide,
  shallowRef,
  watch,
} from 'vue'
import {
  hilosTableTextKey,
  TABLE_TEXT_COPY,
  useTableTextReading,
  type HilosTableTextContext,
  type HilosTableValueProps,
} from './hilosTableText.js'

const props = defineProps<HilosTableValueProps>()
defineSlots<{ default(): unknown }>()
const target = shallowRef<HTMLElement | null>(null)
const content = shallowRef<HTMLElement | null>(null)
// Wrap pending text before the parent's first geometry read. The DOM guard
// alone permits a cap; a rejected slot returns to intrinsic presentation.
const safe = shallowRef<boolean | null>(null)
const regions = shallowRef(0)
let mounted = false
let stopped = false
let observer: MutationObserver | null = null
let resize: ResizeObserver | null = null
let observed: HTMLElement | null = null

// DOM handles and registrations live only for this browser mount. No worker or
// node reads them (runtime/rt-context.md, architecture/truth-source.md).
const context: HilosTableTextContext = {
  value: props,
  policy: () => props.controller.frame.rowHeight,
  scope: () => {
    const record = props.readRecord()
    return record
      ? {
          controller: props.controller,
          record,
          columnKey: props.columnKey,
          projection: props.projection,
        }
      : null
  },
  regions,
  register() {
    regions.value++
    let active = true
    return () => {
      if (!active) return
      active = false
      regions.value--
    }
  },
}
provide(hilosTableTextKey, context)
const text = computed(() => props.heightContent === 'text')
watch(text, () => (safe.value = null), { flush: 'sync' })
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
  () => text.value && safe.value === true,
)

// Only ordinary inline text is a text slot. Inspect actual DOM, including the
// descendants of child components; never extract controls from a mixed value.
const INLINE_TEXT = new Set(
  'SPAN B STRONG I EM U S SMALL MARK SUB SUP BR WBR CODE KBD SAMP VAR ABBR BDI BDO DEL INS Q CITE TIME RUBY RP RT'.split(
    ' ',
  ),
)

function checkText(): void {
  if (stopped) return
  // The SDK's natural-size container is not consumer slot content.
  const node = content.value
  const valid =
    node !== null &&
    [...node.querySelectorAll('*')].every((element) => {
      if (
        !INLINE_TEXT.has(element.tagName) ||
        element.hasAttribute('tabindex') ||
        element.hasAttribute('contenteditable') ||
        element.hasAttribute('role') ||
        element.hasAttribute('onclick') ||
        element.matches('.badge, [data-id="hilos-hidden"]')
      )
        return false
      const display = getComputedStyle(element).display
      return !/^(block|flow-root|flex|grid|table|list-item|inline-flex|inline-grid|inline-block)/.test(
        display,
      )
    })
  safe.value = valid
}

let diagnosedUnsafe = false
function inspect(): void {
  if (stopped) return
  if (observed !== target.value) {
    observer?.disconnect()
    resize?.disconnect()
    observed = target.value
    if (observed) {
      resize?.observe(observed)
      observer?.observe(observed, {
        childList: true,
        subtree: true,
        attributes: true,
      })
    }
  }
  checkText()
  if (text.value && target.value && !safe.value && !diagnosedUnsafe) {
    if (import.meta.env.DEV)
      console.warn(
        `HilosTableValue: unsafe text slot for ${props.columnKey}; use heightContent: 'parts' and HilosTableText.`,
      )
  }
  diagnosedUnsafe = text.value && !safe.value
}

function validateParts(): void {
  if (
    mounted &&
    !stopped &&
    props.heightContent === 'parts' &&
    context.policy().mode !== 'content' &&
    regions.value !== 1
  )
    throw new TypeError(
      'HilosTableValue: limited parts requires exactly one HilosTableText.',
    )
}
watch(regions, validateParts, { flush: 'post' })
onMounted(() => {
  mounted = true
  observer =
    typeof MutationObserver === 'undefined'
      ? null
      : new MutationObserver(inspect)
  resize =
    typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(inspect)
  window.addEventListener('resize', inspect)
  inspect()
  validateParts()
})
onUpdated(() => {
  inspect()
  validateParts()
})
onScopeDispose(() => {
  stopped = true
  window.removeEventListener('resize', inspect)
  observer?.disconnect()
  resize?.disconnect()
  observer = null
  resize = null
  observed = null
  target.value = null
  content.value = null
})
</script>

<template>
  <div v-if="text">
    <div
      :id="contentId"
      ref="target"
      :class="[
        safe ? textClass : safe === null ? 'hilos-table-text' : undefined,
        { 'is-open': safe && expanded },
      ]"
    >
      <div ref="content"><slot /></div>
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
  <slot v-else />
</template>
