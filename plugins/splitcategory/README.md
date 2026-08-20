# Split Category

Splits the ITIL category question of a form into two chained dropdowns — **Categoria** and
**Subcategoria** — instead of the single tree dropdown GLPI renders by default.

```
Categoria:     [ Paineis - PAINEIS          ▾ ]
Subcategoria:  [ Acompanhamento de Emendas  ▾ ]
```

The second dropdown stays disabled until a category is picked, and then only lists what lives under
it. Picking just a category and leaving the subcategory empty is a valid answer.

## The problem

A *Dropdown* question pointed at **ITILCategory** renders every category, at every depth, in a single
list, under the entity the category belongs to:

```
Raiz > TI
  »Paineis - PAINEIS
    »Acompanhamento de Contas BB Ágil
    »Acompanhamento de Emendas
    »Convênios – Despesa
```

The entity header carries no information on a single-entity instance, the `»` markers are the only
thing telling a category from a subcategory, and the list grows with the whole tree instead of the
branch the user cares about.

## Why this is not a new question type

The obvious shape for this would be a question type of its own, registered next to the native
*Dropdown* in the question picker. It does not work, because **GLPI matches question types by exact
class name, never by inheritance**:

```php
// src/Glpi/Form/Form.php:727
$type = $question->getQuestionType() !== null ? get_class($question->getQuestionType()) : self::class;
return in_array($type, $types);

// src/Glpi/Form/AnswersSet.php:133
fn(Answer $answer) => $answer->getRawType() == $type
```

The "ITIL category" field of a form destination goes through both of them — `LAST_VALID_ANSWER`
filters the answers with `getAnswersByType(QuestionTypeItemDropdown::class)`
(`src/Glpi/Form/Destination/CommonITILField/ITILCategoryFieldStrategy.php:97`), and the
`SPECIFIC_ANSWER` strategy only offers questions returned by
`getQuestionsByType(QuestionTypeItemDropdown::class)`
(`src/Glpi/Form/Destination/CommonITILField/ITILCategoryField.php:185`).

A question type declared by a plugin — even one extending `QuestionTypeItemDropdown` — is therefore
invisible to both, and the ticket created from the form would carry no category at all. There is no
hook to register a destination field either, so nothing can be plugged in on that side.

> The same limitation applies to the `flatdropdown` plugin: a question created with its
> *"Lista suspensa (sem agrupamento por entidade)"* type does not feed `itilcategories_id`.

## The approach

The question stays a **native** `QuestionTypeItemDropdown` and its answer keeps the exact same shape,
so destinations, visibility conditions, exports, translations and the answers view all keep working.
Only the way it is *displayed* changes, and only on the page where the form is answered:

- `public/js/split_category.js` walks the rendered form, finds the questions whose
  `answers_<id>[itemtype]` is `ITILCategory`, hides the native dropdown and disables it so it is no
  longer serialized, and builds the two dropdowns plus a hidden `answers_<id>[items_id]` input
  carrying the answer. The hidden input holds the subcategory when one is picked, the category
  otherwise, and `0` when nothing is selected — which is what GLPI reads as an empty answer
  (`QuestionTypeItem::transformConditionValueForComparisons()`, `src/Glpi/Form/QuestionType/QuestionTypeItem.php:511`).
- `src/Controller/CategoryTreeController.php` answers `POST /plugins/splitcategory/CategoryTree` with
  the categories that question is allowed to show. It applies the form's own access policies (so
  public forms keep working without a session) and rebuilds the SQL restrictions from the question's
  configuration — helpdesk visibility, the request/incident/change filter, the tree root, the subtree
  depth, the entity restriction — never from anything the browser sends.

Top level categories are the ones whose parent is not part of the allowed set, so a subtree root, or
a category whose parent is hidden from the helpdesk, behaves as a category of its own.

Uninstalling or deactivating the plugin brings back the native dropdown with no migration: nothing was
ever stored differently.

## Install

The plugin owns no table and no configuration.

1. `Configuração > Plug-ins`
2. Install, then activate **Split Category**.

Nothing to change in the forms: every ITIL category question is split as soon as the plugin is active.

## Notes

- **It applies to every ITIL category dropdown question**, on every form. There is no per-question
  switch, because a plugin cannot add a setting to a native question type.
- **The whole allowed tree is sent in one request** and both dropdowns are built from it. That is fine
  for a few hundred categories; an instance with thousands of them would be better served by a
  paginated endpoint.
- **Dropdown translations are not applied.** The endpoint reads `name` and `completename` straight
  from `glpi_itilcategories`, so a category translated through `Configuração > Dicionários` would
  still show its original name.
- **The labels are hardcoded in pt_BR.** This instance is locked to pt_BR by the `defaultlang` plugin,
  so there is no catalog to look a msgid up in.
- **If the request for the tree fails**, the native dropdown is put back, so the question is never left
  without an input.
