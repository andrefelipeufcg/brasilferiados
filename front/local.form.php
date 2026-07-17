<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — front/local.form.php
 * Formulário de inserção / edição / exclusão de feriados locais.
 * -----------------------------------------------------------------------
 */

include("../../../inc/includes.php");

use GlpiPlugin\Brasilferiados\Local;

Session::checkRight("config", UPDATE);

$feriadoLocal = new Local();

// -----------------------------------------------------------------------
// POST: Excluir feriado local
// -----------------------------------------------------------------------
if (isset($_POST['delete_local'])) {
    Session::checkCSRF();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && $feriadoLocal->getFromDB($id)) {
        $feriadoLocal->delete(['id' => $id]);
        Session::addMessageAfterRedirect(__('Feriado local excluído com sucesso.', 'brasilferiados'), true, INFO);
    }
    global $CFG_GLPI;
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php');
}

// -----------------------------------------------------------------------
// POST: Salvar (inserir ou atualizar)
// -----------------------------------------------------------------------
if (isset($_POST['save_local'])) {
    Session::checkCSRF();
    $id   = (int)($_POST['id'] ?? 0);
    $dia  = (int)($_POST['dia'] ?? 0);
    $mes  = (int)($_POST['mes'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $is_perpetual = isset($_POST['is_perpetual']) ? 1 : 0;

    $erros = [];

    if (empty($nome)) {
        $erros[] = __('O nome do feriado é obrigatório.', 'brasilferiados');
    }

    if ($dia < 1 || $dia > 31 || $mes < 1 || $mes > 12) {
        $erros[] = __('Data inválida. Informe um dia (1-31) e mês (1-12) válidos.', 'brasilferiados');
    } elseif (!checkdate($mes, $dia, 2024)) {
        $erros[] = sprintf(__('A data %02d/%02d não existe.', 'brasilferiados'), $dia, $mes);
    }

    if (empty($erros) && Local::existeDuplicado($dia, $mes, $nome, $id)) {
        $erros[] = sprintf(__('Já existe um feriado local "%s" na data %02d/%02d.', 'brasilferiados'), $nome, $dia, $mes);
    }

    if (!empty($erros)) {
        foreach ($erros as $e) {
            Session::addMessageAfterRedirect($e, false, ERROR);
        }
        global $CFG_GLPI;
        Html::redirect($CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php');
    }

    if ($id > 0) {
        $feriadoLocal->update([
            'id'           => $id,
            'dia'          => $dia,
            'mes'          => $mes,
            'nome'         => $nome,
            'is_perpetual' => $is_perpetual,
        ]);
        Session::addMessageAfterRedirect(__('Feriado local atualizado com sucesso.', 'brasilferiados'), true, INFO);
    } else {
        $feriadoLocal->add([
            'dia'          => $dia,
            'mes'          => $mes,
            'nome'         => $nome,
            'is_perpetual' => $is_perpetual,
        ]);
        Session::addMessageAfterRedirect(__('Feriado local adicionado com sucesso.', 'brasilferiados'), true, INFO);
    }

    global $CFG_GLPI;
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php');
}
