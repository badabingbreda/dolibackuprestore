<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    backuprestore/restore.php
 * \ingroup backuprestore
 * \brief   Restore confirmation and execution page
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

require_once __DIR__ . '/lib/backuprestore.lib.php';
require_once __DIR__ . '/class/backup.class.php';
require_once __DIR__ . '/class/restore.class.php';

// Security check
if (empty($conf->backuprestore->enabled)) {
    accessforbidden('Module not enabled');
}
if (empty($user->rights->backuprestore->restore)) {
    accessforbidden();
}

$langs->loadLangs(array('backuprestore@backuprestore'));

$action   = GETPOST('action', 'aZ09');
$backupId = GETPOST('id', 'int');

if ($backupId <= 0) {
    header('Location: ' . dol_buildpath('/backuprestore/index.php', 1));
    exit;
}

// Load the backup record
$backup = new Backup($db);
if ($backup->fetch($backupId) <= 0) {
    setEventMessages($langs->trans('BackupFileNotFound'), null, 'errors');
    header('Location: ' . dol_buildpath('/backuprestore/index.php', 1));
    exit;
}

// ---- Handle restore execution ----
if ($action === 'doRestore') {
    if (function_exists('checkToken')) {
        checkToken();
    }
    $confirmation  = GETPOST('restore_confirm', 'alpha');
    $restoreDb     = GETPOST('restore_db', 'int');
    $restoreDocs   = GETPOST('restore_docs', 'int');

    if ($confirmation !== 'RESTORE') {
        setEventMessages($langs->trans('ErrorInvalidConfirmation'), null, 'errors');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $backupId);
        exit;
    }

    $restore = new Restore($db);
    $result  = $restore->run($backupId, $user, (bool) $restoreDb, (bool) $restoreDocs);

    if ($result > 0) {
        setEventMessages($langs->trans('RestoreSuccess'), null, 'mesgs');
        setEventMessages($langs->trans('PreRestoreBackupCreated'), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('RestoreFailed') . ': ' . $restore->error, null, 'errors');
    }

    header('Location: ' . dol_buildpath('/backuprestore/index.php', 1));
    exit;
}

// ---- Page output ----
llxHeader('', $langs->trans('RestoreBackup'));

print load_fiche_titre($langs->trans('RestoreConfirmTitle'), '', 'technic');

// Warning banner
print '<div class="error" style="padding:15px; margin-bottom:20px;">';
print '<strong>⚠ ' . $langs->trans('RestoreWarning') . '</strong>';
print '</div>';

// Backup details
print '<table class="border centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans('BackupRef') . ': ' . dol_escape_htmltag($backup->ref) . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $langs->trans('BackupDate') . '</td>';
print '<td>' . dol_print_date($backup->date_creation, 'dayhour') . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('BackupType') . '</td>';
print '<td>' . dol_escape_htmltag(backuprestoreGetBackupTypeLabel($backup->backup_type)) . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('StorageType') . '</td>';
print '<td>' . dol_escape_htmltag(backuprestoreGetStorageTypeLabel($backup->storage_type)) . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('BackupFileSize') . '</td>';
print '<td>' . backuprestoreFormatFileSize($backup->file_size) . '</td>';
print '</tr>';

if (!empty($backup->dolibarr_version)) {
    print '<tr class="oddeven">';
    print '<td>' . $langs->trans('DolibarrVersion') . '</td>';
    print '<td>' . dol_escape_htmltag($backup->dolibarr_version) . '</td>';
    print '</tr>';
}

if (!empty($backup->note)) {
    print '<tr class="oddeven">';
    print '<td>' . $langs->trans('BackupNote') . '</td>';
    print '<td>' . dol_escape_htmltag($backup->note) . '</td>';
    print '</tr>';
}

print '</table>';

print '<br>';

// Restore form
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . $backupId . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="doRestore">';

print '<table class="border centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans('RestoreOptions') . '</td></tr>';

// What to restore
$hasDb   = ($backup->backup_type === 'full' || $backup->backup_type === 'database');
$hasDocs = ($backup->backup_type === 'full' || $backup->backup_type === 'documents');

print '<tr class="oddeven">';
print '<td class="titlefield">' . $langs->trans('IncludeDatabase') . '</td>';
print '<td>';
if ($hasDb) {
    print '<input type="checkbox" name="restore_db" value="1" checked' . (!$hasDb ? ' disabled' : '') . '>';
} else {
    print '<span class="opacitymedium">' . $langs->trans('NotAvailableInThisBackup') . '</span>';
    print '<input type="hidden" name="restore_db" value="0">';
}
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('IncludeDocuments') . '</td>';
print '<td>';
if ($hasDocs) {
    print '<input type="checkbox" name="restore_docs" value="1" checked' . (!$hasDocs ? ' disabled' : '') . '>';
} else {
    print '<span class="opacitymedium">' . $langs->trans('NotAvailableInThisBackup') . '</span>';
    print '<input type="hidden" name="restore_docs" value="0">';
}
print '</td></tr>';

// Confirmation field
print '<tr class="oddeven">';
print '<td class="fieldrequired">' . $langs->trans('RestoreConfirmText') . '</td>';
print '<td>';
print '<input type="text" name="restore_confirm" class="flat" placeholder="' . dol_escape_htmltag($langs->trans('RestoreConfirmPlaceholder')) . '" style="width:200px; border:2px solid #e05353;" autocomplete="off">';
print '</td></tr>';

print '</table>';

print '<br>';
print '<div class="center">';
print '<input type="submit" class="butActionDelete" value="' . $langs->trans('RestoreButton') . '" onclick="return confirm(\'' . dol_escape_js($langs->trans('RestoreWarning')) . '\')">';
print ' &nbsp; ';
print '<a class="button button-cancel" href="' . dol_buildpath('/backuprestore/index.php', 1) . '">' . $langs->trans('Cancel') . '</a>';
print '</div>';

print '</form>';

llxFooter();
$db->close();
