# Flat Dropdown

Adds a form question type — **"Lista suspensa (sem agrupamento por entidade)"** — that behaves
exactly like GLPI's native *Dropdown* question, minus the entity header above the options.

## The problem

In the form editor, a *Dropdown* question pointed at **ITILCategory** renders its options under a
group header carrying the entity's complete name:

```
Raiz > TI
  »Paineis - PAINEIS
    »Acompanhamento de Contas BB Ágil
    »Acompanhamento de Emendas
    »Convênios – Despesa
```

That `Raiz > TI` line is the entity group, not a category. On a single-entity instance it carries no
information and only costs a row of the dropdown.

It is not a display preference. `Setup > General > Default values` has two related settings —
*"Mostrar o nome completo do menu em árvore em listas suspensas"* (`use_completename_in_tree`) and
its search-results counterpart — but neither one controls this header; they change how the *items*
are labelled, not whether the group exists.

The grouping is decided in `Dropdown::getDropdownValue()`:

```php
// src/Dropdown.php:3102-3105
// Force recursive items to multi entity view
if ($recur) {
    $multi = true;
}
```

`$recur` is `$item->maybeRecursive()`, which is always true for `ITILCategory`, so `$multi` is always
on and the results are always emitted as `['text' => <entity>, 'children' => [...]]`
(`src/Dropdown.php:3238` and `3386`). No setting, parameter or hook turns it off.

## The approach

The plugin does **not** patch the core dropdown — that would strip the header from every recursive
dropdown in GLPI (locations, groups, categories, …), including the multi-entity cases where it is the
only thing telling two identically named items apart.

Instead it registers a *second* question type next to the native one:

- `QuestionTypeFlatItemDropdown` extends the core `QuestionTypeItemDropdown`, so the itemtype picker,
  the ITIL categories filter, the tree root and the subtree depth all keep working unchanged.
- Its fill-time template is the core one with a single addition: `url`, pointing the select2 at
  `/plugins/flatdropdown/ajax/getFlatDropdownValue.php` instead of `/ajax/getDropdownValue.php`.
  `Dropdown::show()` reads that option straight from the caller (`src/Dropdown.php:154`, overridden by
  the loop at `164-168`).
- That endpoint calls the very same `Dropdown::getDropdownValue()` and only replaces each entity group
  by its own children. Rights, entity restriction, IDOR validation, search and pagination are all
  still done by the core method, so the plugin cannot widen what a user is allowed to see.

Form authors pick whichever field they want per question, and uninstalling the plugin cannot break a
form built on the native one.

## Result

```
»Paineis - PAINEIS
  »Acompanhamento de Contas BB Ágil
  »Acompanhamento de Emendas
  »Convênios – Despesa
```

The ancestor rows that indent the tree are kept — they live inside `children` and are what makes the
hierarchy readable. Only the entity header is removed.

## Install

The plugin owns no table and no configuration.

1. `Configuração > Plug-ins`
2. Install, then activate **Flat Dropdown**.
3. In the form editor, add a question and choose *Lista suspensa (sem agrupamento por entidade)*.

## Notes

- **Existing questions are not converted.** A question already created as a native *Dropdown* keeps
  its header until it is replaced by one of this type.
- **Uninstalling with questions still using this type** leaves those rows in place, but their class is
  no longer registered — the form editor reports them as an unknown type until the plugin is
  reinstalled or the questions are switched back to a native dropdown.
- **The label is hardcoded in pt_BR.** This instance is locked to pt_BR by the `defaultlang` plugin,
  so there is no translation catalog to look a msgid up in. Add `locales/` and wrap
  `QuestionTypeFlatItemDropdown::getName()` in `__()` if the instance ever serves more than one
  language.
