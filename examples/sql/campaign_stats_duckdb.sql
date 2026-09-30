-- Campaign stats on dummy data (run from repo root so relative paths work).
-- Requires block_file_data view — created below.

CREATE OR REPLACE TEMP VIEW block_file_data AS
SELECT *
FROM read_parquet('data/dummy/block_file_data/*.parquet');

SELECT
  COALESCE(
    COUNT(
      DISTINCT CASE
        WHEN c.email_status = 1
          AND (
            (
              (
                ((c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi')
                  OR (c.f31 > 51 AND c.f30 = 'Mastercard'))
                AND (
                  NOT (
                    ((c.f31 > 51 AND c.f30 = 'Mastercard')
                      OR (c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi' AND c.f6 = 'ICICI'))
                  )
                  AND NOT EXISTS (
                    SELECT 1
                    FROM block_file_data AS bfd
                    WHERE bfd.block_file_id IN (57)
                      AND bfd.unique_identifier = c.f2
                  )
                )
              )
              OR (
                EXISTS (
                  SELECT 1
                  FROM block_file_data AS bfd
                  WHERE bfd.block_file_id IN (56)
                    AND bfd.unique_identifier = c.f2
                )
                AND (
                  NOT (
                    ((c.f31 > 51 AND c.f30 = 'Mastercard')
                      OR (c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi' AND c.f6 = 'ICICI'))
                  )
                  AND NOT EXISTS (
                    SELECT 1
                    FROM block_file_data AS bfd
                    WHERE bfd.block_file_id IN (57)
                      AND bfd.unique_identifier = c.f2
                  )
                )
              )
            )
          )
        THEN NULLIF(TRIM(c.email), '')
      END
    ),
    0
  ) AS cnt,

  (
    COALESCE(
      SUM(
        IF(
          c.email_status = 1
            AND (
              (
                ((c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi')
                  OR (c.f31 > 51 AND c.f30 = 'Mastercard'))
                AND (
                  NOT (
                    ((c.f31 > 51 AND c.f30 = 'Mastercard')
                      OR (c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi' AND c.f6 = 'ICICI'))
                  )
                  AND NOT EXISTS (
                    SELECT 1 FROM block_file_data AS bfd
                    WHERE bfd.block_file_id IN (57) AND bfd.unique_identifier = c.f2
                  )
                )
              )
              OR (
                EXISTS (
                  SELECT 1 FROM block_file_data AS bfd
                  WHERE bfd.block_file_id IN (56) AND bfd.unique_identifier = c.f2
                )
                AND (
                  NOT (
                    ((c.f31 > 51 AND c.f30 = 'Mastercard')
                      OR (c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi' AND c.f6 = 'ICICI'))
                  )
                  AND NOT EXISTS (
                    SELECT 1 FROM block_file_data AS bfd
                    WHERE bfd.block_file_id IN (57) AND bfd.unique_identifier = c.f2
                  )
                )
              )
            )
            AND NULLIF(TRIM(c.email), '') IS NOT NULL,
          1,
          0
        )
      ),
      0
    )
    - COUNT(
      DISTINCT CASE
        WHEN c.email_status = 1
          AND (
            (
              ((c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi')
                OR (c.f31 > 51 AND c.f30 = 'Mastercard'))
              AND (
                NOT (
                  ((c.f31 > 51 AND c.f30 = 'Mastercard')
                    OR (c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi' AND c.f6 = 'ICICI'))
                )
                AND NOT EXISTS (
                  SELECT 1 FROM block_file_data AS bfd
                  WHERE bfd.block_file_id IN (57) AND bfd.unique_identifier = c.f2
                )
              )
            )
            OR (
              EXISTS (
                SELECT 1 FROM block_file_data AS bfd
                WHERE bfd.block_file_id IN (56) AND bfd.unique_identifier = c.f2
              )
              AND (
                NOT (
                  ((c.f31 > 51 AND c.f30 = 'Mastercard')
                    OR (c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi' AND c.f6 = 'ICICI'))
                )
                AND NOT EXISTS (
                  SELECT 1 FROM block_file_data AS bfd
                  WHERE bfd.block_file_id IN (57) AND bfd.unique_identifier = c.f2
                )
              )
            )
          )
        THEN NULLIF(TRIM(c.email), '')
      END
    )
  ) AS duplicate_count,

  COALESCE(
    SUM(
      IF(
        c.email_status = 1
          AND (
            ((c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi')
              OR (c.f31 > 51 AND c.f30 = 'Mastercard'))
            OR EXISTS (
              SELECT 1 FROM block_file_data AS bfd
              WHERE bfd.block_file_id IN (56) AND bfd.unique_identifier = c.f2
            )
          ),
        1,
        0
      )
    ),
    0
  ) AS include_count,

  COALESCE(
    SUM(
      IF(
        c.email_status = 1
          AND (
            (
              ((c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi')
                OR (c.f31 > 51 AND c.f30 = 'Mastercard'))
              AND NOT (
                NOT (
                  ((c.f31 > 51 AND c.f30 = 'Mastercard')
                    OR (c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi' AND c.f6 = 'ICICI'))
                )
                AND NOT EXISTS (
                  SELECT 1 FROM block_file_data AS bfd
                  WHERE bfd.block_file_id IN (57) AND bfd.unique_identifier = c.f2
                )
              )
            )
            OR (
              EXISTS (
                SELECT 1 FROM block_file_data AS bfd
                WHERE bfd.block_file_id IN (56) AND bfd.unique_identifier = c.f2
              )
              AND NOT (
                NOT (
                  ((c.f31 > 51 AND c.f30 = 'Mastercard')
                    OR (c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi' AND c.f6 = 'ICICI'))
                )
                AND NOT EXISTS (
                  SELECT 1 FROM block_file_data AS bfd
                  WHERE bfd.block_file_id IN (57) AND bfd.unique_identifier = c.f2
                )
              )
            )
          ),
        1,
        0
      )
    ),
    0
  ) AS exclude_count,

  COALESCE(SUM(IF(c.email_status = 1, 1, 0)), 0) AS email_active,
  COALESCE(SUM(IF(c.email_status = 2, 1, 0)), 0) AS email_unsubscribed,
  COALESCE(SUM(IF(c.email_status = 3, 1, 0)), 0) AS email_bounced,
  COALESCE(SUM(IF(c.email_status = 4, 1, 0)), 0) AS email_marked_spam,
  COALESCE(SUM(IF(c.email_status = 5, 1, 0)), 0) AS email_manually_suppressed,
  COALESCE(SUM(IF(c.email_status = 6, 1, 0)), 0) AS email_unconfirmed,
  COALESCE(SUM(IF(c.email_status = 1 AND c.sms_status = 1, 1, 0)), 0) AS sms_active,
  COALESCE(SUM(IF(c.email_status = 1 AND c.sms_status = 5, 1, 0)), 0) AS sms_manually_suppressed,
  COALESCE(
    SUM(
      IF(
        c.email_status IN (2, 3, 4, 5)
          AND (
            ((c.f30 = 'Mastercard' AND c.f31 = 50 AND c.f18 = 'Delhi')
              OR (c.f31 > 51 AND c.f30 = 'Mastercard'))
            OR EXISTS (
              SELECT 1 FROM block_file_data AS bfd
              WHERE bfd.block_file_id IN (56) AND bfd.unique_identifier = c.f2
            )
          ),
        1,
        0
      )
    ),
    0
  ) AS email_all_suppressed
FROM read_parquet(
  'data/dummy/contact/**/*.parquet',
  hive_partitioning = true,
  union_by_name = true
) AS c
WHERE c.account_id = 1133
  AND c.is_deleted = 0;
