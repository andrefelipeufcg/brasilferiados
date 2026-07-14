<?php

namespace GlpiPlugin\Brasilferiados\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Teste unitário para a lógica de conversão de datas contida em 
 * PluginBrasilferiadosFeriadosApi::converterData (linhas 208-221 do sync.class.php).
 */
class DateConverterTest extends TestCase
{
    /**
     * Replica a lógica do método converterData
     */
    private function converterData(string $data): ?string {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $data, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $data)) {
            return $data;
        }
        return null;
    }

    public function testConverteDataBrasileiraPadrao(): void
    {
        $result = $this->converterData('25/12/2024');
        $this->assertEquals('2024-12-25', $result, 'Deve inverter de DD/MM/YYYY para YYYY-MM-DD');
    }

    public function testMantemDataJaConvertidaISO(): void
    {
        $result = $this->converterData('2024-01-01');
        $this->assertEquals('2024-01-01', $result, 'Se já estiver no formato de banco, mantem intacto');
    }

    public function testRejeitaDatasDeFormatoDiferente(): void
    {
        $this->assertNull($this->converterData('12-25-2024'), 'Formato americano com hifens não é aceito');
        $this->assertNull($this->converterData('2024/12/25'), 'Formato invertido com barras não é aceito');
        $this->assertNull($this->converterData('Natal'), 'Strings aleatórias retornam null');
    }
}
