-- Copyright (C) 2024 Your Name <your@email.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.

CREATE TABLE IF NOT EXISTS llx_backuprestore_history (
    rowid              INTEGER     NOT NULL AUTO_INCREMENT,
    ref                VARCHAR(64) NOT NULL,
    date_creation      DATETIME    NOT NULL,
    tms                TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    storage_type       VARCHAR(16) NOT NULL DEFAULT 'local',
    storage_path       TEXT,
    file_size          BIGINT      DEFAULT 0,
    backup_type        VARCHAR(16) NOT NULL DEFAULT 'full',
    status             SMALLINT    NOT NULL DEFAULT 0,
    note               TEXT,
    dolibarr_version   VARCHAR(32) DEFAULT NULL,
    fk_user_creat      INTEGER     NOT NULL,
    fk_user_modif      INTEGER     DEFAULT NULL,
    import_key         VARCHAR(14) DEFAULT NULL,
    entity             INTEGER     DEFAULT 1 NOT NULL,
    PRIMARY KEY (rowid)
) ENGINE=InnoDB;
