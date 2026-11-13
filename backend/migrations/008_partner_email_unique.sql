-- SP-5 hardening: one partner per email, at most one open (pending/approved) application per email.
-- Additive: two STORED generated columns + two UNIQUE keys. No existing value is rewritten.
-- Keys compare LOWER(TRIM(gmail)) under utf8mb4_bin: exact after normalising case and outer spaces,
-- not accent-folded the way the tables' default utf8mb4_0900_ai_ci collation would.
-- Phone / WhatsApp / UPI are deliberately NOT unique (family members and institutions share them).
-- Rejected applications get open_gmail NULL, so a rejected email can apply again.
--
-- PRE-CHECK (must return no rows, otherwise this migration fails with "Duplicate entry"):
--   SELECT LOWER(TRIM(gmail)) AS g, COUNT(*) AS n, GROUP_CONCAT(id) FROM referral_partners GROUP BY g HAVING n > 1;
--   SELECT LOWER(TRIM(gmail)) AS g, COUNT(*) AS n, GROUP_CONCAT(id) FROM referral_applications
--     WHERE application_status IN ('pending','approved') GROUP BY g HAVING n > 1;
-- CLEAN-UP if rows come back (decide per case with the business; never delete a partner that has commissions):
--   duplicate partners: change the extra partner's gmail (e.g. append "+dup<id>" before the @) or merge by hand;
--   duplicate open applications: reject the newer pending one(s):
--     UPDATE referral_applications SET application_status = 'rejected', reviewed_at = NOW() WHERE id IN (<newer ids>);
ALTER TABLE referral_partners
  ADD COLUMN gmail_key VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
    GENERATED ALWAYS AS (LOWER(TRIM(gmail))) STORED,
  ADD UNIQUE KEY uq_partner_gmail (gmail_key);

ALTER TABLE referral_applications
  ADD COLUMN open_gmail VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
    GENERATED ALWAYS AS (IF(application_status IN ('pending', 'approved'), LOWER(TRIM(gmail)), NULL)) STORED,
  ADD UNIQUE KEY uq_open_application (open_gmail);
