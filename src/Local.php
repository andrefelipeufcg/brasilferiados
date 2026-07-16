<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — src/Local.php
 * Classe CRUD para feriados locais.
 * -----------------------------------------------------------------------
 */

namespace GlpiPlugin\Brasilferiados;

use CommonDBTM;

if (!defined('GLPI_ROOT')) {
    die("Desculpe. Você não pode acessar este arquivo diretamente.");
}

class Local extends CommonDBTM {

    public static function getTable($classname = '') {
        return 'glpi_plugin_brasilferiados_locais';
    }

    static function getTypeName($nb = 0) {
        return __('Feriados Locais', 'brasilferiados');
    }

    /**
     * Retorna todos os feriados locais cadastrados, ordenados por mês e dia.
     *
     * @return array  Lista de registros ['id','dia','mes','nome']
     */
    public static function listarTodos(): array {
        global $DB;

        $feriados = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'ORDER' => ['mes ASC', 'dia ASC'],
        ]);

        foreach ($iterator as $row) {
            $feriados[] = $row;
        }

        return $feriados;
    }

    /**
     * Verifica se já existe um feriado local com a mesma data e nome.
     *
     * @param  int    $dia
     * @param  int    $mes
     * @param  string $nome
     * @param  int    $excluirId  ID a ser ignorado (para edição)
     * @return bool
     */
    public static function existeDuplicado(int $dia, int $mes, string $nome, int $excluirId = 0): bool {
        global $DB;

        $where = [
            'dia'  => $dia,
            'mes'  => $mes,
            'nome' => $nome,
        ];

        if ($excluirId > 0) {
            $where['NOT'] = ['id' => $excluirId];
        }

        $iterator = $DB->request([
            'SELECT' => 'id',
            'FROM'   => self::getTable(),
            'WHERE'  => $where,
        ]);

        return count($iterator) > 0;
    }
}
