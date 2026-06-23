<?php
/**
 * -----------------------------------------------------------------------
 * Brasil Feriados — sync.class.php
 * Classe de sincronização: consome APIs de feriados brasileiros e insere
 * na tabela nativa glpi_holidays do GLPI.
 *
 * Padrão Strategy: cada provedor de API é uma classe concreta que
 * implementa PluginBrasilferiadosApiProvider.
 * -----------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Desculpe. Você não pode acessar este arquivo diretamente.");
}

// =========================================================================
// INTERFACE — Contrato que todo provedor de API deve seguir
// =========================================================================
interface PluginBrasilferiadosApiProvider {
    /**
     * Busca feriados de um dado ano, retornando um array normalizado.
     *
     * @param  int   $year   Ano no formato YYYY
     * @param  array $config Configurações do plugin (api_token, api_uf, api_cidade_ibge, etc.)
     * @return array ['feriados' => [['date' => 'YYYY-MM-DD', 'name' => '...']], 'erros' => []]
     */
    public function fetchHolidays(int $year, array $config): array;

    /** Nome amigável do provedor (exibido na interface). */
    public function getName(): string;

    /** Se o provedor requer um token de autenticação. */
    public function requiresToken(): bool;

    /** Se o provedor requer seleção de localidade (UF/cidade). */
    public function requiresLocation(): bool;
}

