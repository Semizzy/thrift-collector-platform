<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER reconciliation_entries_source_immutable_update
BEFORE UPDATE ON reconciliation_entries
FOR EACH ROW
WHEN OLD.reconciliation_batch_id IS NOT NEW.reconciliation_batch_id
  OR OLD.source_transaction_id IS NOT NEW.source_transaction_id
  OR OLD.external_reference IS NOT NEW.external_reference
  OR OLD.normalized_reference IS NOT NEW.normalized_reference
  OR OLD.amount_minor IS NOT NEW.amount_minor
  OR OLD.currency_code IS NOT NEW.currency_code
  OR OLD.occurred_at IS NOT NEW.occurred_at
  OR OLD.row_fingerprint IS NOT NEW.row_fingerprint
  OR OLD.source_data IS NOT NEW.source_data
  OR OLD.created_at IS NOT NEW.created_at
BEGIN
    SELECT RAISE(ABORT, 'Reconciliation source evidence is immutable');
END;
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER reconciliation_entries_no_delete
BEFORE DELETE ON reconciliation_entries
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'Reconciliation evidence cannot be deleted');
END;
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER reconciliation_decisions_no_update
BEFORE UPDATE ON reconciliation_decisions
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'Reconciliation decisions are append-only');
END;
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER reconciliation_decisions_no_delete
BEFORE DELETE ON reconciliation_decisions
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'Reconciliation decisions are append-only');
END;
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER reconciliation_batches_source_immutable_update
BEFORE UPDATE ON reconciliation_batches
FOR EACH ROW
WHEN OLD.business_id IS NOT NEW.business_id
  OR OLD.source IS NOT NEW.source
  OR OLD.source_reference IS NOT NEW.source_reference
  OR OLD.currency_code IS NOT NEW.currency_code
  OR OLD.created_by_user_id IS NOT NEW.created_by_user_id
  OR OLD.created_at IS NOT NEW.created_at
BEGIN
    SELECT RAISE(ABORT, 'Reconciliation batch source identity is immutable');
END;
SQL);
            return;
        }

        if ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER reconciliation_entries_source_immutable_update BEFORE UPDATE ON reconciliation_entries FOR EACH ROW BEGIN IF NOT (OLD.reconciliation_batch_id <=> NEW.reconciliation_batch_id) OR NOT (OLD.source_transaction_id <=> NEW.source_transaction_id) OR NOT (OLD.external_reference <=> NEW.external_reference) OR NOT (OLD.normalized_reference <=> NEW.normalized_reference) OR NOT (OLD.amount_minor <=> NEW.amount_minor) OR NOT (OLD.currency_code <=> NEW.currency_code) OR NOT (OLD.occurred_at <=> NEW.occurred_at) OR NOT (OLD.row_fingerprint <=> NEW.row_fingerprint) OR NOT (CAST(OLD.source_data AS CHAR) <=> CAST(NEW.source_data AS CHAR)) OR NOT (OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation source evidence is immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER reconciliation_entries_no_delete BEFORE DELETE ON reconciliation_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation evidence cannot be deleted'");
            DB::unprepared("CREATE TRIGGER reconciliation_decisions_no_update BEFORE UPDATE ON reconciliation_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation decisions are append-only'");
            DB::unprepared("CREATE TRIGGER reconciliation_decisions_no_delete BEFORE DELETE ON reconciliation_decisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation decisions are append-only'");
            DB::unprepared("CREATE TRIGGER reconciliation_batches_source_immutable_update BEFORE UPDATE ON reconciliation_batches FOR EACH ROW BEGIN IF NOT (OLD.business_id <=> NEW.business_id) OR NOT (OLD.source <=> NEW.source) OR NOT (OLD.source_reference <=> NEW.source_reference) OR NOT (OLD.currency_code <=> NEW.currency_code) OR NOT (OLD.created_by_user_id <=> NEW.created_by_user_id) OR NOT (OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation batch source identity is immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach ([
                'reconciliation_entries_source_immutable_update',
                'reconciliation_entries_no_delete',
                'reconciliation_decisions_no_update',
                'reconciliation_decisions_no_delete',
                'reconciliation_batches_source_immutable_update',
            ] as $trigger) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
            }
            return;
        }

        if ($driver === 'mysql') {
            foreach ([
                'reconciliation_entries_source_immutable_update',
                'reconciliation_entries_no_delete',
                'reconciliation_decisions_no_update',
                'reconciliation_decisions_no_delete',
                'reconciliation_batches_source_immutable_update',
            ] as $trigger) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }
    }
};
