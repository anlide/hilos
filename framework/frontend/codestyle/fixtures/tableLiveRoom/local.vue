<script setup lang="ts">
import {
  HilosModal as Dialog,
  HilosViewportTable as Grid,
} from '@hilos/vue/base'
</script>

<template>
  <section>
    <div class="alert invisible" data-id="page-reserve"></div>
    <HilosFormError />
    <Grid>
      <template #cell-name>
        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div v-else style="min-height: 2rem" data-id="cell-reserve"></div>
      </template>
      <template #cell-note>
        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div class="opacity-0" aria-hidden="true"></div>
        <i class="bi bi-snow invisible"></i>
        <span class="hilos-table-marks invisible">
          <span class="badge border border-warning-subtle text-warning-emphasis"
            >Will move</span
          >
        </span>
        <div class="hilos-table-work-space hilos-table-work-reserve"></div>
        <div class="hilos-table-record-space">Gone</div>
        <span v-if="rowMessage" data-id="hilos-table-row-message">{{
          rowMessage
        }}</span>
        <!-- <div class="alert invisible"></div> -->
        <Dialog>
          <div class="alert invisible" data-id="dialog-reserve"></div>
          <Grid>
            <template #detail-note>
              <div class="opacity-0" data-id="nested-reserve">
                <div class="alert alert-warning">Table refusal</div>
              </div>
            </template>
          </Grid>
        </Dialog>
      </template>
    </Grid>
  </section>
</template>
