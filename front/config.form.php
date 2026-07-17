<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — front/config.form.php
 * Interface de configuração: provedor de API, automação, calendário,
 * grid de feriados locais e sincronização manual.
 * -----------------------------------------------------------------------
 */

include("../../../inc/includes.php");

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
        $updateData[$fieldName] = trim($_POST[$fieldName] ?? '');
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

// Estados Brasil movido para FeriadosApi.php

// -----------------------------------------------------------------------
// RENDERIZAÇÃO DA PÁGINA
// -----------------------------------------------------------------------
Html::header('Brasil Feriados', $_SERVER['PHP_SELF'], 'config', 'plugins');

// Obter nome do provedor ativo para exibição
$providerList = \GlpiPlugin\Brasilferiados\Sync::getProviderList();
$providerLabel = $providerList[$apiProvider] ?? 'Brasil API';

// =====================================================================
// SEÇÃO 1 — Configuração da Automação (e Provedor de API)
// =====================================================================
echo "<form method='post' action='" . $form_url . "' id='form_config'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrfToken]);
echo "<input type='hidden' name='update_config' value='1'>";

echo "<div class='center' style='margin-top: 20px;'>";

// Título Principal
echo "<table class='tab_cadre_fixe' style='width: 700px;'>";
echo "<tr><th colspan='2'>Brasil Feriados — Configuração</th></tr>";
echo "</table>";

echo "<hr style='width: 700px; border-top: 3px solid black; margin: 20px auto 10px auto;'>";

// ── Bloco: Provedor ──
echo "<table class='tab_cadre_fixe' style='width: 700px;'>";
echo "<tr><th colspan='2'>Provedor</th></tr>";

// Dropdown do Provedor
echo "<tr class='tab_bg_1'>";
echo "<td style='width: 40%; vertical-align: top; padding-top: 15px;'>API de Feriados / Importador</td>";
echo "<td>";
echo "<select name='api_provider' id='api_provider_select' class='form-select' style='width: auto; display: inline-block;'>";
foreach ($providerList as $key => $label) {
    $selected = ($key === $apiProvider) ? "selected='selected'" : "";
    echo "<option value='{$key}' {$selected}>{$label}</option>";
}
echo "</select>";
echo "<br><small class='text-muted'>Selecione API / Importador utilizado para buscar feriados.</small>";
echo "</td>";
echo "</tr>";

// ── Campos Dinâmicos dos Provedores ──
foreach ($providerList as $key => $label) {
    $p = \GlpiPlugin\Brasilferiados\Sync::getProvider($key);
    $fields = $p->getConfigFields();
    
    $displayStyle = ($key === $apiProvider) ? '' : "style='display: none;'";
    
    foreach ($fields as $field) {
        echo "<tr class='tab_bg_1 provider-field-{$key}' {$displayStyle}>";
        
        if (($field['type'] ?? '') === 'info') {
            echo "<td colspan='2' style='padding: 10px;'>";
            echo "<div style='background: #e8f4fd; border-left: 4px solid #2196F3; padding: 12px 15px; border-radius: 4px;'>";
            echo "<i class='fas fa-info-circle' style='color: #2196F3;'></i> ";
            echo $field['content'];
            echo "</div>";
            echo "</td>";
        } else {
            $req = !empty($field['required']) ? " <span style='color:red;'>*</span>" : "";
            echo "<td style='width: 40%; vertical-align: top; padding-top: 15px;'>" . htmlspecialchars($field['label']) . $req . "</td>";
            echo "<td>";
            
            $fieldName = $field['name'] ?? '';
            $val = "";
            if ($fieldName === 'api_token') $val = $apiToken;
            elseif ($fieldName === 'api_uf') $val = $apiUf;
            elseif ($fieldName === 'api_cidade_ibge') $val = $apiCidadeIbge;
            elseif ($fieldName === 'gov_federal_text') $val = $govFederalText;
            
            $valEsc = htmlspecialchars($val, ENT_QUOTES);
            $placeholder = isset($field['placeholder']) ? htmlspecialchars($field['placeholder'], ENT_QUOTES) : '';
            $cssId = isset($field['css_class']) ? $field['css_class'] : $fieldName . '_input';
            if ($fieldName === 'api_uf') $cssId = 'api_uf_select';
            
            if ($field['type'] === 'text') {
                echo "<input type='text' name='{$fieldName}' id='{$cssId}' value='{$valEsc}' class='form-control' style='width: 100%;' placeholder='{$placeholder}'>";
            } elseif ($field['type'] === 'textarea') {
                $height = $field['height'] ?? '150px';
                echo "<textarea name='{$fieldName}' id='{$cssId}' class='form-control' style='width: 100%; height: {$height};' placeholder='{$placeholder}'>{$valEsc}</textarea>";
            } elseif ($field['type'] === 'select') {
                echo "<select name='{$fieldName}' id='{$cssId}' class='form-select' style='width: auto; display: inline-block; min-width: 200px;'>";
                if (!empty($field['empty_option'])) {
                    if ($fieldName === 'api_cidade_ibge' && !empty($val)) {
                        echo "<option value='{$val}' selected='selected'>Carregando... (IBGE: {$val})</option>";
                    } else {
                        echo "<option value=''>" . htmlspecialchars($field['empty_option']) . "</option>";
                    }
                }
                if (!empty($field['options'])) {
                    foreach ($field['options'] as $optVal => $optLabel) {
                        $sel = ($optVal === $val) ? "selected='selected'" : "";
                        echo "<option value='{$optVal}' {$sel}>" . htmlspecialchars($optVal . ' — ' . $optLabel) . "</option>";
                    }
                }
                echo "</select>";
            }
            
            if (!empty($field['help_text'])) {
                echo "<br><small class='text-muted'>" . $field['help_text'] . "</small>";
            }
            
            echo "</td>";
        }
        echo "</tr>";
    }
}

