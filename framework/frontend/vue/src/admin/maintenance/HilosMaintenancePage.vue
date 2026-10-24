<!-- Maintenance Overview: the live protected-mode state and manual enable,
disable and code dialogs, under the shared Maintenance section head. Each action
waits for its tracked answer independently of live state pushes. -->
<script setup lang="ts">
import {
  ActionError,
  formatMoment,
  HilosPages,
  hilosTimeZone,
} from '@hilos/core/base'
import {
  createHilosMaintenanceActions,
  createHilosMaintenanceState,
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_PASS_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  hilosMaintenanceCard,
  hilosMaintenancePosition,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosMaintenanceContext
}>()

const stateHandle = createHilosMaintenanceState(props.context)
const maintenanceState = useSignal(stateHandle.state)
const timeZone = useSignal(hilosTimeZone)
const nowMs = ref(Date.now())
const position = computed(() =>
  hilosMaintenancePosition(maintenanceState.value),
)
const stateCard = computed(() =>
  hilosMaintenanceCard(maintenanceState.value, nowMs.value),
)
const startedAt = computed(() => {
  void timeZone.value
  return (
    formatMoment(stateCard.value?.startedAt ?? '', { kind: 'dateTime' }) ?? '—'
  )
})
let clockTick: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  stateHandle.start()
  clockTick = setInterval(() => {
    nowMs.value = Date.now()
  }, 30_000)
})
onUnmounted(() => {
  stateHandle.dispose()
  clearInterval(clockTick)
})

const { sendMaintenanceEnable, sendMaintenanceDisable, sendMaintenancePass } =
  createHilosMaintenanceActions(props.context)

// A state push may replace the open banner while this action still awaits its ack.
const enableOpen = ref(false)
const enableAction = useTrackedAction({
  describeError: (error) =>
    error instanceof ActionError && error.outcome === 'fail'
      ? error.message
      : HILOS_MAINTENANCE_ENABLE_COPY.unconfirmedNote,
})
const { busy: enableBusy } = enableAction

function openEnable(): void {
  if (enableBusy.value || position.value !== 'open') return
  enableAction.clearError()
  enableOpen.value = true
}

function closeEnable(): void {
  // The controlled model guards the header's close button as well as Cancel.
  if (!enableBusy.value) enableOpen.value = false
}

async function submitEnable(): Promise<void> {
  if (enableBusy.value || position.value !== 'open') return
  if (await enableAction.run(sendMaintenanceEnable())) closeEnable()
}

// The dialog survives a state push; the shared lift may reload before its ack arrives.
const disableOpen = ref(false)
const disableAction = useTrackedAction({
  describeError: (error) =>
    error instanceof ActionError && error.outcome === 'fail'
      ? error.message
      : HILOS_MAINTENANCE_DISABLE_COPY.unconfirmedNote,
})
const { busy: disableBusy } = disableAction

function openDisable(): void {
  if (disableBusy.value || position.value !== 'manual') return
  disableAction.clearError()
  disableOpen.value = true
}

function closeDisable(): void {
  if (!disableBusy.value) disableOpen.value = false
}

async function submitDisable(): Promise<void> {
  if (disableBusy.value || position.value !== 'manual') return
  if (await disableAction.run(sendMaintenanceDisable())) closeDisable()
}

// The clear code belongs only to this dialog's result and is forgotten on every exit.
const passOpen = ref(false)
const pass = ref<string | null>(null)
const passStep = ref<'confirm' | 'result'>('confirm')
const passValue = ref<HTMLElement>()
let passDisposed = false
const passAction = useTrackedAction({
  describeError: (error) =>
    error instanceof ActionError && error.outcome === 'fail'
      ? error.message
      : HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote,
})
const { busy: passBusy } = passAction

function openPass(): void {
  if (passDisposed || passBusy.value || position.value !== 'manual') return
  pass.value = null
  passStep.value = 'confirm'
  passAction.clearError()
  passOpen.value = true
}

function closePass(): void {
  if (passBusy.value) return
  passOpen.value = false
  pass.value = null
  passStep.value = 'confirm'
}

async function submitPass(): Promise<void> {
  if (
    passDisposed ||
    !passOpen.value ||
    passBusy.value ||
    passStep.value !== 'confirm' ||
    position.value !== 'manual'
  )
    return
  const handle = sendMaintenancePass()
  if (await passAction.run(handle)) {
    const result = await handle.done
    if (passDisposed || !passOpen.value) return
    pass.value = result.reply!.pass
    passStep.value = 'result'
    await nextTick()
    passValue.value?.focus()
  }
}

