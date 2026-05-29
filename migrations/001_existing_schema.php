<?php

declare(strict_types=1);

use App\Core\MigrationRunner;

return static function (MigrationRunner $m): void {
    $m->exec("CREATE TABLE IF NOT EXISTS distributors (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $m->renameColumnIfPresent('distributors', 'street', 'address', 'address VARCHAR(120) DEFAULT NULL');
    $m->addColumnIfMissing('distributors', 'address', 'address VARCHAR(120) DEFAULT NULL AFTER name');
    $m->addColumnIfMissing('distributors', 'country_code', "country_code VARCHAR(2) NOT NULL DEFAULT 'US' AFTER state");
    $m->addColumnIfMissing('distributors', 'contract_term_years', 'contract_term_years TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER segment');
    $m->addColumnIfMissing('distributors', 'contract_start_date', 'contract_start_date DATE DEFAULT NULL AFTER contract_term_years');
    $m->addColumnIfMissing('distributors', 'contract_end_date', 'contract_end_date DATE DEFAULT NULL AFTER contract_start_date');
    $m->addColumnIfMissing('distributors', 'internal_only', 'internal_only TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER contract_end_date');
    $m->addColumnIfMissing('distributors', 'created_at', 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    $m->addColumnIfMissing('distributors', 'updated_at', 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    $m->addIndexIfMissing('distributors', 'idx_distributors_name', 'INDEX idx_distributors_name (name)');
    $m->addIndexIfMissing('distributors', 'idx_distributors_internal_only', 'INDEX idx_distributors_internal_only (internal_only)');
    $m->addIndexIfMissing('distributors', 'idx_distributors_status', 'INDEX idx_distributors_status (status)');
    $m->exec("UPDATE distributors SET contract_term_years = 1 WHERE contract_term_years IS NULL OR contract_term_years NOT IN (1, 2, 3)");
    $m->exec("UPDATE distributors SET contract_start_date = COALESCE(contract_start_date, CURRENT_DATE()) WHERE contract_start_date IS NULL");
    $m->exec("UPDATE distributors SET contract_end_date = COALESCE(contract_end_date, DATE_ADD(contract_start_date, INTERVAL contract_term_years YEAR)) WHERE contract_end_date IS NULL");

    $m->exec("CREATE TABLE IF NOT EXISTS users (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $m->addColumnIfMissing('users', 'display_name', 'display_name VARCHAR(120) DEFAULT NULL AFTER password_hash');
    $m->addColumnIfMissing('users', 'distributor_id', 'distributor_id INT UNSIGNED DEFAULT NULL AFTER role');
    $m->addColumnIfMissing('users', 'status', 'status TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER distributor_id');
    $m->addColumnIfMissing('users', 'last_login_at', 'last_login_at TIMESTAMP NULL DEFAULT NULL AFTER status');
    $m->addColumnIfMissing('users', 'created_at', 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    $m->addColumnIfMissing('users', 'updated_at', 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    $m->addIndexIfMissing('users', 'idx_users_role', 'INDEX idx_users_role (role)');
    $m->addIndexIfMissing('users', 'idx_users_distributor', 'INDEX idx_users_distributor (distributor_id)');
    $m->addIndexIfMissing('users', 'idx_users_status', 'INDEX idx_users_status (status)');

    $m->exec("CREATE TABLE IF NOT EXISTS agent (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $m->renameColumnIfPresent('agent', 'street', 'address', 'address VARCHAR(120) DEFAULT NULL');
    $m->addColumnIfMissing('agent', 'address', 'address VARCHAR(120) DEFAULT NULL AFTER last_name');
    $m->addColumnIfMissing('agent', 'country_code', "country_code VARCHAR(2) NOT NULL DEFAULT 'US' AFTER state");
    $m->addColumnIfMissing('agent', 'created_at', 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    $m->addColumnIfMissing('agent', 'updated_at', 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    $m->addIndexIfMissing('agent', 'idx_agent_distributor', 'INDEX idx_agent_distributor (distributor_id)');
    $m->addIndexIfMissing('agent', 'idx_agent_name', 'INDEX idx_agent_name (last_name, first_name)');
    $m->addIndexIfMissing('agent', 'idx_agent_status', 'INDEX idx_agent_status (status)');

    $m->exec("CREATE TABLE IF NOT EXISTS providers (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $m->renameColumnIfPresent('providers', 'street', 'address', 'address VARCHAR(120) DEFAULT NULL');
    $m->addColumnIfMissing('providers', 'address', 'address VARCHAR(120) DEFAULT NULL AFTER name');
    $m->addColumnIfMissing('providers', 'country_code', "country_code VARCHAR(2) NOT NULL DEFAULT 'US' AFTER state");
    $m->addColumnIfMissing('providers', 'point_of_contact_name', 'point_of_contact_name VARCHAR(120) DEFAULT NULL AFTER status');
    $m->addColumnIfMissing('providers', 'point_of_contact_phone', 'point_of_contact_phone VARCHAR(25) DEFAULT NULL AFTER point_of_contact_name');
    $m->addColumnIfMissing('providers', 'point_of_contact_email', 'point_of_contact_email VARCHAR(190) DEFAULT NULL AFTER point_of_contact_phone');
    $m->addColumnIfMissing('providers', 'customer_name', 'customer_name VARCHAR(120) DEFAULT NULL AFTER point_of_contact_email');
    $m->addColumnIfMissing('providers', 'start_date', 'start_date DATE DEFAULT NULL AFTER customer_name');
    $m->addColumnIfMissing('providers', 'end_date', 'end_date DATE DEFAULT NULL AFTER start_date');
    $m->addColumnIfMissing('providers', 'created_at', 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    $m->addColumnIfMissing('providers', 'updated_at', 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    $m->addIndexIfMissing('providers', 'idx_providers_name', 'INDEX idx_providers_name (name)');
    $m->addIndexIfMissing('providers', 'idx_providers_distributor', 'INDEX idx_providers_distributor (distributor_id)');
    $m->addIndexIfMissing('providers', 'idx_providers_agent', 'INDEX idx_providers_agent (account_manager_agent_id)');
    $m->addIndexIfMissing('providers', 'idx_providers_status', 'INDEX idx_providers_status (status)');

    $m->exec("CREATE TABLE IF NOT EXISTS deals (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $m->addColumnIfMissing('deals', 'close_date', 'close_date DATE NULL AFTER deal_date');
    $m->exec('ALTER TABLE deals MODIFY COLUMN commission_rate DECIMAL(6,3) NULL DEFAULT NULL');
    $m->exec('ALTER TABLE deals MODIFY COLUMN commission_amount DECIMAL(12,2) NULL DEFAULT NULL');
    $m->addColumnIfMissing('deals', 'created_at', 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    $m->addColumnIfMissing('deals', 'updated_at', 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    $m->addIndexIfMissing('deals', 'idx_deals_agent', 'INDEX idx_deals_agent (agent_id)');
    $m->addIndexIfMissing('deals', 'idx_deals_distributor', 'INDEX idx_deals_distributor (distributor_id)');
    $m->addIndexIfMissing('deals', 'idx_deals_provider', 'INDEX idx_deals_provider (provider_id)');
    $m->addIndexIfMissing('deals', 'idx_deals_date', 'INDEX idx_deals_date (deal_date)');
    $m->addIndexIfMissing('deals', 'idx_deals_stage', 'INDEX idx_deals_stage (stage)');
    $m->addIndexIfMissing('deals', 'idx_deals_status', 'INDEX idx_deals_status (status)');

    foreach ([
        ['distributors', 'chk_distributors_internal_only', 'CHECK (internal_only IN (0, 1))'],
        ['distributors', 'chk_distributors_status', 'CHECK (status IN (0, 1, 2))'],
        ['distributors', 'chk_distributors_contract_term', 'CHECK (contract_term_years IN (1, 2, 3))'],
        ['users', 'chk_users_role', "CHECK (role IN ('internal_admin', 'internal_read_only', 'agent_viewer'))"],
        ['users', 'chk_users_status', 'CHECK (status IN (0, 1))'],
        ['agent', 'chk_agent_status', 'CHECK (status IN (0, 1, 2))'],
        ['providers', 'chk_providers_status', 'CHECK (status IN (0, 1, 2))'],
        ['deals', 'chk_deals_status', 'CHECK (status IN (0, 1, 2))'],
    ] as [$table, $constraint, $definition]) {
        $m->tryAddConstraintIfMissing($table, $constraint, $definition);
    }

    foreach ([
        ['users', 'fk_users_distributor', 'FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['agent', 'fk_agent_distributor', 'FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['providers', 'fk_providers_distributor', 'FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['providers', 'fk_providers_agent', 'FOREIGN KEY (account_manager_agent_id) REFERENCES agent(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['deals', 'fk_deals_agent', 'FOREIGN KEY (agent_id) REFERENCES agent(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['deals', 'fk_deals_distributor', 'FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['deals', 'fk_deals_provider', 'FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL ON UPDATE CASCADE'],
    ] as [$table, $constraint, $definition]) {
        $m->tryAddConstraintIfMissing($table, $constraint, $definition);
    }
};
