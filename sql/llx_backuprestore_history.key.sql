-- Copyright (C) 2024 Your Name <your@email.com>
--
-- Indexes and constraints for llx_backuprestore_history

ALTER TABLE llx_backuprestore_history ADD UNIQUE INDEX uk_backuprestore_history_ref (ref, entity);
ALTER TABLE llx_backuprestore_history ADD INDEX idx_backuprestore_history_status (status);
ALTER TABLE llx_backuprestore_history ADD INDEX idx_backuprestore_history_date (date_creation);
ALTER TABLE llx_backuprestore_history ADD INDEX idx_backuprestore_history_fk_user_creat (fk_user_creat);
ALTER TABLE llx_backuprestore_history ADD CONSTRAINT fk_backuprestore_history_fk_user_creat FOREIGN KEY (fk_user_creat) REFERENCES llx_user (rowid);
