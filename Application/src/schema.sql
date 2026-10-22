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
    added_at BIGINT NOT NULL DEFAULT 0,
    generation CHAR(32) CHARACTER SET ascii NOT NULL,
    payload LONGTEXT NOT NULL,
    INDEX section_title (section_id, title(120)),
    INDEX section_modified (section_id, modified_at),
    INDEX section_added (section_id, added_at),
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
    probed_at BIGINT NOT NULL DEFAULT 0,
    duration DOUBLE NOT NULL DEFAULT 0,
    INDEX duration_index (duration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_chat_rooms (
 id VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 kind VARCHAR(12) NOT NULL, title VARCHAR(100) NOT NULL, owner_id VARCHAR(64) NOT NULL,
 peer_id VARCHAR(64) NOT NULL DEFAULT '', retention INT UNSIGNED NOT NULL DEFAULT 86400,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1, updated_at BIGINT NOT NULL,
 INDEX chat_owner (owner_id,kind), INDEX chat_activity (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_chat_members (
 room_id VARCHAR(64) CHARACTER SET ascii NOT NULL, user_id VARCHAR(64) NOT NULL,
 read_id BIGINT UNSIGNED NOT NULL DEFAULT 0, muted TINYINT NOT NULL DEFAULT 0,
 banned TINYINT NOT NULL DEFAULT 0, seen_at BIGINT NOT NULL DEFAULT 0,
 typing_until BIGINT NOT NULL DEFAULT 0, PRIMARY KEY(room_id,user_id), INDEX chat_member (user_id,room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_chat_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 room_id VARCHAR(64) CHARACTER SET ascii NOT NULL, sender_id VARCHAR(64) NOT NULL,
 body TEXT NOT NULL, attachment VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '',
 nonce VARCHAR(32) CHARACTER SET ascii NOT NULL, created_at BIGINT NOT NULL,
 expires_at BIGINT NOT NULL DEFAULT 0, edited_at BIGINT NOT NULL DEFAULT 0,
 UNIQUE KEY chat_nonce (sender_id,nonce), INDEX chat_history(room_id,id,sender_id,expires_at),
 INDEX chat_expiry(expires_at), INDEX chat_sender(sender_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_chat_files (
 id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 room_id VARCHAR(64) CHARACTER SET ascii NOT NULL, user_id VARCHAR(64) NOT NULL,
 bytes INT UNSIGNED NOT NULL, received INT UNSIGNED NOT NULL DEFAULT 0,
 mime VARCHAR(32) NOT NULL DEFAULT '', created_at BIGINT NOT NULL,
 message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 INDEX chat_file_gc(message_id,created_at), INDEX chat_file_owner(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_chat_presence (
 user_id VARCHAR(64) NOT NULL PRIMARY KEY, seen_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_browse_items (
 item_id VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 section_id VARCHAR(120) NOT NULL, media_kind VARCHAR(20) NOT NULL, type VARCHAR(20) NOT NULL,
 title VARCHAR(512) NOT NULL, search_path TEXT NOT NULL, show_key VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '',
 show_name VARCHAR(512) NOT NULL DEFAULT '', season INT NULL, episode INT NULL, year INT NULL,
 system_name VARCHAR(120) NOT NULL DEFAULT '', modified_at BIGINT NOT NULL DEFAULT 0,
 source_sig CHAR(64) CHARACTER SET ascii NOT NULL,
 INDEX browse_section(section_id,title(100)), INDEX browse_show(show_key,item_id), INDEX browse_title(title(120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_show_summaries (
 show_key VARCHAR(16) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 section_id VARCHAR(120) NOT NULL, title VARCHAR(512) NOT NULL, type VARCHAR(20) NOT NULL,
 source_sig CHAR(32) CHARACTER SET ascii NOT NULL, prepared_sig CHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '',
 payload LONGTEXT NOT NULL,
 INDEX summary_section(section_id,title(120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_play_scores (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id VARCHAR(64) NOT NULL, game VARCHAR(24) CHARACTER SET ascii NOT NULL,
 score INT NOT NULL, duration_ms INT UNSIGNED NOT NULL DEFAULT 0, meta VARCHAR(255) NOT NULL DEFAULT '',
 created_at BIGINT NOT NULL,
 INDEX play_board (game,created_at,score), INDEX play_board_user (game,created_at,user_id,score), INDEX play_user (user_id,game,score), INDEX play_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_play_points (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id VARCHAR(64) NOT NULL, team_id VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '',
 source VARCHAR(24) CHARACTER SET ascii NOT NULL, points INT NOT NULL DEFAULT 0,
 label VARCHAR(200) NOT NULL DEFAULT '', created_at BIGINT NOT NULL,
 INDEX play_points_user (user_id,created_at), INDEX play_points_team (team_id,created_at), INDEX play_points_time (created_at), INDEX play_points_board (created_at,user_id,points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_drop_files (
 id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 user_id VARCHAR(64) NOT NULL, kind VARCHAR(8) CHARACTER SET ascii NOT NULL,
 name VARCHAR(255) NOT NULL DEFAULT '', mime VARCHAR(120) NOT NULL DEFAULT '',
 bytes BIGINT UNSIGNED NOT NULL DEFAULT 0, received BIGINT UNSIGNED NOT NULL DEFAULT 0,
 body MEDIUMTEXT NULL, complete TINYINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL DEFAULT 0,
 max_downloads INT UNSIGNED NOT NULL DEFAULT 0, downloads INT UNSIGNED NOT NULL DEFAULT 0, purged TINYINT NOT NULL DEFAULT 0, inbox TINYINT NOT NULL DEFAULT 0,
 origin VARCHAR(64) NOT NULL DEFAULT '',
 INDEX drop_user (user_id,created_at), INDEX drop_expiry (expires_at), INDEX drop_pending (complete,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_drop_links (
 token CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 file_id CHAR(32) CHARACTER SET ascii NOT NULL, user_id VARCHAR(64) NOT NULL,
 require_login TINYINT NOT NULL DEFAULT 1, password_hash VARCHAR(255) NOT NULL DEFAULT '',
 expires_at BIGINT NOT NULL DEFAULT 0, max_downloads INT UNSIGNED NOT NULL DEFAULT 0, downloads INT UNSIGNED NOT NULL DEFAULT 0,
 revoked TINYINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL,
 INDEX drop_link_file (file_id), INDEX drop_link_user (user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_play_emulator (
 user_id VARCHAR(64) NOT NULL, day CHAR(10) CHARACTER SET ascii NOT NULL,
 seconds INT UNSIGNED NOT NULL DEFAULT 0, sessions INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (user_id, day), INDEX emu_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_cal_calendars (
 id CHAR(16) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 owner_id VARCHAR(64) NOT NULL, name VARCHAR(80) NOT NULL, colour CHAR(7) CHARACTER SET ascii NOT NULL DEFAULT '#59e9ff',
 kind VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'local', url TEXT NULL,
 feed_token CHAR(40) CHARACTER SET ascii NULL, episodes VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '',
 show_reminders TINYINT NOT NULL DEFAULT 0, shares TEXT NULL, version INT UNSIGNED NOT NULL DEFAULT 0,
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 INDEX cal_owner (owner_id), UNIQUE KEY cal_feed (feed_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_cal_events (
 id CHAR(16) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 calendar_id CHAR(16) CHARACTER SET ascii NOT NULL, uid VARCHAR(190) NOT NULL,
 title VARCHAR(200) NOT NULL DEFAULT '', location VARCHAR(200) NOT NULL DEFAULT '', notes TEXT NULL,
 all_day TINYINT NOT NULL DEFAULT 0, start_at BIGINT NOT NULL, end_at BIGINT NOT NULL, tz VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT 'UTC',
 rrule VARCHAR(255) CHARACTER SET ascii NOT NULL DEFAULT '', until_at BIGINT NOT NULL DEFAULT 0, exdates TEXT NULL,
 reminders VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT '', source VARCHAR(8) CHARACTER SET ascii NOT NULL DEFAULT '',
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 INDEX cal_event_range (calendar_id,start_at), INDEX cal_event_uid (calendar_id,uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_cal_reminders (
 id CHAR(16) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 user_id VARCHAR(64) NOT NULL, title VARCHAR(120) NOT NULL, emoji VARCHAR(16) NOT NULL DEFAULT '',
 colour CHAR(7) CHARACTER SET ascii NOT NULL DEFAULT '#59e9ff', note VARCHAR(200) NOT NULL DEFAULT '',
 template VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '', schedule TEXT NOT NULL,
 at_time CHAR(5) CHARACTER SET ascii NOT NULL DEFAULT '', lead_minutes INT NOT NULL DEFAULT 0, tz VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT 'UTC',
 done_through CHAR(10) CHARACTER SET ascii NOT NULL DEFAULT '', snooze_until BIGINT NOT NULL DEFAULT 0,
 active TINYINT NOT NULL DEFAULT 1, on_calendar TINYINT NOT NULL DEFAULT 0,
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
 INDEX cal_rem_user (user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS vaultcast_pictures (
 id CHAR(16) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 user_id VARCHAR(64) NOT NULL, name VARCHAR(200) NOT NULL DEFAULT '', ext VARCHAR(5) CHARACTER SET ascii NOT NULL,
 mime VARCHAR(32) CHARACTER SET ascii NOT NULL, bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 width INT UNSIGNED NOT NULL DEFAULT 0, height INT UNSIGNED NOT NULL DEFAULT 0, sha1 CHAR(40) CHARACTER SET ascii NOT NULL DEFAULT '',
 created_at BIGINT NOT NULL,
 INDEX pictures_user (user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_webcams (
 id CHAR(16) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 url_key CHAR(40) CHARACTER SET ascii NOT NULL,
 name VARCHAR(120) NOT NULL DEFAULT '', url VARCHAR(1000) NOT NULL, kind VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'auto',
 added_by VARCHAR(64) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, last_seen BIGINT NOT NULL,
 enabled_ambient TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY webcams_url (url_key),
 INDEX webcams_enabled (enabled_ambient,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_content_ratings (
 target VARCHAR(80) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 bucket TINYINT NOT NULL DEFAULT 0, raw VARCHAR(40) NOT NULL DEFAULT '',
 source VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '', updated_at BIGINT NOT NULL DEFAULT 0,
 INDEX rating_bucket (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_smart_collections (
 id CHAR(16) CHARACTER SET ascii NOT NULL PRIMARY KEY, owner_id VARCHAR(64) NOT NULL DEFAULT '',
 name VARCHAR(120) NOT NULL, description VARCHAR(400) NOT NULL DEFAULT '', rules TEXT NOT NULL,
 pin_home TINYINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL DEFAULT 0,
 INDEX smart_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_smart_facts (
 item_id VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY, show_key VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '',
 genres VARCHAR(1500) NOT NULL DEFAULT '', people TEXT NULL, community DECIMAL(3,1) NOT NULL DEFAULT 0,
 height INT NOT NULL DEFAULT 0, sig VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '', updated_at BIGINT NOT NULL DEFAULT 0,
 INDEX smart_facts_show (show_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_inbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id VARCHAR(64) NOT NULL,
 type VARCHAR(16) CHARACTER SET ascii NOT NULL, subtype VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '',
 dedupe VARCHAR(120) NOT NULL, actor TEXT NOT NULL, title VARCHAR(255) NOT NULL DEFAULT '', body VARCHAR(600) NOT NULL DEFAULT '',
 action TEXT NOT NULL, payload TEXT NOT NULL, created_at BIGINT NOT NULL DEFAULT 0, read_at BIGINT NOT NULL DEFAULT 0, deleted TINYINT NOT NULL DEFAULT 0,
 UNIQUE KEY inbox_dedupe (user_id, dedupe), INDEX inbox_user (user_id, deleted, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_inbox_seen (
 user_id VARCHAR(64) NOT NULL PRIMARY KEY, last_seen BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_parental (
 user_id VARCHAR(64) NOT NULL PRIMARY KEY,
 settings TEXT NOT NULL, pin_hash VARCHAR(255) NOT NULL DEFAULT '',
 pin_failures INT NOT NULL DEFAULT 0, pin_locked_until BIGINT NOT NULL DEFAULT 0,
 updated_at BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_media_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 ident VARCHAR(120) CHARACTER SET ascii NOT NULL, title_key VARCHAR(100) CHARACTER SET ascii NOT NULL, match_key VARCHAR(190) NOT NULL DEFAULT '',
 media_type VARCHAR(8) CHARACTER SET ascii NOT NULL, source VARCHAR(16) CHARACTER SET ascii NOT NULL, ext_id VARCHAR(40) CHARACTER SET ascii NOT NULL,
 scope VARCHAR(8) CHARACTER SET ascii NOT NULL DEFAULT '', season INT NOT NULL DEFAULT -1,
 title VARCHAR(255) NOT NULL, year SMALLINT NOT NULL DEFAULT 0, overview TEXT NULL, poster VARCHAR(600) NOT NULL DEFAULT '',
 imdb_id VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '', rating_bucket TINYINT NOT NULL DEFAULT 0, rating_raw VARCHAR(40) NOT NULL DEFAULT '',
 status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'pending', reason VARCHAR(400) NOT NULL DEFAULT '', note VARCHAR(255) NOT NULL DEFAULT '',
 decided_by VARCHAR(120) NOT NULL DEFAULT '', decided_at BIGINT NOT NULL DEFAULT 0,
 acq TEXT NULL, progress DECIMAL(5,1) NOT NULL DEFAULT 0, library_ref VARCHAR(100) CHARACTER SET ascii NOT NULL DEFAULT '',
 created_at BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL DEFAULT 0, available_at BIGINT NOT NULL DEFAULT 0,
 UNIQUE KEY mreq_ident (ident), INDEX mreq_status (status, updated_at), INDEX mreq_title (title_key), INDEX mreq_match (match_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_media_request_users (
 request_id BIGINT UNSIGNED NOT NULL, user_id VARCHAR(64) NOT NULL, note VARCHAR(300) NOT NULL DEFAULT '',
 restricted TINYINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL DEFAULT 0,
 PRIMARY KEY (request_id, user_id), INDEX mreq_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v16: trailers and extras found next to films and shows (Plex / Jellyfin folder and suffix names).
-- Extras are not library items: they only play from their parent title page.
CREATE TABLE IF NOT EXISTS vaultcast_extras (
 id CHAR(24) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 section_id VARCHAR(120) NOT NULL,
 parent_kind VARCHAR(8) CHARACTER SET ascii NOT NULL,
 parent_id VARCHAR(64) CHARACTER SET ascii NOT NULL,
 season INT NULL,
 extra_type VARCHAR(24) CHARACTER SET ascii NOT NULL,
 title VARCHAR(255) NOT NULL,
 path TEXT NOT NULL,
 rel TEXT NOT NULL,
 ext VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '',
 size BIGINT NOT NULL DEFAULT 0,
 mtime BIGINT NOT NULL DEFAULT 0,
 duration INT NOT NULL DEFAULT 0,
 probed TINYINT NOT NULL DEFAULT 0,
 seen_gen CHAR(16) CHARACTER SET ascii NOT NULL DEFAULT '',
 created_at BIGINT NOT NULL DEFAULT 0,
 updated_at BIGINT NOT NULL DEFAULT 0,
 INDEX extras_parent (parent_kind, parent_id),
 INDEX extras_section (section_id, seen_gen),
 INDEX extras_probe (probed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v17: achievements and badges. Earned achievements, incremental counters and one-off marks
-- (a finished film, a Watch together room, a review) so progress never needs a heavy scan.
CREATE TABLE IF NOT EXISTS vaultcast_achievements (
 user_id VARCHAR(64) NOT NULL, ach_id VARCHAR(40) CHARACTER SET ascii NOT NULL,
 earned_at BIGINT NOT NULL DEFAULT 0, toasted TINYINT NOT NULL DEFAULT 0, showcase TINYINT NOT NULL DEFAULT 0,
 PRIMARY KEY (user_id, ach_id), INDEX ach_rarity (ach_id), INDEX ach_toast (user_id, toasted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_achievement_counters (
 user_id VARCHAR(64) NOT NULL, counter VARCHAR(32) CHARACTER SET ascii NOT NULL,
 value BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL DEFAULT 0,
 PRIMARY KEY (user_id, counter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vaultcast_achievement_marks (
 user_id VARCHAR(64) NOT NULL, mark VARCHAR(96) CHARACTER SET ascii NOT NULL, created_at BIGINT NOT NULL DEFAULT 0,
 PRIMARY KEY (user_id, mark), INDEX ach_mark_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
