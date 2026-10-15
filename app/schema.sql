-- Briefly schema. All timestamps are UTC strings: 'YYYY-MM-DD HH:MM:SS'.

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name    TEXT NOT NULL,
    last_name     TEXT NOT NULL,
    email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    theme         TEXT NOT NULL DEFAULT 'system',      -- system | light | dark
    is_admin      INTEGER NOT NULL DEFAULT 0,
    feed_filters  TEXT,                                 -- JSON of the user's feed filter sheet
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS sources (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT NOT NULL,
    domain           TEXT,
    homepage_url     TEXT,
    feed_url         TEXT NOT NULL UNIQUE,
    feed_type        TEXT NOT NULL DEFAULT 'rss',       -- rss | atom | rdf | api
    api_info         TEXT,                              -- JSON, for future API-based sources
    country          TEXT,                              -- ISO code, 'EU' or 'INT'
    region           TEXT,                              -- e.g. 'New York', 'Valencian Community'
    geo_group        TEXT NOT NULL DEFAULT 'intl',      -- diversity bucket, see Sources::GEO
    scope            TEXT,                              -- local | regional | national | continental | international
    language         TEXT NOT NULL DEFAULT 'en',
    source_type      TEXT,                              -- newspaper | broadcaster | magazine | digital | wire | public | institution
    default_category TEXT,
    status           TEXT NOT NULL DEFAULT 'pending',   -- pending | approved | rejected | blocked
    is_active        INTEGER NOT NULL DEFAULT 1,        -- disabled/enabled independently of approval
    paywall_safe     INTEGER NOT NULL DEFAULT 0,        -- skip HTML paywall checks (false-positive override)
    access_status    TEXT NOT NULL DEFAULT 'unknown',   -- unknown | ok | failing | denied
    discovered_via   TEXT,                              -- seed | manual | discovery:<how>
    discovered_at    TEXT,
    notes            TEXT,
    -- fetch bookkeeping
    etag             TEXT,
    last_modified    TEXT,
    last_fetched_at  TEXT,
    last_success_at  TEXT,
    last_status      INTEGER,
    last_error       TEXT,
    fail_count       INTEGER NOT NULL DEFAULT 0,
    retry_after      TEXT,
    last_item_count  INTEGER,
    last_new_count   INTEGER,
    metadata_profile TEXT,                              -- JSON: what fields this feed actually provides
    created_at       TEXT NOT NULL,
    updated_at       TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sources_due ON sources (status, is_active, last_fetched_at);

CREATE TABLE IF NOT EXISTS articles (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id      INTEGER NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    title          TEXT NOT NULL,
    url            TEXT NOT NULL,
    canonical_url  TEXT NOT NULL,
    url_hash       TEXT NOT NULL UNIQUE,                -- sha1 of the normalized URL
    title_hash     TEXT NOT NULL,                       -- sha1 of the folded title (same-source dedup)
    external_id    TEXT,
    excerpt        TEXT,
    image_url      TEXT,
    author         TEXT,
    published_at   TEXT NOT NULL,
    language       TEXT,
    category       TEXT NOT NULL DEFAULT 'general',     -- mapped category, see Categories
    categories_raw TEXT,                                -- JSON array as supplied by the source
    tags           TEXT,                                -- JSON array
    search_text    TEXT NOT NULL DEFAULT '',            -- folded title/excerpt/tags for keyword filtering
    title_tokens   TEXT NOT NULL DEFAULT '',            -- clustering tokens
    cluster_id     INTEGER,                             -- id of the first article in the story cluster
    paywall_status TEXT NOT NULL DEFAULT 'unknown',     -- unknown | free | likely | paywalled
    paywall_reason TEXT,
    paywall_checked_at TEXT,
    retrieved_at   TEXT NOT NULL,
    created_at     TEXT NOT NULL,
    updated_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_articles_published ON articles (published_at);
CREATE INDEX IF NOT EXISTS idx_articles_source ON articles (source_id, published_at);
CREATE INDEX IF NOT EXISTS idx_articles_cluster ON articles (cluster_id);
CREATE INDEX IF NOT EXISTS idx_articles_title ON articles (source_id, title_hash);
CREATE INDEX IF NOT EXISTS idx_articles_external ON articles (source_id, external_id);

CREATE TABLE IF NOT EXISTS user_articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    article_id      INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    seen            INTEGER NOT NULL DEFAULT 0,
    saved           INTEGER NOT NULL DEFAULT 0,
    opened          INTEGER NOT NULL DEFAULT 0,        -- clicked through to the original article
    open_count      INTEGER NOT NULL DEFAULT 0,
    note            TEXT,                               -- optional note on a saved article
    first_seen_at   TEXT,
    first_opened_at TEXT,
    last_opened_at  TEXT,
    first_saved_at  TEXT,                               -- kept even after unsaving
    saved_at        TEXT,                               -- set while currently saved; cleared on unsave
    dismissed       INTEGER NOT NULL DEFAULT 0,         -- user removed it from their feed (e.g. paywall)
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL,
    UNIQUE (user_id, article_id)
);
CREATE INDEX IF NOT EXISTS idx_user_articles_saved ON user_articles (user_id, saved, saved_at);
CREATE INDEX IF NOT EXISTS idx_user_articles_article ON user_articles (article_id, seen);
CREATE INDEX IF NOT EXISTS idx_user_articles_opened ON user_articles (user_id, opened, open_count);
CREATE INDEX IF NOT EXISTS idx_articles_paywall ON articles (paywall_status);
