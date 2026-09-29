<!-- HilosAvatar — one circle of initials for a person in the header, profile
and admin card. The circle is decorative: its surroundings carry the name,
visibly or as hidden text, and any link or tooltip. Photos arrive in HIL-1205.
In the header it may carry the mark of the session's standing (HIL-945): a ring
in the standing's color and its icon in the corner, the pair of the strip that
says the same in words — so the mark is decorative too. -->
<script setup lang="ts">
import { computed } from 'vue'
import { formatInitials, type HilosAvatarMark } from '@hilos/core'

const props = withDefaults(
  defineProps<{
    /** The person's name, from the same source as the surrounding text. */
    name: string
    /** Header (sm), admin card (md), or profile (lg). */
    size?: 'sm' | 'md' | 'lg'
    /** The standing mark by the header avatar, or none (`hilosSessionAvatarMark`). */
    mark?: HilosAvatarMark | null
  }>(),
  { size: 'sm', mark: null },
)

const initials = computed(() => formatInitials(props.name))
const ringClasses = computed(() =>
  props.mark === null
    ? []
    : ['position-relative', 'border', 'border-2', `border-${props.mark.tone}`],
)
</script>

<template>
  <span
    class="rounded-circle bg-secondary-subtle d-inline-flex align-items-center justify-content-center fw-semibold flex-shrink-0"
    :class="[`hilos-avatar-${props.size}`, ringClasses]"
    data-id="hilos-avatar"
    aria-hidden="true"
  >
    <template v-if="initials">{{ initials }}</template>
    <i v-else class="bi bi-person"></i>
    <i
      v-if="props.mark !== null"
      class="bi position-absolute hilos-avatar-mark"
      :class="[props.mark.icon, `text-${props.mark.tone}-emphasis`]"
      data-id="avatar-mark"
    ></i>
  </span>
</template>
