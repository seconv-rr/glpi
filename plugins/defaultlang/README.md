# Default Language

Locks this GLPI instance to **pt_BR**. Always, and only — there is no other language left to
negotiate, select or store.

## Why a plugin is needed

Setting *Setup > General > Default language* is not enough:

- `Session::getPreferredLanguage()` (`src/Session.php:971`) reads the browser's `Accept-Language`
  header **first** and only falls back to `$CFG_GLPI['language']` when no header value matches a
  locale GLPI ships. Every workstation whose browser is set to English gets the login page, the
  anonymous helpdesk and the password-reset pages in English.
- Any user can pick another language in their own preferences, and the `glpi` account the
  installer creates already carries `en_GB` (`install/empty_data.php:9451`).

## How it works

Everything hangs off one array. `$CFG_GLPI['languages']` (`src/autoload/CFG_GLPI.php:105`) is what
the header negotiation matches against (`src/Session.php:984`), what fills every language dropdown
(`Dropdown::getLanguages()`, `src/Dropdown.php:1568`), and what validates a user's stored
preference (`User::computePreferences()`, `src/User.php:318`). The plugin reduces it to the single
pt_BR entry, so:

- `Accept-Language` can only ever resolve to pt_BR;
- the language selector in user preferences and in *Setup > General* offers one option;
- a user row still holding `en_GB` is reset to the default on the spot, because
  `computePreferences()` discards any language that is no longer in the list.

That happens in `plugin_defaultlang_boot()`. The plugin boot function is deliberate: the kernel
fires it at priority 140, before `SessionStart` (130) and `LoadLanguage` (120), so the very first
language resolution of the request already sees the restricted list
(`src/Glpi/Kernel/ListenersPriority.php:64`).

A session opened *before* the plugin was activated still carries its old language, and
`Session::loadLanguage()` has already run by the time plugins are initialized (priority 110).
`plugin_defaultlang_force_session_language()`, hooked on `Hooks::POST_INIT`, overwrites
`$_SESSION['glpilanguage']` and reloads the translations. It reloads at most once per session —
after that the session already holds pt_BR and the hook returns immediately.

**On install** (`plugin_defaultlang_install`):

- records the current default language in the `plugin:defaultlang` config context;
- sets the core `language` config to `pt_BR`;
- sets `glpi_users.language` to `NULL` for every existing user, so the stored rows match what the
  runtime enforces.

**On uninstall**: the default language recorded at install time is restored, and the locale list
goes back to normal as soon as the plugin is deactivated. The per-user languages cleared by the
install are **not** restored — they were dropped, not saved.

## Caveats

- **Notification template translations written for another language will break their list screen.**
  `NotificationTemplateTranslation` looks the language name up without a guard
  (`src/NotificationTemplateTranslation.php:83` and `:162`), so a row whose `language` is, say,
  `fr_FR` raises an undefined-key error once that locale is gone. A fresh instance only has rows
  with an empty `language` ("Default translation"), which are unaffected. Delete any leftover
  per-language translation before activating the plugin.
- Dashboard card caches built for other languages are no longer cleared on plugin
  install/uninstall (`src/Plugin.php:3350` iterates the same list). Harmless — nothing renders in
  those languages anymore.

## Install

```bash
php bin/console plugin:install --username=glpi defaultlang
php bin/console plugin:activate defaultlang
```

## Changing the language

Edit `PLUGIN_DEFAULTLANG_LANGUAGE` in `setup.php`. It must be a key of `$CFG_GLPI['languages']`;
an unknown value makes the plugin log a warning and leave GLPI untouched rather than empty the
list and break every page. *Setup > General > Default language* is no longer a real choice — the
dropdown only offers the locked language.
