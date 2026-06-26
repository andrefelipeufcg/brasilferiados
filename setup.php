<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — Plugin GLPI 11
 * Sincroniza feriados nacionais (Brasil API) e feriados locais
 * recorrentes na tabela nativa glpi_holidays.
 * -----------------------------------------------------------------------
 * @package   brasilferiados
 * @author    andrefelipeufcg
 * @license   GPLv3+
 * @link      https://github.com/andrefelipeufcg/brasilferiados
 * -----------------------------------------------------------------------
 */

define('PLUGIN_BRASILFERIADOS_VERSION', '1.1.0');
define('PLUGIN_BRASILFERIADOS_MIN_GLPI', '11.0.0');

// -----------------------------------------------------------------------
// Inicialização — chamada pelo core toda vez que o plugin está ativo
// -----------------------------------------------------------------------
function plugin_init_brasilferiados() {
    global $PLUGIN_HOOKS;

    // Conformidade CSRF (obrigatório no GLPI 11)
    $PLUGIN_HOOKS['csrf_compliant']['brasilferiados'] = true;

    // Página de configuração acessível em Configurar > Plugins
    $PLUGIN_HOOKS['config_page']['brasilferiados'] = 'front/config.form.php';

    // Registra as classes do plugin para que o autoloader do GLPI as encontre
    Plugin::registerClass('PluginBrasilferiadosSync', ['addtabon' => []]);
    Plugin::registerClass('PluginBrasilferiadosLocal');

    // Registra o hook de cron: o GLPI lê o array e associa o nome da
    // tarefa ao método estático cronBrasilFeriados() da classe indicada.
    $PLUGIN_HOOKS['cron']['brasilferiados'] = [
        'BrasilFeriados' => [
            'description' => 'Sincronizar feriados brasileiros via API configurada',
            'parameter'   => null,
        ],
    ];
}

// -----------------------------------------------------------------------
// Metadados do plugin — exibidos na tela Configurar > Plugins
// -----------------------------------------------------------------------
function plugin_version_brasilferiados() {
    return [
        'name'           => 'Brasil Feriados',
        'version'        => PLUGIN_BRASILFERIADOS_VERSION,
        'author'         => 'andrefelipeufcg',
        'license'        => 'GPLv3+',
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
        echo 'Este plugin requer GLPI 11.0.0 ou superior.';
        return false;
    }
    if (!function_exists('curl_init')) {
        echo 'A extensão PHP cURL é obrigatória.';
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
