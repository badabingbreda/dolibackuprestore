<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    backuprestore/class/storageadapter/FtpStorage.class.php
 * \ingroup backuprestore
 * \brief   FTP storage adapter using PHP's native ftp_* functions
 */

/**
 * Class FtpStorage
 * Stores backup files on a remote FTP server using PHP's native ftp extension.
 */
class FtpStorage
{
    /** @var string Last error message */
    public $error = '';

    /** @var resource|null FTP connection handle */
    private $conn = null;

    /**
     * Upload a file to the FTP server.
     *
     * @param string $sourcePath Absolute local path to the source file
     * @param string $fileName   Desired filename on the remote server
     * @return string|false       Remote path if OK, false on error
     */
    public function upload($sourcePath, $fileName)
    {
        if (!$this->connect()) {
            return false;
        }

        $remotePath = $this->getRemotePath($fileName);

        // Ensure remote directory exists
        $remoteDir = dirname($remotePath);
        $this->mkdirRecursive($remoteDir);

        if (!ftp_put($this->conn, $remotePath, $sourcePath, FTP_BINARY)) {
            $this->error = 'FTP upload failed for: ' . $remotePath;
            dol_syslog('FtpStorage::upload - ' . $this->error, LOG_ERR);
            $this->disconnect();
            return false;
        }

        $this->disconnect();
        return $remotePath;
    }

    /**
     * Download a file from the FTP server to a local path.
     *
     * @param string $storagePath Remote path on FTP server
     * @param string $targetPath  Local destination path
     * @return bool                true if OK, false on error
     */
    public function download($storagePath, $targetPath)
    {
        if (!$this->connect()) {
            return false;
        }

        if (!ftp_get($this->conn, $targetPath, $storagePath, FTP_BINARY)) {
            $this->error = 'FTP download failed for: ' . $storagePath;
            dol_syslog('FtpStorage::download - ' . $this->error, LOG_ERR);
            $this->disconnect();
            return false;
        }

        $this->disconnect();
        return true;
    }

    /**
     * Delete a file from the FTP server.
     *
     * @param string $storagePath Remote path on FTP server
     * @return bool                true if OK
     */
    public function delete($storagePath)
    {
        if (!$this->connect()) {
            return false;
        }

        $result = @ftp_delete($this->conn, $storagePath);
        $this->disconnect();
        return $result;
    }

    /**
     * Test the FTP connection with current configuration.
     *
     * @return bool true if connection succeeds
     */
    public function testConnection()
    {
        $result = $this->connect();
        if ($result) {
            $this->disconnect();
        }
        return $result;
    }

    /**
     * Establish an FTP connection using module constants.
     *
     * @return bool true if connected and logged in
     */
    private function connect()
    {
        global $conf;

        if (!function_exists('ftp_connect')) {
            $this->error = 'PHP FTP extension is not available.';
            return false;
        }

        $host     = !empty($conf->global->BACKUPRESTORE_FTP_HOST)     ? $conf->global->BACKUPRESTORE_FTP_HOST     : '';
        $port     = !empty($conf->global->BACKUPRESTORE_FTP_PORT)     ? (int) $conf->global->BACKUPRESTORE_FTP_PORT : 21;
        $user     = !empty($conf->global->BACKUPRESTORE_FTP_USER)     ? $conf->global->BACKUPRESTORE_FTP_USER     : '';
        // Decrypt password stored with Dolibarr's 'password' type
        $password = !empty($conf->global->BACKUPRESTORE_FTP_PASSWORD) ? dol_decrypt($conf->global->BACKUPRESTORE_FTP_PASSWORD) : '';

        if (empty($host)) {
            $this->error = 'FTP host is not configured.';
            return false;
        }

        $this->conn = @ftp_connect($host, $port, 30);
        if (!$this->conn) {
            $this->error = 'Cannot connect to FTP server: ' . $host . ':' . $port;
            return false;
        }

        if (!@ftp_login($this->conn, $user, $password)) {
            $this->error = 'FTP login failed for user: ' . $user;
            ftp_close($this->conn);
            $this->conn = null;
            return false;
        }

        // Enable passive mode (better for firewalls/NAT)
        ftp_pasv($this->conn, true);

        return true;
    }

    /**
     * Close the FTP connection.
     *
     * @return void
     */
    private function disconnect()
    {
        if ($this->conn) {
            @ftp_close($this->conn);
            $this->conn = null;
        }
    }

    /**
     * Build the full remote path for a given filename.
     *
     * @param string $fileName Filename
     * @return string           Full remote path
     */
    private function getRemotePath($fileName)
    {
        global $conf;

        $remotePath = !empty($conf->global->BACKUPRESTORE_FTP_REMOTE_PATH)
            ? rtrim($conf->global->BACKUPRESTORE_FTP_REMOTE_PATH, '/')
            : '/backups';

        return $remotePath . '/' . $fileName;
    }

    /**
     * Recursively create directories on the FTP server.
     *
     * @param string $dir Remote directory path
     * @return void
     */
    private function mkdirRecursive($dir)
    {
        if (empty($dir) || $dir === '/') {
            return;
        }

        // Try to change to the directory first
        if (@ftp_chdir($this->conn, $dir)) {
            ftp_chdir($this->conn, '/');
            return;
        }

        // Create parent first
        $parent = dirname($dir);
        if ($parent !== $dir) {
            $this->mkdirRecursive($parent);
        }

        @ftp_mkdir($this->conn, $dir);
    }
}
