-- Add country columns to an existing distdb deployment.
-- Run this on systems created before country_code was added to the schema.

USE distdb;

SET @sql = (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'distributors'
        AND COLUMN_NAME = 'country_code'
    ),
    'SELECT "distributors.country_code exists; skipping"',
    'ALTER TABLE distributors ADD COLUMN country_code VARCHAR(2) NOT NULL DEFAULT ''US'' AFTER state'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'agent'
        AND COLUMN_NAME = 'country_code'
    ),
    'SELECT "agent.country_code exists; skipping"',
    'ALTER TABLE agent ADD COLUMN country_code VARCHAR(2) NOT NULL DEFAULT ''US'' AFTER state'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'providers'
        AND COLUMN_NAME = 'country_code'
    ),
    'SELECT "providers.country_code exists; skipping"',
    'ALTER TABLE providers ADD COLUMN country_code VARCHAR(2) NOT NULL DEFAULT ''US'' AFTER state'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
