<?php

namespace GlpiPlugin\Brasilferiados\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Teste unitário PURO para validação do extrator de texto do Governo Federal.
 *
 * Este teste NÃO depende do GLPI ou banco de dados. Ele replica a lógica exata de extração
 * contida em PluginBrasilferiadosGovFederalImporter::fetchHolidays (linhas 261-296 do sync.class.php).
 *
 * Para rodar:
 *   php phpunit plugins/brasilferiados/tests/GovFederalImporterTest.php --testdox
 */
class GovFederalImporterTest extends TestCase
{
    /**
     * Replica a lógica de extração via regex do Importador do Governo Federal
     */
    private function parseHolidaysFromText(string $texto, int $year): array
    {
        $resultado = [];
        $mesesMap = [
            'janeiro' => '01', 'fevereiro' => '02', 'março' => '03', 'abril' => '04',
            'maio' => '05', 'junho' => '06', 'julho' => '07', 'agosto' => '08',
            'setembro' => '09', 'outubro' => '10', 'novembro' => '11', 'dezembro' => '12'
        ];

        $pattern = '/(\d{1,2})(?:º)?\s+de\s+(janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)(?:[\s,.-]+(.*?))?\s*\(((?:feriado|ponto facultativo|facultativo)[^)]*)\)/iu';

        if (!preg_match_all($pattern, $texto, $matches, PREG_SET_ORDER)) {
            return []; // Nenhum encontrado
        }

        foreach ($matches as $m) {
            $dia = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mesStr = mb_strtolower($m[2]);
            $mes = $mesesMap[$mesStr] ?? '01';
            
            $nome = trim($m[3] ?? '');
            $nome = ltrim($nome, '- '); // limpa traços sobressalentes
            if (empty($nome)) {
                $nome = 'Ponto Facultativo';
            }
            
            $tipo = mb_strtolower(trim($m[4]));
            $is_perpetual = (strpos($tipo, 'feriado nacional') !== false) ? 1 : 0;

            $resultado[] = [
                'date' => "$year-$mes-$dia",
                'name' => $nome,
                'is_perpetual' => $is_perpetual
            ];
        }

        return $resultado;
    }

    public function testExtraiFeriadoNacional(): void
    {
        $texto = "I - 1º de janeiro, Confraternização Universal (feriado nacional);";
        $feriados = $this->parseHolidaysFromText($texto, 2024);

        $this->assertCount(1, $feriados);
        $this->assertEquals('2024-01-01', $feriados[0]['date']);
        $this->assertEquals('Confraternização Universal', $feriados[0]['name']);
        $this->assertEquals(1, $feriados[0]['is_perpetual'], 'Deve ser considerado recorrente');
    }

    public function testExtraiPontoFacultativo(): void
    {
        $texto = "II - 12 de fevereiro, Carnaval (ponto facultativo);";
        $feriados = $this->parseHolidaysFromText($texto, 2024);

        $this->assertCount(1, $feriados);
        $this->assertEquals('2024-02-12', $feriados[0]['date']);
        $this->assertEquals('Carnaval', $feriados[0]['name']);
        $this->assertEquals(0, $feriados[0]['is_perpetual'], 'Ponto facultativo não é recorrente (fixo) automaticamente');
    }

    public function testExtraiSemNomeExplícitoApenasComTipo(): void
    {
        // Caso as vezes a portaria não liste o nome de algo muito específico e venha só a data
        $texto = "III - 14 de fevereiro (ponto facultativo);";
        $feriados = $this->parseHolidaysFromText($texto, 2024);

        $this->assertCount(1, $feriados);
        $this->assertEquals('2024-02-14', $feriados[0]['date']);
        $this->assertEquals('Ponto Facultativo', $feriados[0]['name'], 'Se não tiver nome, fallback para Ponto Facultativo');
    }

    public function testExtraiMultiplosFeriadosNoTexto(): void
    {
        $texto = "
            PORTARIA blablabla
            I - 1º de janeiro, Confraternização Universal (feriado nacional);
            II - 12 de fevereiro, Carnaval (ponto facultativo);
            III - 29 de março, Paixão de Cristo (feriado nacional);
            IV - 21 de abril, Tiradentes (feriado nacional);
        ";

        $feriados = $this->parseHolidaysFromText($texto, 2024);
        $this->assertCount(4, $feriados);
        
        // Verifica o Tiradentes
        $this->assertEquals('2024-04-21', $feriados[3]['date']);
        $this->assertEquals('Tiradentes', $feriados[3]['name']);
    }

    public function testTratamentoDeEspacosETravessoes(): void
    {
        // Se a portaria vier com traços duplos ou espaço a mais
        $texto = "V - 1 de maio - Dia do Trabalhador (feriado nacional);";
        $feriados = $this->parseHolidaysFromText($texto, 2024);

        $this->assertCount(1, $feriados);
        $this->assertEquals('Dia do Trabalhador', $feriados[0]['name'], 'Hífen solto não deve fazer parte do nome');
        $this->assertEquals('2024-05-01', $feriados[0]['date'], 'O dia "1" deve virar "01" (pad left com 0)');
    }
}
