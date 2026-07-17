<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — hook.php
 * Rotinas de instalação e desinstalação do plugin.
 * -----------------------------------------------------------------------
 */

use GlpiPlugin\Brasilferiados\Sync;

// -----------------------------------------------------------------------
// INSTALL — Cria as tabelas de configuração e registra a ação automática
// -----------------------------------------------------------------------
function plugin_brasilferiados_install() {
    global $DB;
    $migration = new Migration(PLUGIN_BRASILFERIADOS_VERSION);

    // 1) Tabela de configuração geral do plugin
    if (!$DB->tableExists('glpi_plugin_brasilferiados_configs')) {
        $query = "CREATE TABLE `glpi_plugin_brasilferiados_configs` (
            `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `is_active`         TINYINT      NOT NULL DEFAULT 0,
            `calendars_id`      INT UNSIGNED NOT NULL DEFAULT 0,
            `api_provider`      VARCHAR(50)  NOT NULL DEFAULT 'brasilapi',
            `api_token`         VARCHAR(255) NOT NULL DEFAULT '',
            `api_uf`            VARCHAR(2)   NOT NULL DEFAULT '',
            `api_cidade_ibge`   VARCHAR(10)  NOT NULL DEFAULT '',
            `gov_federal_text`  TEXT         NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $DB->doQuery($query);

        $DB->insert('glpi_plugin_brasilferiados_configs', [
            'id'              => 1,
            'is_active'       => 0,
            'calendars_id'    => 0,
            'api_provider'    => 'brasilapi',
            'api_token'       => '',
            'api_uf'          => '',
            'api_cidade_ibge' => '',
            'gov_federal_text'=> '',
        ]);
    } else {
        $migration->addField('glpi_plugin_brasilferiados_configs', 'api_provider', "VARCHAR(50) NOT NULL DEFAULT 'brasilapi'");
        $migration->addField('glpi_plugin_brasilferiados_configs', 'api_token', "VARCHAR(255) NOT NULL DEFAULT ''");
        $migration->addField('glpi_plugin_brasilferiados_configs', 'api_uf', "VARCHAR(2) NOT NULL DEFAULT ''");
        $migration->addField('glpi_plugin_brasilferiados_configs', 'api_cidade_ibge', "VARCHAR(10) NOT NULL DEFAULT ''");
        $migration->addField('glpi_plugin_brasilferiados_configs', 'gov_federal_text', "TEXT NULL");
    }

    // 2) Tabela de feriados locais (CRUD)
    if (!$DB->tableExists('glpi_plugin_brasilferiados_locais')) {
        $query = "CREATE TABLE `glpi_plugin_brasilferiados_locais` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `dia`           INT          NOT NULL,
            `mes`           INT          NOT NULL,
            `nome`          VARCHAR(255) NOT NULL DEFAULT '',
            `is_perpetual`  TINYINT      NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $DB->doQuery($query);
    }

    $migration->executeMigration();

    // 3) Registrar ação automática no CronTask
    $crontask = new CronTask();
    if (!$crontask->getFromDBbyName(Sync::class, 'BrasilFeriados') && !$crontask->getFromDBbyName('PluginBrasilferiadosSync', 'BrasilFeriados')) {
        CronTask::register(
            Sync::class,
            'BrasilFeriados',
            DAY_TIMESTAMP,
            [
                'comment'   => 'Sincronizar feriados brasileiros via API configurada',
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
    if ($crontask->getFromDBbyName(Sync::class, 'BrasilFeriados')) {
        $crontask->delete(['id' => $crontask->fields['id']]);
    }
    // Delete old legacy class crontask if exists
    if ($crontask->getFromDBbyName('PluginBrasilferiadosSync', 'BrasilFeriados')) {
        $crontask->delete(['id' => $crontask->fields['id']]);
    }

    return true;
}
