# Entity service catalog

Adds a helpdesk tile that opens the **service catalog of a chosen entity**, instead of the one the
visitor happens to be sitting in. Configure it where the native tiles are configured:
**Administração > Entidades > (entidade) > Página inicial do Helpdesk > Adicionar quadro**, or on a
profile.

## Why a plugin is needed

The service catalog has no entity parameter. It reads the session and nothing else:

- `Glpi\Controller\ServiceCatalog\IndexController` loads
  `Entity::getById($session->getCurrentEntityId())` (`src/Glpi/Controller/ServiceCatalog/IndexController.php:80`),
  and so does the AJAX controller behind the search and the category browsing
  (`src/Glpi/Controller/ServiceCatalog/ItemsController.php:99`).
- `FormProvider` restricts the forms to the session's active entities
  (`src/Glpi/Form/ServiceCatalog/Provider/FormProvider.php:102`).

The native **GLPI page** tile can therefore only point at the current entity's catalog: its URL is
the constant `/ServiceCatalog` (`src/Glpi/Helpdesk/Tile/GlpiPageTile.php:158`).

## How it works

`GlpiPlugin\Entitycatalog\EntityCatalogTile` is a tile like the native ones, with one extra field:
the target entity. Its URL is not the catalog but a route of this plugin,
`/plugins/entitycatalog/ServiceCatalog/{tile id}`, which switches the session to that entity
(`Session::changeActiveEntities()`) and redirects to `/ServiceCatalog`. The catalog then sees
exactly the session it expects, so the form list, the categories, the search and the ticket the
form creates all work against the target entity without any of them being patched.

The route carries the **tile id**, not the entity id, so the link cannot be edited into a switch to
an entity no administrator configured.

## What it does not do

- **The switch is not scoped to the request.** The visitor stays in the target entity afterwards,
  exactly as if they had used the entity selector — GLPI has no per-request entity override. Add a
  second tile pointing back if people need the round trip.
- **It grants no access.** The route refuses any entity the visitor's active profile does not
  already allow, and the tile hides itself in that case, so the home page never shows a tile that
  would 403. Someone who has no profile on the TI entity still cannot see the TI catalog; give them
  a profile there first (**Administração > Usuários > (usuário) > Autorizações**).
- **The target entity needs its catalog enabled** (`Entidade > Assistência > Habilitar o catálogo
  de serviços`, inherited from the parent when left on "herdar"). Otherwise the tile hides itself,
  the same way the native one does.

## Install

```bash
php bin/console plugin:install --username=glpi entitycatalog
php bin/console plugin:activate entitycatalog
```

Then add the tile: **Adicionar quadro > Tipo: Catálogo de serviços de outra entidade**, fill in the
title, the description, the illustration, and pick the entity.

## Uninstall

Uninstalling leaves the tiles in the database and stops displaying them: GLPI drops rows whose
itemtype no longer resolves (`src/Glpi/Helpdesk/Tile/TilesManager.php:152`), so reinstalling brings
them back untouched. To remove them for good, **delete the tiles in the UI before uninstalling** —
that path also cleans up the rows linking them to their entity or profile.
