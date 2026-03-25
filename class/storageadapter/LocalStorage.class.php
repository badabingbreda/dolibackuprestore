<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    backuprestore/class/storageadapter/LocalStorage.class.php
 * \ingroup backuprestore
 * \brief   Local filesystem storage adapter
 */

/**
 * Class LocalStorage
 * Stores backup files on the local server filesystem.
 */
class LocalStorage
{
    /** @var string Last error message */
    public $error = '';

    /**
     * Upload (move) a file to the local backup storage directory.
     *
     * @param string $sourcePath    Absolute path to the source file
     * @param string $fileName      Desired filename in the storage directory
     * @return string|false          Stored path if OK, false on error
     */
    public function upload($sourcePath, $fileName)
    {
        global $conf;

        $storageDir = $this->getStorageDir();
        if (!$storageDir) {
            return false;
        }

        dol_mkdir($storageDir);

        $destPath = $storageDir . DIRECTORY_SEPARATOR . $fileName;

        if (!copy($sourcePath, $destPath)) {
            $this->error = 'Cannot copy file to local storage: ' . $destPath;
            dol_syslog('LocalStorage::upload - ' . $this->error, LOG_ERR);
            return false;
        }

        return $destPath;
    }

    /**
     * Download (copy) a file from local storage to a target path.
     *
     * @param string $storagePath  Absolute path in storage
     * @param string $targetPath   Destination path
     * @return bool                 true if OK, false on error
     */
    public function download($storagePath, $targetPath)
    {
        if (!file_exists($storagePath)) {
            $this->error = 'File not found in local storage: ' . $storagePath;
            return false;
        }

        if (!copy($storagePath, $targetPath)) {
            $this->error = 'Cannot copy file from local storage: ' . $storagePath;
            return false;
        }

        return true;
    }

    /**
     * Delete a file from local storage.
     *
     * @param string $storagePath Absolute path in storage
     * @return bool                true if OK
     */
    public function delete($storagePath)
    {
        if (file_exists($storagePath)) {
            return @unlink($storagePath);
        }
        return true;
    }

    /**
     * Test that the storage directory is writable.
     *
     * @return bool true if OK
     */
    public function testConnection()
    {
        $storageDir = $this->getStorageDir();
        if (!$storageDir) {
            $this->error = 'Local storage path is not configured.';
            return false;
        }

        dol_mkdir($storageDir);

        if (!is_writable($storageDir)) {
            $this->error = 'Local storage directory is not writable: ' . $storageDir;
            return false;
        }

        return true;
    }

    /**
     * Get the configured local storage directory.
     *
     * @return string|false Absolute path, or false if not configured
     */
    private function getStorageDir()
    {
        global $conf;

        if (!empty($conf->global->BACKUPRESTORE_LOCAL_PATH)) {
            $configuredPath = $conf->global->BACKUPRESTORE_LOCAL_PATH;
            // If the configured path is relative, resolve it against DOL_DATA_ROOT
            if (!preg_match('/^(\/|[A-Za-z]:[\\\\\/])/', $configuredPath)) {
                if (defined('DOL_DATA_ROOT') && DOL_DATA_ROOT) {
                    return rtrim(DOL_DATA_ROOT, '/\\') . '/' . ltrim($configuredPath, '/\\');
                }
            }
            return rtrim($configuredPath, '/\\');
        }

        // Default: use Dolibarr documents directory (DOL_DATA_ROOT is a PHP constant)
        if (defined('DOL_DATA_ROOT') && DOL_DATA_ROOT) {
            return rtrim(DOL_DATA_ROOT, '/\\') . '/backuprestore/backups';
        }

        $this->error = 'Cannot determine local storage directory.';
        return false;
    }
}
