<!-- The user detail page (PAGE_USER): a read-only profile of one chat
participant reached at /user/:id. The profile resolves from the page entity the
subscription delivered, so a rename re-renders here without a refresh; presence
and the session count ride the reactive userPresence slot, flipping the online
badge on connect or disconnect. The header starts with the person's circle
(HIL-1300): the photo the personPhoto slot carries, or the initials, redrawn
without a refresh when the photo changes. An account folded into another one
stays at this address (HIL-1292): the userMerge slot says so, the circle wears
the merge sign, the header wears a gray Merged badge in place of the presence,
and a line names the survivor with the way to their page instead of the
sessions and the activity. A skeleton shows until the profile
entity lands (or when the id is unknown and the subscription is rejected).
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosAvatar, HilosLink, useSignal } from '@hilos/vue'

import { PAGE_USER } from '../../pages/keys'
import { router } from '../../pages/routes'
import { userDetail } from './userPage'

defineOptions({ name: 'UserPage' })

/** The Bootstrap Icons class a merged account's circle wears in place of a face. */
const MERGED_ICON = 'bi-sign-merge-left'

const detail = useSignal(userDetail)

/**
 * The address of a person's page, for the survivor a merged account points at.
 *
 * @param id The person's id.
 */
function userHref(id: number): string | undefined {
  return router.path(PAGE_USER, { id: String(id) })
}
</script>

<template>
  <section data-id="user-view">
    <div v-if="detail" class="card" data-id="user-detail">
      <div class="card-header d-flex align-items-center gap-2">
        <HilosAvatar
          :name="detail.name"
          :photo="detail.merged ? null : detail.photo"
          :icon="detail.merged ? MERGED_ICON : null"
          size="sm"
        />
        <h1 class="h5 mb-0" data-id="user-name">{{ detail.name }}</h1>
        <span
          v-if="detail.merged"
          class="badge text-bg-secondary"
          data-id="user-merged-badge"
          ><i class="bi bi-sign-merge-left me-1" aria-hidden="true"></i
          >Merged</span
        >
        <span v-else class="badge text-bg-secondary" data-id="user-presence">{{
          detail.presence
        }}</span>
      </div>
      <div class="card-body">
        <!-- A merged account has no sessions and no activity of its own any
        more: the merge closed its sign-in, and what it is now is the survivor. -->
        <p v-if="detail.merged" class="mb-0" data-id="user-merged-into">
          <template v-if="detail.mergedInto !== null">
            This account was merged into
            <HilosLink
              v-if="userHref(detail.mergedInto) !== undefined"
              data-id="user-merged-into-link"
              :to="userHref(detail.mergedInto) ?? ''"
              >{{ detail.mergedIntoName }}</HilosLink
            ><template v-else>{{ detail.mergedIntoName }}</template
            >.
          </template>
          <template v-else>This account was merged into another one.</template>
        </p>
        <dl v-else class="row mb-0">
          <dt class="col-sm-3">Online sessions</dt>
          <dd class="col-sm-9" data-id="user-sessions">
            {{ detail.onlineSessionCount }}
          </dd>
          <template v-if="detail.lastActivity">
            <dt class="col-sm-3">Last activity</dt>
            <dd class="col-sm-9" data-id="user-last-activity">
              {{ detail.lastActivity }}
            </dd>
          </template>
        </dl>
      </div>
    </div>
    <p v-else class="text-body-secondary" data-id="user-empty">Loading user…</p>
  </section>
</template>
