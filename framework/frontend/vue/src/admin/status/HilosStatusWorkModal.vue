<!-- A new announcement and a live row edit share the form, never the merge. -->
<script setup lang="ts">
import { formatMoment, hilosTimeZone, hilosToasts } from '@hilos/core/base'
import type {
  HilosStatusWorkCreate,
  HilosStatusWorkEdit,
  HilosStatusWorkForm,
  HilosStatusWorksState,
} from '@hilos/core/admin/status'
import { computed, useId } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import HilosDateTimePicker from '../../HilosDateTimePicker.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** A real core creation or row-edit window, owned by the Works page. */
  controller: HilosStatusWorkCreate | HilosStatusWorkEdit
  /** The component catalog from the complete first page answer. */
  state: HilosStatusWorksState
}>()
const editor = 'state' in props.controller ? props.controller : null
const creator = 'submitAvailable' in props.controller ? props.controller : null
const opened = useSignal(props.controller.opened)
const form = useSignal(props.controller.form)
const dirty = useSignal(props.controller.dirty)
const timeZone = useSignal(props.controller.timeZone)
const readerTimeZone = useSignal(hilosTimeZone)
const windowPicker = useSignal(props.controller.windowPicker)
const windowState = useSignal({
  get: () => props.controller.windowPicker.get()?.state.get() ?? null,
})
const busy = useSignal(editor?.saving ?? props.controller.busy)
const error = useSignal(props.controller.error)
const failure = useSignal(props.controller.failure)
const blockedReason = useSignal(props.controller.blockedReason)
const data = useSignal(props.state.data)
const editState = useSignal({ get: () => editor?.state.get() ?? null })
const editNoticeText = useSignal({ get: () => editor?.noticeText.get() ?? '' })
const saveAvailable = useSignal({
  get: () => editor?.saveAvailable.get() ?? creator!.submitAvailable.get(),
})
const saveLabel = useSignal({
  get: () => editor?.saveLabel.get() ?? 'Schedule maintenance',
})
const title = editor ? 'Change scheduled maintenance' : 'Schedule maintenance'
const formId = useId()
const hintId = useId()
const fieldsId = useId()
const overlapHintId = useId()
const copyText = computed(() =>
  failure.value?.errorDetail
    ? [failure.value.errorType, failure.value.errorDetail]
        .filter(Boolean)
        .join('\n')
    : (error.value ?? ''),
)
const noticeKind = computed(() =>
  blockedReason.value ? 'deleted' : (editState.value?.notice?.kind ?? null),
)
const noticeText = computed(() => {
  if (blockedReason.value) return blockedReason.value
  if (!editState.value?.conflict) return editNoticeText.value
  const values = editState.value.fields
  const labels: Record<keyof HilosStatusWorkForm, string> = {
    title: 'Title',
    description: 'What visitors read',
    componentKeys: 'Affects',
    startsAt: 'Starts',
    endsAt: 'Ends',
  }
  const conflicts = editState.value.notice?.fields ?? []
  return [
    editNoticeText.value,
    ...conflicts.map((field) => {
      const incoming = values[field].incoming
      const momentField = field === 'startsAt' || field === 'endsAt'
      const theirs = momentField
        ? incoming === null
          ? '—'
          : `${formatMoment(incoming as number) ?? '—'} (${readerTimeZone.value})`
        : field === 'componentKeys'
          ? componentNames(JSON.parse(incoming as string) as string[])
          : (incoming ?? '')
      const mine =
        field === 'componentKeys'
          ? componentNames(form.value.componentKeys)
          : momentField
            ? windowText(field === 'startsAt' ? 'start' : 'end')
            : form.value[field]
      return `${labels[field]}: Keep mine: ${mine}; Take theirs: ${theirs}`
    }),
  ].join('\n')
})

/**
 * The draft of one window edge as its parts show it, in the captured zone.
 *
 * @param edge The edge a conflicting moment field names.
 */
function windowText(edge: 'start' | 'end'): string {
  const parts = windowState.value?.[edge]?.parts ?? []
  const shown = parts.every((part) => part.text === '')
    ? '—'
    : parts
        .map((part) => part.prefix + (part.text || part.placeholder))
        .join('')
  return `${shown} (${timeZone.value})`
}

function componentNames(keys: readonly string[]): string {
  return keys
    .map(
      (key) =>
        data.value?.components.find(
          (component) => component.componentKey === key,
        )?.name ?? key,
    )
    .join(', ')
}

/** Text fields only; the window is edited in its picker. */
function patch(patch: Partial<HilosStatusWorkForm>): void {
  props.controller.patchForm(patch)
}

function toggleComponent(key: string, checked: boolean): void {
  patch({
    componentKeys: checked
      ? [...form.value.componentKeys, key]
      : form.value.componentKeys.filter((value) => value !== key),
  })
}

