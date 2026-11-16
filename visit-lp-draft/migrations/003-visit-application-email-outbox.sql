-- 出張買取の申込完了メールをDBコミット後に送信し、失敗時に再試行するための送信状態。
-- ステージング専用 contact_db のバックアップとスキーマ確認後に、明示操作で適用してください。
-- このSQLは自動実行しません。本番DBへ適用しないでください。

CREATE TABLE IF NOT EXISTS visit_application_email_outbox (
    outbox_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_token CHAR(64) NOT NULL,
    region_slug VARCHAR(80) NOT NULL,
    delivery_status ENUM('pending', 'sending', 'sent') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (outbox_id),
    UNIQUE KEY uq_visit_email_public_token (public_token),
    KEY ix_visit_email_status_attempt (delivery_status, last_attempt_at),
    CONSTRAINT fk_visit_email_outbox_purchase
        FOREIGN KEY (public_token) REFERENCES purchase_requests (public_token)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 確認リンクを送信失敗後に再利用できるよう、トークンはAES-256-GCM暗号文で保持します。
-- 確認成功・期限切れ時はトークン暗号文をランダム値で上書きし、復元できない状態にします。
CREATE TABLE IF NOT EXISTS visit_application_confirmation_outbox (
    draft_id BIGINT UNSIGNED NOT NULL,
    region_slug VARCHAR(80) NOT NULL,
    confirmation_token_ciphertext VARBINARY(64) NOT NULL,
    confirmation_token_iv BINARY(12) NOT NULL,
    confirmation_token_tag BINARY(16) NOT NULL,
    delivery_status ENUM('pending', 'sending', 'sent', 'consumed', 'expired') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (draft_id),
    KEY ix_visit_confirmation_status_attempt (delivery_status, last_attempt_at),
    CONSTRAINT fk_visit_confirmation_outbox_draft
        FOREIGN KEY (draft_id) REFERENCES visit_application_drafts (draft_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
