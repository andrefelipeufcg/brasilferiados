<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — Plugin GLPI 10+
 * Sincroniza feriados nacionais (Brasil API) e feriados locais
 * recorrentes na tabela nativa glpi_holidays.
 * -----------------------------------------------------------------------
 * @package   brasilferiados
 * @author    andrefelipeufcg
 * @license   GPLv3+
 * @link      https://github.com/andrefelipeufcg/brasilferiados
 * -----------------------------------------------------------------------
 */

define('PLUGIN_BRASILFERIADOS_VERSION', '1.1.4');
define('PLUGIN_BRASILFERIADOS_MIN_GLPI', '10.0.0');

// -----------------------------------------------------------------------
// Inicialização — chamada pelo core toda vez que o plugin está ativo
// -----------------------------------------------------------------------
function plugin_init_brasilferiados() {
    global $PLUGIN_HOOKS;

    // Conformidade CSRF (obrigatório no GLPI 11)
    $PLUGIN_HOOKS['csrf_compliant']['brasilferiados'] = true;

    // Página de configuração acessível em Configurar > Plugins
    $PLUGIN_HOOKS['config_page']['brasilferiados'] = 'front/config.form.php';

    // Registra as classes do plugin com PSR-4
    Plugin::registerClass(\GlpiPlugin\Brasilferiados\Sync::class, ['addtabon' => []]);
    Plugin::registerClass(\GlpiPlugin\Brasilferiados\Local::class);

    // Registra o hook de cron
    $PLUGIN_HOOKS['cron']['brasilferiados'] = [
        \GlpiPlugin\Brasilferiados\Sync::class => [
            'description' => __('Sincronizar feriados brasileiros via API configurada', 'brasilferiados'),
            'parameter'   => null,
        ],
    ];

    // Oculta o api_token no GLPI para evitar vazamento em logs, exportações de diagnóstico e na API REST
    if (class_exists('Hooks') && defined('Hooks::UNDISCLOSED_CONFIG_VALUE')) {
        $PLUGIN_HOOKS[Hooks::UNDISCLOSED_CONFIG_VALUE]['brasilferiados'] = function () {
            return ['api_token'];
        };
    }
}

// -----------------------------------------------------------------------
// Metadados do plugin — exibidos na tela Configurar > Plugins
// -----------------------------------------------------------------------
function plugin_version_brasilferiados() {
    return [
        'name'           => 'Brasil Feriados',
        'version'        => PLUGIN_BRASILFERIADOS_VERSION,
        'author'         => 'andrefelipeufcg',
        'license'        => 'GPL v3+',
        'homepage'       => 'https://github.com/andrefelipeufcg/brasilferiados',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_BRASILFERIADOS_MIN_GLPI,
            ],
        ],
    ];
}

// -----------------------------------------------------------------------
// Pré-requisitos — verificados antes de permitir "Instalar"
// -----------------------------------------------------------------------
function plugin_brasilferiados_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_BRASILFERIADOS_MIN_GLPI, '<')) {
        echo sprintf(__('Este plugin requer GLPI %s ou superior.', 'brasilferiados'), PLUGIN_BRASILFERIADOS_MIN_GLPI);
        return false;
    }
    if (!function_exists('curl_init')) {
        echo __('A extensão PHP cURL é obrigatória.', 'brasilferiados');
        return false;
    }
    return true;
}

// -----------------------------------------------------------------------
// Verificação de configuração — chamada após a instalação
// -----------------------------------------------------------------------
function plugin_brasilferiados_check_config() {
    return true;
}
