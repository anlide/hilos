<template>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <slot name="leading" />
    <slot
      name="save-button"
      :disabled="disableSave"
      :on-save="() => $emit('save')"
    >
      <button
        type="button"
        class="btn btn-primary"
        :disabled="disableSave"
        @click="$emit('save')"
      >
        {{ saveLabel }}
      </button>
    </slot>
    <template v-if="conflict">
      <button
        type="button"
        class="btn btn-outline-danger"
        @click="$emit('accept-mine')"
      >
        Accept mine
      </button>
      <button
        type="button"
        class="btn btn-outline-secondary"
        @click="$emit('accept-theirs')"
      >
        Accept theirs
      </button>
      <button
        type="button"
        class="btn btn-outline-warning"
        @click="$emit('merge')"
      >
        Merge changes
      </button>
    </template>
  </div>
</template>

<script setup lang="ts">
interface Props {
  conflict: boolean
  disableSave?: boolean
  saveLabel?: string
}

withDefaults(defineProps<Props>(), {
  disableSave: false,
  saveLabel: 'Save',
})

defineEmits(['save', 'accept-mine', 'accept-theirs', 'merge'])
</script>
