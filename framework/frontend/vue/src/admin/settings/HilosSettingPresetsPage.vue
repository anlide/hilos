<!-- HilosSettingPresetsPage — the framework setting-presets screen: a section's
settings offered as a few named sets instead of a few dozen keys. The layout, the
states and the behavior are here; every phrase is the section's, arriving through
the vocabulary prop, so the screen knows nothing about the settings it is showing
(hilosSettingPresets.ts). A section mounts it with its page key, its context, its
signal name and its vocabulary.

Two gestures, one action: choosing an unapplied mode, and putting the applied
mode's values back when they have drifted. The applied card is never the gesture
itself — with no drift there is nothing to press, and with drift the one button
inside it says out loud what it will do. While the action is in flight every card
is disabled, or two quick clicks would race and the later write would win.

The outcome arrives by push, not as a reply: the backend answers the action with
nothing and sends the new state to every open tab on its next tick. There is no
optimistic drawing — a rewrite of several keys can be refused by the rule on any
one of them. A refusal shows where the person acted (inside the confirmation when
they came through it, above the cards when the click applied at once) and, by the
driver's default, as a toast; a success is silent, because the card lighting up
is the result itself (toasts.md). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSettingPresets,
  createHilosSettingPresetsActions,
  differencesOf,
  hasDifferences,
  isPresetApplied,
  isSelectionUnknown,
  presetsOf,
  resolveHilosPath,
  selectedPresetOf,
  type HilosSettingPreset,
  type HilosSettingPresetsContext,
  type HilosSettingPresetsVocabulary,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The page key the admin shell draws its breadcrumb, heading and lead from. */
  page: string
  /** The project context: the connection the frames arrive on and the action lifecycle. */
  context: HilosSettingPresetsContext
  /** Server→client signal `type` this section's group frame arrives under. */
  signal: string
  /** Everything this section says about its own presets. */
  vocabulary: HilosSettingPresetsVocabulary
}>()

const presets = createHilosSettingPresets(props.context, props.signal)
const { sendSettingPresetApply } = createHilosSettingPresetsActions(
  props.context,
)
const state = useSignal(presets.state)

// Listen for the group frame on mount and stop on unmount; nothing is requested,
// the page sends the first frame ahead of releasing the page.
onMounted(() => presets.start())
onUnmounted(() => presets.dispose())

const applyAction = useTrackedAction()
const { loading: applyLoading, busy: applyBusy, clearError } = applyAction

const cards = computed(() => presetsOf(state.value))
const differences = computed(() => differencesOf(state.value))
const drifted = computed(() => hasDifferences(state.value))
const unknownSelection = computed(() => isSelectionUnknown(state.value))
const settingsPath = computed(() =>
  resolveHilosPath(props.vocabulary.generalSettingsPage),
)

// Confirmation: only a click that would overwrite hand-made edits raises it, and
// what it destroys is shown in a THIRD card — the applied one — so the ruin does
// not happen where the person is looking.
const confirmOpen = ref(false)
const confirmPreset = ref<string | null>(null)
const confirmTitle = computed(() =>
  props.vocabulary.presetTitle(confirmPreset.value ?? ''),
)

/** Whether this card is the applied one, which is what lights it. */
function applied(name: string): boolean {
  return isPresetApplied(state.value, name)
}

/** The frame of a card: lit blue when applied and clean, amber when applied and drifted. */
function cardClass(name: string): string {
  if (!applied(name)) {
    return ''
  }

  return drifted.value
    ? 'border-warning bg-warning-subtle'
    : 'border-primary bg-primary-subtle'
}

/**
 * Choose a mode: at once when nothing of the person's own is at stake, through
 * the confirmation when their hand-made edits would go with it.
 */
function choose(name: string): void {
  clearError()
  if (drifted.value) {
    confirmPreset.value = name
    confirmOpen.value = true

    return
  }
  void apply(name)
}

/** Send the apply and, when it was confirmed, close the confirmation on success. */
async function apply(name: string): Promise<void> {
  const applyOk = await applyAction.run(sendSettingPresetApply(name))
  if (applyOk) {
    confirmOpen.value = false
    confirmPreset.value = null
  }
}

/**
 * Close the confirmation, taking the refusal it raised with it: the alert over the
 * cards is for a click that applied at once, and a failure left behind would surface
 * there, far from where it was answered.
 */
function closeConfirm(): void {
  confirmOpen.value = false
  confirmPreset.value = null
  clearError()
}

/** Confirm the overwrite the person was warned about. */
function confirmApply(): void {
  if (confirmPreset.value !== null) {
    void apply(confirmPreset.value)
  }
}

