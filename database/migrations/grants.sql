-- Grants for the transaction_writer DB user used by the Function App.
-- Apply once, after the migration runs. The user owns nothing in Snipe-IT
-- proper -- only the transaction_* tables.

CREATE USER IF NOT EXISTS 'transaction_writer'@'%' IDENTIFIED BY '__SET_BY_KV__';

GRANT SELECT, INSERT, UPDATE
    ON snipeit.transaction_raw_rows         TO 'transaction_writer'@'%';
GRANT SELECT, INSERT, UPDATE
    ON snipeit.transaction_gl_totals        TO 'transaction_writer'@'%';
GRANT SELECT, INSERT, UPDATE
    ON snipeit.transaction_reconciliations  TO 'transaction_writer'@'%';
GRANT SELECT, INSERT, UPDATE
    ON snipeit.transaction_line_items       TO 'transaction_writer'@'%';
GRANT SELECT
    ON snipeit.transaction_overrides        TO 'transaction_writer'@'%';
GRANT SELECT
    ON snipeit.transaction_effective_line_items TO 'transaction_writer'@'%';

FLUSH PRIVILEGES;
