<!-- The user detail page (PAGE_USER): a read-only profile of one chat
participant reached at /user/:id. The profile resolves from the page entity the
subscription delivered, so a rename re-renders here without a refresh; presence
and the session count ride the reactive userPresence slot, flipping the dot on
connect or disconnect. A skeleton shows until the profile entity lands (or when
the id is unknown and the subscription is rejected). Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import { useSignal } from '@hilos/vue'

import { userDetail } from './userPage'

defineOptions({ name: 'UserPage' })

const detail = useSignal(userDetail)
</script>

<template>
  <section data-id="user-view">
    <div v-if="detail" class="card" data-id="user-detail">
      <div class="card-header d-flex align-items-center gap-2">
        <span
          class="rounded-circle flex-shrink-0"
          :class="detail.presence === 'online' ? 'bg-success' : 'bg-secondary'"
          style="width: 10px; height: 10px"
        />
        <span class="h5 mb-0" data-id="user-name">{{ detail.name }}</span>
        <span class="badge text-bg-secondary" data-id="user-presence">{{
          detail.presence
        }}</span>
      </div>
      <div class="card-body">
        <dl class="row mb-0">
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
