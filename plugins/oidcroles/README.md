# OIDC Roles

Gives a user the GLPI profiles that match their Zitadel roles, on every login through Zitadel.

## Why a plugin is needed

GLPI's authorization rules (*Administração > Regras > Regras para atribuir autorizações a um
usuário*) can assign a profile at login, but for a login that arrives in the HTTP request they
only see the authentication type, the login, the e-mail and the groups
(`RuleRightCollection::prepareInputDataForProcess()`, `src/RuleRightCollection.php:111`). No
criterion reads a role, or any other value the identity provider sends. A rule per login or per
e-mail works, but it has to be repeated for every person.

## Roles

A Zitadel role is a profile key, optionally followed by a dot and an entity id:

| Zitadel role | Grants |
| --- | --- |
| `tecnico` | Técnico on the root entity and every entity below it |
| `tecnico.12` | Técnico on entity 12 and the entities below it |
| `super-admin` | Super-Admin on the root entity and every entity below it |

Entity ids are in the ID column of *Administração > Entidades*. A new unit needs no change to
the plugin: create the role `<profile key>.<entity id>` in Zitadel and grant it.

The profile keys live in `PLUGIN_OIDCROLES_PROFILES` in `setup.php`:

| Profile key | Profile id | Profile |
| --- | --- | --- |
| `super-admin` | 4 | Super-Admin |
| `admin` | 3 | Admin |
| `supervisor` | 7 | Supervisor |
| `tecnico` | 6 | Técnico |
| `atendente` | 5 | Atendente |
| `prioridade` | 9 | Prioridade |
| `observador` | 2 | Observador |
| `leitor` | 8 | Leitor |
| `padrao` | 1 | Padrão |

The map uses ids because the profiles of this instance have been renamed before, and a rename
must not cut anybody's access. The ids are in the ID column of *Administração > Perfis*.

On top of their roles, everyone who logs in through Zitadel gets GLPI's default profile (the one
marked as default in *Administração > Perfis*, Padrão today) on the root entity and every entity
below it. No role is needed for that.

## What happens at login

| Zitadel sends | Result |
| --- | --- |
| Roles that grant something | The user holds the default profile everywhere plus one dynamic profile per role. Missing ones are added, and ones whose role is gone are removed. |
| Roles that grant nothing | Same as no roles. A role grants nothing when its profile key is not in the map or its entity does not exist; a warning naming it goes to `php-errors.log`. |
| No roles at all | The user holds only the default profile, on every entity. |

Profiles assigned by hand are not dynamic, and neither the plugin nor GLPI removes them. Dynamic
profiles show a "D" next to them in the user's *Autorizações* tab.

GLPI opens the session on the default profile of the user's preferences. When the user does not
hold that profile anymore, or never chose one, the plugin sets it to the first profile in the
order of the map above that the user got from a role: someone with `super-admin` and
`tecnico.12` opens on Super-Admin, someone with only `tecnico.12` on Técnico. A preference the
user still holds is left alone.

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
per granted role, plus the default profile, to `_ldap_rules.rules_entities_rights`. That is the input a matched authorization
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
   roles, and everybody gets the default profile alone.
2. Roles: create the roles, `<profile key>` or `<profile key>.<entity id>`.
3. Authorizations: grant the roles to users.

With **Check authorization on Authentication** also enabled, Zitadel refuses users who hold no
role in the project. Leave it off if everyone in the organization should reach GLPI with the
default profile.

## Install

```bash
php bin/console plugin:install --username=glpi oidcroles
php bin/console plugin:activate oidcroles
```

## Changing the map

Only needed for a new profile. Add its key and id to `PLUGIN_OIDCROLES_PROFILES` in `setup.php`
and redeploy; where it goes in the list sets its priority for the session's starting profile.
Keys are compared exactly, including case. The change reaches each user at their next login
through Zitadel.

## Caveats

- Before `SECONV_OIDC=on`, make sure one Zitadel account holds `super-admin` and has logged
  in once. A Super-Admin assigned by hand also survives, but only for that account.
- Disabling "Assert Roles on Authentication" later takes every role-granted profile away, one
  user at a time as they log in. Everybody is left with the default profile.
- When a role moves a profile from one entity to another (`tecnico.12` becomes `tecnico.15`) and
  that profile was the user's default, GLPI clears the default while deleting the old row
  (`Profile_User::post_deleteFromDB()`, `src/Profile_User.php:1236`). That one session opens on
  the first profile in alphabetical order; the next login sets the default again.
- A profile deleted in GLPI leaves its id in the map pointing at nothing. The role then
  grants a row GLPI ignores. Remove the entry from the map.
