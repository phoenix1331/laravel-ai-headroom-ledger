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
    | Keys can be up to 64 characters. Values are stored as strings of up to
    | 191 characters; a longer value is skipped and reported, not truncated.
    |
    | primary_tag: one allowlisted key (for example "tenant_id") kept in the
    |              daily rollups so per-tenant queries stay fast. It must
    |              also be in the allowlist, or it is ignored
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
    | Days to keep raw entries and daily rollups. Null keeps them forever.
    | Keep rollups longer than entries for cheap long-term totals: once a
    | day's entries are pruned, its totals come from rollups.
    |
    | schedule: when headroom-ledger:prune runs, as a cron expression. It
    |           rolls up any day it is about to prune that has no rollup yet.
    |           Set to false to schedule it yourself, for example:
    |
    |           Schedule::command('headroom-ledger:prune')->daily()->onOneServer();
    |
    */

    'retention_days' => [
        'entries' => env('HEADROOM_LEDGER_RETENTION_ENTRIES', 90),
        'rollups' => env('HEADROOM_LEDGER_RETENTION_ROLLUPS', 730),
    ],

    'prune' => [
        'schedule' => env('HEADROOM_LEDGER_PRUNE_SCHEDULE', '30 0 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily rollups
    |--------------------------------------------------------------------------
    |
    | Aggregate queries read closed days from daily rollups, so dashboards
    | stay fast as the ledger grows.
    |
    | enabled: build and use rollups
    | schedule: when headroom-ledger:rollup runs, as a cron expression. Each
    |           run catches up every closed day since the last rollup (up to
    |           31 days), so any frequency is safe, from hourly to weekly.
    |           Set to false to schedule it yourself, for example in
    |           routes/console.php:
    |
    |           Schedule::command('headroom-ledger:rollup')->hourly()->onOneServer();
    |
    */

    'rollup' => [
        'enabled' => env('HEADROOM_LEDGER_ROLLUP_ENABLED', true),
        'schedule' => env('HEADROOM_LEDGER_ROLLUP_SCHEDULE', '15 0 * * *'),
    ],

];
