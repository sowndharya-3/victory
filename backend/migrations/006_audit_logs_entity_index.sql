-- SP-5 Task 19: admin order detail / audit-log screen look rows up by entity. Additive; one-shot like 001-005.
ALTER TABLE audit_logs ADD INDEX idx_audit_entity (entity_type, entity_id);
