# Brasil Feriados — Plugin GLPI
[![GLPI 10](https://img.shields.io/badge/GLPI-10-red.svg)](https://glpi-project.org/)
[![GLPI 11](https://img.shields.io/badge/GLPI-11-blue.svg)](https://glpi-project.org/)
[![GLPI 12](https://img.shields.io/badge/GLPI-12-FFD700.svg)](https://glpi-project.org/)
[![License: GPL v3+](https://img.shields.io/badge/License-GPL%20v3%2B-green.svg)](https://www.gnu.org/licenses/gpl-3.0.html)

> Sincroniza automaticamente os feriados brasileiros (nacionais e municipais) via provedores externos (como [Brasil API](https://brasilapi.com.br), [FeriadosAPI](https://feriadosapi.com) ou Importador de Portaria do Governo Federal) e feriados locais cadastrados, inserindo na tabela nativa `glpi_holidays` do GLPI com associação automática ao calendário de atendimento.

---

## 📋 Visão Geral

| Recurso | Descrição |
|---|---|
| **Interface em Abas Nativas** | Organização da interface no padrão nativo de abas do GLPI (menu lateral à esquerda), dividida em **Provedor**, **Sincronização Automática**, **Sincronização Manual** e a visão unificada **Todos**. |
| **Feriados via Provedores** | Utiliza provedores (Brasil API, FeriadosAPI ou Importador de Portaria do Governo Federal) para importar feriados nacionais, estaduais e municipais do ano selecionado. Identifica automaticamente feriados móveis (Carnaval, Páscoa, etc.) definindo-os como não-recorrentes. |
| **Portarias de Alteração** | Suporte a portarias federais de retificação/alteração (ex.: mudança de data do Dia do Servidor Público Federal). Detecta datas substituídas, exibe-as para remoção e atualiza o calendário com a nova data comemorativa. |
| **Seleção Individual e em Massa** | Checkboxes para selecionar feriados específicos (nacionais e locais) antes da sincronização manual, com botões para marcar/desmarcar todos. Permite personalizar feriados por campus ou setor. |
| **Feriados Locais (CRUD)** | Grid completo para cadastrar, editar e excluir feriados municipais/estaduais, permitindo escolher se são Recorrentes (anuais) ou Não Recorrentes (apenas para aquele ano). |
| **Sincronização Manual** | Botão para importar feriados (Nacionais + Locais) do ano carregado sob demanda, associando-os a um Calendário Principal à sua escolha. É independente da sincronização automática. |
| **Sincronização Automática** | Ação automática (CronTask) que executa de forma precisa em 1º de Janeiro para criar um "Calendário YYYY" com todos os feriados do ano corrente (via provedor configurado + Locais Recorrentes). |
| **Vínculo com Calendário** | Associa automaticamente os feriados inseridos ao calendário na tabela `glpi_calendars_holidays`. |
| **Anti-duplicidade** | Verifica `begin_date` + `name` antes de inserir, evitando registros duplicados e atualizando a recorrência se necessário. |

---

## ⚙️ Requisitos

- **GLPI** ≥ 10.0.0
- **PHP** ≥ 8.1 com extensão **cURL** habilitada
- Acesso à internet para consumir os provedores baseados em API (como [Brasil API](https://brasilapi.com.br) ou [FeriadosAPI](https://feriadosapi.com)).

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

A tela de configuração (`Configurar > Plugins > Brasil Feriados`) segue o padrão nativo de abas do GLPI (menu lateral vertical à esquerda, renderizado via `CommonGLPI`/`CommonDBTM`), garantindo conformidade visual com o ecossistema do GLPI:

### 1. Provedor

Permite selecionar e configurar a fonte de dados para obtenção dos feriados:

| Provedor | Características |
|---|---|
| **Brasil API** | Gratuita e sem necessidade de token, importa feriados nacionais oficiais. |
| **FeriadosAPI** | Cobertura nacional, estadual e municipal. Permite informar token de acesso, UF e código IBGE do município. |
| **Importador Governo Federal** | Importa feriados diretamente do texto oficial da Portaria do Diário Oficial da União. |

#### 📄 Portarias de Alteração (GovFederalImporter)
O campo de texto do **Importador Governo Federal** suporta colar a portaria original e, logo abaixo, as **portarias de alteração/retificação** publicadas posteriormente:
- **Detecção automática**: Identifica o padrão de remanejamento de datas (ex.: *"28 de outubro, Dia do Servidor Público federal, a ser comemorado no dia 30 de outubro"*).
- **Substituição e limpeza**: Identifica a data anterior substituída e agenda sua remoção, inserindo a nova data comemorativa na lista.
- **Visibilidade**: Apresenta na tela de sincronização manual as datas alteradas que serão removidas do GLPI ao sincronizar.

---

### 2. Sincronização Automática

| Campo | Descrição |
|---|---|
| **Sincronização automática de Ano Novo** | Checkbox que habilita a ação automática. Ao desmarcar esta caixa, a CronTask do plugin é **fisicamente desabilitada** (`STATE_DISABLE`) no motor do GLPI, economizando processamento. Quando ativada, a CronTask executa a importação dos feriados do ano corrente exatamente no dia 1º de Janeiro. |

---

### 3. Sincronização Manual

Permite importar sob demanda de forma **completamente independente** da automação, com controle detalhado:

#### Feriados do Ano (Provedor)
- Permite informar o ano desejado e clicar em **Carregar Feriados**.
- Exibe Data, Nome, indicação de Recorrente (Sim/Não) e ações para editar ou excluir itens do grid.

#### ☑️ Seleção Individual e em Massa de Feriados
- Cada feriado (nacional e local) conta com um **checkbox de seleção individual**.
- O cabeçalho de cada tabela possui um **checkbox geral para selecionar ou desmarcar todos** com um único clique.
- **Todos os feriados vêm marcados por padrão**.
- **Apenas os feriados selecionados são sincronizados** no calendário de destino. Isso permite que gestores de diferentes campi, setores ou unidades selecionem apenas os feriados aplicáveis à sua localidade.

#### 🔄 Datas Alteradas por Portaria
Quando são detectadas alterações via portaria do governo federal, é exibida uma tabela informativa listando as datas originais a serem removidas e as novas datas substitutas. Durante a sincronização manual, os feriados antigos são automaticamente excluídos da base e dos calendários do GLPI.

#### Feriados Locais
Grid interativo para gerenciar feriados municipais, estaduais ou regimentais.
| Recurso | Descrição |
|---|---|
| **Grid** | Exibe feriados locais cadastrados com Data, Nome, Recorrência e checkboxes de seleção. |
| **Adicionar/Editar** | Permite incluir um novo feriado escolhendo Dia, Mês, Nome e recorrência. Feriados Não Recorrentes são ignorados pela Sincronização Automática. |
| **Excluir** | Remove o cadastro do feriado local. |

#### Calendário Principal de Atendimento e Sincronização
- **Calendário Principal**: Dropdown para selecionar qual calendário do GLPI receberá os feriados.
- **Botão Sincronizar Agora**: Grava imediatamente os feriados selecionados no calendário e remove as datas alteradas.

---

### 4. Todos

Aba nativa do GLPI que apresenta uma visão unificada e contínua de todas as seções (Provedor, Sincronização Automática e Sincronização Manual), permitindo visualizar e ajustar todas as configurações em uma única tela.

---

## ⏰ Ação Automática (CronTask)

O plugin registra uma tarefa chamada **BrasilFeriados** nas Ações Automáticas do GLPI.

- **Frequência**: Diária (roda 1x/dia)
- **Trava de Segurança**: O código interno aborta a operação (demorando < 1ms) se o dia não for **1º de Janeiro (`01-01`)**. Isso garante precisão cirúrgica sem onerar o servidor.
- **Comportamento**: Cria o calendário do ano vigente na raiz, busca feriados no provedor configurado e cruza com a base local, registrando-os. 

> ⚠️ Para que a ação automática funcione, o CRON externo do GLPI deve estar configurado (via crontab ou agendador de tarefas do servidor).

---

## 🏗️ Estrutura do Plugin

```
brasilferiados/
├── .gitignore
├── brasilferiados.xml              # Descriptor XML
├── logo.png                        # Ícone oficial exibido no catálogo GLPI
├── setup.php                       # Inicialização, versão e pré-requisitos
├── hook.php                        # Install (cria tabelas + CronTask) / Uninstall
├── front/
│   ├── config.form.php             # Controlador da tela de configuração (abas nativas)
│   └── local.form.php              # Controller POST do CRUD de feriados locais
├── src/
│   ├── ApiProvider.php             # Interface para adaptadores de provedores
│   ├── BrasilApi.php               # Provedor Brasil API
│   ├── FeriadosApi.php             # Provedor FeriadosAPI
│   ├── GovFederalImporter.php      # Importador de portarias federais e alterações
│   ├── Local.php                   # Model de feriados locais (tabela dedicada)
│   └── Sync.php                    # Model CommonDBTM (configuração, abas, cron e sync)
├── templates/
│   ├── config.html.twig            # Template mestre de configuração
│   └── tabs/
│       ├── main_header.html.twig   # Cabeçalho unificado de configuração
│       ├── styles.html.twig        # Estilos, temas claro/escuro e tabelas responsivas
│       ├── tab_automatic.html.twig # Aba de sincronização automática
│       ├── tab_manual.html.twig    # Aba de sincronização manual com checkboxes
│       └── tab_provider.html.twig  # Aba de seleção e configuração de provedores
└── README.md                       # Documentação
```

---

## 🗄️ Banco de Dados

### Tabelas do Plugin

```sql
-- Configuração geral
glpi_plugin_brasilferiados_configs
├── id              INT (PK, AUTO_INCREMENT)
├── is_active       TINYINT (0 = desativado, 1 = ativado)
├── api_provider    VARCHAR(50)  (brasilapi, feriadosapi, importador_gov_federal)
├── api_token       VARCHAR(255)
├── api_uf          VARCHAR(2)
├── api_cidade_ibge VARCHAR(10)
└── gov_federal_text TEXT

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