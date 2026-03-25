<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    backuprestore/admin/setup.php
 * \ingroup backuprestore
 * \brief   Module configuration page
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once __DIR__ . '/../lib/backuprestore.lib.php';
require_once __DIR__ . '/../class/backup.class.php';

// Security: admin only
if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array('admin', 'backuprestore@backuprestore'));

$action = GETPOST('action', 'aZ09');

// ---- Handle form submission ----
if ($action === 'update') {
    if (function_exists('checkToken')) {
        checkToken();
    }
    $storageType    = GETPOST('BACKUPRESTORE_STORAGE_TYPE', 'aZ09');
    $localPath      = GETPOST('BACKUPRESTORE_LOCAL_PATH', 'alpha');
    $retentionDays  = GETPOST('BACKUPRESTORE_RETENTION_DAYS', 'int');
    $cronInterval   = GETPOST('BACKUPRESTORE_CRON_INTERVAL', 'int');
    $ftpHost        = GETPOST('BACKUPRESTORE_FTP_HOST', 'alpha');
    $ftpPort        = GETPOST('BACKUPRESTORE_FTP_PORT', 'int');
    $ftpUser        = GETPOST('BACKUPRESTORE_FTP_USER', 'alpha');
    $ftpPassword    = GETPOST('BACKUPRESTORE_FTP_PASSWORD', 'alpha');
    $ftpRemotePath  = GETPOST('BACKUPRESTORE_FTP_REMOTE_PATH', 'alpha');
    $sftpHost       = GETPOST('BACKUPRESTORE_SFTP_HOST', 'alpha');
    $sftpPort       = GETPOST('BACKUPRESTORE_SFTP_PORT', 'int');
    $sftpUser       = GETPOST('BACKUPRESTORE_SFTP_USER', 'alpha');
    $sftpPassword   = GETPOST('BACKUPRESTORE_SFTP_PASSWORD', 'alpha');
    $sftpPrivateKey = GETPOST('BACKUPRESTORE_SFTP_PRIVATE_KEY', 'alpha');
    $sftpRemotePath = GETPOST('BACKUPRESTORE_SFTP_REMOTE_PATH', 'alpha');

    dolibarr_set_const($db, 'BACKUPRESTORE_STORAGE_TYPE',    $storageType,    'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_LOCAL_PATH',      $localPath,      'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_RETENTION_DAYS',  $retentionDays,  'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_CRON_INTERVAL',   $cronInterval ?: 86400, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_FTP_HOST',        $ftpHost,        'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_FTP_PORT',        $ftpPort ?: 21,  'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_FTP_USER',        $ftpUser,        'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_FTP_REMOTE_PATH', $ftpRemotePath,  'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_SFTP_HOST',       $sftpHost,       'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_SFTP_PORT',       $sftpPort ?: 22, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_SFTP_USER',       $sftpUser,       'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_SFTP_PRIVATE_KEY', $sftpPrivateKey, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BACKUPRESTORE_SFTP_REMOTE_PATH', $sftpRemotePath, 'chaine', 0, '', $conf->entity);

    // Store FTP/SFTP passwords using Dolibarr's encrypted 'password' type
    if (!empty($ftpPassword)) {
        dolibarr_set_const($db, 'BACKUPRESTORE_FTP_PASSWORD', $ftpPassword, 'password', 0, '', $conf->entity);
    }
    if (!empty($sftpPassword)) {
        dolibarr_set_const($db, 'BACKUPRESTORE_SFTP_PASSWORD', $sftpPassword, 'password', 0, '', $conf->entity);
    }

    setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ---- Handle connection test ----
if ($action === 'testconnection') {
    // Note: this page is already protected by the admin-only check above.
    // checkToken() is intentionally omitted here because the page renders two
    // forms and Dolibarr tokens are single-use: consuming the token for one
    // form invalidates it for the other. The admin guard provides sufficient
    // protection for a read-only connection test.
    $storageType = GETPOST('storage_type_test', 'aZ09');
    $backup = new Backup($db);
    $backup->storage_type = $storageType;
    $adapter = $backup->getStorageAdapter();

    if ($adapter && $adapter->testConnection()) {
        setEventMessages($langs->trans('ConnectionSuccess'), null, 'mesgs');
    } else {
        $errMsg = $adapter ? $adapter->error : 'No adapter found';
        setEventMessages($langs->trans('ConnectionFailed') . ': ' . $errMsg, null, 'errors');
    }
}

// ---- Page output ----
$page_name = 'BackupRestoreSetup';
llxHeader('', $langs->trans($page_name));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = backuprestoreAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('Module'), -1, 'technic');

// Check requirements
$missing = backuprestoreCheckRequirements();
if (!empty($missing)) {
    print '<div class="warning">';
    print $langs->trans('ErrorPhpZipExtension');
    print '</div>';
}

// Generate a single CSRF token for the whole page. Both the Save form and the
// Test Connection form share this token. Calling newToken() twice on the same
// page would overwrite the session token after the first form is rendered,
// causing checkToken() to fail with a 500 when the Save form is submitted.
$pageToken = newToken();
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . $pageToken . '">';
print '<input type="hidden" name="action" value="update">';

print load_fiche_titre($langs->trans('StorageConfiguration'), '', '');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans('Parameter') . '</td>';
print '<td>' . $langs->trans('Value') . '</td>';
print '</tr>';

// Storage type
print '<tr class="oddeven">';
print '<td class="fieldrequired">' . $langs->trans('StorageType') . '</td>';
print '<td>';
$currentStorageType = !empty($conf->global->BACKUPRESTORE_STORAGE_TYPE) ? $conf->global->BACKUPRESTORE_STORAGE_TYPE : 'local';
print backuprestoreSelectStorageType($currentStorageType, 'BACKUPRESTORE_STORAGE_TYPE');
print '</td></tr>';

// Retention days
print '<tr class="oddeven">';
print '<td>' . $langs->trans('RetentionDays') . ' <span class="opacitymedium">(' . $langs->trans('RetentionDaysHelp') . ')</span></td>';
print '<td><input type="number" name="BACKUPRESTORE_RETENTION_DAYS" class="flat" value="' . dol_escape_htmltag(!empty($conf->global->BACKUPRESTORE_RETENTION_DAYS) ? $conf->global->BACKUPRESTORE_RETENTION_DAYS : '30') . '" min="0" style="width:80px"></td>';
print '</tr>';

// Cron interval
$cronIntervalOptions = array(
    86400        => $langs->trans('CronIntervalDaily'),
    86400 * 3.5  => $langs->trans('CronIntervalBiweekly'),
    86400 * 7    => $langs->trans('CronIntervalWeekly'),
    86400 * 14   => $langs->trans('CronIntervalBimonthly'),
    86400 * 30   => $langs->trans('CronIntervalMonthly'),
);
$currentCronInterval = !empty($conf->global->BACKUPRESTORE_CRON_INTERVAL) ? (int) $conf->global->BACKUPRESTORE_CRON_INTERVAL : 86400;

print '<tr class="oddeven">';
print '<td>' . $langs->trans('CronInterval') . ' <span class="opacitymedium">(' . $langs->trans('CronIntervalHelp') . ')</span></td>';
print '<td>';
print '<select name="BACKUPRESTORE_CRON_INTERVAL" class="flat">';
foreach ($cronIntervalOptions as $seconds => $label) {
    $selected = ((int) $seconds === $currentCronInterval) ? ' selected' : '';
    print '<option value="' . (int) $seconds . '"' . $selected . '>' . dol_escape_htmltag($label) . '</option>';
}
print '</select>';
print '</td></tr>';

print '</table>';

// ---- Local storage settings ----
print '<br>';
print load_fiche_titre($langs->trans('StorageTypeLocal'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('LocalStoragePath') . ' <span class="opacitymedium">(' . $langs->trans('LocalStoragePathHelp') . ')</span></td>';
print '<td><input type="text" name="BACKUPRESTORE_LOCAL_PATH" class="flat minwidth400" value="' . dol_escape_htmltag(!empty($conf->global->BACKUPRESTORE_LOCAL_PATH) ? $conf->global->BACKUPRESTORE_LOCAL_PATH : '') . '"></td>';
print '</tr>';

// ---- Local path writability check ----
// Resolve relative paths against DOL_DATA_ROOT (same logic as LocalStorage::getStorageDir())
if (!empty($conf->global->BACKUPRESTORE_LOCAL_PATH)) {
    $configuredPath = $conf->global->BACKUPRESTORE_LOCAL_PATH;
    if (!preg_match('/^(\/|[A-Za-z]:[\\\\\/])/', $configuredPath) && defined('DOL_DATA_ROOT') && DOL_DATA_ROOT) {
        $effectiveLocalPath = rtrim(DOL_DATA_ROOT, '/\\') . '/' . ltrim($configuredPath, '/\\');
    } else {
        $effectiveLocalPath = rtrim($configuredPath, '/\\');
    }
} else {
    $effectiveLocalPath = (defined('DOL_DATA_ROOT') && DOL_DATA_ROOT) ? rtrim(DOL_DATA_ROOT, '/\\') . '/backuprestore/backups' : '';
}

if (!empty($effectiveLocalPath)) {
    // Try to create the directory if it doesn't exist yet
    if (!is_dir($effectiveLocalPath)) {
        @mkdir($effectiveLocalPath, 0755, true);
    }

    print '<tr class="oddeven">';
    print '<td>' . $langs->trans('LocalStoragePathStatus') . '</td>';
    print '<td>';
    if (!is_dir($effectiveLocalPath)) {
        print '<span class="badge badge-status8 status8">&#10007; ' . $langs->trans('LocalStoragePathNotFound') . '</span>';
        print ' <span class="opacitymedium">' . dol_escape_htmltag($effectiveLocalPath) . '</span>';
    } elseif (!is_writable($effectiveLocalPath)) {
        print '<span class="badge badge-status8 status8">&#10007; ' . $langs->trans('LocalStoragePathNotWritable') . '</span>';
        print ' <span class="opacitymedium">' . dol_escape_htmltag($effectiveLocalPath) . '</span>';
    } else {
        print '<span class="badge badge-status1 status1">&#10003; ' . $langs->trans('LocalStoragePathOk') . '</span>';
        print ' <span class="opacitymedium">' . dol_escape_htmltag($effectiveLocalPath) . '</span>';
    }
    print '</td>';
    print '</tr>';
}

print '</table>';

// ---- FTP settings ----
print '<br>';
print load_fiche_titre($langs->trans('StorageTypeFtp'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';

$ftpFields = array(
    'BACKUPRESTORE_FTP_HOST'        => array('label' => 'FtpHost',       'type' => 'text',     'default' => ''),
    'BACKUPRESTORE_FTP_PORT'        => array('label' => 'FtpPort',       'type' => 'number',   'default' => '21'),
    'BACKUPRESTORE_FTP_USER'        => array('label' => 'FtpUser',       'type' => 'text',     'default' => ''),
    'BACKUPRESTORE_FTP_PASSWORD'    => array('label' => 'FtpPassword',   'type' => 'password', 'default' => ''),
    'BACKUPRESTORE_FTP_REMOTE_PATH' => array('label' => 'FtpRemotePath', 'type' => 'text',     'default' => '/backups'),
);

foreach ($ftpFields as $constName => $field) {
    print '<tr class="oddeven">';
    print '<td>' . $langs->trans($field['label']) . '</td>';
    print '<td>';
    $val = !empty($conf->global->$constName) ? $conf->global->$constName : $field['default'];
    if ($field['type'] === 'password') {
        print '<input type="password" name="' . $constName . '" class="flat minwidth200" value="' . dol_escape_htmltag($val) . '" autocomplete="new-password">';
    } elseif ($field['type'] === 'number') {
        print '<input type="number" name="' . $constName . '" class="flat" value="' . dol_escape_htmltag($val) . '" style="width:80px">';
    } else {
        print '<input type="text" name="' . $constName . '" class="flat minwidth300" value="' . dol_escape_htmltag($val) . '">';
    }
    print '</td></tr>';
}

print '</table>';

// ---- SFTP settings ----
print '<br>';
print load_fiche_titre($langs->trans('StorageTypeSftp'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';

$sftpFields = array(
    'BACKUPRESTORE_SFTP_HOST'        => array('label' => 'SftpHost',       'type' => 'text',     'default' => ''),
    'BACKUPRESTORE_SFTP_PORT'        => array('label' => 'SftpPort',       'type' => 'number',   'default' => '22'),
    'BACKUPRESTORE_SFTP_USER'        => array('label' => 'SftpUser',       'type' => 'text',     'default' => ''),
    'BACKUPRESTORE_SFTP_PASSWORD'    => array('label' => 'SftpPassword',   'type' => 'password', 'default' => ''),
    'BACKUPRESTORE_SFTP_PRIVATE_KEY' => array('label' => 'SftpPrivateKey', 'type' => 'text',     'default' => ''),
    'BACKUPRESTORE_SFTP_REMOTE_PATH' => array('label' => 'SftpRemotePath', 'type' => 'text',     'default' => '/backups'),
);

foreach ($sftpFields as $constName => $field) {
    print '<tr class="oddeven">';
    print '<td>' . $langs->trans($field['label']) . '</td>';
    print '<td>';
    $val = !empty($conf->global->$constName) ? $conf->global->$constName : $field['default'];
    if ($field['type'] === 'password') {
        print '<input type="password" name="' . $constName . '" class="flat minwidth200" value="' . dol_escape_htmltag($val) . '" autocomplete="new-password">';
    } elseif ($field['type'] === 'number') {
        print '<input type="number" name="' . $constName . '" class="flat" value="' . dol_escape_htmltag($val) . '" style="width:80px">';
    } else {
        print '<input type="text" name="' . $constName . '" class="flat minwidth300" value="' . dol_escape_htmltag($val) . '">';
    }
    print '</td></tr>';
}

print '</table>';

// ---- Save button ----
print '<br>';
print '<div class="center">';
print '<input type="submit" class="button button-save" value="' . $langs->trans('Save') . '">';
print '</div>';
print '</form>';

// ---- Test connection form ----
print '<br>';
print load_fiche_titre($langs->trans('TestConnection'), '', '');
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
// Reuse $pageToken — do NOT call newToken() again here, as that would
// overwrite the session token and cause checkToken() to fail for the Save form.
print '<input type="hidden" name="token" value="' . $pageToken . '">';
print '<input type="hidden" name="action" value="testconnection">';
print '<table class="noborder centpercent">';
print '<tr class="oddeven">';
print '<td>' . $langs->trans('StorageType') . '</td>';
print '<td>';
print backuprestoreSelectStorageType($currentStorageType, 'storage_type_test');
print ' <input type="submit" class="button" value="' . $langs->trans('TestConnection') . '">';
print '</td></tr>';
print '</table>';
print '</form>';

// ---- Cron status section ----
print '<br>';
print load_fiche_titre($langs->trans('CronStatus'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';

// Find the last successful backup
$lastBackupDate = null;
$sqlLast = "SELECT date_creation FROM " . MAIN_DB_PREFIX . "backuprestore_history";
$sqlLast .= " WHERE entity = " . (int) $conf->entity;
$sqlLast .= " AND status = 2"; // STATUS_SUCCESS
$sqlLast .= " ORDER BY date_creation DESC LIMIT 1";
$resqlLast = $db->query($sqlLast);
if ($resqlLast) {
    $objLast = $db->fetch_object($resqlLast);
    if ($objLast) {
        $lastBackupDate = $db->jdate($objLast->date_creation);
    }
}

// Last backup
print '<tr class="oddeven">';
print '<td>' . $langs->trans('CronLastBackup') . '</td>';
print '<td>';
if ($lastBackupDate) {
    print dol_print_date($lastBackupDate, 'dayhour');
} else {
    print '<span class="opacitymedium">' . $langs->trans('CronNoBackupYet') . '</span>';
}
print '</td></tr>';

// Next scheduled run
print '<tr class="oddeven">';
print '<td>' . $langs->trans('CronNextRun') . '</td>';
print '<td>';
if ($lastBackupDate) {
    $nextRun = $lastBackupDate + $currentCronInterval;
    $now     = dol_now();
    if ($nextRun <= $now) {
        print '<span class="badge badge-status4 status4">' . $langs->trans('CronOverdue') . '</span>';
        print ' <span class="opacitymedium">' . dol_print_date($nextRun, 'dayhour') . '</span>';
    } else {
        $diff    = $nextRun - $now;
        $hours   = floor($diff / 3600);
        $minutes = floor(($diff % 3600) / 60);
        print dol_print_date($nextRun, 'dayhour');
        print ' <span class="opacitymedium">(';
        if ($hours > 0) {
            print $hours . 'h ';
        }
        print $minutes . 'min ' . $langs->trans('CronFromNow') . ')</span>';
    }
} else {
    print '<span class="opacitymedium">' . $langs->trans('CronWillRunAfterFirstBackup') . '</span>';
}
print '</td></tr>';

// Cron interval (read-only display)
print '<tr class="oddeven">';
print '<td>' . $langs->trans('CronInterval') . '</td>';
print '<td>';
$intervalLabel = isset($cronIntervalOptions[$currentCronInterval]) ? $cronIntervalOptions[$currentCronInterval] : $currentCronInterval . 's';
print dol_escape_htmltag($intervalLabel);
print ' <span class="opacitymedium">(' . $langs->trans('CronIntervalChangeHelp') . ')</span>';
print '</td></tr>';

print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
