# AMFOT PHP runtime

This repository builds a **runtime-only** Docker image for the AMFOT Laravel 9 application.

**No Laravel application source code, database dumps, credentials, `.env`, `vendor`, or user uploads are stored here or embedded in the image.**

## Image

`ghcr.io/sirtimon/amfot-php:v1.0.0` after a successful tagged build.

Runtime includes PHP 8.2 FPM, Composer 2, required PHP extensions, cron, Java, and `pdftk-java`.

## Publish

Create and push a `v*` git tag (for example `v1.0.0`). The GitHub Actions workflow builds and publishes to GHCR using the repository's built-in `GITHUB_TOKEN`.

A manual workflow dispatch is available for test builds, which publish a SHA-based tag. Check **Actions** for successful completion before deploying.

Package visibility is configured separately in the GHCR package settings; new packages may be private by default. If private, use `docker login ghcr.io` with a credential allowed to read packages on the destination server.

## Deployment

Application files reside **only on the deployment server**. Bind-mount them into all PHP containers:

```yaml
services:
  app:
    image: ghcr.io/sirtimon/amfot-php:v1.0.0
    volumes:
      - ./app:/var/www/html
      - ./storage:/var/www/html/storage
```

Reuse the runtime image for the queue worker and scheduler, with their own commands. Continue to use a separate Nginx container and database. Make sure `vendor/` is installed **locally** using Composer and is accessible to workers.

Create `public/storage -> ../storage/app/public` and `public/scorm -> ../storage/app/scorm` through the deployment initialization service, not in the image; their targets live in bind mounts.

Production tasks (DB import, `APP_KEY`, `.env`, permissions, Laravel queue configuration) remain local to the deployment host.

## Safety

The `.gitignore` also excludes common sensitive content. The workflow never downloads application sources. Do not change the Dockerfile to `COPY app/` or `COPY storage/`.

This runtime is not yet validated against a real running AMFOT installation.
