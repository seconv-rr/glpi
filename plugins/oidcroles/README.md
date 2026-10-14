# OIDC Roles

Gives a user the GLPI profiles that match their Zitadel roles, on every login through Zitadel.

## Why a plugin is needed

GLPI's authorization rules (*Administração > Regras > Regras para atribuir autorizações a um
usuário*) can assign a profile at login, but for a login that arrives in the HTTP request they
only see the authentication type, the login, the e-mail and the groups
(`RuleRightCollection::prepareInputDataForProcess()`, `src/RuleRightCollection.php:111`). No
criterion reads a role, or any other value the identity provider sends. A rule per login or per
e-mail works, but it has to be repeated for every person.

## The map

`PLUGIN_OIDCROLES_PROFILES` in `setup.php`, from Zitadel role key to GLPI profile id:

| Zitadel role key | Profile id | Profile |
| --- | --- | --- |
| `padrao` | 1 | Padrão |
| `observador` | 2 | Observador |
| `admin` | 3 | Admin |
| `super-admin` | 4 | Super-Admin |
| `atendente` | 5 | Atendente |
| `tecnico` | 6 | Técnico |
| `supervisor` | 7 | Supervisor |
| `leitor` | 8 | Leitor |
| `prioridade` | 9 | Prioridade |

The map uses ids because the profiles of this instance have been renamed before, and a rename
must not cut anybody's access. The ids are in the ID column of *Administração > Perfis*.

Every profile is granted on the root entity and its children (`PLUGIN_OIDCROLES_ENTITY` and
`PLUGIN_OIDCROLES_RECURSIVE`). A Zitadel role says nothing about entities.

## What happens at login

| Zitadel sends | Result |
| --- | --- |
| Roles that are in the map | The user holds exactly those profiles as dynamic profiles. Missing ones are added, and ones whose role is gone are removed. |
| Only roles that are not in the map | The user holds no dynamic profile. With no profile assigned by hand either, GLPI refuses the login. A warning naming the roles goes to `php-errors.log`. |
| No roles claim | The plugin does nothing and GLPI behaves as if it were not installed: a new account gets the default profile. Profiles the plugin granted earlier are removed by GLPI, because nothing grants them anymore. |

A user with several roles gets several profiles. GLPI opens the session on the default profile
from the user's preferences, or on the first one in alphabetical order when none is set.

Profiles assigned by hand are not dynamic, and neither the plugin nor GLPI removes them. Dynamic
profiles show a "D" next to them in the user's *Autorizações* tab.

Local logins, API tokens and every other save of a user are left alone. The plugin only acts
on the save `Auth::login()` makes for a user authenticated outside GLPI, before anybody is
logged in.

## How it works

`mod_auth_openidc` hands every claim to PHP as a server variable. Zitadel's roles claim is
`urn:zitadel:iam:org:project:roles`, an object keyed by role:

```json
{"tecnico": {"<org id>": "<org domain>"}}
```

It arrives JSON-encoded in `$_SERVER['OIDC_CLAIM_urn-zitadel-iam-org-project-roles']`; the module
replaces the colons with `-`. The module also sends the claim as a request header, which PHP
shows with an `HTTP_` prefix. The plugin ignores that copy. A variable without the prefix can
only have been set by Apache, so no request can bring roles of its own, even on the paths
`on.conf` leaves open or while `SECONV_OIDC` is unset.

`Auth::login()` saves the user on every login (`src/Auth.php:1085` and `:1097`). The plugin hooks
`PRE_ITEM_ADD` and `PRE_ITEM_UPDATE` on `User` and adds one `[entity, profile, recursive]` entry
per mapped role to `_ldap_rules.rules_entities_rights`. That is the input a matched authorization
rule produces, and `User::applyRightRules()` (`src/User.php:1565`) already knows what to do with
it: it compares the list with the user's dynamic rows in `glpi_profiles_users`, adds the missing
ones and deletes the rest. Rows that already match are left as they are, so a login that changes
nothing writes nothing. Rights from an authorization rule that matched are kept alongside the
plugin's.

The plugin stores nothing. Install and uninstall are no-ops, and after an uninstall GLPI drops
the dynamic profiles by itself at each user's next login.

## Zitadel

In the project:

1. General settings: enable **Assert Roles on Authentication**. Without it Zitadel sends no
   roles claim and the plugin does nothing.
2. Roles: create one role per row of the map, with the key exactly as written there.
3. Authorizations: grant the roles to users.

With **Check authorization on Authentication** also enabled, Zitadel refuses users who hold no
role in the project, so everyone who reaches GLPI arrives with at least one.

## Install

```bash
php bin/console plugin:install --username=glpi oidcroles
php bin/console plugin:activate oidcroles
```

## Changing the map

Edit `PLUGIN_OIDCROLES_PROFILES` in `setup.php` and redeploy. Role keys are compared exactly,
including case. The change reaches each user at their next login through Zitadel.

## Caveats

- Before `SECONV_OIDC=on`, make sure one Zitadel account holds `super-admin` and has logged
  in once. A Super-Admin assigned by hand also survives, but only for that account.
- Disabling "Assert Roles on Authentication" later removes the profiles the plugin granted,
  one user at a time as they log in. Users with nothing assigned by hand are then refused.
- A profile deleted in GLPI leaves its id in the map pointing at nothing. The role then
  grants a row GLPI ignores. Remove the entry from the map.
