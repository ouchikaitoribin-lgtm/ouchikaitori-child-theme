-- Persist wrong confirmation-code attempts per application draft.
-- Apply only after checking the staging contact_db schema and taking its backup.
-- This additive DDL is not auto-executed and must never be applied to production.

ALTER TABLE visit_application_drafts
    ADD COLUMN IF NOT EXISTS verification_attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER confirmation_token_hash;