echo "<tr class='tab_bg_2'>";
echo "<td colspan='2' class='center' style='padding: 10px;'>";
echo "<button type='submit' class='btn btn-secondary'>Salvar configurações de provedor</button>";
echo "</td>";
echo "</tr>";

echo "</table>";

echo "<hr style='width: 700px; border-top: 3px solid black; margin: 20px auto 10px auto;'>";

// ── Bloco: Sincronização Automática ──
echo "<table class='tab_cadre_fixe' style='width: 700px;'>";
echo "<tr><th colspan='2'>Sincronização Automática</th></tr>";

echo "<tr class='tab_bg_1'>";
echo "<td style='width: 40%; vertical-align: top; padding-top: 15px;'>Sincronização automática de Ano Novo</td>";
echo "<td>";
$checked = $isActive ? "checked='checked'" : "";
echo "<label>";
echo "<input type='checkbox' name='is_active' value='1' $checked> ";
echo "Executar sincronização automaticamente em 1º de Janeiro do ano corrente via GLPI Cron";
echo "<br><small class='text-muted'>Exemplo: no dia 1º de janeiro de 2030, será criado um calendário na entidade raiz do GLPI com o nome \"Calendário 2030\". Esse calendário terá os feriados obtidos via <strong>" . htmlspecialchars($providerLabel) . "</strong> e os feriados locais recorrentes cadastrados pelo usuário.</small>";
echo "</label>";
echo "</td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td colspan='2' class='center' style='padding: 10px;'>";
echo "<button type='submit' class='btn btn-secondary'>Salvar</button>";
echo "</td>";
echo "</tr>";
echo "</table>";

echo "<hr style='width: 700px; border-top: 3px solid black; margin: 20px auto 10px auto;'>";

echo "<table class='tab_cadre_fixe' style='width: 700px;'>";
echo "<tr><th colspan='2'>Sincronização Manual</th></tr>";
echo "</table>";

// Fim da SEÇÃO 1

echo "</div>";
Html::closeForm();


// =====================================================================
// SEÇÃO 2 — Feriados do Ano (vindos da API configurada)
// =====================================================================
echo "<div class='center' style='margin-top: 20px;'>";

// Formulário apenas para carregar os feriados
echo "<form method='post' action='" . $form_url . "' style='margin-bottom: 0;'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrfToken]);
echo "<input type='hidden' name='load_national' value='1'>";

