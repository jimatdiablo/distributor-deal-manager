-- Rename legacy street columns to address.
-- Run this after backing up distdb.

USE distdb;

SET @sql = (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'distributors'
        AND COLUMN_NAME = 'street'
    ),
    'ALTER TABLE distributors CHANGE COLUMN street address VARCHAR(120) DEFAULT NULL',
    'SELECT "distributors.street not found; skipping"'
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
        AND COLUMN_NAME = 'street'
    ),
    'ALTER TABLE agent CHANGE COLUMN street address VARCHAR(120) DEFAULT NULL',
    'SELECT "agent.street not found; skipping"'
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
        AND COLUMN_NAME = 'street'
    ),
    'ALTER TABLE providers CHANGE COLUMN street address VARCHAR(120) DEFAULT NULL',
    'SELECT "providers.street not found; skipping"'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
