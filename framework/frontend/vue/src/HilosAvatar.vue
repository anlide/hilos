<!-- HilosAvatar — one circle of a person's photo, initials, or an icon standing in
for both, in the header, profile and admin card. The circle is decorative: its
surroundings carry the name, visibly or as hidden text, and any link or tooltip.
In the header it may carry the mark of the session's standing (HIL-945): a ring
in the standing's color and its icon in the corner, the pair of the strip that
says the same in words — so the mark is decorative too. -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { formatInitials, type HilosAvatarMark } from '@hilos/core/base'

const props = withDefaults(
  defineProps<{
    /** The person's name, from the same source as the surrounding text. */
    name: string
    /** Published photo URL; initials return if it cannot be loaded. */
    photo?: string | null
    /** Bootstrap Icons class drawn instead of the photo and the initials, or none. */
    icon?: string | null
    /** Header (sm), admin card (md), or profile (lg). */
    size?: 'sm' | 'md' | 'lg'
    /** The standing mark by the header avatar, or none (`hilosSessionAvatarMark`). */
    mark?: HilosAvatarMark | null
  }>(),
  { size: 'sm', mark: null, photo: null, icon: null },
)

const initials = computed(() => formatInitials(props.name))
const failedPhoto = ref<string | null>(null)
const shownPhoto = computed(() =>
  props.photo !== null && props.photo !== failedPhoto.value
    ? props.photo
    : null,
)
watch(
  () => props.photo,
  () => {
    failedPhoto.value = null
  },
)
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
    <i
      v-if="props.icon !== null"
      class="bi"
      :class="props.icon"
      data-id="hilos-avatar-icon"
    ></i>
    <img
      v-else-if="shownPhoto !== null"
      :src="shownPhoto"
      alt=""
      class="w-100 h-100 rounded-circle object-fit-cover"
      data-id="hilos-avatar-photo"
      @error="failedPhoto = shownPhoto"
    />
    <template v-else-if="initials">{{ initials }}</template>
    <i v-else class="bi bi-person"></i>
    <i
      v-if="props.mark !== null"
      class="bi position-absolute hilos-avatar-mark"
      :class="[props.mark.icon, `text-${props.mark.tone}-emphasis`]"
      data-id="avatar-mark"
    ></i>
  </span>
</template>
