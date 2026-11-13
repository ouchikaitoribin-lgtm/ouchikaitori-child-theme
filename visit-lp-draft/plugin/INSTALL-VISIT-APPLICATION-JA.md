# 出張買取申込バックエンド（ステージング専用）

## 構成

- `visit-application-backend.php` は仮申込、確認リンク、確認後登録の処理です。子テーマの `functions.php` から `WP_ENVIRONMENT_TYPE=staging` の場合だけ読み込まれます。
- `../migrations/001-visit-application.sql` は新規4テーブル、`../migrations/002-visit-slot-locks.sql` は日単位の同時申込ロックテーブルを作る手動マイグレーションです。プラグインからDDLを実行しません。
- 本番環境では `WP_ENVIRONMENT_TYPE=staging` でない限り、受付API・メール確認APIを有効にしません。

## 有効化前の確認

1. ステージングDBへ接続し、`SHOW CREATE TABLE purchase_requests` を実行して現状を確認します。
2. `public_token` が `CHAR(64)` のUNIQUE KEYであり、テーブルがInnoDBであることを確認します。
3. `shipping_choice` に `visit` が含まれることを確認します。添付されたDDLは `pickup/self` のみです。`visit` がなければ、このプラグインは登録を拒否します。既存テーブルの変更SQLは含めていません。
4. 親表と同じ文字コード・照合順序であることを確認した後、未適用のマイグレーションだけをステージングDBへ適用します。4テーブルがすでにある場合は `001` を再実行せず、`002` のロックテーブルだけを適用してください。
5. `OTP_DB_HOST`、`OTP_DB_NAME`、`OTP_DB_USER`、`OTP_DB_PASS`、`OTP_DB_CHARSET` がWordPressの設定で定義されていることを確認します。出張買取は専用PDO接続を作り、`OTP_DB_NAME` が `contact_db` と完全一致する場合だけ接続します。既存の宅配買取接続関数やその処理は変更しません。
6. WordPress管理画面の「設定 > 出張買取の予約枠上限」で、全エリア共通の各時間帯の受付上限と受付曜日を設定します。どちらかが未設定の場合は空き枠とAPIを無効にします。
7. APIは `WP_ENVIRONMENT_TYPE=staging` に加え、`VISIT_APPLICATION_API_ENABLED=1` と `VISIT_APPLICATION_EMAIL_ENABLED=1` が明示された場合だけ有効です。いずれも未設定時は無効です。固定テスト宛先と暗号化キーをWordPress/PHPプロセスに設定し、必要なテーブルの確認が通った後に限り受付画面が接続されます。これらの有効化設定は、明示的な承認なしに追加しないでください。WordPress管理画面から別途プラグインを有効化する必要はありません。

必要な環境変数（値はGitやチャットへ登録しないでください）：

- `WP_ENVIRONMENT_TYPE=staging`
- `VISIT_APPLICATION_API_ENABLED=1`（明示的な承認後のみ）
- `VISIT_APPLICATION_EMAIL_ENABLED=1`（明示的な承認後のみ）
- `VISIT_CONFIRMATION_TEST_RECIPIENT`（固定のテスト受信先。環境変数、またはステージングの `wp-config.php` に定数として設定）
- `PAYOUT_ENC_KEY_B64`（既存の32バイトAES鍵をBase64化した値。PHP環境変数または `wp-config.php` の定数）

受付上限・受付曜日は、WordPress管理画面の設定として保存します。コードや環境変数には秘密情報を追加しません。

DB接続専用の設定は追加せず、既存の `OTP_DB_*` 定数を使用します。接続先DB名が `contact_db` でない場合や定数が不足する場合は接続しません。

`VISIT_CONFIRMATION_TEST_RECIPIENT` が申込フォームに入力されたメールアドレスと同じ場合は、メール送信を拒否します。申込者へメールは送信しません。
送信先のメールアドレスはリポジトリへ書かず、Lightsail側のPHP環境変数またはステージング用 `wp-config.php` に設定してください。

## データの流れ

1. 仮申込時に入力情報全体をAES-256-GCMで暗号化し、確認トークンと状態確認トークンのハッシュを `visit_application_drafts` に保存します。
2. 確認メールは固定テスト受信先だけへ送信します。有効期限は5時間です。
3. メールリンクをGETしただけでは登録しません。表示された確認ボタンのPOSTで、確認トークンを行ロックし、`purchase_requests`、出張詳細、希望時間帯、非対面同意を1トランザクションで登録します。
4. 受付番号は `VIS-YYYYMMDD-連番`、`shipping_choice` と `shipping_method` は `visit`、支払方法は既存値の `銀行振込` です。銀行情報は既存形式のAES-256-GCMで暗号化します。
5. 希望時間帯は `visit_pickup_time_slots` へ個別行で保存します。`pickup_time` には保存しません。1枠選択時はメール確認後にその日時を `visit_purchase_details.confirmed_start_at` / `confirmed_end_at` に保存して確保し、複数枠は希望時間帯だけを保存します。
6. 確認後、状態確認用の一時トークンを含む暗号化ペイロードはランダム値で上書きします。期限切れドラフトは定期処理で削除します。

## 制限

- 空き枠APIは、設定済み時間帯と `visit_purchase_details.confirmed_start_at` / `confirmed_end_at` の確保済み件数から空きを返します。単一枠の申込は、メール確認時に日付単位のInnoDBロックを取得して上限を再確認し、同じトランザクション内で1枠を確保します。複数枠は空き照会と希望記録のみで、予約枠を確保しません。
- 会員登録要求は `register_flg` に保存しますが、WordPressユーザーや `customers` テーブルの作成・更新はしません。
- キャンペーンコードは保存しますが、既存のクーポン判定・割引処理は実行しません。
- 本人確認、査定、振込、回収スタッフ管理、回収記録・写真保存は実行しません。
- 運用開始前に、マイグレーション `002` の適用、管理画面での上限設定、固定テスト宛先での許可済み統合テストが必要です。外部メール送信とAPIは有効化フラグがない限り停止します。

## 今回の統合作業の状態

コードとSQLを子テーマへ統合した段階です。ステージングDBへ接続していないため、DDL適用、確認メール送信、DB書込テストは行っていません。PHP構文検証とステージング統合テストを終えるまで本番利用しないでください。本番DBには適用しないでください。
