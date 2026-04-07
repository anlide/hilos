FROM node:20-alpine AS core-build

WORKDIR /srv/agent-core

COPY framework/agent-core/package.json framework/agent-core/package-lock.json ./
RUN npm ci

COPY framework/agent-core/tsconfig.json ./tsconfig.json
COPY framework/agent-core/src ./src
RUN npm run build

FROM node:20-alpine AS host-build

WORKDIR /srv/agent-host

COPY framework/agent-host/package.json framework/agent-host/package-lock.json ./
COPY framework/agent-host/tsconfig.json ./tsconfig.json
COPY framework/agent-host/src ./src
COPY framework/agent-core/package.json ../agent-core/package.json
COPY --from=core-build /srv/agent-core/dist ../agent-core/dist

RUN npm install
RUN npm run build

FROM node:20-alpine AS runtime

WORKDIR /srv/agent-host

COPY --from=host-build /srv/agent-host/package.json ./package.json
COPY --from=host-build /srv/agent-host/node_modules ./node_modules
COPY --from=host-build /srv/agent-host/dist ./dist
COPY --from=host-build /srv/agent-core /srv/agent-core

RUN ln -s /srv/agent-host/node_modules /srv/agent-core/node_modules

ENV NODE_ENV=production
EXPOSE 9309

CMD ["node", "dist/server.js"]
