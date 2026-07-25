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

# Install deps first for better layer caching
COPY apps/web/package.json apps/web/package-lock.json* ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci || npm install

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
