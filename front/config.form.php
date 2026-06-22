<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — front/config.form.php
 * Interface de configuração: automação, calendário, grid de feriados
 * locais e sincronização manual.
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
    $config->update([
        'id'            => 1,
        'is_active'     => isset($_POST['is_active']) ? 1 : 0,
        'calendars_id'  => (int)($_POST['calendars_id'] ?? 0),
    ]);

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

    if ($year < 2001 || $year > 2099) {
        Session::addMessageAfterRedirect(
            'Por favor, informe um ano válido entre 2001 e 2099.',
            false,
            ERROR
        );
        Html::back();
    }

    $resultado = PluginBrasilferiadosSync::sincronizarFeriados($year);

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
// RENDERIZAÇÃO DA PÁGINA
// -----------------------------------------------------------------------
Html::header(
    'Brasil Feriados',
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$isActive    = (int)($config->fields['is_active'] ?? 0);
$calendarsId = (int)($config->fields['calendars_id'] ?? 0);
$anoAtual    = (int)date('Y');

global $CFG_GLPI;
$form_url = $CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php';

// =====================================================================
// SEÇÃO 1 — Configuração da Automação
// =====================================================================
echo "<form method='post' action='" . $form_url . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<div class='center' style='margin-top: 20px;'>";
echo "<table class='tab_cadre_fixe' style='width: 700px;'>";

echo "<tr><th colspan='2'>Brasil Feriados — Configuração</th></tr>";

// Checkbox — Automação de Ano Novo
echo "<tr class='tab_bg_1'>";
echo "<td style='width: 40%;'>Sincronização automática de Ano Novo</td>";
echo "<td>";
$checked = $isActive ? "checked='checked'" : "";
echo "<label>";
echo "<input type='checkbox' name='is_active' value='1' $checked> ";
echo "Executar sincronização automaticamente em 1º de Janeiro via GLPI Cron";
echo "</label>";
echo "</td>";
echo "</tr>";

// Dropdown — Calendário principal
echo "<tr class='tab_bg_1'>";
echo "<td>Calendário Principal de Atendimento</td>";
echo "<td>";
Calendar::dropdown([
    'name'  => 'calendars_id',
    'value' => $calendarsId,
]);
echo "<br><small class='text-muted'>"
   . "Os feriados serão vinculados automaticamente a este calendário após a inserção."
   . "</small>";
echo "</td>";
echo "</tr>";

// Botão Salvar
echo "<tr class='tab_bg_2'>";
echo "<td colspan='2' class='center'>";
echo "<input type='submit' name='update_config' class='btn btn-primary' value='Salvar'>";
echo "</td>";
echo "</tr>";

echo "</table>";
echo "</div>";
Html::closeForm();

// =====================================================================
// SEÇÃO 2 — Grid de Feriados Locais Recorrentes
// =====================================================================
$feriadosLocais = PluginBrasilferiadosLocal::listarTodos();

$meses = [
    1  => 'Janeiro',    2  => 'Fevereiro',  3  => 'Março',
    4  => 'Abril',      5  => 'Maio',       6  => 'Junho',
    7  => 'Julho',      8  => 'Agosto',     9  => 'Setembro',
    10 => 'Outubro',    11 => 'Novembro',   12 => 'Dezembro',
];

echo "<div class='center' style='margin-top: 20px;'>";
echo "<table class='tab_cadre_fixe' style='width: 700px;'>";

echo "<tr><th colspan='3'>Feriados Locais Recorrentes</th></tr>";

// Cabeçalho do grid
echo "<tr class='tab_bg_2'>";
echo "<th style='width: 20%; text-align: center;'>Data</th>";
echo "<th style='text-align: left;'>Nome do Feriado</th>";
echo "<th style='width: 20%; text-align: center;'>Ações</th>";
echo "</tr>";

if (empty($feriadosLocais)) {
    echo "<tr class='tab_bg_1'>";
    echo "<td colspan='3' class='center' style='padding: 20px; color: #888;'>";
    echo "<i class='fas fa-info-circle'></i> Nenhum feriado local cadastrado.";
    echo "</td>";
    echo "</tr>";
} else {
    foreach ($feriadosLocais as $fl) {
        $dataFormatada = sprintf('%02d/%02d', $fl['dia'], $fl['mes']);
        $mesNome       = $meses[(int)$fl['mes']] ?? '';
        $nomeEsc       = htmlspecialchars($fl['nome']);
        $flId          = (int)$fl['id'];

        echo "<tr class='tab_bg_1'>";

        // Coluna: Data
        echo "<td class='center'>";
        echo "<strong>$dataFormatada</strong>";
        echo "</td>";

        // Coluna: Nome
        echo "<td>$nomeEsc</td>";

        // Coluna: Ações
        echo "<td class='center' style='white-space: nowrap;'>";

        // Botão Editar
        echo "<a href='" . $CFG_GLPI['root_doc'] . "/plugins/brasilferiados/front/local.form.php?id=$flId' class='btn btn-sm btn-outline-primary' "
           . "title='Editar' style='margin-right: 5px;'>";
        echo "<i class='fas fa-edit'></i>";
        echo "</a>";

        // Botão Excluir (com confirmação JavaScript)
        echo "<form method='post' action='" . $CFG_GLPI['root_doc'] . "/plugins/brasilferiados/front/local.form.php' style='display: inline;'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo "<input type='hidden' name='id' value='$flId'>";
        echo "<input type='hidden' name='delete_local' value='1'>";
        echo "<button type='button' class='btn btn-sm btn-outline-danger' "
           . "title='Excluir' onclick=\"if(confirm('Tem certeza que deseja excluir o feriado local \'" . addslashes($nomeEsc) . "\'?')) { this.form.submit(); }\">";
        echo "<i class='fas fa-trash-alt'></i>";
        echo "</button>";
        echo "</form>";

        echo "</td>";
        echo "</tr>";
    }
}

// Botão Adicionar Feriado Local
echo "<tr class='tab_bg_2'>";
echo "<td colspan='3' class='center' style='padding: 10px;'>";
echo "<a href='" . $CFG_GLPI['root_doc'] . "/plugins/brasilferiados/front/local.form.php?action=add' class='btn btn-success'>";
echo "<i class='fas fa-plus'></i> Adicionar Feriado Local";
echo "</a>";
echo "</td>";
echo "</tr>";

echo "</table>";
echo "</div>";

// =====================================================================
// SEÇÃO 3 — Sincronização Manual
// =====================================================================
echo "<form method='post' action='" . $form_url . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<div class='center' style='margin-top: 20px;'>";
echo "<table class='tab_cadre_fixe' style='width: 700px;'>";

echo "<tr><th colspan='2'>Sincronização Manual</th></tr>";

echo "<tr class='tab_bg_1'>";
echo "<td style='width: 40%;'>Ano para sincronizar</td>";
echo "<td>";
echo "<input type='number' name='sync_year' value='$anoAtual' min='2001' max='2099' "
   . "style='width: 120px;' class='form-control d-inline-block'>";
echo "</td>";
echo "</tr>";

echo "<tr class='tab_bg_2'>";
echo "<td colspan='2' class='center'>";
echo "<input type='submit' name='sync_now' class='btn btn-warning' value='Sincronizar Agora'>";
echo "</td>";
echo "</tr>";

echo "</table>";
echo "</div>";
Html::closeForm();

Html::footer();
