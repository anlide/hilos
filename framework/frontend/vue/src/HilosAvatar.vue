<!-- HilosAvatar — one circle of initials for a person in the header, profile
and admin card. The circle is decorative: its surroundings carry the name,
visibly or as hidden text, and any link or tooltip. Photos arrive in HIL-1205. -->
<script setup lang="ts">
import { computed } from 'vue'
import { formatInitials } from '@hilos/core'

const props = withDefaults(
  defineProps<{
    /** The person's name, from the same source as the surrounding text. */
    name: string
    /** Header (sm), admin card (md), or profile (lg). */
    size?: 'sm' | 'md' | 'lg'
  }>(),
  { size: 'sm' },
)

const initials = computed(() => formatInitials(props.name))
</script>

<template>
  <span
    class="rounded-circle bg-secondary-subtle d-inline-flex align-items-center justify-content-center fw-semibold flex-shrink-0"
    :class="`hilos-avatar-${props.size}`"
    data-id="hilos-avatar"
    aria-hidden="true"
  >
    <template v-if="initials">{{ initials }}</template>
    <i v-else class="bi bi-person"></i>
  </span>
</template>
