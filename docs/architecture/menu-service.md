# Menu Service

The `MenuService` centralizes all navigation menu logic, using a configuration file to define the structure and removing hardcoded conditionals from the `app.blade.php` layout.

## Purpose

- Removes complex menu logic from Blade templates.
- Centralizes menu configuration in `config/menu.php`.
- Makes it easy to add or modify menu items.
- Handles role-based menu visibility.
- Manages active state detection for main and sub-menus.

## How It Works

1.  **Configuration**: The entire menu structure for the main and utility navigation is defined in the `config/menu.php` file. This includes items, sub-items (`children`), icons, routes, and role requirements.
2.  **Service Processing**: The `MenuService` reads the configuration from `config/menu.php`. It then processes this structure to determine which items should be displayed based on the user's role and the current route. It sets the `active` state for the relevant main menu item and its children (sub-menu).
3.  **View Injection**: The `MenuService` is automatically injected into the `app.blade.php` view via a View Composer in `AppServiceProvider`. This makes a `$menuService` variable available to the layout.
4.  **Rendering**: The Blade layout uses simple `@foreach` loops to render the items returned by `$menuService->getMainMenu()`, `$menuService->getUtilityMenu()`, and `$menuService->getSubMenu()`.

## Menu Item Structure in `config/menu.php`

Each menu item is an array with these possible keys:

```php
[
    'id' => 'optional-element-id',        // Optional HTML id attribute
    'label' => 'Menu Text',               // Text to display (null for icon-only)
    'icon' => 'fa-icon-name',             // FontAwesome icon class
    'route' => 'route.name',              // Laravel route name
    'patterns' => ['route.name.*'],       // Route patterns to determine when this item is active
    'roles' => ['Admin', 'Impersonator'], // (Optional) Roles required to see this item
    'children' => [],                     // (Optional) An array of sub-menu items
    'title' => 'Tooltip text',            // (Optional) HTML title attribute
    'style' => 'padding: 14px 8px',       // (Optional) Inline CSS styles
    'type' => 'logout',                   // (Optional) Special type for logout button
]
```

## Adding a New Main Menu Item

To add a new item to the main navigation, edit the `'main'` array in `config/menu.php`:

```php
// in config/menu.php
'main' => [
    // ... existing items ...
    [
        'label' => 'Sleep',
        'icon' => 'fa-bed',
        'route' => 'mobile-entry.sleep',
        'patterns' => ['sleep.*', 'mobile-entry.sleep'],
        // No 'children' means no sub-menu for this item
    ],
]
```

## Adding a New Sub-Menu

A sub-menu is created by adding a `children` array to a main menu item in `config/menu.php`. The `MenuService` will automatically display this sub-menu when the parent item is active.

```php
// in config/menu.php
'main' => [
    [
        'id' => 'lifts-nav-link',
        'label' => 'Lifts',
        'icon' => 'fa-dumbbell',
        'route' => 'mobile-entry.lifts',
        'patterns' => [ // These patterns make the parent 'Lifts' item active
            'exercises.*', 'lift-logs.*', 'mobile-entry.lifts', 'workouts.*',
        ],
        'children' => [ // This array defines the sub-menu
            [
                'label' => 'Workouts',
                'route' => 'workouts.index',
                'patterns' => ['workouts.*'], // This pattern makes this specific child active
            ],
            [
                'label' => 'History',
                'route' => 'lift-logs.index',
                'patterns' => ['lift-logs.*'],
            ],
        ],
    ],
    // ... other main menu items
]
```

## Role-Based Menu Items

To restrict a menu item to certain roles, add a `roles` key to its array in `config/menu.php`. The `MenuService` will automatically filter the menu and only show items the user has permission to see.

```php
// in config/menu.php
'children' => [
    // ... other sub-menu items
    [
        'label' => 'Exercises',
        'route' => 'exercises.index',
        'patterns' => ['exercises.*'],
        'roles' => ['Admin'], // Only users with the 'Admin' role will see this.
    ],
]
```

## Dynamic Menu Items

For menus that depend on database data (like user-created measurement types), the `MenuService` has special logic to handle them. A placeholder is added in `config/menu.php`:

```php
// in config/menu.php, under the 'Body' item's children
[
    'type' => 'dynamic-measurement-types', // Special key for the service
    'patterns' => ['body-logs.show-by-type'],
]
```

The `MenuService::getSubMenu()` method detects this `type` and replaces it with a dynamically generated list of menu items from the database.

## Benefits

- **Cleaner Views**: Blade templates are simple loops instead of nested conditionals.
- **Centralized Configuration**: All menu structure is in one config file.
- **Testable**: Menu logic can be unit tested.
- **Maintainable**: Easy to add/modify menu items by editing the config.
- **Consistent**: Same structure for all menu types.
