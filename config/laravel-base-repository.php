<?php

return [
    'pagination' => [
        'default_per_page' => env('LARAVEL_BASE_REPOSITORY_PAGINATION_DEFAULT_PER_PAGE', 10),
    ],
    'order_by' => [
        'default_field' => env('LARAVEL_BASE_REPOSITORY_ORDER_BY_DEFAULT_FIELD', 'id'),
        'default_direction' => env('LARAVEL_BASE_REPOSITORY_ORDER_BY_DEFAULT_DIRECTION', 'asc'),
    ],
];