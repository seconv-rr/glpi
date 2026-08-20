# SECONV-RR branding

Replaces the GLPI logos, favicon and browser-tab title with the Estado de Roraima coat of
arms and the name SECONV-RR.

## What is here

| Path | Role |
| --- | --- |
| `src/brasao-roraima.png` | Source coat of arms, 924x1024 RGBA, transparent background |
| `generate-assets.py` | Renders `dist/` from the source. Needs Pillow and Liberation Sans Bold |
| `dist/` | The nine logo variants plus `favicon.ico`, named exactly as GLPI serves them |
| `apply.sh` | Copies `dist/` over `public/pics` |
| `git-guard.sh` | Keeps the overwritten GLPI files from showing up as modified, and from blocking pulls |

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

GLPI 11.0 wires the logos in `css/includes/_base.scss:65-72`. Only four of the ten files are
actually read:

| File | Where |
| --- | --- |
| `logo-GLPI-100-white.png` | header / expanded side menu, on the navy `#2f3f64` background |
| `logo-G-100-white.png` | collapsed side menu |
| `logo-GLPI-250-black.png` | login card, light palette |
| `logo-GLPI-250-white.png` | login card, dark palette |

The `-black` and `-grey` variants of the two smaller sizes are generated anyway so a palette
change cannot fall back to a GLPI-branded image.

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

## Known effects

- The string "GLPI" still appears in the page footer and in the About dialog; those are
  translated literals, not `app_name`.
- The login page background is GLPI's own — nothing here replaces it.
