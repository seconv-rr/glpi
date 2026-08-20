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

namespace GlpiPlugin\Urgencyprofile\Destination;

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\Answer;
use Glpi\Form\AnswersSet;
use Glpi\Form\Destination\AbstractCommonITILFormDestination;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\CommonITILField\Category;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Export\Context\DatabaseMapper;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Urgency;
use GlpiPlugin\Urgencyprofile\QuestionType\UrgencyByProfile;
use InvalidArgumentException;
use Override;

/**
 * Carries the answer of an UrgencyByProfile question over to the created ITIL
 * object.
 *
 * The core urgency field cannot do it: both the question dropdown it offers
 * (Glpi\Form\Destination\CommonITILField\UrgencyField:189) and its default
 * "last valid answer" strategy
 * (Glpi\Form\Destination\CommonITILField\UrgencyFieldStrategy:98) look for the
 * exact QuestionTypeUrgency class, and Form::getQuestionsByTypes() compares with
 * get_class() rather than instanceof (src/Glpi/Form/Form.php:727).
 *
 * Nothing is written when no answer is usable, so a form that also carries a
 * core urgency question keeps working: this field runs after the core one
 * (weight 71 against 70 in the same category) and only overrides it when it
 * actually has a value.
 */
final class UrgencyByProfileField extends AbstractConfigField
{
    #[Override]
    public function getLabel(): string
    {
        return __('Urgency (restricted by profile)', 'urgencyprofile');
    }

    #[Override]
    public function getCategory(): Category
    {
        return Category::PROPERTIES;
    }

    #[Override]
    public function getWeight(): int
    {
        // Glpi\Form\Destination\CommonITILField\UrgencyField uses 70 and fields
        // are applied in weight order
        // (src/Glpi/Form/Destination/AbstractCommonITILFormDestination.php:286).
        return 71;
    }

    #[Override]
    public function getConfigClass(): string
    {
        return UrgencyByProfileFieldConfig::class;
    }

    #[Override]
    public function getDefaultConfig(Form $form): UrgencyByProfileFieldConfig
    {
        return new UrgencyByProfileFieldConfig(
            UrgencyByProfileFieldConfig::LAST_ANSWER
        );
    }

    /**
     * @param array<string, mixed> $display_options
     */
    #[Override]
    public function renderConfigForm(
        Form $form,
        FormDestination $destination,
        JsonFieldInterface $config,
        string $input_name,
        array $display_options
    ): string {
        if (!$config instanceof UrgencyByProfileFieldConfig) {
            throw new InvalidArgumentException("Unexpected config class");
        }

        $possible_values = [
            UrgencyByProfileFieldConfig::DISABLED    => __('Do not set the urgency', 'urgencyprofile'),
            UrgencyByProfileFieldConfig::LAST_ANSWER => __('Answer to the last restricted urgency question', 'urgencyprofile'),
        ];
        foreach ($form->getQuestionsByType(UrgencyByProfile::class) as $question) {
            $possible_values[$question->getID()] = $question->fields['name'];
        }

        $template = <<<TWIG
            {% import 'components/form/fields_macros.html.twig' as fields %}

            {{ fields.dropdownArrayField(
                input_name,
                value,
                possible_values,
                '',
                options|merge({
                    'no_label' : true,
                    'mb'       : '',
                })
            ) }}
TWIG;

        $twig = TemplateRenderer::getInstance();
        return $twig->renderFromStringTemplate($template, [
            'input_name'      => $input_name . "[" . UrgencyByProfileFieldConfig::QUESTION_ID . "]",
            'value'           => $config->getQuestionId(),
            'possible_values' => $possible_values,
            'options'         => $display_options,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    #[Override]
    public function applyConfiguratedValueToInputUsingAnswers(
        JsonFieldInterface $config,
        array $input,
        AnswersSet $answers_set
    ): array {
        if (!$config instanceof UrgencyByProfileFieldConfig) {
            throw new InvalidArgumentException("Unexpected config class");
        }

        $question_id = $config->getQuestionId();
        if ($question_id === UrgencyByProfileFieldConfig::DISABLED) {
            return $input;
        }

        $answer = $question_id === UrgencyByProfileFieldConfig::LAST_ANSWER
            ? $this->getLastAnswer($answers_set)
            : $answers_set->getAnswerByQuestionId($question_id);

        if ($answer === null) {
            return $input;
        }

        $urgency = $answer->getRawAnswer();
        if (!is_numeric($urgency)) {
            return $input;
        }

        // A question left without a default value answers 0, which is not an
        // urgency level: the ITIL template default must win in that case.
        $urgency = (int) $urgency;
        $valid_values = array_map(
            'intval',
            array_keys(Urgency::getEnabledUrgencyValuesForDropdown())
        );
        if (!in_array($urgency, $valid_values, true)) {
            return $input;
        }

        $input['urgency'] = $urgency;

        return $input;
    }

    /**
     * Remap the referenced question when a form is imported into another GLPI.
     *
     * DISABLED and LAST_ANSWER are sentinels, not ids, and must be left alone.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    #[Override]
    public static function prepareDynamicConfigDataForImport(
        array $config,
        AbstractCommonITILFormDestination $destination,
        DatabaseMapper $mapper,
    ): array {
        $question_id = $config[UrgencyByProfileFieldConfig::QUESTION_ID] ?? null;
        if (!is_numeric($question_id) || (int) $question_id <= 0) {
            return $config;
        }

        $config[UrgencyByProfileFieldConfig::QUESTION_ID] = $mapper->getItemId(
            Question::class,
            (int) $question_id,
        );

        return $config;
    }

    private function getLastAnswer(AnswersSet $answers_set): ?Answer
    {
        $answers = $answers_set->getAnswersByType(UrgencyByProfile::class);
        if ($answers === []) {
            return null;
        }

        $answer = end($answers);

        return $answer === false ? null : $answer;
    }
}
