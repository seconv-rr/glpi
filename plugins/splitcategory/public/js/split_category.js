/**
 * -------------------------------------------------------------------------
 * Split Category plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Turns the single ITIL category dropdown of a rendered form into two chained
 * dropdowns: the top level categories, then whatever lives under the one that
 * was picked.
 *
 * The native <select> is kept in the page but disabled, so it is no longer
 * serialized, and the answer travels in a hidden input carrying the very same
 * name. Nothing else about the question changes: GLPI still sees a native
 * "Dropdown" question answered with an ITIL category id.
 *
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * -------------------------------------------------------------------------
 */

/* global select2_configs, setupAdaptDropdown, templateResult, templateSelection */

const ITEMTYPE = 'ITILCategory';
const ENDPOINT = '/plugins/splitcategory/CategoryTree';

/** Separator CommonTreeDropdown builds `completename` with (src/CommonTreeDropdown.php:136). */
const SEPARATOR = ' > ';

/**
 * The instance this plugin was written for is locked to pt_BR by the
 * `defaultlang` plugin, so the labels are not run through a translation
 * catalog: there is none to look them up in.
 */
const LABEL_CATEGORY = 'Categoria';
const LABEL_SUBCATEGORY = 'Subcategoria';
const LABEL_EMPTY = '-----';

/**
 * A question with no category selected must answer something GLPI reads as
 * empty. `QuestionTypeItem::transformConditionValueForComparisons()`
 * (src/Glpi/Form/QuestionType/QuestionTypeItem.php:511) resolves 0 to an empty
 * string, which is what the mandatory check looks for.
 */
const NO_ANSWER = '0';

function run() {
    document
        .querySelectorAll('[data-glpi-form-renderer-question]')
        .forEach((section) => splitQuestion(section));
}

/**
 * Replace the category dropdown of a single question, if that is what it is.
 *
 * @param {HTMLElement} section
 */
async function splitQuestion(section) {
    const question_id = section.dataset.glpiFormRendererId;
    if (!question_id) {
        return;
    }

    // Every "GLPI object" question renders its itemtype next to the value, so
    // this is enough to tell an ITIL category dropdown from any other dropdown
    // (templates/pages/admin/form/question_type/item/end_user_template.html.twig:36).
    const itemtype_input = section.querySelector(
        `input[name="answers_${question_id}[itemtype]"]`
    );
    if (itemtype_input === null || itemtype_input.value !== ITEMTYPE) {
        return;
    }

    const native_select = section.querySelector(
        `select[name="answers_${question_id}[items_id]"]`
    );
    if (native_select === null) {
        return;
    }

    // Hide before the request goes out, otherwise the original dropdown shows up
    // for as long as it takes to answer.
    const native_field = native_select.closest('div') ?? native_select;
    native_field.style.display = 'none';

    let categories;
    try {
        categories = await fetchCategories(question_id);
    } catch (error) {
        // Restoring the native dropdown is the only safe fallback: the question
        // would otherwise be left with no input at all.
        native_field.style.display = '';
        console.error(error);
        return;
    }

    const tree = buildTree(categories);
    const initial_value = Number(native_select.value);

    // Not serialized anymore: the two dropdowns below are only a UI, the answer
    // travels in the hidden input.
    native_select.disabled = true;

    render(native_field, question_id, tree, tree.by_id.get(initial_value));
}

/**
 * @param {string} question_id
 * @returns {Promise<Array<{id: number, name: string, completename: string, parent: number}>>}
 */
async function fetchCategories(question_id) {
    // jQuery is used rather than fetch() so the CSRF token is added to the
    // request headers by GLPI's own `ajaxSend` handler (js/common.js:1308).
    const response = await $.post({
        url: `${CFG_GLPI.root_doc}${ENDPOINT}`,
        data: { question_id: question_id },
        dataType: 'json',
    });

    return response.categories ?? [];
}

/**
 * Index the flat list the endpoint returns as a two level tree.
 *
 * @param {Array<{id: number, name: string, completename: string, parent: number}>} categories
 */
function buildTree(categories) {
    const by_id = new Map(categories.map((category) => [category.id, category]));
    const roots = [];
    const descendants = new Map();

    categories.forEach((category) => {
        const root = topAncestor(category, by_id);

        if (root === category) {
            roots.push(category);
            return;
        }

        if (!descendants.has(root.id)) {
            descendants.set(root.id, []);
        }
        descendants.get(root.id).push(category);
    });

    return { by_id, roots, descendants };
}

/**
 * Walk up until a category whose parent is not part of the allowed set.
 *
 * A category whose parent was filtered out — hidden from the helpdesk, outside
 * the subtree the question is configured with, filtered out by the
 * request/incident/change filter — becomes a top level category of its own.
 */
