<!-- The language main card and its switch controls share one page-scoped
browser datum for both the initial view and live updates. -->
<script setup lang="ts">
import {
  createHilosI18nLanguageCard,
  createHilosI18nLanguageCardWindow,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  hilosI18nLanguageDelete,
  HilosPages,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { computed, inject, ref } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nLanguageDelete from './HilosI18nLanguageDelete.vue'
import HilosI18nLanguageHeader from './HilosI18nLanguageHeader.vue'
import HilosI18nLanguageSwitchOff from './HilosI18nLanguageSwitchOff.vue'
import HilosI18nLanguageSwitchOn from './HilosI18nLanguageSwitchOn.vue'
import HilosI18nLanguageWindow from './HilosI18nLanguageWindow.vue'

const props = defineProps<{ context: HilosI18nLanguageContext }>()
const cardSignal = createHilosI18nLanguageCard(props.context)
const card = useSignal(cardSignal)
const languageWindow = createHilosI18nLanguageCardWindow(
  cardSignal,
  props.context,
)
const copy = HILOS_I18N_LANGUAGE_WINDOW_COPY
const deleteCopy = HILOS_I18N_LANGUAGE_DELETE_COPY
const deleteOpen = ref(false)
const deleteVerdict = computed(() =>
  hilosI18nLanguageDelete(card.value?.summary ?? null),
)
const deleteSource = computed(() =>
  card.value === null
    ? null
    : {
        languageId: card.value.languageId,
        code: card.value.code,
        nativeName: card.value.nativeName,
        canDelete: card.value.summary.canDelete,
        deleteReason: card.value.summary.deleteReason,
      },
)
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nLanguagePage requires a provided Hilos router.')
}
const loading = useSignal(router.pageLoading)

const deleted = (): void => {
  router.navigate(router.resolvePath(HilosPages.I18N_LANGUAGES, {})!)
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_LANGUAGE">
    <template v-if="card">
      <HilosI18nLanguageHeader
        :card="card"
        :active-page="HilosPages.I18N_LANGUAGE"
      />

      <section class="mb-4" data-id="language-card-main">
        <div class="d-flex align-items-center gap-2 mb-2">
          <h2 class="h5 mb-0 flex-grow-1">Main</h2>
          <p
            v-if="card.enabled"
            class="badge text-bg-warning mb-0"
            data-id="language-card-frozen"
          >
            Language enabled — frozen
          </p>
          <button
            v-if="card.enabled"
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :title="copy.open.view"
            :aria-label="copy.open.view"
            data-id="language-card-view"
            @click="languageWindow.open()"
          >
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
          <button
            v-else
            type="button"
            class="btn btn-sm btn-outline-primary"
            :title="copy.open.edit"
            :aria-label="copy.open.edit"
            data-id="language-card-edit"
            @click="languageWindow.open()"
          >
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>
        </div>
        <dl class="row mb-0 border rounded-3 p-3">
          <dt class="col-sm-4">Code</dt>
          <dd class="col-sm-8 text-uppercase" data-id="language-card-code">
            {{ card.code }}
          </dd>
          <dt class="col-sm-4">Native name</dt>
          <dd class="col-sm-8" data-id="language-card-name">
            {{ card.nativeName }}
          </dd>
          <dt class="col-sm-4">Writing direction</dt>
          <dd class="col-sm-8" data-id="language-card-direction">
            {{ card.rtl ? 'Right to left' : 'Left to right' }}
          </dd>
        </dl>
      </section>

      <section class="mb-4" data-id="language-card-state">
        <h2 class="h5">State</h2>
        <div class="border rounded-3 p-3">
          <dl class="row mb-0">
            <dt class="col-sm-4">Availability</dt>
            <dd class="col-sm-8">
              <span data-id="language-card-enabled">
                {{ card.enabled ? 'Enabled' : 'Disabled' }}
              </span>
              <div class="mt-2">
                <HilosI18nLanguageSwitchOff :context="context" :card="card" />
                <HilosI18nLanguageSwitchOn :context="context" :card="card" />
              </div>
            </dd>
            <dt class="col-sm-4">Delete language</dt>
            <dd class="col-sm-8" data-id="language-card-delete-verdict">
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                :disabled="deleteVerdict.disabled"
                :aria-describedby="
                  deleteVerdict.reason
                    ? 'language-card-delete-reason'
                    : undefined
                "
                data-id="language-card-delete"
                @click="deleteOpen = true"
              >
                {{ deleteCopy.open }}
              </button>
              <p
                v-if="deleteVerdict.reason"
                id="language-card-delete-reason"
                class="small text-body-secondary mt-1 mb-0"
                data-id="language-card-delete-reason"
              >
                {{ deleteVerdict.reason }}
              </p>
            </dd>
          </dl>
        </div>
      </section>
    </template>
    <div
      v-else-if="loading"
      class="placeholder-glow"
      role="status"
      aria-label="Loading language"
    >
      <span class="placeholder col-6 d-block mb-2 rounded"></span>
      <span class="placeholder col-4 d-block rounded"></span>
    </div>
    <p
      v-else-if="!deleteOpen"
      class="alert alert-secondary"
      role="status"
      data-id="language-card-unavailable"
    >
      Language details are no longer available.
    </p>
    <HilosI18nLanguageWindow :window="languageWindow" />
    <HilosI18nLanguageDelete
      v-model="deleteOpen"
      :context="context"
      :source="deleteSource"
      :action-name="HILOS_I18N_LANGUAGE_DELETE_ACTION"
      @deleted="deleted"
    />
  </HilosAdminPage>
</template>