echo "<table class='tab_cadre_fixe' style='width: 700px;'>";
echo "<tr class='tab_bg_2'>";
echo "<th colspan='4' style='text-align: left; padding: 10px;'>Feriados do ano ";

    echo "<input type='number' name='load_year' id='sync_year_input' value='$loadedYear' min='2001' max='2099' style='width: 90px; margin-left: 10px;' class='form-control d-inline-block'>";
    echo "<button type='submit' class='btn btn-warning' style='margin-left: 10px; color: white;'>Carregar Feriados</button>";
    echo "<span style='margin-left: 15px; font-weight: normal; font-size: 0.85em; color: #666;'>";
    echo "<i class='fas fa-plug'></i> Provedor: <strong>" . htmlspecialchars($providerLabel) . "</strong>";
    echo "</span>";

echo "</th>";
echo "</tr>";
echo "</form>"; // Fecha o form de carregar

// Início do GRID de Feriados
echo "<tr class='tab_bg_2'>";
echo "<th style='width: 20%; text-align: center;'>Data</th>";
echo "<th style='text-align: left;'>Nome do Feriado</th>";
echo "<th style='width: 15%; text-align: center;'>Recorrente</th>";
echo "<th style='width: 15%; text-align: center;'>Ações</th>";
echo "</tr>";

if (!$isLoaded) {
    echo "<tr class='tab_bg_1'>";
    echo "<td colspan='4' class='center' style='padding: 20px; color: #888;'>";
    echo "<i class='fas fa-info-circle'></i> Clique em 'Carregar Feriados' para visualizar e remover os indesejados.";
    echo "</td>";
    echo "</tr>";
} else if (empty($apiHolidays)) {
    echo "<tr class='tab_bg_1'>";
    echo "<td colspan='4' class='center' style='padding: 20px; color: #888;'>";
    echo "<i class='fas fa-info-circle'></i> Nenhum feriado encontrado via " . htmlspecialchars($providerLabel) . " para o ano $loadedYear.";
    echo "</td>";
    echo "</tr>";
} else {
    foreach ($apiHolidays as $idx => $f) {
        $dataOrig = $f['date'];
        $nomeEsc = htmlspecialchars($f['name'], ENT_QUOTES);
        
        $partes = explode('-', $dataOrig);
        $dataFormatada = "";
        if (count($partes) === 3) {
            $dataFormatada = $partes[2] . "/" . $partes[1];
        } else {
            $dataFormatada = $dataOrig;
        }

        if (isset($f['is_perpetual'])) {
            $isPerpetual = (bool)$f['is_perpetual'];
        } else {
            $feriadosMoveis = ['Carnaval', 'Sexta-feira Santa', 'Páscoa', 'Corpus Christi'];
            $isPerpetual = true;
            foreach ($feriadosMoveis as $movel) {
                if (stripos($f['name'], $movel) !== false) {
                    $isPerpetual = false;
                    break;
                }
            }
        }
        $textoRecorrente = $isPerpetual ? "Sim" : "Não";

        echo "<tr class='tab_bg_1' id='row_nat_$idx'>";
        echo "<td class='center'><strong>$dataFormatada</strong></td>";
        echo "<td id='name_nat_$idx'>$nomeEsc</td>";
        echo "<td class='center' id='rec_nat_$idx'>$textoRecorrente</td>";
        echo "<td class='center' style='white-space: nowrap;'>";
        
        $isPerpetualInt = $isPerpetual ? 1 : 0;
        echo "<button type='button' class='btn btn-sm btn-outline-primary' title='Editar' style='margin-right: 5px;' onclick='abrirModalFeriadoNacional($idx, \"$dataFormatada\", \"" . addslashes($nomeEsc) . "\", $isPerpetualInt)'>";
        echo "<i class='fas fa-edit'></i></button>";
        
        // Botão Excluir Visual (Remove a linha via JS)
        echo "<button type='button' class='btn btn-sm btn-outline-danger' title='Excluir' onclick='removerFeriadoNacional($idx)'>";
        echo "<i class='fas fa-trash-alt'></i>";
        echo "</button>";
        
        echo "</td>";
        echo "</tr>";
    }
}

echo "</table>";
echo "</div>";


// =====================================================================
// SEÇÃO 3 — Grid de Feriados Locais Recorrentes
// =====================================================================
$feriadosLocais = \GlpiPlugin\Brasilferiados\Local::listarTodos();

