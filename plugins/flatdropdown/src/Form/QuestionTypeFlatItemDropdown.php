<?php

/**
 * -------------------------------------------------------------------------
 * Flat Dropdown plugin for GLPI
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

namespace GlpiPlugin\Flatdropdown\Form;

use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeItemDropdown;
use Override;

/**
 * The native dropdown question, rendered without the entity group header.
 *
 * Everything except the fill-time rendering is inherited: the itemtype picker,
 * the ITIL categories filter, the tree root and the subtree depth all keep
 * working exactly as they do on the native question type.
 */
final class QuestionTypeFlatItemDropdown extends QuestionTypeItemDropdown
{
    /**
     * Endpoint that strips the entity groups off the core dropdown response.
     */
    public const AJAX_URL = '/plugins/flatdropdown/ajax/getFlatDropdownValue.php';

    #[Override]
    public function getName(): string
    {
        // The instance this plugin was written for is locked to pt_BR by the
        // `defaultlang` plugin, so the label is not run through __(): there is no
        // translation catalog to look it up in and the msgid would leak as-is.
        return 'Lista suspensa (sem agrupamento por entidade)';
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-list-details';
    }

    #[Override]
    public function getWeight(): int
    {
        // Right after the native dropdown (weight 30), so both sit side by side
        // in the question type picker.
        return 31;
    }

    /**
     * Same as QuestionTypeItem::renderEndUserTemplate(), with the dropdown pointed
     * at this plugin's endpoint instead of /ajax/getDropdownValue.php.
     *
     * The template is inlined rather than shipped as a file because a plugin twig
     * namespace would have to be registered for a template that only differs from
     * the core one by the `url` option.
     */
    #[Override]
    public function renderEndUserTemplate(Question $question): string
    {
        global $CFG_GLPI;

        $itemtype = $this->getDefaultValueItemtype($question) ?? '0';

        $template = <<<TWIG
            {% import 'components/form/fields_macros.html.twig' as fields %}

            {{ fields.hiddenField(
                question.getEndUserInputName() ~ '[itemtype]',
                itemtype
            ) }}
            {# Empty choice is defined manually so the root entity keeps working #}
            {% set dropdown_options = {
                'no_label'           : true,
                'right'              : 'all',
                'aria_label'         : aria_label,
                'mb'                 : '',
                'addicon'            : false,
                'comments'           : false,
                'condition'          : dropdown_restriction_params,
                'nochecklimit'       : true,
                'display_emptychoice': false,
                'url'                : url,
                'toadd'              : {
                    '-1': constant('Dropdown::EMPTY_VALUE'),
                },
            } %}

            {% if displaywith is not empty %}
                {% set dropdown_options = dropdown_options|merge({'displaywith': displaywith}) %}
            {% endif %}

            {{ fields.dropdownField(
                itemtype,
                question.getEndUserInputName() ~ '[items_id]',
                default_items_id,
                '',
                dropdown_options
            ) }}
TWIG;

        $twig = TemplateRenderer::getInstance();
        return $twig->renderFromStringTemplate($template, [
            'question'                    => $question,
            'itemtype'                    => $itemtype,
            'default_items_id'            => $this->getDefaultValueItemId($question),
            'aria_label'                  => $question->fields['name'],
            'dropdown_restriction_params' => $this->getDropdownRestrictionParams($question),
            'displaywith'                 => Dropdown::getDisplayWith($itemtype),
            'url'                         => $CFG_GLPI['root_doc'] . self::AJAX_URL,
        ]);
    }
}
