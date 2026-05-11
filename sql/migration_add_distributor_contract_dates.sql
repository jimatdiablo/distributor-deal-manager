ALTER TABLE distributors
  ADD COLUMN contract_start_date DATE DEFAULT NULL AFTER contract_term_years,
  ADD COLUMN contract_end_date DATE DEFAULT NULL AFTER contract_start_date;

UPDATE distributors
SET contract_start_date = COALESCE(contract_start_date, CURRENT_DATE())
WHERE contract_start_date IS NULL;

UPDATE distributors
SET contract_end_date = COALESCE(
    contract_end_date,
    DATE_ADD(contract_start_date, INTERVAL contract_term_years YEAR)
)
WHERE contract_end_date IS NULL;