function topAncestor(category, by_id) {
    let current = category;
    const seen = new Set([current.id]);

    while (by_id.has(current.parent) && !seen.has(current.parent)) {
        seen.add(current.parent);
        current = by_id.get(current.parent);
    }

    return current;
}

/**
 * Label a descendant relative to the category selected in the first dropdown,
 * so a two level tree reads as plain subcategory names and a deeper one still
 * shows where it sits.
 */
function relativeLabel(category, root) {
    const prefix = root.completename + SEPARATOR;

    return category.completename.startsWith(prefix)
        ? category.completename.slice(prefix.length)
        : category.name;
}

/**
 * Build both dropdowns, the hidden answer, and wire them together.
 */
function render(native_field, question_id, tree, selected) {
    const category_select = createSelect(`splitcategory_category_${question_id}`);
    const subcategory_select = createSelect(`splitcategory_subcategory_${question_id}`);

    const answer = document.createElement('input');
    answer.type = 'hidden';
    answer.name = `answers_${question_id}[items_id]`;
    answer.value = NO_ANSWER;

    // The `w-100` break keeps the subcategory on its own line, below the
    // category it refines, without widening either dropdown.
    const line_break = document.createElement('div');
    line_break.className = 'w-100';

    const row = document.createElement('div');
    row.className = 'row g-2';
    row.append(
        wrapField(LABEL_CATEGORY, category_select),
        line_break,
        wrapField(LABEL_SUBCATEGORY, subcategory_select),
        answer
    );
    native_field.after(row);

    fillOptions(
        category_select,
        tree.roots.map((category) => [category.id, category.name])
    );
    enhanceSelect(category_select);
    enhanceSelect(subcategory_select);

    const syncAnswer = () => {
        const value = subcategory_select.value || category_select.value;
        answer.value = value === '' ? NO_ANSWER : value;

        // Native event: the renderer recomputes the visibility conditions on any
        // `input` event bubbling up to the document
        // (js/modules/Forms/RendererController.js:127).
        answer.dispatchEvent(new Event('input', { bubbles: true }));
    };

    const refreshSubcategories = (preselected) => {
        const root = tree.by_id.get(Number(category_select.value));
        const children = root === undefined ? [] : (tree.descendants.get(root.id) ?? []);

        fillOptions(
            subcategory_select,
            children.map((category) => [category.id, relativeLabel(category, root)])
        );
        subcategory_select.disabled = children.length === 0;

        if (preselected !== undefined) {
            subcategory_select.value = String(preselected);
        }

        // select2 renders from the options it read on init, so it has to be told
        // they changed. The `.select2` namespace keeps our own `change` handler
        // out of it.
        $(subcategory_select).trigger('change.select2');
        syncAnswer();
    };

    // select2 fires jQuery events only, so native listeners would never run.
    $(category_select).on('change', () => refreshSubcategories());
    $(subcategory_select).on('change', syncAnswer);

    if (selected === undefined) {
        refreshSubcategories();
        return;
    }

    const root = topAncestor(selected, tree.by_id);
    category_select.value = String(root.id);
    $(category_select).trigger('change.select2');
    refreshSubcategories(selected === root ? undefined : selected.id);
}

function createSelect(id) {
    const select = document.createElement('select');
    select.id = id;
    select.className = 'form-select';

    // No name on purpose: only the hidden answer must be submitted.
    return select;
}

/**
 * Same column width the core question template gives its own dropdown
 * (templates/components/form/fields_macros.html.twig:895), so the two
 * dropdowns line up with the other questions of the form.
 */
function wrapField(label_text, select) {
    const column = document.createElement('div');
    column.className = 'col-12 col-sm-6';

    const label = document.createElement('label');
    label.className = 'form-label';
    label.htmlFor = select.id;
    label.textContent = label_text;

    column.append(label, select);

    return column;
}

/**
 * @param {HTMLSelectElement} select
 * @param {Array<[number, string]>} entries
 */
function fillOptions(select, entries) {
    select.replaceChildren(new Option(LABEL_EMPTY, ''));
    entries.forEach(([value, text]) => select.append(new Option(text, String(value))));
}

/**
 * Turn a plain select into the searchable dropdown the rest of GLPI uses.
 * Skipped rather than reimplemented when the core helpers are not loaded: a
 * plain select still works.
 */
function enhanceSelect(select) {
    if (typeof setupAdaptDropdown !== 'function' || typeof select2_configs === 'undefined') {
        return;
    }

    select2_configs[select.id] = {
        type: 'adapt',
        field_id: select.id,
        width: '100%',
        dropdown_css_class: '',
        placeholder: '',
        ajax_limit_count: 10,
        templateresult: templateResult,
        templateselection: templateSelection,
    };

    setupAdaptDropdown(select2_configs[select.id]);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
} else {
    run();
}
