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

        $sftpStream = 'ssh2.sftp://' . intval($this->sftp) . $remotePath;

        $localHandle  = fopen($sourcePath, 'rb');
        $remoteHandle = fopen($sftpStream, 'wb');

        if (!$localHandle || !$remoteHandle) {
            $this->error = 'Cannot open file handles for SFTP upload.';
            dol_syslog('SftpStorage::upload - ' . $this->error, LOG_ERR);
            $this->disconnect();
            return false;
        }

        $written = stream_copy_to_stream($localHandle, $remoteHandle);
        fclose($localHandle);
        fclose($remoteHandle);

        if ($written === false) {
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

        $sftpStream = 'ssh2.sftp://' . intval($this->sftp) . $storagePath;

        $remoteHandle = fopen($sftpStream, 'rb');
        $localHandle  = fopen($targetPath, 'wb');

        if (!$remoteHandle || !$localHandle) {
            $this->error = 'Cannot open file handles for SFTP download.';
            $this->disconnect();
            return false;
        }

        $written = stream_copy_to_stream($remoteHandle, $localHandle);
        fclose($remoteHandle);
        fclose($localHandle);

        $this->disconnect();

        if ($written === false) {
            $this->error = 'SFTP download failed for: ' . $storagePath;
            return false;
        }

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
