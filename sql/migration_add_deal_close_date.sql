-- Add close_date to deals for closed-stage tracking.

USE distdb;

ALTER TABLE deals
  ADD COLUMN close_date DATE NULL AFTER deal_date;
