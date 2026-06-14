<!-- ConflictActions — the action row for an edit modal with 3-way-merge conflict
resolution. Renders a Save button (override the #save-button slot to supply a
LoadingButton, which receives the computed `disabled` and an `onSave` handler)
and, only while a conflict is unresolved, the three resolution choices: keep
mine, take theirs, or merge. Save stays disabled until the conflict is resolved.
Emits the choice; the parent form applies it against the core threeWayMerge
result. Bootstrap classes only. -->
<script setup lang="ts">
withDefaults(
  defineProps<{
    /** Whether an unresolved conflict blocks saving. */
    conflict?: boolean
    /** Disable save independently (e.g. an invalid draft). */
    disableSave?: boolean
    /** Save button label. */
    saveLabel?: string
  }>(),
  { conflict: false, disableSave: false, saveLabel: 'Save' },
)

const emit = defineEmits<{
  save: []
  'accept-mine': []
  'accept-theirs': []
  merge: []
}>()

function onSave(): void {
  emit('save')
}
</script>

<template>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <slot
      name="save-button"
      :disabled="disableSave || conflict"
      :on-save="onSave"
    >
      <button
        type="button"
        class="btn btn-primary"
        :disabled="disableSave || conflict"
        data-id="conflict-save"
        @click="onSave"
      >
        {{ saveLabel }}
      </button>
    </slot>
    <template v-if="conflict">
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm"
        data-id="conflict-accept-mine"
        @click="emit('accept-mine')"
      >
        Keep mine
      </button>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm"
        data-id="conflict-accept-theirs"
        @click="emit('accept-theirs')"
      >
        Take theirs
      </button>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm"
        data-id="conflict-merge"
        @click="emit('merge')"
      >
        Merge
      </button>
    </template>
  </div>
</template>
