CREATE OR REPLACE VIEW v_flat_1cr AS
SELECT *
FROM read_parquet(
  '/Users/lumegalabs/Projects/php-parquet-duckdb/data/dummy_1cr/contact/**/*.parquet',
  hive_partitioning=true,
  union_by_name=true
);

CREATE OR REPLACE VIEW v_base_delta_45 AS
SELECT
  b.id, b.account_id, b.primary_key, b.email, b.mobile,
  COALESCE(d.email_status, b.email_status) AS email_status,
  COALESCE(d.sms_status, b.sms_status) AS sms_status,
  COALESCE(d.is_deleted, b.is_deleted) AS is_deleted,
  COALESCE(d.is_contact, b.is_contact) AS is_contact,
  b.is_preview,
  COALESCE(d.last_emailed, b.last_emailed) AS last_emailed,
  COALESCE(d.last_sms, b.last_sms) AS last_sms,
  COALESCE(d.email_suppressed_on, b.email_suppressed_on) AS email_suppressed_on,
  COALESCE(d.sms_suppressed_on, b.sms_suppressed_on) AS sms_suppressed_on,
  COALESCE(d.email_bounce_count, b.email_bounce_count) AS email_bounce_count,
  COALESCE(d.last_open, b.last_open) AS last_open,
  COALESCE(d.last_click, b.last_click) AS last_click,
  b.f2, b.f6, b.f7, b.f18, b.f30, b.f31
FROM read_parquet(
  '/Users/lumegalabs/Projects/php-parquet-duckdb/data/dummy_base_delta/contact/base/**/*.parquet',
  hive_partitioning=true, union_by_name=true
) AS b
LEFT JOIN (
  SELECT * EXCLUDE (_rn) FROM (
    SELECT *,
      ROW_NUMBER() OVER (PARTITION BY id ORDER BY delta_seq DESC, updated_at DESC) AS _rn
    FROM read_parquet(
      '/Users/lumegalabs/Projects/php-parquet-duckdb/data/dummy_base_delta/contact/delta/**/*.parquet',
      hive_partitioning=true, union_by_name=true
    )
  ) WHERE _rn = 1
) AS d ON b.id = d.id;

CREATE OR REPLACE VIEW v_base_delta_10 AS
SELECT
  b.id, b.account_id, b.primary_key, b.email, b.mobile,
  COALESCE(d.email_status, b.email_status) AS email_status,
  COALESCE(d.sms_status, b.sms_status) AS sms_status,
  COALESCE(d.is_deleted, b.is_deleted) AS is_deleted,
  COALESCE(d.is_contact, b.is_contact) AS is_contact,
  b.is_preview,
  COALESCE(d.last_emailed, b.last_emailed) AS last_emailed,
  COALESCE(d.last_sms, b.last_sms) AS last_sms,
  COALESCE(d.email_suppressed_on, b.email_suppressed_on) AS email_suppressed_on,
  COALESCE(d.sms_suppressed_on, b.sms_suppressed_on) AS sms_suppressed_on,
  COALESCE(d.email_bounce_count, b.email_bounce_count) AS email_bounce_count,
  COALESCE(d.last_open, b.last_open) AS last_open,
  COALESCE(d.last_click, b.last_click) AS last_click,
  b.f2, b.f6, b.f7, b.f18, b.f30, b.f31
FROM read_parquet(
  '/Users/lumegalabs/Projects/php-parquet-duckdb/data/dummy_base_delta_10/contact/base/**/*.parquet',
  hive_partitioning=true, union_by_name=true
) AS b
LEFT JOIN (
  SELECT * EXCLUDE (_rn) FROM (
    SELECT *,
      ROW_NUMBER() OVER (PARTITION BY id ORDER BY delta_seq DESC, updated_at DESC) AS _rn
    FROM read_parquet(
      '/Users/lumegalabs/Projects/php-parquet-duckdb/data/dummy_base_delta_10/contact/delta/**/*.parquet',
      hive_partitioning=true, union_by_name=true
    )
  ) WHERE _rn = 1
) AS d ON b.id = d.id;
