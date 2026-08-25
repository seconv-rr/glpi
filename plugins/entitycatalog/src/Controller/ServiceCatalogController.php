<?php

/**
 * -------------------------------------------------------------------------
 * Entity service catalog plugin for GLPI
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

namespace GlpiPlugin\Entitycatalog\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Glpi\Http\Firewall;
use Glpi\Http\RedirectResponse;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Entitycatalog\EntityCatalogTile;
use Html;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Switches the session to a tile's target entity, then sends the visitor to
 * the native service catalog.
 *
 * The catalog itself is left untouched: it reads the entity from the session
 * (src/Glpi/Controller/ServiceCatalog/IndexController.php:80) and so does
 * every provider behind it, so once the switch is done everything downstream —
 * the form list, the categories, the search, the ticket the form creates —
 * already works against the right entity.
 *
 * The switch is not scoped to this request. The visitor stays in the target
 * entity until they pick another one in the entity selector, which is the
 * only behaviour GLPI offers: there is no per-request entity override.
 *
 * On a GET route changing session state, and on the absence of a CSRF token —
 * both are deliberate, and neither is free:
 *
 * A tile is rendered as a plain `<a href>` by the core home page
 * (templates/pages/helpdesk/index.html.twig:114), so a tile's URL is followed
 * with GET or not at all; a plugin cannot make that link submit a form. GLPI
 * itself switches the session entity on GET far more broadly: `force_entity=N`
 * on *any* URL does it from the request listener, tokenless, as a documented
 * feature meant for links crafted by external apps
 * (src/Glpi/Kernel/Listener/RequestListener/SessionVariables.php:90).
 *
 * What a forged link can achieve is bounded by the check below: the entity has
 * to be one the victim's own profile already grants, so the worst case is
 * their entity selector ending up somewhere they could have navigated to by
 * hand. Nothing is written, no access is granted, and the entity they are in
 * stays visible in the header for them to change back.
 */
final class ServiceCatalogController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route(
        "/ServiceCatalog/{tiles_id}",
        name: "service_catalog",
        methods: "GET",
        requirements: ['tiles_id' => '\d+'],
    )]
    public function __invoke(Request $request): Response
    {
        $tile = $this->loadTile((int) $request->get('tiles_id'));

        $entity = $tile->getTargetEntity();
        if ($entity === null) {
            // The configured entity was deleted after the tile was created.
            throw new NotFoundHttpException();
        }

        if (!EntityCatalogTile::canSessionReachEntity($entity->getID())) {
            throw new AccessDeniedHttpException();
        }

        // Both refusals happen before the switch, so a request that cannot
        // succeed leaves the visitor's entity alone instead of moving them
        // somewhere and then showing them an error. isServiceCatalogEnabled()
        // only walks up the entity tree for an inherited value
        // (src/Entity.php:3435), so it does not need the switch to answer.
        if (!$entity->isServiceCatalogEnabled()) {
            throw new AccessDeniedHttpException();
        }

        // Recursive first, like the native entity selector: a service catalog
        // covering an entity normally covers its sub-entities' forms too
        // (FormProvider passes is_recursive: true,
        // src/Glpi/Form/ServiceCatalog/Provider/FormProvider.php:104).
        //
        // A profile can be granted an entity without the recursive flag,
        // though, and changeActiveEntities() refuses the recursive request
        // outright in that case (src/Session.php:499). Falling back to the
        // entity alone gives such a user the catalog they are entitled to
        // instead of a 403.
        if (
            !Session::changeActiveEntities($entity->getID(), true)
            && !Session::changeActiveEntities($entity->getID(), false)
        ) {
            throw new AccessDeniedHttpException();
        }

        return new RedirectResponse(Html::getPrefixedUrl('/ServiceCatalog'));
    }

    private function loadTile(int $tiles_id): EntityCatalogTile
    {
        $tile = new EntityCatalogTile();
        if (!$tile->getFromDB($tiles_id)) {
            throw new NotFoundHttpException();
        }

        return $tile;
    }
}
