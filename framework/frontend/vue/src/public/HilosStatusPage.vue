<!-- Public Status reads files even before handshake. The mount also owns one
subscription controller; its actions never delay or replace the file reader. -->
<script setup lang="ts">
import {
  formatMoment,
  hilosTimeZone,
  type ActionLifecycle,
  type HilosConnection,
} from '@hilos/core/base'
import {
  createPublicStatus,
  createStatusSubscription,
  STATUS_SUBSCRIPTION_COPY,
  type PublicStatusDay,
  type PublicStatusDayStrip,
  type PublicStatusState,
} from '@hilos/core/status'
import { computed, onMounted, onUnmounted, ref, useId } from 'vue'

import HilosFormError from '../HilosFormError.vue'
import HilosStaticPage from '../HilosStaticPage.vue'
import { useSignal } from '../useSignal.js'
import HilosStatusSubscriptionModal from './HilosStatusSubscriptionModal.vue'

const props = defineProps<{
  /** The project's connection singleton; no session answer is required. */
  connection: HilosConnection
  /** The project's existing lifecycle for correlated public actions. */
  actions: ActionLifecycle
}>()

const reader = createPublicStatus(props.connection)
const subscription = createStatusSubscription({
  connection: props.connection,
  actions: props.actions,
})
const subscriptionUsable = useSignal(subscription.usable)
const subscriptionOperation = useSignal(subscription.operation)
const subscriptionDialog = useSignal(subscription.dialog)
const subscriptionStep = useSignal(subscription.step)
const subscriptionError = useSignal(subscription.error)
const subscriptionSuccess = useSignal(subscription.success)
const subscriptionProgress = computed(() =>
  subscriptionStep.value === 'waiting'
    ? STATUS_SUBSCRIPTION_COPY.waiting
    : subscriptionStep.value !== 'pending'
      ? ''
      : subscriptionOperation.value === 'confirm'
        ? 'Confirming your subscription…'
        : 'Waiting for confirmation…',
)
// A confirmed address is the one success; a sent link and an ended
// subscription are neutral news. The mailed link gets the envelope.
const subscriptionNoticeClass = computed(() =>
  subscriptionSuccess.value !== null &&
  subscriptionOperation.value === 'confirm'
    ? 'alert-success'
    : 'alert-secondary',
)
const subscriptionNoticeIcon = computed(() =>
  subscriptionSuccess.value === null
    ? null
    : subscriptionOperation.value === 'subscribe'
      ? 'bi-envelope'
      : subscriptionOperation.value === 'confirm'
        ? 'bi-check-circle'
        : null,
)
const projection = useSignal(reader.projection)
const freshness = useSignal(reader.freshness)
const banner = useSignal(reader.banner)
const connectionMessage = useSignal(reader.connectionMessage)
const components = useSignal(reader.components)
const currentOutages = useSignal(reader.currentOutages)
const history = useSignal(reader.history)
const upcomingWorks = useSignal(reader.upcomingWorks)
const currentMaintenance = useSignal(reader.currentMaintenance)
const dayStrips = useSignal(reader.dayStrips)
const timeZone = useSignal(hilosTimeZone)
const detailId = useId()
const selectedDay = ref<{ componentKey: string; startTs: number } | null>(null)

// Presentation only: precedence, freshness, calendar and interval facts belong
// to the core controller. The same words accompany badges and colored days.
const states: Record<
  PublicStatusState | 'not_responding',
  { label: string; tone: string; icon: string }
> = {
  operational: { label: 'Operational', tone: 'success', icon: 'check-circle' },
  checking: { label: 'Checking', tone: 'secondary', icon: 'search' },
  partial_outage: {
    label: 'Partial outage',
    tone: 'warning',
    icon: 'exclamation-triangle',
  },
  major_outage: { label: 'Major outage', tone: 'danger', icon: 'x-circle' },
  maintenance: { label: 'Maintenance', tone: 'info', icon: 'tools' },
  unknown: { label: 'Unknown', tone: 'secondary', icon: 'question-circle' },
  not_responding: {
    label: 'Not responding',
    tone: 'danger',
    icon: 'exclamation-triangle',
  },
}
const candidates = computed(() =>
  freshness.value.kind === 'fresh' ? (projection.value?.candidates ?? []) : [],
)
const announcementLabels = {
  announced: 'Announced in advance',
  unannounced: 'Not announced in advance',
  unknown: 'Advance announcement unknown',
}
const lastUpdate = computed(() =>
  freshness.value.aliveAt === null ? null : moment(freshness.value.aliveAt),
)

function moment(at: number): string {
  void timeZone.value
  return formatMoment(at) ?? '—'
}

