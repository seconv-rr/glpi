# Conditions by profile

Adds a **Current user profile** question to the native form builder. The question is never shown to
whoever fills the form — it answers itself with the profile active in the session — and exists only
to be picked as a criterion in the native conditions editor. Anything the condition engine controls
can then depend on the current profile:

- **visibility** of any question, comment or section;
- **validation** rules of any question;
- **conditional creation** of any destination (ticket, change, problem).

## Why a plugin is needed

GLPI 11 has no per-profile behaviour inside a form:

- Conditional visibility only reads answers. `Glpi\Form\Condition\Type` (`src/Glpi/Form/Condition/Type.php:44`)
  offers `QUESTION`, `SECTION` and `COMMENT`, and `Glpi\Form\Condition\EngineInput`
  (`src/Glpi/Form/Condition/EngineInput.php:41`) carries nothing but the answers. The current
  profile can never become a criterion.
- Access control (`AllowList`) filters **forms**, not questions, so the only native workaround is
  duplicating the whole form per profile.
- Ticket templates with hidden fields are wired to a profile (`Profile::tickettemplates_id`,
  `src/Profile.php:85`) but only apply to the classic ticket form, not to the service catalog.

Since the engine only understands answers, this plugin turns the profile *into* an answer: a
question whose value is the profile active in the session.

## How it works

`GlpiPlugin\Profilecondition\QuestionType\UserProfile` registers under its own **Context** category
and provides two condition handlers instead of the default ones:

| Handler | Operators | Meaning |
| --- | --- | --- |
| `ProfileEqualsConditionHandler` | *Is equal to*, *Is not equal to* | Compare the current profile against one picked from a dropdown of all profiles. |
| `ProfileEmptyConditionHandler` | *Is empty*, *Is not empty* | Whether there is a session profile at all — an "is the visitor logged in" check for public forms. |

The one design decision everything rests on: **the handlers ignore the answer the engine hands
them and read the profile straight from the session.** The engine evaluates conditions in three
places, each feeding it a different "answer":

- the initial render feeds stored default values (`EngineInput::fromForm()`,
  `src/Glpi/Controller/Form/RendererController.php:98`);
- the live re-evaluation feeds whatever the browser posts from the DOM
  (`src/Glpi/Controller/Form/Condition/EngineController.php:72`);
- the submit path feeds the posted answers, for mandatory validation
  (`src/Glpi/Form/AnswersHandler/AnswersHandler.php:103`), for discarding answers to hidden
  questions (`AnswersHandler::removeUnusedAnswers()`) and for conditional destination creation
  (`AnswersHandler::createDestinations()`).

By reading the session, all three see the same unforgeable value. Editing the question's hidden
input in the DOM reveals conditioned questions client side at most — server side the engine still
computes visibility from the real profile, so answers to questions the user was not supposed to
see are dropped (`removeUnusedAnswers()`), and mandatory checks only apply to genuinely visible
questions.

What the question itself does:

- `isHiddenInput()` returns `true` unconditionally, which puts
  `data-glpi-form-renderer-hidden-input` on the whole question block. CSS collapses it
  (`css/includes/components/form/_form-renderer.scss:101`), so the title and input never appear.
- `renderEndUserTemplate()` emits a hidden input carrying the session profile id, which keeps the
  question present in the submitted data so the answer is stored with the others.
- `prepareEndUserAnswer()` throws the posted value away and stores the real session profile — the
  saved answers then double as an audit trail of which profile filled the form.
- `isAllowedForUnauthenticatedAccess()` returns `true` so the question survives on public forms
  (returning `false` would drop it from the form, `templates/pages/form_renderer.html.twig:138`,
  and every condition built on it would lose its criterion). An unauthenticated visitor has no
  profile: *Is equal to* never matches, *Is empty* always does.

## Usage

1. Install and enable the plugin.
2. In the form, add a question of type **Current user profile** (category *Context*). Name it
   something recognizable — the conditions editor lists it by name. Do **not** mark it as
   mandatory: a visitor without a session has no profile, and the empty answer would block the
   whole form.
3. On any other question, comment or section, set *Visibility* to *Visible if...* (or *Hidden
   if...*) and pick the profile question as the criterion, **Is equal to**, and a profile.
   Several profiles = several conditions chained with **OR**.
4. The same criterion works in a question's validation conditions and in a destination's creation
   conditions.

### Recipe: urgency restricted by profile

To let only some profiles choose the urgency (what the former `urgencyprofile` plugin hard-coded):

1. Add a **Current user profile** question.
2. Add a core **Urgency** question, *Visible if* profile *Is equal to* Technician (OR-chain the
   other allowed profiles).
3. Leave the destination's **Urgency** field on *Answer to last "Urgency" question* (the default).

Allowed profiles answer and their choice reaches the ticket. Everyone else never sees the
question, submits no answer, and the ITIL template default applies.

### Recipe: a forced default per profile

If the restricted profiles must get a *specific* urgency (different from the template default),
use two destinations with opposite creation conditions:

- Destination A — *Created if* profile *Is equal to* Technician — urgency = answer to the urgency
  question.
- Destination B — *Created if* profile *Is not equal to* Technician — urgency = the specific
  value.

Exactly one of the two is created per submission. The same pattern gives any per-profile
destination difference (category, assignee, template...), which no hard-coded plugin could.

## Caveats

- **Conditions store profile ids, not names.** A form exported to another GLPI keeps the ids,
  which almost certainly point at different profiles there. Re-check conditions after an import.
- The dropdown lists every profile of the instance; deleting a profile leaves conditions
  referencing its id never matching (visibility falls back to the item's strategy).
- The stored answer (the submitter's profile) appears wherever saved answers are displayed, e.g.
  in the generated ticket's answer summary. That is intended — it documents which profile the
  conditions evaluated against.

## Living next to Advanced Forms

The plugin uses core APIs only. It shares no class, namespace, table, configuration key or
`$PLUGIN_HOOKS` entry with `pluginsGLPI/advancedforms`, and never imports from
`GlpiPlugin\Advancedforms`. Both plugins register through the same two core singletons, which only
ever append to a list (`QuestionTypesManager::registerPluginCategory()`,
`QuestionTypesManager::registerPluginQuestionType()`). Categories are keyed by class name
(`QuestionTypesManager::getCategoryKey()`), so this plugin's *Context* category cannot collide
with Advanced Forms' own; their weights differ too (200 against 1000).

## Replacing the former urgencyprofile plugin

This plugin replaces `plugins/urgencyprofile`, which shipped a profile-gated copy of the urgency
question with the allowed profiles hard-coded in a constant. The generic mechanism covers that use
case through the recipes above, configured per form in the UI instead of per instance in code.
Nothing is migrated: that plugin never reached a deployment, and its question type (a different
class) degrades gracefully once unregistered.

## Uninstall

Questions already created with this type keep their rows in `glpi_forms_questions`. GLPI degrades
on its own: once the class is no longer registered, `Question::getQuestionType()` returns `null`
(`src/Glpi/Form/Question.php:225`) and `Section::getQuestions()` drops the question from the form
(`src/Glpi/Form/Section.php:312`). Reinstalling brings them back untouched, which is why the
uninstall deletes nothing.

**Remove the conditions that reference the question before uninstalling.** The condition engine
loads the criterion question straight from the database and asks its type for handlers
(`src/Glpi/Form/Condition/Engine.php:368`); a question whose type no longer resolves breaks the
evaluation of the form that conditions on it. This is core behaviour for any vanished question
type, not something this plugin can compensate for.
