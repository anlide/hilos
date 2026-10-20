<!-- Local read-only detail surface. The core reader owns requests, snapshots,
attribution, value sides and pagination; HilosModal owns the keyboard and focus.
Hide's focus/scroll placement is the only DOM effect this view adds. A record
whose key is not masked links to the Tracked table page once the router gives
that page an address; following it closes the dialog. -->
<script setup lang="ts">
import {
  CHANGE_LOG_RECEIPT_COPY as copy,
  CHANGE_LOG_RECORD_ROUTE_PARAM,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  changeLogEntryDetails,
  changeLogReceiptAttribution,
  changeLogReceiptPagination,
  changeLogRecordRouteParam,
  formatChangeLogReceiptTitle,
  formatChangeLogTime,
  type HilosChangeLogEntry,
  type HilosChangeLogReceiptDialog,
  type HilosChangeLogReceiptReader,
} from '@hilos/core/admin/change-log'
import { HilosPages, isHiddenValue } from '@hilos/core/base'
import { computed, inject, nextTick, ref } from 'vue'

import HilosFormError from '../../HilosFormError.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosLink from '../../HilosLink.vue'
import HilosLongText from '../../HilosLongText.vue'
import HilosModal from '../../HilosModal.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const props = defineProps<{
  /** The current lazy snapshot, or null when closed. */
  dialog: HilosChangeLogReceiptDialog | null
  /** Intents of the page's one shared receipt reader. */
  reader: HilosChangeLogReceiptReader
}>()

const router = inject(hilosRouterKey, undefined)

const title = computed(() => formatChangeLogReceiptTitle(props.dialog))
const attribution = computed(() =>
  props.dialog?.header
    ? changeLogReceiptAttribution(props.dialog.header)
    : null,
)
const entries = computed(() =>
  (props.dialog?.entries ?? []).map((entry) => ({
    entryId: entry.entryId,
    details: changeLogEntryDetails(entry),
    recordPath: recordPath(entry),
  })),
)
const pagination = computed(() =>
  props.dialog ? changeLogReceiptPagination(props.dialog) : null,
)
const changesHeading = ref<HTMLElement>()

/**
 * Address the Tracked table page narrowed to the entry's record, when that page
 * is built and no part of the key is masked.
 *
 * @param entry Journal entry of the open receipt.
 */
function recordPath(entry: HilosChangeLogEntry): string | undefined {
  const key = entry.recordKey
  const parts = (key ?? []).filter(
    (part): part is number | string => part !== null,
  )
  if (key === null || parts.length === 0 || parts.length !== key.length) {
    return undefined
  }
  return router?.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
    [CHANGE_LOG_TABLE_ROUTE_PARAM]: entry.table,
    [CHANGE_LOG_RECORD_ROUTE_PARAM]: changeLogRecordRouteParam(parts),
  })
}

/** Restore the first portion, then return to the beginning of What changed. */
async function hide(): Promise<void> {
  props.reader.collapse()
  await nextTick()
  changesHeading.value?.focus({ preventScroll: true })
  changesHeading.value?.scrollIntoView({ block: 'start' })
}
</script>

