CREATE TABLE IF NOT EXISTS vaultcast_documents (
    name VARCHAR(120) NOT NULL PRIMARY KEY,
    payload LONGTEXT NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deprecated: the TTL cache moved to data/cache/kv (storage migration v1). Kept, empty, so an older build can roll back.
CREATE TABLE IF NOT EXISTS vaultcast_cache (
    cache_key CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    expires_at BIGINT NOT NULL,
    payload LONGTEXT NOT NULL,
    INDEX cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_playback_tokens (
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    expires_at BIGINT NOT NULL,
    payload LONGTEXT NOT NULL,
    INDEX playback_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_users (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    username VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    payload LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_sessions (
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    expires_at BIGINT NOT NULL,
    payload LONGTEXT NOT NULL,
    INDEX session_expiry (expires_at),
    INDEX session_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_items (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    section_id VARCHAR(120) NOT NULL,
    media_kind VARCHAR(20) NOT NULL,
    title VARCHAR(512) NOT NULL,
    show_title VARCHAR(512) NOT NULL DEFAULT '',
    system_name VARCHAR(120) NOT NULL DEFAULT '',
    initial CHAR(1) CHARACTER SET ascii NOT NULL DEFAULT '#',
    modified_at BIGINT NOT NULL DEFAULT 0,
    generation CHAR(32) CHARACTER SET ascii NOT NULL,
    payload LONGTEXT NOT NULL,
    INDEX section_title (section_id, title(120)),
    INDEX section_modified (section_id, modified_at),
    INDEX section_show (section_id, show_title(120)),
    INDEX media_section (media_kind, section_id),
    INDEX section_system (section_id, system_name, initial),
    INDEX generation_index (generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_progress (
    user_id VARCHAR(64) NOT NULL,
    item_id VARCHAR(64) NOT NULL,
    updated_at BIGINT NOT NULL,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (user_id, item_id),
    INDEX progress_item (item_id),
    INDEX recent_progress (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_metadata_pins (
    kind VARCHAR(20) CHARACTER SET ascii NOT NULL,
    target_id VARCHAR(64) CHARACTER SET ascii NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at BIGINT NOT NULL,
    PRIMARY KEY (kind, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_media_facts (
    item_id VARCHAR(64) NOT NULL PRIMARY KEY,
    sig VARCHAR(48) CHARACTER SET ascii NOT NULL,
    video_codec VARCHAR(32) NOT NULL DEFAULT '',
    audio_codec VARCHAR(32) NOT NULL DEFAULT '',
    needs_convert TINYINT(1) NOT NULL DEFAULT 0,
    present TINYINT(1) NOT NULL DEFAULT 1,
    probed_at BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
