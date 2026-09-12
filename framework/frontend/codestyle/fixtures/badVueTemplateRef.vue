<!-- Deliberately broken sample: a ref-bearing prop and a ref-bearing local, both
     read in the template without `.value`. VUE-TEMPLATE-REF must report one line
     per read — the attribute and the interpolation alike — and must stay silent
     on the method call, which names a function and not a ref. -->
<template>
  <div v-if="action.error" class="alert">
    <span>{{ action.error }}</span>
    <em v-if="action.failure">the framework held something back</em>
    <button :disabled="own.busy" @click="own.run('retry')">Retry</button>
    <span>{{ label }}</span>
  </div>
</template>

<script setup lang="ts">
/** Stands in for the type the rule knows by name. */
interface TrackedAction {
  error: { value: string | null }
  failure: { value: string | null }
  busy: { value: boolean }
  run(handle: string): void
}

defineProps<{
  /** The ref-bearing prop the template reads bare. */
  action: TrackedAction
  /** A prop of a type the rule knows nothing about. */
  label: string
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

/** The ref-bearing local the template reads bare. */
const own = useTrackedAction()
</script>
