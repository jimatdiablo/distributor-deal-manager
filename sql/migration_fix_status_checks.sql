-- Ensure status check constraints support Active/Inactive/Pending style values.
-- Mapping for core tables: 0, 1, 2
-- Providers also uses 0, 1, 2 (Reserved/Protected/Open in UI)

USE distdb;

ALTER TABLE distributors DROP CHECK chk_distributors_status;
ALTER TABLE distributors ADD CONSTRAINT chk_distributors_status CHECK (status IN (0, 1, 2));

ALTER TABLE agent DROP CHECK chk_agent_status;
ALTER TABLE agent ADD CONSTRAINT chk_agent_status CHECK (status IN (0, 1, 2));

ALTER TABLE deals DROP CHECK chk_deals_status;
ALTER TABLE deals ADD CONSTRAINT chk_deals_status CHECK (status IN (0, 1, 2));

ALTER TABLE providers DROP CHECK chk_providers_status;
ALTER TABLE providers ADD CONSTRAINT chk_providers_status CHECK (status IN (0, 1, 2));
