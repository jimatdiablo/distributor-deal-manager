-- Make deal commission fields optional so deals can be created with estimated revenue only.

USE distdb;

ALTER TABLE deals
  MODIFY COLUMN commission_rate DECIMAL(6,3) NULL DEFAULT NULL,
  MODIFY COLUMN commission_amount DECIMAL(12,2) NULL DEFAULT NULL;
