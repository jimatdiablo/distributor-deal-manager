ALTER TABLE distributors
  ADD COLUMN contract_term_years TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER segment;

UPDATE distributors
SET contract_term_years = 1
WHERE contract_term_years IS NULL OR contract_term_years NOT IN (1, 2, 3);

ALTER TABLE distributors
  ADD CONSTRAINT chk_distributors_contract_term
  CHECK (contract_term_years IN (1, 2, 3));
