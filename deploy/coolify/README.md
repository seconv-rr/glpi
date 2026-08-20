# Deploying to Coolify

`Dockerfile` here is the whole build: the official `glpi/glpi` image with the SECONV-RR logos
copied over GLPI's own, the UI Branding plugin, and this repository's `defaultlang` plugin
bundled in. Nothing is compiled, so the build is a few seconds.

## Why not build the fork

The official image accepts `GLPI_REPO` and `GLPI_VERSION` build args, so building
`seconv-rr/glpi` at branch `11.0/bugfixes` straight from
[glpi-project/docker-images](https://github.com/glpi-project/docker-images) is supported and
would work. It is the wrong trade here for two reasons:

- This fork carries no changes to GLPI itself — the branding lives entirely in `branding/`.
  Building the source costs composer, npm and locale compilation for an identical result.
- `11.0/bugfixes` is upstream's *development* branch for the next patch. `version/` says 11.0.9,
  which is unreleased; the newest published image is 11.0.8. Production should track releases.

Switch to building the fork only once there are real patches to GLPI's own code. The command is:

```bash
docker build --build-arg GLPI_REPO=seconv-rr/glpi --build-arg GLPI_VERSION=11.0/bugfixes .
```

## Coolify setup

Create a project, then two resources inside it.

**1. Database — MariaDB.** Version 11.8 matches the development stack. Note the generated
database name, user and password; Coolify shows the internal hostname on the database page.

**2. Application — from the Git repository.**

| Field | Value |
| --- | --- |
| Source | `seconv-rr/glpi`, branch `11.0/bugfixes` |
| Build Pack | Dockerfile |
| Base Directory | `/` |
| Dockerfile Location | `/deploy/coolify/Dockerfile` |
| Ports Exposes | `80` |

Environment variables — the names come from the official image's entrypoint, the values from the
database resource:

```
GLPI_DB_HOST=<internal hostname of the MariaDB resource>
GLPI_DB_PORT=3306
GLPI_DB_NAME=glpi
GLPI_DB_USER=glpi
GLPI_DB_PASSWORD=<from Coolify>
```

Persistent storage — one volume, mounted at **`/var/glpi`**. That single path holds `config`,
`files`, `logs` and `marketplace`; everything else is disposable. Without it, every redeploy
wipes the instance configuration.

Set the domain and let Coolify terminate TLS. Apache inside the container listens on 80 as a
non-root user.

The image runs Apache **and** GLPI's cron worker under supervisor, so no separate scheduler
resource is needed.

## First deploy

The entrypoint installs the schema by itself (`GLPI_SKIP_AUTOINSTALL` defaults to `false`).
Default super-admin is `glpi` / `glpi` — **change it before exposing the domain.**

Then, once, in the container terminal:

```bash
php bin/console plugin:install --username=glpi mod
php bin/console plugin:activate mod
printf 'title="SECONV-RR"\nlogin="0"\ntheme_logos="0"\n' > /var/glpi/files/_plugins/mod/modifiers.ini

php bin/console plugin:install --username=glpi defaultlang
php bin/console plugin:activate defaultlang

php bin/console plugin:install --username=glpi splitcategory
php bin/console plugin:activate splitcategory
```

That is all the `mod` plugin is there for: `$CFG_GLPI['app_name']`, which drives the browser tab
title, the footer of notification e-mails, and the issuer label shown by MFA apps. The logos do
not go through it — they are already in the image.

`defaultlang` lives in this repository (`plugins/defaultlang`) and locks the instance to pt_BR: it
removes every other locale from `$CFG_GLPI['languages']`, so the browser's `Accept-Language` has
nothing else to negotiate, the language selector in user preferences offers a single option, and
the `en_GB` the installer wrote into the `glpi` account is discarded. Nobody gets logged out; the
language changes on the next page load. See `plugins/defaultlang/README.md` for the two caveats.

`splitcategory` also lives in this repository (`plugins/splitcategory`) and splits the ITIL category
question of every form into two chained dropdowns — category, then the subcategories of that
category — instead of the single tree dropdown listing every level at once. The question itself is
untouched, so the ticket destination keeps reading the same answer. No configuration, and
deactivating it restores the native dropdown. See `plugins/splitcategory/README.md`.

> **Do not press "Apply" on any logo in the plugin's UI Branding screen.** It would overwrite the
> baked-in SECONV-RR files with the plugin's own sample images. `theme_logos="0"` keeps that
> screen from touching the themed variants.

After the first successful deploy, set `GLPI_SKIP_AUTOUPDATE=true` so schema migrations happen
when you decide, not when a redeploy happens to pull a newer image.

## Timezones

GLPI wants the MySQL timezone tables. On the database, once:

```sql
GRANT SELECT ON mysql.time_zone_name TO 'glpi'@'%';
```

then in the application container:

```bash
php bin/console database:enable_timezones
```

## Updating GLPI

Bump the tag in `Dockerfile` and redeploy. With `GLPI_SKIP_AUTOUPDATE=true`, run the migration
yourself afterwards:

```bash
php bin/console database:update
```

Check that the `mod` plugin has a release for the new GLPI patch level first — it declares
`glpi min 11.0 / max 12.0`, so patch bumps inside 11.x are fine.
