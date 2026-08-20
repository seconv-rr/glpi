# Urgency by Profile

Adds an **Urgency (restricted by profile)** question to the native form builder. Profiles listed in
`PLUGIN_URGENCYPROFILE_ALLOWED_PROFILES` answer it like any urgency question; everyone else never
sees it and gets the default value configured on the question.

## Why a plugin is needed

GLPI 11 has no per-profile field visibility inside a form:

- Conditional visibility only reads answers. `Glpi\Form\Condition\Type` (`src/Glpi/Form/Condition/Type.php:44`)
  offers `QUESTION`, `SECTION` and `COMMENT`, and `Glpi\Form\Condition\EngineInput`
  (`src/Glpi/Form/Condition/EngineInput.php:41`) carries nothing but the answers. The current
  profile can never become a criteria.
- Access control (`AllowList`) filters **forms**, not questions, so the only native workaround is
  duplicating the whole form per profile.
- Ticket templates with hidden fields are wired to a profile (`Profile::tickettemplates_id`,
  `src/Profile.php:85`) but only apply to the classic ticket form, not to the service catalog.

What the renderer *does* expose is `QuestionTypeInterface::isHiddenInput()`
(`templates/pages/form_renderer.html.twig:154`), asked of the question **type**. A plugin type can
answer it from the session, which is exactly the decision this plugin needs to take.

## How it works

`GlpiPlugin\Urgencyprofile\QuestionType\UrgencyByProfile` is a sibling of the core
`QuestionTypeUrgency` — that class is `final`, and subclassing it would tie the plugin to its
internals anyway. It reports the core `QuestionTypeCategory::URGENCY` category, so the form builder
lists it right next to the core urgency question and no extra category has to be registered.

For a profile outside the allow list:

- `isHiddenInput()` returns `true`, which puts `data-glpi-form-renderer-hidden-input` on the whole
  question block. CSS collapses it (`css/includes/components/form/_form-renderer.scss:101`), so the
  title, the mandatory asterisk and the input disappear together.
- `renderEndUserTemplate()` emits a hidden input carrying the question's default value. The value
  has to be submitted: `AnswersHandler::validateAnswers()`
  (`src/Glpi/Form/AnswersHandler/AnswersHandler.php:107`) enforces mandatory questions server side
  and knows nothing about `isHiddenInput()`.
- `prepareEndUserAnswer()` throws the posted value away and recomputes the default. The hidden
  input sits in the DOM and can be edited before submitting, so it is never trusted.

An unauthenticated visitor on a public form has no profile and is treated as a regular user.

### The extra destination field

The core urgency destination field matches urgency questions by exact class name — both the
question dropdown it offers (`src/Glpi/Form/Destination/CommonITILField/UrgencyField.php:189`) and
its default *last valid answer* strategy
(`src/Glpi/Form/Destination/CommonITILField/UrgencyFieldStrategy.php:98`) compare against
`QuestionTypeUrgency`, and `Form::getQuestionsByTypes()` filters with `get_class()` rather than
`instanceof` (`src/Glpi/Form/Form.php:727`). A plugin question type is therefore invisible to it,
and the answer would never reach the ticket.

`GlpiPlugin\Urgencyprofile\Destination\UrgencyByProfileField` closes that gap. It is registered on
`AbstractCommonITILFormDestination`, which covers Ticket, Change and Problem in one go because
`getPluginCommonITILConfigFields()` matches with `is_a()`
(`src/Glpi/Form/Destination/FormDestinationManager.php:180`).

It appears in *Destinations > Properties* as **Urgency (restricted by profile)** with three
choices:

| Choice | Effect |
| --- | --- |
| Answer to the last restricted urgency question | Default. Uses the last answered question of this plugin's type. |
| *(a specific question)* | Uses that question's answer. |
| Do not set the urgency | Leaves the urgency alone. |

The field weighs 71 against the core field's 70 and fields are applied in weight order
(`src/Glpi/Form/Destination/AbstractCommonITILFormDestination.php:286`), so it runs last — but it
only writes `$input['urgency']` when it actually holds a valid, enabled urgency level. A form that
also carries a core urgency question keeps working, and a question left without a default value
answers `0`, which is not a level, so the ITIL template default wins.

## Configuration

`PLUGIN_URGENCYPROFILE_ALLOWED_PROFILES` in `setup.php`:

```php
const PLUGIN_URGENCYPROFILE_ALLOWED_PROFILES = [
    'Super-Admin',
    'Admin',
    'Supervisor',
    'Technician',
];
```

Entries are profile names, matched against `glpi_profiles.name`, or numeric profile ids. Names are
preferred: ids differ from one GLPI to the next, names survive an export. They are resolved once
per request by `GlpiPlugin\Urgencyprofile\ProfileGate`.

An empty list hides the question from everyone. The form builder shows the resolved list under the
question's default value, read back from the database, so a name that matches nothing is visibly
missing there instead of silently doing nothing at runtime.

The setting is instance-wide, not per question: the renderer builds the type instance through
`Question::getQuestionType()` (`src/Glpi/Form/Question.php:230`) without any question context, so
`isHiddenInput()` has nothing but the session to go on.

## Usage

1. Install and enable the plugin.
2. In the form, add a question of type **Urgency (restricted by profile)** (category *Urgency*).
3. Set its **default value** — that is what restricted profiles will answer. Leave it empty only if
   you want the ITIL template default to apply instead.
4. In the form's destination, set **Urgency (restricted by profile)** to *Answer to the last
   restricted urgency question* (already the default), and leave the core **Urgency** field on
   *From template* so the two do not fight over the same value.
5. Remove the old core urgency question from the form. Existing forms keep theirs; the two question
   types are unrelated and answers are not migrated.

## Living next to Advanced Forms

The plugin uses core APIs only. It shares no class, namespace, table, configuration key or
`$PLUGIN_HOOKS` entry with `pluginsGLPI/advancedforms`, and never imports from
`GlpiPlugin\Advancedforms`. Both plugins register through the same two core singletons, which only
ever append to a list (`QuestionTypesManager::registerPluginQuestionType()`,
`FormDestinationManager::registerPluginCommonITILConfigField()`), and this plugin reuses the **core**
urgency category rather than declaring one, so Advanced Forms' own category is never involved.
Nothing here is affected by what that plugin adds, renames or drops in a future release.

Checked against Advanced Forms 1.3.0: its only destination field is `PreReservationField`, which
never touches `urgency`; its `AdvancedCategory` weighs 1000 and keys on its own class name, so it
cannot collide with the core urgency category; and it overrides no core template.

One known limitation, not a conflict: Advanced Forms' *Table* question accepts a fixed list of
column types that includes the core `QuestionTypeUrgency` but not this plugin's type
(`src/Model/QuestionType/TableQuestion.php`). A restricted urgency cannot be used as a table
column.

## Uninstall

Questions already created with this type keep their rows in `glpi_forms_questions`. GLPI degrades on
its own: once the class is no longer registered, `Question::getQuestionType()` returns `null`
(`src/Glpi/Form/Question.php:225`) and `Section::getQuestions()` drops the question from the form
(`src/Glpi/Form/Section.php:312`). Reinstalling brings them back untouched, which is why the
uninstall deletes nothing.

Tickets created while the plugin was active are not affected — the urgency was written onto them at
creation time.
