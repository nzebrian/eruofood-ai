# syntax=docker/dockerfile:1.7
# =============================================================================
# EruoFood AI — Web (React + TS + Vite) image.
# Stage 1 builds static assets; stage 2 serves them via Nginx.
# For local development the Vite dev server is run directly (see compose override).
# =============================================================================

ARG NODE_IMAGE_TAG=22-alpine
ARG NGINX_IMAGE_TAG=1.27-alpine

# ---- Stage 1: build ---------------------------------------------------------
FROM node:${NODE_IMAGE_TAG} AS build
WORKDIR /app

# Install deps first for better layer caching.
#
# `npm ci`, and nothing after it. This read `npm ci || npm install` until M50-02.
# The two commands are not interchangeable: `npm ci` installs exactly the tree in
# package-lock.json and fails when the lock and package.json disagree, while
# `npm install` resolves the semver ranges afresh against the registry and
# rewrites the lock. So the fallback turned "this lockfile is stale, stop" into
# "ship whatever the registry has today" — in an image that staging-deploy.yml
# pushes and deploys. The required `Dependency audit` gate audits
# apps/web/package-lock.json; with the fallback live, the audited tree and the
# shipped tree could differ, and nothing would have said so.
#
# The `package-lock.json*` glob stays: it lets the COPY succeed when the lock is
# absent, and `npm ci` then fails loudly on its own ("can only install packages
# when your package.json and package-lock.json are in sync"). That is the
# fail-closed behaviour, so the glob is no longer load-bearing.
COPY apps/web/package.json apps/web/package-lock.json* ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci

COPY apps/web ./
RUN npm run build

# ---- Stage 2: serve ---------------------------------------------------------
FROM nginx:${NGINX_IMAGE_TAG} AS runtime
COPY infra/nginx/conf.d/web.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1/ > /dev/null 2>&1 || exit 1

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
