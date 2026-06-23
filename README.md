# Brasil Feriados — Plugin GLPI 11

> Sincroniza automaticamente os feriados nacionais brasileiros (via [Brasil API](https://brasilapi.com.br)) e feriados locais na tabela nativa `glpi_holidays` do GLPI, com associação automática ao calendário de atendimento.

---

## 📋 Visão Geral

| Recurso | Descrição |
|---|---|
| **Feriados Nacionais** | Consome a Brasil API para importar todos os feriados nacionais do ano selecionado. Identifica automaticamente feriados móveis (Carnaval, Páscoa, etc.) definindo-os como não-recorrentes. |
| **Feriados Locais (CRUD)** | Grid completo para cadastrar, editar e excluir feriados municipais/estaduais, permitindo escolher se são Recorrentes (anuais) ou Não Recorrentes (apenas para aquele ano). |
| **Sincronização Manual** | Botão para importar feriados (Nacionais + Locais) do ano carregado sob demanda, associando-os a um Calendário Principal à sua escolha. |
| **Sincronização Automática** | Ação automática (CronTask) que executa todo 1º de Janeiro para criar um "Calendário YYYY" com todos os feriados do ano corrente (Nacionais via API + Locais Recorrentes). |
| **Vínculo com Calendário** | Associa automaticamente os feriados inseridos ao calendário na tabela `glpi_calendars_holidays`. |
| **Anti-duplicidade** | Verifica `begin_date` + `name` antes de inserir, evitando registros duplicados e atualizando a recorrência se necessário. |

---

## ⚙️ Requisitos

- **GLPI** ≥ 11.0.0
- **PHP** ≥ 8.1 com extensão **cURL** habilitada
- Acesso à internet para consumir a [Brasil API](https://brasilapi.com.br/api/feriados/v1/)

---

## 🚀 Instalação

1. Copie a pasta `brasilferiados/` para o diretório de plugins do GLPI:
   ```
   {GLPI_ROOT}/plugins/brasilferiados/
   ```

2. No GLPI, acesse **Configurar > Plugins**.

3. Clique em **Instalar** e depois em **Ativar**.

4. Acesse **Configurar > Plugins > Brasil Feriados** para configurar.

---

## 🖥️ Interface de Configuração

A tela de configuração (`Configurar > Plugins > Brasil Feriados`) é dividida em blocos bem definidos:

### 1. Sincronização Automática

| Campo | Descrição |
|---|---|
| **Sincronização automática de Ano Novo** | Checkbox que habilita a ação automática. Quando ativada, o GLPI CronTask executará a importação dos feriados do ano corrente todo dia 1º de Janeiro, criando automaticamente o calendário na entidade raiz com os feriados Nacionais da API e os Locais Recorrentes. |

### 2. Sincronização Manual

Permite importar sob demanda. É composta pelos seguintes elementos:

#### Feriados Nacionais do ano
Permite escolher um ano e carregar a listagem que vem da Brasil API. Exibe colunas como Data, Nome e a indicação de "Recorrente" (Sim/Não). Feriados móveis como Carnaval e Páscoa são marcados como Não Recorrentes automaticamente.
**Nota:** O ano selecionado nesta seção é o que será utilizado na Sincronização Manual.

#### Feriados Locais
Grid interativo para gerenciar feriados municipais/estaduais.
| Recurso | Descrição |
|---|---|
| **Grid** | Exibe todos os feriados locais cadastrados, com Data, Nome e se são Recorrentes. |
| **Adicionar/Editar** | Permite incluir um novo feriado escolhendo Dia, Mês, Nome e a flag "Recorrente". Feriados Não Recorrentes são ignorados pela Sincronização Automática. |
| **Excluir** | Remove o registro de feriado local. |

#### Calendário Principal de Atendimento
Dropdown para selecionar qual calendário do GLPI receberá a carga da Sincronização Manual.

#### Botão Sincronizar Agora
Dispara a importação imediata dos feriados nacionais (do ano carregado) + locais (todos, caso manual) para o calendário selecionado.

---

## ⏰ Ação Automática (CronTask)

O plugin registra uma tarefa chamada **BrasilFeriados** nas Ações Automáticas do GLPI.

- **Frequência**: Diária (roda 1x/dia)
- **Janela de execução**: 00:00 – 02:00
- **Comportamento**: Cria o calendário do ano vigente na raiz, busca feriados na API (nacionais) e cruza com a base local (onde Recorrente = Sim), registrando-os. 

> ⚠️ Para que a ação automática funcione, o CRON externo do GLPI deve estar configurado (via crontab ou agendador de tarefas do servidor).

---

## 🏗️ Estrutura do Plugin

```
brasilferiados/
├── .gitignore
├── brasilferiados.xml              # Descriptor XML (marketplace GLPI)
├── setup.php                       # Inicialização, versão e pré-requisitos
├── hook.php                        # Install (cria tabelas + CronTask) / Uninstall
├── front/
│   ├── config.form.php             # Tela de Configuração completa (layout e forms)
│   └── local.form.php              # Controller POST do CRUD de feriados locais
├── inc/
│   ├── sync.class.php              # PluginBrasilferiadosSync (Sincronização Cron e Manual)
│   └── local.class.php             # PluginBrasilferiadosLocal (CRUD feriados locais na BD)
└── README.md                       # Documentação
```

---

## 🗄️ Banco de Dados

### Tabelas do Plugin

```sql
-- Configuração geral
glpi_plugin_brasilferiados_configs
├── id              INT (PK, AUTO_INCREMENT)
└── is_active       TINYINT (0 = desativado, 1 = ativado)

-- Feriados locais (CRUD)
glpi_plugin_brasilferiados_locais
├── id              INT (PK, AUTO_INCREMENT)
├── dia             INT (1-31)
├── mes             INT (1-12)
├── nome            VARCHAR(255)
└── is_perpetual    TINYINT (0 = não, 1 = sim)
```

### Tabelas Nativas Utilizadas
- `glpi_holidays` — Inserção dos feriados via classe `Holiday::add()`
- `glpi_calendars_holidays` — Vínculo feriado ↔ calendário via `Calendar_Holiday::add()`
- `glpi_calendars` — Criação dinâmica de calendários anuais via Sincronização Automática

---

## 🔒 Segurança

- **CSRF**: Plugin declarado como `csrf_compliant`. Tokens CSRF em todos os formulários.
- **Permissões**: Acesso restrito a usuários com direito `config` + `UPDATE`.
- **Validação**: Anos 2001–2099. Datas validadas com `checkdate()`. Verificação de duplicidade no grid e na inserção.

---

## 📜 Licença

Este plugin é software livre, distribuído sob os termos da **GNU General Public License** versão 3 ou posterior (GPLv3+).