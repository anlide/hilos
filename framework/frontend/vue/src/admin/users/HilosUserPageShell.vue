<!-- The shared user entity head and merged-account notice for every user tab.
The page supplies its body and dialogs; the server identity supplies the owner,
breadcrumb and eligible tabs. Only the current user page's permitted detail
binds the live name. No mutation belongs in this head.
-->
<script setup lang="ts">
import { computed, inject, onMounted, onUnmounted } from 'vue'
import {
  bindPageInstanceName,
  computedSignal,
  hiddenAsWord,
  HilosPages,
  isHiddenValue,
  type HilosPageHeadAction,
} from '@hilos/core/base'
import { ANALYTICS_PERSON_ROUTE_PARAM } from '@hilos/core/admin/analytics'
import {
  createHilosUserDetail,
  createHilosUserPhoto,
  createHilosUserStanding,
  HILOS_PRESENCE_WIDEST_LABEL,
  HILOS_USER_ANALYTICS_LINK_LABEL,
  HILOS_USER_MERGED_COPY,
  hilosPresenceBadge,
  hilosStandingBadge,
  hilosUserAnalyticsEnabled,
  hilosUserAnalyticsPath,
  hilosUserMergedNotice,
  type HilosUsersContext,
} from '@hilos/core/admin/users'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosAvatar from '../../HilosAvatar.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosLink from '../../HilosLink.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The existing page scopes, connection and typed user collection. */
  context: HilosUsersContext
  /** The subscribing user page key, whose tab is current. */
  page: string
}>()

defineSlots<{
  /** The tab's content, below the shared head and merged notice. */
  default?(): unknown
  /** The tab's dialogs, under the admin shell's providers. */
  modals?(): unknown
}>()

const userDetail = createHilosUserDetail(props.context)
const currentDetail = computedSignal(() => {
  const page = props.context.scopes.page()?.key
  if (
    page !== HilosPages.USER &&
    page !== HilosPages.USER_ROLES &&
    page !== HilosPages.USER_ACCESS &&
    page !== HilosPages.USER_SESSIONS
  ) {
    return undefined
  }
  return userDetail.get()
})
const unbindName = bindPageInstanceName(
  props.context.scopes,
  HilosPages.USER,
  computedSignal(() => {
    const detail = currentDetail.get()
    return detail === undefined ? undefined : hiddenAsWord(detail.name)
  }),
)
onUnmounted(unbindName)

const detail = useSignal(currentDetail)
const photo = useSignal(createHilosUserPhoto(props.context))
const userStanding = createHilosUserStanding(props.context)
const standing = useSignal(userStanding.standing)
onMounted(userStanding.start)
onUnmounted(userStanding.dispose)

const presenceBadge = computed(() =>
  hilosPresenceBadge(detail.value?.presence ?? 'offline'),
)
const standingBadge = computed(() =>
  standing.value === null ? null : hilosStandingBadge(standing.value.shown),
)
const mergedNotice = computed(() =>
  detail.value === undefined ? null : hilosUserMergedNotice(standing.value),
)
const mergedCopy = HILOS_USER_MERGED_COPY

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosUserPageShell requires a provided Hilos router.')
}
const analyticsEnabled = useSignal(
  hilosUserAnalyticsEnabled(props.context.scopes),
)
const headAction = computed<HilosPageHeadAction | undefined>(() =>
  hilosUserAnalyticsPath(
    analyticsEnabled.value,
    detail.value?.id,
    router.resolvePath,
  ) === null
    ? undefined
    : {
        page: HilosPages.ANALYTICS_PERSON,
        label: HILOS_USER_ANALYTICS_LINK_LABEL,
        params: {
          [ANALYTICS_PERSON_ROUTE_PARAM]: String(detail.value?.id),
        },
      },
)
</script>

<template>
  <HilosAdminPage
    :page="page"
    head-kind="entity"
    tabs-label="User pages"
    :head-action="headAction"
  >
    <template v-if="detail" #head-visual>
      <HilosAvatar
        :name="isHiddenValue(detail.name) ? '' : detail.name"
        :photo="photo"
        size="md"
      />
    </template>
    <template v-if="detail" #head-badges>
      <span class="hilos-stack d-inline-grid">
        <span class="badge invisible" aria-hidden="true">{{
          HILOS_PRESENCE_WIDEST_LABEL
        }}</span>
        <span class="badge" :class="`text-bg-${presenceBadge.tone}`">{{
          presenceBadge.label
        }}</span>
      </span>
      <span
        v-if="standingBadge !== null"
        class="badge"
        :class="`text-bg-${standingBadge.tone}`"
        data-id="user-standing-badge"
      >
        <i class="bi me-1" :class="standingBadge.icon" aria-hidden="true"></i
        >{{ standingBadge.label }}
      </span>
    </template>
    <template v-if="detail" #head-description>
      <p class="text-body-secondary mb-0">
        #{{ detail.id
        }}<template v-if="detail.lastActivity">
          · Last seen {{ detail.lastActivity }}</template
        >
      </p>
    </template>

    <!-- A one-line notice keeps its room as the standing changes. Its text
    truncates beside the survivor's navigation, with no extra action or modal. -->
    <div class="hilos-stack mb-4" role="status" aria-live="polite">
      <div
        class="alert alert-secondary mb-0 invisible d-flex align-items-center gap-2"
        aria-hidden="true"
        inert
      >
        <span class="flex-grow-1 text-truncate">&nbsp;</span>
      </div>
      <div
        v-if="mergedNotice !== null"
        class="alert alert-secondary mb-0 d-flex align-items-center gap-2"
        data-id="hilos-user-merged"
      >
        <span class="flex-grow-1 text-truncate">
          <template v-if="mergedNotice.userId !== null">
            {{ mergedCopy.into }}
            <HilosHideable :value="mergedNotice.name" /> (#{{
              mergedNotice.userId
            }}).
          </template>
          <template v-else>{{ mergedCopy.gone }}</template>
        </span>
        <HilosLink
          v-if="mergedNotice.path !== null"
          class="alert-link flex-shrink-0 text-nowrap"
          data-id="hilos-user-merged-link"
          :to="mergedNotice.path"
          >{{ mergedCopy.open }}</HilosLink
        >
      </div>
    </div>
    <slot />
    <template #modals>
      <slot name="modals" />
    </template>
  </HilosAdminPage>
</template>
