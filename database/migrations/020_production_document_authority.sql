-- Production document (PDF) authority (API 2.20.0).
-- Additive only. No document, revision, print, QR, order, stage or grant row is created, changed or
-- deleted. Existing revisions and prints keep their employee attribution and satisfy the new checks.
--
-- A signed commerce source (YD SOFT) can now trigger revision 1 of an Arasya-managed order and record a
-- print of the active revision through the signed source contract, when its document authority is
-- `enforce`. Such rows are attributed to the source (its key and the operator name the source reports)
-- instead of an Arasya employee: exactly one of the two attributions is present on every row.
ALTER TABLE production_document_revisions
    MODIFY generated_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN generated_by_source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER generated_by_employee_uuid,
    ADD COLUMN generated_by_source_actor VARCHAR(120) NULL AFTER generated_by_source_key,
    ADD CONSTRAINT chk_production_document_revisions_generator CHECK
        ((generated_by_employee_uuid IS NOT NULL AND generated_by_source_key IS NULL AND generated_by_source_actor IS NULL)
         OR (generated_by_employee_uuid IS NULL AND generated_by_source_key IS NOT NULL AND generated_by_source_actor IS NOT NULL));

ALTER TABLE production_document_prints
    MODIFY printed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN printed_by_source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER printed_by_employee_uuid,
    ADD COLUMN printed_by_source_actor VARCHAR(120) NULL AFTER printed_by_source_key,
    ADD CONSTRAINT chk_production_document_prints_printer CHECK
        ((printed_by_employee_uuid IS NOT NULL AND printed_by_source_key IS NULL AND printed_by_source_actor IS NULL)
         OR (printed_by_employee_uuid IS NULL AND printed_by_source_key IS NOT NULL AND printed_by_source_actor IS NOT NULL));
