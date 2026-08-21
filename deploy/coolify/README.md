# Deploying to Coolify

`Dockerfile` here is the whole build: the official `glpi/glpi` image with the SECONV-RR logos
copied over GLPI's own and this repository's `appname`, `defaultlang` and `profilecondition`
plugins bundled in.
Nothing is compiled, so the build is a few seconds.

`verify-branding.sh` runs as the last build step and fails the build when the branding has
stopped holding; `smoke.sh` checks the same thing against a deployed instance. See
[Checking the branding still works](#checking-the-branding-still-works).

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
php bin/console plugin:install --username=glpi appname
php bin/console plugin:activate appname

php bin/console plugin:install --username=glpi defaultlang
php bin/console plugin:activate defaultlang

php bin/console plugin:install --username=glpi profilecondition
php bin/console plugin:activate profilecondition
```

`appname` sets `$CFG_GLPI['app_name']` to `SECONV-RR`, which drives the browser tab title, the
footer of notification e-mails, and the issuer label shown by MFA apps — the one "GLPI" string the
logos cannot cover. The logos themselves do not go through any plugin; they are already in the
image. See `plugins/appname/README.md`.

`defaultlang` lives in this repository (`plugins/defaultlang`) and locks the instance to pt_BR: it
removes every other locale from `$CFG_GLPI['languages']`, so the browser's `Accept-Language` has
nothing else to negotiate, the language selector in user preferences offers a single option, and
the `en_GB` the installer wrote into the `glpi` account is discarded. Nobody gets logged out; the
language changes on the next page load. See `plugins/defaultlang/README.md` for the two caveats.

`profilecondition` (`plugins/profilecondition`) adds a **Current user profile** question type to
the native form builder. The question is hidden from whoever fills the form and answers itself
with the profile active in the session, which makes the current profile usable as a criterion in
the native conditions editor: visibility of any question, comment or section, validation rules,
and conditional creation of destinations. Activating it changes nothing on its own — everything is
configured per form in the UI. See `plugins/profilecondition/README.md` for the two recipes
(urgency restricted by profile, forced default per profile) and for the caveat that conditions
store profile **ids**, so a form exported to another instance needs its conditions re-checked.

> **Do not install i-Vertix's `mod` (UI Branding) plugin here.** Its `plugin_mod_activate()`
> copies the plugin's own sample images over `public/pics` — which is exactly the i-Vertix logo
> that showed up on the login page before — sets `login="1"` so the login page takes the i-Vertix
> photo background, and renames the instance to "i-Vertix". `appname` replaces it.

### Removing `mod` from an instance that already has it

Uninstalling from the GLPI plugins page restores the logos it backed up, but its image directory
lives in the `/var/glpi` volume and a redeploy does not clear it. In the container terminal:

```bash
php bin/console plugin:deactivate mod
php bin/console plugin:uninstall mod
rm -rf /var/glpi/files/_plugins/mod
```

The redeploy then puts the SECONV-RR logos back, since `public/pics` is an image layer.

After the first successful deploy, set `GLPI_SKIP_AUTOUPDATE=true` so schema migrations happen
when you decide, not when a redeploy happens to pull a newer image.

## Checking the branding still works

Both halves of the branding fail without saying anything. A GLPI release that adds or renames a
logo leaves a file the `COPY` never touched, so a GLPI-branded image comes back on one screen.
A release that moves `$CFG_GLPI['app_name']`, the `plugin_<key>_boot()` hook or the twig that
prints it turns `appname` into a no-op, and the tab title quietly says "GLPI" again. Nothing
errors, nothing is logged, and the deploy goes green.

**At build time** — `verify-branding.sh` is the last step of the `Dockerfile`, so a tag bump
that breaks any of it fails the build instead of shipping. It checks that every logo GLPI ships
and every logo the stylesheet asks for has a counterpart in `branding/seconv-rr/dist`, that the
served files match it byte for byte, that the three things `appname` stands on are still there,
and that the image's GLPI version is inside the range the plugin declares. It reads BuildKit
bind mounts, so nothing it needs ends up in the image. Run it by hand against the checkout too:

```bash
./deploy/coolify/verify-branding.sh
```

**After a deploy** — the one thing an image cannot prove is that the plugin is *active*: that
lives in the database, in the `/var/glpi` volume. An instance where nobody ran
`plugin:activate appname`, or where someone deactivated it, serves a perfectly working site
called "GLPI". `smoke.sh` reads the login page anonymously and compares what it gets with
`dist/`:

```bash
./deploy/coolify/smoke.sh https://helpdesk.example
```

Worth running after the first deploy, after every GLPI bump, and after touching plugins.

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

Both bundled plugins declare `glpi min 11.0 / max 12.0`, so patch bumps inside 11.x need nothing.
A move to 12.0 does: `appname` and `defaultlang` both read internals (`$CFG_GLPI['app_name']`,
`$CFG_GLPI['languages']`) that a major release is free to change.

The build refuses either case rather than shipping it: `verify-branding.sh` fails when the new
tag is outside that range, when it ships a logo `dist/` does not replace, or when it moved
anything `appname` reads. Run `smoke.sh` against the deployed URL afterwards, since the plugin
being *active* is instance state the build cannot see.
