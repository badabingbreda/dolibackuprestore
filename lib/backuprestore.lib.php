<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    backuprestore/lib/backuprestore.lib.php
 * \ingroup backuprestore
 * \brief   Shared helper functions for the BackupRestore module
 */

/**
 * Prepare array of tabs for the BackupRestore module admin pages.
 *
 * @return array Array of tabs
 */
function backuprestoreAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load('backuprestore@backuprestore');

    $h   = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/backuprestore/admin/setup.php', 1);
    $head[$h][1] = $langs->trans('Settings');
    $head[$h][2] = 'settings';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'backuprestore_admin');

    return $head;
}

/**
 * Format a file size in human-readable form.
 *
 * @param int $bytes Size in bytes
 * @return string     Formatted string (e.g. "4.2 MB")
 */
function backuprestoreFormatFileSize($bytes)
{
    global $langs;
    $langs->load('backuprestore@backuprestore');

    if ($bytes < 1024) {
        return $bytes . ' ' . $langs->trans('SizeBytes');
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' ' . $langs->trans('SizeKB');
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, 1) . ' ' . $langs->trans('SizeMB');
    } else {
        return round($bytes / 1073741824, 2) . ' ' . $langs->trans('SizeGB');
    }
}

/**
 * Return the label for a backup type.
 *
 * @param string $type Backup type: full, database, documents
 * @return string       Translated label
 */
function backuprestoreGetBackupTypeLabel($type)
{
    global $langs;
    $langs->load('backuprestore@backuprestore');

    $map = array(
        'full'      => $langs->trans('BackupTypeFull'),
        'database'  => $langs->trans('BackupTypeDatabase'),
        'documents' => $langs->trans('BackupTypeDocuments'),
    );

    return isset($map[$type]) ? $map[$type] : $type;
}

/**
 * Return the label for a storage type.
 *
 * @param string $type Storage type: local, ftp, sftp
 * @return string       Translated label
 */
function backuprestoreGetStorageTypeLabel($type)
{
    global $langs;
    $langs->load('backuprestore@backuprestore');

    $map = array(
        'local' => $langs->trans('StorageTypeLocal'),
        'ftp'   => $langs->trans('StorageTypeFtp'),
        'sftp'  => $langs->trans('StorageTypeSftp'),
    );

    return isset($map[$type]) ? $map[$type] : $type;
}

/**
 * Check that all required PHP extensions for the module are available.
 *
 * @return array Array of missing extension names (empty if all OK)
 */
function backuprestoreCheckRequirements()
{
    $missing = array();

    if (!class_exists('ZipArchive')) {
        $missing[] = 'zip';
    }

    return $missing;
}

/**
 * Return an HTML select element for backup type.
 *
 * @param string $selected Currently selected value
 * @param string $htmlname HTML name attribute
 * @return string           HTML select
 */
function backuprestoreSelectBackupType($selected = 'full', $htmlname = 'backup_type')
{
    global $langs;
    $langs->load('backuprestore@backuprestore');

    $options = array(
        'full'      => $langs->trans('BackupTypeFull'),
        'database'  => $langs->trans('BackupTypeDatabase'),
        'documents' => $langs->trans('BackupTypeDocuments'),
    );

    $html = '<select name="' . dol_escape_htmltag($htmlname) . '" id="' . dol_escape_htmltag($htmlname) . '" class="flat">';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . dol_escape_htmltag($value) . '"' . ($selected === $value ? ' selected' : '') . '>';
        $html .= dol_escape_htmltag($label);
        $html .= '</option>';
    }
    $html .= '</select>';

    return $html;
}

/**
 * Return an HTML select element for storage type.
 *
 * @param string $selected Currently selected value
 * @param string $htmlname HTML name attribute
 * @return string           HTML select
 */
function backuprestoreSelectStorageType($selected = 'local', $htmlname = 'storage_type')
{
    global $langs, $conf;
    $langs->load('backuprestore@backuprestore');

    $options = array(
        'local' => $langs->trans('StorageTypeLocal'),
        'ftp'   => $langs->trans('StorageTypeFtp'),
        'sftp'  => $langs->trans('StorageTypeSftp'),
    );

    $html = '<select name="' . dol_escape_htmltag($htmlname) . '" id="' . dol_escape_htmltag($htmlname) . '" class="flat">';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . dol_escape_htmltag($value) . '"' . ($selected === $value ? ' selected' : '') . '>';
        $html .= dol_escape_htmltag($label);
        $html .= '</option>';
    }
    $html .= '</select>';

    return $html;
}
