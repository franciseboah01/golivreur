<?php

// Config Spatie Laravel Permission.
// teams=false : le cloisonnement multi-etablissement n'est PAS gere par le mecanisme "teams"
// de Spatie mais explicitement par establishment_id dans chaque Service/Policy (voir
// app/Modules/Recruitment/Policies/CumulativeRoleGuardPolicy.php). Roles cumulables
// (ex. Directeur d'etudes = candidate+recruiter) : un utilisateur peut porter plusieurs roles.

return [

    'models' => [
        'permission' => Spatie\Permission\Models\Permission::class,
        'role' => Spatie\Permission\Models\Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    // Cloisonnement multi-tenant gere explicitement par establishment_id, pas par teams Spatie.
    'teams' => false,

    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,

    'enable_wildcard_permission' => false,

    'cache' => [
        'expiration_time' => \DateInterval::createFromDateString('24 hours'),
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],

];
