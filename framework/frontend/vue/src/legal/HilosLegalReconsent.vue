<!-- HilosLegalReconsent — the "the terms have changed" screen (HIL-500), one
component in three places: the window HilosLayout raises over the page on a
sign-in while a document waits for a decision (variant "window"), the screen
that takes the content's place once the deadline has passed and the account is
frozen (variant "frozen"), and the administrator's read-only preview on a
document's page (variant "preview"). One markup for the three, because two
copies of it would drift apart exactly in the words.
A section per document: its plate (days left, the deadline passed, the freeze),
the revision the person accepted, the list of what changed — the clause's icon,
its wording now, the kind, the wording before — and the way into the full text
and the line-by-line comparison, both drawn inside the same body with "Back to
the changes". "I do not accept" opens the refusal step in the body and sends
nothing. The frozen variant keeps only "Accept" and lays the exits out as
actions: the data copy, the deletion called off when one is scheduled, and
sign-out. Under a takeover the acceptance is not offered at all: only the person
may accept their terms. The state lives in the core store; the component draws
it and emits what was pressed. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { computed, useId } from 'vue'
import {
  describeHilosLegalReconsentAccepted,
  describeHilosLegalReconsentAddress,
  describeHilosLegalReconsentChange,
  describeHilosLegalReconsentPlate,
  describeHilosLegalReconsentRefusal,
  HILOS_PAGE_ROUTES,
  hilosLegalDocumentLabel,
  hilosLegalReconsentSections,
  HilosPages,
  keepMyAccount,
  LEGAL_RECONSENT_COPY,
  signOut,
  type HilosLegalReconsentContent,
  type HilosLegalReconsentPerson,
  type HilosLegalReconsentPreview,
  type HilosLegalReconsentSection,
  type HilosLegalReconsentVariant,
  type HilosLegalReconsentView,
} from '@hilos/core'
import HilosFormError from '../HilosFormError.vue'
import HilosLink from '../HilosLink.vue'
import LoadingButton from '../LoadingButton.vue'
import { useTrackedAction } from '../useTrackedAction.js'
import HilosLegalChanges from './HilosLegalChanges.vue'
import HilosLegalRevisionText from './HilosLegalRevisionText.vue'

const props = withDefaults(
  defineProps<{
    /** Where the screen stands. */
    variant: HilosLegalReconsentVariant
    /** What was read, or null while it is read or after it failed. */
    content: HilosLegalReconsentContent | HilosLegalReconsentPreview | null
    /** What the body shows. */
    view: HilosLegalReconsentView
    /** Whether the content is being read. */
    loading?: boolean
    /** The refusal to show, or null. */
    error?: string | null
    /** Whether the acceptance is in flight. */
    busy?: boolean
    /** The person the screen speaks to; null in the preview. */
    person?: HilosLegalReconsentPerson | null
    /** Whether the person's deletion is scheduled, which offers "Keep my account" on the freeze. */
    deletionScheduled?: boolean
    /** The moment the days are counted from, LOCAL epoch ms. */
    now?: number
  }>(),
  {
    loading: false,
    error: null,
    busy: false,
    person: null,
    deletionScheduled: false,
    now: () => Date.now(),
  },
)
const emit = defineEmits<{
  accept: []
  later: []
  retry: []
  show: [view: HilosLegalReconsentView]
}>()

const copy = LEGAL_RECONSENT_COPY
const headingId = useId()
const dataHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE_DATA] ?? '/'
const profileHref = HILOS_PAGE_ROUTES[HilosPages.PROFILE] ?? '/'
const { busy: signOutBusy, run: runSignOut } = useTrackedAction()
const { busy: keepBusy, run: runKeep } = useTrackedAction()

const sections = computed<readonly HilosLegalReconsentSection[]>(() =>
  props.content === null ? [] : hilosLegalReconsentSections(props.content),
)
const rows = computed(() =>
  sections.value.map((section) => ({
    section,
    plate: describeHilosLegalReconsentPlate(section, props.variant, props.now),
    accepted: describeHilosLegalReconsentAccepted(section),
    changes: section.changes.map(describeHilosLegalReconsentChange),
  })),
)
const firstRevision = computed(
  () =>
    props.variant === 'preview' &&
    sections.value.length === 1 &&
    sections.value[0]!.held === null,
)
const impersonated = computed(() => props.person?.impersonated === true)
const heading = computed(() =>
  props.variant === 'frozen' ? copy.frozenHeading : copy.heading,
)
const viewed = computed(() => {
  const view = props.view
  if (view.kind !== 'text' && view.kind !== 'compare') return null
  return sections.value.find((item) => item.document === view.document) ?? null
})
const refusal = computed(() =>
  props.content !== null && 'refusal' in props.content
    ? describeHilosLegalReconsentRefusal(
        props.content as HilosLegalReconsentContent,
      )
    : copy.refuseRemind,
)
const acceptOffered = computed(
  () => props.variant !== 'frozen' || !impersonated.value,
)
const acceptDisabled = computed(
  () =>
    props.variant === 'preview' ||
    props.content === null ||
    sections.value.length === 0 ||
    props.loading ||
    props.busy,
)
const address = computed(() =>
  describeHilosLegalReconsentAddress(props.content),
)

