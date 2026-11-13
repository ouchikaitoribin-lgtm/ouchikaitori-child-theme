-- 出張買取の単一希望枠をメール確認後に確保するための日単位ロックテーブル。
-- 確保済み日時は既存の visit_purchase_details.confirmed_start_at / confirmed_end_at に保存します。
-- 既存の4テーブルは変更・再作成しません。このSQLは自動実行しません。
-- 適用前にステージング専用 contact_db のバックアップと既存スキーマを確認してください。

CREATE TABLE IF NOT EXISTS visit_slot_reservation_day_locks (
    service_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
