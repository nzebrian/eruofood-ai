<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog module configuration (Nigerian Food Database & Recipes)
|------------------------------------------------------------------------------
*/

return [
    // Default page size for catalogue/recipe listings.
    'pagination' => [
        'per_page' => (int) env('CATALOG_PER_PAGE', 20),
        'max_per_page' => 60,
    ],

    // Object-storage disk for food/recipe/step images (S3-compatible in prod).
    // Defaults to the local "public" disk so dev/test need no S3 configuration.
    'media_disk' => env('CATALOG_MEDIA_DISK', 'public'),

    // Whether newly created foods/recipes require moderation before publishing.
    'moderation' => (bool) env('CATALOG_MODERATION', true),
];
