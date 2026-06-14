<!-- The current user's profile page (HilosPages.PROFILE): a read-only view of the
signed-in user reached from the navbar. The display name arrives in the session
scope on the handshake; the rename flow (framework modal) lands in a later step.
Styling is Bootstrap classes only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import { useSignal } from '@hilos/vue'

import { profileDetail } from './profilePage'

const detail = useSignal(profileDetail)
</script>

<template>
  <section data-id="profile-view">
    <div class="d-flex flex-column gap-1 mb-4">
      <h1 class="h4 mb-0">Profile</h1>
      <p class="mb-0 text-body-secondary">Your account.</p>
    </div>
    <div v-if="detail" class="card" data-id="profile-detail">
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-3">Name</dt>
          <dd class="col-sm-9 mb-0" data-id="profile-name">{{ detail.name }}</dd>
        </dl>
      </div>
    </div>
    <p v-else class="text-body-secondary" data-id="profile-loading">
      Loading profile…
    </p>
  </section>
</template>
