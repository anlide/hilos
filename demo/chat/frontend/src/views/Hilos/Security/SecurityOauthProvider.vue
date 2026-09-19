<!-- The Hilos OAuth provider page (HilosPages.SECURITY_OAUTH_PROVIDER, HIL-286): a
thin project binding of the framework HilosSecurityOauthProviderPage to this app's
context. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosSecurityOauthProviderPage } from '@hilos/vue'

import { hilosSecurityOauthContext } from './hilosSecurityOauthContext'

defineOptions({ name: 'SecurityOauthProviderPage' })
</script>

<template>
  <HilosSecurityOauthProviderPage :context="hilosSecurityOauthContext" />
</template>
