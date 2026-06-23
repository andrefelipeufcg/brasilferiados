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
    $is_perpetual = isset($_POST['is_perpetual']) ? 1 : 0;

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
        // Volta para a tela principal com as mensagens de erro
        global $CFG_GLPI;
        Html::redirect($CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php');
    }

        if ($id > 0) {
        // Atualizar
        $feriadoLocal->update([
            'id'           => $id,
            'dia'          => $dia,
            'mes'          => $mes,
            'nome'         => $nome,
            'is_perpetual' => $is_perpetual,
        ]);
        Session::addMessageAfterRedirect('Feriado local atualizado com sucesso.', true, INFO);
    } else {
        // Inserir
        $feriadoLocal->add([
            'dia'          => $dia,
            'mes'          => $mes,
            'nome'         => $nome,
            'is_perpetual' => $is_perpetual,
        ]);
        Session::addMessageAfterRedirect('Feriado local adicionado com sucesso.', true, INFO);
    }

    global $CFG_GLPI;
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/brasilferiados/front/config.form.php');
}

// Fim. Este arquivo atua apenas como processador de POST.
