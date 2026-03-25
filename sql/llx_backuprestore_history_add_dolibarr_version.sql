-- Copyright (C) 2024 Your Name <your@email.com>
--
-- Migration: add dolibarr_version column to llx_backuprestore_history

ALTER TABLE llx_backuprestore_history ADD COLUMN dolibarr_version VARCHAR(32) DEFAULT NULL AFTER note;
