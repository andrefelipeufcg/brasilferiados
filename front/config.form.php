<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — front/config.form.php
 * Interface de configuração: provedor de API, automação, calendário,
 * grid de feriados locais e sincronização manual.
 * Configuração completa com suporte nativo a abas do GLPI.
 * -----------------------------------------------------------------------
 */

$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
include $inc;

use GlpiPlugin\Brasilferiados\Sync;

Session::checkRight("config", UPDATE);
if (!Sync::canView()) {
    Session::addMessageAfterRedirect(__('Acesso negado.', 'brasilferiados'), false, ERROR);
    global $CFG_GLPI;
    Html::redirect($CFG_GLPI['root_doc'] ?? '/');
}

global $CFG_GLPI;
$form_url = \Plugin::getPhpDir('brasilferiados', false) . '/front/config.form.php';

// -----------------------------------------------------------------------
// Carrega (ou cria) o registro de configuração
// -----------------------------------------------------------------------
$config = new Sync();
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
// POST: Salvar configuração (Provedor ou Sincronização Automática)
// -----------------------------------------------------------------------
if (isset($_POST['update_config'])) {
    Session::checkValidSessionId();

    if (isset($_POST['update_automatic'])) {
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $config->update([
            'id'        => 1,
            'is_active' => $isActive,
        ]);

        $crontask = new CronTask();
        if ($crontask->getFromDBbyName('PluginBrasilferiadosSync', 'BrasilFeriados')) {
            $state = $isActive ? CronTask::STATE_WAITING : CronTask::STATE_DISABLE;
            $crontask->update([
                'id'    => $crontask->fields['id'],
                'state' => $state,
            ]);
        }

        Session::addMessageAfterRedirect(
            __('Configuração de sincronização automática salva com sucesso.', 'brasilferiados'),
            true,
            INFO
        );
        Session::setActiveTab(Sync::class, Sync::class . '$2');
        Html::redirect($form_url . '?tab=automatic');
    }

    // Salva configurações de Provedor
    $apiProvider = $_POST['api_provider'] ?? 'brasilapi';
    $validProviders = array_keys(Sync::getProviderList());
    if (!in_array($apiProvider, $validProviders)) {
        $apiProvider = 'brasilapi';
    }

    $provider = Sync::getProvider($apiProvider);
    $validationError = $provider->validateConfig($_POST);

    if ($validationError !== '') {
        Session::addMessageAfterRedirect($validationError, false, ERROR);
        Session::setActiveTab(Sync::class, Sync::class . '$1');
        Html::redirect($form_url . '?tab=provider');
    }

    $updateData = [
        'id'           => 1,
        'api_provider' => $apiProvider,
    ];

    if (!isset($_POST['update_provider']) && isset($_POST['is_active'])) {
        $updateData['is_active'] = 1;
    }

    $allProviders = Sync::getProviderList();
    foreach (array_keys($allProviders) as $provKey) {
        $provInstance = Sync::getProvider($provKey);
        $provFields = array_column($provInstance->getConfigFields(), 'name');
        foreach ($provFields as $fName) {
            if (!isset($updateData[$fName])) {
                $updateData[$fName] = '';
            }
        }
    }

    $currentProviderFields = array_column($provider->getConfigFields(), 'name');
    foreach ($currentProviderFields as $fieldName) {
        $val = trim($_POST[$fieldName] ?? '');
        if ($fieldName === 'api_token' && !empty($val)) {
            $val = (new GLPIKey())->encrypt($val);
        }
        $updateData[$fieldName] = $val;
    }

    $config->update($updateData);

    Session::addMessageAfterRedirect(
        __('Configuração do provedor salva com sucesso.', 'brasilferiados'),
        true,
        INFO
    );

    Session::setActiveTab(Sync::class, Sync::class . '$1');
    Html::redirect($form_url . '?tab=provider');
}

