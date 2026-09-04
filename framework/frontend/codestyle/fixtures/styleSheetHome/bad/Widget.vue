<!-- Deliberately broken sample: an SFC carrying the trailing <style> block the
     rule refuses, so STYLE-SHEET-HOME must report the block's own line. -->
<template>
  <div class="widget">A widget that styles itself.</div>
</template>

<style>
.widget {
  padding: 1rem;
}
</style>
