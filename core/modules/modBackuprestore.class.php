<?php
/* Copyright (C) 2024 Your Name <your@email.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \defgroup   backuprestore     Module BackupRestore
 * \brief      Module to backup and restore Dolibarr database and documents.
 * \file       htdocs/custom/backuprestore/backuprestore.php
 * \ingroup    backuprestore
 * \brief      Description and activation file for the module BackupRestore
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module BackupRestore
 */
class modBackuprestore extends DolibarrModules
{
    /**
     * Constructor. Define names, constants, directories, boxes, permissions
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // ---- Module identification ----
        // Unique numeric ID for this module (must be > 500000 for custom modules)
        $this->numero = 500100;

        // Module family (used for grouping in the module list)
        $this->family = "technic";

        // Module position in the family list
        $this->module_position = '90';

        // Module name (no spaces, no special chars)
        // Must match the directory name exactly (case-sensitive on Linux)
        $this->name = 'backuprestore';

        // Module description (shown in module list)
        $this->description = "BackupRestoreDescription";

        // Possible values for version: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or version string like 'x.y.z'
        $this->version = '1.0.0';

        // Key used in llx_const table to save module status enabled/disabled
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);

        // Name of image file used for this module
        $this->picto = 'technic';

        // ---- Module parts ----
        // Set this to 1 if module has its own trigger directory (core/triggers)
        $this->module_parts = array(
            'triggers' => 0,
            'login'    => 0,
            'substitutions' => 0,
            'menus'    => 0,
            'tpl'      => 0,
            'barcode'  => 0,
            'models'   => 0,
            'theme'    => 0,
            'css'      => array(),
            'js'       => array(),
            'hooks'    => array(),
            'moduleforexternal' => 0,
        );

        // ---- Data directories ----
        $this->dirs = array(
            "/backuprestore/temp",
            "/backuprestore/backups",
        );

        // ---- Config page ----
        $this->config_page_url = array("setup.php@backuprestore");

        // ---- Dependencies ----
        $this->hidden = false;
        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("backuprestore@backuprestore");
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(14, 0);
        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();

        // ---- Constants ----
        $this->const = array(
            // 0 => array('BACKUPRESTORE_STORAGE_TYPE', 'chaine', 'local', 'Storage type: local, ftp, sftp', 0, 'current', 1),
            // 1 => array('BACKUPRESTORE_LOCAL_PATH', 'chaine', '', 'Local backup storage path', 0, 'current', 1),
            // 2 => array('BACKUPRESTORE_RETENTION_DAYS', 'chaine', '30', 'Number of days to keep backups', 0, 'current', 1),
        );

        // ---- New pages on tabs ----
        $this->tabs = array();

        // ---- Dictionaries ----
        $this->dictionaries = array();

        // ---- Boxes/Widgets ----
        $this->boxes = array();

        // ---- Cronjobs ----
        $this->cronjobs = array(
            0 => array(
                'label'     => 'AutomaticBackupCron',
                'jobtype'   => 'method',
                'class'     => '/custom/backuprestore/class/backup.class.php',
                'objectname' => 'Backup',
                'method'    => 'runScheduledBackup',
                'parameters' => '',
                'comment'   => 'Automatic scheduled backup of database and documents',
                'frequency' => 1,
                'unitfrequency' => 86400, // daily
                'status'    => 1,
                'test'      => true,
                'priority'  => 50,
            ),
        );

        // ---- Permissions ----
        $this->rights = array();
        $r = 0;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'ReadBackupRestore';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'CreateBackup';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'DeleteBackup';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'delete';
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'RestoreBackup';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'restore';
        $r++;

        // ---- Main menu entries ----
        $this->menu = array();
        $r = 0;

        // Top-level menu entry
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=tools',
            'type'     => 'left',
            'titre'    => 'BackupRestore',
            'prefix'   => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"', false, 0, 0, '', 'width20'),
            'mainmenu' => 'tools',
            'leftmenu' => 'backuprestore',
            'url'      => '/backuprestore/index.php',
            'langs'    => 'backuprestore@backuprestore',
            'position' => 1000 + $r,
            'enabled'  => '$conf->backuprestore->enabled',
            'perms'    => '$user->rights->backuprestore->read',
            'target'   => '',
            'user'     => 0,
        );

        // Sub-menu: Backup list
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=tools,fk_leftmenu=backuprestore',
            'type'     => 'left',
            'titre'    => 'BackupList',
            'mainmenu' => 'tools',
            'leftmenu' => 'backuprestore_list',
            'url'      => '/backuprestore/index.php',
            'langs'    => 'backuprestore@backuprestore',
            'position' => 1000 + $r,
            'enabled'  => '$conf->backuprestore->enabled',
            'perms'    => '$user->rights->backuprestore->read',
            'target'   => '',
            'user'     => 0,
        );

        // Sub-menu: New backup
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=tools,fk_leftmenu=backuprestore',
            'type'     => 'left',
            'titre'    => 'NewBackup',
            'mainmenu' => 'tools',
            'leftmenu' => 'backuprestore_new',
            'url'      => '/backuprestore/index.php?action=create',
            'langs'    => 'backuprestore@backuprestore',
            'position' => 1000 + $r,
            'enabled'  => '$conf->backuprestore->enabled',
            'perms'    => '$user->rights->backuprestore->write',
            'target'   => '',
            'user'     => 0,
        );
    }

    /**
     * Function called when module is enabled.
     * The init function adds tables, constants, boxes, permissions and menus
     * (defined in constructor) into Dolibarr database.
     * It also creates data directories.
     *
     * @param string $options Options when enabling module ('', 'noboxes')
     * @return int             1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        global $conf, $langs;

        // Resolve the module root directory regardless of whether this file
        // is loaded from backuprestore/ or backuprestore/core/modules/
        $moduleFile = __FILE__;
        if (strpos(str_replace('\\', '/', $moduleFile), '/core/modules/') !== false) {
            // Loaded from core/modules/ subdirectory — go up two levels
            $moduleRoot = dirname(dirname(dirname($moduleFile)));
        } else {
            // Loaded from module root
            $moduleRoot = dirname($moduleFile);
        }

        // Build path relative to DOL_DOCUMENT_ROOT for _load_tables()
        $sqlDir = str_replace('\\', '/', str_replace(DOL_DOCUMENT_ROOT, '', $moduleRoot)) . '/sql/';
        if (strpos($sqlDir, '/') !== 0) {
            $sqlDir = '/' . $sqlDir;
        }

        $result = $this->_load_tables($sqlDir);
        if ($result < 0) {
            return -1;
        }

        // Create data directories with web-server-writable permissions (0755)
        // DOL_DATA_ROOT is a PHP constant defined by Dolibarr's main.inc.php
        if (defined('DOL_DATA_ROOT') && DOL_DATA_ROOT) {
            foreach ($this->dirs as $dir) {
                $fullPath = DOL_DATA_ROOT . $dir;
                if (!is_dir($fullPath)) {
                    @mkdir($fullPath, 0755, true);
                }
                // Ensure the directory is writable (chmod in case it already existed)
                @chmod($fullPath, 0755);
            }
        }

        $result = $this->_init(array(), $options);
        if ($result <= 0) {
            return $result;
        }

        // After _init() seeds the cronjob row (always with the hard-coded daily defaults),
        // immediately overwrite frequency+unitfrequency with whatever the admin has configured
        // so that re-activating the module respects the saved setting.
        $savedInterval = (isset($conf->global->BACKUPRESTORE_CRON_INTERVAL) && strlen($conf->global->BACKUPRESTORE_CRON_INTERVAL) > 0)
            ? (int) $conf->global->BACKUPRESTORE_CRON_INTERVAL
            : 86400; // fall back to daily when no setting exists yet

        $cronStatus   = ($savedInterval === 0) ? 0 : 1;
        $cronUnitFreq = ($savedInterval  >  0) ? $savedInterval : 86400;

        // Identify the row by label+entity (Dolibarr's unique key for cronjobs).
        // Also set datenextrun = now + interval so the job doesn't fire immediately
        // after module activation when a non-default frequency is configured.
        if ($savedInterval > 0) {
            $newNextRun = $this->db->idate(dol_now() + $cronUnitFreq);
            $this->db->query(
                "UPDATE " . MAIN_DB_PREFIX . "cronjob"
                . " SET status = "      . (int) $cronStatus
                . ", frequency = 1"
                . ", unitfrequency = '" . (int) $cronUnitFreq . "'"
                . ", datenextrun = '"   . $this->db->escape($newNextRun) . "'"
                . " WHERE label = 'AutomaticBackupCron'"
                . " AND entity = "      . (int) $conf->entity
            );
        } else {
            $this->db->query(
                "UPDATE " . MAIN_DB_PREFIX . "cronjob"
                . " SET status = 0"
                . " WHERE label = 'AutomaticBackupCron'"
                . " AND entity = "  . (int) $conf->entity
            );
        }

        return $result;
    }

    /**
     * Function called when module is disabled.
     * Remove from database constants, boxes and permissions from Dolibarr database.
     * Data directories are not deleted.
     *
     * @param string $options Options when disabling module ('', 'noboxes')
     * @return int             1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
