<!-- ConflictActions — the action group for an edit modal with 3-way-merge
conflict resolution. The group draws, left to right, the conflict choices, the
window's Cancel (the cancel-button slot) and Save, in that same markup order,
so Tab runs left to right. While no conflict stands, an invisible twin of the
same markup holds the choices' room, so neither the arrival nor the leaving of
a conflict moves Cancel or Save (styling-rules.md, "The room a live message
takes"; the owner's decision on the acceptance of HIL-1050). Renders a Save
button (override the #save-button slot to supply a LoadingButton, which receives
the computed `disabled` and an `onSave` handler) and, only while a conflict is
unresolved, the three resolution choices: keep mine, take theirs, and merge
where the surface asks for it. Save stays disabled until the conflict is
resolved.
Emits the choice; the parent form applies it against the core threeWayMerge
result. Inside an admin page a viewer of the admin view mode finds Save disabled
— the default one and the slotted one alike (HIL-1261), and so does an
administrator in the page's area of a takeover that only looks (HIL-1170);
Cancel and the conflict choices, which edit only the draft in this window, stay
as always. Bootstrap classes only. -->
<script setup lang="ts">
import { useLookOnly } from './hilosAdminViewMode.js'

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

/**
 * The choice buttons and the inert spans of their twin, to the character —
 * the room equals the true width of the choices only while the classes match.
 */
const CHOICE_CLASS = 'btn btn-outline-secondary'

const { locked, describedBy } = useLookOnly()

function onSave(): void {
  emit('save')
}
</script>

<template>
  <div class="hilos-button-group d-md-flex align-items-center gap-2 flex-wrap">
    <div
      v-if="conflict"
      class="hilos-conflict-choices d-flex gap-2"
      data-id="conflict-choices"
    >
      <button
        type="button"
        :class="CHOICE_CLASS"
        data-id="conflict-accept-mine"
        @click="emit('accept-mine')"
      >
        Keep mine
      </button>
      <button
        type="button"
        :class="CHOICE_CLASS"
        data-id="conflict-accept-theirs"
        @click="emit('accept-theirs')"
      >
        Take theirs
      </button>
      <button
        v-if="mergeable"
        type="button"
        :class="CHOICE_CLASS"
        data-id="conflict-merge"
        @click="emit('merge')"
      >
        Merge
      </button>
    </div>
    <div
      v-else
      class="hilos-conflict-choices d-flex gap-2 invisible"
      aria-hidden="true"
      data-id="conflict-choices-idle"
    >
      <!-- Spans, not buttons: the twin holds the room, it takes no focus. -->
      <span :class="CHOICE_CLASS">Keep mine</span>
      <span :class="CHOICE_CLASS">Take theirs</span>
      <span v-if="mergeable" :class="CHOICE_CLASS">Merge</span>
    </div>
    <slot name="cancel-button" />
    <slot
      name="save-button"
      :disabled="disableSave || conflict || locked"
      :on-save="onSave"
    >
      <button
        type="button"
        class="btn btn-primary"
        :disabled="disableSave || conflict || locked"
        :aria-describedby="describedBy"
        data-id="conflict-save"
        @click="onSave"
      >
        {{ saveLabel }}
      </button>
    </slot>
  </div>
</template>