echo "<div class='center' style='margin-top: 20px;'>";
echo "<table class='tab_cadre_fixe' style='width: 700px;'>";

echo "<tr><th colspan='4'>Feriados Locais</th></tr>";

// Cabeçalho do grid
echo "<tr class='tab_bg_2'>";
echo "<th style='width: 20%; text-align: center;'>Data</th>";
echo "<th style='text-align: left;'>Nome do Feriado</th>";
echo "<th style='width: 15%; text-align: center;'>Recorrente</th>";
echo "<th style='width: 20%; text-align: center;'>Ações</th>";
echo "</tr>";

if (empty($feriadosLocais)) {
    echo "<tr class='tab_bg_1'>";
    echo "<td colspan='4' class='center' style='padding: 20px; color: #888;'>";
    echo "<i class='fas fa-info-circle'></i> Nenhum feriado local cadastrado.";
    echo "</td>";
    echo "</tr>";
} else {
    foreach ($feriadosLocais as $fl) {
        $dataFormatada = sprintf('%02d/%02d', $fl['dia'], $fl['mes']);
        $nomeEsc       = htmlspecialchars($fl['nome'], ENT_QUOTES);
        $flId          = (int)$fl['id'];
        $flDia         = (int)$fl['dia'];
        $flMes         = (int)$fl['mes'];

        $is_perpetual = isset($fl['is_perpetual']) ? (int)$fl['is_perpetual'] : 1;

        echo "<tr class='tab_bg_1'>";
        echo "<td class='center'><strong>$dataFormatada</strong></td>";
        echo "<td>$nomeEsc</td>";
        echo "<td class='center'>" . ($is_perpetual ? "Sim" : "Não") . "</td>";
        echo "<td class='center' style='white-space: nowrap;'>";

        echo "<button type='button' class='btn btn-sm btn-outline-primary' title='Editar' style='margin-right: 5px;' onclick='abrirModalFeriado($flId, $flDia, $flMes, \"" . addslashes($nomeEsc) . "\", $is_perpetual)'>";
        echo "<i class='fas fa-edit'></i></button>";

        echo "<button type='button' class='btn btn-sm btn-outline-danger' title='Excluir' onclick='excluirFeriadoLocal($flId, \"" . addslashes($nomeEsc) . "\")'>";
        echo "<i class='fas fa-trash-alt'></i></button>";

        echo "</td>";
        echo "</tr>";
    }
}

echo "<tr class='tab_bg_2'>";
echo "<td colspan='4' class='center' style='padding: 10px;'>";
echo "<button type='button' class='btn btn-success' onclick='abrirModalFeriado(0, \"\", \"\", \"\", 1)'>";
echo "<i class='fas fa-plus'></i> Adicionar Feriado Local</button>";
echo "</td>";
echo "</tr>";

echo "</table>";
echo "</div>";

// =====================================================================
// SEÇÃO 4 — Sincronização Manual (Formulário Oculto com Payload)
// =====================================================================
echo "<form method='post' action='" . $form_url . "' id='form_sync_manual'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrfToken]);

// Injetamos os feriados ocultos aqui para serem enviados no POST de sincronização manual
echo "<div id='national_holidays_container' style='display: none;'>";
if ($isLoaded && !empty($apiHolidays)) {
    foreach ($apiHolidays as $idx => $f) {
        $d = htmlspecialchars($f['date'], ENT_QUOTES);
        $n = htmlspecialchars($f['name'], ENT_QUOTES);
        
        if (isset($f['is_perpetual'])) {
            $isP = (int)$f['is_perpetual'];
        } else {
            $feriadosMoveis = ['Carnaval', 'Sexta-feira Santa', 'Páscoa', 'Corpus Christi'];
            $isP = 1;
            foreach ($feriadosMoveis as $movel) {
                if (stripos($f['name'], $movel) !== false) {
                    $isP = 0;
                    break;
                }
            }
        }
        
        echo "<div id='hidden_nat_$idx'>";
        echo "<input type='hidden' name='national_holidays[$idx][date]' value='$d'>";
        echo "<input type='hidden' id='hidden_name_nat_$idx' name='national_holidays[$idx][name]' value='$n'>";
        echo "<input type='hidden' id='hidden_rec_nat_$idx' name='national_holidays[$idx][is_perpetual]' value='$isP'>";
        echo "</div>";
    }
}
// Registramos o ano que estava carregado para validar no POST
if ($isLoaded) {
    echo "<input type='hidden' name='loaded_year' value='$loadedYear'>";
}
echo "</div>";

