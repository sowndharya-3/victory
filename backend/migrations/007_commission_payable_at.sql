-- SP-5 Task 20: when a commission was marked payable (paid_at/reversed_at came with 004). Additive; one-shot like 001-006.
ALTER TABLE commissions ADD COLUMN payable_at DATETIME NULL AFTER status;
