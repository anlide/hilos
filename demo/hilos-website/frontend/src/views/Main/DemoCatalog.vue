<script setup lang="ts">
const SOURCE_TREE = 'https://github.com/anlide/hilos/tree/total-refac'
</script>

<template>
  <section aria-labelledby="home-demos-title">
    <h2 id="home-demos-title">Try the demos</h2>
    <p>
      Open a demo in two windows to see changes travel between them without a
      reload.
    </p>
    <section aria-labelledby="home-featured-title" class="mt-4">
      <h3 id="home-featured-title">Explore the working demos</h3>
      <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">Chat</h4>
              <span class="badge text-bg-success">Vue</span>
              <p class="card-text mt-3">
                A live conversation over WebSocket, with conversational bots and
                AI-assisted moderation.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <a href="https://chat-demo.hleb.studio/">
                Open demo<span class="visually-hidden"> Chat</span>
              </a>
              <a :href="`${SOURCE_TREE}/demo/chat`">
                Source<span class="visually-hidden"> of Chat</span> on GitHub
              </a>
            </div>
          </article>
        </div>
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">E-commerce shop</h4>
              <span class="badge text-bg-info">React</span>
              <p class="card-text mt-3">
                Explore shop administration with warehouses, categories,
                products and stock counts that update across open windows. The
                storefront is still being built.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <a href="https://shop-demo.hleb.studio/">
                Open demo<span class="visually-hidden"> E-commerce shop</span>
              </a>
              <a :href="`${SOURCE_TREE}/demo/ecommerce-shop`">
                Source<span class="visually-hidden"> of E-commerce shop</span>
                on GitHub
              </a>
            </div>
          </article>
        </div>
      </div>
    </section>
    <section aria-labelledby="home-starters-title" class="mt-4">
      <h3 id="home-starters-title">Application starters</h3>
      <p>
        These starters offer sign-in, an admin area and a live status page.
        Their own products are still ahead.
      </p>
      <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">BTC tracker</h4>
              <span class="badge text-bg-success">Vue</span>
              <p class="card-text mt-3">
                The framework shell for a future live BTC/USDT tracker; market
                tracking is still to come.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <a href="https://btc-demo.hleb.studio/">
                Open demo<span class="visually-hidden"> BTC tracker</span>
              </a>
              <a :href="`${SOURCE_TREE}/demo/binance-btc-tracker`">
                Source<span class="visually-hidden"> of BTC tracker</span> on
                GitHub
              </a>
            </div>
          </article>
        </div>
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">PMD</h4>
              <span class="badge text-bg-success">Vue</span>
              <p class="card-text mt-3">
                A Vue application shell with a dedicated service database, ready
                for its own product features.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <p class="mb-0 text-body-secondary">No public address yet</p>
              <a :href="`${SOURCE_TREE}/demo/pmd`">
                Source<span class="visually-hidden"> of PMD</span> on GitHub
              </a>
            </div>
          </article>
        </div>
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">Roblox game server</h4>
              <span class="badge text-bg-success">Vue</span>
              <p class="card-text mt-3">
                The starting point for a team PvP arena with point capture. Game
                rooms, scoring and rankings are planned.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <p class="mb-0 text-body-secondary">No public address yet</p>
              <a :href="`${SOURCE_TREE}/demo/roblox-game-server`">
                Source<span class="visually-hidden">
                  of Roblox game server</span
                >
                on GitHub
              </a>
            </div>
          </article>
        </div>
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">File gallery manager</h4>
              <span class="badge text-bg-info">React</span>
              <p class="card-text mt-3">
                A framework shell for a future file gallery. Gallery browsing
                and file management are still to come.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <p class="mb-0 text-body-secondary">No public address yet</p>
              <a :href="`${SOURCE_TREE}/demo/file-gallery-manager`">
                Source<span class="visually-hidden">
                  of File gallery manager</span
                >
                on GitHub
              </a>
            </div>
          </article>
        </div>
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">Solar system model</h4>
              <span class="badge text-bg-info">React</span>
              <p class="card-text mt-3">
                A framework shell for a future interactive solar system. The 3D
                model has not been built yet.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <p class="mb-0 text-body-secondary">No public address yet</p>
              <a :href="`${SOURCE_TREE}/demo/solar-system-model`">
                Source<span class="visually-hidden">
                  of Solar system model</span
                >
                on GitHub
              </a>
            </div>
          </article>
        </div>
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">Tasks</h4>
              <span class="badge text-bg-info">React</span>
              <p class="card-text mt-3">
                The starting point for a shared task list, with adding,
                completing and deleting tasks planned.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <a href="https://tasks-demo.hleb.studio/">
                Open demo<span class="visually-hidden"> Tasks</span>
              </a>
              <a :href="`${SOURCE_TREE}/demo/tasks`">
                Source<span class="visually-hidden"> of Tasks</span> on GitHub
              </a>
            </div>
          </article>
        </div>
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">Concert tickets</h4>
              <span class="badge text-bg-danger">Angular</span>
              <p class="card-text mt-3">
                An application shell for concert ticket sales. The hall, seats
                and booking are still to come.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <p class="mb-0 text-body-secondary">No public address yet</p>
              <a :href="`${SOURCE_TREE}/demo/concert-tickets`">
                Source<span class="visually-hidden"> of Concert tickets</span>
                on GitHub
              </a>
            </div>
          </article>
        </div>
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">Online testing</h4>
              <span class="badge text-bg-danger">Angular</span>
              <p class="card-text mt-3">
                The framework shell for a testing room. Tests, attempts and
                verdicts have not been built yet.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <a href="https://testing-demo.hleb.studio/">
                Open demo<span class="visually-hidden"> Online testing</span>
              </a>
              <a :href="`${SOURCE_TREE}/demo/online-testing`">
                Source<span class="visually-hidden"> of Online testing</span> on
                GitHub
              </a>
            </div>
          </article>
        </div>
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">Polls</h4>
              <span class="badge text-bg-danger">Angular</span>
              <p class="card-text mt-3">
                The starting point for polls with answer options and live voting
                results; the polling product is still to come.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <a href="https://polls-demo.hleb.studio/">
                Open demo<span class="visually-hidden"> Polls</span>
              </a>
              <a :href="`${SOURCE_TREE}/demo/polls`">
                Source<span class="visually-hidden"> of Polls</span> on GitHub
              </a>
            </div>
          </article>
        </div>
        <div class="col">
          <article class="card h-100">
            <div class="card-body">
              <h4 class="h5">Preferans</h4>
              <span class="badge text-bg-danger">Angular</span>
              <p class="card-text mt-3">
                An application shell for Sochi preferans. The card game has not
                been built yet.
              </p>
            </div>
            <div
              class="card-footer bg-transparent d-flex flex-column align-items-start gap-2"
            >
              <p class="mb-0 text-body-secondary">No public address yet</p>
              <a :href="`${SOURCE_TREE}/demo/preferans`">
                Source<span class="visually-hidden"> of Preferans</span> on
                GitHub
              </a>
            </div>
          </article>
        </div>
      </div>
    </section>
  </section>
</template>