// -----------------------------------------------------------------------
// POST: Sincronização manual
// -----------------------------------------------------------------------
if (isset($_POST['sync_now'])) {
    Session::checkValidSessionId();

    $year = (int)($_POST['sync_year'] ?? date('Y'));
    $loadedYear = (int)($_POST['loaded_year'] ?? 0);
    $manualCalendarId = (int)($_POST['manual_calendars_id'] ?? 0);
    $nationalHolidays = $_POST['national_holidays'] ?? [];
    $localHolidays = $_POST['local_holidays'] ?? [];

    if ($year < 2001 || $year > 2099) {
        Session::addMessageAfterRedirect(__('Por favor, informe um ano válido entre 2001 e 2099.', 'brasilferiados'), false, ERROR);
        Session::setActiveTab(Sync::class, Sync::class . '$3');
        Html::redirect($form_url . '?tab=manual');
    }

    $configCheck = new Sync();
    $configCheck->getFromDB(1);
    $isAct = (int)($configCheck->fields['is_active'] ?? 0);

    if (!$isAct) {
        if ($year !== $loadedYear) {
            Session::addMessageAfterRedirect(
                sprintf(__("Você precisa 'Carregar Feriados' do ano %d no grid acima antes de sincronizar.", 'brasilferiados'), $year),
                false,
                ERROR
            );
            Session::setActiveTab(Sync::class, Sync::class . '$3');
            Html::redirect($form_url . '?tab=manual');
        }

        $nacionais = [];
        if (is_array($nationalHolidays)) {
            foreach ($nationalHolidays as $nh) {
                if (isset($nh['date'], $nh['name']) && (int)($nh['selected'] ?? 1) === 1) {
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
        $remocoes = [];
        $removeDates = $_POST['remove_holidays'] ?? [];
        if (is_array($removeDates)) {
            foreach ($removeDates as $rd) {
                if (isset($rd['date'])) {
                    $remocoes[] = ['date' => $rd['date']];
                }
            }
        }

        $resultado = Sync::sincronizarFeriados($year, $nacionais, false, $manualCalendarId, $localHolidays, $remocoes);
    } else {
        $resultado = Sync::sincronizarFeriados($year, null, false, $manualCalendarId);
    }

    $removidos = $resultado['removidos'] ?? 0;
    $msg = sprintf(
        __('Ano %d — Inseridos: %d | Ignorados (duplicados): %d | Removidos: %d', 'brasilferiados'),
        $year,
        $resultado['inseridos'],
        $resultado['ignorados'],
        $removidos
    );
    Session::addMessageAfterRedirect($msg, true, INFO);

    foreach ($resultado['erros'] as $err) {
        Session::addMessageAfterRedirect($err, false, ERROR);
    }

    Session::setActiveTab(Sync::class, Sync::class . '$3');
    Html::redirect($form_url . '?tab=manual');
}

// -----------------------------------------------------------------------
// Sincronização de aba ativa com navegação por query string
// -----------------------------------------------------------------------
if (isset($_GET['tab'])) {
    $tabMap = [
        'provider'  => Sync::class . '$1',
        'automatic' => Sync::class . '$2',
        'manual'    => Sync::class . '$3',
        'all'       => '-1',
        '1'         => Sync::class . '$1',
        '2'         => Sync::class . '$2',
        '3'         => Sync::class . '$3',
    ];
    if (isset($tabMap[$_GET['tab']])) {
        Session::setActiveTab(Sync::class, $tabMap[$_GET['tab']]);
    }
}
if (isset($_REQUEST['load_national'])) {
    Session::setActiveTab(Sync::class, Sync::class . '$3');
}

// -----------------------------------------------------------------------
// Renderização da interface de abas nativa do GLPI
// -----------------------------------------------------------------------
Html::header(
    Sync::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$options = [
    'id'              => 1,
    'show_nav_header' => false,
];
if (isset($_REQUEST['load_national'])) {
    $options['load_national'] = 1;
    $options['load_year'] = (int)($_REQUEST['load_year'] ?? date('Y'));
}

$config->display($options);

Html::footer();
