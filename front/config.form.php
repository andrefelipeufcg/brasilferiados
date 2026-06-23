<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — front/config.form.php
 * Interface de configuração: automação, calendário, grid de feriados
 * locais e sincronização manual com exclusão de nacionais.
 * -----------------------------------------------------------------------
 */

include("../../../inc/includes.php");

// Permissão: somente quem pode gerenciar configurações
Session::checkRight("config", UPDATE);

// -----------------------------------------------------------------------
// Carrega (ou cria) o registro de configuração
// -----------------------------------------------------------------------
$config = new PluginBrasilferiadosSync();
if (!$config->getFromDB(1)) {
    global $DB;
    $DB->insert('glpi_plugin_brasilferiados_configs', [
        'id'            => 1,
        'is_active'     => 0,
        'calendars_id'  => 0,
    ]);
    $config->getFromDB(1);
}

// -----------------------------------------------------------------------
// POST: Salvar configuração
// -----------------------------------------------------------------------
if (isset($_POST['update_config'])) {
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    $config->update([
        'id'            => 1,
        'is_active'     => $isActive
    ]);

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
        'Configuração salva com sucesso.',
        true,
        INFO
    );
    Html::back();
}

// -----------------------------------------------------------------------
// POST: Sincronização manual
// -----------------------------------------------------------------------
if (isset($_POST['sync_now'])) {
    $year = (int)($_POST['sync_year'] ?? date('Y'));
    $loadedYear = (int)($_POST['loaded_year'] ?? 0);
    $manualCalendarId = (int)($_POST['manual_calendars_id'] ?? 0);
    $nationalHolidays = $_POST['national_holidays'] ?? [];

    if ($year < 2001 || $year > 2099) {
        Session::addMessageAfterRedirect('Por favor, informe um ano válido entre 2001 e 2099.', false, ERROR);
        Html::back();
    }

    $configCheck = new PluginBrasilferiadosSync();
    $configCheck->getFromDB(1);
    $isAct = (int)($configCheck->fields['is_active'] ?? 0);

    if (!$isAct) {
        // Automação desligada: O usuário precisa carregar o ano idêntico antes de sincronizar
        if ($year !== $loadedYear) {
            Session::addMessageAfterRedirect(
                "Você precisa 'Carregar Feriados' do ano {$year} no grid acima antes de sincronizar.",
                false,
                ERROR
            );
            Html::back();
        }

        $nacionais = [];
        if (is_array($nationalHolidays)) {
            foreach ($nationalHolidays as $nh) {
                if (isset($nh['date']) && isset($nh['name'])) {
                    $nacionais[] = ['date' => $nh['date'], 'name' => $nh['name']];
                }
            }
        }
        $resultado = PluginBrasilferiadosSync::sincronizarFeriados($year, $nacionais, false, $manualCalendarId);
    } else {
        // Automação ativada: Ignora exclusões manuais e bate na API nativamente
        $resultado = PluginBrasilferiadosSync::sincronizarFeriados($year, null, false, $manualCalendarId);
    }

    $msg = sprintf(
        'Ano %d — Inseridos: %d | Ignorados (duplicados): %d',
        $year,
        $resultado['inseridos'],
        $resultado['ignorados']
    );
    Session::addMessageAfterRedirect($msg, true, INFO);

    foreach ($resultado['erros'] as $err) {
        Session::addMessageAfterRedirect($err, false, ERROR);
    }

    Html::back();
}

// -----------------------------------------------------------------------
// Lógica para renderização de Feriados Nacionais (GET / POST load_national)
// -----------------------------------------------------------------------
$isActive    = (int)($config->fields['is_active'] ?? 0);
$calendarsId = (int)($config->fields['calendars_id'] ?? 0);
$anoAtual    = (int)date('Y');

$loadedYear = $anoAtual;
$apiHolidays = [];
$isLoaded = false;

if (isset($_POST['load_national'])) {
    $loadedYear = (int)($_POST['load_year'] ?? $anoAtual);
    $apiResult = PluginBrasilferiadosSync::fetchFromApi($loadedYear);
    $apiHolidays = $apiResult['feriados'];
    if (!empty($apiResult['erros'])) {
        foreach ($apiResult['erros'] as $err) {
            Session::addMessageAfterRedirect($err, false, ERROR);
        }
        Html::back();
    }
    $isLoaded = true;
} else if ($isActive) {
    // Se automação está ativa, carrega sempre o ano atual automaticamente para consulta
    $loadedYear = $anoAtual;
    $apiResult = PluginBrasilferiadosSync::fetchFromApi($loadedYear);
    $apiHolidays = $apiResult['feriados'];
    $isLoaded = true;
}

