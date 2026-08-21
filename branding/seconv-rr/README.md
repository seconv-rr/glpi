# SECONV-RR branding

Replaces the GLPI logos, favicon and browser-tab title with the Estado de Roraima coat of
arms and the name SECONV-RR.

## What is here

| Path | Role |
| --- | --- |
| `src/brasao-roraima.png` | Source coat of arms, 924x1024 RGBA, transparent background |
| `generate-assets.py` | Renders `dist/` from the source. Needs Pillow and Liberation Sans Bold |
| `dist/` | The nine logo variants plus `favicon.ico`, named exactly as GLPI serves them |
| `apply.sh` | Copies `dist/` over `public/pics`, then verifies the result |
| `git-guard.sh` | Keeps the overwritten GLPI files from showing up as modified, and from blocking pulls |

The check `apply.sh` ends with lives in `deploy/coolify/verify-branding.sh`, because the
production build runs the same one — see [Catching it when it breaks](#catching-it-when-it-breaks).

The logos are laid out as coat of arms on the left, `SECONV-RR` to the right of it.

## The name

The images cover everything visual, but the product name itself is a PHP value —
`$CFG_GLPI['app_name']`, assigned literally in `src/autoload/CFG_GLPI.php:47` — and it drives the
browser-tab title, the notification-mail footer and the MFA issuer label. `plugins/appname` sets
it; see its README. No third-party plugin is involved.

> Do **not** install i-Vertix's `mod` (UI Branding) plugin to do this. Its `plugin_mod_activate()`
> copies its own sample logos over `public/pics`, undoing everything below, and turns the
> i-Vertix photo background on for the login page.

## Applying it

```bash
python3 branding/seconv-rr/generate-assets.py   # only after changing the source or the layout
./branding/seconv-rr/apply.sh
```

That is the development stack. In production the same files are copied in at build time
(`deploy/coolify/Dockerfile`): `public/pics` is an image layer, so anything written there at
runtime disappears on the next redeploy.

To remove the branding, `git-guard.sh off` and then `git checkout -- public/pics`.

## Which file shows up where

GLPI 11.0 wires the logos in `css/includes/_base.scss:65-70`. Six of the ten files are read:

| File | Where |
| --- | --- |
| `logo-GLPI-100-white.png` | header / expanded side menu, on the navy `#2f3f64` background |
| `logo-G-100-white.png` | collapsed side menu |
| `logo-GLPI-100-black.png` | the same header, when the palette puts it on a light background |
| `logo-G-100-black.png` | the same collapsed menu, on a light background |
| `logo-GLPI-250-black.png` | login card, light palette |
| `logo-GLPI-250-white.png` | login card, dark palette |

The three `-grey` variants are generated anyway so a palette change cannot fall back to a
GLPI-branded image. `verify-branding.sh` reads the same stylesheet, so this list stops being a
thing to remember: if GLPI points a variable somewhere else, the check fails.

## Living with git

`apply.sh` writes over ten files that GLPI itself tracks (`public/pics/favicon.ico` and
`public/pics/logos/logo-*.png`). `git-guard.sh` handles the fallout:

```bash
./branding/seconv-rr/git-guard.sh on        # hide them (skip-worktree) — already done once
./branding/seconv-rr/git-guard.sh status    # list what is hidden
./branding/seconv-rr/git-guard.sh off       # let git see them again
```

`skip-worktree` alone is not enough when upstream touches one of those files: git still
refuses to overwrite a worktree it considers dirty, and `git pull` aborts with *"Your local
changes to the following files would be overwritten"*. Wrap the git command instead — it
restores the pristine files, runs the command against a clean tree, then puts the branding
back and re-hides it, including when the command fails:

```bash
./branding/seconv-rr/git-guard.sh run git pull --rebase
./branding/seconv-rr/git-guard.sh run git checkout 11.0/bugfixes
```

The flag lives in `.git/index`, so it is local to one clone — it is not pushed and whoever
clones the repo does not inherit it. Run `git-guard.sh on` once per clone.

## Catching it when it breaks

Both halves of the branding break without a word. GLPI can add or rename a logo, and then
`apply.sh` — which only writes names `dist/` already has — leaves the new one GLPI-branded. Or
upstream moves what `plugins/appname` stands on, and the tab title goes back to "GLPI" with the
plugin still loading fine. Neither shows up as an error anywhere.

`deploy/coolify/verify-branding.sh` turns both into a failure. It runs on its own at the end of
`apply.sh`, so a pull that changed something is caught the next time the branding is applied,
and again as the last step of the production `Dockerfile`, where it fails the build:

```bash
./deploy/coolify/verify-branding.sh                    # the checkout
./deploy/coolify/verify-branding.sh --glpi-root /var/www/glpi --dist /tmp/dist
```

It checks that every logo in `public/pics/logos` and every logo `_base.scss` asks for exists in
`dist/`, that what is served matches `dist/` byte for byte, that `$CFG_GLPI['app_name']`, the
`plugin_<key>_boot()` hook and `config('app_name')` in the layout are all still where `appname`
expects them, and that GLPI's version is inside the range the plugin declares.

What it cannot check is whether the plugin is installed and active — that is instance state, in
the database. `deploy/coolify/smoke.sh <url>` reads a running site's login page for that.

## Known effects

- The string "GLPI" still appears in the page footer and in the About dialog; those are
  translated literals, not `app_name`.
- The login page background is GLPI's own — nothing here replaces it.
