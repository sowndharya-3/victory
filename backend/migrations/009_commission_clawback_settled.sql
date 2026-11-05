-- SP-5 finalize: close a commission clawback once the money is recovered from the partner. Additive; one-shot like 001-008.
-- clawback_due stays 1 as history; clawback_settled_at/clawback_reference record the settlement. No data pre-check needed.
ALTER TABLE commissions
  ADD COLUMN clawback_settled_at DATETIME NULL AFTER clawback_due,
  ADD COLUMN clawback_reference VARCHAR(100) NULL AFTER clawback_settled_at;
