<script setup lang="ts">
import { HilosLegalHistoryPage } from '@hilos/vue/base'
import { HilosPages, hilosPublicLegalNavigation } from '@hilos/core/base'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { router } from '../../pages/routes.js'
const context = { connection, scopes, actions }
const navigation = hilosPublicLegalNavigation(
  HilosPages.PRIVACY_HISTORY,
  (page, params) => router.path(page, params),
)
defineOptions({ name: 'PrivacyHistoryPage' })
</script>

<template>
  <HilosLegalHistoryPage
    :context="context"
    document="privacy"
    :navigation="navigation"
  />
</template>
