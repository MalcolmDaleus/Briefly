<?php
// Default settings. Override any of these in data/config.php (created on first run,
// never overwritten when you upload a new version).
return [
    'app_name' => 'Briefly',

    // Feed
    'feed_window_days' => 7,        // "new" = published within this many days
    'feed_batch_size' => 15,        // articles per feed request
    'feed_pool_limit' => 500,       // candidates considered by the ranker per request

    // Retrieval economy. A source is only requested when it is "due":
    //   it has fewer than source_min_available unseen articles published within pool_fresh_hours,
    //   or it hasn't been checked for source_max_age_hours (to catch breaking news),
    // and never more often than source_min_interval_minutes.
    'pool_fresh_hours' => 24,
    'source_min_available' => 3,
    'source_max_age_hours' => 6,
    'source_min_interval_minutes' => 15,
    'fetch_parallel' => 6,          // feeds requested at the same time
    'fetch_timeout' => 12,          // seconds per feed request
    'cron_time_budget' => 100,      // seconds a cron run may spend fetching
    'on_demand_max_sources' => 6,   // feeds fetched when a user exhausts the pool
    'retention_days' => 30,         // unsaved articles older than this are deleted
    'user_agent' => 'Briefly/1.0 (personal RSS news reader)',
    'ca_bundle' => null,            // path to a CA bundle if the host's cURL lacks one

    // Clustering
    'cluster_window_hours' => 48,
    'cluster_threshold' => 0.6,

    // Accounts
    'allow_registration' => true,   // first account is always allowed (and becomes admin); set false to lock new sign-ups
    'session_days' => 30,

    // Database
    'sqlite_wal' => true,

    // Secret for the web cron URL (/tasks/fetch?key=...). Generated on first run.
    'cron_key' => '',
];
