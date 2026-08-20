<?php

/**
 * -------------------------------------------------------------------------
 * Split Category plugin for GLPI
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

namespace GlpiPlugin\Splitcategory\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Controller\Form\Utils\CanCheckAccessPolicies;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeItemDropdown;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use ITILCategory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Returns the ITIL categories a given form question is allowed to show.
 *
 * The whole tree is sent at once because both dropdowns are built from it in the
 * browser: the parent list, the children of whatever the user picks, and the
 * label of an answer that was already filled in. A category tree is small enough
 * for that (a few hundred rows at most on the instances this was written for)
 * and it avoids a round trip on every keystroke.
 *
 * Rights are not relaxed anywhere: the same access policies the renderer applies
 * to the form are checked here, and the SQL restrictions are rebuilt from the
 * question's own configuration, never from anything the client sends.
 */
final class CategoryTreeController extends AbstractController
{
    use CanCheckAccessPolicies;

    /**
     * Public forms can be answered without a session, so the firewall check is
     * delegated to the form's own access policies, exactly like the core
     * question endpoints do (src/Glpi/Controller/Form/QuestionDropdownValuesController.php:62).
     */
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    #[Route(
        "/CategoryTree",
        name: "category_tree",
        methods: "POST",
    )]
    public function __invoke(Request $request): Response
    {
        $question = $this->loadQuestion($request);
        $this->checkFormAccessPolicies($question->getForm(), $request);

        $question_type = $question->getQuestionType();
        if (!$question_type instanceof QuestionTypeItemDropdown) {
            throw new BadRequestHttpException();
        }

        if ($question_type->getDefaultValueItemtype($question) !== ITILCategory::getType()) {
            throw new BadRequestHttpException();
        }

        return new JsonResponse([
            'categories' => $this->getCategories($question, $question_type),
        ]);
    }

    private function loadQuestion(Request $request): Question
    {
        $question_id = $request->request->getInt('question_id');
        if (!$question_id) {
            throw new BadRequestHttpException();
        }

        $question = Question::getById($question_id);
        if (!$question instanceof Question) {
            throw new NotFoundHttpException();
        }

        return $question;
    }

    /**
     * @return array<int, array{id: int, name: string, completename: string, parent: int}>
     */
    private function getCategories(Question $question, QuestionTypeItemDropdown $question_type): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $item  = new ITILCategory();
        $table = ITILCategory::getTable();

        // Same baseline filtering as the core dropdown endpoint
        // (src/Dropdown.php:2931-2942).
        $where = $item->getSystemSQLCriteria();
        if ($item->maybeDeleted()) {
            $where["$table.is_deleted"] = 0;
        }

        // Entity restriction, as computed when the core dropdown gets no explicit
        // `entity_restrict` parameter (src/Dropdown.php:3095).
        $where += getEntitiesRestrictCriteria($table, '', '', $item->maybeRecursive());

        // Helpdesk visibility, the request/incident/change filter, the tree root
        // and the subtree depth are all part of the question's configuration and
        // are rebuilt by the question type itself
        // (src/Glpi/Form/QuestionType/QuestionTypeItemDropdown.php:213).
        $restrictions = $question_type->getDropdownRestrictionParams($question)['WHERE'] ?? [];
        $where = array_merge($where, $restrictions);

        $rows = $DB->request([
            'SELECT' => [
                "$table.id",
                "$table.name",
                "$table.completename",
                "$table.itilcategories_id",
            ],
            'FROM'  => $table,
            'WHERE' => $where,
            'ORDER' => ["$table.completename"],
        ]);

        $categories = [];
        foreach ($rows as $row) {
            $categories[] = [
                'id'           => (int) $row['id'],
                'name'         => (string) $row['name'],
                'completename' => (string) $row['completename'],
                'parent'       => (int) $row['itilcategories_id'],
            ];
        }

        return $categories;
    }
}
