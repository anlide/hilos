<!-- HilosI18nTranslateUiPagePage — the Translate UI page admin page (HilosPages.I18N_TRANSLATE_UI_PAGE). A framework default: a
thin binding of the page key to the shared admin shell HilosAdminPage, which
resolves the heading, lead, breadcrumb, and any sub-section cards from the
@hilos/core admin tree. Implement the page by replacing the shell's default body
through its default slot. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core'

import HilosAdminPage from '../../../HilosAdminPage.vue'
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_TRANSLATE_UI_PAGE" />
</template>
