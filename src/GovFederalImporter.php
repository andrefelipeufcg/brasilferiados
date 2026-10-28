<?php
namespace GlpiPlugin\Brasilferiados;

if (!defined('GLPI_ROOT')) {
    die("Desculpe. Você não pode acessar este arquivo diretamente.");
}

class GovFederalImporter implements ApiProvider {

    private static array $mesesMap = [
        'janeiro' => '01', 'fevereiro' => '02', 'março' => '03', 'abril' => '04',
        'maio' => '05', 'junho' => '06', 'julho' => '07', 'agosto' => '08',
        'setembro' => '09', 'outubro' => '10', 'novembro' => '11', 'dezembro' => '12'
    ];

    public function fetchHolidays(int $year, array $config): array {
        $resultado = ['feriados' => [], 'remover' => [], 'erros' => []];
        $texto = $config['gov_federal_text'] ?? '';

        if (empty($texto)) {
            $resultado['erros'][] = __('Texto da portaria do Governo Federal não informado.', 'brasilferiados');
            return $resultado;
        }

        if (preg_match('/no ano de\s*(\d{4})/i', $texto, $matches)) {
            $anoTexto = (int)$matches[1];
        }

        $pattern = '/(\d{1,2})(?:º)?\s+de\s+(janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)(?:[\s,.-]+(.*?))?\s*\(((?:feriado|ponto facultativo|facultativo)[^)]*)\)/iu';

        if (!preg_match_all($pattern, $texto, $matches, PREG_SET_ORDER)) {
            $resultado['erros'][] = __('Nenhum feriado ou ponto facultativo encontrado. Verifique se o texto colado contém o formato padrão da portaria.', 'brasilferiados');
            return $resultado;
        }

        foreach ($matches as $m) {
            $dia = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mesStr = mb_strtolower($m[2]);
            $mes = self::$mesesMap[$mesStr] ?? '01';
            
            $nome = trim($m[3] ?? '');
            $nome = ltrim($nome, '- ');
            if (empty($nome)) {
                $nome = __('Ponto Facultativo', 'brasilferiados');
            }
            
            $tipo = mb_strtolower(trim($m[4]));
            $is_perpetual = (strpos($tipo, 'feriado nacional') !== false) ? 1 : 0;

            $resultado['feriados'][] = [
                'date' => "$year-$mes-$dia",
                'name' => strip_tags($nome),
                'is_perpetual' => $is_perpetual
            ];
        }

        // Detectar portarias de alteração: "a ser comemorado no dia DD de MÊS"
        $this->processarAlteracoes($texto, $year, $resultado);

        return $resultado;
    }

    /**
     * Detecta portarias de alteração coladas junto com a original.
     * Padrão: "DD de MÊS, Nome, a ser comemorado no dia DD de MÊS (tipo)"
     * A data original entra na lista de remoção e a nova data na lista de feriados
     * (se já não estiver lá).
     */
    private function processarAlteracoes(string $texto, int $year, array &$resultado): void {
        $patternAlteracao = '/(\d{1,2})(?:º)?\s+de\s+(janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\s*,\s*([^()]+?),\s*a\s+ser\s+comemorad[oa]\s+no\s+dia\s+(\d{1,2})(?:º)?\s+de\s+(janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\s*\(((?:feriado|ponto facultativo|facultativo)[^)]*)\)/iu';

        if (!preg_match_all($patternAlteracao, $texto, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $m) {
            $diaOriginal = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mesOriginal = self::$mesesMap[mb_strtolower($m[2])] ?? '01';
            $nome = trim($m[3]);
            $nome = ltrim($nome, '- ');

            $diaNovo = str_pad($m[4], 2, '0', STR_PAD_LEFT);
            $mesNovo = self::$mesesMap[mb_strtolower($m[5])] ?? '01';

            $tipo = mb_strtolower(trim($m[6]));
            $is_perpetual = (strpos($tipo, 'feriado nacional') !== false) ? 1 : 0;

            $dataOriginal = "$year-$mesOriginal-$diaOriginal";
            $dataNova = "$year-$mesNovo-$diaNovo";

            if ($dataOriginal !== $dataNova) {
                // Marcar data original para remoção (se já existir no sistema)
                $resultado['remover'][] = [
                    'date' => $dataOriginal,
                    'name' => strip_tags($nome),
                    'replaced_by' => $dataNova,
                ];

                // Remover da lista de feriados caso a data original esteja lá
                foreach ($resultado['feriados'] as $idx => $f) {
                    if ($f['date'] === $dataOriginal) {
                        unset($resultado['feriados'][$idx]);
                    }
                }
                $resultado['feriados'] = array_values($resultado['feriados']);

                // Adicionar a nova data se ainda não estiver na lista
                $jaExiste = false;
                foreach ($resultado['feriados'] as $f) {
                    if ($f['date'] === $dataNova) {
                        $jaExiste = true;
                        break;
                    }
                }

                if (!$jaExiste) {
                    $resultado['feriados'][] = [
                        'date' => $dataNova,
                        'name' => strip_tags($nome),
                        'is_perpetual' => $is_perpetual,
                    ];
                }
            }
        }

        // Reordenar feriados por data
        usort($resultado['feriados'], function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
    }

    public function getName(): string {
        return __('Importador Governo Federal', 'brasilferiados');
    }

    public function requiresToken(): bool {
        return false;
    }

    public function requiresLocation(): bool {
        return false;
    }

    public function getConfigFields(): array {
        return [
            [
                'name' => 'gov_federal_text',
                'type' => 'textarea',
                'label' => __('Texto da Portaria do Governo Federal', 'brasilferiados'),
                'required' => true,
                'placeholder' => __('Cole aqui todo o texto da Portaria do Governo Federal divulgada no Diário Oficial...', 'brasilferiados'),
                'help_text' => __('Copie o texto da portaria original do Diário Oficial da União e cole aqui. Se houver portarias de alteração, cole o texto delas logo abaixo. O importador identificará as datas automaticamente e detectará alterações (ex: mudança de data de ponto facultativo), marcando a data antiga para remoção e adicionando a nova data.', 'brasilferiados'),
                'height' => '300px'
            ]
        ];
    }

    public function validateConfig(array $input): string {
        $texto = trim($input['gov_federal_text'] ?? '');
        if (empty($texto)) {
            return __('Importador Governo Federal: O texto da portaria é obrigatório.', 'brasilferiados');
        }
        return '';
    }
}