// -----------------------------------------------------------------------
// RENDERIZAÇÃO DA PÁGINA
// -----------------------------------------------------------------------
Html::header('Brasil Feriados', $_SERVER['PHP_SELF'], 'config', 'plugins');

global $CFG_GLPI;
$form_url = $CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php';

// =====================================================================
// SEÇÃO 1 — Configuração da Automação (e Calendário)
// =====================================================================
echo "<form method='post' action='" . $form_url . "' id='form_config'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<input type='hidden' name='update_config' value='1'>";

echo "<div class='center' style='margin-top: 20px;'>";

// Título Principal
echo "<table class='tab_cadre_fixe' style='width: 700px;'>";
echo "<tr><th colspan='2'>Brasil Feriados — Configuração</th></tr>";
echo "</table>";

echo "<hr style='width: 700px; border-top: 3px solid black; margin: 20px auto 10px auto;'>";

// 1. Bloco de Sincronização Automática
echo "<table class='tab_cadre_fixe' style='width: 700px;'>";
echo "<tr><th colspan='2'>Sincronização Automática</th></tr>";

echo "<tr class='tab_bg_1'>";
echo "<td style='width: 40%;'>Sincronização automática de Ano Novo</td>";
echo "<td>";
$checked = $isActive ? "checked='checked'" : "";
echo "<label>";
echo "<input type='checkbox' name='is_active' value='1' $checked> ";
echo "Executar sincronização automaticamente em 1º de Janeiro do ano corrente via GLPI Cron";
echo "<br><small class='text-muted'>Exemplo: no dia 1º de janeiro de 2030, será criado um calendário na entidade raiz do GLPI com o nome \"Calendário 2030\". Esse calendário terá os feriados nacionais que foram obtidos automaticamente via API e os feriados locais recorrentes cadastrados pelo usuário via plugin.</small>";
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
// SEÇÃO 2 — Feriados Nacionais do Ano
// =====================================================================
echo "<div class='center' style='margin-top: 20px;'>";

// Formulário apenas para carregar os feriados
echo "<form method='post' action='" . $form_url . "' style='margin-bottom: 0;'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<table class='tab_cadre_fixe' style='width: 700px;'>";
echo "<tr class='tab_bg_2'>";
echo "<th colspan='4' style='text-align: left; padding: 10px;'>Feriados Nacionais do ano ";

if ($isActive) {
    echo "<input type='number' name='load_year' id='sync_year_input' value='$loadedYear' readonly style='width: 90px; margin-left: 10px;' class='form-control d-inline-block'>";
    echo "<small style='margin-left: 15px; font-weight: normal; color: #666;'><i class='fas fa-lock'></i> Sincronização Automática Ativa (Somente Consulta)</small>";
} else {
    echo "<input type='number' name='load_year' id='sync_year_input' value='$loadedYear' min='2001' max='2099' style='width: 90px; margin-left: 10px;' class='form-control d-inline-block'>";
    echo "<button type='submit' name='load_national' value='1' class='btn btn-warning' style='margin-left: 10px; color: white;'>Carregar Feriados</button>";
}

echo "</th>";
echo "</tr>";
echo "</form>"; // Fecha o form de carregar

// Início do GRID de Feriados Nacionais
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
    echo "<i class='fas fa-info-circle'></i> Nenhum feriado encontrado na Brasil API para o ano $loadedYear.";
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

        $feriadosMoveis = ['Carnaval', 'Sexta-feira Santa', 'Páscoa', 'Corpus Christi'];
        $isPerpetual = true;
        foreach ($feriadosMoveis as $movel) {
            if (stripos($f['name'], $movel) !== false) {
                $isPerpetual = false;
                break;
            }
        }
        $textoRecorrente = $isPerpetual ? "Sim" : "Não";

        echo "<tr class='tab_bg_1' id='row_nat_$idx'>";
        echo "<td class='center'><strong>$dataFormatada</strong></td>";
        echo "<td>$nomeEsc</td>";
        echo "<td class='center'>$textoRecorrente</td>";
        echo "<td class='center'>";
        
        if (!$isActive) {
            // Botão Excluir Visual (Remove a linha via JS)
            echo "<button type='button' class='btn btn-sm btn-outline-danger' title='Excluir' onclick='removerFeriadoNacional($idx)'>";
            echo "<i class='fas fa-trash-alt'></i>";
            echo "</button>";
        } else {
            echo "<span style='color: #ccc;'><i class='fas fa-lock'></i></span>";
        }
        
        echo "</td>";
        echo "</tr>";
    }
}