/** Put the applied mode's values back, which needs no second question. */
function revert(): void {
  const selected = selectedPresetOf(state.value)
  if (selected !== null) {
    clearError()
    void apply(selected)
  }
}

/** The lines a card lists, read out of the values the preset declares. */
function valueLines(preset: HilosSettingPreset): string[] {
  return props.vocabulary.valueLines(preset.values)
}
</script>

<template>
  <HilosAdminPage :page="page">
    <p class="text-body-secondary">{{ vocabulary.intro }}</p>

    <h2 class="h6 mb-2">{{ vocabulary.groupHeading }}</h2>

    <p
      v-if="unknownSelection"
      class="alert alert-warning py-2 small"
      data-id="hilos-setting-preset-unknown"
    >
      {{ vocabulary.unknownSelectionNote }}
    </p>

    <HilosActionError v-if="!confirmOpen" :action="applyAction" />

    <div class="row row-cols-1 row-cols-md-3 g-3 mb-2">
      <div v-for="preset in cards" :key="preset.name" class="col">
        <div
          class="h-100 border rounded-3 d-flex flex-column"
          :class="cardClass(preset.name)"
        >
          <button
            type="button"
            class="btn text-start border-0 rounded-0 rounded-top-3 p-3 flex-grow-1"
            :disabled="applyBusy || applied(preset.name)"
            :aria-current="applied(preset.name) ? 'true' : undefined"
            :data-id="`hilos-setting-preset-${preset.name}`"
            @click="choose(preset.name)"
          >
            <span class="d-flex align-items-center gap-2 mb-1">
              <i class="bi" :class="vocabulary.presetIcon(preset.name)"></i>
              <span class="fw-semibold small flex-grow-1">
                {{ vocabulary.presetTitle(preset.name) }}
              </span>
              <i
                v-if="applied(preset.name)"
                class="bi bi-check-circle-fill"
                :class="drifted ? 'text-warning' : 'text-primary'"
                aria-hidden="true"
              ></i>
            </span>
            <span class="d-block small text-body-secondary mb-2">
              {{ vocabulary.presetSubtitle(preset.name) }}
            </span>
            <span
              v-for="line in valueLines(preset)"
              :key="line"
              class="d-block small text-body-secondary"
            >
              {{ line }}
            </span>
          </button>

          <div
            v-if="applied(preset.name) && drifted"
            class="border-top p-3"
            data-id="hilos-setting-preset-differences"
          >
            <div class="small fw-semibold text-warning-emphasis mb-1">
              {{ vocabulary.differencesHeading }}
            </div>
            <ul class="small text-warning-emphasis mb-2 ps-3">
              <li v-for="difference in differences" :key="difference.key">
                {{ vocabulary.differenceLine(difference) }}
              </li>
            </ul>
            <LoadingButton
              class="btn-sm btn-outline-secondary w-100"
              :loading="applyLoading"
              :disabled="applyBusy"
              data-id="hilos-setting-preset-revert"
              @click="revert"
            >
              {{ vocabulary.revertLabel }}
            </LoadingButton>
          </div>
        </div>
      </div>
    </div>

    <p class="small text-body-secondary">{{ vocabulary.footnote }}</p>

    <div
      class="d-flex flex-wrap align-items-center gap-3 border rounded-3 p-3 mt-4"
    >
      <i class="bi bi-sliders2 text-body-secondary" aria-hidden="true"></i>
      <div class="flex-grow-1">
        <div class="fw-semibold small">
          {{ vocabulary.generalSettingsTitle }}
        </div>
        <div class="small text-body-secondary">
          {{ vocabulary.generalSettingsLead }}
        </div>
      </div>
      <HilosLink
        :to="settingsPath"
        class="btn btn-sm btn-outline-secondary text-nowrap"
        data-id="hilos-setting-preset-settings-link"
      >
        {{ vocabulary.generalSettingsLabel }}
        <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
      </HilosLink>
    </div>

    <HilosModal
      v-model="confirmOpen"
      :title="vocabulary.confirmTitle"
      :close-on-backdrop="!applyBusy"
      :close-on-esc="!applyBusy"
      @cancel="closeConfirm"
    >
      <HilosActionError :action="applyAction" />
      <p class="mb-0 text-body-secondary">
        {{ vocabulary.confirmBody(confirmTitle) }}
      </p>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="applyBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="applyLoading"
          data-id="hilos-setting-preset-apply-confirm"
          @click="confirmApply"
        >
          {{ vocabulary.confirmLabel(confirmTitle) }}
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
