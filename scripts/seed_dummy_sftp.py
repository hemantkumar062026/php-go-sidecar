#!/usr/bin/env python3
"""Regenerate data/dummy Parquet for account 1133 segment/campaign demos."""

from __future__ import annotations

from pathlib import Path

import duckdb

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "data" / "dummy"


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "contact" / "account_id=1133").mkdir(parents=True, exist_ok=True)
    (OUT / "block_file_data").mkdir(parents=True, exist_ok=True)

    con = duckdb.connect()
    con.execute(
        """
CREATE OR REPLACE TABLE contacts AS
SELECT * FROM (VALUES
  (1, 1133, 'pk-1', 'a1@example.com', '9000000001', 1, 1, 0, 1, 1, 0, 0, 'ACC001', 'OrgA', '2020-01-15', 'Delhi', 'Mastercard', 50),
  (2, 1133, 'pk-2', 'a2@example.com', '9000000002', 1, 0, 0, 1, 1, 0, 0, 'ACC002', NULL, '2019-06-01', 'Delhi', 'Mastercard', 50),
  (3, 1133, 'pk-3', 'a3@example.com', NULL, 1, 1, 0, 1, 1, 0, 0, 'ACC003', 'HDFC', '2021-03-20', 'Delhi', 'Mastercard', 50),
  (4, 1133, 'pk-4', 'a4@example.com', '9000000004', 1, 1, 0, 1, 1, 0, 0, 'ACC004', 'ICICI', '2018-11-11', 'Delhi', 'Mastercard', 50),
  (5, 1133, 'pk-5', 'a5@example.com', '9000000005', 1, 1, 0, 1, 1, 0, 0, 'ACC005', 'OrgB', '2022-08-08', 'Mumbai', 'Mastercard', 55),
  (6, 1133, 'pk-6', 'dup@example.com', '9000000006', 1, 1, 0, 1, 1, 0, 0, 'ACC006', 'OrgC', '2020-05-05', 'Delhi', 'Mastercard', 50),
  (7, 1133, 'pk-7', 'dup@example.com', '9000000007', 1, 0, 0, 1, 1, 0, 0, 'ACC007', 'OrgD', '2020-05-05', 'Delhi', 'Mastercard', 50),
  (8, 1133, 'pk-8', 'block56@example.com', '9000000008', 1, 1, 0, 1, 1, 0, 0, 'INC56', 'OrgE', '2017-01-01', 'Pune', 'Visa', 30),
  (9, 1133, 'pk-9', 'block57@example.com', '9000000009', 1, 1, 0, 1, 1, 0, 0, 'EXC57', NULL, '2020-01-15', 'Delhi', 'Mastercard', 50),
  (10, 1133, 'pk-10', 'u@example.com', NULL, 2, 0, 0, 1, 0, 0, 0, 'ACC010', NULL, '2015-01-01', 'Delhi', 'Mastercard', 50),
  (11, 1133, 'pk-11', 'b@example.com', NULL, 3, 0, 0, 1, 0, 0, 0, 'ACC011', NULL, '2015-01-01', 'Delhi', 'Mastercard', 50),
  (12, 1133, 'pk-12', 's@example.com', NULL, 4, 0, 0, 1, 0, 0, 0, 'ACC012', NULL, '2015-01-01', 'Delhi', 'Mastercard', 50),
  (13, 1133, 'pk-13', 'm@example.com', NULL, 5, 0, 0, 1, 0, 0, 0, 'ACC013', NULL, '2015-01-01', 'Delhi', 'Mastercard', 50),
  (14, 1133, 'pk-14', 'n@example.com', NULL, 6, 0, 0, 1, 0, 0, 0, 'ACC014', NULL, '2015-01-01', 'Delhi', 'Mastercard', 50),
  (15, 1133, 'pk-15', 'sms5@example.com', '9000000015', 1, 5, 0, 1, 1, 0, 0, 'ACC015', 'OrgF', '2020-12-31', 'Delhi', 'Mastercard', 50),
  (16, 9999, 'pk-x', 'other@example.com', NULL, 1, 1, 0, 1, 1, 0, 0, 'ACCX', NULL, '2020-01-01', 'Delhi', 'Mastercard', 50)
) AS t(
  id, account_id, primary_key, email, mobile,
  email_status, sms_status, is_deleted, is_contact, is_preview,
  last_emailed, last_sms,
  f2, f6, f7, f18, f30, f31
);
"""
    )
    contact_path = OUT / "contact" / "account_id=1133" / "part-0.parquet"
    con.execute(
        f"COPY (SELECT * FROM contacts WHERE account_id = 1133) TO '{contact_path}' "
        "(FORMAT PARQUET, COMPRESSION ZSTD)"
    )
    con.execute(
        """
CREATE OR REPLACE TABLE block_file_data AS
SELECT * FROM (VALUES
  (56, 'INC56'),
  (57, 'EXC57')
) AS t(block_file_id, unique_identifier);
"""
    )
    block_path = OUT / "block_file_data" / "part-0.parquet"
    con.execute(f"COPY block_file_data TO '{block_path}' (FORMAT PARQUET, COMPRESSION ZSTD)")
    print(f"wrote {contact_path}")
    print(f"wrote {block_path}")


if __name__ == "__main__":
    main()
