<?php
namespace GlpiPlugin\Brasilferiados;

if (!defined('GLPI_ROOT')) {
    die("Desculpe. Você não pode acessar este arquivo diretamente.");
}

class FeriadosApi implements ApiProvider {

    public function fetchHolidays(int $year, array $config): array {
        $resultado = ['feriados' => [], 'erros' => []];

        $token = trim($config['api_token'] ?? '');
        $ibge  = preg_replace('/[^0-9]/', '', trim($config['api_cidade_ibge'] ?? ''));

        if (empty($token)) {
            $resultado['erros'][] = __('Token da FeriadosAPI não configurado. Acesse a configuração do plugin.', 'brasilferiados');
            return $resultado;
        }
        if (empty($ibge)) {
            $resultado['erros'][] = __('Cidade (código IBGE) não configurada para a FeriadosAPI.', 'brasilferiados');
            return $resultado;
        }

        $url = "https://feriadosapi.com/api/v1/feriados/cidade/{$ibge}?ano={$year}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'GLPI-BrasilFeriados/1.1',
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 401 || $httpCode === 403) {
            $errorMsg = __('Token inválido ou sem permissão', 'brasilferiados');
            if ($response !== false) {
                $jsonErr = json_decode($response, true);
                if (isset($jsonErr['message'])) {
                    $errorMsg = $jsonErr['message'];
                } elseif (isset($jsonErr['error'])) {
                    $errorMsg = $jsonErr['error'];
                }
            }
            $resultado['erros'][] = sprintf(
                __('FeriadosAPI: %s (HTTP %s). Verifique seu token e plano no painel.', 'brasilferiados'),
                $errorMsg,
                $httpCode
            );
            return $resultado;
        }

        if ($httpCode !== 200 || $response === false) {
            $resultado['erros'][] = sprintf(
                __('Falha na requisição à FeriadosAPI (HTTP %s): %s', 'brasilferiados'),
                $httpCode,
                $curlErr ?: __('resposta vazia', 'brasilferiados')
            );
            return $resultado;
        }

        $json = json_decode($response, true);
        if (!is_array($json) || !isset($json['feriados'])) {
            $resultado['erros'][] = __('JSON inválido ou formato inesperado da FeriadosAPI.', 'brasilferiados');
            return $resultado;
        }

        foreach ($json['feriados'] as $f) {
            $dataOriginal = $f['data'] ?? '';
            $nome         = $f['nome'] ?? '';

            if (empty($dataOriginal) || empty($nome)) {
                continue;
            }

            $date = self::converterData($dataOriginal);
            if ($date === null) {
                $resultado['erros'][] = sprintf(
                    __('FeriadosAPI: data inválida "%s" para o feriado "%s".', 'brasilferiados'),
                    $dataOriginal,
                    $nome
                );
                continue;
            }

            $resultado['feriados'][] = [
                'date' => $date,
                'name' => strip_tags($nome),
            ];
        }

        return $resultado;
    }

    private static function converterData(string $data): ?string {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $data, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $data)) {
            return $data;
        }
        return null;
    }

    public function getName(): string {
        return 'Feriados API';
    }

    public function requiresToken(): bool {
        return true;
    }

    public function requiresLocation(): bool {
        return true;
    }
}
