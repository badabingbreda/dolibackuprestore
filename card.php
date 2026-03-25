<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    backuprestore/card.php
 * \ingroup backuprestore
 * \brief   Backup detail page
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

// Security check
if (empty($conf->backuprestore->enabled)) {
    accessforbidden('Module not enabled');
}
if (empty($user->rights->backuprestore->read)) {
    accessforbidden();
}

$langs->loadLangs(array('backuprestore@backuprestore'));

$backupId = GETPOST('id', 'int');

if ($backupId <= 0) {
    header('Location: ' . dol_buildpath('/backuprestore/index.php', 1));
    exit;
}

$backup = new Backup($db);
if ($backup->fetch($backupId) <= 0) {
    setEventMessages($langs->trans('BackupFileNotFound'), null, 'errors');
    header('Location: ' . dol_buildpath('/backuprestore/index.php', 1));
    exit;
}

// ---- Page output ----
llxHeader('', $langs->trans('BackupRestore') . ' - ' . dol_escape_htmltag($backup->ref));

print load_fiche_titre(dol_escape_htmltag($backup->ref), '', 'technic');

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

print '<tr class="oddeven">';
print '<td>' . $langs->trans('BackupStatus') . '</td>';
print '<td>' . Backup::getStatusBadge($backup->status) . '</td>';
print '</tr>';

if (!empty($backup->storage_path)) {
    print '<tr class="oddeven">';
    print '<td>' . $langs->trans('BackupStoragePath') . '</td>';
    print '<td>' . dol_escape_htmltag($backup->storage_path) . '</td>';
    print '</tr>';
}

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

// ---- Actions ----
print '<br>';
print '<div class="tabsAction">';

if ((int) $backup->status === Backup::STATUS_SUCCESS && !empty($user->rights->backuprestore->restore)) {
    print '<a class="butAction" href="' . dol_buildpath('/backuprestore/restore.php', 1) . '?id=' . $backup->id . '">' . $langs->trans('RestoreBackup') . '</a>';
}

if (!empty($user->rights->backuprestore->delete)) {
    print '<a class="butActionDelete" href="' . dol_buildpath('/backuprestore/index.php', 1) . '?action=delete&id=' . $backup->id . '&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans('ConfirmDeleteBackup', $backup->ref)) . '\')">' . $langs->trans('DeleteBackup') . '</a>';
}

print '<a class="butAction" href="' . dol_buildpath('/backuprestore/index.php', 1) . '">' . $langs->trans('BackupList') . '</a>';
print '</div>';

llxFooter();
$db->close();
