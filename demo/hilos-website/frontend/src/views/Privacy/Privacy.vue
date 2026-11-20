<!-- The public Privacy page (HilosPages.PRIVACY). A framework-declared public
page; this project supplies the introduction. The frame, heading,
policy text, reader and erase block are the framework's (HilosPrivacyPage), and
this demo declares no browser value of its own, so it hands in no `values`.
What the site records about a visit, for how long, and what stays after an
account is deleted is the policy text itself, served from the project's legal
catalog (backend/Legal/), so the introduction does not repeat it.
See views/About/About.vue. -->
<script setup lang="ts">
import { HilosPrivacyPage } from '@hilos/vue/base'

import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { HilosPages, hilosPublicLegalNavigation } from '@hilos/core/base'
import { router } from '../../pages/routes.js'

const navigation = hilosPublicLegalNavigation(
  HilosPages.PRIVACY,
  (page, params) => router.path(page, params),
)

defineOptions({ name: 'PrivacyPage' })
</script>

<template>
  <HilosPrivacyPage
    :context="{ connection, scopes, actions }"
    :navigation="navigation"
  >
    <p class="mb-0">
      The official Hilos website records the visits of everyone who opens it,
      signed in or not. The policy below says what is recorded, for how long,
      and what stays after an account is deleted. The site uses no third-party
      trackers.
    </p>
  </HilosPrivacyPage>
</template>
