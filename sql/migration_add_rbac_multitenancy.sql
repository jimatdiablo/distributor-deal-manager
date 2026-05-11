-- RBAC + multi-tenancy migration for existing distdb instances
-- Safe to run once per environment.

USE distdb;

ALTER TABLE distributors
  ADD COLUMN IF NOT EXISTS internal_only TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER contract_end_date;

ALTER TABLE distributors
  ADD INDEX IF NOT EXISTS idx_distributors_internal_only (internal_only);

ALTER TABLE distributors
  ADD CONSTRAINT chk_distributors_internal_only CHECK (internal_only IN (0, 1));

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) DEFAULT NULL,
  role VARCHAR(40) NOT NULL,
  distributor_id INT UNSIGNED DEFAULT NULL,
  status TINYINT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_distributor (distributor_id),
  KEY idx_users_status (status),
  CONSTRAINT fk_users_distributor
    FOREIGN KEY (distributor_id) REFERENCES distributors(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_users_role CHECK (role IN ('internal_admin', 'internal_read_only', 'agent_viewer')),
  CONSTRAINT chk_users_status CHECK (status IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mark the Diablo Data distributor as internal-only.
UPDATE distributors
SET internal_only = 1
WHERE LOWER(TRIM(name)) = 'diablo data';
