<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — front/local.form.php
 * Formulário de inserção / edição / exclusão de feriados locais.
 * -----------------------------------------------------------------------
 */

include("../../../inc/includes.php");

Session::checkRight("config", UPDATE);

$feriadoLocal = new PluginBrasilferiadosLocal();

// -----------------------------------------------------------------------
// POST: Excluir feriado local
// -----------------------------------------------------------------------
if (isset($_POST['delete_local'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && $feriadoLocal->getFromDB($id)) {
        $feriadoLocal->delete(['id' => $id]);
        Session::addMessageAfterRedirect('Feriado local excluído com sucesso.', true, INFO);
    }
    global $CFG_GLPI;
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php');
}

// -----------------------------------------------------------------------
// POST: Salvar (inserir ou atualizar)
// -----------------------------------------------------------------------
if (isset($_POST['save_local'])) {
    $id   = (int)($_POST['id'] ?? 0);
    $dia  = (int)($_POST['dia'] ?? 0);
    $mes  = (int)($_POST['mes'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');

    // Validações
    $erros = [];

    if (empty($nome)) {
        $erros[] = 'O nome do feriado é obrigatório.';
    }

    if ($dia < 1 || $dia > 31 || $mes < 1 || $mes > 12) {
        $erros[] = 'Data inválida. Informe um dia (1-31) e mês (1-12) válidos.';
    } elseif (!checkdate($mes, $dia, 2024)) {
        // Usa 2024 (ano bissexto) para validar a data
        $erros[] = sprintf('A data %02d/%02d não existe.', $dia, $mes);
    }

    if (empty($erros) && PluginBrasilferiadosLocal::existeDuplicado($dia, $mes, $nome, $id)) {
        $erros[] = sprintf('Já existe um feriado local "%s" na data %02d/%02d.', $nome, $dia, $mes);
    }

    if (!empty($erros)) {
        foreach ($erros as $e) {
            Session::addMessageAfterRedirect($e, false, ERROR);
        }
        // Volta para a tela de edição preservando os dados
        global $CFG_GLPI;
        Html::redirect($CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/local.form.php' . ($id > 0 ? "?id=$id" : '?action=add'));
    }

    if ($id > 0) {
        // Atualizar
        $feriadoLocal->update([
            'id'   => $id,
            'dia'  => $dia,
            'mes'  => $mes,
            'nome' => $nome,
        ]);
        Session::addMessageAfterRedirect('Feriado local atualizado com sucesso.', true, INFO);
    } else {
        // Inserir
        $feriadoLocal->add([
            'dia'  => $dia,
            'mes'  => $mes,
            'nome' => $nome,
        ]);
        Session::addMessageAfterRedirect('Feriado local adicionado com sucesso.', true, INFO);
    }

    global $CFG_GLPI;
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php');
}

// -----------------------------------------------------------------------
// RENDERIZAÇÃO: Formulário de inserção / edição
// -----------------------------------------------------------------------
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

// Se é edição, carrega os dados
$dia  = '';
$mes  = '';
$nome = '';
$titulo = 'Novo Feriado Local';

if ($id > 0 && $feriadoLocal->getFromDB($id)) {
    $dia    = (int)$feriadoLocal->fields['dia'];
    $mes    = (int)$feriadoLocal->fields['mes'];
    $nome   = $feriadoLocal->fields['nome'];
    $titulo = 'Editar Feriado Local';
}

Html::header('Brasil Feriados', $_SERVER['PHP_SELF'], 'config', 'plugins');

global $CFG_GLPI;
$form_url = $CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/local.form.php';
echo "<form method='post' action='" . $form_url . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo Html::hidden('id', ['value' => $id]);

echo "<div class='center' style='margin-top: 20px;'>";
echo "<table class='tab_cadre_fixe' style='width: 500px;'>";

echo "<tr><th colspan='2'>$titulo</th></tr>";

// Campo: Dia
echo "<tr class='tab_bg_1'>";
echo "<td style='width: 35%;'>Dia</td>";
echo "<td>";
echo "<input type='number' name='dia' value='$dia' min='1' max='31' "
   . "class='form-control d-inline-block' style='width: 100px;' required>";
echo "</td>";
echo "</tr>";

// Campo: Mês
echo "<tr class='tab_bg_1'>";
echo "<td>Mês</td>";
echo "<td>";
echo "<select name='mes' class='form-select d-inline-block' style='width: 200px;' required>";
$meses = [
    1  => 'Janeiro',    2  => 'Fevereiro',  3  => 'Março',
    4  => 'Abril',      5  => 'Maio',       6  => 'Junho',
    7  => 'Julho',      8  => 'Agosto',     9  => 'Setembro',
    10 => 'Outubro',    11 => 'Novembro',   12 => 'Dezembro',
];
echo "<option value=''>Selecione...</option>";
foreach ($meses as $num => $label) {
    $selected = ($mes == $num) ? "selected" : "";
    echo "<option value='$num' $selected>$label</option>";
}
echo "</select>";
echo "</td>";
echo "</tr>";

// Campo: Nome do feriado
echo "<tr class='tab_bg_1'>";
echo "<td>Nome do Feriado</td>";
echo "<td>";
echo "<input type='text' name='nome' value='" . htmlspecialchars($nome) . "' "
   . "class='form-control' style='width: 100%;' maxlength='255' required "
   . "placeholder='Ex: São João, Aniversário da Cidade...'>";
echo "</td>";
echo "</tr>";

// Botões: Cancelar e Salvar
echo "<tr class='tab_bg_2'>";
echo "<td colspan='2' class='center' style='padding: 12px;'>";
echo "<a href='" . $CFG_GLPI['root_doc'] . "/plugins/brasilferiados/front/config.form.php' class='btn btn-secondary' style='margin-right: 10px;'>Cancelar</a>";
echo "<input type='submit' name='save_local' class='btn btn-primary' value='Salvar'>";
echo "</td>";
echo "</tr>";

echo "</table>";
echo "</div>";
Html::closeForm();

Html::footer();
