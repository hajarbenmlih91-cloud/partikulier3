-- Partikulier Core migration 002: audit correlation hardening.
-- This migration is intentionally idempotent; dbDelta also enforces the final shape.
-- The correlation_id index is required for incident and request tracing.
ALTER TABLE {prefix}pk_audit_log ADD INDEX correlation (correlation_id);
