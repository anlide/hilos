<!-- The server's effective frame supplies the preview; only the core editor
owns the draft and its merge. Frame HTML never enters the admin document. -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core/base'
import {
  createHilosChannelFrame,
  createHilosChannelFrameEdit,
  createHilosChannelSummary,
  createHilosCommunicationsActions,
  LETTER_FRAME_FIELD,
  type HilosCommunicationsContext,
} from '@hilos/core/admin/communications'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import HilosCommunicationsChannelHead from './HilosCommunicationsChannelHead.vue'

const props = defineProps<{ context: HilosCommunicationsContext }>()
const summary = createHilosChannelSummary(props.context)
const source = createHilosChannelFrame(props.context)
const frame = useSignal(source)
const actions = createHilosCommunicationsActions(props.context)
const editor = createHilosChannelFrameEdit(source, actions)
onMounted(editor.start)
onUnmounted(editor.dispose)
const opened = useSignal(editor.opened)
const editOpen = computed({
  get: () => opened.value,
  set: (next: boolean) => {
    if (!next) editor.close()
  },
})
const form = useSignal(editor.form)
const live = useSignal(editor.state)
const notice = useSignal(editor.noticeText)
const saveLabel = useSignal(editor.saveLabel)
const canSave = useSignal(editor.saveAvailable)
const saving = useSignal(editor.saving)
const html = computed({
  get: () => form.value.html,
  set: (value: string) => editor.patchForm({ html: value }),
})
const edit = useTrackedAction()
const reset = useTrackedAction()
const resetOpen = ref(false)
const resetGone = computed(() => frame.value?.source !== 'settings')
const sourceLabel = computed(() =>
  frame.value
    ? { settings: 'Override', project: 'Project', default: 'Default' }[
        frame.value.source
      ]
    : '',
)
const incomingHtml = computed(() => live.value.fields.html.incoming)
function openEdit(): void {
  edit.clearError()
  editor.open()
}
function submitEdit(): void {
  void editor.save(edit.run)
}
function openReset(): void {
  reset.clearError()
  resetOpen.value = true
}
async function submitReset(): Promise<void> {
  if (reset.busy.value || resetGone.value) return
  if (await reset.run(actions.sendChannelReset('email', LETTER_FRAME_FIELD)))
    resetOpen.value = false
}
</script>