/** Enter and the footer use the same verdict; success belongs to this request. */
async function submit(): Promise<void> {
  if (!(editor?.canSave.get() ?? creator!.canSubmit.get())) return
  if (await props.controller.submit()) {
    const message = props.controller.success.get()
    if (message !== null) hilosToasts.push(message, { severity: 'success' })
  }
}

/**
 * Enter while the window calendar is open belongs to the calendar; buttons
 * activate themselves.
 *
 * @param event The Enter keydown that reached the form.
 */
function onEnter(event: KeyboardEvent): void {
  if (
    event.target instanceof Element &&
    event.target.closest('textarea, button, a')
  )
    return
  event.preventDefault()
  if (props.controller.windowPicker.get()?.calendar.state.get().open) return
  void submit()
}
</script>

<template>
  <HilosModal
    :model-value="opened"
    :title="title"
    :busy="busy"
    :confirm-on-close="dirty"
    initial-focus="inner"
    @update:model-value="controller.close()"
  >
    <form
      v-if="opened"
      :id="formId"
      :aria-label="title"
      :aria-describedby="hintId"
      data-id="hilos-status-work-form"
      @submit.prevent="submit"
      @keydown.enter="onEnter"
    >
      <HilosFormError
        :message="error"
        :error-type="failure?.errorType"
        :error-detail="failure?.errorDetail"
        :details-title="title"
        :copy-text="copyText"
        data-id="hilos-status-work-error"
        announce
      />
      <div class="mb-2">
        <label :for="`${fieldsId}-title`" class="form-label">Title</label>
        <input
          :id="`${fieldsId}-title`"
          :value="form.title"
          class="form-control form-control-sm"
          data-id="hilos-status-work-title"
          data-autofocus
          @input="patch({ title: ($event.target as HTMLInputElement).value })"
        />
      </div>
      <div class="mb-1">
        <HilosDateTimePicker
          v-if="windowPicker"
          :id="`${fieldsId}-window`"
          :picker="windowPicker"
          :label="`Window, ${timeZone}`"
          :described-by="overlapHintId"
          data-id="hilos-status-work-window"
        />
      </div>
      <div :id="overlapHintId" class="form-text mt-0 mb-2">
        The window cannot overlap another announcement, even if the components
        differ. Simultaneous work is one announcement with several components.
        Adjacent windows are fine: one ends at 07:00, the next starts at 07:00.
      </div>
      <fieldset class="mb-2">
        <legend class="form-label fs-6 mb-1">Affects</legend>
        <div
          v-for="component in data?.components ?? []"
          :key="component.componentKey"
          class="form-check form-check-inline"
        >
          <input
            :id="`${fieldsId}-component-${component.componentKey}`"
            :checked="form.componentKeys.includes(component.componentKey)"
            type="checkbox"
            class="form-check-input"
            :data-id="`hilos-status-work-component-${component.componentKey}`"
            @change="
              toggleComponent(
                component.componentKey,
                ($event.target as HTMLInputElement).checked,
              )
            "
          />
          <label
            :for="`${fieldsId}-component-${component.componentKey}`"
            class="form-check-label"
            >{{ component.name }}</label
          >
        </div>
      </fieldset>
      <div class="mb-2">
        <label :for="`${fieldsId}-description`" class="form-label"
          >What visitors read</label
        >
        <textarea
          :id="`${fieldsId}-description`"
          :value="form.description"
          class="form-control form-control-sm"
          rows="2"
          data-id="hilos-status-work-description"
          @input="
            patch({ description: ($event.target as HTMLTextAreaElement).value })
          "
        ></textarea>
      </div>
      <p :id="hintId" class="small text-body-secondary mb-0">
        This is an advance announcement. It does not close the service; the
        freeze in Maintenance starts the actual work.
      </p>
      <HilosEditNotice
        :kind="noticeKind"
        :text="noticeText"
        data-id="hilos-status-work-notice"
      />
    </form>
    <template #actions="{ requestClose }">
      <ConflictActions
        :conflict="editState?.conflict ?? false"
        :disable-save="!busy && !saveAvailable"
        @save="submit"
        @accept-mine="editor?.keepMine()"
        @accept-theirs="editor?.takeTheirs()"
      >
        <template #cancel-button>
          <button
            type="button"
            class="btn btn-secondary"
            :disabled="busy"
            data-id="hilos-status-work-close"
            @click="requestClose"
          >
            Cancel
          </button>
        </template>
        <template #save-button="{ disabled }">
          <LoadingButton
            :form="formId"
            type="submit"
            class="btn-primary"
            :loading="busy"
            :disabled="disabled"
            data-id="hilos-status-work-save"
            >{{ busy && editor ? 'Save' : saveLabel }}</LoadingButton
          >
        </template>
      </ConflictActions>
    </template>
  </HilosModal>
</template>
