<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    backuprestore/class/restore.class.php
 * \ingroup backuprestore
 * \brief   Restore class: restores database and documents from a backup archive
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once __DIR__ . '/backup.class.php';

/**
 * Class Restore
 * Handles restoration of database and documents from a backup ZIP archive.
 */
class Restore
{
    /** @var DoliDB $db Database handler */
    public $db;

    /** @var string Last error message */
    public $error = '';

    /** @var array Error messages */
    public $errors = array();

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
     * Run the full restore process from a backup record.
     *
     * Steps:
     *  1. Create a pre-restore backup (safety net)
     *  2. Download the archive from storage if remote
     *  3. Extract the ZIP
     *  4. Restore database (if present in archive)
     *  5. Restore documents (if present in archive)
     *  6. Mark backup record as restored
     *
     * @param int  $backupId    rowid of the backup to restore
     * @param User $user        User performing the restore
     * @param bool $restoreDb   Whether to restore the database
     * @param bool $restoreDocs Whether to restore the documents folder
     * @return int               1 if OK, <0 if KO
     */
    public function run($backupId, $user, $restoreDb = true, $restoreDocs = true)
    {
        global $conf, $langs;

        $langs->load('backuprestore@backuprestore');

        // Load the backup record
        $backup = new Backup($this->db);
        if ($backup->fetch($backupId) <= 0) {
            $this->error = 'Backup record not found: ' . $backupId;
            return -1;
        }

        dol_syslog('Restore::run - Starting restore of backup: ' . $backup->ref, LOG_INFO);

        // Step 1: Create a pre-restore safety backup
        dol_syslog('Restore::run - Creating pre-restore safety backup', LOG_DEBUG);
        $safetyBackup = new Backup($this->db);
        $safetyBackup->storage_type = 'local';
        $safetyBackup->backup_type  = 'full';
        $safetyResult = $safetyBackup->run($user, 'full', 'local', 'Pre-restore safety backup before restoring ' . $backup->ref);
        if ($safetyResult < 0) {
            dol_syslog('Restore::run - WARNING: Pre-restore backup failed: ' . $safetyBackup->error, LOG_WARNING);
            // Non-fatal: continue with restore
        }

        // Step 2: Get the archive file (download if remote)
        $tempDir = '';
        if (!empty($conf->backuprestore) && !empty($conf->backuprestore->dir_temp)) {
            $tempDir = $conf->backuprestore->dir_temp;
        }
        if (empty($tempDir)) {
            $tempDir = DOL_DATA_ROOT . '/backuprestore/temp';
        }
        dol_mkdir($tempDir);

        $localArchivePath = $tempDir . '/' . $backup->ref . '_restore.zip';

        $adapter = $backup->getStorageAdapter();
        if (!$adapter) {
            $this->error = 'No storage adapter for type: ' . $backup->storage_type;
            return -1;
        }

        dol_syslog('Restore::run - Downloading backup archive', LOG_DEBUG);
        $downloaded = $adapter->download($backup->storage_path, $localArchivePath);
        if (!$downloaded) {
            $this->error = $langs->trans('ErrorDownloadRemote') . ': ' . $adapter->error;
            return -1;
        }

        // Step 3: Extract the ZIP
        if (!class_exists('ZipArchive')) {
            $this->error = $langs->trans('ErrorPhpZipExtension');
            @unlink($localArchivePath);
            return -1;
        }

        $extractDir = $tempDir . '/' . $backup->ref . '_extracted';
        dol_mkdir($extractDir);

        $zip = new ZipArchive();
        if ($zip->open($localArchivePath) !== true) {
            $this->error = 'Cannot open ZIP archive: ' . $localArchivePath;
            @unlink($localArchivePath);
            return -1;
        }

        // Zip-slip protection: validate every entry stays within $extractDir
        $realExtractDir = realpath($extractDir);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            // Resolve the target path without actually creating it
            $entryTarget = $realExtractDir . DIRECTORY_SEPARATOR . $entryName;
            // Normalize separators and check prefix
            $entryTarget = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $entryTarget);
            if (strpos($entryTarget, $realExtractDir . DIRECTORY_SEPARATOR) !== 0
                && $entryTarget !== $realExtractDir) {
                $zip->close();
                @unlink($localArchivePath);
                $this->error = 'Zip-slip path traversal detected in archive entry: ' . $entryName;
                dol_syslog('Restore::run - ' . $this->error, LOG_ERR);
                return -1;
            }
        }

        $zip->extractTo($extractDir);
        $zip->close();
        @unlink($localArchivePath);

        dol_syslog('Restore::run - Archive extracted to: ' . $extractDir, LOG_DEBUG);

        // Step 4: Restore database
        if ($restoreDb && file_exists($extractDir . '/database.sql')) {
            dol_syslog('Restore::run - Restoring database', LOG_DEBUG);
            $result = $this->restoreDatabase($extractDir . '/database.sql');
            if ($result < 0) {
                $this->cleanupExtractDir($extractDir);
                return -1;
            }
            dol_syslog('Restore::run - Database restored successfully', LOG_INFO);
        }

        // Step 5: Restore documents
        if ($restoreDocs && is_dir($extractDir . '/documents')) {
            dol_syslog('Restore::run - Restoring documents', LOG_DEBUG);
            $result = $this->restoreDocuments($extractDir . '/documents', DOL_DATA_ROOT);
            if ($result < 0) {
                $this->cleanupExtractDir($extractDir);
                return -1;
            }
            dol_syslog('Restore::run - Documents restored successfully', LOG_INFO);
        }

        // Step 6: Mark backup as restored
        $backup->status = Backup::STATUS_RESTORED;
        $backup->update($user);

        // Cleanup
        $this->cleanupExtractDir($extractDir);

        dol_syslog('Restore::run - Restore completed successfully for: ' . $backup->ref, LOG_INFO);
        return 1;
    }

    /**
     * Restore the database from a SQL dump file.
     * Executes statements one by one using the Dolibarr DB handler.
     *
     * @param string $sqlFile Path to the SQL dump file
     * @return int             1 if OK, <0 if KO
     */
    private function restoreDatabase($sqlFile)
    {
        global $langs;

        if (!file_exists($sqlFile)) {
            $this->error = 'SQL dump file not found: ' . $sqlFile;
            return -1;
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            $this->error = 'Cannot read SQL dump file: ' . $sqlFile;
            return -1;
        }

        // Split into individual statements
        $statements = $this->splitSqlStatements($sql);

        $this->db->begin();

        // Allowlist of permitted SQL statement types for restore safety
        $allowedStatementTypes = array('INSERT', 'UPDATE', 'CREATE', 'DROP', 'ALTER', 'SET', 'LOCK', 'UNLOCK');

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue;
            }

            // Extract the first keyword to validate statement type
            $firstWord = strtoupper(strtok($statement, " \t\n\r"));
            if (!in_array($firstWord, $allowedStatementTypes)) {
                dol_syslog('Restore::restoreDatabase - Skipping disallowed statement type: ' . $firstWord, LOG_WARNING);
                continue;
            }

            $resql = $this->db->query($statement);
            if (!$resql) {
                $errMsg = $this->db->lasterror();
                // Ignore "table already exists" type errors for CREATE TABLE
                if (strpos(strtoupper($statement), 'CREATE TABLE') !== false && strpos($errMsg, 'already exists') !== false) {
                    continue;
                }
                dol_syslog('Restore::restoreDatabase - SQL error: ' . $errMsg . ' | Statement: ' . substr($statement, 0, 200), LOG_WARNING);
                // Continue on non-critical errors
            }
        }

        $this->db->commit();
        return 1;
    }

    /**
     * Split a SQL dump string into individual statements.
     *
     * @param string $sql Full SQL dump
     * @return array       Array of SQL statements
     */
    private function splitSqlStatements($sql)
    {
        $statements = array();
        $current    = '';
        $inString   = false;
        $stringChar = '';
        $len        = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            if ($inString) {
                $current .= $char;
                if ($char === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inString = false;
                }
            } else {
                if ($char === "'" || $char === '"' || $char === '`') {
                    $inString   = true;
                    $stringChar = $char;
                    $current   .= $char;
                } elseif ($char === ';') {
                    $current .= $char;
                    $trimmed  = trim($current);
                    if (!empty($trimmed)) {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
        }

        // Last statement without semicolon
        $trimmed = trim($current);
        if (!empty($trimmed)) {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Restore documents by copying files from the extracted directory to the target.
     *
     * @param string $sourceDir Extracted documents directory
     * @param string $targetDir Target documents root (DOL_DATA_ROOT)
     * @return int               1 if OK, <0 if KO
     */
    private function restoreDocuments($sourceDir, $targetDir)
    {
        if (!is_dir($sourceDir)) {
            $this->error = 'Source documents directory not found: ' . $sourceDir;
            return -1;
        }

        if (!is_dir($targetDir)) {
            dol_mkdir($targetDir);
        }

        $result = $this->copyDirectory($sourceDir, $targetDir);
        if (!$result) {
            $this->error = 'Failed to copy documents from ' . $sourceDir . ' to ' . $targetDir;
            return -1;
        }

        return 1;
    }

    /**
     * Recursively copy a directory.
     *
     * @param string $src Source directory
     * @param string $dst Destination directory
     * @return bool        true if OK
     */
    private function copyDirectory($src, $dst)
    {
        if (!is_dir($dst)) {
            if (!mkdir($dst, 0755, true)) {
                return false;
            }
        }

        $items = scandir($src);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;

            if (is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $dstPath)) {
                    return false;
                }
            } else {
                if (!copy($srcPath, $dstPath)) {
                    dol_syslog('Restore::copyDirectory - Cannot copy: ' . $srcPath . ' to ' . $dstPath, LOG_WARNING);
                }
            }
        }

        return true;
    }

    /**
     * Remove the temporary extraction directory.
     *
     * @param string $dir Directory to remove
     * @return void
     */
    private function cleanupExtractDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->cleanupExtractDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
