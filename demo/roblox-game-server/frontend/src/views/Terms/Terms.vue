<!-- The public Terms page (HilosPages.TERMS). The page is the framework's: its
body is the text of the Terms revision in force, read from the project's legal
catalog (backend/Legal/), with the reader's standing. Revision history has its own page.
This project supplies only the introduction above the text. -->
<script setup lang="ts">
import { HilosTermsPage } from '@hilos/vue/base'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { HilosPages, hilosPublicLegalNavigation } from '@hilos/core/base'
import { router } from '../../pages/routes.js'

const navigation = hilosPublicLegalNavigation(
  HilosPages.TERMS,
  (page, params) => router.path(page, params),
)

defineOptions({ name: 'TermsPage' })
</script>

<template>
  <HilosTermsPage
    :context="{ connection, scopes, actions }"
    :navigation="navigation"
  >
    <p>
      This is a demonstration application provided for evaluation purposes only,
      without warranty of any kind.
    </p>
  </HilosTermsPage>
</template>
