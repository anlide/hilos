<!-- The public About page (HilosPages.ABOUT). A framework-declared static page:
the framework owns the route, the frame, the heading and the support block at the
end of the text (HilosAboutPage), and this project owns the content. The page
subscribes like any other but the framework page sends no payload, so nothing
here depends on the socket. -->
<script setup lang="ts">
import { HilosAboutPage } from '@hilos/vue'

defineOptions({ name: 'AboutPage' })
</script>

<template>
  <HilosAboutPage>
    <p class="lead">
      BTC Tracker is a demonstration of the Hilos framework — background
      computation over a live market, drawn on a real-time, no-refresh
      WebSocket application.
    </p>
    <p>
      It is going to follow BTC/USDT: take every minute from the Binance
      stream, fold it into fifteen timeframes, compute RSI(14) on each, find
      the signals the chains of zones and divergences give, and let a robot
      open and close paper trades on them — with a Telegram bot telling you
      what happened.
    </p>
    <p class="mb-0">
      Today it is the empty shell of that application: you can create an
      account and sign in, and the home page says who is looking. The chart
      and the rest arrive one by one.
    </p>
  </HilosAboutPage>
</template>
