<!-- Status health and incident facts arrive together in the page answer. -->
<script setup lang="ts">
import { formatMoment, hilosTimeZone, HilosPages } from '@hilos/core/base'
import {
  createHilosStatusState,
  createHilosStatusIncidentController,
  HILOS_STATUS_COPY,
  HILOS_STATUS_ACTION_COPY,
  HILOS_STATUS_SUBSCRIBERS_COPY,
  HILOS_STATUS_WORKS_COPY,
  type HilosStatusContext,
} from '@hilos/core/admin/status'
import { inject, onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosLink from '../../HilosLink.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import HilosStatusIncidentModal from './HilosStatusIncidentModal.vue'

const props = defineProps<{
  /** The project's existing scope, connection and action lifecycle. */
  context: HilosStatusContext
}>()
const router = inject(hilosRouterKey)
const state = createHilosStatusState(props.context)
const controller = createHilosStatusIncidentController(props.context, state)
const overview = useSignal(state.overview)
const cards = useSignal(state.cards)
const summary = useSignal(state.summary)
const maintenance = useSignal(state.maintenance)
const timeZone = useSignal(hilosTimeZone)

function moment(at: number): string {
  void timeZone.value
  return formatMoment(at) ?? '—'
}

onMounted(() => {
  state.start()
  controller.start()
})
onUnmounted(() => {
  controller.dispose()
  state.dispose()
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.STATUS"
    head-kind="section"
    tabs-label="Status pages"
  >
    <div v-if="overview" data-id="hilos-status-overview">
      <div
        v-if="summary === 'operational'"
        class="alert alert-success d-flex flex-wrap gap-3 align-items-center"
        data-id="hilos-status-operational"
      >
        <span class="flex-grow-1" data-id="hilos-status-operational-label">
          {{ HILOS_STATUS_COPY.allOperational }}
        </span>
        <a
          href="/status"
          class="btn btn-outline-secondary btn-sm text-nowrap"
          data-id="hilos-status-public-link"
          >{{ HILOS_STATUS_COPY.publicPage }}</a
        >
      </div>
      <div
        v-else-if="summary === 'unknown'"
        class="alert alert-secondary"
        data-id="hilos-status-unknown"
      >
        {{ HILOS_STATUS_COPY.unknown }}
      </div>
      <div
        v-else-if="summary === 'checking'"
        class="alert alert-secondary"
        data-id="hilos-status-checking"
      >
        {{ HILOS_STATUS_COPY.checking }}
      </div>

      <section
        v-if="maintenance"
        class="card border-info mb-3"
        data-id="hilos-status-maintenance"
      >
        <div class="card-header bg-info-subtle">
          <h2 class="h6 mb-0">Maintenance in progress</h2>
        </div>
        <div class="card-body text-break">
          <dl class="row mb-2">
            <dt class="col-sm-4">Advance announcement</dt>
            <dd
              class="col-sm-8"
              data-id="hilos-status-maintenance-announcement"
            >
              <div class="hilos-stack">
                <p class="invisible mb-0" aria-hidden="true" inert>
                  Announcement details are unavailable.
                </p>
                <p v-if="maintenance.announcement === null" class="mb-0">
                  Announcement details are unavailable.
                </p>
                <p v-else-if="!maintenance.announcement.known" class="mb-0">
                  Advance announcement unknown
                </p>
                <p
                  v-else-if="maintenance.announcement.work === null"
                  class="mb-0"
                >
                  Not announced in advance
                </p>
                <div v-else>
                  <p class="mb-1">{{ maintenance.announcement.work.title }}</p>
                  <p class="small mb-0">
                    {{ moment(maintenance.announcement.work.startsAt) }} –
                    {{ moment(maintenance.announcement.work.endsAt) }}
                  </p>
                </div>
              </div>
            </dd>
            <dt class="col-sm-4">Phase</dt>
            <dd class="col-sm-8">{{ maintenance.phase }}</dd>
            <dt class="col-sm-4">Operation</dt>
            <dd class="col-sm-8">
              <code>{{ maintenance.operation ?? '—' }}</code>
            </dd>
            <dt class="col-sm-4">Started</dt>
            <dd class="col-sm-8">{{ maintenance.startedAtLabel ?? '—' }}</dd>
          </dl>
          <HilosLink
            v-if="router?.resolvePath(HilosPages.MAINTENANCE)"
            :to="router.resolvePath(HilosPages.MAINTENANCE)!"
            data-id="hilos-status-maintenance-link"
            >View Maintenance</HilosLink
          >
        </div>
      </section>
      <p
        v-if="overview.incidents === null"
        class="text-body-secondary"
        data-id="hilos-status-incidents-unavailable"
      >
        {{ HILOS_STATUS_COPY.incidentsUnavailable }}
      </p>
      <section
        v-for="card in cards"
        :key="card.incident.incidentId"
        class="card mb-3"
        :class="
          card.state === 'major_outage'
            ? 'border-danger'
            : card.state === 'partial_outage'
              ? 'border-warning'
              : 'border-secondary'
        "
        :data-id="`hilos-status-incident-${card.incident.incidentId}`"
      >
        <div
          class="card-header d-flex flex-wrap gap-2 align-items-center"
          :class="
            card.state === 'major_outage'
              ? 'bg-danger-subtle'
              : card.state === 'partial_outage'
                ? 'bg-warning-subtle'
                : 'bg-secondary-subtle'
          "
        >
          <h2 class="h6 mb-0 text-break">{{ card.incident.componentName }}</h2>
          <span
            class="badge text-wrap"
            :class="
              card.state === 'major_outage'
                ? 'text-bg-danger'
                : card.state === 'partial_outage'
                  ? 'text-bg-warning'
                  : 'text-bg-secondary'
            "
            >{{ card.label }}</span
          >
        </div>
        <div class="card-body text-break">
          <dl class="row mb-2">
            <dt class="col-sm-4">Agent</dt>
            <dd class="col-sm-8">
              <code>{{ card.incident.agentType }}</code>
            </dd>
            <dt class="col-sm-4">{{ HILOS_STATUS_COPY.slots }}</dt>
            <dd class="col-sm-8">
              {{ card.incident.liveInstances ?? '—' }} /
              {{ card.incident.expectedInstances ?? '—' }}
            </dd>
            <dt class="col-sm-4">Last observed</dt>
            <dd class="col-sm-8">{{ card.observedAtLabel ?? '—' }}</dd>
            <dt class="col-sm-4">{{ HILOS_STATUS_COPY.detected }}</dt>
            <dd class="col-sm-8">{{ card.detectedAtLabel }}</dd>
            <template v-if="card.incident.publishedAt !== null"
              ><dt class="col-sm-4">{{ HILOS_STATUS_COPY.published }}</dt>
              <dd class="col-sm-8">{{ card.publishedAtLabel }}</dd></template
            >
            <template v-else-if="!card.suppressed"
              ><dt class="col-sm-4">{{ HILOS_STATUS_COPY.publishAt }}</dt>
              <dd class="col-sm-8">{{ card.publishAtLabel }}</dd></template
            >
          </dl>
          <p
            v-if="card.suppressed"
            class="text-body-secondary"
            :data-id="`hilos-status-suppressed-${card.incident.incidentId}`"
          >
            {{ HILOS_STATUS_COPY.suppressed }}
          </p>
          <p
            v-else-if="card.countdown"
            :data-id="`hilos-status-countdown-${card.incident.incidentId}`"
          >
            Publication in {{ card.countdown }}
          </p>
          <p
            v-else-if="card.waitingForPublication"
            :data-id="`hilos-status-awaiting-${card.incident.incidentId}`"
          >
            {{ HILOS_STATUS_COPY.awaitingPublication }}
          </p>
          <ol
            v-if="card.updates.length"
            class="list-unstyled mb-3"
            :data-id="`hilos-status-timeline-${card.incident.incidentId}`"
          >
            <li
              v-for="update in card.updates"
              :key="update.id"
              class="border-top py-2"
            >
              <span class="small text-body-secondary"
                >{{ update.atLabel }} ·
                {{ HILOS_STATUS_COPY.updateKinds[update.kind] }} ·
                {{ HILOS_STATUS_COPY[update.severity] }}</span
              >
              <div class="text-break">
                <HilosHideable :value="update.text" />
              </div>
            </li>
          </ol>
          <div class="hilos-button-row">
            <LoadingButton
              v-if="card.canCancelPublication"
              class="btn-outline-danger btn-sm"
              :data-id="`hilos-status-cancel-publication-${card.incident.incidentId}`"
              @click="
                controller.open('cancel-publication', card.incident.incidentId)
              "
              >{{
                HILOS_STATUS_ACTION_COPY['cancel-publication'].title
              }}</LoadingButton
            >
            <LoadingButton
              v-if="card.canPublishNow"
              class="btn-danger btn-sm"
              :data-id="`hilos-status-publish-now-${card.incident.incidentId}`"
              @click="controller.open('publish-now', card.incident.incidentId)"
              >{{
                HILOS_STATUS_ACTION_COPY['publish-now'].confirm
              }}</LoadingButton
            >
            <LoadingButton
              v-if="card.canAddUpdate"
              class="btn-outline-primary btn-sm"
              :data-id="`hilos-status-add-update-${card.incident.incidentId}`"
              @click="controller.open('add-update', card.incident.incidentId)"
              >{{ HILOS_STATUS_ACTION_COPY['add-update'].title }}</LoadingButton
            >
          </div>
        </div>
      </section>
      <div class="row row-cols-2 row-cols-lg-3 g-3 mb-3">
        <div class="col">
          <section class="card h-100" data-id="hilos-status-works-tile">
            <div class="card-body text-break">
              <h2 class="h6">Scheduled maintenance</h2>
              <p class="fs-4 mb-2" data-id="hilos-status-works-count">
                {{ overview.scheduledWorks?.count ?? '—' }}
              </p>
              <p
                v-if="overview.scheduledWorks?.nextWork"
                data-id="hilos-status-works-next"
              >
                Next: {{ overview.scheduledWorks.nextWork.title }}<br />
                <span class="small">
                  {{ moment(overview.scheduledWorks.nextWork.startsAt) }} –
                  {{ moment(overview.scheduledWorks.nextWork.endsAt) }}
                </span>
              </p>
              <div class="hilos-stack mb-2 small">
                <p class="invisible mb-0" aria-hidden="true" inert>
                  Scheduled maintenance data is unavailable.
                </p>
                <p
                  v-if="overview.scheduledWorks === null"
                  class="text-body-secondary mb-0"
                  data-id="hilos-status-works-unavailable"
                >
                  Scheduled maintenance data is unavailable.
                </p>
                <p
                  v-else-if="overview.scheduledWorks.count === 0"
                  class="mb-0"
                  data-id="hilos-status-works-empty"
                >
                  {{ HILOS_STATUS_WORKS_COPY.empty }}
                </p>
              </div>
              <HilosLink
                v-if="router?.resolvePath(HilosPages.STATUS_WORKS)"
                :to="router.resolvePath(HilosPages.STATUS_WORKS)!"
                data-id="hilos-status-works-link"
                >Scheduled maintenance</HilosLink
              >
            </div>
          </section>
        </div>
        <div class="col">
          <section class="card h-100">
            <div class="card-body">
              <h2 class="h6">{{ HILOS_STATUS_COPY.components }}</h2>
              <HilosLink
                v-if="router?.resolvePath(HilosPages.STATUS_COMPONENTS)"
                :to="router.resolvePath(HilosPages.STATUS_COMPONENTS)!"
                data-id="hilos-status-components-link"
                >{{ overview.components.operationalCount }} /
                {{ overview.components.totalCount }} operational ·
                {{ HILOS_STATUS_COPY.componentsLink }}</HilosLink
              >
            </div>
          </section>
        </div>
        <div class="col">
          <section class="card h-100" data-id="hilos-status-subscribers-tile">
            <div class="card-body">
              <h2 class="h6">
                {{ HILOS_STATUS_SUBSCRIBERS_COPY.subscribers }}
              </h2>
              <dl class="mb-2">
                <div class="d-flex gap-2">
                  <dt>{{ HILOS_STATUS_SUBSCRIBERS_COPY.confirmed }}</dt>
                  <dd
                    class="mb-0"
                    data-id="hilos-status-subscribers-tile-confirmed"
                  >
                    {{ overview.subscribers?.confirmedCount ?? '—' }}
                  </dd>
                </div>
                <div class="d-flex gap-2">
                  <dt>
                    {{ HILOS_STATUS_SUBSCRIBERS_COPY.awaitingConfirmation }}
                  </dt>
                  <dd
                    class="mb-0"
                    data-id="hilos-status-subscribers-tile-pending"
                  >
                    {{ overview.subscribers?.pendingCount ?? '—' }}
                  </dd>
                </div>
              </dl>
              <div class="hilos-stack mb-2">
                <p class="invisible mb-0" aria-hidden="true">
                  {{ HILOS_STATUS_SUBSCRIBERS_COPY.unavailable }}
                </p>
                <p
                  v-if="overview.subscribers === null"
                  class="text-body-secondary mb-0"
                  data-id="hilos-status-subscribers-tile-unavailable"
                >
                  {{ HILOS_STATUS_SUBSCRIBERS_COPY.unavailable }}
                </p>
              </div>
              <HilosLink
                v-if="router?.resolvePath(HilosPages.STATUS_SUBSCRIBERS)"
                :to="router.resolvePath(HilosPages.STATUS_SUBSCRIBERS)!"
                data-id="hilos-status-subscribers-link"
                >{{ HILOS_STATUS_SUBSCRIBERS_COPY.subscribers }}</HilosLink
              >
            </div>
          </section>
        </div>
      </div>
      <section class="card">
        <div class="card-body">
          <h2 class="h6">{{ HILOS_STATUS_COPY.publishDelay }}</h2>
          <p
            v-if="overview.publishDelaySeconds === null"
            data-id="hilos-status-delay"
          >
            {{ HILOS_STATUS_COPY.delayUnavailable }}
          </p>
          <p v-else data-id="hilos-status-delay">
            <HilosHideable :value="overview.publishDelaySeconds"
              ><template #default="{ value }"
                >{{ value }} seconds</template
              ></HilosHideable
            >
          </p>
          <HilosLink
            v-if="router?.resolvePath(HilosPages.SETTINGS)"
            :to="router.resolvePath(HilosPages.SETTINGS)!"
            data-id="hilos-status-settings-link"
            >{{ HILOS_STATUS_COPY.settings }}</HilosLink
          >
        </div>
      </section>
    </div>
    <HilosStatusIncidentModal :controller="controller" />
  </HilosAdminPage>
</template>
