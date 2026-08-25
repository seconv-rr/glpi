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

namespace GlpiPlugin\Entitycatalog;

use CommonDBTM;
use Entity;
use Glpi\Helpdesk\Tile\Item_Tile;
use Glpi\Helpdesk\Tile\TileInterface;
use Glpi\Helpdesk\Tile\TileRightTrait;
use Glpi\Session\SessionInfo;
use Glpi\UI\IllustrationManager;
use Html;
use Override;

/**
 * A helpdesk tile that opens the service catalog of a fixed entity.
 *
 * The target entity is stored in `target_entities_id` rather than the usual
 * `entities_id`: the latter is the column CommonDBTM reads to decide an item
 * is entity-assigned (src/CommonDBTM.php:3233), which would make GLPI treat
 * the tile as living in the target entity and apply entity restrictions to it.
 * A tile belongs to the entity or profile it is attached to, and the column
 * here is a plain reference to somewhere else.
 */
final class EntityCatalogTile extends CommonDBTM implements TileInterface
{
    use TileRightTrait;

    public static $rightname = 'config';

    #[Override]
    public function getWeight(): int
    {
        // After the core tiles (20 for the GLPI page one), so the native
        // choices keep coming first in the "Add tile" dropdown.
        return 30;
    }

    #[Override]
    public function getLabel(): string
    {
        return __("Service catalog of another entity", 'entitycatalog');
    }

    #[Override]
    public function getTitle(): string
    {
        return $this->fields['title'] ?? '';
    }

    #[Override]
    public function getDescription(): string
    {
        return $this->fields['description'] ?? '';
    }

    #[Override]
    public function getIllustration(): string
    {
        return $this->fields['illustration'] ?? IllustrationManager::DEFAULT_ILLUSTRATION;
    }

    /**
     * The tile links to this plugin's own route rather than to `/ServiceCatalog`.
     *
     * The catalog reads the target entity from the session and from nowhere
     * else (src/Glpi/Controller/ServiceCatalog/IndexController.php:80), so the
     * switch has to happen before it runs. The route only carries the tile id:
     * the entity comes from the tile row, which means the link cannot be
     * rewritten into a switch to an entity no administrator configured.
     */
    #[Override]
    public function getTileUrl(): string
    {
        return Html::getPrefixedUrl(
            '/plugins/entitycatalog/ServiceCatalog/' . $this->getID()
        );
    }

    #[Override]
    public function isAvailable(SessionInfo $session_info): bool
    {
        $entity = $this->getTargetEntity();
        if ($entity === null) {
            return false;
        }

        // Same two conditions the tile's route will check, so a tile that
        // could only lead to an error page is never displayed.
        return self::canSessionReachEntity($entity->getID())
            && $entity->isServiceCatalogEnabled();
    }

    #[Override]
    public function getDatabaseId(): int
    {
        return $this->fields['id'];
    }

    #[Override]
    public function getConfigFieldsTemplate(): string
    {
        return "@entitycatalog/tile_config_fields.html.twig";
    }

    #[Override]
    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([Item_Tile::class]);
    }

    public function getTargetEntityId(): int
    {
        return (int) ($this->fields['target_entities_id'] ?? 0);
    }

    /**
     * The configured entity, or null when it no longer exists.
     *
     * Entities are not soft-deleted, so a tile can outlive its target.
     */
    public function getTargetEntity(): ?Entity
    {
        $entity = Entity::getById($this->getTargetEntityId());

        return $entity instanceof Entity ? $entity : null;
    }

    /**
     * Whether the current session could switch to the given entity.
     *
     * Deliberately a copy of the check Session::changeActiveEntities() runs
     * before accepting a new entity (src/Session.php:494) rather than a
     * stricter rule of its own: this decides whether the tile is displayed,
     * and any divergence would either hide a tile that works or show one that
     * 403s. Calling the real thing is not an option — it *performs* the
     * switch.
     *
     * Note it reads the active *profile*, not the entities currently active,
     * so it answers "could the user go there", which is what the tile needs.
     */
    public static function canSessionReachEntity(int $entity_id, bool $is_recursive = false): bool
    {
        $profile_entities = $_SESSION['glpiactiveprofile']['entities'] ?? [];
        $ancestors = getAncestorsOf('glpi_entities', $entity_id);

        foreach ($profile_entities as $profile_entity) {
            if ($profile_entity['id'] != $entity_id && !in_array($profile_entity['id'], $ancestors)) {
                continue;
            }

            // A non-recursive request only needs the entity to be in reach; a
            // recursive one also needs the grant it was reached through to be
            // recursive.
            if (!$is_recursive || $profile_entity['is_recursive']) {
                return true;
            }
        }

        return false;
    }
}
