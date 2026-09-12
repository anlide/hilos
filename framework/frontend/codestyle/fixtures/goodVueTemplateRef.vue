<!-- The look-alikes: the same two ref-bearing values, read through `.value` and
     through a computed unwrapped in the script block, the method call that is not
     a read at all, and a name that merely ends with the one the rule watches.
     VUE-TEMPLATE-REF must stay silent. -->
<template>
  <div v-if="message" class="alert">
    <span>{{ action.error.value }}</span>
    <em v-if="own.busy.value">working</em>
    <button @click="own.run('retry')">Retry</button>
    <span>{{ reaction.error }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

/** Stands in for the type the rule knows by name. */
interface TrackedAction {
  error: { value: string | null }
  failure: { value: string | null }
  busy: { value: boolean }
  run(handle: string): void
}

const props = defineProps<{
  /** The ref-bearing prop, read through a computed below and through `.value` above. */
  action: TrackedAction
}>()

/**
 * Stands in for the composable the rule knows by name.
 *
 * @returns The action state, whose fields are refs.
 */
function useTrackedAction(): TrackedAction {
  return {
    error: { value: null },
    failure: { value: null },
    busy: { value: false },
    run: () => undefined,
  }
}

/** The ref-bearing local, read through `.value` in the template. */
const own = useTrackedAction()

/** The other way out: unwrap once here, and the template reads a plain value. */
const message = computed(() => props.action.error.value)

/** A plain object whose name ends with the one the rule watches. */
const reaction = { error: 'not a ref at all' }
</script>
