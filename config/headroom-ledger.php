<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Recording
    |--------------------------------------------------------------------------
    |
    | Switch the ledger off without uninstalling it. Headroom keeps firing its
    | events; nothing is written while this is false.
    |
    */

    'enabled' => env('HEADROOM_LEDGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | connection: a separate database connection for the ledger tables, or
    |             null for the app's default connection
    | table_prefix: prepended to the entries, tags and daily table names
    |
    */

    'connection' => env('HEADROOM_LEDGER_DB_CONNECTION'),

    'table_prefix' => env('HEADROOM_LEDGER_TABLE_PREFIX', 'headroom_ledger_'),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Listeners are queued so recording never slows a request. Set the
    | connection to "sync" to record inline instead. Null uses the app's
    | default queue connection and queue name.
    |
    */

    'queue' => [
        'connection' => env('HEADROOM_LEDGER_QUEUE_CONNECTION'),
        'name' => env('HEADROOM_LEDGER_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tags
    |--------------------------------------------------------------------------
    |
    | Context tags from Headroom::resolveContextUsing() are stored only when
    | their key is listed here. The default is empty, so nothing personal is
    | recorded unless the app opts in. User IDs are personal data.
    |
    | primary_tag: one allowlisted key (for example "tenant_id") kept in the
    |              daily rollups so per-tenant queries stay fast
    |
    */

    'tags' => [
        'allowed' => [],
        'primary_tag' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Days to keep raw entries and daily rollups before pruning. Null keeps
    | them forever.
    |
    */

    'retention_days' => [
        'entries' => env('HEADROOM_LEDGER_RETENTION_ENTRIES', 90),
        'rollups' => env('HEADROOM_LEDGER_RETENTION_ROLLUPS', 730),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily rollups
    |--------------------------------------------------------------------------
    |
    | Aggregate queries read from daily rollups for closed days. When enabled,
    | the rollup command is scheduled daily at the given time.
    |
    */

    'rollup' => [
        'enabled' => env('HEADROOM_LEDGER_ROLLUP_ENABLED', true),
        'time' => env('HEADROOM_LEDGER_ROLLUP_TIME', '00:15'),
    ],

];
