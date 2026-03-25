<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    backuprestore/index.php
 * \ingroup backuprestore
 * \brief   Main page: backup list and new backup form
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
require_once __DIR__ . '/lib/backuprestore.lib.php';
require_once __DIR__ . '/class/backup.class.php';

// Security check
if (empty($conf->backuprestore->enabled)) {
    accessforbidden('Module not enabled');
}
if (empty($user->rights->backuprestore->read)) {
    accessforbidden();
}

$langs->loadLangs(array('backuprestore@backuprestore'));

$action     = GETPOST('action', 'aZ09');
$backupId   = GETPOST('id', 'int');
$sortfield  = GETPOST('sortfield', 'aZ09') ?: 'date_creation';
$sortorder  = GETPOST('sortorder', 'aZ09') ?: 'DESC';

// ---- Handle actions ----

// Create a new backup (only execute on POST submission, not on GET form display)
if ($action === 'create' && !empty($user->rights->backuprestore->write) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('checkToken')) {
        checkToken();
    }
    $backupType  = GETPOST('backup_type', 'aZ09') ?: 'full';
    $storageType = GETPOST('storage_type', 'aZ09') ?: (!empty($conf->global->BACKUPRESTORE_STORAGE_TYPE) ? $conf->global->BACKUPRESTORE_STORAGE_TYPE : 'local');
    $note        = GETPOST('note', 'restricthtml');

    $backup = new Backup($db);
    try {
        $result = $backup->run($user, $backupType, $storageType, $note);
    } catch (Throwable $e) {
        $result = -1;
        $backup->error = 'Fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        dol_syslog('BackupRestore::create - Caught fatal: ' . $backup->error, LOG_ERR);
    }

    if ($result > 0) {
        setEventMessages($langs->trans('BackupSuccess'), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('BackupFailed') . ': ' . $backup->error, null, 'errors');
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Delete a backup (token is always in URL for delete, so check on GET too)
if ($action === 'delete' && $backupId > 0 && !empty($user->rights->backuprestore->delete)) {
    if (function_exists('checkToken')) {
        checkToken('get');
    }
    $backup = new Backup($db);
    if ($backup->fetch($backupId) > 0) {
        $result = $backup->delete($user, true);
        if ($result > 0) {
            setEventMessages($langs->trans('BackupDeleted'), null, 'mesgs');
        } else {
            setEventMessages($langs->trans('BackupDeleteFailed') . ': ' . $backup->error, null, 'errors');
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ---- Page output ----
llxHeader('', $langs->trans('BackupRestore'));

print load_fiche_titre($langs->trans('BackupRestore'), '', 'technic');

// Show new backup form if action=create
if ($action === 'create' || GETPOST('show_form', 'int')) {
    print '<div class="div-table-responsive-no-min">';
    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="create">';

    print load_fiche_titre($langs->trans('BackupOptions'), '', '');
    print '<table class="border centpercent">';

    // Backup type
    print '<tr>';
    print '<td class="titlefield fieldrequired">' . $langs->trans('BackupType') . '</td>';
    print '<td>' . backuprestoreSelectBackupType('full', 'backup_type') . '</td>';
    print '</tr>';

    // Storage type
    $defaultStorage = !empty($conf->global->BACKUPRESTORE_STORAGE_TYPE) ? $conf->global->BACKUPRESTORE_STORAGE_TYPE : 'local';
    print '<tr>';
    print '<td class="fieldrequired">' . $langs->trans('StorageType') . '</td>';
    print '<td>' . backuprestoreSelectStorageType($defaultStorage, 'storage_type') . '</td>';
    print '</tr>';

    // Note
    print '<tr>';
    print '<td>' . $langs->trans('BackupComment') . '</td>';
    print '<td><input type="text" name="note" class="flat minwidth400" value="" placeholder="' . dol_escape_htmltag($langs->trans('BackupComment')) . '"></td>';
    print '</tr>';

    print '</table>';
    print '<br>';
    print '<div class="center">';
    print '<input type="submit" class="button button-save" value="' . $langs->trans('StartBackup') . '">';
    print ' &nbsp; ';
    print '<a class="button button-cancel" href="' . $_SERVER['PHP_SELF'] . '">' . $langs->trans('Cancel') . '</a>';
    print '</div>';
    print '</form>';
    print '</div>';
    print '<br>';
}

// ---- Backup list ----
$backup = new Backup($db);
$records = $backup->fetchAll($sortfield, $sortorder, 100, 0);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';

// Header row
print '<tr class="liste_titre">';
print_liste_field_titre('BackupRef',     $_SERVER['PHP_SELF'], 'ref',           '', '', '', $sortfield, $sortorder);
print_liste_field_titre('BackupDate',    $_SERVER['PHP_SELF'], 'date_creation', '', '', '', $sortfield, $sortorder);
print_liste_field_titre('BackupType',    $_SERVER['PHP_SELF'], 'backup_type',   '', '', '', $sortfield, $sortorder);
print_liste_field_titre('StorageType',   $_SERVER['PHP_SELF'], 'storage_type',  '', '', '', $sortfield, $sortorder);
print_liste_field_titre('BackupFileSize', $_SERVER['PHP_SELF'], 'file_size',    '', '', '', $sortfield, $sortorder);
print_liste_field_titre('BackupStatus',  $_SERVER['PHP_SELF'], 'status',        '', '', '', $sortfield, $sortorder);
print_liste_field_titre('', '', '', '', '', '', '', ''); // Actions column
print '</tr>';

if (empty($records) || (is_array($records) && count($records) === 0)) {
    print '<tr class="oddeven">';
    print '<td colspan="7" class="center opacitymedium">' . $langs->trans('NoBackupsFound') . '</td>';
    print '</tr>';
} elseif (is_array($records)) {
    foreach ($records as $record) {
        print '<tr class="oddeven">';

        // Ref — link to detail card page
        print '<td><a href="' . dol_buildpath('/backuprestore/card.php', 1) . '?id=' . $record->id . '">' . dol_escape_htmltag($record->ref) . '</a></td>';

        // Date
        print '<td>' . dol_print_date($record->date_creation, 'dayhour') . '</td>';

        // Backup type
        print '<td>' . dol_escape_htmltag(backuprestoreGetBackupTypeLabel($record->backup_type)) . '</td>';

        // Storage type
        print '<td>' . dol_escape_htmltag(backuprestoreGetStorageTypeLabel($record->storage_type)) . '</td>';

        // File size
        print '<td>' . backuprestoreFormatFileSize($record->file_size) . '</td>';

        // Status badge
        print '<td>' . Backup::getStatusBadge($record->status) . '</td>';

        // Actions
        print '<td class="nowrap right">';

        // Restore button (only for successful backups)
        if ((int) $record->status === Backup::STATUS_SUCCESS && !empty($user->rights->backuprestore->restore)) {
            print '<a class="butActionDelete" href="' . dol_buildpath('/backuprestore/restore.php', 1) . '?id=' . $record->id . '" title="' . dol_escape_htmltag($langs->trans('RestoreBackup')) . '">';
            print img_picto($langs->trans('RestoreBackup'), 'refresh');
            print '</a> ';
        }

        // Delete button
        if (!empty($user->rights->backuprestore->delete)) {
            print '<a class="butActionDelete" href="' . $_SERVER['PHP_SELF'] . '?action=delete&id=' . $record->id . '&token=' . newToken() . '" title="' . dol_escape_htmltag($langs->trans('DeleteBackup')) . '" onclick="return confirm(\'' . dol_escape_js($langs->trans('ConfirmDeleteBackup', $record->ref)) . '\')">';
            print img_picto($langs->trans('DeleteBackup'), 'delete');
            print '</a>';
        }

        print '</td>';
        print '</tr>';
    }
}

print '</table>';
print '</div>';

// ---- New backup button ----
if (empty($action) || $action !== 'create') {
    if (!empty($user->rights->backuprestore->write)) {
        print '<br>';
        print '<div class="tabsAction">';
        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=create">' . $langs->trans('NewBackup') . '</a>';
        print '</div>';
    }
}

llxFooter();
$db->close();