<template>
  <HilosModal
    :model-value="dialog !== null"
    :title="title"
    size="wide"
    initial-focus="dialog"
    @update:model-value="!$event && reader.close()"
  >
    <div data-id="hilos-change-log-detail">
      <div class="visually-hidden" role="status" aria-live="polite">
        {{
          dialog?.busyInitial
            ? copy.loading
            : dialog?.busyMore
              ? copy.loadingMore
              : ''
        }}
      </div>
      <HilosFormError
        :message="dialog?.refusal ?? null"
        data-id="hilos-change-log-detail-error"
        announce
      />
      <div class="mb-2">
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          :class="{ invisible: !dialog?.refusal }"
          :disabled="!dialog?.refusal || dialog.busyInitial || dialog.busyMore"
          :aria-hidden="!dialog?.refusal || undefined"
          :tabindex="dialog?.refusal ? 0 : -1"
          data-id="hilos-change-log-retry"
          @click="reader.retry()"
        >
          {{ copy.retry }}
        </button>
      </div>
      <p v-if="dialog?.busyInitial" class="text-body-secondary">
        {{ copy.loading }}
      </p>
      <dl v-if="attribution" class="row mb-2">
        <dt class="col-sm-4">{{ copy.when }}</dt>
        <dd class="col-sm-8">{{ attribution.when }}</dd>
        <dt class="col-sm-4">{{ copy.who }}</dt>
        <dd class="col-sm-8 text-break">
          <template v-if="isHiddenValue(attribution.actor.label)">
            <HilosHiddenMark /> (#{{ attribution.actor.id }})
          </template>
          <template v-else>{{ attribution.actor.label }}</template>
          <div
            v-if="attribution.actor.explanation"
            class="small text-body-secondary"
          >
            {{ attribution.actor.explanation }}
          </div>
        </dd>
        <template v-if="attribution.subject">
          <dt class="col-sm-4">{{ copy.onBehalfOf }}</dt>
          <dd class="col-sm-8 text-break">
            <template v-if="isHiddenValue(attribution.subject.label)">
              <HilosHiddenMark /> (#{{ attribution.subject.id }})
            </template>
            <template v-else>{{ attribution.subject.label }}</template>
            <div
              v-if="attribution.subject.explanation"
              class="small text-body-secondary"
            >
              {{ attribution.subject.explanation }}
            </div>
            <div class="small text-body-secondary">
              {{ copy.impersonation }}
              <template v-if="isHiddenValue(attribution.actor.label)">
                <HilosHiddenMark /> (#{{ attribution.actor.id }})
              </template>
              <template v-else>{{ attribution.actor.label }}</template>
            </div>
          </dd>
        </template>
        <dt class="col-sm-4">{{ copy.channel }}</dt>
        <dd class="col-sm-8">
          <span
            v-if="attribution.channel"
            class="badge bg-body-secondary text-body border"
          >
            <i
              :class="`bi ${attribution.channel.icon} me-1`"
              aria-hidden="true"
            ></i>
            {{ attribution.channel.label }}
          </span>
          <template v-else>{{ copy.unknown }}</template>
        </dd>
        <dt class="col-sm-4">{{ copy.source }}</dt>
        <dd class="col-sm-8">
          <code class="text-break">{{ attribution.source }}</code>
        </dd>
        <dt class="col-sm-4">{{ copy.action }}</dt>
        <dd class="col-sm-8">
          <code class="text-break">{{ attribution.action }}</code>
        </dd>
        <dt class="col-sm-4">{{ copy.agent }}</dt>
        <dd class="col-sm-8">
          <code class="text-break">{{ attribution.agent }}</code>
        </dd>
      </dl>
      <dl
        v-else-if="dialog?.kind === 'entry' && dialog.entries.length"
        class="row mb-2"
      >
        <dt class="col-sm-4">{{ copy.when }}</dt>
        <dd class="col-sm-8">
          {{ formatChangeLogTime(dialog.entries[0]!.createdAt) }}
        </dd>
        <dt class="col-sm-4">{{ copy.who }}</dt>
        <dd class="col-sm-8">
          {{ copy.unknownPerson }}
          <div class="small text-body-secondary">{{ copy.noReceipt }}</div>
        </dd>
      </dl>

      <section v-if="entries.length">
        <h2
          ref="changesHeading"
          class="h6 mb-2"
          tabindex="-1"
          data-id="hilos-change-log-changes-heading"
        >
          {{ copy.whatChanged }}
        </h2>
        <div
          v-for="entry in entries"
          :key="entry.entryId"
          class="border rounded-2 p-2 mb-2"
          :data-id="`hilos-change-log-entry-${entry.entryId}`"
        >
          <h3 class="h6 d-flex flex-wrap align-items-center gap-2 mb-2">
            <HilosLink
              v-if="entry.recordPath !== undefined"
              :to="entry.recordPath"
              :data-id="`hilos-change-log-receipt-record-${entry.entryId}`"
              @click="reader.close()"
              ><code class="text-break">{{
                entry.details.record
              }}</code></HilosLink
            >
            <code v-else class="text-break">{{ entry.details.record }}</code>
            <span class="badge bg-body-secondary text-body border">{{
              entry.details.mutation
            }}</span>
          </h3>
          <p
            v-if="entry.details.explanation"
            class="small text-body-secondary mb-1"
          >
            {{ entry.details.explanation }}
          </p>
          <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
            <li v-for="field in entry.details.fields" :key="field.field">
              <code class="text-break">{{ field.field }}</code>
              <span
                v-if="field.kind === 'fact'"
                class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1 text-wrap"
              >
                {{ field.oldValue.text }}
              </span>
              <div v-else class="row g-2 mt-1">
                <div :class="field.newValue ? 'col-sm-6' : 'col-12'">
                  <div class="small text-body-secondary mb-1">
                    {{ copy.oldValue }}
                  </div>
                  <HilosLongText
                    kind="output"
                    :text="field.oldValue.text"
                    :data-id="`hilos-change-log-value-${entry.entryId}-${field.field}-old`"
                  />
                </div>
                <div v-if="field.newValue" class="col-sm-6">
                  <div class="small text-body-secondary mb-1">
                    {{ copy.newValue }}
                  </div>
                  <HilosLongText
                    kind="output"
                    :text="field.newValue.text"
                    :data-id="`hilos-change-log-value-${entry.entryId}-${field.field}-new`"
                  />
                </div>
              </div>
            </li>
          </ul>
        </div>
        <div v-if="pagination" class="mt-3">
          <div
            class="small text-body-secondary mb-2"
            data-id="hilos-change-log-detail-count"
          >
            {{ pagination.count }}
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button
              v-if="pagination.showMore"
              type="button"
              class="btn btn-sm btn-outline-secondary"
              :disabled="dialog?.busyMore"
              :aria-busy="dialog?.busyMore"
              data-id="hilos-change-log-more"
              @click="reader.more()"
            >
              {{ copy.showMore }}
            </button>
            <button
              v-if="pagination.showHide"
              type="button"
              class="btn btn-sm btn-outline-secondary"
              data-id="hilos-change-log-hide"
              @click="hide"
            >
              {{ copy.hide }}
            </button>
          </div>
        </div>
      </section>
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-primary"
        data-id="hilos-change-log-detail-close"
        @click="requestClose"
      >
        {{ copy.close }}
      </button>
    </template>
  </HilosModal>
</template>
