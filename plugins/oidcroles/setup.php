<?php

/**
 * -------------------------------------------------------------------------
 * OIDC Roles plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Gives a user the GLPI profiles that match their Zitadel roles, on every
 * single sign-on login.
 *
 * GLPI cannot do this by itself. For a login that arrives in the HTTP request
 * the authorization rules only see the authentication type, the login, the
 * e-mail and the groups (RuleRightCollection::prepareInputDataForProcess(),
 * src/RuleRightCollection.php:111); no criterion reads a role or any other
 * value sent by the identity provider.
 *
 * The plugin does not write profiles itself. It hands them to the mechanism
 * GLPI already has for rule-assigned ("dynamic") profiles: the same
 * `_ldap_rules` input a matched authorization rule produces, which
 * User::applyRightRules() (src/User.php:1565) compares with the dynamic
 * profiles the user holds, adding what is missing and deleting what is no
 * longer granted. Profiles assigned by hand are not dynamic and are never
 * touched.
 *
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;

const PLUGIN_OIDCROLES_VERSION = '1.0.0';

/**
 * Profile key => id of the GLPI profile it stands for (Administração > Perfis).
 *
 * A Zitadel role is a profile key, optionally followed by a dot and the id of an
 * entity (Administração > Entidades): `tecnico` grants Técnico on the root entity,
 * `tecnico.12` grants it on entity 12. Either way the profile covers the entity's
 * children too. A role whose key is not listed here grants nothing.
 *
 * Ids rather than names: the profiles of this instance were renamed once already,
 * and a rename must not cut anybody's access.
 *
 * The order matters for one thing: a user whose default profile (their preferences)
 * is not one they hold anymore lands on the first profile of this list they got
 * from a role.
 */
const PLUGIN_OIDCROLES_PROFILES = [
    'super-admin' => 4, // Super-Admin
    'admin'       => 3, // Admin
    'supervisor'  => 7, // Supervisor
    'tecnico'     => 6, // Técnico
    'atendente'   => 5, // Atendente
    'prioridade'  => 9, // Prioridade
    'observador'  => 2, // Observador
    'leitor'      => 8, // Leitor
    'padrao'      => 1, // Padrão
];

/**
 * How a role key splits into a profile key and an optional entity id.
 */
const PLUGIN_OIDCROLES_ROLE_PATTERN = '/^(?<profile>[^.]+)(?:\.(?<entity>\d+))?$/';

/**
 * The server variable mod_auth_openidc leaves the roles claim in.
 *
 * Zitadel names the claim `urn:zitadel:iam:org:project:roles`, and repeats it as
 * `urn:zitadel:iam:org:project:<project id>:roles`. The module replaces the colons,
 * which are not legal in a header name, with `-`.
 *
 * Only the environment copy matches. The module also sends every claim as a request
 * header, which PHP shows as HTTP_OIDC_CLAIM_...; a variable without the HTTP_ prefix
 * can only have been set by Apache, never by whoever sent the request.
 */
const PLUGIN_OIDCROLES_CLAIM_PATTERN = '/^OIDC_CLAIM_urn[-_]zitadel[-_]iam[-_]org[-_]project(?:[-_]\d+)?[-_]roles$/i';

function plugin_init_oidcroles(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['oidcroles'] = true;

    // Auth::login() saves the user on every login: an update for a known account
    // (src/Auth.php:1085) and an add for one seen for the first time (src/Auth.php:1097).
    // Both hooks run before User::applyRightRules() reads the input.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['oidcroles']    = [User::class => 'plugin_oidcroles_grant_profiles'];
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['oidcroles'] = [User::class => 'plugin_oidcroles_grant_profiles'];
}

/**
 * Add the profiles of the user's Zitadel roles to the rights GLPI is about to apply.
 */
