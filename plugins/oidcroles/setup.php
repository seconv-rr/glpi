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
 * Zitadel role key => id of the GLPI profile it grants (Administração > Perfis).
 *
 * Ids rather than names: the profiles of this instance were renamed once already,
 * and a rename must not cut anybody's access. A role that is not listed here grants
 * nothing.
 */
const PLUGIN_OIDCROLES_PROFILES = [
    'padrao'      => 1, // Padrão
    'observador'  => 2, // Observador
    'admin'       => 3, // Admin
    'super-admin' => 4, // Super-Admin
    'atendente'   => 5, // Atendente
    'tecnico'     => 6, // Técnico
    'supervisor'  => 7, // Supervisor
    'leitor'      => 8, // Leitor
    'prioridade'  => 9, // Prioridade
];

/**
 * Where the profiles are granted. A Zitadel role says nothing about entities, so
 * every one of them applies to the root entity and everything below it.
 */
const PLUGIN_OIDCROLES_ENTITY = 0;
const PLUGIN_OIDCROLES_RECURSIVE = 1;

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

    $roles = plugin_oidcroles_roles_from_request();

    // No claim at all means Zitadel is not sending roles (the project setting is off), which
    // is not the same as a user without roles. GLPI then does what it would without the plugin.
    if ($roles === null) {
        return;
    }

    $rights = [];
    foreach (array_intersect_key(PLUGIN_OIDCROLES_PROFILES, array_flip($roles)) as $profiles_id) {
        $rights[] = [PLUGIN_OIDCROLES_ENTITY, $profiles_id, PLUGIN_OIDCROLES_RECURSIVE];
    }

    if ($rights === []) {
        trigger_error(
            sprintf(
                'Plugin oidcroles: none of the Zitadel roles %s of "%s" maps to a profile.',
                json_encode($roles),
                $user->input['name'] ?? ''
            ),
            E_USER_WARNING
        );
    }

    // Set even when empty: Zitadel answered, so the dynamic profiles follow it, and a user
    // whose roles grant nothing ends up with none. Rights from a matched authorization
    // rule are already in here and are kept.
    $user->input['_ldap_rules']['rules_entities_rights'] = array_merge(
        $user->input['_ldap_rules']['rules_entities_rights'] ?? [],
        $rights
    );

    // User::getFromSSO() only raises this flag after running the authorization rules, and it
    // returns before them when no "SSO field" is mapped in the authentication settings
    // (src/User.php:2825). Without the flag applyRightRules() ignores the input above.
    $user->willProcessRuleRight();
}

/**
 * The role keys Zitadel sent for the current request, or null when it sent no roles claim.
 *
 * The claim is an object keyed by role, `{"admin": {"<org id>": "<org domain>"}}`, which the
 * module passes on as JSON. Zitadel's documentation also shows it as a list of such objects,
 * so both shapes are read.
 *
 * @return list<string>|null
 */
function plugin_oidcroles_roles_from_request(): ?array
{
    $roles = null;

    foreach ($_SERVER as $name => $value) {
        if (!is_string($value) || preg_match(PLUGIN_OIDCROLES_CLAIM_PATTERN, (string) $name) !== 1) {
            continue;
        }

        $claim = json_decode($value, true);
        if (!is_array($claim)) {
            continue;
        }

        $by_role = array_is_list($claim) ? array_filter($claim, is_array(...)) : [$claim];
        $roles   = array_merge($roles ?? [], ...array_map(array_keys(...), array_values($by_role)));
    }

    return $roles === null ? null : array_map(strval(...), $roles);
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
