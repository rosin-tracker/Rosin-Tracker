CREATE TABLE owner_accounts (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    username TEXT NOT NULL COLLATE NOCASE UNIQUE
        CHECK (length(trim(username)) BETWEEN 1 AND 64),
    password_hash TEXT NOT NULL,
    session_epoch INTEGER NOT NULL DEFAULT 1 CHECK (session_epoch >= 1),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO settings (key, value, updated_at)
VALUES
    ('unit_system', 'metric', strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('timezone', 'Europe/Copenhagen', strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('accent', 'blue', strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));

CREATE TABLE batch_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE
        CHECK (length(trim(name)) BETWEEN 1 AND 80),
    payload_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pressed_at TEXT NOT NULL,
    start_material TEXT NOT NULL CHECK (length(trim(start_material)) > 0),
    start_amount_g REAL NOT NULL CHECK (start_amount_g > 0),
    yield_amount_g REAL NOT NULL CHECK (yield_amount_g >= 0 AND yield_amount_g <= start_amount_g),
    press_capacity_tons REAL CHECK (press_capacity_tons IS NULL OR press_capacity_tons > 0),
    humidity_percent REAL CHECK (
        humidity_percent IS NULL OR humidity_percent BETWEEN 0 AND 100
    ),
    notes TEXT,
    source_template_id INTEGER REFERENCES batch_templates(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX batches_pressed_at_index ON batches (pressed_at DESC);
CREATE INDEX batches_material_index ON batches (start_material COLLATE NOCASE);

CREATE TABLE batch_strains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id INTEGER NOT NULL REFERENCES batches(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position >= 0),
    name TEXT NOT NULL COLLATE NOCASE CHECK (length(trim(name)) BETWEEN 1 AND 120),
    amount_g REAL CHECK (amount_g IS NULL OR amount_g > 0),
    UNIQUE (batch_id, position)
);

CREATE INDEX batch_strains_name_index ON batch_strains (name COLLATE NOCASE);

CREATE TABLE batch_bags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id INTEGER NOT NULL REFERENCES batches(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position >= 0),
    micron INTEGER NOT NULL CHECK (micron BETWEEN 1 AND 500),
    width_mm REAL NOT NULL CHECK (width_mm > 0 AND width_mm <= 1000),
    length_mm REAL NOT NULL CHECK (length_mm > 0 AND length_mm <= 2000),
    layer INTEGER NOT NULL CHECK (layer >= 1),
    brand TEXT CHECK (brand IS NULL OR length(trim(brand)) BETWEEN 1 AND 80),
    UNIQUE (batch_id, position)
);

CREATE TABLE batch_passes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id INTEGER NOT NULL REFERENCES batches(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position BETWEEN 1 AND 20),
    temperature_c REAL NOT NULL CHECK (temperature_c BETWEEN -50 AND 300),
    pressure_bar REAL CHECK (pressure_bar IS NULL OR pressure_bar >= 0),
    preheat_seconds INTEGER CHECK (
        preheat_seconds IS NULL OR preheat_seconds BETWEEN 0 AND 7200
    ),
    press_duration_seconds INTEGER CHECK (
        press_duration_seconds IS NULL OR press_duration_seconds BETWEEN 0 AND 7200
    ),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (batch_id, position)
);

CREATE TRIGGER batch_passes_maximum_twenty
BEFORE INSERT ON batch_passes
WHEN (SELECT COUNT(*) FROM batch_passes WHERE batch_id = NEW.batch_id) >= 20
BEGIN
    SELECT RAISE(ABORT, 'a batch can contain no more than twenty passes');
END;

CREATE TABLE batch_photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id INTEGER NOT NULL REFERENCES batches(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position >= 0),
    storage_name TEXT NOT NULL UNIQUE,
    original_name TEXT NOT NULL,
    mime_type TEXT NOT NULL CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp')),
    byte_size INTEGER NOT NULL CHECK (byte_size > 0),
    created_at TEXT NOT NULL,
    UNIQUE (batch_id, position)
);

