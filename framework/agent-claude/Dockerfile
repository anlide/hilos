FROM node:20-alpine AS build

WORKDIR /srv/agent-claude

COPY framework/agent-claude/package.json framework/agent-claude/package-lock.json ./
RUN npm ci

COPY framework/agent-claude/tsconfig.json ./tsconfig.json
COPY framework/agent-claude/src ./src

RUN npm run build

FROM node:20-alpine AS runtime

WORKDIR /srv/agent-claude

COPY --from=build /srv/agent-claude/package.json ./package.json
COPY --from=build /srv/agent-claude/package-lock.json ./package-lock.json
COPY --from=build /srv/agent-claude/node_modules ./node_modules
COPY --from=build /srv/agent-claude/dist ./dist

ENV NODE_ENV=production
EXPOSE 9308

CMD ["node", "dist/server.js"]