<?php
namespace GlpiPlugin\Brasilferiados;

use CommonDBTM;
use CommonGLPI;
use CronTask;
use Holiday;
use Calendar;
use Calendar_Holiday;
use Session;
use Html;
use GLPIKey;
use Glpi\Application\View\TemplateRenderer;

if (!defined('GLPI_ROOT')) {
    die("Desculpe. Você não pode acessar este arquivo diretamente.");
}

class Sync extends CommonDBTM {

    public function __construct() {
        $this->get_item_to_display_tab = true;
        parent::__construct();
    }

    /**
     * Não exibe a barra de navegação/ID do item (ex: 'Brasil Feriados - N/A - ID 1')
     * pois este plugin possui apenas um único registro de configuração global.
     */
    public function showNavigationHeader($options = []) {
        return;
    }

    public static function canCreate(): bool {
        return Session::haveRight('config', UPDATE);
    }

    public static function canView(): bool {
        return Session::haveRight('config', READ);
    }

    public static function canUpdate(): bool {
        return Session::haveRight('config', UPDATE);
    }

    public static function canDelete(): bool {
        return Session::haveRight('config', UPDATE);
    }

    public static function canPurge(): bool {
        return Session::haveRight('config', UPDATE);
    }

    public function canCreateItem(): bool {
        return self::canCreate();
    }

    public function canViewItem(): bool {
        return self::canView();
    }

    public function canUpdateItem(): bool {
        return self::canUpdate();
    }

    public function canDeleteItem(): bool {
        return self::canDelete();
    }

    public function canPurgeItem(): bool {
        return self::canPurge();
    }

    public static function getTable($classname = '') {
        return 'glpi_plugin_brasilferiados_configs';
    }

    static function getTypeName($nb = 0) {
        return __('Brasil Feriados', 'brasilferiados');
    }

    public function defineTabs($options = []) {
        $ong = [];
        $ong[__CLASS__ . '$1'] = self::createTabEntry(__('Provedor', 'brasilferiados'), 0, __CLASS__, 'ti ti-plug');
        $ong[__CLASS__ . '$2'] = self::createTabEntry(__('Sincronização automática', 'brasilferiados'), 0, __CLASS__, 'ti ti-calendar-event');
        $ong[__CLASS__ . '$3'] = self::createTabEntry(__('Sincronização Manual', 'brasilferiados'), 0, __CLASS__, 'ti ti-refresh');
        return $ong;
    }

    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0) {
        if ($item->getType() === __CLASS__) {
            $ong = [];
            $ong[1] = self::createTabEntry(__('Provedor', 'brasilferiados'), 0, $item::class, 'ti ti-plug');
            $ong[2] = self::createTabEntry(__('Sincronização automática', 'brasilferiados'), 0, $item::class, 'ti ti-calendar-event');
            $ong[3] = self::createTabEntry(__('Sincronização Manual', 'brasilferiados'), 0, $item::class, 'ti ti-refresh');
            return $ong;
        }
        return '';
    }

    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item->getType() !== __CLASS__) {
            return false;
        }

        $tabnum = (int)$tabnum;
        if ($tabnum < 1 || $tabnum > 3) {
            return false;
        }

        $params = self::getTabViewParams($item, $tabnum);
        TemplateRenderer::getInstance()->display('@brasilferiados/config.html.twig', $params);

        return true;
    }

    public function showForm($ID, array $options = []) {
        $this->initForm($ID, $options);
        return self::displayTabContentForItem($this, 1);
    }

    public static function getTabViewParams(\CommonGLPI $item, int $tabnum = 1): array {
        $form_url       = \Plugin::getPhpDir('brasilferiados', false) . '/front/config.form.php';
        $local_form_url = \Plugin::getPhpDir('brasilferiados', false) . '/front/local.form.php';

        $isActive       = (int)($item->fields['is_active'] ?? 0);
        $calendarsId    = (int)($item->fields['calendars_id'] ?? 0);
        $apiProvider    = $item->fields['api_provider'] ?? 'brasilapi';
        $apiToken       = $item->fields['api_token'] ?? '';
        if (!empty($apiToken)) {
            $apiToken = (new GLPIKey())->decrypt($apiToken);
        }
        $apiUf          = $item->fields['api_uf'] ?? '';
        $apiCidadeIbge  = $item->fields['api_cidade_ibge'] ?? '';
        $govFederalText = $item->fields['gov_federal_text'] ?? '';
        $anoAtual       = (int)date('Y');

        $loadedYear  = $anoAtual;
        $apiHolidays = [];
        $isLoaded    = false;

        if (isset($_REQUEST['load_national'])) {
            $loadedYear = (int)($_REQUEST['load_year'] ?? $anoAtual);
            if ($apiProvider === 'importador_gov_federal' && preg_match('/no ano de\s*(\d{4})/i', $govFederalText, $matches)) {
                $loadedYear = (int)$matches[1];
            }
            $apiResult = self::fetchFromProvider($loadedYear, $item->fields);
            $apiHolidays = $apiResult['feriados'] ?? [];
            if (!empty($apiResult['erros'])) {
                foreach ($apiResult['erros'] as $err) {
                    Session::addMessageAfterRedirect($err, false, ERROR);
                }
            }
            $isLoaded = true;
        } else if ($isActive) {
            $loadedYear = $anoAtual;
            $apiResult = self::fetchFromProvider($loadedYear, $item->fields);
            $apiHolidays = $apiResult['feriados'] ?? [];
            $isLoaded = true;
        }

        $providerList = self::getProviderList();
        $providerLabel = $providerList[$apiProvider] ?? 'Brasil API';

        $allProviderFields = [];
        foreach (array_keys($providerList) as $key) {
            $p = self::getProvider($key);
            $allProviderFields[$key] = $p->getConfigFields();
        }

        $calendarDropdown = Calendar::dropdown([
            'name'    => 'calendars_id',
            'value'   => $calendarsId,
            'display' => false,
            'width'   => '100%',
        ]);

        $feriadosLocais = Local::listarTodos();

        return [
            'form_url'          => $form_url,
            'local_form_url'    => $local_form_url,
            'apiProvider'       => $apiProvider,
            'providerLabel'     => $providerLabel,
            'providerList'      => $providerList,
            'allProviderFields' => $allProviderFields,
            'apiToken'          => $apiToken,
            'apiUf'             => $apiUf,
            'apiCidadeIbge'     => $apiCidadeIbge,
            'govFederalText'    => $govFederalText,
            'isActive'          => $isActive,
            'loadedYear'        => $loadedYear,
            'apiHolidays'       => $apiHolidays,
            'isLoaded'          => $isLoaded,
            'feriadosLocais'    => $feriadosLocais,
            'calendarsId'       => $calendarsId,
            'calendarDropdown'  => $calendarDropdown,
            'tabnum'            => $tabnum,
        ];
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
