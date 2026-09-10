<!-- HilosSupportModal — the dialog behind the one button of the support block on
/about: pick a tier, press Subscribe, and get told the truth. The tiers and the
sentence are core data (@hilos/core, public/supportTiers.ts); the rest of the
words are markup here, the way every other SDK page carries its text.

Nothing is sent. The refusal is a fixed sentence this surface carries, and that
is the point rather than a shortcut: a signal, a payload or an error class would
hand the payment ticket a protocol nobody designed. For the same reason
HilosActionError is not used even though the plate copies its shape — that
component draws the failure of a TrackedAction, and faking one with no request
behind it would put a lie in the one place the framework tells the truth about
failures. No toast either: nothing reached the server, so there is no outcome to
report to a corner, and the person is looking at this dialog.

The plate belongs to the ATTEMPT that raised it: choosing another tier re-arms
the dialog, and closing it starts the next visit clean — the same rule the
framework already keeps for a failure panel.

Not exported from the package: what a project mounts is the page. The block has
exactly one home, the end of About, and a modal in the SDK's public surface would
be a component projects are invited to reuse. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  HILOS_SUPPORT_DEFAULT_TIER,
  HILOS_SUPPORT_REFUSAL,
  HILOS_SUPPORT_TIERS,
  type HilosSupportTierKey,
} from '@hilos/core'

import HilosModal from '../HilosModal.vue'

const props = defineProps<{
  /** Whether the dialog is open (v-model). */
  modelValue: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [open: boolean]
}>()

/** The tier the person is subscribing at; never empty, so Subscribe is never dead. */
const selected = ref<HilosSupportTierKey>(HILOS_SUPPORT_DEFAULT_TIER)

/** Whether the honest refusal is on screen — the answer to the last attempt. */
const refused = ref(false)

// A reopened dialog is a new visit: the tier goes back to the drawn default and
// the refusal goes with it. The state is the dialog's, not the page's.
watch(
  () => props.modelValue,
  () => {
    selected.value = HILOS_SUPPORT_DEFAULT_TIER
    refused.value = false
  },
)

/**
 * What the screen reader is told, and nothing while there is nothing to tell.
 *
 * The sentence is announced from a region that stands in the dialog before it
 * has anything to say — a role arriving together with its text is not announced
 * at all (accessibility.md) — and the visible plate below carries no role.
 */
const announced = computed(() => (refused.value ? HILOS_SUPPORT_REFUSAL : ''))

/**
 * Take a tier and re-arm the dialog.
 *
 * @param key The tier the person pressed.
 */
function pick(key: HilosSupportTierKey): void {
  selected.value = key
  refused.value = false
}
</script>

<template>
  <HilosModal
    :model-value="modelValue"
    title="Support the project"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <!-- The refusal is announced from here and not from the plate that shows
    it. The region lives inside the dialog because the dialog is aria-modal,
    which hides the page under it from a screen reader. -->
    <div
      class="visually-hidden"
      role="alert"
      aria-live="assertive"
      data-id="hilos-about-live-assertive"
    >
      {{ announced }}
    </div>

    <p class="small text-body-secondary mb-3">
      Pick what you can spare. The money goes to servers and coffee.
    </p>

    <div class="d-grid gap-2 mb-3" role="group" aria-label="Support tier">
      <button
        v-for="tier in HILOS_SUPPORT_TIERS"
        :key="tier.key"
        type="button"
        class="btn btn-outline-secondary text-start d-flex align-items-center gap-3"
        :class="{ 'border-primary': selected === tier.key }"
        :aria-pressed="selected === tier.key"
        :data-id="`hilos-about-tier-${tier.key}`"
        @click="pick(tier.key)"
      >
        <i :class="`bi ${tier.icon} fs-5`" aria-hidden="true"></i>
        <span class="flex-grow-1">
          <span class="d-block fw-semibold small"
            >{{ tier.label }} · {{ tier.price }}</span
          >
          <span class="d-block small text-body-secondary">{{
            tier.blurb
          }}</span>
        </span>
      </button>
    </div>

    <!-- The shape HilosActionError draws a server refusal in, written here
    because that component takes a TrackedAction and there is none. Pressing
    Subscribe again re-states the same sentence rather than stacking a second
    plate. -->
    <div
      v-if="refused"
      class="alert alert-danger d-flex align-items-center gap-2 py-2"
      data-id="hilos-about-refusal"
    >
      <i class="bi bi-exclamation-circle flex-shrink-0" aria-hidden="true"></i>
      <span class="flex-grow-1">{{ HILOS_SUPPORT_REFUSAL }}</span>
    </div>

    <div class="alert alert-secondary small py-2 mb-0">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
      A subscription unlocks nothing: every feature is open to everyone anyway.
    </div>

    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-outline-secondary"
        data-id="hilos-about-cancel"
        @click="requestClose"
      >
        Cancel
      </button>
      <button
        type="button"
        class="btn btn-primary"
        data-id="hilos-about-subscribe"
        @click="refused = true"
      >
        Subscribe
      </button>
    </template>
  </HilosModal>
</template>
