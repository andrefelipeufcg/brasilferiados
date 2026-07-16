<?php
namespace GlpiPlugin\Brasilferiados;

if (!defined('GLPI_ROOT')) {
    die("Desculpe. Você não pode acessar este arquivo diretamente.");
}

interface ApiProvider {
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