echo "</table>";
echo "</div>";


// =====================================================================
// SEÇÃO 3 — Grid de Feriados Locais Recorrentes
// =====================================================================
$feriadosLocais = PluginBrasilferiadosLocal::listarTodos();

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
        $nomeEsc       = htmlspecialchars($fl['nome']);
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
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

// Injetamos os feriados nacionais ocultos aqui para serem enviados no POST de sincronização manual
echo "<div id='national_holidays_container' style='display: none;'>";
if ($isLoaded && !empty($apiHolidays)) {
    foreach ($apiHolidays as $idx => $f) {
        $d = htmlspecialchars($f['date'], ENT_QUOTES);
        $n = htmlspecialchars($f['name'], ENT_QUOTES);
        echo "<div id='hidden_nat_$idx'>";
        echo "<input type='hidden' name='national_holidays[$idx][date]' value='$d'>";
        echo "<input type='hidden' name='national_holidays[$idx][name]' value='$n'>";
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
echo "<td style='width: 40%;'>Calendário Principal de Atendimento <span style='color:red;'>*</span></td>";
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
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<input type='hidden' name='id' id='delete_local_id' value='0'>";
echo "<input type='hidden' name='delete_local' value='1'>";
echo "</form>";

// =====================================================================
// JAVASCRIPT: Exclusão na tela e Validação de Ano
// =====================================================================
echo "
<script>
function removerFeriadoNacional(idx) {
    // Remove a linha visível do Grid
    var row = document.getElementById('row_nat_' + idx);
    if (row) { row.remove(); }
    
    // Remove os inputs ocultos para que não sejam enviados na Sincronização
    var hiddenDiv = document.getElementById('hidden_nat_' + idx);
    if (hiddenDiv) { hiddenDiv.remove(); }
}

function excluirFeriadoLocal(id, nome) {
    if (confirm('Tem certeza que deseja excluir o feriado local \'' + nome + '\'?')) {
        document.getElementById('delete_local_id').value = id;
        document.getElementById('form_delete_local').submit();
    }
}

function validarSincronizacao() {
    var loadedYear = " . ($isLoaded ? $loadedYear : '0') . ";
    var syncYearInput = document.getElementById('sync_year_input').value;
    document.getElementById('hidden_sync_year').value = syncYearInput;
    var isActive = " . $isActive . ";
    
    var calDropdown = document.querySelector(\"select[name='calendars_id']\");
    var currentCalId = calDropdown ? parseInt(calDropdown.value) : 0;
    
    if (currentCalId === 0) {
        alert('O \"Calendário Principal de Atendimento\" é obrigatório para a sincronização manual.\\n\\nPor favor, selecione-o no menu dropdown acima e tente novamente.');
        return false;
    }
    document.getElementById('hidden_manual_cal').value = currentCalId;

    if (isActive === 0) {
        if (loadedYear === 0) {
            alert('Você deve Carregar os Feriados Nacionais do ano desejado antes de sincronizar.');
            return false;
        }
        
        if (parseInt(syncYearInput) !== loadedYear) {
            alert('Atenção: O ano digitado na Sincronização (' + syncYearInput + ') é diferente do ano carregado no Grid (' + loadedYear + ').\\n\\nPor favor, atualize o ano no campo \"Feriados Nacionais do ano\", clique em \"Carregar Feriados\" e tente novamente.');
            return false;
        }
    }
    
    if (confirm('Prosseguir com a Sincronização Manual do ano ' + syncYearInput + '?')) {
        document.getElementById('real_sync_btn').click();
    }
}

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
</script>

<!-- Modal Feriado Local -->
<div class='modal fade' id='modalFeriadoLocal' tabindex='-1' aria-labelledby='modalFeriadoLocalLabel' aria-hidden='true'>
  <div class='modal-dialog'>
    <div class='modal-content'>
      <form method='post' action='" . $CFG_GLPI['root_doc'] . "/plugins/brasilferiados/front/local.form.php'>
        " . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) . "
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

Html::footer();
