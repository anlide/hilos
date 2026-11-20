<!-- The main page's skeleton (HIL-983): what the routed outlet draws while the
page waits for its first answer. The page is one heading today, so its skeleton
is one bar at the height of that heading, and nothing moves when the page lands.
Decorative: the outlet announces the wait once, and the bar is not a heading. -->
<template>
  <p class="h1 placeholder-glow" aria-hidden="true">
    <span class="placeholder col-2 rounded"></span>
  </p>
</template>