function onSignOut(): void {
  if (signOutBusy.value) return
  void runSignOut(signOut())
}
function onKeepAccount(): void {
  if (keepBusy.value) return
  void runKeep(keepMyAccount())
}
</script>

<template>
  <section
    data-id="legal-reconsent"
    :data-variant="variant"
    :aria-labelledby="headingId"
  >
    <div
      v-if="variant !== 'preview' && person !== null"
      class="d-flex flex-wrap align-items-center gap-2 border rounded px-3 py-2 mb-3 small"
      data-id="legal-reconsent-person"
    >
      <i class="bi bi-person-circle text-body-secondary" aria-hidden="true" />
      <div class="lh-sm text-break">
        <div class="fw-semibold">{{ person.name }}</div>
        <div
          v-if="address !== null"
          class="text-body-secondary small"
          data-id="legal-reconsent-address"
        >
          {{ address }}
        </div>
      </div>
      <LoadingButton
        v-if="!person.impersonated"
        class="btn-link btn-sm p-0 ms-auto"
        data-id="legal-reconsent-not-you"
        :loading="signOutBusy"
        @click="onSignOut"
      >
        {{ copy.notYou }}
      </LoadingButton>
    </div>
    <h2 :id="headingId" class="h5 mb-3" data-id="legal-reconsent-heading">
      {{ heading }}
    </h2>
    <div class="visually-hidden" role="status" aria-live="polite">
      {{ error ?? (loading ? 'Loading terms…' : '') }}
    </div>
    <div
      v-if="loading"
      class="placeholder-glow mb-3"
      data-id="legal-reconsent-loading"
      aria-busy="true"
    >
      <span class="placeholder col-12" aria-hidden="true" />
      <span class="placeholder col-9" aria-hidden="true" />
      <span class="placeholder col-10" aria-hidden="true" />
    </div>
    <p
      v-if="firstRevision"
      class="text-body-secondary mb-0"
      data-id="legal-reconsent-preview-first"
    >
      {{ copy.previewFirst }}
    </p>
    <template v-else-if="content !== null && view.kind === 'changes'">
      <section
        v-for="row in rows"
        :key="row.section.document"
        class="mb-3"
        data-id="legal-reconsent-document"
        :data-document="row.section.document"
      >
        <h3 class="h6 mb-2">
          {{ hilosLegalDocumentLabel(row.section.document) }}
        </h3>
        <div
          v-if="row.plate !== null"
          class="d-flex flex-wrap align-items-center gap-2 rounded px-3 py-2 mb-2 small"
          :class="`bg-${row.plate.tone}-subtle text-${row.plate.tone}-emphasis`"
          data-id="legal-reconsent-badge"
          :data-tone="row.plate.tone"
        >
          <i class="bi" :class="row.plate.icon" aria-hidden="true" />
          <strong>{{ row.plate.text }}</strong>
          <span v-if="row.plate.detail !== null">{{ row.plate.detail }}</span>
        </div>
        <p class="small text-body-secondary mb-2">{{ row.accepted }}</p>
        <ul class="list-unstyled mb-2">
          <li
            v-for="change in row.changes"
            :key="change.clauseKey"
            class="d-flex gap-2 py-2 border-bottom text-break"
            data-id="legal-reconsent-change"
            :data-clause="change.clauseKey"
          >
            <i
              class="bi text-body-secondary mt-1"
              :class="change.icon"
              aria-hidden="true"
            />
            <div class="flex-grow-1">
              <div class="small fw-semibold">
                {{ change.statement }}
                <span
                  class="badge text-bg-light border ms-1"
                  data-id="legal-reconsent-change-kind"
                  >{{ change.kind }}</span
                >
              </div>
              <div
                v-if="change.before !== null"
                class="small text-body-secondary"
              >
                {{ change.before }}
              </div>
            </div>
          </li>
        </ul>
        <div class="small">
          <button
            type="button"
            class="btn btn-link btn-sm p-0"
            data-id="legal-reconsent-full-text"
            :data-document="row.section.document"
            @click="
              emit('show', { kind: 'text', document: row.section.document })
            "
          >
            {{ copy.fullText }}
          </button>
          <template v-if="row.section.held !== null">
            ·
            <button
              type="button"
              class="btn btn-link btn-sm p-0"
              data-id="legal-reconsent-compare"
              :data-document="row.section.document"
              @click="
                emit('show', {
                  kind: 'compare',
                  document: row.section.document,
                })
              "
            >
              {{ copy.compare }}
            </button>
          </template>
        </div>
      </section>
      <section
        v-if="variant === 'frozen'"
        class="border rounded px-3 py-2 mb-3"
        data-id="legal-reconsent-exits"
      >
        <h3 class="h6">{{ copy.freezeKeeps }}</h3>
        <div class="d-flex flex-wrap gap-2">
          <HilosLink
            :to="dataHref"
            class="btn btn-sm btn-outline-secondary"
            data-id="legal-reconsent-data-link"
          >
            <i class="bi bi-download me-1" aria-hidden="true" />{{
              copy.dataLink
            }}
          </HilosLink>
          <LoadingButton
            v-if="deletionScheduled && !impersonated"
            class="btn-sm btn-outline-secondary"
            data-id="legal-reconsent-keep-account"
            :loading="keepBusy"
            @click="onKeepAccount"
          >
            {{ copy.keepAccount }}
          </LoadingButton>
          <LoadingButton
            class="btn-sm btn-outline-secondary"
            data-id="legal-reconsent-sign-out"
            :loading="signOutBusy"
            @click="onSignOut"
          >
            {{ copy.signOut }}
          </LoadingButton>
        </div>
      </section>
      <p
        v-if="variant === 'frozen' && impersonated && person !== null"
        class="small text-body-secondary"
        data-id="legal-reconsent-impersonated"
      >
        {{ copy.onlyPerson.replace('{name}', person.name) }}
      </p>
    </template>
    <div v-else-if="viewed !== null && view.kind === 'text'">
      <h3 class="h6">{{ hilosLegalDocumentLabel(viewed.document) }}</h3>
      <HilosLegalRevisionText :clauses="[...viewed.clauses]" />
    </div>
    <div v-else-if="viewed !== null && view.kind === 'compare'">
      <h3 class="h6">{{ hilosLegalDocumentLabel(viewed.document) }}</h3>
      <HilosLegalChanges
        :changes="[...viewed.changes]"
        :from-label="viewed.held?.revisionId ?? ''"
        :to-label="viewed.current.revisionId"
      />
    </div>
    <div
      v-else-if="content !== null && view.kind === 'refuse'"
      data-id="legal-reconsent-refuse-step"
    >
      <p>{{ refusal }}</p>
      <div class="d-flex flex-wrap gap-3 small mb-3">
        <HilosLink
          :to="dataHref"
          data-id="legal-reconsent-data-link"
          @click="emit('later')"
        >
          {{ copy.dataLink }}
        </HilosLink>
        <HilosLink
          :to="profileHref"
          class="link-danger"
          data-id="legal-reconsent-delete-link"
          @click="emit('later')"
        >
          {{ copy.deleteLink }}
        </HilosLink>
      </div>
    </div>
    <button
      v-if="viewed !== null"
      type="button"
      class="btn btn-link btn-sm px-0 mb-3"
      data-id="legal-reconsent-back"
      @click="emit('show', { kind: 'changes' })"
    >
      {{ copy.backToChanges }}
    </button>
    <HilosFormError :message="error" data-id="legal-reconsent-error" />
    <div
      v-if="!firstRevision"
      class="d-flex flex-wrap justify-content-end gap-2 mt-2"
    >
      <button
        v-if="error !== null && content === null"
        type="button"
        class="btn btn-outline-primary"
        data-id="legal-reconsent-retry"
        @click="emit('retry')"
      >
        {{ copy.retry }}
      </button>
      <template v-if="view.kind === 'refuse'">
        <button
          type="button"
          class="btn btn-outline-secondary"
          data-id="legal-reconsent-back"
          @click="emit('show', { kind: 'changes' })"
        >
          {{ copy.back }}
        </button>
        <button
          type="button"
          class="btn btn-secondary"
          data-id="legal-reconsent-close"
          @click="emit('later')"
        >
          {{ copy.close }}
        </button>
      </template>
      <template v-else>
        <button
          v-if="variant !== 'frozen'"
          type="button"
          class="btn btn-outline-danger"
          data-id="legal-reconsent-refuse"
          :disabled="variant === 'preview' || content === null"
          @click="emit('show', { kind: 'refuse' })"
        >
          {{ copy.refuse }}
        </button>
        <button
          v-if="variant !== 'frozen'"
          type="button"
          class="btn btn-outline-secondary"
          data-id="legal-reconsent-later"
          :disabled="variant === 'preview'"
          @click="emit('later')"
        >
          {{ copy.later }}
        </button>
        <LoadingButton
          v-if="acceptOffered"
          class="btn-primary"
          data-id="legal-reconsent-accept"
          :loading="busy"
          :disabled="acceptDisabled"
          @click="emit('accept')"
        >
          {{ copy.accept }}
        </LoadingButton>
      </template>
    </div>
  </section>
</template>
