<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    backuprestore/class/backup.class.php
 * \ingroup backuprestore
 * \brief   Core backup class: pure-PHP database dump + ZipArchive document backup
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Class Backup
 * Handles creation of database dumps and document archives.
 */
class Backup extends CommonObject
{
    /** @var string Module name */
    public $module = 'backuprestore';

    /** @var string Table name without prefix */
    public $table_element = 'backuprestore_history';

    /** @var string Picto */
    public $picto = 'technic';

    /** @var string Backup reference */
    public $ref;

    /** @var string Storage type: local, ftp, sftp */
    public $storage_type = 'local';

    /** @var string Full path or URL to the backup file */
    public $storage_path;

    /** @var int File size in bytes */
    public $file_size = 0;

    /** @var string Backup type: full, database, documents */
    public $backup_type = 'full';

    /** @var int Status: 0=pending, 1=running, 2=success, 3=failed, 4=restored */
    public $status = 0;

    /** @var string Optional note */
    public $note;

    /** @var string Dolibarr version at time of backup */
    public $dolibarr_version;

    /** @var int User who created the backup */
    public $fk_user_creat;

    /** @var string Date of creation */
    public $date_creation;

    // Status constants
    const STATUS_PENDING  = 0;
    const STATUS_RUNNING  = 1;
    const STATUS_SUCCESS  = 2;
    const STATUS_FAILED   = 3;
    const STATUS_RESTORED = 4;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Create a new backup record in the database.
     *
     * @param User $user User creating the backup
     * @return int        rowid if OK, <0 if KO
     */
    public function create($user)
    {
        global $conf, $dolibarr_main_version;

        $this->ref              = $this->generateRef();
        $this->date_creation    = dol_now();
        $this->fk_user_creat    = $user->id;
        $this->status           = self::STATUS_PENDING;
        $this->entity           = $conf->entity;
        $this->dolibarr_version = !empty($dolibarr_main_version) ? $dolibarr_main_version : DOL_VERSION;

        $sql  = "INSERT INTO " . MAIN_DB_PREFIX . "backuprestore_history";
        $sql .= " (ref, date_creation, storage_type, storage_path, file_size, backup_type, status, note, dolibarr_version, fk_user_creat, entity)";
        $sql .= " VALUES (";
        $sql .= "'" . $this->db->escape($this->ref) . "',";
        $sql .= "'" . $this->db->idate($this->date_creation) . "',";
        $sql .= "'" . $this->db->escape($this->storage_type) . "',";
        $sql .= ($this->storage_path ? "'" . $this->db->escape($this->storage_path) . "'" : "NULL") . ",";
        $sql .= (int) $this->file_size . ",";
        $sql .= "'" . $this->db->escape($this->backup_type) . "',";
        $sql .= (int) $this->status . ",";
        $sql .= ($this->note ? "'" . $this->db->escape($this->note) . "'" : "NULL") . ",";
        $sql .= "'" . $this->db->escape($this->dolibarr_version) . "',";
        $sql .= (int) $this->fk_user_creat . ",";
        $sql .= (int) $this->entity;
        $sql .= ")";

        $this->db->begin();
        $resql = $this->db->query($sql);
        if ($resql) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX . "backuprestore_history");
            $this->db->commit();
            return $this->id;
        } else {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }
    }

    /**
     * Load a backup record from the database.
     *
     * @param int    $id  rowid
     * @param string $ref Reference
     * @return int         1 if OK, 0 if not found, <0 if KO
     */
    public function fetch($id, $ref = '')
    {
        $sql  = "SELECT rowid, ref, date_creation, storage_type, storage_path, file_size, backup_type, status, note, dolibarr_version, fk_user_creat, entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . "backuprestore_history";
        if ($id > 0) {
            $sql .= " WHERE rowid = " . (int) $id;
        } elseif ($ref) {
            $sql .= " WHERE ref = '" . $this->db->escape($ref) . "'";
        } else {
            return -1;
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $this->id               = $obj->rowid;
                $this->ref              = $obj->ref;
                $this->date_creation    = $this->db->jdate($obj->date_creation);
                $this->storage_type     = $obj->storage_type;
                $this->storage_path     = $obj->storage_path;
                $this->file_size        = $obj->file_size;
                $this->backup_type      = $obj->backup_type;
                $this->status           = (int) $obj->status;
                $this->note             = $obj->note;
                $this->dolibarr_version = $obj->dolibarr_version;
                $this->fk_user_creat    = (int) $obj->fk_user_creat;
                $this->entity           = (int) $obj->entity;
                return 1;
            }
            return 0;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }

    /**
     * Update a backup record.
     *
     * @param User $user User performing the update
     * @return int        1 if OK, <0 if KO
     */
    public function update($user)
    {
        $sql  = "UPDATE " . MAIN_DB_PREFIX . "backuprestore_history SET";
        $sql .= " storage_type = '" . $this->db->escape($this->storage_type) . "',";
        $sql .= " storage_path = " . ($this->storage_path ? "'" . $this->db->escape($this->storage_path) . "'" : "NULL") . ",";
        $sql .= " file_size = " . (int) $this->file_size . ",";
        $sql .= " backup_type = '" . $this->db->escape($this->backup_type) . "',";
        $sql .= " status = " . (int) $this->status . ",";
        $sql .= " note = " . ($this->note ? "'" . $this->db->escape($this->note) . "'" : "NULL") . ",";
        $sql .= " fk_user_modif = " . (int) $user->id;
        $sql .= " WHERE rowid = " . (int) $this->id;

        $resql = $this->db->query($sql);
        if ($resql) {
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }

    /**
     * Delete a backup record and optionally its file.
     *
     * @param User $user       User performing the delete
     * @param bool $deleteFile Whether to also delete the physical file
     * @return int              1 if OK, <0 if KO
     */
    public function delete($user, $deleteFile = true)
    {
        if ($deleteFile && !empty($this->storage_path)) {
            $adapter = $this->getStorageAdapter();
            if ($adapter) {
                $adapter->delete($this->storage_path);
            }
        }

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "backuprestore_history WHERE rowid = " . (int) $this->id;
        $resql = $this->db->query($sql);
        if ($resql) {
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }

    /**
     * Run the full backup process.
     *
     * @param User   $user        User triggering the backup
     * @param string $backupType  'full', 'database', or 'documents'
     * @param string $storageType 'local', 'ftp', or 'sftp'
     * @param string $note        Optional note
     * @return int                 rowid of backup record if OK, <0 if KO
     */
    public function run($user, $backupType = 'full', $storageType = 'local', $note = '')
    {
        global $conf, $langs;

        // Extend execution time and memory for large backups
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $this->backup_type  = $backupType;
        $this->storage_type = $storageType;
        $this->note         = $note;

        // Create the DB record first (status = pending)
        $id = $this->create($user);
        if ($id < 0) {
            return -1;
        }

        // Mark as running
        $this->status = self::STATUS_RUNNING;
        $this->update($user);

        // Determine temp directory — use DOL_DATA_ROOT constant (not $conf->global)
        $tempDir = '';
        if (!empty($conf->backuprestore) && !empty($conf->backuprestore->dir_temp)) {
            $tempDir = $conf->backuprestore->dir_temp;
        }
        if (empty($tempDir)) {
            $tempDir = DOL_DATA_ROOT . '/backuprestore/temp';
        }

        // Ensure temp directory exists and is writable
        if (!is_dir($tempDir)) {
            if (!@mkdir($tempDir, 0755, true)) {
                dol_syslog('Backup::run - Cannot create temp directory: ' . $tempDir, LOG_WARNING);
            }
        }
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            $this->error = 'Temp directory is not writable: ' . $tempDir;
            $this->status = self::STATUS_FAILED;
            $this->update($user);
            return -1;
        }

        $archiveName = $this->ref . '.zip';
        $archivePath = $tempDir . '/' . $archiveName;

        try {
            if (!class_exists('ZipArchive')) {
                throw new Exception($langs->trans('ErrorPhpZipExtension'));
            }

            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception('Cannot create ZIP archive at: ' . $archivePath . ' (check directory permissions)');
            }

            // --- README.txt ---
            $readmeContent = $this->generateReadme($backupType);
            $zip->addFromString('README.txt', $readmeContent);
            dol_syslog('BackupRestore::run - README.txt added', LOG_DEBUG);

            // --- Database dump ---
            if (in_array($backupType, array('full', 'database'))) {
                dol_syslog('BackupRestore::run - Starting database dump', LOG_DEBUG);
                $sqlDump = $this->dumpDatabase();
                if ($sqlDump === false) {
                    $zip->close();
                    throw new Exception('Database dump failed: ' . $this->error);
                }
                $zip->addFromString('database.sql', $sqlDump);
                unset($sqlDump); // free memory immediately after adding to ZIP
                dol_syslog('BackupRestore::run - Database dump complete', LOG_DEBUG);
            }

            // --- Documents archive ---
            if (in_array($backupType, array('full', 'documents'))) {
                dol_syslog('BackupRestore::run - Starting documents archive', LOG_DEBUG);
                $docsRoot = DOL_DATA_ROOT;
                if (!empty($docsRoot) && is_dir($docsRoot)) {
                    // Build the exclusion list: always exclude the temp dir (to avoid
                    // recursion) and the local backup storage directory (to prevent
                    // previous backup ZIPs from being included in new backups).
                    $excludeDirs = array($tempDir);

                    require_once __DIR__ . '/storageadapter/LocalStorage.class.php';
                    $localAdapter   = new LocalStorage();
                    $localStorageDir = $localAdapter->getStorageDir();
                    if ($localStorageDir) {
                        $excludeDirs[] = $localStorageDir;
                    }

                    $this->addDirectoryToZip($zip, $docsRoot, 'documents', $excludeDirs);
                }
                dol_syslog('BackupRestore::run - Documents archive complete', LOG_DEBUG);
            }

            $zip->close();

            // --- Upload to storage ---
            $adapter = $this->getStorageAdapter();
            if (!$adapter) {
                throw new Exception('No storage adapter available for type: ' . $storageType);
            }

            $remotePath = $adapter->upload($archivePath, $archiveName);
            if ($remotePath === false) {
                throw new Exception('Upload to storage failed: ' . $adapter->error);
            }

            // Update record with final info
            $this->storage_path = $remotePath;
            $this->file_size    = filesize($archivePath);
            $this->status       = self::STATUS_SUCCESS;
            $this->update($user);

            // Clean up temp file
            @unlink($archivePath);

            dol_syslog('BackupRestore::run - Backup completed: ' . $this->ref, LOG_INFO);
            return $this->id;

        } catch (Exception $e) {
            dol_syslog('BackupRestore::run - ERROR: ' . $e->getMessage(), LOG_ERR);
            $this->error  = $e->getMessage();
            $this->status = self::STATUS_FAILED;
            $this->note   = $e->getMessage();
            $this->update($user);
            @unlink($archivePath);
            return -1;
        }
    }

    /**
     * Dump the entire database to a SQL string using pure PHP (no exec/shell).
     *
     * @return string|false SQL dump string, or false on error
     */
    public function dumpDatabase()
    {
        global $conf;

        $output = '';

        // Header
        $output .= "-- Dolibarr Database Backup\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Dolibarr version: " . DOL_VERSION . "\n";
        $output .= "-- Module: BackupRestore\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $output .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
        $output .= "SET NAMES utf8mb4;\n\n";

        // Get all tables
        $resql = $this->db->query("SHOW TABLES");
        if (!$resql) {
            $this->error = 'Cannot list tables: ' . $this->db->lasterror();
            return false;
        }

        $tables = array();
        while ($row = $this->db->fetch_row($resql)) {
            $tables[] = $row[0];
        }

        foreach ($tables as $table) {
            $tableDump = $this->dumpTable($table);
            if ($tableDump === false) {
                return false;
            }
            $output .= $tableDump;
        }

        $output .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
        return $output;
    }

    /**
     * Dump a single table (CREATE + INSERT statements).
     *
     * @param string $table Table name
     * @return string        SQL for this table
     */
    private function dumpTable($table)
    {
        $output = '';
        $output .= "\n-- Table: `$table`\n";
        $output .= "DROP TABLE IF EXISTS `$table`;\n";

        // CREATE TABLE statement
        $resql = $this->db->query("SHOW CREATE TABLE `$table`");
        if (!$resql) {
            dol_syslog('BackupRestore::dumpTable - Cannot get CREATE TABLE for ' . $table, LOG_WARNING);
            return $output;
        }
        $row = $this->db->fetch_row($resql);
        if ($row) {
            $output .= $row[1] . ";\n\n";
        }

        // Row count
        $resqlCount = $this->db->query("SELECT COUNT(*) FROM `$table`");
        if ($resqlCount) {
            $countRow = $this->db->fetch_row($resqlCount);
            $rowCount = (int) $countRow[0];
        } else {
            $rowCount = 0;
        }

        if ($rowCount === 0) {
            return $output;
        }

        // Determine the primary key column for stable ORDER BY across batches.
        // Using ORDER BY on a unique, non-nullable column guarantees that LIMIT/OFFSET
        // pagination never skips or duplicates rows, even when the engine's default
        // sort order is non-deterministic (e.g. InnoDB with concurrent writes).
        // Fall back to ORDER BY 1 only when no PRIMARY KEY is defined (e.g. views, log tables).
        $orderByCol = '1';
        $resqlPk = $this->db->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
        if ($resqlPk) {
            $pkRow = $this->db->fetch_object($resqlPk);
            if ($pkRow && !empty($pkRow->Column_name)) {
                $orderByCol = '`' . $pkRow->Column_name . '`';
            }
        }

        // Fetch all rows in batches to avoid memory issues.
        $batchSize = 500;
        $offset    = 0;

        while ($offset < $rowCount) {
            $resqlData = $this->db->query("SELECT * FROM `$table` ORDER BY $orderByCol LIMIT $batchSize OFFSET $offset");
            if (!$resqlData) {
                break;
            }

            while ($dataRow = $this->db->fetch_row($resqlData)) {
                $values = array();
                // Use count($dataRow) instead of num_fields() — DoliDB does not expose num_fields()
                foreach ($dataRow as $val) {
                    if ($val === null) {
                        $values[] = 'NULL';
                    } elseif (is_numeric($val) && !preg_match('/^0\d/', $val)) {
                        // Only treat as numeric if it doesn't have a leading zero (e.g. phone numbers)
                        $values[] = $val;
                    } else {
                        $values[] = "'" . $this->db->escape($val) . "'";
                    }
                }
                $output .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
            }

            $offset += $batchSize;
        }

        $output .= "\n";
        return $output;
    }

    /**
     * Recursively add a directory to a ZipArchive.
     *
     * @param ZipArchive $zip        The zip archive object
     * @param string     $dir        Absolute path to the directory
     * @param string     $zipPrefix  Prefix inside the zip
     * @param array      $excludeDirs Absolute paths to exclude
     * @return void
     */
    private function addDirectoryToZip(ZipArchive $zip, $dir, $zipPrefix, $excludeDirs = array())
    {
        $dir = rtrim($dir, '/\\');

        if (!is_dir($dir)) {
            return;
        }

        // Check if this directory should be excluded
        foreach ($excludeDirs as $excl) {
            $excl = rtrim($excl, '/\\');
            if (strpos($dir, $excl) === 0) {
                return;
            }
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath  = $dir . DIRECTORY_SEPARATOR . $item;
            $zipPath   = $zipPrefix . '/' . $item;

            if (is_dir($fullPath)) {
                $zip->addEmptyDir($zipPath);
                $this->addDirectoryToZip($zip, $fullPath, $zipPath, $excludeDirs);
            } elseif (is_file($fullPath)) {
                $zip->addFile($fullPath, $zipPath);
            }
        }
    }

    /**
     * Get the appropriate storage adapter based on current storage_type.
     *
     * @return LocalStorage|FtpStorage|SftpStorage|null
     */
    public function getStorageAdapter()
    {
        $type = $this->storage_type;

        if ($type === 'local') {
            require_once __DIR__ . '/storageadapter/LocalStorage.class.php';
            return new LocalStorage();
        } elseif ($type === 'ftp') {
            require_once __DIR__ . '/storageadapter/FtpStorage.class.php';
            return new FtpStorage();
        } elseif ($type === 'sftp') {
            require_once __DIR__ . '/storageadapter/SftpStorage.class.php';
            return new SftpStorage();
        }

        return null;
    }

    /**
     * Generate the README.txt content to include in the backup ZIP.
     *
     * @param string $backupType Backup type: full, database, documents
     * @return string             README content
     */
    private function generateReadme($backupType)
    {
        global $conf, $dolibarr_main_version;

        $version = !empty($dolibarr_main_version) ? $dolibarr_main_version : DOL_VERSION;
        $date    = date('Y-m-d H:i:s');
        $ref     = $this->ref;

        // Collect enabled modules
        $enabledModules = array();
        $sql = "SELECT name, value FROM " . MAIN_DB_PREFIX . "const WHERE name LIKE 'MAIN_MODULE_%' AND value = '1' AND entity IN (0, " . (int) $conf->entity . ")";
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                // Strip MAIN_MODULE_ prefix to get module name
                $moduleName = strtolower(preg_replace('/^MAIN_MODULE_/', '', $obj->name));
                $enabledModules[] = $moduleName;
            }
            sort($enabledModules);
        }

        $hasDb   = in_array($backupType, array('full', 'database'));
        $hasDocs = in_array($backupType, array('full', 'documents'));

        $readme  = "================================================================================\n";
        $readme .= "  DOLIBARR BACKUP - README\n";
        $readme .= "================================================================================\n\n";

        $readme .= "Backup Reference : " . $ref . "\n";
        $readme .= "Created          : " . $date . "\n";
        $readme .= "Backup Type      : " . $backupType . "\n";
        $readme .= "Dolibarr Version : " . $version . "\n\n";

        $readme .= "--------------------------------------------------------------------------------\n";
        $readme .= "CONTENTS OF THIS BACKUP\n";
        $readme .= "--------------------------------------------------------------------------------\n\n";

        if ($hasDb) {
            $readme .= "  database.sql   - Full MySQL database dump (all tables and data)\n";
        }
        if ($hasDocs) {
            $readme .= "  documents/     - Dolibarr documents folder (uploaded files, generated PDFs, etc.)\n";
        }
        $readme .= "  README.txt     - This file\n\n";

        if (!empty($enabledModules)) {
            $readme .= "--------------------------------------------------------------------------------\n";
            $readme .= "ENABLED MODULES AT TIME OF BACKUP\n";
            $readme .= "--------------------------------------------------------------------------------\n\n";
            foreach ($enabledModules as $mod) {
                $readme .= "  - " . $mod . "\n";
            }
            $readme .= "\n";
        }

        $readme .= "--------------------------------------------------------------------------------\n";
        $readme .= "HOW TO RESTORE THIS BACKUP\n";
        $readme .= "--------------------------------------------------------------------------------\n\n";

        $readme .= "PREREQUISITES\n";
        $readme .= "  - A working web server (Apache/Nginx) with PHP " . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "+ and MySQL/MariaDB\n";
        $readme .= "  - Dolibarr " . $version . " installed (same version as this backup)\n";
        $readme .= "  - Access to the server filesystem and database\n\n";

        if ($hasDb) {
            $readme .= "STEP 1 - RESTORE THE DATABASE\n";
            $readme .= "  Option A (recommended): Use the BackupRestore module restore function\n";
            $readme .= "    1. Log in to Dolibarr as administrator\n";
            $readme .= "    2. Go to Tools > Backup & Restore\n";
            $readme .= "    3. Upload this ZIP file or ensure it is in the backup storage directory\n";
            $readme .= "    4. Click the Restore button next to this backup\n";
            $readme .= "    5. Confirm by typing RESTORE and click Restore Now\n\n";
            $readme .= "  Option B (manual): Import database.sql via command line\n";
            $readme .= "    1. Extract database.sql from this ZIP\n";
            $readme .= "    2. Run: mysql -u <db_user> -p <db_name> < database.sql\n";
            $readme .= "    3. Or use phpMyAdmin: Import > Choose file > database.sql\n\n";
        }

        if ($hasDocs) {
            $step = $hasDb ? "STEP 2" : "STEP 1";
            $readme .= $step . " - RESTORE THE DOCUMENTS FOLDER\n";
            $readme .= "  1. Extract the 'documents/' folder from this ZIP\n";
            $readme .= "  2. Copy its contents to your Dolibarr data directory (DOL_DATA_ROOT)\n";
            $readme .= "     Typically: /var/lib/dolibarr/documents/ or /home/<user>/dolibarr/documents/\n";
            $readme .= "  3. Ensure the web server user (www-data, apache, etc.) owns the files:\n";
            $readme .= "     chown -R www-data:www-data /path/to/dolibarr/documents/\n\n";
        }

        $readme .= "STEP " . ($hasDb && $hasDocs ? "3" : ($hasDb || $hasDocs ? "2" : "1")) . " - VERIFY THE RESTORE\n";
        $readme .= "  1. Clear Dolibarr's cache: delete files in documents/admin/temp/\n";
        $readme .= "  2. Log in to Dolibarr and verify data integrity\n";
        $readme .= "  3. Check that all modules listed above are still enabled\n";
        $readme .= "  4. Run Setup > System information to confirm the version\n\n";

        $readme .= "IMPORTANT NOTES\n";
        $readme .= "  - Restoring overwrites ALL existing data. Make a backup before restoring.\n";
        $readme .= "  - The database dump uses SET FOREIGN_KEY_CHECKS=0 for compatibility.\n";
        $readme .= "  - If restoring to a different server, update conf/conf.php with the new\n";
        $readme .= "    database credentials and DOL_DATA_ROOT path.\n\n";

        $readme .= "================================================================================\n";
        $readme .= "  Generated by BackupRestore module for Dolibarr\n";
        $readme .= "================================================================================\n";

        return $readme;
    }

    /**
     * Generate a unique backup reference.
     *
     * @return string e.g. BCK-20260325-143000
     */
    private function generateRef()
    {
        return 'BCK-' . date('Ymd-His') . '-' . strtoupper(substr(uniqid(), -4));
    }

    /**
     * Get a list of backup records.
     *
     * @param string $sortfield  Sort field
     * @param string $sortorder  Sort order (ASC/DESC)
     * @param int    $limit      Max records
     * @param int    $offset     Offset
     * @return array|int          Array of objects, or <0 on error
     */
    public function fetchAll($sortfield = 'date_creation', $sortorder = 'DESC', $limit = 50, $offset = 0)
    {
        global $conf;

        // Allowlist sort field to prevent ORDER BY injection
        $allowedSortFields = array('ref', 'date_creation', 'backup_type', 'storage_type', 'file_size', 'status');
        if (!in_array($sortfield, $allowedSortFields)) {
            $sortfield = 'date_creation';
        }

        $sql  = "SELECT rowid, ref, date_creation, storage_type, storage_path, file_size, backup_type, status, note, dolibarr_version, fk_user_creat";
        $sql .= " FROM " . MAIN_DB_PREFIX . "backuprestore_history";
        $sql .= " WHERE entity = " . (int) $conf->entity;
        $sql .= " ORDER BY " . $sortfield . " " . ($sortorder === 'ASC' ? 'ASC' : 'DESC');
        if ($limit > 0) {
            $sql .= " LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $records = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $record                  = new Backup($this->db);
            $record->id              = $obj->rowid;
            $record->ref             = $obj->ref;
            $record->date_creation   = $this->db->jdate($obj->date_creation);
            $record->storage_type    = $obj->storage_type;
            $record->storage_path    = $obj->storage_path;
            $record->file_size       = $obj->file_size;
            $record->backup_type     = $obj->backup_type;
            $record->status          = (int) $obj->status;
            $record->note            = $obj->note;
            $record->dolibarr_version = $obj->dolibarr_version;
            $record->fk_user_creat   = $obj->fk_user_creat;
            $records[]               = $record;
        }

        return $records;
    }

    /**
     * Cron method: run a scheduled backup with settings from module constants.
     *
     * @return int 0 if OK, <0 if KO
     */
    public function runScheduledBackup()
    {
        global $conf, $user;

        $storageType  = !empty($conf->global->BACKUPRESTORE_STORAGE_TYPE) ? $conf->global->BACKUPRESTORE_STORAGE_TYPE : 'local';
        $cronInterval = !empty($conf->global->BACKUPRESTORE_CRON_INTERVAL) ? (int) $conf->global->BACKUPRESTORE_CRON_INTERVAL : 86400;

        // Check if enough time has passed since the last successful backup
        $sqlLast = "SELECT date_creation FROM " . MAIN_DB_PREFIX . "backuprestore_history";
        $sqlLast .= " WHERE entity = " . (int) $conf->entity . " AND status = " . self::STATUS_SUCCESS;
        $sqlLast .= " ORDER BY date_creation DESC LIMIT 1";
        $resqlLast = $this->db->query($sqlLast);
        if ($resqlLast) {
            $objLast = $this->db->fetch_object($resqlLast);
            if ($objLast) {
                $lastRun = $this->db->jdate($objLast->date_creation);
                if ((dol_now() - $lastRun) < $cronInterval) {
                    dol_syslog('BackupRestore::runScheduledBackup - Skipping, interval not reached yet', LOG_DEBUG);
                    return 0;
                }
            }
        }

        $result = $this->run($user, 'full', $storageType, 'Scheduled automatic backup');

        if ($result < 0) {
            return -1;
        }

        // Purge old backups
        $retentionDays = !empty($conf->global->BACKUPRESTORE_RETENTION_DAYS) ? (int) $conf->global->BACKUPRESTORE_RETENTION_DAYS : 30;
        if ($retentionDays > 0) {
            $this->purgeOldBackups($user, $retentionDays);
        }

        return 0;
    }

    /**
     * Delete backups older than $days days.
     *
     * @param User $user User performing the purge
     * @param int  $days Number of days
     * @return int        Number of deleted records
     */
    public function purgeOldBackups($user, $days = 30)
    {
        global $conf;

        $cutoff = dol_now() - ($days * 86400);

        $sql  = "SELECT rowid FROM " . MAIN_DB_PREFIX . "backuprestore_history";
        $sql .= " WHERE entity = " . (int) $conf->entity;
        $sql .= " AND date_creation < '" . $this->db->idate($cutoff) . "'";
        $sql .= " AND status = " . self::STATUS_SUCCESS;

        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }

        $deleted = 0;
        while ($obj = $this->db->fetch_object($resql)) {
            $backup = new Backup($this->db);
            if ($backup->fetch($obj->rowid) > 0) {
                if ($backup->delete($user, true) > 0) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Return label of status.
     *
     * @param int $status Status code
     * @return string      Label
     */
    public static function getStatusLabel($status)
    {
        global $langs;
        $langs->load('backuprestore@backuprestore');

        $labels = array(
            self::STATUS_PENDING  => $langs->trans('BackupStatusPending'),
            self::STATUS_RUNNING  => $langs->trans('BackupStatusRunning'),
            self::STATUS_SUCCESS  => $langs->trans('BackupStatusSuccess'),
            self::STATUS_FAILED   => $langs->trans('BackupStatusFailed'),
            self::STATUS_RESTORED => $langs->trans('BackupStatusRestored'),
        );

        return isset($labels[$status]) ? $labels[$status] : 'Unknown';
    }

    /**
     * Return HTML badge for status.
     *
     * @param int $status Status code
     * @return string      HTML
     */
    public static function getStatusBadge($status)
    {
        $label = self::getStatusLabel($status);
        $colorMap = array(
            self::STATUS_PENDING  => 'status0',
            self::STATUS_RUNNING  => 'status4',
            self::STATUS_SUCCESS  => 'status1',
            self::STATUS_FAILED   => 'status8',
            self::STATUS_RESTORED => 'status6',
        );
        $class = isset($colorMap[$status]) ? $colorMap[$status] : 'status0';
        return '<span class="badge ' . $class . '">' . dol_escape_htmltag($label) . '</span>';
    }
}
