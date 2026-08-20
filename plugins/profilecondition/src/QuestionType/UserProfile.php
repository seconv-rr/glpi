<?php

/**
 * -------------------------------------------------------------------------
 * Conditions by profile plugin for GLPI
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

namespace GlpiPlugin\Profilecondition\QuestionType;

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\AbstractQuestionType;
use Glpi\Form\QuestionType\QuestionTypeCategoryInterface;
use GlpiPlugin\Profilecondition\Condition\ProfileEmptyConditionHandler;
use GlpiPlugin\Profilecondition\Condition\ProfileEqualsConditionHandler;
use GlpiPlugin\Profilecondition\SessionProfile;
use Override;

/**
 * Invisible question whose answer is the profile active in the session.
 *
 * It exists so that the current profile can be used as a criterion in the
 * native conditions editor: visibility of questions, comments and sections,
 * validation rules, and conditional creation of destinations. The form builder
 * can only condition an item on the answer of a question
 * (Glpi\Form\Condition\Type only has QUESTION, SECTION and COMMENT), so the
 * profile has to become a question to become a criterion.
 *
 * The handlers returned by getConditionHandlers() never compare against the
 * answer: they read the session directly (see ProfileEqualsConditionHandler).
 * The value submitted by the hidden input is only stored for the record, and
 * prepareEndUserAnswer() recomputes it server side so it cannot be forged.
 */
final class UserProfile extends AbstractQuestionType
{
    #[Override]
    public function getCategory(): QuestionTypeCategoryInterface
    {
        return new ContextCategory();
    }

    #[Override]
    public function getName(): string
    {
        return __('Current user profile', 'profilecondition');
    }

    #[Override]
    public function getIcon(): string
    {
        // Same icon as the core Profile object (src/Profile.php:3802).
        return 'ti ti-user-check';
    }

    #[Override]
    public function getWeight(): int
    {
        return 10;
    }

    /**
     * Public forms have no session profile, but the question must survive the
     * renderer: returning false would drop it from the form entirely
     * (templates/pages/form_renderer.html.twig:138) and every condition built
     * on it would lose its criterion. The handlers still evaluate without a
     * profile: EQUALS never matches and EMPTY always does.
     */
    #[Override]
    public function isAllowedForUnauthenticatedAccess(): bool
    {
        return true;
    }

    /**
     * Always collapsed for whoever fills the form: this question asks nothing.
     *
     * Drives data-glpi-form-renderer-hidden-input on the whole question block,
     * which CSS collapses (css/includes/components/form/_form-renderer.scss:101),
     * so the title, the mandatory asterisk and the input disappear together.
     */
    #[Override]
    public function isHiddenInput(): bool
    {
        return true;
    }

    #[Override]
    public function renderAdministrationTemplate(?Question $question): string
    {
        $template = <<<TWIG
            <p class="text-muted mb-0">
                {{ __("Never shown to whoever fills the form: it answers itself with the profile active in the session.", 'profilecondition') }}
                {{ __("Use it as a criterion in the conditions of other questions, comments, sections and destinations.", 'profilecondition') }}
            </p>
            <p class="text-muted mt-2 mb-0">
                {{ __("Do not mark it as mandatory: a visitor without a session has no profile, and the empty answer would block the whole form.", 'profilecondition') }}
            </p>
TWIG;

        $twig = TemplateRenderer::getInstance();
        return $twig->renderFromStringTemplate($template);
    }

    /**
     * The hidden input only keeps the question present in the submitted data
     * so the answer is stored with the others. Its value is informative: every
     * condition handler of this plugin reads the session instead, and
     * prepareEndUserAnswer() below recomputes it before it is stored.
     */
    #[Override]
    public function renderEndUserTemplate(Question $question): string
    {
        $template = <<<TWIG
            <input
                type="hidden"
                name="{{ question.getEndUserInputName() }}"
                value="{{ value }}"
            >
TWIG;

        $twig = TemplateRenderer::getInstance();
        return $twig->renderFromStringTemplate($template, [
            'question' => $question,
            'value'    => SessionProfile::getActiveProfileId() ?? '',
        ]);
    }

    /**
     * Store the real profile, not the posted one: the hidden input sits in the
     * DOM and can be edited before submitting, so it is never trusted.
     */
    #[Override]
    public function prepareEndUserAnswer(Question $question, mixed $answer): mixed
    {
        return SessionProfile::getActiveProfileId() ?? '';
    }

    #[Override]
    public function formatRawAnswer(mixed $answer, Question $question): string
    {
        if (!is_numeric($answer)) {
            return '';
        }

        return SessionProfile::getProfilesDropdown()[(int) $answer] ?? '';
    }

    /**
     * Only this plugin's handlers, on purpose: the parent's defaults (regex,
     * empty, visibility) compare against the carried answer, which for this
     * question is an implementation detail — the session is the only truth.
     *
     * @return array<\Glpi\Form\Condition\ConditionHandler\ConditionHandlerInterface>
     */
    #[Override]
    public function getConditionHandlers(
        ?JsonFieldInterface $question_config
    ): array {
        return [
            new ProfileEqualsConditionHandler(),
            new ProfileEmptyConditionHandler(),
        ];
    }
}
