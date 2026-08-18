# SECONV-RR branding

Replaces the GLPI logos, favicon and browser-tab title with the Estado de Roraima coat of
arms and the name SECONV-RR.

## What is here

| Path | Role |
| --- | --- |
| `src/brasao-roraima.png` | Source coat of arms, 924x1024 RGBA, transparent background |
| `generate-assets.py` | Renders `dist/` from the source. Needs Pillow and Liberation Sans Bold |
| `dist/` | The nine logo variants plus `favicon.ico`, named exactly as GLPI serves them |
| `apply.sh` | Copies `dist/` into the plugin and into `public/pics`, and sets the tab title |
| `git-guard.sh` | Keeps the overwritten GLPI files from showing up as modified, and from blocking pulls |

The logos are laid out as coat of arms on the left, `SECONV-RR` to the right of it.

## The plugin

Branding is done by [i-Vertix/glpi-modifications](https://github.com/i-Vertix/glpi-modifications)
(folder name `mod`, GPL-3.0), which requires GLPI >= 11.0 < 12.0 and PHP >= 8.2. It is **not**
tracked here: `.gitignore` excludes `plugins/*`, so a fresh clone has to fetch it again.

```bash
curl -sLO https://github.com/i-Vertix/glpi-modifications/releases/download/11.0.5/glpi-mod-11.0.5.tar.gz
tar -xzf glpi-mod-11.0.5.tar.gz -C plugins/
```

## Applying it

Order matters. The plugin's `install()` copies the untouched GLPI logos to
`files/_plugins/mod/backups`, and that copy is the only thing `Restore` and the uninstall
routine can put back. Overwriting `public/pics` before it runs destroys the originals.

```bash
bin/console plugin:install mod
bin/console plugin:activate mod
./branding/seconv-rr/apply.sh
```

To remove the branding, uninstall the plugin **from the GLPI plugins page** before deleting
`plugins/mod` — that restores the backups.

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

- The plugin changes the browser-tab title only. The string "GLPI" still appears in the
  footer and in the About dialog.
- The plugin ships no `pt_BR` locale (de, en, es, fr, it only), so its configuration screen
  stays in English.
- The login page background is left untouched (`login="0"` in `modifiers.ini`).
