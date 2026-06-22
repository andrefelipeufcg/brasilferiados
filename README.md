# Brasil Feriados — Plugin GLPI 11

> Sincroniza automaticamente os feriados nacionais brasileiros (via [Brasil API](https://brasilapi.com.br)) e feriados locais recorrentes na tabela nativa `glpi_holidays` do GLPI, com associação automática ao calendário de atendimento.

---

## 📋 Visão Geral

| Recurso | Descrição |
|---|---|
| **Feriados Nacionais** | Consome a Brasil API para importar todos os feriados nacionais do ano selecionado |
| **Feriados Locais (CRUD)** | Grid completo para cadastrar, editar e excluir feriados municipais/estaduais recorrentes |
| **Sincronização Manual** | Botão para importar feriados de qualquer ano sob demanda |
| **Sincronização Automática** | Ação automática (CronTask) que executa todo 1º de Janeiro para importar o novo ano |
| **Vínculo com Calendário** | Associa automaticamente os feriados inseridos ao calendário de atendimento selecionado |
| **Anti-duplicidade** | Verifica `begin_date` + `name` antes de inserir, evitando registros duplicados |

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

A tela de configuração (`Configurar > Plugins > Brasil Feriados`) possui três seções:

### Seção 1 — Configuração da Automação

| Campo | Descrição |
|---|---|
| **Sincronização automática de Ano Novo** | Checkbox que habilita/desabilita a ação automática. Quando ativada, o GLPI CronTask executará a importação dos feriados do ano corrente todo dia 1º de Janeiro. |
| **Calendário Principal de Atendimento** | Dropdown com os calendários cadastrados no GLPI. Os feriados importados serão automaticamente vinculados a este calendário na tabela `glpi_calendars_holidays`. |

### Seção 2 — Feriados Locais Recorrentes (Grid CRUD)

Grid interativo para gerenciar feriados municipais/estaduais que se repetem todo ano:

| Recurso | Descrição |
|---|---|
| **Grid** | Exibe todos os feriados locais cadastrados com colunas Data (DD/MM) e Nome |
| **Adicionar** | Botão que abre formulário com campos Dia, Mês (dropdown) e Nome do Feriado |
| **Editar** | Botão de edição por registro, abre formulário pré-preenchido |
| **Excluir** | Botão de exclusão com confirmação — remove direto do banco de dados |
| **Validação** | Verifica duplicidade, valida datas com `checkdate()` e campos obrigatórios |

**Exemplos de feriados locais:**
- 24/06: São João
- 11/10: Aniversário da Cidade
- 20/11: Dia da Consciência Negra
- 08/12: Nossa Senhora da Conceição

### Seção 3 — Sincronização Manual

| Campo | Descrição |
|---|---|
| **Ano** | Campo numérico (2001–2099) que define qual ano será importado. |
| **Sincronizar Agora** | Botão que dispara a importação imediata dos feriados nacionais + locais do ano indicado. |

---

## ⏰ Ação Automática (CronTask)

O plugin registra uma tarefa chamada **BrasilFeriados** nas Ações Automáticas do GLPI.

- **Frequência**: Diária (roda 1x/dia)
- **Janela de execução**: 00:00 – 02:00
- **Comportamento**: Só executa a sincronização se o checkbox "Sincronização automática de Ano Novo" estiver marcado na configuração.

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
│   ├── config.form.php             # Configuração, grid de feriados locais, sync manual
│   └── local.form.php              # Formulário de inserção / edição de feriado local
├── inc/
│   ├── sync.class.php              # PluginBrasilferiadosSync (lógica de negócio)
│   └── local.class.php             # PluginBrasilferiadosLocal (CRUD feriados locais)
└── README.md
```

---

## 🗄️ Banco de Dados

### Tabelas do Plugin

```sql
-- Configuração geral
glpi_plugin_brasilferiados_configs
├── id              INT (PK, AUTO_INCREMENT)
├── is_active       TINYINT (0 = desativado, 1 = ativado)
└── calendars_id    INT (FK → glpi_calendars.id, 0 = nenhum)

-- Feriados locais recorrentes (CRUD)
glpi_plugin_brasilferiados_locais
├── id    INT (PK, AUTO_INCREMENT)
├── dia   INT (1-31)
├── mes   INT (1-12)
└── nome  VARCHAR(255)
```

### Tabelas Nativas Utilizadas
- `glpi_holidays` — Inserção dos feriados via classe `Holiday::add()`
- `glpi_calendars_holidays` — Vínculo feriado ↔ calendário via `Calendar_Holiday::add()`

---

## 🔒 Segurança

- **CSRF**: Plugin declarado como `csrf_compliant`. Tokens CSRF em todos os formulários.
- **Permissões**: Acesso restrito a usuários com direito `config` + `UPDATE`.
- **Validação**: Anos 2001–2099. Datas validadas com `checkdate()`. Verificação de duplicidade no grid e na inserção.
- **Confirmação**: Exclusão de feriado local exige confirmação do usuário.

---

## 📜 Licença

Este plugin é software livre, distribuído sob os termos da **GNU General Public License** versão 3 ou posterior (GPLv3+).

---
*Desenvolvido com 🇧🇷 para a comunidade GLPI.*