onUnmounted(() => {
  passDisposed = true
  pass.value = null
  passOpen.value = false
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.MAINTENANCE"
    head-kind="section"
    tabs-label="Maintenance pages"
  >
    <div aria-live="polite" data-id="hilos-maintenance-state">
      <div
        v-if="position === 'open'"
        class="alert alert-success d-flex flex-column flex-sm-row gap-3 align-items-start mb-3"
        data-id="hilos-maintenance-state-open"
      >
        <div class="d-flex gap-3 flex-grow-1">
          <i class="bi bi-check-circle-fill fs-5" aria-hidden="true"></i>
          <div>
            <h2 class="h6 fw-semibold mb-1">
              {{ HILOS_MAINTENANCE_STATE_COPY.openTitle }}
            </h2>
            <p class="small mb-0">
              {{ HILOS_MAINTENANCE_STATE_COPY.openDescription }}
            </p>
          </div>
        </div>
        <LoadingButton
          class="btn-warning btn-sm text-nowrap"
          :disabled="enableBusy"
          data-id="hilos-maintenance-enable"
          @click="openEnable"
        >
          {{ HILOS_MAINTENANCE_ENABLE_COPY.enableTitle }}
        </LoadingButton>
      </div>

      <section
        v-else-if="stateCard"
        class="card border-warning mb-3"
        :data-id="`hilos-maintenance-state-${stateCard.position}`"
        :aria-label="stateCard.title"
      >
        <div
          class="card-header d-flex flex-wrap align-items-center gap-2 bg-warning-subtle"
        >
          <h2 class="h6 fw-semibold mb-0">{{ stateCard.title }}</h2>
          <span class="badge text-bg-warning">{{ stateCard.phase }}</span>
          <span class="small text-body-secondary ms-auto">
            {{ HILOS_MAINTENANCE_STATE_COPY.operationPrefix }}
            <code>{{ stateCard.operation ?? '—' }}</code>
          </span>
        </div>
        <div class="card-body">
          <dl class="row mb-0">
            <dt class="col-sm-3">{{ HILOS_MAINTENANCE_STATE_COPY.phase }}</dt>
            <dd class="col-sm-9">
              <span class="badge text-bg-warning">{{ stateCard.phase }}</span>
              <span
                v-if="stateCard.phaseNote"
                class="text-body-secondary ms-2"
                >{{ stateCard.phaseNote }}</span
              >
            </dd>

            <template v-if="stateCard.position === 'manual'">
              <dt class="col-sm-3">
                {{ HILOS_MAINTENANCE_STATE_COPY.closedSince }}
              </dt>
              <dd class="col-sm-9">
                <code data-id="hilos-maintenance-started-at">{{
                  startedAt
                }}</code>
                <span class="text-body-secondary ms-2">{{
                  stateCard.elapsed
                }}</span>
              </dd>
              <dt class="col-sm-3">
                {{ HILOS_MAINTENANCE_STATE_COPY.turnedOn }}
              </dt>
              <dd class="col-sm-9">{{ stateCard.turnedOn }}</dd>
            </template>
            <template v-else>
              <dt class="col-sm-3">
                {{ HILOS_MAINTENANCE_STATE_COPY.operation }}
              </dt>
              <dd class="col-sm-9">
                <code>{{ stateCard.operation ?? '—' }}</code>
              </dd>
              <dt class="col-sm-3">
                {{ HILOS_MAINTENANCE_STATE_COPY.started }}
              </dt>
              <dd class="col-sm-9">
                <code data-id="hilos-maintenance-started-at">{{
                  startedAt
                }}</code>
              </dd>
            </template>

            <dt class="col-sm-3">
              {{ HILOS_MAINTENANCE_STATE_COPY.codesInHand }}
            </dt>
            <dd class="col-sm-9" data-id="hilos-maintenance-pass-count">
              {{ stateCard.passCount }}
            </dd>
            <dt class="col-sm-3">{{ HILOS_MAINTENANCE_STATE_COPY.circle }}</dt>
            <dd class="col-sm-9">{{ stateCard.circle }}</dd>
          </dl>
          <div
            v-if="stateCard.position === 'manual'"
            class="hilos-button-row mt-3"
          >
            <LoadingButton
              class="btn-outline-primary btn-sm"
              :loading="passBusy"
              data-id="hilos-maintenance-pass"
              @click="openPass"
            >
              {{ HILOS_MAINTENANCE_PASS_COPY.issue }}
            </LoadingButton>
            <LoadingButton
              class="btn-warning btn-sm"
              :loading="disableBusy"
              data-id="hilos-maintenance-disable"
              @click="openDisable"
            >
              {{ HILOS_MAINTENANCE_DISABLE_COPY.disableTitle }}
            </LoadingButton>
          </div>
        </div>
      </section>
    </div>

    <HilosModal
      :model-value="enableOpen"
      :title="HILOS_MAINTENANCE_ENABLE_COPY.enableTitle"
      :busy="enableBusy"
      initial-focus="dialog"
      @update:model-value="closeEnable"
    >
      <div data-id="hilos-maintenance-enable-modal">
        <HilosActionError
          :action="enableAction"
          :details-title="HILOS_MAINTENANCE_ENABLE_COPY.refusalTitle"
        />
        <p class="mb-2">{{ HILOS_MAINTENANCE_ENABLE_COPY.lead }}</p>
        <ul class="mb-2 ps-3">
          <li>
            <strong>{{ HILOS_MAINTENANCE_ENABLE_COPY.outside }}</strong>
            — {{ HILOS_MAINTENANCE_ENABLE_COPY.outsideNote }}
          </li>
          <li>
            <strong>{{ HILOS_MAINTENANCE_ENABLE_COPY.inside }}</strong>
            — {{ HILOS_MAINTENANCE_ENABLE_COPY.insideNote }}
          </li>
          <li>
            <strong>{{ HILOS_MAINTENANCE_ENABLE_COPY.agents }}</strong>
            — {{ HILOS_MAINTENANCE_ENABLE_COPY.agentsNote }}
          </li>
        </ul>
        <p class="mb-0 text-body-secondary">
          {{ HILOS_MAINTENANCE_ENABLE_COPY.footer }}
        </p>
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="enableBusy"
          data-id="hilos-maintenance-enable-cancel"
          @click="requestClose"
        >
          {{ HILOS_MAINTENANCE_ENABLE_COPY.cancel }}
        </button>
        <LoadingButton
          class="btn-warning"
          :loading="enableBusy"
          :disabled="position !== 'open'"
          data-id="hilos-maintenance-enable-confirm"
          @click="submitEnable"
        >
          {{ HILOS_MAINTENANCE_ENABLE_COPY.confirm }}
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      :model-value="disableOpen"
      :title="HILOS_MAINTENANCE_DISABLE_COPY.disableTitle"
      :busy="disableBusy"
      initial-focus="dialog"
      @update:model-value="closeDisable"
    >
      <div data-id="hilos-maintenance-disable-modal">
        <HilosActionError
          :action="disableAction"
          :details-title="HILOS_MAINTENANCE_DISABLE_COPY.refusalTitle"
        />
        <p class="mb-2">{{ HILOS_MAINTENANCE_DISABLE_COPY.lead }}</p>
        <p class="mb-0 text-body-secondary">
          {{ HILOS_MAINTENANCE_DISABLE_COPY.footer }}
        </p>
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="disableBusy"
          data-id="hilos-maintenance-disable-cancel"
          @click="requestClose"
        >
          {{ HILOS_MAINTENANCE_DISABLE_COPY.cancel }}
        </button>
        <LoadingButton
          class="btn-warning"
          :loading="disableBusy"
          :disabled="position !== 'manual'"
          data-id="hilos-maintenance-disable-confirm"
          @click="submitDisable"
        >
          {{ HILOS_MAINTENANCE_DISABLE_COPY.confirm }}
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      :model-value="passOpen"
      :title="
        passStep === 'confirm'
          ? HILOS_MAINTENANCE_PASS_COPY.mintTitle
          : HILOS_MAINTENANCE_PASS_COPY.codeTitle
      "
      :busy="passBusy"
      :copy-text="pass ?? ''"
      initial-focus="dialog"
      @update:model-value="closePass"
    >
      <div data-id="hilos-maintenance-pass-modal">
        <template v-if="passStep === 'confirm'">
          <HilosActionError
            :action="passAction"
            :details-title="HILOS_MAINTENANCE_PASS_COPY.refusalTitle"
          />
          <p class="mb-2">{{ HILOS_MAINTENANCE_PASS_COPY.lead }}</p>
          <p class="mb-0 text-body-secondary">
            {{ HILOS_MAINTENANCE_PASS_COPY.reuseNote }}
          </p>
        </template>
        <div v-else data-id="hilos-maintenance-pass-result">
          <p class="mb-2 text-body-secondary">
            {{ HILOS_MAINTENANCE_PASS_COPY.resultLead }}
          </p>
          <div
            ref="passValue"
            tabindex="-1"
            class="border rounded-3 p-3 bg-body-tertiary text-center user-select-all text-break"
            data-id="hilos-maintenance-pass-value"
          >
            <code class="fs-5">{{ pass }}</code>
          </div>
          <p class="small text-body-secondary mt-2 mb-0">
            {{ HILOS_MAINTENANCE_PASS_COPY.resultNote }}
          </p>
        </div>
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="passBusy"
          data-id="hilos-maintenance-pass-cancel"
          @click="requestClose"
        >
          {{
            passStep === 'confirm'
              ? HILOS_MAINTENANCE_PASS_COPY.cancel
              : HILOS_MAINTENANCE_PASS_COPY.close
          }}
        </button>
        <LoadingButton
          v-if="passStep === 'confirm'"
          class="btn-primary"
          :loading="passBusy"
          :disabled="position !== 'manual'"
          data-id="hilos-maintenance-pass-confirm"
          @click="submitPass"
        >
          {{ HILOS_MAINTENANCE_PASS_COPY.mint }}
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
