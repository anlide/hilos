FROM node:20-alpine AS build

WORKDIR /srv/agent-openai

COPY framework/agent-openai/package.json framework/agent-openai/package-lock.json ./
RUN npm ci

COPY framework/agent-openai/tsconfig.json ./tsconfig.json
COPY framework/agent-openai/src ./src

RUN npm run build

FROM node:20-alpine AS runtime

WORKDIR /srv/agent-openai

COPY --from=build /srv/agent-openai/package.json ./package.json
COPY --from=build /srv/agent-openai/package-lock.json ./package-lock.json
COPY --from=build /srv/agent-openai/node_modules ./node_modules
COPY --from=build /srv/agent-openai/dist ./dist

ENV NODE_ENV=production
EXPOSE 9307

CMD ["node", "dist/server.js"]
