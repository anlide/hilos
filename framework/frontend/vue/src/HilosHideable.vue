<!-- HilosHideable — a value a viewer of the admin view mode may be sent hidden
(Hideable<T>, @hilos/core): the hidden one is drawn as HilosHiddenMark, any other
goes to the default slot narrowed to T, so the slot draws the value the way the
screen always has and never meets the mark. With no slot the value is printed as
text. The page reads the field as hideable and hands it here as it is; nothing
on the screen tests for the mark itself. -->
<script setup lang="ts" generic="T">
import { type Hideable, isHiddenValue } from '@hilos/core'

import HilosHiddenMark from './HilosHiddenMark.vue'

const props = defineProps<{
  /** The value, or the hidden mark the server sent in its place. */
  value: Hideable<T>
}>()

defineSlots<{
  /** The value when it is not hidden, narrowed to its own type. */
  default?(props: { value: T }): unknown
}>()
</script>

<template>
  <HilosHiddenMark v-if="isHiddenValue(props.value)" />
  <slot v-else :value="props.value">{{ props.value }}</slot>
</template>
