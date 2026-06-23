<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — sync.class.php
 * Classe de sincronização: consome a Brasil API e insere feriados
 * na tabela nativa glpi_holidays do GLPI.
 * -----------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Desculpe. Você não pode acessar este arquivo diretamente.");
}

class PluginBrasilferiadosSync extends CommonDBTM {

    // ===================================================================
    // Força o ORM a usar a tabela correta (evita pluralização errada)
    // ===================================================================
    public static function getTable($classname = '') {
        return 'glpi_plugin_brasilferiados_configs';
    }

    static function getTypeName($nb = 0) {
        return 'Brasil Feriados';
    }

    // ===================================================================
    // MÉTODO PRINCIPAL — Sincroniza feriados para um dado $year
    // ===================================================================
    /**
     * Consulta a Brasil API e retorna o array de feriados ou erros.
     */
    public static function fetchFromApi(int $year): array {
        $resultado = ['feriados' => [], 'erros' => []];
        $url = "https://brasilapi.com.br/api/feriados/v1/{$year}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'GLPI-BrasilFeriados/1.0',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            $resultado['erros'][] = sprintf(
                'Falha na requisição à Brasil API (HTTP %s): %s',
                $httpCode,
                $curlErr ?: 'resposta vazia'
            );
        }

        if ($httpCode === 200 && $response !== false) {
            $feriadosApi = json_decode($response, true);
            if (!is_array($feriadosApi)) {
                $resultado['erros'][] = 'JSON inválido retornado pela Brasil API.';
            } else {
                $resultado['feriados'] = $feriadosApi;
            }
        }

        return $resultado;
    }

    /**
     * Insere os feriados na tabela nativa glpi_holidays.
     *
     * @param  int        $year               Ano no formato YYYY
     * @param  array|null $feriadosNacionais  Opcional. Se null, busca da API. Se array, usa o fornecido.
     * @param  bool       $isAuto             Se true, cria um calendário automaticamente na entidade raiz.
     * @return array                          ['inseridos' => int, 'ignorados' => int, 'erros' => string[]]
     */
    public static function sincronizarFeriados(int $year, ?array $feriadosNacionais = null, bool $isAuto = false, int $manualCalendarId = 0): array {
        $resultado = [
            'inseridos' => 0,
            'ignorados' => 0,
            'erros'     => [],
        ];

        // Se não foi fornecido um array pré-carregado, busca da API (útil para o CronTask)
        if ($feriadosNacionais === null) {
            $apiResult = self::fetchFromApi($year);
            $feriadosNacionais = $apiResult['feriados'];
            if (!empty($apiResult['erros'])) {
                $resultado['erros'] = array_merge($resultado['erros'], $apiResult['erros']);
            }
        }

        if ($isAuto) {
            $calendarsId = self::obterOuCriarCalendarioAutomatico($year);
        } else {
            $calendarsId = $manualCalendarId;
            if ($calendarsId === 0) {
                $resultado['erros'][] = 'Calendário Principal não configurado. Sincronização cancelada.';
                return $resultado;
            }
        }

        // ---------------------------------------------------------------
        // 2) Inserir feriados nacionais
        // ---------------------------------------------------------------
        foreach ($feriadosNacionais as $f) {
            $data = $f['date'] ?? '';
            $nome = $f['name'] ?? '';

            if (empty($data) || empty($nome)) {
                continue;
            }

            // Feriados móveis no Brasil (Carnaval, Paixão de Cristo, Páscoa, Corpus Christi)
            $feriadosMoveis = ['Carnaval', 'Sexta-feira Santa', 'Páscoa', 'Corpus Christi'];
            $isPerpetual = 1; // Por padrão, feriados nacionais como Natal e Tiradentes são recorrentes (fixos)
            
            foreach ($feriadosMoveis as $movel) {
                if (stripos($nome, $movel) !== false) {
                    $isPerpetual = 0;
                    break;
                }
            }

            self::inserirFeriado($data, $nome, $calendarsId, $resultado, $isPerpetual);
        }

        // ---------------------------------------------------------------
        // 3) Inserir feriados locais (da tabela CRUD)
        // ---------------------------------------------------------------
        self::sincronizarFeriadosLocais($year, $calendarsId, $resultado, $isAuto);

        return $resultado;
    }

    // ===================================================================
    // CRON — Método chamado pelo CronTask do GLPI (Ações Automáticas)
    // ===================================================================
    public static function cronBrasilFeriados(CronTask $task): int {
        $config = new self();
        if (!$config->getFromDB(1) || (int)$config->fields['is_active'] !== 1) {
            $task->log('Sincronização automática desativada. Pulando.');
            return 0;
        }

        // O CronTask roda com frequência diária, mas só executamos de fato no Ano Novo.
        if (date('m-d') !== '01-01') {
            $task->log('Hoje não é 1º de Janeiro. Pulando.');
            return 0;
        }

        $year      = (int)date('Y');
        $resultado = self::sincronizarFeriados($year, null, true);

        $msg = sprintf(
            'Ano %d — Inseridos: %d | Ignorados (duplicados): %d',
            $year,
            $resultado['inseridos'],
            $resultado['ignorados']
        );
        $task->log($msg);

        if (!empty($resultado['erros'])) {
            foreach ($resultado['erros'] as $err) {
                $task->log('[ERRO] ' . $err);
            }
        }

        $task->setVolume($resultado['inseridos']);
        return 1;
    }

    // ===================================================================
    // CRON INFO — Descrição exibida na tela de Ações Automáticas
    // ===================================================================
    public static function cronInfo(string $name): array {
        if ($name === 'BrasilFeriados') {
            return [
                'description' => 'Sincronizar feriados brasileiros via Brasil API',
            ];
        }
        return [];
    }

    // ===================================================================
    // MÉTODOS AUXILIARES PRIVADOS
    // ===================================================================

    /**
     * Insere um feriado na tabela nativa, verificando duplicidade.
     */
    private static function inserirFeriado(
        string $data,
        string $nome,
        int    $calendarsId,
        array  &$resultado,
        int    $isPerpetual = 0
    ): void {
        global $DB;

        $existente = $DB->request([
            'SELECT' => ['id', 'name', 'is_perpetual'],
            'FROM'   => 'glpi_holidays',
            'WHERE'  => [
                'begin_date' => $data,
                'end_date'   => $data,
            ],
        ]);

        if (count($existente) > 0) {
            $resultado['ignorados']++;
            $row = $existente->current();
            $feriadoId = (int)$row['id'];
            
            if ($row['name'] !== $nome || (int)$row['is_perpetual'] !== $isPerpetual) {
                $h = new Holiday();
                $h->update([
                    'id'   => $feriadoId,
                    'name' => $nome,
                    'is_perpetual' => $isPerpetual
                ]);
            }

            if ($calendarsId > 0) {
                self::vincularFeriadoAoCalendario($feriadoId, $calendarsId);
            }
            return;
        }

        $feriado   = new Holiday();
        $feriadoId = $feriado->add([
            'name'         => $nome,
            'begin_date'   => $data,
            'end_date'     => $data,
            'is_perpetual' => $isPerpetual,
            'is_recursive' => 1,
        ]);

        if ($feriadoId === false) {
            $resultado['erros'][] = sprintf(
                'Falha ao inserir o feriado "%s" (%s).',
                $nome,
                $data
            );
            return;
        }

        $resultado['inseridos']++;

        if ($calendarsId > 0) {
            self::vincularFeriadoAoCalendario($feriadoId, $calendarsId);
        }
    }

    /**
     * Vincula feriado ao calendário na tabela associativa nativa.
     */
    private static function vincularFeriadoAoCalendario(int $feriadoId, int $calendarsId): void {
        global $DB;

        $existente = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_calendars_holidays',
            'WHERE'  => [
                'calendars_id' => $calendarsId,
                'holidays_id'  => $feriadoId,
            ],
        ]);

        if (count($existente) > 0) {
            return;
        }

        $calFeriado = new Calendar_Holiday();
        $calFeriado->add([
            'calendars_id' => $calendarsId,
            'holidays_id'  => $feriadoId,
        ]);
    }

    /**
     * Processa feriados locais (da tabela CRUD).
     * Lê todos os registros da tabela glpi_plugin_brasilferiados_locais,
     * monta a data YYYY-MM-DD com o $year informado e insere.
     */
    private static function sincronizarFeriadosLocais(int $year, int $calendarsId, array &$resultado, bool $isAuto = false): void {
        $feriadosLocais = PluginBrasilferiadosLocal::listarTodos();

        foreach ($feriadosLocais as $fl) {
            $dia          = (int)$fl['dia'];
            $mes          = (int)$fl['mes'];
            $nome         = $fl['nome'];
            $is_perpetual = isset($fl['is_perpetual']) ? (int)$fl['is_perpetual'] : 1;

            if ($isAuto && $is_perpetual === 0) {
                continue; // Auto-Sync não pega feriados locais "Não Recorrentes"
            }

            if (!checkdate($mes, $dia, $year)) {
                $resultado['erros'][] = sprintf(
                    'Data inválida no feriado local: %02d/%02d (%s).',
                    $dia, $mes, $nome
                );
                continue;
            }

            $data = sprintf('%04d-%02d-%02d', $year, $mes, $dia);
            self::inserirFeriado($data, $nome, $calendarsId, $resultado, $is_perpetual);
        }
    }

    /**
     * Retorna o ID do calendário configurado (ou 0).
     */
    private static function obterCalendarioConfigurado(): int {
        $config = new self();
        if ($config->getFromDB(1)) {
            return (int)($config->fields['calendars_id'] ?? 0);
        }
        return 0;
    }

    /**
     * Cria ou retorna um calendário chamado "Calendário YYYY" na entidade raiz.
     */
    private static function obterOuCriarCalendarioAutomatico(int $year): int {
        global $DB;
        $nome = "Calendário {$year}";

        $existente = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_calendars',
            'WHERE'  => [
                'name'        => $nome,
                'entities_id' => 0
            ],
            'LIMIT'  => 1
        ]);

        if (count($existente) > 0) {
            $row = $existente->current();
            return (int)$row['id'];
        }

        $cal = new Calendar();
        $calId = $cal->add([
            'name'         => $nome,
            'entities_id'  => 0,
            'is_recursive' => 1
        ]);

        return (int)$calId;
    }
}
