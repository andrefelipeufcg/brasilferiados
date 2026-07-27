<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — front/config.form.php
 * Interface de configuração: provedor de API, automação, calendário,
 * grid de feriados locais e sincronização manual.
 * -----------------------------------------------------------------------
 */

$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
include $inc;

Session::checkRight("config", UPDATE);

global $CFG_GLPI;
$form_url = $CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php';



// -----------------------------------------------------------------------
// Carrega (ou cria) o registro de configuração
// -----------------------------------------------------------------------
$config = new \GlpiPlugin\Brasilferiados\Sync();
if (!$config->getFromDB(1)) {
    global $DB;
    $DB->insert('glpi_plugin_brasilferiados_configs', [
        'id'              => 1,
        'is_active'       => 0,
        'calendars_id'    => 0,
        'api_provider'    => 'brasilapi',
        'api_token'       => '',
        'api_uf'          => '',
        'api_cidade_ibge' => '',
    ]);
    $config->getFromDB(1);
}

// -----------------------------------------------------------------------
// POST: Salvar configuração
// -----------------------------------------------------------------------
if (isset($_POST['update_config'])) {
    // GLPI 11+ valida e consome o CSRF automaticamente no middleware
    if (!class_exists('Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener')) {
        Session::checkCSRF($_POST);
    }
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $apiProvider    = $_POST['api_provider'] ?? 'brasilapi';
    $apiToken       = trim($_POST['api_token'] ?? '');
    $apiUf          = trim($_POST['api_uf'] ?? '');
    $apiCidadeIbge  = trim($_POST['api_cidade_ibge'] ?? '');

    $govFederalText = trim($_POST['gov_federal_text'] ?? '');

    // Validar provedor
    $validProviders = array_keys(\GlpiPlugin\Brasilferiados\Sync::getProviderList());
    if (!in_array($apiProvider, $validProviders)) {
        $apiProvider = 'brasilapi';
    }

    $provider = \GlpiPlugin\Brasilferiados\Sync::getProvider($apiProvider);
    $validationError = $provider->validateConfig($_POST);

    if ($validationError !== '') {
        Session::addMessageAfterRedirect($validationError, false, ERROR);
        Html::redirect($form_url);
    }

    $updateData = [
        'id'           => 1,
        'is_active'    => $isActive,
        'api_provider' => $apiProvider,
    ];

    // 1. Descobre todos os campos possíveis de TODOS os provedores e os inicializa vazios
    $allProviders = \GlpiPlugin\Brasilferiados\Sync::getProviderList();
    foreach (array_keys($allProviders) as $provKey) {
        $provInstance = \GlpiPlugin\Brasilferiados\Sync::getProvider($provKey);
        $provFields = array_column($provInstance->getConfigFields(), 'name');
        foreach ($provFields as $fName) {
            if (!isset($updateData[$fName])) {
                $updateData[$fName] = '';
            }
        }
    }

    // 2. Preenche APENAS os campos do provedor atual com o que veio do form
    $currentProviderFields = array_column($provider->getConfigFields(), 'name');
    foreach ($currentProviderFields as $fieldName) {
        $val = trim($_POST[$fieldName] ?? '');
        if ($fieldName === 'api_token' && !empty($val)) {
            $val = (new GLPIKey())->encrypt($val);
        }
        $updateData[$fieldName] = $val;
    }

    $config->update($updateData);

    // Habilita ou desabilita fisicamente o CronTask no motor do GLPI
    $crontask = new CronTask();
    if ($crontask->getFromDBbyName('PluginBrasilferiadosSync', 'BrasilFeriados')) {
        $state = $isActive ? CronTask::STATE_WAITING : CronTask::STATE_DISABLE;
        $crontask->update([
            'id'    => $crontask->fields['id'],
            'state' => $state
        ]);
    }

    Session::addMessageAfterRedirect(
        __('Configuração salva com sucesso.', 'brasilferiados'),
        true,
        INFO
    );
    
    global $CFG_GLPI;
    if ($apiProvider === 'importador_gov_federal') {
        Html::redirect($CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php?load_national=1');
    } else {
        Html::redirect($form_url);
    }
}

// -----------------------------------------------------------------------
// POST: Sincronização manual
// -----------------------------------------------------------------------
if (isset($_POST['sync_now'])) {
    // GLPI 11+ valida e consome o CSRF automaticamente no middleware
    if (!class_exists('Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener')) {
        Session::checkCSRF($_POST);
    }
    $year = (int)($_POST['sync_year'] ?? date('Y'));
    $loadedYear = (int)($_POST['loaded_year'] ?? 0);
    $manualCalendarId = (int)($_POST['manual_calendars_id'] ?? 0);
    $nationalHolidays = $_POST['national_holidays'] ?? [];

    if ($year < 2001 || $year > 2099) {
        Session::addMessageAfterRedirect(__('Por favor, informe um ano válido entre 2001 e 2099.', 'brasilferiados'), false, ERROR);
        Html::redirect($form_url);
    }

    $configCheck = new \GlpiPlugin\Brasilferiados\Sync();
    $configCheck->getFromDB(1);
    $isAct = (int)($configCheck->fields['is_active'] ?? 0);

    if (!$isAct) {
        // Automação desligada: O usuário precisa carregar o ano idêntico antes de sincronizar
        if ($year !== $loadedYear) {
            Session::addMessageAfterRedirect(
                sprintf(__("Você precisa 'Carregar Feriados' do ano %d no grid acima antes de sincronizar.", 'brasilferiados'), $year),
                false,
                ERROR
            );
            Html::redirect($form_url);
        }

        $nacionais = [];
        if (is_array($nationalHolidays)) {
            foreach ($nationalHolidays as $nh) {
                if (isset($nh['date']) && isset($nh['name'])) {
                    $item = [
                        'date' => $nh['date'],
                        'name' => $nh['name']
                    ];
                    if (isset($nh['is_perpetual'])) {
                        $item['is_perpetual'] = (int)$nh['is_perpetual'];
                    }
                    $nacionais[] = $item;
                }
            }
        }
        $resultado = \GlpiPlugin\Brasilferiados\Sync::sincronizarFeriados($year, $nacionais, false, $manualCalendarId);
    } else {
        // Automação ativada: Ignora exclusões manuais e bate na API nativamente
        $resultado = \GlpiPlugin\Brasilferiados\Sync::sincronizarFeriados($year, null, false, $manualCalendarId);
    }

    $msg = sprintf(
        __('Ano %d — Inseridos: %d | Ignorados (duplicados): %d', 'brasilferiados'),
        $year,
        $resultado['inseridos'],
        $resultado['ignorados']
    );
    Session::addMessageAfterRedirect($msg, true, INFO);

    foreach ($resultado['erros'] as $err) {
        Session::addMessageAfterRedirect($err, false, ERROR);
    }

    Html::redirect($form_url);
}

// -----------------------------------------------------------------------
// Lógica para renderização de Feriados (GET / POST load_national)
// -----------------------------------------------------------------------
$csrfToken = Session::getNewCSRFToken();
$isActive       = (int)($config->fields['is_active'] ?? 0);
$calendarsId    = (int)($config->fields['calendars_id'] ?? 0);
$apiProvider    = $config->fields['api_provider'] ?? 'brasilapi';
$apiToken       = $config->fields['api_token'] ?? '';
if (!empty($apiToken)) {
    $apiToken = (new GLPIKey())->decrypt($apiToken);
}
$apiUf          = $config->fields['api_uf'] ?? '';
$apiCidadeIbge  = $config->fields['api_cidade_ibge'] ?? '';
$govFederalText = $config->fields['gov_federal_text'] ?? '';
$anoAtual       = (int)date('Y');

$loadedYear = $anoAtual;
$apiHolidays = [];
$isLoaded = false;

if (isset($_REQUEST['load_national'])) {
    $loadedYear = (int)($_REQUEST['load_year'] ?? $anoAtual);
    if ($apiProvider === 'importador_gov_federal' && preg_match('/no ano de\s*(\d{4})/i', $govFederalText, $matches)) {
        $loadedYear = (int)$matches[1];
    }
    $apiResult = \GlpiPlugin\Brasilferiados\Sync::fetchFromProvider($loadedYear);
    $apiHolidays = $apiResult['feriados'];
    if (!empty($apiResult['erros'])) {
        foreach ($apiResult['erros'] as $err) {
            Session::addMessageAfterRedirect($err, false, ERROR);
        }
        Html::redirect($form_url);
    }
    $isLoaded = true;
} else if ($isActive) {
    // Se automação está ativa, carrega sempre o ano atual automaticamente para consulta
    $loadedYear = $anoAtual;
    $apiResult = \GlpiPlugin\Brasilferiados\Sync::fetchFromProvider($loadedYear);
    $apiHolidays = $apiResult['feriados'];
    $isLoaded = true;
}

// -----------------------------------------------------------------------
// RENDERIZAÇÃO DA PÁGINA (VIA TWIG)
// -----------------------------------------------------------------------
Html::header(__('Brasil Feriados', 'brasilferiados'), $_SERVER['PHP_SELF'], 'config', 'plugins');

// Obter nome do provedor ativo para exibição
$providerList = \GlpiPlugin\Brasilferiados\Sync::getProviderList();
$providerLabel = $providerList[$apiProvider] ?? 'Brasil API';

// Prepara os campos de todos os provedores
$allProviderFields = [];
foreach (array_keys($providerList) as $key) {
    $p = \GlpiPlugin\Brasilferiados\Sync::getProvider($key);
    $allProviderFields[$key] = $p->getConfigFields();
}

$params = [
    'form_url'          => $form_url,
    'local_form_url'    => $CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/local.form.php',
    'csrfToken'         => $csrfToken,
    'apiProvider'       => $apiProvider,
    'providerLabel'     => $providerLabel,
    'providerList'      => $providerList,
    'allProviderFields' => $allProviderFields,
    'apiToken'          => $apiToken,
    'apiUf'             => $apiUf,
    'apiCidadeIbge'     => $apiCidadeIbge,
    'govFederalText'    => $govFederalText,
    'isActive'          => $isActive,
    'loadedYear'        => $loadedYear,
    'apiHolidays'       => $apiHolidays,
    'isLoaded'          => $isLoaded,
    'feriadosLocais'    => \GlpiPlugin\Brasilferiados\Local::listarTodos(),
    'calendarsId'       => $calendarsId,
    'calendarDropdown'  => Calendar::dropdown(['name' => 'calendars_id', 'value' => $calendarsId, 'display' => false])
];

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@brasilferiados/config.html.twig', $params);

Html::footer();