echo "<div class='center' style='margin-top: 20px;'>";

// Tabela de Sincronização Manual (Calendário Principal)
echo "<table class='tab_cadre_fixe' style='width: 700px; margin-bottom: 20px;'>";
echo "<tr class='tab_bg_1'>";
echo "<td style='width: 40%; vertical-align: top; padding-top: 15px;'>Calendário Principal de Atendimento <span style='color:red;'>*</span></td>";
echo "<td>";
Calendar::dropdown(['name' => 'calendars_id', 'value' => $calendarsId]);
echo "<br><small class='text-muted'>Os feriados serão vinculados a este calendário durante a Sincronização Manual.</small>";
echo "</td>";
echo "</tr>";
echo "</table>";

echo "<input type='hidden' name='sync_year' id='hidden_sync_year' value='$loadedYear'>";
echo "<button type='button' class='btn btn-secondary' onclick='validarSincronizacao()' style='color: white;'>Sincronizar Agora</button>";
// Hidden submit button triggered via JS if valid
echo "<input type='hidden' name='manual_calendars_id' id='hidden_manual_cal' value='0'>";
echo "<input type='submit' name='sync_now' id='real_sync_btn' style='display: none;'>";

echo "<hr style='width: 700px; border-top: 3px solid black; margin: 20px auto 50px auto;'>";
echo "</div>";

Html::closeForm();

// Form oculto para exclusão de feriados locais (fora de qualquer outro form)
echo "<form method='post' action='" . $CFG_GLPI['root_doc'] . "/plugins/brasilferiados/front/local.form.php' id='form_delete_local' style='display:none;'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrfToken]);
echo "<input type='hidden' name='id' id='delete_local_id' value='0'>";
echo "<input type='hidden' name='delete_local' value='1'>";
echo "</form>";

// =====================================================================
// JAVASCRIPT: Provedor dinâmico, AJAX cidades, exclusão e validação
// =====================================================================

// Valor salvo do IBGE para pré-selecionar após carregar via AJAX
$apiCidadeIbgeJs = htmlspecialchars($apiCidadeIbge, ENT_QUOTES);
$apiUfJs = htmlspecialchars($apiUf, ENT_QUOTES);

echo "
<script>
// =================================================================
// Provedor de API — Toggle de campos dinâmicos
// =================================================================
(function() {
    var providerSelect = document.getElementById('api_provider_select');
    if (!providerSelect) return;

    function toggleProviderFields() {
        var provider = providerSelect.value;
        var allProviderFields = document.querySelectorAll('[class*=\"provider-field-\"]');
        
        for (var i = 0; i < allProviderFields.length; i++) {
            allProviderFields[i].style.display = 'none';
        }
        
        var activeFields = document.querySelectorAll('.provider-field-' + provider);
        for (var j = 0; j < activeFields.length; j++) {
            activeFields[j].style.display = '';
            activeFields[j].style.opacity = '0';
            activeFields[j].style.transition = 'opacity 0.3s ease-in';
            setTimeout((function(el) {
                return function() { el.style.opacity = '1'; };
            })(activeFields[j]), 50);
        }
    }

    providerSelect.addEventListener('change', toggleProviderFields);
})();

