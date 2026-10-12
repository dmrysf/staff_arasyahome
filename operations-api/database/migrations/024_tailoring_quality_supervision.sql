-- Tailoring quality supervision: defects found at stages 3-12 go to the appointed tailoring quality
-- supervisor first; only the final workshop-receiving -> material-preparation return needs an operations
-- manager decision.
-- Additive only. No existing exception, decision, quality fact, activity event, reason label or grant is
-- rewritten. Constraints are widened, never narrowed; new columns are NULL for every existing row.

-- ---------------------------------------------------------------- scoped responsibility

-- The supervisor is an explicit, audited appointment of one employee UUID (never a role, department or
-- username). One active appointment at a time; a new appointment ends the previous one.
ALTER TABLE responsibility_assignments DROP CONSTRAINT chk_responsibility_assignments_key;
ALTER TABLE responsibility_assignments ADD CONSTRAINT chk_responsibility_assignments_key
    CHECK (responsibility_key IN ('tailoring_intake_responsible', 'operations_backup_approver', 'document_revision_backup_approver', 'tailoring_quality_supervisor'));

-- ---------------------------------------------------------------- fault reasons

-- Whether a reason can ever be attributed to the cutting employee. A material defect needs new cutting
-- without proving a cutting mistake, so a manager can never attribute it to the cutter.
ALTER TABLE production_fault_reasons
    ADD COLUMN cutting_attributable TINYINT(1) NOT NULL DEFAULT 1 AFTER requires_comment,
    ADD CONSTRAINT chk_production_fault_reasons_attributable CHECK (cutting_attributable IN (0, 1));

UPDATE production_fault_reasons SET cutting_attributable = 0 WHERE reason_key = 'material-defect';

