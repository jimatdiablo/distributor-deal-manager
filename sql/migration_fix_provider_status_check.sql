-- Ensure providers status constraint supports Reserved/Protected/Open.
-- Mapping: 0=Reserved, 1=Protected, 2=Open

USE distdb;

ALTER TABLE providers DROP CHECK chk_providers_status;
ALTER TABLE providers ADD CONSTRAINT chk_providers_status CHECK (status IN (0, 1, 2));
