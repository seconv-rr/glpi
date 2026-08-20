<?php

/**
 * -------------------------------------------------------------------------
 * Urgency by Profile plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Urgencyprofile\QuestionType;

use CommonITILObject;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\Condition\ConditionHandler\UrgencyConditionHandler;
use Glpi\Form\Condition\ConditionValueTransformerInterface;
use Glpi\Form\Condition\UsedAsCriteriaInterface;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionType;
use Glpi\Form\QuestionType\QuestionTypeCategory;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use Glpi\Urgency;
use GlpiPlugin\Urgencyprofile\ProfileGate;
use Override;

/**
 * Same behaviour as the core urgency question, except that profiles outside
 * PLUGIN_URGENCYPROFILE_ALLOWED_PROFILES never see it and never get to choose a
 * value.
 *
 * The class is deliberately a sibling of Glpi\Form\QuestionType\QuestionTypeUrgency
 * rather than a subclass: that one is final, and extending it would tie this
 * plugin to its internals anyway.
 */
final class UrgencyByProfile extends AbstractQuestionType implements
    UsedAsCriteriaInterface,
    ConditionValueTransformerInterface
{
    /**
     * Urgency level answered on behalf of a profile that may not see the field.
     *
     * Zero means "not configured": no urgency level in GLPI uses it, so the
     * destination field below leaves the ticket alone and the ITIL template
     * default applies.
     */
    public function getDefaultValue(?Question $question): int
    {
        if ($question === null) {
            return 0;
        }

        $default_value = $question->fields['default_value'] ?? null;

        return is_numeric($default_value) ? (int) $default_value : 0;
    }

    #[Override]
    public function getCategory(): QuestionTypeCategoryInterface
    {
        // The core urgency category on purpose: the form builder then lists this
        // question right next to the core one, and no extra category has to be
        // registered.
        return QuestionTypeCategory::URGENCY;
    }

    #[Override]
    public function getName(): string
    {
        return __('Urgency (restricted by profile)', 'urgencyprofile');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-hourglass-off';
    }

    #[Override]
    public function getWeight(): int
    {
        // AbstractQuestionType defaults to 20, which is what the core urgency
        // question uses. One more puts this type just after it in the dropdown.
        return 21;
    }

    /**
     * Public forms have no session profile, so the question renders hidden and
     * answers itself. Returning false instead would drop the question from the
     * form entirely (templates/pages/form_renderer.html.twig:138) and leave the
     * ticket without the default urgency.
     */
    #[Override]
    public function isAllowedForUnauthenticatedAccess(): bool
    {
        return true;
    }

    /**
     * Drives data-glpi-form-renderer-hidden-input on the whole question block,
     * which CSS collapses (css/includes/components/form/_form-renderer.scss:101).
     * Title, mandatory asterisk and input disappear together.
     *
     * The renderer calls this on an instance built by Question::getQuestionType()
     * without any question context, hence the session-wide configuration rather
     * than a per-question one.
     */
    #[Override]
    public function isHiddenInput(): bool
    {
        return !ProfileGate::isCurrentProfileAllowed();
    }

    #[Override]
    public function renderAdministrationTemplate(?Question $question): string
    {
        $template = <<<TWIG
            {% import 'components/form/fields_macros.html.twig' as fields %}

            {{ fields.dropdownArrayField(
                'default_value',
                value,
                urgency_levels,
                '',
                {
                    'init'                : init,
                    'no_label'            : true,
                    'display_emptychoice' : true,
                    'mb'                  : '',
                }
            ) }}

            <p class="text-muted mt-2 mb-0">
                {% if allowed_profiles is empty %}
                    {{ __('No profile is allowed to answer this question: everyone gets the default value above.', 'urgencyprofile') }}
                {% else %}
                    {{ __('Only these profiles see this question: %s. Every other profile gets the default value above.', 'urgencyprofile')|format(allowed_profiles|join(', ')) }}
                {% endif %}
            </p>
TWIG;

        $twig = TemplateRenderer::getInstance();
        return $twig->renderFromStringTemplate($template, [
            'init'             => $question != null,
            'value'            => $this->getDefaultValue($question),
            'urgency_levels'   => Urgency::getEnabledUrgencyValuesForDropdown(),
            'allowed_profiles' => ProfileGate::getAllowedProfileNames(),
        ]);
    }

    #[Override]
    public function renderEndUserTemplate(Question $question): string
    {
        $twig = TemplateRenderer::getInstance();

        if (!ProfileGate::isCurrentProfileAllowed()) {
            // The value still has to be submitted: AnswersHandler::validateAnswers()
            // (src/Glpi/Form/AnswersHandler/AnswersHandler.php:107) enforces
            // mandatory questions server side and does not know about
            // isHiddenInput(). prepareEndUserAnswer() below overwrites whatever
            // comes back anyway.
            $template = <<<TWIG
                <input
                    type="hidden"
                    name="{{ question.getEndUserInputName() }}"
                    value="{{ value }}"
                >
TWIG;

            return $twig->renderFromStringTemplate($template, [
                'question' => $question,
                'value'    => $this->getDefaultValue($question),
            ]);
        }

        $template = <<<TWIG
            {% import 'components/form/fields_macros.html.twig' as fields %}

            {{ fields.dropdownArrayField(
                question.getEndUserInputName(),
                value,
                urgency_levels,
                '',
                {
                    'no_label'            : true,
                    'display_emptychoice' : true,
                    'aria_label'          : label,
                    'mb'                  : '',
                }
            ) }}
TWIG;

        return $twig->renderFromStringTemplate($template, [
            'value'          => $this->getDefaultValue($question),
            'question'       => $question,
            'urgency_levels' => Urgency::getEnabledUrgencyValuesForDropdown(),
            'label'          => $question->fields['name'],
        ]);
    }

    /**
     * Ignore what a disallowed profile posted.
     *
     * The hidden input above sits in the DOM and can be edited before submitting,
     * so the value is recomputed here rather than trusted.
     */
    #[Override]
    public function prepareEndUserAnswer(Question $question, mixed $answer): mixed
    {
        if (!ProfileGate::isCurrentProfileAllowed()) {
            return $this->getDefaultValue($question);
        }

        return $answer;
    }

    #[Override]
    public function formatRawAnswer(mixed $answer, Question $question): string
    {
        return CommonITILObject::getUrgencyName($answer);
    }

    #[Override]
    public function getConditionHandlers(
        ?JsonFieldInterface $question_config
    ): array {
        return array_merge(
            parent::getConditionHandlers($question_config),
            [new UrgencyConditionHandler()]
        );
    }

    #[Override]
    public function transformConditionValueForComparisons(
        mixed $value,
        ?JsonFieldInterface $question_config
    ): string {
        if (empty($value)) {
            return '';
        }

        return strval($value);
    }
}
