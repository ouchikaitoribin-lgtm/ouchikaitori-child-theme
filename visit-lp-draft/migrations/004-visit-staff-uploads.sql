-- Staff-captured identity and pre-collection photos for visit purchases.
-- Review and apply manually only after checking the staging schema and backup.
-- This migration is not run by WordPress or the deployment script.

CREATE TABLE IF NOT EXISTS visit_staff_uploads (
    upload_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_token CHAR(64) NOT NULL,
    batch_id CHAR(32) NOT NULL,
    media_type ENUM('identity_front', 'identity_back', 'collection_pre_photo') NOT NULL,
    document_type VARCHAR(50) NULL,
    collection_item_count INT UNSIGNED NULL,
    storage_key VARCHAR(255) NOT NULL,
    mime_type VARCHAR(32) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (upload_id),
    KEY ix_visit_staff_upload_token_type_time (public_token, media_type, created_at),
    KEY ix_visit_staff_upload_batch (batch_id),
    CONSTRAINT fk_visit_staff_upload_purchase
        FOREIGN KEY (public_token) REFERENCES purchase_requests (public_token)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