<template>
  <HilosCommunicationsChannelHead
    :page="HilosPages.COMMUNICATIONS_CHANNEL_FRAME"
    :scopes="context.scopes"
    :summary="summary"
  >
    <section v-if="frame" class="card" data-id="hilos-channel-frame">
      <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <div class="flex-grow-1">
          <h2 class="h6 mb-1">Letter frame</h2>
          <div class="small text-body-secondary">
            Header, footer and styles around every letter
          </div>
        </div>
        <span
          class="badge text-bg-secondary-subtle text-secondary-emphasis"
          data-id="hilos-channel-frame-source"
          >{{ sourceLabel }}</span
        >
        <div class="d-flex gap-1">
          <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            title="Edit frame"
            aria-label="Edit frame"
            data-id="hilos-channel-frame-edit"
            @click="openEdit"
          >
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            title="Reset frame"
            aria-label="Reset frame"
            :disabled="resetGone"
            data-id="hilos-channel-frame-reset"
            @click="openReset"
          >
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
          </button>
        </div>
      </div>
      <div class="card-body">
        <div class="rounded-3 p-3 p-sm-4 bg-body-tertiary">
          <HilosHideable :value="frame.previewHtml">
            <template #default="{ value }">
              <iframe
                class="w-100 border rounded-3 bg-body"
                sandbox=""
                title="Letter frame preview"
                :srcdoc="value"
                data-id="hilos-channel-frame-preview"
              ></iframe>
            </template>
          </HilosHideable>
        </div>
        <div class="small text-body-secondary mt-2">
          Preview with <code>auth.password_reset</code> in English. Plain text
          goes without the frame.
        </div>
      </div>
    </section>
    <HilosModal
      v-model="editOpen"
      size="wide"
      aria-label="Edit · Letter frame"
      :copy-text="form.html"
      :confirm-on-close="live.dirty"
      :busy="saving"
      @cancel="editor.close()"
    >
      <template #header
        ><ConflictHeader title="Edit · Letter frame" :conflict="live.conflict"
      /></template>
      <HilosActionError
        :action="edit"
        details-title="Couldn't save the frame"
      />
      <form @submit.prevent="submitEdit">
        <label class="form-label" for="hilos-channel-frame-html"
          >Frame HTML</label
        >
        <HilosHiddenMark v-if="frame && typeof frame.html !== 'string'" />
        <textarea
          v-else
          id="hilos-channel-frame-html"
          v-model="html"
          :disabled="saving"
          class="form-control font-monospace small"
          rows="14"
          data-id="hilos-channel-frame-html"
          data-autofocus
        ></textarea>
        <HilosEditNotice
          :kind="live.notice?.kind ?? null"
          :text="notice"
          data-id="hilos-channel-frame-notice"
        />
        <div
          v-if="live.conflict"
          class="mt-2"
          data-id="hilos-channel-frame-incoming"
        >
          <div class="small text-body-secondary">Changed elsewhere</div>
          <HilosHideable :value="incomingHtml"
            ><template #default="{ value }">
              <pre class="small text-wrap text-break">{{ value }}</pre>
            </template></HilosHideable
          >
        </div>
        <div class="small mt-2">
          <div class="text-body-secondary mb-1">Placeholders</div>
          <ul class="list-unstyled mb-0">
            <li>
              <code>[[:content]]</code> — the letter itself — required, once
            </li>
            <li><code>[[:lang]]</code> — reader's language</li>
            <li>
              <code>[[:dir]]</code> — ltr or rtl, by the reader's language
            </li>
          </ul>
          <div class="form-text">
            Any other name, or no <code>[[:content]]</code>, is refused on save.
            The frame carries no words of its own: every sentence belongs to a
            template.
          </div>
        </div>
      </form>
      <template #actions="{ requestClose }">
        <ConflictActions
          :conflict="live.conflict"
          :disable-save="!canSave"
          :save-label="saveLabel"
          @save="submitEdit"
          @accept-mine="editor.keepMine()"
          @accept-theirs="editor.takeTheirs()"
        >
          <template #cancel-button
            ><button
              type="button"
              class="btn btn-secondary"
              :disabled="saving"
              data-id="hilos-channel-frame-edit-cancel"
              @click="requestClose"
            >
              Cancel
            </button></template
          >
          <template #save-button="{ disabled, onSave }"
            ><LoadingButton
              class="btn-primary"
              :loading="edit.busy.value"
              :disabled="disabled"
              data-id="hilos-channel-frame-save"
              @click="onSave"
              >{{ saveLabel }}</LoadingButton
            ></template
          >
        </ConflictActions>
      </template>
    </HilosModal>
    <HilosModal
      v-model="resetOpen"
      title="Reset · Letter frame"
      :busy="reset.busy.value"
      initial-focus="dialog"
    >
      <HilosActionError
        :action="reset"
        details-title="Couldn't reset the frame"
      />
      <dl class="row mb-0">
        <dt class="col-4">Now</dt>
        <dd class="col-8 text-break" data-id="hilos-channel-frame-reset-now">
          <span
            class="badge text-bg-secondary-subtle text-secondary-emphasis"
            >{{ sourceLabel }}</span
          >
        </dd>
        <dt class="col-4">Back to</dt>
        <dd class="col-8 mb-0" data-id="hilos-channel-frame-reset-back-to">
          the project frame, or the framework frame when the project has none
        </dd>
      </dl>
      <HilosEditNotice
        :kind="resetGone ? 'updated' : null"
        text="Already reset elsewhere."
        data-id="hilos-channel-frame-reset-state"
      />
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="reset.busy.value"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="reset.busy.value"
          :disabled="resetGone"
          data-id="hilos-channel-frame-reset-confirm"
          @click="submitReset"
          >Reset</LoadingButton
        >
      </template>
    </HilosModal>
  </HilosCommunicationsChannelHead>
</template>
