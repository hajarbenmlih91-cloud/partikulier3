-- Partikulier Core migration 001: initial schema.
-- Runtime execution is performed by Database\Migrator using dbDelta.
-- All names are prefixed at runtime with $wpdb->prefix.

CREATE TABLE {prefix}pk_listings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_user_id bigint(20) unsigned NOT NULL,
  external_id varchar(64) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  locale varchar(12) NOT NULL DEFAULT 'fr',
  title text NOT NULL,
  description longtext NOT NULL,
  price decimal(14,2) unsigned NOT NULL DEFAULT 0,
  area decimal(12,2) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY external_id (external_id),
  KEY status_locale (status, locale),
  KEY status_locale_created (status, locale, created_at, id),
  KEY status_locale_price (status, locale, price, id),
  KEY status_locale_area (status, locale, area, id),
  KEY owner_status (owner_user_id, status),
  KEY search_order (status, price, area)
);

CREATE TABLE {prefix}pk_audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  correlation_id char(36) NOT NULL,
  actor_user_id bigint(20) unsigned NULL,
  action varchar(80) NOT NULL,
  object_type varchar(80) NOT NULL,
  object_id bigint(20) unsigned NULL,
  metadata_json longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY correlation (correlation_id),
  KEY object_lookup (object_type, object_id),
  KEY created_at (created_at)
);

CREATE TABLE {prefix}pk_idempotency (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id varchar(128) NOT NULL,
  event_hash char(64) NOT NULL,
  response_json longtext NOT NULL,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY event_id (event_id),
  KEY expires_at (expires_at)
);
