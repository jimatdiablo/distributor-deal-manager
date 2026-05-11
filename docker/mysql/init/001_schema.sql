-- Local dev schema aligned with production import

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS distdb
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE distdb;

DROP TABLE IF EXISTS deals;
DROP TABLE IF EXISTS providers;
DROP TABLE IF EXISTS agent;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS distributors;

CREATE TABLE distributors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  address VARCHAR(120) DEFAULT NULL,
  city VARCHAR(80) DEFAULT NULL,
  state VARCHAR(10) DEFAULT NULL,
  country_code VARCHAR(2) NOT NULL DEFAULT 'US',
  postal_code VARCHAR(20) DEFAULT NULL,
  phone VARCHAR(25) DEFAULT NULL,
  phone_alt VARCHAR(25) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  segment VARCHAR(30) DEFAULT NULL,
  contract_term_years TINYINT UNSIGNED NOT NULL DEFAULT 1,
  contract_start_date DATE DEFAULT NULL,
  contract_end_date DATE DEFAULT NULL,
  internal_only TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_distributors_name (name),
  KEY idx_distributors_internal_only (internal_only),
  KEY idx_distributors_status (status),
  CONSTRAINT chk_distributors_internal_only CHECK (internal_only IN (0, 1)),
  CONSTRAINT chk_distributors_status CHECK (status IN (0, 1, 2)),
  CONSTRAINT chk_distributors_contract_term CHECK (contract_term_years IN (1, 2, 3))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
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

CREATE TABLE agent (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  distributor_id INT UNSIGNED DEFAULT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  address VARCHAR(120) DEFAULT NULL,
  city VARCHAR(80) DEFAULT NULL,
  state VARCHAR(10) DEFAULT NULL,
  country_code VARCHAR(2) NOT NULL DEFAULT 'US',
  postal_code VARCHAR(20) DEFAULT NULL,
  phone VARCHAR(25) DEFAULT NULL,
  phone_alt VARCHAR(25) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  status TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_agent_distributor (distributor_id),
  KEY idx_agent_name (last_name, first_name),
  KEY idx_agent_status (status),
  CONSTRAINT fk_agent_distributor
    FOREIGN KEY (distributor_id) REFERENCES distributors(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_agent_status CHECK (status IN (0, 1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE providers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  address VARCHAR(120) DEFAULT NULL,
  city VARCHAR(80) DEFAULT NULL,
  state VARCHAR(10) DEFAULT NULL,
  country_code VARCHAR(2) NOT NULL DEFAULT 'US',
  postal_code VARCHAR(20) DEFAULT NULL,
  phone VARCHAR(25) DEFAULT NULL,
  phone_alt VARCHAR(25) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  segment VARCHAR(30) DEFAULT NULL,
  distributor_id INT UNSIGNED DEFAULT NULL,
  account_manager_agent_id INT UNSIGNED DEFAULT NULL,
  status TINYINT UNSIGNED NOT NULL DEFAULT 1,
  point_of_contact_name VARCHAR(120) DEFAULT NULL,
  point_of_contact_phone VARCHAR(25) DEFAULT NULL,
  point_of_contact_email VARCHAR(190) DEFAULT NULL,
  customer_name VARCHAR(120) DEFAULT NULL,
  start_date DATE DEFAULT NULL,
  end_date DATE DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_providers_name (name),
  KEY idx_providers_distributor (distributor_id),
  KEY idx_providers_agent (account_manager_agent_id),
  KEY idx_providers_status (status),
  CONSTRAINT fk_providers_distributor
    FOREIGN KEY (distributor_id) REFERENCES distributors(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_providers_agent
    FOREIGN KEY (account_manager_agent_id) REFERENCES agent(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_providers_status CHECK (status IN (0, 1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  deal_name VARCHAR(150) NOT NULL,
  agent_id INT UNSIGNED DEFAULT NULL,
  distributor_id INT UNSIGNED DEFAULT NULL,
  provider_id INT UNSIGNED DEFAULT NULL,
  description TEXT,
  deal_date DATE DEFAULT NULL,
  close_date DATE DEFAULT NULL,
  revenue DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  commission_rate DECIMAL(6,3) DEFAULT NULL,
  commission_amount DECIMAL(12,2) DEFAULT NULL,
  details TEXT,
  stage VARCHAR(40) NOT NULL DEFAULT 'new',
  status TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_deals_agent (agent_id),
  KEY idx_deals_distributor (distributor_id),
  KEY idx_deals_provider (provider_id),
  KEY idx_deals_date (deal_date),
  KEY idx_deals_stage (stage),
  KEY idx_deals_status (status),
  CONSTRAINT fk_deals_agent
    FOREIGN KEY (agent_id) REFERENCES agent(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_deals_distributor
    FOREIGN KEY (distributor_id) REFERENCES distributors(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_deals_provider
    FOREIGN KEY (provider_id) REFERENCES providers(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_deals_status CHECK (status IN (0, 1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