function dayLabel(day: PublicStatusDay): string {
  void timeZone.value
  return `${formatMoment(day.startTs, { kind: 'date' }) ?? '—'} · ${states[day.state].label}`
}

function dayDetail(strip: PublicStatusDayStrip): string {
  const selected = selectedDay.value
  const day =
    (selected?.componentKey === strip.componentKey
      ? strip.days.find((item) => item.startTs === selected.startTs)
      : undefined) ?? strip.days.at(-1)
  return day === undefined ? '' : dayLabel(day)
}

function componentName(key: string): string {
  return (
    components.value.find((component) => component.componentKey === key)
      ?.name ?? key
  )
}

onMounted(() => {
  subscription.start()
  reader.start()
})
onUnmounted(() => {
  subscription.dispose()
  reader.dispose()
})
</script>

<template>
  <HilosStaticPage title="System status" icon="bi-activity">
    <div class="hilos-stack mb-3">
      <div
        class="invisible d-flex justify-content-end"
        aria-hidden="true"
        inert
      >
        <span class="btn btn-sm btn-outline-primary">
          <i class="bi bi-envelope me-1" aria-hidden="true"></i>
          {{ STATUS_SUBSCRIPTION_COPY.subscribe }}
        </span>
      </div>
      <div v-if="subscriptionUsable" class="d-flex justify-content-end">
        <button
          type="button"
          class="btn btn-sm btn-outline-primary"
          data-id="public-status-subscribe"
          @click="subscription.open()"
        >
          <i class="bi bi-envelope me-1" aria-hidden="true"></i>
          {{ STATUS_SUBSCRIPTION_COPY.subscribe }}
        </button>
      </div>
    </div>
    <div
      class="hilos-stack small mb-3"
      data-id="public-status-subscription-slot"
    >
      <div
        class="alert alert-secondary mb-0 invisible"
        aria-hidden="true"
        inert
      >
        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
        {{ STATUS_SUBSCRIPTION_COPY.subscribed }}
      </div>
      <template v-if="!subscriptionDialog">
        <div
          v-if="subscriptionSuccess || subscriptionProgress"
          class="alert mb-0"
          :class="subscriptionNoticeClass"
          data-id="public-status-subscription-notice"
        >
          <i
            v-if="subscriptionNoticeIcon"
            :class="['bi', subscriptionNoticeIcon, 'me-1']"
            aria-hidden="true"
          ></i>
          {{ subscriptionSuccess || subscriptionProgress }}
        </div>
      </template>
      <HilosFormError
        :message="subscriptionDialog ? null : subscriptionError"
        data-id="public-status-subscription-error"
        announce
      />
    </div>
    <div
      class="visually-hidden"
      role="status"
      aria-live="polite"
      aria-atomic="true"
      data-id="public-status-subscription-live"
    >
      {{
        subscriptionDialog ? '' : subscriptionSuccess || subscriptionProgress
      }}
    </div>
    <div
      class="visually-hidden"
      role="status"
      aria-live="polite"
      aria-atomic="true"
      data-id="public-status-live"
    >
      {{ banner.title }} {{ connectionMessage }}
    </div>

    <div class="hilos-stack mb-3 small">
      <div class="invisible border rounded p-2" aria-hidden="true" inert>
        Your live connection to the site is lost — the system is up
      </div>
      <div
        v-if="connectionMessage"
        class="border rounded p-2 bg-warning-subtle text-warning-emphasis"
        data-id="public-status-connection"
      >
        {{ connectionMessage }}
      </div>
    </div>

    <div
      class="border rounded p-3 mb-3"
      :class="`bg-${states[banner.kind].tone}-subtle text-${states[banner.kind].tone}-emphasis`"
      data-id="public-status-banner"
    >
      <h2 class="h5 mb-0">
        <i
          :class="`bi bi-${states[banner.kind].icon} me-2`"
          aria-hidden="true"
        ></i>
        {{ banner.title }}
      </h2>
      <p v-if="freshness.kind === 'unknown'" class="small mt-2 mb-0">
        There is no trustworthy status picture yet. This page keeps checking.
      </p>
    </div>

    <section
      v-if="candidates.length"
      class="card mb-3"
      data-id="public-status-candidates"
    >
      <div class="card-header"><h2 class="h6 mb-0">Checking</h2></div>
      <ul class="list-group list-group-flush">
        <li
          v-for="candidate in candidates"
          :key="candidate.componentKey"
          class="list-group-item"
        >
          <h3 class="h6">
            Checking a possible problem with
            {{ componentName(candidate.componentKey) }}
          </h3>
          <p class="small mb-0">
            Detected at {{ moment(candidate.detectedAt) }}. If confirmed, it
            will appear here as an incident.
          </p>
        </li>
      </ul>
    </section>

    <section
      v-if="currentOutages.length"
      class="mb-3"
      data-id="public-status-outages"
    >
      <h2 class="h6">Current incidents</h2>
      <article
        v-for="outage in currentOutages"
        :key="outage.id"
        class="card mb-3"
      >
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
          <h3 class="h6 mb-0 text-break">
            {{ componentName(outage.componentKey) }}
          </h3>
          <span
            class="badge"
            :class="`text-bg-${states[outage.severity].tone}`"
            >{{ states[outage.severity].label }}</span
          >
          <span class="small"
            >Since {{ outage.detectedAtLabel }} ·
            {{ outage.durationLabel }}</span
          >
        </div>
        <ul
          v-if="outage.updates.length"
          class="list-group list-group-flush small"
        >
          <li
            v-for="(update, index) in outage.updates"
            :key="index"
            class="list-group-item text-break"
            data-id="public-status-update"
          >
            <span class="text-body-secondary">{{ moment(update.at) }}</span>
            <p class="mb-1">{{ update.text }}</p>
            <span class="badge bg-body-secondary text-body border fw-normal">{{
              update.source === 'team' ? 'From the team' : 'Automatic'
            }}</span>
          </li>
        </ul>
      </article>
    </section>

    <section
      v-if="upcomingWorks.length"
      class="mb-3"
      data-id="public-status-works"
    >
      <h2 class="h6">Scheduled maintenance</h2>
      <article
        v-for="work in upcomingWorks"
        :key="work.id"
        class="card border-info mb-3 text-break"
      >
        <div class="card-header bg-info-subtle d-flex flex-wrap gap-2">
          <h3 class="h6 mb-0">{{ work.title }}</h3>
          <span class="small ms-auto">
            {{ work.startsAtLabel ?? '—' }} – {{ work.endsAtLabel ?? '—' }}
          </span>
        </div>
        <div class="card-body small">
          <p v-if="work.description" class="mb-1">{{ work.description }}</p>
          <p class="mb-0">Affects: {{ work.componentNames.join(', ') }}</p>
        </div>
      </article>
    </section>

    <section
      v-if="currentMaintenance.length"
      class="mb-3"
      data-id="public-status-maintenance"
    >
      <h2 class="h6">Maintenance</h2>
      <article
        v-for="period in currentMaintenance"
        :key="period.id"
        class="card border-info mb-3 text-break"
      >
        <div class="card-header bg-info-subtle d-flex flex-wrap gap-2">
          <h3 class="h6 mb-0">{{ period.title }}</h3>
          <span class="badge text-bg-info text-wrap">
            {{
              period.confirmedCurrent
                ? 'Maintenance in progress'
                : 'Last observed maintenance'
            }}
          </span>
        </div>
        <div class="card-body small">
          <div class="hilos-stack mb-2">
            <p class="invisible mb-0" aria-hidden="true" inert>
              This is a retained observation. Current maintenance status is
              unknown.
            </p>
            <p
              v-if="!period.confirmedCurrent"
              class="mb-0"
              data-id="public-status-maintenance-retained"
            >
              This is a retained observation. Current maintenance status is
              unknown.
            </p>
          </div>
          <dl class="row mb-0">
            <dt class="col-sm-4">Advance announcement</dt>
            <dd class="col-sm-8">
              {{ announcementLabels[period.announcement] }}
            </dd>
            <template v-if="period.plannedWork">
              <dt class="col-sm-4">Planned window</dt>
              <dd class="col-sm-8">
                {{ period.plannedWork.startsAtLabel ?? '—' }} –
                {{ period.plannedWork.endsAtLabel ?? '—' }}
              </dd>
            </template>
            <dt class="col-sm-4">Started</dt>
            <dd class="col-sm-8">{{ period.startedAtLabel ?? '—' }}</dd>
            <dt class="col-sm-4">Affects</dt>
            <dd class="col-sm-8">{{ period.componentNames.join(', ') }}</dd>
          </dl>
          <p v-if="period.plannedWork" class="mb-0">
            The scheduled window does not determine the actual end.
          </p>
        </div>
      </article>
    </section>

    <section
      v-if="components.length"
      class="card mb-3"
      data-id="public-status-components"
    >
      <div class="card-header"><h2 class="h6 mb-0">Components</h2></div>
      <div
        v-for="(component, componentIndex) in components"
        :key="component.componentKey"
        class="card-body border-top"
      >
        <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
          <div class="flex-grow-1 text-break">
            <h3 class="h6 mb-1">{{ component.name }}</h3>
            <p v-if="component.description" class="small mb-0">
              {{ component.description }}
            </p>
          </div>
          <span
            class="badge"
            :class="`text-bg-${states[component.state].tone}`"
            data-id="public-status-component-state"
            >{{ states[component.state].label }}</span
          >
        </div>
        <template
          v-for="strip in dayStrips.filter(
            (item) => item.componentKey === component.componentKey,
          )"
          :key="strip.componentKey"
        >
          <div
            v-for="variant in [
              { days: strip.days, classes: 'd-none d-md-block', count: 90 },
              { days: strip.narrowDays, classes: 'd-md-none', count: 30 },
            ]"
            :key="variant.count"
            :class="variant.classes"
            :data-id="`public-status-days-${variant.count}`"
          >
            <div
              class="d-flex gap-1"
              role="group"
              :aria-label="`${component.name}: last ${variant.count} days`"
            >
              <button
                v-for="day in variant.days"
                :key="day.startTs"
                type="button"
                class="btn flex-fill p-0 py-2 border-0 rounded-1"
                :class="`btn-${states[day.state].tone}`"
                :aria-label="dayLabel(day)"
                :aria-describedby="`${detailId}-${componentIndex}`"
                @focus="
                  selectedDay = {
                    componentKey: component.componentKey,
                    startTs: day.startTs,
                  }
                "
                @click="
                  selectedDay = {
                    componentKey: component.componentKey,
                    startTs: day.startTs,
                  }
                "
              ></button>
            </div>
            <div
              class="d-flex justify-content-between small text-body-secondary mt-1"
            >
              <span>{{ variant.count }} days</span><span>Today</span>
            </div>
          </div>
          <p
            :id="`${detailId}-${componentIndex}`"
            class="small mb-0 mt-1"
            data-id="public-status-day-detail"
          >
            {{ dayDetail(strip) }}
          </p>
        </template>
      </div>
    </section>

    <section
      v-if="projection"
      class="card mb-3"
      data-id="public-status-history"
    >
      <div class="card-header"><h2 class="h6 mb-0">Past incidents</h2></div>
      <p v-if="!history.length" class="card-body small mb-0">
        No past published incidents
      </p>
      <ul v-else class="list-group list-group-flush small">
        <li
          v-for="entry in history"
          :key="`${entry.kind}-${entry.id}`"
          class="list-group-item text-break"
        >
          <template v-if="entry.kind === 'outage'">
            <h3 class="h6">
              {{ componentName(entry.componentKey) }}
              <span
                class="badge"
                :class="`text-bg-${states[entry.severity].tone}`"
                >{{ states[entry.severity].label }}</span
              >
            </h3>
            <p class="mb-1">
              {{ entry.detectedAtLabel }} · {{ entry.durationLabel }}
            </p>
            <p class="mb-1">Resolved at {{ entry.resolvedAtLabel }}</p>
            <p
              v-for="(update, index) in entry.updates"
              :key="index"
              class="mb-1"
            >
              {{ moment(update.at) }} · {{ update.text }}
            </p>
          </template>
          <template v-else>
            <h3 class="h6">
              {{ entry.title }}
              <span class="badge text-bg-info">Maintenance</span>
              <span class="badge bg-body-secondary text-body">Completed</span>
            </h3>
            <p class="mb-1">{{ announcementLabels[entry.announcement] }}</p>
            <p class="mb-1">Affects: {{ entry.componentNames.join(', ') }}</p>
            <p class="mb-1">
              {{ entry.startedAtLabel }}
              <span v-if="entry.durationLabel !== null">
                · {{ entry.durationLabel }}</span
              >
            </p>
            <p v-if="entry.endedAt !== null" class="mb-0">
              Completed at {{ entry.endedAtLabel }}
            </p>
            <p v-else class="mb-0">
              Completion observed at {{ entry.completionObservedAtLabel }}.
              Exact end unknown.
            </p>
          </template>
        </li>
      </ul>
    </section>

    <p class="small text-body-secondary" data-id="public-status-last-update">
      Last update from the system: {{ lastUpdate ?? 'Unknown' }}
    </p>
    <div class="hilos-stack small text-body-secondary">
      <p class="invisible mb-0" aria-hidden="true" inert>
        Subscribed already? Every letter has a link to unsubscribe.
      </p>
      <p
        v-if="subscriptionUsable"
        class="mb-0"
        data-id="public-status-subscription-hint"
      >
        Subscribed already? Every letter has a link to unsubscribe.
      </p>
    </div>
    <HilosStatusSubscriptionModal :controller="subscription" />
  </HilosStaticPage>
</template>
