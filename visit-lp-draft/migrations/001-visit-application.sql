-- 出張買取申込用テーブル
-- 適用前にステージングDBで purchase_requests の実スキーマを確認してください。
-- purchase_requests.shipping_choice に 'visit' が存在する前提です。
-- このSQLは自動実行しません。本番DBへ適用しないでください。

CREATE TABLE IF NOT EXISTS visit_application_drafts (
    draft_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_key_hash BINARY(32) NOT NULL,
    confirmation_token_hash BINARY(32) NOT NULL,
    poll_token_hash BINARY(32) NOT NULL,
    payload_ciphertext MEDIUMBLOB NOT NULL,
    payload_iv BINARY(12) NOT NULL,
    payload_tag BINARY(16) NOT NULL,
    poll_token_ciphertext BINARY(32) NOT NULL,
    poll_token_iv BINARY(12) NOT NULL,
    poll_token_tag BINARY(16) NOT NULL,
    expires_at DATETIME NOT NULL,
    draft_status ENUM('pending', 'confirmed', 'expired') NOT NULL DEFAULT 'pending',
    public_token CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (draft_id),
    UNIQUE KEY uq_visit_drafts_submission_key (submission_key_hash),
    UNIQUE KEY uq_visit_drafts_confirmation_token (confirmation_token_hash),
    UNIQUE KEY uq_visit_drafts_poll_token (poll_token_hash),
    UNIQUE KEY uq_visit_drafts_public_token (public_token),
    KEY ix_visit_drafts_status_expiry (draft_status, expires_at),
    CONSTRAINT fk_visit_drafts_purchase
        FOREIGN KEY (public_token) REFERENCES purchase_requests (public_token)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_purchase_details (
    visit_detail_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_token CHAR(64) NOT NULL,
    collection_code CHAR(2) NOT NULL,
    visit_type VARCHAR(16) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    placement_location VARCHAR(240) NULL,
    placement_note TEXT NULL,
    nameplate_status VARCHAR(16) NOT NULL,
    nameplate_name VARCHAR(80) NULL,
    building_type VARCHAR(24) NOT NULL,
    access_note TEXT NULL,
    confirmed_start_at DATETIME NULL,
    confirmed_end_at DATETIME NULL,
    collection_status VARCHAR(24) NOT NULL DEFAULT 'pending',
    collected_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (visit_detail_id),
    UNIQUE KEY uq_visit_details_public_token (public_token),
    KEY ix_visit_details_status_start (collection_status, confirmed_start_at),
    CONSTRAINT fk_visit_details_purchase
        FOREIGN KEY (public_token) REFERENCES purchase_requests (public_token)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_pickup_time_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_token CHAR(64) NOT NULL,
    preference_order TINYINT UNSIGNED NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_visit_slot_preference (public_token, preference_order),
    UNIQUE KEY uq_visit_slot_duplicate (public_token, start_at, end_at),
    KEY ix_visit_slots_start_at (start_at),
    CONSTRAINT fk_visit_slots_purchase
        FOREIGN KEY (public_token) REFERENCES purchase_requests (public_token)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_consent_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_token CHAR(64) NOT NULL,
    consent_type VARCHAR(48) NOT NULL,
    consent_version VARCHAR(80) NOT NULL,
    consent_text MEDIUMTEXT NOT NULL,
    consented_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_visit_consents_token_time (public_token, consented_at),
    CONSTRAINT fk_visit_consents_purchase
        FOREIGN KEY (public_token) REFERENCES purchase_requests (public_token)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
