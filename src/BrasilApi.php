<?php
namespace GlpiPlugin\Brasilferiados;

if (!defined('GLPI_ROOT')) {
    die("Desculpe. Você não pode acessar este arquivo diretamente.");
}

class BrasilApi implements ApiProvider {

    public function fetchHolidays(int $year, array $config): array {
        $resultado = ['feriados' => [], 'erros' => []];
        $url = "https://brasilapi.com.br/api/feriados/v1/{$year}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'GLPI-BrasilFeriados/1.1',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            $resultado['erros'][] = sprintf(
                __('Falha na requisição à Brasil API (HTTP %s): %s', 'brasilferiados'),
                $httpCode,
                $curlErr ?: __('resposta vazia', 'brasilferiados')
            );
            return $resultado;
        }

        $feriadosApi = json_decode($response, true);
        if (!is_array($feriadosApi)) {
            $resultado['erros'][] = __('JSON inválido retornado pela Brasil API.', 'brasilferiados');
            return $resultado;
        }

        foreach ($feriadosApi as $f) {
            if (!empty($f['date']) && !empty($f['name'])) {
                $resultado['feriados'][] = [
                    'date' => $f['date'],
                    'name' => strip_tags($f['name']),
                ];
            }
        }

        return $resultado;
    }

    public function getName(): string {
        return 'Brasil API';
    }

    public function requiresToken(): bool {
        return false;
    }

    public function requiresLocation(): bool {
        return false;
    }

    public function getConfigFields(): array {
        return [];
    }

    public function validateConfig(array $input): string {
        return '';
    }
}
