<?php
namespace GlpiPlugin\Brasilferiados;

if (!defined('GLPI_ROOT')) {
    die("Desculpe. Você não pode acessar este arquivo diretamente.");
}

class GovFederalImporter implements ApiProvider {

    public function fetchHolidays(int $year, array $config): array {
        $resultado = ['feriados' => [], 'erros' => []];
        $texto = $config['gov_federal_text'] ?? '';

        if (empty($texto)) {
            $resultado['erros'][] = __('Texto da portaria do Governo Federal não informado.', 'brasilferiados');
            return $resultado;
        }

        if (preg_match('/no ano de\s*(\d{4})/i', $texto, $matches)) {
            $anoTexto = (int)$matches[1];
        }

        $mesesMap = [
            'janeiro' => '01', 'fevereiro' => '02', 'março' => '03', 'abril' => '04',
            'maio' => '05', 'junho' => '06', 'julho' => '07', 'agosto' => '08',
            'setembro' => '09', 'outubro' => '10', 'novembro' => '11', 'dezembro' => '12'
        ];

        $pattern = '/(\d{1,2})(?:º)?\s+de\s+(janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)(?:[\s,.-]+(.*?))?\s*\(((?:feriado|ponto facultativo|facultativo)[^)]*)\)/iu';

        if (!preg_match_all($pattern, $texto, $matches, PREG_SET_ORDER)) {
            $resultado['erros'][] = __('Nenhum feriado ou ponto facultativo encontrado. Verifique se o texto colado contém o formato padrão da portaria.', 'brasilferiados');
            return $resultado;
        }

        foreach ($matches as $m) {
            $dia = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mesStr = mb_strtolower($m[2]);
            $mes = $mesesMap[$mesStr] ?? '01';
            
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

        return $resultado;
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
}
