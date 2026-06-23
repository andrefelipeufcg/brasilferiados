<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — hook.php
 * Rotinas de instalação e desinstalação do plugin.
 * -----------------------------------------------------------------------
 */

// -----------------------------------------------------------------------
// INSTALL — Cria as tabelas de configuração e registra a ação automática
// -----------------------------------------------------------------------
function plugin_brasilferiados_install() {
    global $DB;

    // 1) Tabela de configuração geral do plugin
    if (!$DB->tableExists('glpi_plugin_brasilferiados_configs')) {
        $query = "CREATE TABLE `glpi_plugin_brasilferiados_configs` (
            `id`              INT          NOT NULL AUTO_INCREMENT,
            `is_active`       TINYINT      NOT NULL DEFAULT 0,
            `calendars_id`    INT          NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $stmt = $DB->prepare($query);
        $DB->executeStatement($stmt);

        $DB->insert('glpi_plugin_brasilferiados_configs', [
            'id'            => 1,
            'is_active'     => 0,
            'calendars_id'  => 0,
        ]);
    }

    // 2) Tabela de feriados locais (CRUD)
    if (!$DB->tableExists('glpi_plugin_brasilferiados_locais')) {
        $query = "CREATE TABLE `glpi_plugin_brasilferiados_locais` (
            `id`            INT          NOT NULL AUTO_INCREMENT,
            `dia`           INT          NOT NULL,
            `mes`           INT          NOT NULL,
            `nome`          VARCHAR(255) NOT NULL DEFAULT '',
            `is_perpetual`  TINYINT      NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $stmt = $DB->prepare($query);
        $DB->executeStatement($stmt);
    }

    // 3) Registrar ação automática no CronTask (se ainda não existir)
    $crontask = new CronTask();
    if (!$crontask->getFromDBbyName('PluginBrasilferiadosSync', 'BrasilFeriados')) {
        CronTask::register(
            'PluginBrasilferiadosSync',
            'BrasilFeriados',
            DAY_TIMESTAMP,
            [
                'comment'   => 'Sincronizar feriados brasileiros via Brasil API',
                'mode'      => CronTask::MODE_EXTERNAL,
                'state'     => CronTask::STATE_WAITING,
                'hourmin'   => 0,
                'hourmax'   => 2,
            ]
        );
    }

    return true;
}

// -----------------------------------------------------------------------
// UNINSTALL — Remove tabelas e ação automática
// -----------------------------------------------------------------------
function plugin_brasilferiados_uninstall() {
    global $DB;

    if ($DB->tableExists('glpi_plugin_brasilferiados_configs')) {
        $DB->dropTable('glpi_plugin_brasilferiados_configs');
    }

    if ($DB->tableExists('glpi_plugin_brasilferiados_locais')) {
        $DB->dropTable('glpi_plugin_brasilferiados_locais');
    }

    $crontask = new CronTask();
    if ($crontask->getFromDBbyName('PluginBrasilferiadosSync', 'BrasilFeriados')) {
        $crontask->delete(['id' => $crontask->fields['id']]);
    }

    return true;
}
