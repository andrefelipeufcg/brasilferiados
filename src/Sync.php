<?php
namespace GlpiPlugin\Brasilferiados;

use CommonDBTM;
use CronTask;
use Holiday;
use Calendar;
use Calendar_Holiday;

if (!defined('GLPI_ROOT')) {
    die("Desculpe. Você não pode acessar este arquivo diretamente.");
}

class Sync extends CommonDBTM {

    public static function getTable($classname = '') {
        return 'glpi_plugin_brasilferiados_configs';
    }

    static function getTypeName($nb = 0) {
        return __('Brasil Feriados', 'brasilferiados');
    }

    private static array $providers = [
        'brasilapi'              => BrasilApi::class,
        'feriadosapi'            => FeriadosApi::class,
        'importador_gov_federal' => GovFederalImporter::class,
    ];

    public static function getProviderList(): array {
        $list = [];
        foreach (self::$providers as $key => $class) {
            $instance = new $class();
            $list[$key] = $instance->getName();
        }
        return $list;
    }

    public static function getProvider(string $providerName): ApiProvider {
        $class = self::$providers[$providerName] ?? self::$providers['brasilapi'];
        return new $class();
    }

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

    public static function fetchFromApi(int $year): array {
        return self::fetchFromProvider($year);
    }

    public static function sincronizarFeriados(int $year, ?array $feriadosNacionais = null, bool $isAuto = false, int $manualCalendarId = 0): array {
        $resultado = [
            'inseridos' => 0,
            'ignorados' => 0,
            'erros'     => [],
        ];

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
                $resultado['erros'][] = __('Calendário Principal não configurado. Sincronização cancelada.', 'brasilferiados');
                return $resultado;
            }
        }

        foreach ($feriadosNacionais as $f) {
            $data = $f['date'] ?? '';
            $nome = $f['name'] ?? '';

            if (empty($data) || empty($nome)) {
                continue;
            }

            $isPerpetual = 1;
            if (isset($f['is_perpetual'])) {
                $isPerpetual = (int)$f['is_perpetual'];
            } else {
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

        self::sincronizarFeriadosLocais($year, $calendarsId, $resultado, $isAuto);

        return $resultado;
    }

    public static function cronBrasilFeriados(CronTask $task): int {
        $config = new self();
        if (!$config->getFromDB(1) || (int)$config->fields['is_active'] !== 1) {
            $task->log(__('Sincronização automática desativada. Pulando.', 'brasilferiados'));
            return 0;
        }

        if (date('m-d') !== '01-01') {
            $task->log(__('Hoje não é 1º de Janeiro. Pulando.', 'brasilferiados'));
            return 0;
        }

        $providerName = $config->fields['api_provider'] ?? 'brasilapi';
        $provider = self::getProvider($providerName);

        $year      = (int)date('Y');
        $resultado = self::sincronizarFeriados($year, null, true);

        $msg = sprintf(
            __('Ano %d — Provedor: %s | Inseridos: %d | Ignorados (duplicados): %d', 'brasilferiados'),
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

    public static function cronInfo(string $name): array {
        if ($name === 'BrasilFeriados') {
            return [
                'description' => __('Sincronizar feriados brasileiros via API configurada', 'brasilferiados'),
            ];
        }
        return [];
    }

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
                __('Falha ao inserir o feriado "%s" (%s).', 'brasilferiados'),
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

    private static function sincronizarFeriadosLocais(int $year, int $calendarsId, array &$resultado, bool $isAuto = false): void {
        $feriadosLocais = Local::listarTodos();

        foreach ($feriadosLocais as $fl) {
            $dia          = (int)$fl['dia'];
            $mes          = (int)$fl['mes'];
            $nome         = $fl['nome'];
            $is_perpetual = isset($fl['is_perpetual']) ? (int)$fl['is_perpetual'] : 1;

            if ($isAuto && $is_perpetual === 0) {
                continue;
            }

            if (!checkdate($mes, $dia, $year)) {
                $resultado['erros'][] = sprintf(
                    __('Data inválida no feriado local: %02d/%02d (%s).', 'brasilferiados'),
                    $dia, $mes, $nome
                );
                continue;
            }

            $data = sprintf('%04d-%02d-%02d', $year, $mes, $dia);
            self::inserirFeriado($data, $nome, $calendarsId, $resultado, $is_perpetual);
        }
    }

    private static function obterCalendarioConfigurado(): int {
        $config = new self();
        if ($config->getFromDB(1)) {
            return (int)($config->fields['calendars_id'] ?? 0);
        }
        return 0;
    }

    private static function obterOuCriarCalendarioAutomatico(int $year): int {
        global $DB;
        $nome = sprintf(__('Calendário %d', 'brasilferiados'), $year);

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
