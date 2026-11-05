<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store to use for Cogent response caching. If null, the
    | default cache store is used.
    */
    'store' => env('COGENT_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Domains
    |--------------------------------------------------------------------------
    |
    | Cache domains group related resources together. Each domain can have
    | one or more surfaces registered against it.
    */
    'domains' => [
        'posts',
        'users',
        'comments',
        'channels',
        'conversations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Surface Registry
    |--------------------------------------------------------------------------
    |
    | Maps each domain to the surfaces it supports. A surface is a named
    | cache layer with its own enabled flag and TTL.
    */
    'registry' => [
        'posts' => ['list', 'counts', 'detail', 'scope'],
        'users' => ['list', 'detail', 'scope'],
        'comments' => ['list', 'counts'],
        'channels' => ['scope'],
        'conversations' => ['list', 'counts', 'context', 'scope', 'detail'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Surfaces
    |--------------------------------------------------------------------------
    |
    | Each surface can be enabled/disabled and has a default TTL in seconds.
    | Set enabled to false to bypass caching for that surface entirely.
    */
    'surfaces' => [
        'list' => [
            'enabled' => (bool) env('COGENT_LIST_CACHE_ENABLED', false),
            'ttl' => (int) env('COGENT_LIST_CACHE_TTL', 30),
        ],
        'counts' => [
            'enabled' => (bool) env('COGENT_COUNTS_CACHE_ENABLED', false),
            'ttl' => (int) env('COGENT_COUNTS_CACHE_TTL', 20),
        ],
        'context' => [
            'enabled' => (bool) env('COGENT_CONTEXT_CACHE_ENABLED', false),
            'ttl' => (int) env('COGENT_CONTEXT_CACHE_TTL', 60),
        ],
        'scope' => [
            'enabled' => (bool) env('COGENT_SCOPE_CACHE_ENABLED', false),
            'ttl' => (int) env('COGENT_SCOPE_CACHE_TTL', 120),
        ],
        'detail' => [
            'enabled' => (bool) env('COGENT_DETAIL_CACHE_ENABLED', false),
            'ttl' => (int) env('COGENT_DETAIL_CACHE_TTL', 30),
        ],
    ],

];