CREATE TRIGGER batch_photos_maximum_five
BEFORE INSERT ON batch_photos
WHEN (SELECT COUNT(*) FROM batch_photos WHERE batch_id = NEW.batch_id) >= 5
BEGIN
    SELECT RAISE(ABORT, 'a batch can contain no more than five photographs');
END;

CREATE TABLE preset_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field_key TEXT NOT NULL CHECK (length(trim(field_key)) BETWEEN 1 AND 64),
    label TEXT NOT NULL CHECK (length(trim(label)) BETWEEN 1 AND 120),
    value_json TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (field_key, label COLLATE NOCASE)
);

CREATE INDEX preset_options_field_index
    ON preset_options (field_key, sort_order, label COLLATE NOCASE);

INSERT INTO preset_options (field_key, label, value_json, sort_order, created_at, updated_at)
VALUES
    ('start_material', 'Flower', 'null', 10, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('start_material', 'Hash', 'null', 20, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('start_material', 'Kief', 'null', 30, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('start_material', 'Trim', 'null', 40, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('bag', '50.8 × 101.6 mm · 25 μm', '{"brand":null,"micron":25,"widthMm":50.8,"lengthMm":101.6}', 10, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('bag', '50.8 × 101.6 mm · 37 μm', '{"brand":null,"micron":37,"widthMm":50.8,"lengthMm":101.6}', 20, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('bag', '50.8 × 101.6 mm · 73 μm', '{"brand":null,"micron":73,"widthMm":50.8,"lengthMm":101.6}', 30, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('bag', '50.8 × 101.6 mm · 90 μm', '{"brand":null,"micron":90,"widthMm":50.8,"lengthMm":101.6}', 40, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('bag', '50.8 × 101.6 mm · 120 μm', '{"brand":null,"micron":120,"widthMm":50.8,"lengthMm":101.6}', 50, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    ('bag', '50.8 × 101.6 mm · 160 μm', '{"brand":null,"micron":160,"widthMm":50.8,"lengthMm":101.6}', 60, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));

CREATE TABLE authentication_state (
    owner_id INTEGER PRIMARY KEY CHECK (owner_id = 1)
        REFERENCES owner_accounts(id) ON DELETE CASCADE,
    active_method TEXT NOT NULL DEFAULT 'local'
        CHECK (active_method IN ('local', 'oidc')),
    updated_at TEXT NOT NULL
);

CREATE TABLE owner_totp (
    owner_id INTEGER PRIMARY KEY CHECK (owner_id = 1)
        REFERENCES owner_accounts(id) ON DELETE CASCADE,
    secret_ciphertext TEXT NOT NULL,
    secret_nonce TEXT NOT NULL,
    secret_key_version INTEGER NOT NULL DEFAULT 1 CHECK (secret_key_version >= 1),
    last_counter INTEGER NOT NULL CHECK (last_counter >= 0),
    enabled_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE owner_recovery_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id INTEGER NOT NULL CHECK (owner_id = 1)
        REFERENCES owner_accounts(id) ON DELETE CASCADE,
    code_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    used_at TEXT
);

CREATE INDEX owner_recovery_codes_available_index
    ON owner_recovery_codes (owner_id, used_at, id);

CREATE TABLE oidc_provider_config (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    owner_id INTEGER NOT NULL UNIQUE CHECK (owner_id = 1)
        REFERENCES owner_accounts(id) ON DELETE CASCADE,
    provider_type TEXT NOT NULL CHECK (provider_type IN ('entra', 'generic')),
    display_name TEXT NOT NULL CHECK (length(trim(display_name)) BETWEEN 1 AND 80),
    tenant_id TEXT,
    issuer TEXT NOT NULL CHECK (length(trim(issuer)) BETWEEN 8 AND 2048),
    client_id TEXT NOT NULL CHECK (length(trim(client_id)) BETWEEN 1 AND 512),
    client_secret_ciphertext TEXT NOT NULL,
    client_secret_nonce TEXT NOT NULL,
    client_secret_key_version INTEGER NOT NULL DEFAULT 1 CHECK (client_secret_key_version >= 1),
    scopes TEXT NOT NULL DEFAULT 'openid profile email'
        CHECK (length(trim(scopes)) BETWEEN 6 AND 1000),
    token_auth_method TEXT NOT NULL DEFAULT 'client_secret_post'
        CHECK (token_auth_method IN ('client_secret_basic', 'client_secret_post')),
    configuration_fingerprint TEXT NOT NULL CHECK (length(configuration_fingerprint) = 64),
    verified_issuer TEXT NOT NULL CHECK (length(trim(verified_issuer)) BETWEEN 8 AND 2048),
    verified_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (
        (provider_type = 'entra' AND tenant_id IS NOT NULL AND length(trim(tenant_id)) >= 32)
        OR (provider_type = 'generic' AND tenant_id IS NULL)
    )
);

CREATE TABLE owner_oidc_identity (
    owner_id INTEGER PRIMARY KEY CHECK (owner_id = 1)
        REFERENCES owner_accounts(id) ON DELETE CASCADE,
    provider_id INTEGER NOT NULL UNIQUE CHECK (provider_id = 1)
        REFERENCES oidc_provider_config(id) ON DELETE CASCADE,
    issuer TEXT NOT NULL CHECK (length(trim(issuer)) BETWEEN 8 AND 2048),
    subject TEXT NOT NULL CHECK (length(subject) BETWEEN 1 AND 1024),
    display_name TEXT,
    email TEXT,
    entra_tenant_id TEXT,
    entra_object_id TEXT,
    linked_at TEXT NOT NULL,
    UNIQUE (issuer, subject)
);

CREATE TABLE authentication_login_throttle (
    owner_id INTEGER PRIMARY KEY CHECK (owner_id = 1)
        REFERENCES owner_accounts(id) ON DELETE CASCADE,
    failure_count INTEGER NOT NULL DEFAULT 0 CHECK (failure_count BETWEEN 0 AND 4),
    blocked_until INTEGER CHECK (blocked_until IS NULL OR blocked_until >= 0),
    updated_at INTEGER NOT NULL CHECK (updated_at >= 0)
);

CREATE TABLE authentication_sensitive_throttle (
    owner_id INTEGER PRIMARY KEY CHECK (owner_id = 1)
        REFERENCES owner_accounts(id) ON DELETE CASCADE,
    failure_count INTEGER NOT NULL DEFAULT 0 CHECK (failure_count BETWEEN 0 AND 4),
    blocked_until INTEGER CHECK (blocked_until IS NULL OR blocked_until >= 0),
    updated_at INTEGER NOT NULL CHECK (updated_at >= 0)
);

CREATE TABLE legacy_v1_imports (
    source_key TEXT NOT NULL
        CHECK (
            length(source_key) BETWEEN 1 AND 96
            AND source_key NOT GLOB '*[^A-Za-z0-9._-]*'
        ),
    legacy_record_id INTEGER NOT NULL CHECK (legacy_record_id > 0),
    source_row_sha256 TEXT NOT NULL
        CHECK (
            length(source_row_sha256) = 64
            AND source_row_sha256 NOT GLOB '*[^0-9a-f]*'
        ),
    batch_id INTEGER NOT NULL REFERENCES batches(id) ON DELETE CASCADE,
    imported_at TEXT NOT NULL,
    PRIMARY KEY (source_key, legacy_record_id),
    UNIQUE (batch_id)
);

CREATE TRIGGER authentication_state_oidc_requires_link_on_insert
BEFORE INSERT ON authentication_state
WHEN NEW.active_method = 'oidc'
  AND NOT EXISTS (
      SELECT 1 FROM owner_oidc_identity WHERE owner_id = NEW.owner_id
  )
BEGIN
    SELECT RAISE(ABORT, 'OpenID Connect must be linked before activation');
END;

CREATE TRIGGER authentication_state_oidc_requires_link_on_update
BEFORE UPDATE OF active_method ON authentication_state
WHEN NEW.active_method = 'oidc'
  AND NOT EXISTS (
      SELECT 1 FROM owner_oidc_identity WHERE owner_id = NEW.owner_id
  )
BEGIN
    SELECT RAISE(ABORT, 'OpenID Connect must be linked before activation');
END;