-- The three reasons the tailoring supervisor confirms. Existing reasons stay as they are; requests keep
-- their own label snapshot, so no historical label changes.
INSERT IGNORE INTO production_fault_reasons (reason_key, label, requires_comment, cutting_attributable, status, sort_order, created_at, updated_at) VALUES
    ('cut-meterage', 'Metraj tăiat greșit', 0, 1, 'active', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('cut-color', 'Culoare tăiată greșit', 0, 1, 'active', 2, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('fabric-defect', 'Defect al materialului', 0, 0, 'active', 3, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

-- ---------------------------------------------------------------- tailoring defect requests

-- Separate actors, never merged: detector (reported), detected owner (owned the order when it was
-- reported), supervisor (reviewed and holds the case), receiver (cutting employee who physically took
-- the product by scanning its QR), physical custodian (who holds the product now), responsible (cutting
-- employee of the last 2 -> 3 handoff, derived by the server). Attribution is the manager's decision on
-- whether cutting responsibility was established.
ALTER TABLE production_exceptions
    ADD COLUMN detected_owner_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER detector_employee_uuid,
    ADD COLUMN supervisor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER responsible_activity_event_id,
    ADD COLUMN supervisor_reviewed_at DATETIME(6) NULL AFTER supervisor_employee_uuid,
    ADD COLUMN supervisor_comment VARCHAR(1000) NULL AFTER supervisor_reviewed_at,
    ADD COLUMN confirmed_reason_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER reason_label_snapshot,
    ADD COLUMN confirmed_reason_label_snapshot VARCHAR(160) NULL AFTER confirmed_reason_key,
    ADD COLUMN return_requested_at DATETIME(6) NULL AFTER supervisor_comment,
    ADD COLUMN receiver_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER return_requested_at,
    ADD COLUMN physical_custodian_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER receiver_employee_uuid,
    ADD COLUMN responsibility_position VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER acknowledgment_comment,
    ADD COLUMN attribution VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER rework_cycle,
    ADD KEY idx_production_exceptions_supervisor (supervisor_employee_uuid, status),
    ADD KEY idx_production_exceptions_receiver (receiver_employee_uuid, status),
    ADD KEY idx_production_exceptions_type_status (exception_type, status, updated_at),
    ADD CONSTRAINT fk_production_exceptions_detected_owner FOREIGN KEY (detected_owner_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_production_exceptions_supervisor FOREIGN KEY (supervisor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_production_exceptions_receiver FOREIGN KEY (receiver_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_production_exceptions_custodian FOREIGN KEY (physical_custodian_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_production_exceptions_confirmed_reason FOREIGN KEY (confirmed_reason_key) REFERENCES production_fault_reasons (reason_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT chk_production_exceptions_position CHECK (responsibility_position IS NULL OR responsibility_position IN ('accepted', 'disputed', 'not_stated')),
    ADD CONSTRAINT chk_production_exceptions_attribution CHECK (attribution IS NULL OR attribution IN ('cutter', 'none')),
    ADD CONSTRAINT chk_production_exceptions_supervisor_people CHECK (supervisor_employee_uuid IS NULL OR supervisor_employee_uuid <> detector_employee_uuid),
    ADD CONSTRAINT chk_production_exceptions_receiver_people CHECK (receiver_employee_uuid IS NULL OR (receiver_employee_uuid <> detector_employee_uuid AND (supervisor_employee_uuid IS NULL OR receiver_employee_uuid <> supervisor_employee_uuid))),
    ADD CONSTRAINT chk_production_exceptions_tailoring CHECK (exception_type <> 'tailoring_defect' OR detected_owner_employee_uuid IS NOT NULL);

ALTER TABLE production_exceptions DROP CONSTRAINT chk_production_exceptions_type;
ALTER TABLE production_exceptions ADD CONSTRAINT chk_production_exceptions_type CHECK (exception_type IN ('cutting_fault', 'tailoring_defect'));

ALTER TABLE production_exceptions DROP CONSTRAINT chk_production_exceptions_status;
ALTER TABLE production_exceptions ADD CONSTRAINT chk_production_exceptions_status CHECK (status IN (
    'awaiting_acknowledgment', 'awaiting_approval', 'approved', 'rejected', 'cancelled',
    'awaiting_supervisor_review', 'dismissed', 'in_supervisor_custody', 'return_rejected', 'restored'));

ALTER TABLE production_exception_events DROP CONSTRAINT chk_production_exception_events_action;
ALTER TABLE production_exception_events ADD CONSTRAINT chk_production_exception_events_action CHECK (action IN (
    'reported', 'acknowledged', 'approval_requested', 'approved', 'rejected', 'rereview_requested', 'cancelled',
    'supervisor_accepted', 'supervisor_dismissed', 'return_requested', 'restored', 'note_added'));

-- A detection verified by the supervisor is credited to the detector when it is verified, before any
-- rework cycle exists. Existing rows keep their cycle; the CHECK still requires a positive cycle when set.
ALTER TABLE production_quality_events MODIFY rework_cycle INT UNSIGNED NULL;

-- A line whose meters the source never sent (for example a Trendyol line prepared by width and height only) can
-- still be reported in the supervised flow: its meters are recorded as 0.000, meaning "not measured", while the
-- line itself is counted. The intake-only flow keeps refusing such lines. At least one line is still required.
ALTER TABLE production_exceptions DROP CONSTRAINT chk_production_exceptions_meters;
ALTER TABLE production_exceptions ADD CONSTRAINT chk_production_exceptions_meters CHECK (fault_meters >= 0);
ALTER TABLE production_exception_lines DROP CONSTRAINT chk_production_exception_lines_meters;
ALTER TABLE production_exception_lines ADD CONSTRAINT chk_production_exception_lines_meters CHECK (meters_snapshot >= 0);
ALTER TABLE production_quality_events DROP CONSTRAINT chk_production_quality_events_meters;
ALTER TABLE production_quality_events ADD CONSTRAINT chk_production_quality_events_meters CHECK (meters >= 0 AND line_count > 0 AND (rework_cycle IS NULL OR rework_cycle > 0));

-- ---------------------------------------------------------------- immutable production activity

-- Internal tailoring moves are their own actions, never an ordinary stage completion.
ALTER TABLE order_activity_events DROP CONSTRAINT chk_order_activity_action;
ALTER TABLE order_activity_events
    ADD CONSTRAINT chk_order_activity_action CHECK (action IN ('claimed', 'stage_completed', 'production_completed', 'owner_released', 'owner_reassigned', 'production_submitted',
        'fault_reported', 'fault_rejected', 'fault_rereview_requested', 'fault_returned', 'fault_cancelled', 'authority_taken_over', 'authority_released',
        'defect_custody_taken', 'defect_dismissed', 'defect_restored'));