// =========================================================================
// STRATEGY 1 — BrasilAPI (gratuita, somente feriados nacionais)
// Endpoint: GET https://brasilapi.com.br/api/feriados/v1/{ano}
// Formato:  [{ "date": "YYYY-MM-DD", "name": "...", "type": "national" }]
// =========================================================================
class PluginBrasilferiadosBrasilApi implements PluginBrasilferiadosApiProvider {

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
                'Falha na requisição à Brasil API (HTTP %s): %s',
                $httpCode,
                $curlErr ?: 'resposta vazia'
            );
            return $resultado;
        }

        $feriadosApi = json_decode($response, true);
        if (!is_array($feriadosApi)) {
            $resultado['erros'][] = 'JSON inválido retornado pela Brasil API.';
            return $resultado;
        }

        // Normalizar: BrasilAPI já retorna { date: "YYYY-MM-DD", name: "..." }
        foreach ($feriadosApi as $f) {
            if (!empty($f['date']) && !empty($f['name'])) {
                $resultado['feriados'][] = [
                    'date' => $f['date'],
                    'name' => $f['name'],
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
}

// =========================================================================
// STRATEGY 2 — FeriadosAPI (com token, feriados por cidade/IBGE)
// Endpoint: GET https://feriadosapi.com/api/v1/feriados/cidade/{ibge}?ano={ano}
// Header:   Authorization: Bearer {token}
// Formato:  { "feriados": [{ "data": "DD/MM/YYYY", "nome": "...", "tipo": "..." }] }
// =========================================================================
class PluginBrasilferiadosFeriadosApi implements PluginBrasilferiadosApiProvider {

    public function fetchHolidays(int $year, array $config): array {
        $resultado = ['feriados' => [], 'erros' => []];

        $token = trim($config['api_token'] ?? '');
        $ibge  = trim($config['api_cidade_ibge'] ?? '');

        if (empty($token)) {
            $resultado['erros'][] = 'Token da FeriadosAPI não configurado. Acesse a configuração do plugin.';
            return $resultado;
        }
        if (empty($ibge)) {
            $resultado['erros'][] = 'Cidade (código IBGE) não configurada para a FeriadosAPI.';
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
            $errorMsg = 'Token inválido ou sem permissão';
            if ($response !== false) {
                $jsonErr = json_decode($response, true);
                if (isset($jsonErr['message'])) {
                    $errorMsg = $jsonErr['message'];
                } elseif (isset($jsonErr['error'])) {
                    $errorMsg = $jsonErr['error'];
                }
            }
            $resultado['erros'][] = sprintf(
                'FeriadosAPI: %s (HTTP %s). Verifique seu token e plano no painel.',
                $errorMsg,
                $httpCode
            );
            return $resultado;
        }

        if ($httpCode !== 200 || $response === false) {
            $resultado['erros'][] = sprintf(
                'Falha na requisição à FeriadosAPI (HTTP %s): %s',
                $httpCode,
                $curlErr ?: 'resposta vazia'
            );
            return $resultado;
        }

        $json = json_decode($response, true);
        if (!is_array($json) || !isset($json['feriados'])) {
            $resultado['erros'][] = 'JSON inválido ou formato inesperado da FeriadosAPI.';
            return $resultado;
        }

        // Normalizar: converter data DD/MM/YYYY → YYYY-MM-DD
        foreach ($json['feriados'] as $f) {
            $dataOriginal = $f['data'] ?? '';
            $nome         = $f['nome'] ?? '';

            if (empty($dataOriginal) || empty($nome)) {
                continue;
            }

            $date = self::converterData($dataOriginal);
            if ($date === null) {
                $resultado['erros'][] = sprintf(
                    'FeriadosAPI: data inválida "%s" para o feriado "%s".',
                    $dataOriginal,
                    $nome
                );
                continue;
            }

            $resultado['feriados'][] = [
                'date' => $date,
                'name' => $nome,
            ];
        }

        return $resultado;
    }

    /**
     * Converte data do formato DD/MM/YYYY para YYYY-MM-DD.
     */
    private static function converterData(string $data): ?string {
        // Tenta DD/MM/YYYY
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $data, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        // Tenta YYYY-MM-DD (caso a API já retorne neste formato)
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

// =========================================================================
// CLASSE PRINCIPAL — Sincronização de feriados
// =========================================================================
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
    // REGISTRY — Mapa de provedores disponíveis
    // ===================================================================
    /** @var array<string, class-string<PluginBrasilferiadosApiProvider>> */
    private static array $providers = [
        'brasilapi'   => PluginBrasilferiadosBrasilApi::class,
        'feriadosapi' => PluginBrasilferiadosFeriadosApi::class,
    ];

    /**
     * Retorna a lista de provedores para popular dropdowns.
     * @return array ['brasilapi' => 'Brasil API', 'feriadosapi' => 'Feriados API']
     */
    public static function getProviderList(): array {
        $list = [];
        foreach (self::$providers as $key => $class) {
            $instance = new $class();
            $list[$key] = $instance->getName();
        }
        return $list;
    }

    /**
     * Instancia o provedor correto a partir do nome armazenado no banco.
     */
    public static function getProvider(string $providerName): PluginBrasilferiadosApiProvider {
        $class = self::$providers[$providerName] ?? self::$providers['brasilapi'];
        return new $class();
    }

    // ===================================================================
    // MÉTODO PRINCIPAL — Busca feriados usando o provedor configurado
    // ===================================================================
    /**
     * Consulta a API configurada e retorna o array normalizado de feriados.
     */
    public static function fetchFromProvider(int $year, ?array $configOverride = null): array {
        if ($configOverride === null) {
            $config = new self();
            if ($config->getFromDB(1)) {
                $configOverride = $config->fields;
            } else {
                $configOverride = ['api_provider' => 'brasilapi'];
            }
        }

        $providerName = $configOverride['api_provider'] ?? 'brasilapi';
        $provider = self::getProvider($providerName);

        return $provider->fetchHolidays($year, $configOverride);
    }

    /**
     * Compatibilidade retroativa — chama o provedor configurado.
     * @deprecated Use fetchFromProvider() em vez deste método.
     */
    public static function fetchFromApi(int $year): array {
        return self::fetchFromProvider($year);
    }

    // ===================================================================
    // SINCRONIZAÇÃO — Insere feriados no GLPI (manual e automática)
    // ===================================================================
    /**
     * @param  int        $year               Ano no formato YYYY
     * @param  array|null $feriadosNacionais  Opcional. Se null, busca da API. Se array, usa o fornecido.
     * @param  bool       $isAuto             Se true, cria um calendário automaticamente na entidade raiz.
     * @param  int        $manualCalendarId   ID do calendário selecionado manualmente.
     * @return array                          ['inseridos' => int, 'ignorados' => int, 'erros' => string[]]
     */
    public static function sincronizarFeriados(int $year, ?array $feriadosNacionais = null, bool $isAuto = false, int $manualCalendarId = 0): array {
        $resultado = [
            'inseridos' => 0,
            'ignorados' => 0,
            'erros'     => [],
        ];

        // Se não foi fornecido um array pré-carregado, busca da API configurada
        if ($feriadosNacionais === null) {
            $apiResult = self::fetchFromProvider($year);
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
        // 2) Inserir feriados da API (nacionais, estaduais e/ou municipais
        //    dependendo do provedor configurado)
        // ---------------------------------------------------------------
        foreach ($feriadosNacionais as $f) {
            $data = $f['date'] ?? '';
            $nome = $f['name'] ?? '';

            if (empty($data) || empty($nome)) {
                continue;
            }

            $isPerpetual = 1; // Por padrão, feriados fixos são recorrentes
            if (isset($f['is_perpetual'])) {
                $isPerpetual = (int)$f['is_perpetual'];
            } else {
                // Feriados móveis no Brasil (Carnaval, Paixão de Cristo, Páscoa, Corpus Christi)
                $feriadosMoveis = ['Carnaval', 'Sexta-feira Santa', 'Páscoa', 'Corpus Christi'];
                foreach ($feriadosMoveis as $movel) {
                    if (stripos($nome, $movel) !== false) {
                        $isPerpetual = 0;
                        break;
                    }
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

        $providerName = $config->fields['api_provider'] ?? 'brasilapi';
        $provider = self::getProvider($providerName);

        $year      = (int)date('Y');
        $resultado = self::sincronizarFeriados($year, null, true);

        $msg = sprintf(
            'Ano %d — Provedor: %s | Inseridos: %d | Ignorados (duplicados): %d',
            $year,
            $provider->getName(),
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
                'description' => 'Sincronizar feriados brasileiros via API configurada',
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
