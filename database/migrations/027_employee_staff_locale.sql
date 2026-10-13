-- Staff interface language of one identity (API 2.30.0, Staff 2.25.0).
-- Additive only: one nullable column, no row is rewritten. NULL keeps the default language, Romanian, so every
-- existing identity keeps working exactly as before. The value is presentation only: it grants nothing, it is not
-- part of the authorization version and it never changes sessions, roles, stages, passwords or account status.
-- API 2.29.x ignores the column, so this migration may run before the 2.30.0 code is activated.
ALTER TABLE employees
    ADD COLUMN staff_locale VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER position_title,
    ADD CONSTRAINT chk_employees_staff_locale CHECK (staff_locale IS NULL OR staff_locale IN ('ro', 'tr', 'en', 'si'));