// =================================================================
// AJAX — Carregar cidades ao selecionar UF
// =================================================================
(function() {
    var ufSelect = document.getElementById('api_uf_select');
    var cidadeSelect = document.getElementById('api_cidade_select');
    var savedIbge = '{$apiCidadeIbgeJs}';
    var savedUf = '{$apiUfJs}';

    if (!ufSelect || !cidadeSelect) return;

    function carregarCidades(uf, preSelectIbge) {
        if (!uf) {
            cidadeSelect.innerHTML = '<option value=\"\">Selecione o estado primeiro...</option>';
            return;
        }

        cidadeSelect.innerHTML = '<option value=\"\">Carregando cidades...</option>';
        cidadeSelect.disabled = true;

        var url = 'https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + uf + '/municipios?orderBy=nome';

        var capitais = {
            'AC': 'Rio Branco', 'AL': 'Maceió', 'AP': 'Macapá', 'AM': 'Manaus',
            'BA': 'Salvador', 'CE': 'Fortaleza', 'DF': 'Brasília', 'ES': 'Vitória',
            'GO': 'Goiânia', 'MA': 'São Luís', 'MT': 'Cuiabá', 'MS': 'Campo Grande',
            'MG': 'Belo Horizonte', 'PA': 'Belém', 'PB': 'João Pessoa', 'PR': 'Curitiba',
            'PE': 'Recife', 'PI': 'Teresina', 'RJ': 'Rio de Janeiro', 'RN': 'Natal',
            'RS': 'Porto Alegre', 'RO': 'Porto Velho', 'RR': 'Boa Vista', 'SC': 'Florianópolis',
            'SP': 'São Paulo', 'SE': 'Aracaju', 'TO': 'Palmas'
        };

        fetch(url)
            .then(function(response) { return response.json(); })
            .then(function(municipios) {
                cidadeSelect.innerHTML = '<option value=\"\">Selecione a cidade...</option>';
                var capitalNome = capitais[uf];
                for (var i = 0; i < municipios.length; i++) {
                    var m = municipios[i];
                    var opt = document.createElement('option');
                    opt.value = m.id;
                    opt.textContent = m.nome;
                    if (preSelectIbge && String(m.id) === String(preSelectIbge)) {
                        opt.selected = true;
                    } else if (!preSelectIbge && m.nome === capitalNome) {
                        opt.selected = true;
                    }
                    cidadeSelect.appendChild(opt);
                }
                cidadeSelect.disabled = false;
            })
            .catch(function(err) {
                cidadeSelect.innerHTML = '<option value=\"\">Erro ao carregar cidades</option>';
                cidadeSelect.disabled = false;
                console.error('Erro ao carregar municípios do IBGE:', err);
            });
    }

    ufSelect.addEventListener('change', function() {
        carregarCidades(this.value, null);
    });

    // Ao carregar a página, se já existe UF salvo, busca as cidades e pré-seleciona
    if (savedUf) {
        carregarCidades(savedUf, savedIbge);
    }
})();

// =================================================================
// Validação do Formulário de Configuração
// =================================================================
(function() {
    var formConfig = document.getElementById('form_config');
    if (!formConfig) return;

    formConfig.addEventListener('submit', function(e) {
        var providerSelect = document.getElementById('api_provider_select');
        if (!providerSelect) return;
        
        var provider = providerSelect.value;
        var isValid = true;
        
        var rows = document.querySelectorAll('.provider-field-' + provider);
        for (var i = 0; i < rows.length; i++) {
            var labelCell = rows[i].querySelector('td:first-child');
            if (labelCell && labelCell.innerHTML.indexOf('*') !== -1) {
                var input = rows[i].querySelector('input, select, textarea');
                if (input && input.value.trim() === '') {
                    isValid = false;
                    break;
                }
            }
        }
        
        if (!isValid) {
            e.preventDefault();
            alert('Por favor, preencha todos os campos obrigatórios (*) do provedor selecionado.');
        }
    });
})();

// =================================================================
// Exclusão de feriados do grid
// =================================================================
function removerFeriadoNacional(idx) {
    // Remove a linha visível do Grid
    var row = document.getElementById('row_nat_' + idx);
    if (row) { row.remove(); }
    
    // Remove os inputs ocultos para que não sejam enviados na Sincronização
    var hiddenDiv = document.getElementById('hidden_nat_' + idx);
    if (hiddenDiv) { hiddenDiv.remove(); }
}

function excluirFeriadoLocal(id, nome) {
    if (confirm('Tem certeza que deseja excluir o feriado local \\'' + nome + '\\'?')) {
        document.getElementById('delete_local_id').value = id;
        document.getElementById('form_delete_local').submit();
    }
}

