BEGIN TRANSACTION;
ALTER TABLE general ADD COLUMN secret text;
COMMIT;