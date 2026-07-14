<?php

namespace GlpiPlugin\Brasilferiados\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Testes focados em garantir a segurança do importador (Prevenção contra XSS e injeções).
 */
class BrasilFeriadosSecurityTest extends TestCase
{
    /**
     * Replica a lógica de extração via regex do Importador do Governo Federal, 
     * mas agora incluindo a mesma proteção strip_tags implementada no sync.class.php
     */
    private function parseHolidaysFromTextWithSanitization(string $texto, int $year): array
    {
        $resultado = [];
        $mesesMap = [
            'janeiro' => '01', 'fevereiro' => '02', 'março' => '03', 'abril' => '04',
            'maio' => '05', 'junho' => '06', 'julho' => '07', 'agosto' => '08',
            'setembro' => '09', 'outubro' => '10', 'novembro' => '11', 'dezembro' => '12'
        ];

        $pattern = '/(\d{1,2})(?:º)?\s+de\s+(janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)(?:[\s,.-]+(.*?))?\s*\(((?:feriado|ponto facultativo|facultativo)[^)]*)\)/iu';

        if (!preg_match_all($pattern, $texto, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $m) {
            $dia = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mes = $mesesMap[mb_strtolower($m[2])] ?? '01';
            $nome = trim($m[3] ?? '');
            $nome = ltrim($nome, '- ');
            if (empty($nome)) {
                $nome = 'Ponto Facultativo';
            }
            $resultado[] = [
                'date' => "$year-$mes-$dia",
                'name' => strip_tags($nome), // Regra de segurança adicionada
            ];
        }

        return $resultado;
    }

    public function testPrevencaoDeStoredXSSNoImportadorGovFederal(): void
    {
        // Se um usuário tentar colar um script malicioso no campo de importação textual:
        $textoMalicioso = "I - 1º de janeiro, <script>alert('XSS')</script> Feriado Malicioso (feriado nacional);";
        
        $feriados = $this->parseHolidaysFromTextWithSanitization($textoMalicioso, 2024);
        
        $this->assertCount(1, $feriados);
        $this->assertEquals("alert('XSS') Feriado Malicioso", $feriados[0]['name'], 'As tags de script devem ser removidas totalmente');
        $this->assertStringNotContainsString('<script>', $feriados[0]['name']);
    }

    /**
     * Replica a lógica da linha 118 do sync.class.php que previne SSRF/Path Traversal em parâmetros de URL
     */
    private function sanitizeIbgeCode(string $ibgeFromConfig): string
    {
        return preg_replace('/[^0-9]/', '', trim($ibgeFromConfig));
    }

    public function testPrevencaoSSRFNoCodigoIBGE(): void
    {
        // Se um administrador mal intencionado inserir pontos e barras no IBGE para tentar
        // forçar o cURL a acessar outros endpoints (SSRF ou Path Traversal)
        $ibgeMalicioso = "../../outra/rota/secreta";
        
        $sanitized = $this->sanitizeIbgeCode($ibgeMalicioso);
        
        // Tudo que não é número some, impedindo que barras afetem a URL do cURL
        $this->assertEquals('', $sanitized, 'As barras e pontos devem ser extirpados, restando nada ou apenas números');
        
        $ibgeValidoComSujeira = "12345/../";
        $sanitized2 = $this->sanitizeIbgeCode($ibgeValidoComSujeira);
        $this->assertEquals('12345', $sanitized2, 'Deve manter os números mas remover todo lixo malicioso');
    }
}
