<?php

declare(strict_types=1);

use App\Platform\Identity\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

return [
    // Built-in role-management resource (Shield). custom_permissions tab is ON so the
    // platform's existing domain permissions (e.g. "catalog.courses.view") are assignable.
    'shield_resource' => [
        'slug' => 'shield/roles',
        'show_model_path' => true,
        'cluster' => null,
        'tabs' => [
            'pages' => true,
            'widgets' => true,
            'resources' => true,
            'custom_permissions' => true,
        ],
    ],

    'tenant_model' => null,

    // Platform User model (DDD-located; NOT the default App\Models\User).
    'auth_provider_model' => User::class,

    // Central super-admin bypass via Laravel Gate::before (Shield-native, not hand-rolled).
    'super_admin' => [
        'enabled' => true,
        'name' => 'super_admin',
        'define_via_gate' => true,
        'intercept_gate' => 'before',
    ],

    // Platform seeds its own roles; Shield must not create a panel_user role.
    'panel_user' => [
        'enabled' => false,
        'name' => 'panel_user',
    ],

    // generate=false: Shield never auto-creates permissions (platform owns its 39).
    'permissions' => [
        'separator' => ':',
        'case' => 'pascal',
        'generate' => false,
    ],

    // generate/merge=false: Shield never creates or overwrites the 35 hand-written policies.
    'policies' => [
        'path' => app_path('Policies'),
        'merge' => false,
        'generate' => false,
        'methods' => [
            'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'restore',
            'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
        ],
        'single_parameter_methods' => [
            'viewAny', 'create', 'deleteAny', 'forceDeleteAny', 'restoreAny', 'reorder',
        ],
    ],

    'localization' => [
        'enabled' => false,
        'key' => 'filament-shield::filament-shield.resource_permission_prefixes_labels',
    ],

    'resources' => [
        'subject' => 'model',
        'manage' => [
            RoleResource::class => [
                'viewAny', 'view', 'create', 'update', 'delete',
            ],
        ],
        'exclude' => [],
    ],

    'pages' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            Dashboard::class,
        ],
    ],

    'widgets' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            AccountWidget::class,
            FilamentInfoWidget::class,
        ],
    ],

    'custom_permissions' => [],

    'discovery' => [
        'discover_all_resources' => false,
        'discover_all_widgets' => false,
        'discover_all_pages' => false,
    ],

    // RolePolicy binding to identity.roles.* permissions is added in Ticket 0.1.4;
    // until then Role management follows Filament's default resource authorization.
    'register_role_policy' => false,
];