function plugin_oidcroles_grant_profiles(User $user): void
{
    // `_extauth` is what Auth::login() puts on the user it authenticated outside GLPI
    // (src/Auth.php:1067), and nobody is logged in yet while it runs. Any other save of
    // a user, such as an administrator editing an account from their own single sign-on
    // session, carries the roles of whoever is browsing and must not use them.
    if (!isset($user->input['_extauth']) || Session::getLoginUserID() !== false) {
        return;
    }

    $roles       = plugin_oidcroles_roles_from_request();
    $role_rights = array_filter(array_map(plugin_oidcroles_right_for_role(...), $roles));

    $ignored = array_diff_key($roles, $role_rights);
    if ($ignored !== []) {
        trigger_error(
            sprintf(
                'Plugin oidcroles: the Zitadel roles %s of "%s" grant nothing (unknown profile key or entity).',
                json_encode(array_values($ignored)),
                $user->input['name'] ?? ''
            ),
            E_USER_WARNING
        );
    }

    // Everyone who logs in through Zitadel gets the default profile on every entity,
    // whatever their roles. GLPI's own fallback only gives it on the root entity alone
    // (User::post_addItem(), src/User.php:1045), and only to an account without rights.
    $rights  = array_values($role_rights);
    $default = Profile::getDefault();
    if ($default > 0) {
        $rights[] = [0, $default, 1];
    }

    // Rights from a matched authorization rule are already in here and are kept.
    $user->input['_ldap_rules']['rules_entities_rights'] = array_merge(
        $user->input['_ldap_rules']['rules_entities_rights'] ?? [],
        $rights
    );

    // User::getFromSSO() only raises this flag after running the authorization rules, and it
    // returns before them when no "SSO field" is mapped in the authentication settings
    // (src/User.php:2825). Without the flag applyRightRules() ignores the input above.
    $user->willProcessRuleRight();

    plugin_oidcroles_pick_default_profile($user, $rights);
}

/**
 * The [entity, profile, recursive] right a role grants, or null when it grants nothing.
 *
 * @return array{int, int, int}|null
 */
function plugin_oidcroles_right_for_role(string $role): ?array
{
    if (preg_match(PLUGIN_OIDCROLES_ROLE_PATTERN, $role, $matches) !== 1) {
        return null;
    }

    $profiles_id = PLUGIN_OIDCROLES_PROFILES[$matches['profile']] ?? null;
    if ($profiles_id === null) {
        return null;
    }

    // A role naming an entity that does not exist (deleted, or a typo in Zitadel) would
    // leave a row pointing at nothing.
    $entities_id = (int) ($matches['entity'] ?? 0);
    if ($entities_id !== 0 && countElementsInTable(Entity::getTable(), ['id' => $entities_id]) === 0) {
        return null;
    }

    return [$entities_id, $profiles_id, 1];
}

/**
 * Point the user's default profile at their most important role.
 *
 * GLPI opens the session on the user's default profile and, when there is none, on the
 * first one in alphabetical order (Session::init(), src/Session.php:222). With the
 * default profile granted to everybody, that would open almost every session on
 * Padrão. A default profile the user still holds is theirs to keep.
 *
 * @param list<array{int, int, int}> $rights
 */
function plugin_oidcroles_pick_default_profile(User $user, array $rights): void
{
    $granted = array_column($rights, 1);
    if (in_array((int) ($user->input['profiles_id'] ?? 0), $granted, true)) {
        return;
    }

    $preferred = current(array_intersect(PLUGIN_OIDCROLES_PROFILES, $granted));
    if ($preferred !== false) {
        $user->input['profiles_id'] = $preferred;
    }
}

/**
 * The role keys Zitadel sent for the current request, none when it sent no roles claim.
 *
 * The claim is an object keyed by role, `{"admin": {"<org id>": "<org domain>"}}`, which the
 * module passes on as JSON. Zitadel's documentation also shows it as a list of such objects,
 * so both shapes are read.
 *
 * @return list<string>
 */
function plugin_oidcroles_roles_from_request(): array
{
    $roles = [];

    foreach ($_SERVER as $name => $value) {
        if (!is_string($value) || preg_match(PLUGIN_OIDCROLES_CLAIM_PATTERN, (string) $name) !== 1) {
            continue;
        }

        $claim = json_decode($value, true);
        if (!is_array($claim)) {
            continue;
        }

        $by_role = array_is_list($claim) ? array_filter($claim, is_array(...)) : [$claim];
        $roles   = array_merge($roles, ...array_map(array_keys(...), array_values($by_role)));
    }

    return array_values(array_unique(array_map(strval(...), $roles)));
}

/**
 * @return array<string, mixed>
 */
function plugin_version_oidcroles(): array
{
    return [
        'name'         => 'OIDC Roles',
        'version'      => PLUGIN_OIDCROLES_VERSION,
        'author'       => 'SECONV-RR',
        'license'      => 'GPLv3',
        'requirements' => [
            'glpi' => [
                'min' => '11.0',
                'max' => '12.0',
            ],
        ],
    ];
}