// =================================================================
// Validação de sincronização manual
// =================================================================
function validarSincronizacao() {
    var loadedYear = " . ($isLoaded ? $loadedYear : '0') . ";
    var syncYearInput = document.getElementById('sync_year_input').value;
    document.getElementById('hidden_sync_year').value = syncYearInput;
    var isActive = " . $isActive . ";
    
    if (isActive === 0) {
        if (loadedYear === 0) {
            alert('Você deve Carregar os Feriados do ano desejado antes de sincronizar.');
            return false;
        }
        
        if (parseInt(syncYearInput) !== loadedYear) {
            alert('Atenção: O ano digitado na Sincronização (' + syncYearInput + ') é diferente do ano carregado no Grid (' + loadedYear + ').\\n\\nPor favor, atualize o ano no campo \"Feriados do ano\", clique em \"Carregar Feriados\" e tente novamente.');
            return false;
        }
    }

    var calDropdown = document.querySelector(\"select[name='calendars_id']\");
    var currentCalId = calDropdown ? parseInt(calDropdown.value) : 0;
    
    if (currentCalId === 0) {
        alert('O \"Calendário Principal de Atendimento\" é obrigatório para a sincronização manual.\\n\\nPor favor, selecione-o no menu dropdown abaixo e tente novamente.');
        return false;
    }
    document.getElementById('hidden_manual_cal').value = currentCalId;
    
    if (confirm('Prosseguir com a Sincronização Manual do ano ' + syncYearInput + '?')) {
        document.getElementById('real_sync_btn').click();
    }
}

// =================================================================
// Modal de feriado local
// =================================================================
function abrirModalFeriado(id, dia, mes, nome, isPerpetual) {
    document.getElementById('modal_fl_id').value = id;
    document.getElementById('modal_fl_dia').value = dia;
    document.getElementById('modal_fl_mes').value = mes;
    document.getElementById('modal_fl_nome').value = nome;
    document.getElementById('modal_fl_perpetual').checked = (isPerpetual == 1);
    
    document.getElementById('modalFeriadoLocalLabel').innerText = (id > 0) ? 'Editar Feriado Local' : 'Novo Feriado Local';
    
    // Mostra o modal (Bootstrap 5)
    var modalEl = document.getElementById('modalFeriadoLocal');
    if (typeof bootstrap !== 'undefined') {
        var modal = new bootstrap.Modal(modalEl);
        modal.show();
    } else {
        // Fallback para jQuery
        $(modalEl).modal('show');
    }
}

function abrirModalFeriadoNacional(idx, dataFormatada, nome, isPerpetual) {
    document.getElementById('modal_fn_idx').value = idx;
    document.getElementById('modal_fn_data_display').value = dataFormatada;
    document.getElementById('modal_fn_nome').value = nome;
    document.getElementById('modal_fn_perpetual').checked = (isPerpetual == 1);
    
    var modalEl = document.getElementById('modalFeriadoNacional');
    if (typeof bootstrap !== 'undefined') {
        var modal = new bootstrap.Modal(modalEl);
        modal.show();
    } else {
        $(modalEl).modal('show');
    }
}

function salvarEdicaoNacional() {
    var idx = document.getElementById('modal_fn_idx').value;
    var novoNome = document.getElementById('modal_fn_nome').value;
    var isPerpetual = document.getElementById('modal_fn_perpetual').checked;
    
    if (novoNome.trim() === '') {
        alert('O nome do feriado é obrigatório.');
        return;
    }
    
    // Atualiza a tabela visível
    var nameTd = document.getElementById('name_nat_' + idx);
    var recTd = document.getElementById('rec_nat_' + idx);
    if (nameTd) nameTd.innerText = novoNome;
    if (recTd) recTd.innerText = isPerpetual ? 'Sim' : 'Não';
    
    // Atualiza os inputs ocultos que serão enviados
    var hiddenName = document.getElementById('hidden_name_nat_' + idx);
    var hiddenRec = document.getElementById('hidden_rec_nat_' + idx);
    if (hiddenName) hiddenName.value = novoNome;
    if (hiddenRec) hiddenRec.value = isPerpetual ? '1' : '0';
    
    // Atualiza o onclick do botão de editar para refletir os novos dados
    var editBtn = document.querySelector('#row_nat_' + idx + ' .btn-outline-primary');
    if (editBtn) {
        var dataFormatada = document.getElementById('modal_fn_data_display').value;
        var isP = isPerpetual ? 1 : 0;
        var escapedName = novoNome.replace(/\"/g, '&quot;').replace(/'/g, '\\\\\'');
        editBtn.setAttribute('onclick', 'abrirModalFeriadoNacional(' + idx + ', \"' + dataFormatada + '\", \"' + escapedName + '\", ' + isP + ')');
    }
    
    // Fecha o modal
    var modalEl = document.getElementById('modalFeriadoNacional');
    if (typeof bootstrap !== 'undefined') {
        var modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    } else {
        $(modalEl).modal('hide');
    }
}
</script>

<!-- Modal Feriado Local -->
<div class='modal fade' id='modalFeriadoLocal' tabindex='-1' aria-labelledby='modalFeriadoLocalLabel' aria-hidden='true'>
  <div class='modal-dialog'>
    <div class='modal-content'>
      <form method='post' action='" . $CFG_GLPI['root_doc'] . "/plugins/brasilferiados/front/local.form.php'>
        <input type='hidden' name='_glpi_csrf_token' value='" . $csrfToken . "'>
        <input type='hidden' name='id' id='modal_fl_id' value='0'>
        <input type='hidden' name='save_local' value='1'>
        
        <div class='modal-header'>
          <h5 class='modal-title' id='modalFeriadoLocalLabel'>Novo Feriado Local</h5>
          <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
        </div>
        
        <div class='modal-body'>
          <div class='mb-3'>
            <label class='form-label'>Dia</label>
            <input type='number' name='dia' id='modal_fl_dia' class='form-control' min='1' max='31' required>
          </div>
          <div class='mb-3'>
            <label class='form-label'>Mês</label>
            <select name='mes' id='modal_fl_mes' class='form-select' required>
              <option value=''>Selecione...</option>
              <option value='1'>Janeiro</option>
              <option value='2'>Fevereiro</option>
              <option value='3'>Março</option>
              <option value='4'>Abril</option>
              <option value='5'>Maio</option>
              <option value='6'>Junho</option>
              <option value='7'>Julho</option>
              <option value='8'>Agosto</option>
              <option value='9'>Setembro</option>
              <option value='10'>Outubro</option>
              <option value='11'>Novembro</option>
              <option value='12'>Dezembro</option>
            </select>
          </div>
          <div class='mb-3'>
            <label class='form-label'>Nome do Feriado</label>
            <input type='text' name='nome' id='modal_fl_nome' class='form-control' maxlength='255' required placeholder='Ex: São João...'>
          </div>
          <div class='form-check' style='margin-top: 15px;'>
            <input class='form-check-input' type='checkbox' name='is_perpetual' value='1' id='modal_fl_perpetual' checked>
            <label class='form-check-label' for='modal_fl_perpetual'>
              Recorrente
            </label>
          </div>
        </div>
        
        <div class='modal-footer'>
          <button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancelar</button>
          <button type='submit' class='btn btn-primary'>Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>
";

echo "
<!-- Modal Feriado Nacional (Edição Local antes do Sync) -->
<div class='modal fade' id='modalFeriadoNacional' tabindex='-1' aria-labelledby='modalFeriadoNacionalLabel' aria-hidden='true'>
  <div class='modal-dialog'>
    <div class='modal-content'>
      <div class='modal-header'>
        <h5 class='modal-title' id='modalFeriadoNacionalLabel'>Editar Feriado (Sincronização)</h5>
        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
      </div>
      <div class='modal-body'>
        <input type='hidden' id='modal_fn_idx' value=''>
        <div class='mb-3'>
          <label class='form-label'>Data</label>
          <input type='text' id='modal_fn_data_display' class='form-control' disabled>
        </div>
        <div class='mb-3'>
          <label class='form-label'>Nome do Feriado</label>
          <input type='text' id='modal_fn_nome' class='form-control' maxlength='255' required>
        </div>
        <div class='form-check' style='margin-top: 15px;'>
          <input class='form-check-input' type='checkbox' id='modal_fn_perpetual'>
          <label class='form-check-label' for='modal_fn_perpetual'>
            Recorrente
          </label>
        </div>
      </div>
      <div class='modal-footer'>
        <button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancelar</button>
        <button type='button' class='btn btn-primary' onclick='salvarEdicaoNacional()'>Salvar Alteração</button>
      </div>
    </div>
  </div>
</div>
";

Html::footer();
