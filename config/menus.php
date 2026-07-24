<?php

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Admin menu
|--------------------------------------------------------------------------
|
| One WordPress menu entry. The admin SPA owns navigation below that point
| (?page=fitnessclub#/users), so adding a resource does not add a WP menu item.
|
*/

return [
    'fitnessclub' => [
        'page_title' => __('FitnessClub', 'fitnessclub'),
        'menu_title' => __('FitnessClub', 'fitnessclub'),
        'capability' => 'manage_options',
        'position'   => 58,
        'items'      => [
            [
                'page_title' => __('Dashboard', 'fitnessclub'),
                'menu_title' => __('Dashboard', 'fitnessclub'),
                'capability' => 'manage_options',
                'route'      => [
                    'get' => 'Admin\AdminAppController@index',
                ],
            ],
        ],
    ],
];
