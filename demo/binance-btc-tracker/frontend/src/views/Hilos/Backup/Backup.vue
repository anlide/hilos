<!-- The Hilos backup page (HilosPages.BACKUP): a thin project binding of the
framework HilosBackupPage to this app's context. The backup list table, the row
view-model, and the live in-progress row are the framework's; the project supplies
only the context (its scope stores + live connection) and produces the backup
index on its backend. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosBackupPage } from '@hilos/vue'

import { hilosBackupsContext } from './hilosBackupsContext'

defineOptions({ name: 'BackupPage' })
</script>

<template>
  <HilosBackupPage :context="hilosBackupsContext" />
</template>
