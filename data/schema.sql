-- ═══════════════════════════════════════════════════════════════════════════
-- West Side Swim Club — Record Board Database
-- MariaDB schema: records + history table + AFTER UPDATE trigger
-- ═══════════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS westside_records
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE westside_records;

-- ── records ─────────────────────────────────────────────────────────────────
-- One row per unique (panel, age_group, gender, event) combination.
-- age_group is NULL for diving records (no age grouping in the source data).

CREATE TABLE IF NOT EXISTS records (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    panel       ENUM(
                    'team_swimming',
                    'pool_swimming',
                    'team_diving',
                    'pool_diving'
                )                NOT NULL,
    age_group   VARCHAR(20)      NULL     COMMENT 'e.g. 9-10, 11-12 — NULL for diving',
    gender      ENUM('girls','boys') NOT NULL,
    event       VARCHAR(60)      NOT NULL,
    holder_name VARCHAR(200)     NOT NULL,
    record_year SMALLINT UNSIGNED NOT NULL,
    record_time VARCHAR(20)      NOT NULL COMMENT 'Stored as string to preserve original format',
    created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_record (panel, age_group, gender, event)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Current swim/dive records';


-- ── records_history ──────────────────────────────────────────────────────────
-- Append-only audit trail. One row written per field-level change on records.

CREATE TABLE IF NOT EXISTS records_history (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    record_id   INT UNSIGNED     NOT NULL,
    panel       ENUM(
                    'team_swimming',
                    'pool_swimming',
                    'team_diving',
                    'pool_diving'
                )                NOT NULL,
    age_group   VARCHAR(20)      NULL,
    gender      ENUM('girls','boys') NOT NULL,
    event       VARCHAR(60)      NOT NULL,

    -- Snapshot of every tracked field before and after the update
    old_name    VARCHAR(200)     NULL,
    new_name    VARCHAR(200)     NULL,
    old_year    SMALLINT UNSIGNED NULL,
    new_year    SMALLINT UNSIGNED NULL,
    old_time    VARCHAR(20)      NULL,
    new_time    VARCHAR(20)      NULL,

    changed_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_history_record  (record_id),
    KEY idx_history_changed (changed_at),
    CONSTRAINT fk_history_record
        FOREIGN KEY (record_id) REFERENCES records (id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Full audit trail of every change to a record';


-- ── AFTER UPDATE trigger ─────────────────────────────────────────────────────
-- Fires whenever holder_name, record_year, or record_time changes.
-- Inserts a history row only when at least one tracked field actually changed.

DROP TRIGGER IF EXISTS trg_records_after_update;

DELIMITER $$

CREATE TRIGGER trg_records_after_update
AFTER UPDATE ON records
FOR EACH ROW
BEGIN
    IF (OLD.holder_name <> NEW.holder_name
        OR OLD.record_year <> NEW.record_year
        OR OLD.record_time <> NEW.record_time)
    THEN
        INSERT INTO records_history (
            record_id,
            panel, age_group, gender, event,
            old_name,  new_name,
            old_year,  new_year,
            old_time,  new_time
        ) VALUES (
            OLD.id,
            OLD.panel, OLD.age_group, OLD.gender, OLD.event,
            OLD.holder_name,  NEW.holder_name,
            OLD.record_year,  NEW.record_year,
            OLD.record_time,  NEW.record_time
        );
    END IF;
END$$

DELIMITER ;
