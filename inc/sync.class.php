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
     * Busca feriados na Brasil API e nos feriados locais cadastrados,
     * inserindo-os na tabela nativa glpi_holidays.
     *
     * @param  int  $year  Ano no formato YYYY
     * @return array       ['inseridos' => int, 'ignorados' => int, 'erros' => string[]]
     */
    public static function sincronizarFeriados(int $year): array {
        $resultado = [
            'inseridos' => 0,
            'ignorados' => 0,
            'erros'     => [],
        ];

        // ---------------------------------------------------------------
        // 1) Consultar a Brasil API
        // ---------------------------------------------------------------
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

        $feriadosApi = [];
        if ($httpCode === 200 && $response !== false) {
            $feriadosApi = json_decode($response, true);
            if (!is_array($feriadosApi)) {
                $resultado['erros'][] = 'JSON inválido retornado pela Brasil API.';
                $feriadosApi = [];
            }
        }

        // Lê o calendário configurado pelo administrador
        $calendarsId = self::obterCalendarioConfigurado();

        // ---------------------------------------------------------------
        // 2) Inserir feriados nacionais (da API)
        // ---------------------------------------------------------------
        foreach ($feriadosApi as $f) {
            $data = $f['date'] ?? '';
            $nome = $f['name'] ?? '';

            if (empty($data) || empty($nome)) {
                continue;
            }

            self::inserirFeriado($data, $nome, $calendarsId, $resultado);
        }

        // ---------------------------------------------------------------
        // 3) Inserir feriados locais (da tabela CRUD)
        // ---------------------------------------------------------------
        self::sincronizarFeriadosLocais($year, $calendarsId, $resultado);

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

        $year      = (int)date('Y');
        $resultado = self::sincronizarFeriados($year);

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
        array  &$resultado
    ): void {
        global $DB;

        $existente = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_holidays',
            'WHERE'  => [
                'begin_date' => $data,
                'end_date'   => $data,
                'name'       => $nome,
            ],
        ]);

        if (count($existente) > 0) {
            $resultado['ignorados']++;
            return;
        }

        $feriado   = new Holiday();
        $feriadoId = $feriado->add([
            'name'         => $nome,
            'begin_date'   => $data,
            'end_date'     => $data,
            'is_perpetual' => 0,
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
     * Processa feriados locais recorrentes (da tabela CRUD).
     * Lê todos os registros da tabela glpi_plugin_brasilferiados_locais,
     * monta a data YYYY-MM-DD com o $year informado e insere.
     */
    private static function sincronizarFeriadosLocais(int $year, int $calendarsId, array &$resultado): void {
        $feriadosLocais = PluginBrasilferiadosLocal::listarTodos();

        foreach ($feriadosLocais as $fl) {
            $dia  = (int)$fl['dia'];
            $mes  = (int)$fl['mes'];
            $nome = $fl['nome'];

            if (!checkdate($mes, $dia, $year)) {
                $resultado['erros'][] = sprintf(
                    'Data inválida no feriado local: %02d/%02d (%s).',
                    $dia, $mes, $nome
                );
                continue;
            }

            $data = sprintf('%04d-%02d-%02d', $year, $mes, $dia);
            self::inserirFeriado($data, $nome, $calendarsId, $resultado);
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
}
