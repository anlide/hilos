<!-- ConflictActions — the action group for an edit modal with 3-way-merge
conflict resolution, whose buttons stand in the footer's row alongside Cancel on
a narrow screen. Renders a Save button (override the #save-button slot to supply
a LoadingButton, which receives the computed `disabled` and an `onSave` handler)
and, only while a conflict is unresolved, the three resolution choices: keep
mine, take theirs, and merge where the surface asks for it. Save stays disabled
until the conflict is resolved.
Emits the choice; the parent form applies it against the core threeWayMerge
result. Inside an admin page a viewer of the admin view mode finds Save disabled
— the default one and the slotted one alike (HIL-1261); Cancel and the conflict
choices, which edit only the draft in this window, stay as always. Bootstrap
classes only. -->
<script setup lang="ts">
import { HILOS_VIEW_MODE_STRIP_TEXT_ID } from '@hilos/core'

import { useAdminViewMode } from './hilosAdminViewMode.js'

withDefaults(
  defineProps<{
    /** Whether an unresolved conflict blocks saving. */
    conflict?: boolean
    /** Disable save independently (e.g. an invalid draft). */
    disableSave?: boolean
    /** Save button label. */
    saveLabel?: string
    /**
     * Whether the Merge resolution is offered. Only a surface that asks for it
     * shows the button — one where splicing the two values makes sense. A typed
     * value (a setting, a field, a name) never asks: the splice is not a value.
     * Defaults to false.
     */
    mergeable?: boolean
  }>(),
  { conflict: false, disableSave: false, saveLabel: 'Save', mergeable: false },
)

const emit = defineEmits<{
  save: []
  'accept-mine': []
  'accept-theirs': []
  merge: []
}>()

const viewMode = useAdminViewMode()

function onSave(): void {
  emit('save')
}
</script>

<template>
  <div class="hilos-button-group d-md-flex align-items-center gap-2 flex-wrap">
    <slot
      name="save-button"
      :disabled="disableSave || conflict || viewMode"
      :on-save="onSave"
    >
      <button
        type="button"
        class="btn btn-primary"
        :disabled="disableSave || conflict || viewMode"
        :aria-describedby="viewMode ? HILOS_VIEW_MODE_STRIP_TEXT_ID : undefined"
        data-id="conflict-save"
        @click="onSave"
      >
        {{ saveLabel }}
      </button>
    </slot>
    <template v-if="conflict">
      <button
        type="button"
        class="btn btn-outline-secondary"
        data-id="conflict-accept-mine"
        @click="emit('accept-mine')"
      >
        Keep mine
      </button>
      <button
        type="button"
        class="btn btn-outline-secondary"
        data-id="conflict-accept-theirs"
        @click="emit('accept-theirs')"
      >
        Take theirs
      </button>
      <button
        v-if="mergeable"
        type="button"
        class="btn btn-outline-secondary"
        data-id="conflict-merge"
        @click="emit('merge')"
      >
        Merge
      </button>
    </template>
  </div>
</template>
