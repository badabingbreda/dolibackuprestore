<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    backuprestore/class/storageadapter/SftpStorage.class.php
 * \ingroup backuprestore
 * \brief   SFTP storage adapter using PHP's SSH2 extension or pure-PHP fallback via stream wrappers
 *
 * This adapter uses PHP's ssh2 extension if available.
 * If not available, it falls back to using the ssh2:// stream wrapper.
 *
 * For environments without the ssh2 extension, consider installing phpseclib
 * (https://phpseclib.com/) and adapting this class accordingly.
 */

/**
 * Class SftpStorage
 * Stores backup files on a remote SFTP server.
 */
class SftpStorage
{
    /** @var string Last error message */
    public $error = '';

    /** @var resource|null SSH2 connection */
    private $conn = null;

    /** @var resource|null SFTP subsystem */
    private $sftp = null;

    /**
     * Upload a file to the SFTP server.
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
        $remoteDir  = dirname($remotePath);

        // Ensure remote directory exists
        $this->mkdirRecursive($remoteDir);

        // Use ssh2_scp_send instead of the ssh2.sftp:// stream wrapper.
        // The stream wrapper requires casting the SFTP resource to int
        // (intval($this->sftp)), which is deprecated in PHP 8 and returns 0,
        // producing an invalid stream URL. ssh2_scp_send works on all PHP versions.
        if (!ssh2_scp_send($this->conn, $sourcePath, $remotePath, 0640)) {
            $this->error = 'SFTP upload failed for: ' . $remotePath;
            dol_syslog('SftpStorage::upload - ' . $this->error, LOG_ERR);
            $this->disconnect();
            return false;
        }

        $this->disconnect();
        return $remotePath;
    }

    /**
     * Download a file from the SFTP server to a local path.
     *
     * @param string $storagePath Remote path on SFTP server
     * @param string $targetPath  Local destination path
     * @return bool                true if OK, false on error
     */
    public function download($storagePath, $targetPath)
    {
        if (!$this->connect()) {
            return false;
        }

        // Use ssh2_scp_recv instead of the ssh2.sftp:// stream wrapper
        // to avoid the PHP 8 resource-to-int deprecation (see upload() comment).
        if (!ssh2_scp_recv($this->conn, $storagePath, $targetPath)) {
            $this->error = 'SFTP download failed for: ' . $storagePath;
            dol_syslog('SftpStorage::download - ' . $this->error, LOG_ERR);
            $this->disconnect();
            return false;
        }

        $this->disconnect();
        return true;
    }

    /**
     * Delete a file from the SFTP server.
     *
     * @param string $storagePath Remote path on SFTP server
     * @return bool                true if OK
     */
    public function delete($storagePath)
    {
        if (!$this->connect()) {
            return false;
        }

        $result = ssh2_sftp_unlink($this->sftp, $storagePath);
        $this->disconnect();
        return $result;
    }

    /**
     * Test the SFTP connection with current configuration.
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
     * Establish an SFTP connection using module constants.
     * Supports both password and private key authentication.
     *
     * @return bool true if connected
     */
    private function connect()
    {
        global $conf;

        if (!function_exists('ssh2_connect')) {
            $this->error = 'PHP SSH2 extension is not available. Install php-ssh2 or use FTP storage instead.';
            dol_syslog('SftpStorage::connect - ' . $this->error, LOG_ERR);
            return false;
        }

        $host       = !empty($conf->global->BACKUPRESTORE_SFTP_HOST)        ? $conf->global->BACKUPRESTORE_SFTP_HOST        : '';
        $port       = !empty($conf->global->BACKUPRESTORE_SFTP_PORT)        ? (int) $conf->global->BACKUPRESTORE_SFTP_PORT  : 22;
        $user       = !empty($conf->global->BACKUPRESTORE_SFTP_USER)        ? $conf->global->BACKUPRESTORE_SFTP_USER        : '';
        // Decrypt password stored with Dolibarr's 'password' type
        $password   = !empty($conf->global->BACKUPRESTORE_SFTP_PASSWORD)    ? dol_decrypt($conf->global->BACKUPRESTORE_SFTP_PASSWORD) : '';
        $privateKey = !empty($conf->global->BACKUPRESTORE_SFTP_PRIVATE_KEY) ? $conf->global->BACKUPRESTORE_SFTP_PRIVATE_KEY : '';

        if (empty($host)) {
            $this->error = 'SFTP host is not configured.';
            return false;
        }

        $this->conn = @ssh2_connect($host, $port);
        if (!$this->conn) {
            $this->error = 'Cannot connect to SFTP server: ' . $host . ':' . $port;
            return false;
        }

        // Authenticate: prefer private key, fall back to password
        $authenticated = false;

        if (!empty($privateKey) && file_exists($privateKey)) {
            // Validate that the private key path is within the allowed keys directory
            // to prevent a compromised admin account from pointing the key path at
            // arbitrary sensitive files on the server (e.g. /etc/passwd).
            $allowedKeyDir = defined('DOL_DATA_ROOT') ? rtrim(DOL_DATA_ROOT, '/\\') . '/backuprestore/keys' : '';
            $realKeyPath   = realpath($privateKey);
            $realKeyDir    = $allowedKeyDir ? realpath($allowedKeyDir) : false;

            $keyPathAllowed = false;
            if ($realKeyPath !== false && $realKeyDir !== false) {
                $keyPathAllowed = (strpos($realKeyPath, $realKeyDir . DIRECTORY_SEPARATOR) === 0);
            } elseif ($realKeyPath !== false && empty($allowedKeyDir)) {
                // No DOL_DATA_ROOT available — allow but log a warning
                $keyPathAllowed = true;
                dol_syslog('SftpStorage::connect - Cannot validate key path (DOL_DATA_ROOT not defined): ' . $privateKey, LOG_WARNING);
            }

            if (!$keyPathAllowed) {
                $this->error = 'SFTP private key path is outside the allowed directory: ' . $privateKey;
                dol_syslog('SftpStorage::connect - ' . $this->error, LOG_ERR);
                $this->conn = null;
                return false;
            }

            $pubKey = $privateKey . '.pub';
            if (file_exists($pubKey)) {
                $authenticated = @ssh2_auth_pubkey_file($this->conn, $user, $pubKey, $privateKey, $password);
            }
        }

        if (!$authenticated && !empty($password)) {
            $authenticated = @ssh2_auth_password($this->conn, $user, $password);
        }

        if (!$authenticated) {
            $this->error = 'SFTP authentication failed for user: ' . $user;
            $this->conn  = null;
            return false;
        }

        $this->sftp = ssh2_sftp($this->conn);
        if (!$this->sftp) {
            $this->error = 'Cannot initialize SFTP subsystem.';
            $this->conn  = null;
            return false;
        }

        return true;
    }

    /**
     * Close the SFTP connection.
     *
     * @return void
     */
    private function disconnect()
    {
        $this->sftp = null;
        $this->conn = null;
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

        $remotePath = !empty($conf->global->BACKUPRESTORE_SFTP_REMOTE_PATH)
            ? rtrim($conf->global->BACKUPRESTORE_SFTP_REMOTE_PATH, '/')
            : '/backups';

        return $remotePath . '/' . $fileName;
    }

    /**
     * Recursively create directories on the SFTP server.
     *
     * @param string $dir Remote directory path
     * @return void
     */
    private function mkdirRecursive($dir)
    {
        if (empty($dir) || $dir === '/') {
            return;
        }

        // Check if directory already exists
        $stat = @ssh2_sftp_stat($this->sftp, $dir);
        if ($stat !== false) {
            return;
        }

        // Create parent first
        $parent = dirname($dir);
        if ($parent !== $dir) {
            $this->mkdirRecursive($parent);
        }

        @ssh2_sftp_mkdir($this->sftp, $dir, 0755, true);
    }
}
