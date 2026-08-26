# CVSync

Sincronização bidirecional entre o conteúdo Gutenberg no banco de dados e
arquivos versionados no repositório git (`content/`). O banco é a autoridade
em tempo de edição; o repositório é a autoridade de transporte entre
ambientes (spec: cvsync v1 + Apêndice A).

## O que faz

- **Export (banco → arquivo):** edições no admin marcam a entidade como dirty
  e o export acontece no `shutdown` do request, lendo o estado final do banco
  (debounce natural). Arquivos canônicos: frontmatter YAML + corpo de blocos,
  com placeholders no lugar de IDs internos (`{{ref:slug}}`,
  `{{attachment:slug}}`, `{{attachment_url:slug}}`, `{{term:taxonomy:slug}}`,
  `{{home_url}}`).
- **Apply (arquivo → banco):** `wp sync apply` aplica o conteúdo do
  repositório no banco, com detecção de conflitos (3-way por hash),
  preservação do lado perdedor, snapshot pré-apply e audit log.
- **Entidades versionadas:** páginas, padrões (`wp_block`), templates e
  template parts, navegações (`wp_navigation`), menus, global styles,
  branding (logo/ícone do site), anexos de mídia (sidecar + blob) e —
  opcional, desligado por default — termos de taxonomia (Apêndice B).
- **Ambientes:** matriz normativa por ambiente (local/staging/homolog/prod) —
  em produção, apply e export automáticos ficam OFF (apply manual exige
  `--force` + TTY + `CVSYNC_ALLOW_PROD_APPLY`).

## Instalação

1. Copie `plugins/cvsync/` para `wp-content/plugins/` (ou monte como
   submódulo git).
2. **Opcional:** `composer install` dentro do diretório do plugin (instala
   `symfony/yaml` e o autoloader otimizado). Sem `vendor/`, o plugin funciona
   com o autoloader próprio de fallback e o parser YAML interno.
3. Ative o plugin. A ativação:
   - verifica a pré-condição dura: todo post type versionado precisa de
     suporte a **revisions** (exceção: `attachment`);
   - instala as tabelas `wp_cvsync_state`, `wp_cvsync_conflicts`,
     `wp_cvsync_log`;
   - executa a sonda PHP-off de uploads (`.htaccess`/sonda HTTP em CLI) —
     falha gera admin notice crítico, sem bloquear a ativação.
4. Instale os git hooks: `wp sync install-hooks`.
5. Defina o ambiente via `WP_ENVIRONMENT_TYPE` (core) e, quando necessário,
   `CVSYNC_ENVIRONMENT` (única porta para `homolog`).

## Constantes `CVSYNC_*`

Precedência: **constante em `wp-config.php` > variável de ambiente > default**.
Nenhuma flag vive no banco (options viajam com dumps). Valor inválido em
qualquer constante → **default fail-safe + admin notice** (nunca silencioso).

| Constante | Valores | Default | Notas |
|---|---|---|---|
| `CVSYNC_ENVIRONMENT` | `local` \| `staging` \| `homolog` \| `prod` | derivado de `wp_get_environment_type()`; desconhecido → `prod` (fail-closed) | `homolog` só via constante explícita |
| `CVSYNC_CONFLICT_WINNER` | `db` \| `file` | por ambiente (matriz §7.3) | CI staging define `file` no job |
| `CVSYNC_DEPLOY_GATE` | `warn` \| `halt` | `warn` | `halt` = apply falha em conflito (exit 2) |
| `CVSYNC_ALLOW_PROD_APPLY` | `true` | ausente = negado | pré-condição de `--force` em prod |
| `CVSYNC_IMPORT_USER` | login ou ID | primeiro administrador | usuário técnico do import |
| `CVSYNC_CONTENT_DIR` | path | `<repo-root>/content` | detecção: primeiro ancestral do plugin com `.git`; fallback `dirname(ABSPATH)`. Nunca em tema/plugin/uploads |
| `CVSYNC_FILE_MODE` / `CVSYNC_DIR_MODE` | octal | `0664` / `0775` | nunca depender de umask de request |
| `CVSYNC_ATTACHMENT_MAX_BYTES` | bytes | `10485760` (10 MB) | teto único de anexo |
| `CVSYNC_ATTACHMENT_MIME_TYPES` | lista CSV | `image/jpeg,image/png,image/webp,image/gif,application/pdf` | whitelist estática (interseção com `get_allowed_mime_types()`) |
| `CVSYNC_ATTACHMENT_ALLOW_SVG` | `true` \| `false` | `false` (default-deny) | opt-in de SVG; exige sanitizador (§A.9.3) |
| `CVSYNC_ATTACHMENT_SCOPE` | `referenced` \| `all` | `referenced` | `all` exporta todo upload; typo NUNCA vira `all` silencioso |
| `CVSYNC_SNAPSHOT_KEEP` | int | `10` | retenção de snapshots pré-apply |
| `CVSYNC_SNAPSHOT_MAX_BYTES` | bytes | `536870912` (512 MB) | teto de disco com purge LRU |
| `CVSYNC_TOMBSTONE_TTL_DAYS` | int | `90` | retenção de tombstones |

## `cvsync.json` × constantes `CVSYNC_*`

São duas superfícies de configuração com papéis distintos (§A.13.10):

- **Constantes** configuram o **runtime** (apply/export dentro do WordPress).
- **`cvsync.json`** (na raiz do repo) configura o **lint de CI**
  (`bin/cvsync-lint.php`) — o CI não lê `wp-config.php`.

O apply loga warning quando a config do lint e as constantes divergirem.
Mantenha as duas em sincronia (mesmos limites de tamanho/MIME).

## Termos de taxonomia (Apêndice B)

Versionamento **opcional** da definição editorial de termos (name, slug,
description, parent por slug e meta da whitelist). **Desligado por default**:
nada é versionado até o projeto optar via filtro `cvsync/taxonomies` (B.1.1):

```php
add_filter('cvsync/taxonomies', fn () => [
    'category'    => ['dir' => 'categories'],
    'projeto_tag' => [], // item com valor simples: defaults derivados
]);
```

- Item com valor simples → defaults: diretório `{taxonomy}s` e whitelist de
  meta `['thumbnail_id']`; item associativo sobrescreve (`dir`, `meta`).
- **Deny-list** (erro claro na ativação): `nav_menu`, `wp_theme`,
  `wp_pattern_category`, `wp_template_part_area`, `link_category`,
  `post_format` e taxonomias não-públicas.
- Um arquivo por termo, layout plano (hierarquia é campo, não path):
  `content/terms/{dir}/{slug}.term.yml`:

```yaml
# content/terms/categories/noticias.term.yml
uuid: 018f4b2e-7c3a-7d4e-9a1f-5e7c9b2d1e44  # identidade (termmeta _cvsync_uuid) — NUNCA no hash
taxonomy: category                            # hash: entra
slug: noticias                                # hash: entra
name: "Notícias"                              # hash: entra
description: "Conteúdo jornalístico"          # hash: entra
parent: null                                  # hash: entra — SLUG do pai, nunca term_id
meta:
  thumbnail_id: "{{attachment:logo}}"         # placeholderizado (§A.6)
hash: sha256:9f2c71ab…                        # derivado — última linha
```

- A **associação** post↔termo continua no frontmatter do post (ortogonal à
  **definição** do termo — B.6.1); terms são aplicados no estágio 0, antes
  dos posts.
- Export bulk: `wp sync export --taxonomy=<tax>` (sem flag = todas as
  taxonomias versionadas).
- Erratas à spec v1: **E2-bis** (updates de count e
  `edited_term_taxonomies` ficam fora do dirty-marking — import não suja a
  fila) e **E5-bis** (delete de termo não tem trash — a rede de segurança é
  git + conflicts + tombstone + dirty-mark reverso).

## Regras de ouro

- **`content/**` é artefato gerado que é versionado: nunca editar à mão.**
  Mudanças manuais serão perdidas no próximo export (§12.2).
- O plugin **nunca roda git no runtime web**, nunca commita, nunca escreve em
  `.git`.
- Commit de conteúdo é decisão humana: edite no admin → export automático →
  `git add content/... && git commit` → PR com review humano do diff.
- Um worktree ↔ um banco/ambiente WP.
- Nunca force-push em branch compartilhada de conteúdo.

## CLI (contrato §8.3)

Todos os comandos vivem no namespace `wp sync`. Neste projeto, rode dentro do
container:

```bash
docker compose exec -T wordpress wp sync <comando>
```

Convenções gerais (de `CommandBase`, aplicam-se a quase todos):

- `--format=json` emite saída estruturada (uma linha JSON por item + resumo);
- constantes `CVSYNC_*` com valor inválido geram **warning no início** de
  qualquer comando (nunca passam silenciosas);
- **comandos de mutação** (`apply`, `bootstrap`, `resolve`, `restore`)
  respeitam a matriz de ambientes: em local/staging/homolog são livres via
  CLI; em **prod** exigem o triplo fator (`--force` + TTY interativo +
  `CVSYNC_ALLOW_PROD_APPLY=true`) — stdin não-TTY é **recusado**;
- `export`, `plan`, `verify`, `status`, `log`, `blame`, `conflicts` são
  **read-only no banco** e livres em qualquer ambiente (inclusive prod).

### Tabela-resumo

| Comando | O que faz | Mutação? | Exit codes |
|---|---|---|---|
| `apply` | Reconcilia arquivos → banco | sim | 0 ok · 1 falha/recusa · 2 deploy_gate=halt com conflito · **3 migration pendente** |
| `plan` | Dry-run do plano completo | não | 0 ok · 1 erro de plano · **3 migration pendente** |
| `export` | Exporta banco → arquivos (bulk/filtros) | não (banco) | 0 ok · 1 erro ou diff no `--check` · **3 migration pendente** |
| `bootstrap` | Seed inicial da state table | sim | 0 ok · 1 falhas · **3 migration pendente** |
| `verify` | Recalcula hashes dos dois lados × state | não | 0 convergente · ≠0 divergência |
| `status` | Visão geral do sync no ambiente | não | 0 sempre |
| `log` | Audit trail recente | não | 0 sempre |
| `blame` | "Por que esta entidade mudou?" | não | 0 sempre |
| `conflicts` | Lista conflitos pendentes / prune | não / prune | 0 ok · 1 erro |
| `conflict show` | Despeja o payload do lado perdedor | não | 0 ok · 1 id inexistente |
| `resolve` | Resolução manual de conflito | sim | 0 ok · 1 erro/sem pendente |
| `rebase` | Re-alinha state sem aplicar mudanças | state apenas | 0 ok · 1 erros |
| `restore` | Re-aplica snapshot pré-apply | sim | 0 ok · 1 snapshot inexistente |
| `install-hooks` | Aponta git para `.githooks/` | git config | 0 ok · 1 sem git/.githooks |
| `purge-revisions` | Remove revisions antigas (com retenção) | sim (seguro) | 0 sempre |
| `attachments gc` | GC de blobs do repo / arquivos de uploads | dry-run default | 0 sempre |

### Reconciliação

#### `wp sync apply`

Aplica o conteúdo do repositório no banco (arquivo → banco). Detecta
conflitos 3-way por hash, preserva o lado perdedor na tabela de conflitos,
tira snapshot pré-apply e registra tudo no audit log. Entidades com editor
lock ativo são puladas (`skipped-locked` — conta como falha para o exit
code; o retry é natural no próximo checkpoint).

| Flag | Descrição | Default |
|---|---|---|
| `--dry-run` | Simula sem escrever (equivale ao `plan`, com gates de ambiente desligados) | off |
| `--force` | 1º fator do apply em prod | off |
| `--force-locks` | Sobrescreve entidades com editor lock — **só com TTY interativo** | off |
| `--delete` / `--force-delete` | Autoriza deleções pendentes (respeitam a política do ambiente: trash em local/staging; homolog é trash-only e recusa `--force-delete`; prod nunca deleta) | off |
| `--format=json` | Saída estruturada | off |

Exit codes: `0` sucesso (conflitos auto-resolvidos **não** sobem exit code) ·
`1` houve falhas ou recusa de ambiente/lock · `2`
`CVSYNC_DEPLOY_GATE=halt` e houve conflito auto-resolvido no lote · `3`
migration de schema pendente (fail-closed §5.9 — recusa imediata com ação
prescritiva, sem resumo; reative o plugin ou rode a migration no pipeline).

```bash
docker compose exec -T wordpress wp sync apply --dry-run   # ensaio
docker compose exec -T wordpress wp sync apply             # aplica de verdade (local)
```

#### `wp sync plan`

Mostra o plano completo sem aplicar nada: o que seria importado, exportado,
os conflitos, deleções pendentes e purges de tombstone. Ideal para review de
PR e pipeline. Exit `0`/`1` (`1` = erro de plano) · `3` migration pendente
(recusa imediata com ação prescritiva).

```bash
docker compose exec -T wordpress wp sync plan
```

#### `wp sync export`

Exporta o banco para os arquivos (bulk, recuperação ou captura inicial).
**Livre em prod** (read-only no banco) — é a porta para trazer conteúdo do
cliente de volta ao repo.

| Flag | Descrição | Default |
|---|---|---|
| `--post-type=<type>` | Restringe a um post type (inclui `attachment`, `nav_menu`, `wp_global_styles`, `branding`) | todos |
| `--taxonomy=<tax>` | Restringe a uma taxonomia versionada (Apêndice B) | todas as versionadas |
| `--scope=referenced\|all` | Escopo de attachments: só referenciados ou biblioteca inteira | `referenced` |
| `--batch=<n>` | Tamanho do lote (chunking retomável por idempotência) | `50` |
| `--out=<dir>` | Destino alternativo (ex.: captura de prod em dir temporário — não toca o FS do deploy) | content dir real |
| `--check` | Modo CI: **falha (exit 1) se o export geraria diff** — gate de idempotência | off |
| `--format=json` | Saída estruturada | off |

```bash
docker compose exec -T wordpress wp sync export --check    # CI: repo está em sincronia?
docker compose exec -T wordpress wp sync export --post-type=attachment --scope=all
```

#### `wp sync bootstrap`

Seed inicial da state table — a ausência de state nunca é inferida, este
comando é a porta explícita. Comando de mutação (prod: triplo fator).

| Flag | Descrição | Default |
|---|---|---|
| `--from=files` | Repo é a autoridade: importa o que falta no banco, recria linhas convergentes silenciosamente, marca divergências como `conflict` (nunca adivinha) | **default** |
| `--from=db` | Banco é a autoridade: exporta cada entidade do escopo | — |
| `--force` | 1º fator em prod | off |

```bash
docker compose exec -T wordpress wp sync bootstrap           # ambiente novo, repo cheio
docker compose exec -T wordpress wp sync bootstrap --from=db # banco legado, repo vazio
```

### Inspeção e diagnóstico

#### `wp sync status`

Visão geral: ambiente efetivo, política da matriz, versão do schema,
contagens por status na state table, HEAD do repo × último HEAD aplicado.
Exit `0` sempre (observação pura).

```bash
docker compose exec -T wordpress wp sync status
```

#### `wp sync verify`

Recalcula os hashes dos dois lados e compara com a state table. Relatório por
entidade (`ok`, `drift-db`, `drift-file`, `orphan`, `pending_ref`,
`conflict`, `missing_binary`, `oversized-untracked`) + seções agregadas
(tree-hash por tipo, drift externo de otimizadores, sonda PHP-off de
uploads). É o comando de monitoramento em prod e o gate de CI/pós-deploy.

| Flag | Descrição | Default |
|---|---|---|
| `--deep` | Re-hash dos blobs binários (única varredura de disco em massa — uso sob demanda explícita) | off |
| `--format=json` | Saída estruturada | off |

Exit: `≠ 0` em qualquer divergência (apto para CI). Sonda PHP-off: `FAIL` →
exit `≠ 0`; `INDETERMINATE` → warning, exit `0` (nunca trava operação por
não-verificabilidade).

```bash
docker compose exec -T wordpress wp sync verify
```

#### `wp sync log [--last=50]`

Audit trail recente: entidade, direção, gatilho, actor, resultado, arquivo,
hash antes/depois. Exit `0` sempre.

```bash
docker compose exec -T wordpress wp sync log --last=20
```

#### `wp sync blame <entidade> [--last=20]`

Responde "por que esta página mudou?": últimas aplicações da entidade, com
gatilho, arquivo e hashes. `<entidade>` = `post_type:slug` |
`kind:post_type:key` | `uuid`. Exit `0` sempre.

```bash
docker compose exec -T wordpress wp sync blame page:home
```

### Conflitos

#### `wp sync conflicts`

Lista os conflitos pendentes (entidade, lado perdedor, vencedor, gatilho,
actor, HEAD do git). Exit `0`.

#### `wp sync conflict show <id> [--out=<path>]`

Despeja o payload preservado do lado perdedor de um conflito — na tela ou em
arquivo (`--out`). Exit `1` se o id não existir.

#### `wp sync resolve <entidade> --keep=db|file [--force]`

Resolução manual: aplica o vencedor escolhido e fecha administrativamente os
conflitos pendentes da entidade. `--keep=db` re-exporta a partir do banco
(lossless; o working tree diverge do HEAD — commit depois); `--keep=file`
importa o arquivo (sempre cria revisions). Mutação: prod exige triplo fator.

```bash
docker compose exec -T wordpress wp sync conflicts
docker compose exec -T wordpress wp sync conflict show 3
docker compose exec -T wordpress wp sync resolve page:home --keep=db
```

#### `wp sync conflicts prune [--older-than=90d] [--all-resolved]`

Housekeeping da tabela de conflitos: remove registros **já resolvidos** mais
antigos que o cutoff (`90d` default; `--all-resolved` remove todos os
resolvidos). Exit `0`.

### Manutenção

#### `wp sync rebase --from=db|files`

Recalcula a state table **sem aplicar mudanças** (não importa nem exporta) —
re-alinha os hashes observados do lado escolhido à realidade. Caso de uso:
após rename de tema (o global styles namespaced pelo stylesheet antigo vira
órfão). Divergências resultantes aparecem no `plan`/`verify` seguintes.
Exit `0`/`1`.

#### `wp sync restore <timestamp> [--force]`

Re-aplica um snapshot pré-apply (rede de segurança): reimporta o estado
preservado em `uploads/cvsync-backups/<ts>/` e re-materializa binários
ausentes (aditivo — nunca sobrescreve byte alheio). Sem argumento, lista os
snapshots disponíveis. Mutação: prod exige triplo fator. Exit `1` se o
snapshot não existir.

```bash
docker compose exec -T wordpress wp sync restore 20260804-133000
```

#### `wp sync purge-revisions [--older-than=90d]`

Contenção de volume de revisions (o import sempre cria revisions; a
contenção é purge documentado, nunca supressão). **Nunca** remove a revision
mais recente de cada post nem toca posts que não são revisions. Exit `0`
sempre (falhas individuais são warnings).

#### `wp sync attachments gc (--blobs|--files) [--execute] [--older-than=90d]`

Os dois coletores de lixo de mídia, **dry-run por default** (`--execute`
aplica):

- `--blobs` — GC do repo: blob sem sidecar referenciando e sem linha
  não-tombstone na state. A remoção efetiva é commit **humano** em PR próprio
  (`chore(media): gc`) — o plugin lista, nunca commita;
- `--files` — GC físico de uploads: arquivo sem `_wp_attached_file`
  correspondente (o guard cruzado inclui thumbnails e originais `-scaled` via
  `_wp_attachment_metadata`; nunca varre `uploads/cvsync-backups/**`).

Em prod, recomenda-se `--older-than=180d`. Exit `0`.

```bash
docker compose exec -T wordpress wp sync attachments gc --files             # dry-run
docker compose exec -T wordpress wp sync attachments gc --files --execute --older-than=180d
```

### Setup

#### `wp sync install-hooks`

Aponta o git para os hooks versionados (`git config core.hooksPath
.githooks`). É o único comando que invoca o binário git (permitido em SAPI
CLI; o runtime web nunca roda git). Exit `1` se não houver `.git` (artefato
deployado) ou `.githooks/`.

```bash
docker compose exec -T wordpress wp sync install-hooks
```

### Fluxos comuns

**1. Primeiro uso (repo já tem conteúdo versionado):**

```bash
docker compose exec -T wordpress wp sync install-hooks
docker compose exec -T wordpress wp sync bootstrap        # importa o repo, cria a state
docker compose exec -T wordpress wp sync status           # confere o resultado
```

**2. Puxei código do colega (`git pull` trouxe `content/**` novo):**

```bash
# O hook post-merge já tenta o apply automaticamente. Para conferir/reenviar:
docker compose exec -T wordpress wp sync plan
docker compose exec -T wordpress wp sync apply
```

**3. Apareceu conflito, e agora?**

```bash
docker compose exec -T wordpress wp sync conflicts                 # o que está pendente
docker compose exec -T wordpress wp sync conflict show <id>        # vê o lado perdedor
docker compose exec -T wordpress wp sync resolve page:home --keep=db   # escolhe o vencedor
# --keep=db: re-exporta do banco → git add content/... && git commit (o tree diverge do HEAD)
```

**4. Capturar mídia de produção para o repo (FS de prod é imutável):**

```bash
# No servidor de prod (export é livre, read-only no banco):
wp sync export --post-type=attachment --scope=all --out=/tmp/cvsync-capture
# Copia /tmp/cvsync-capture para a estação, merge no content/ do repo, PR com review.
```

**5. Verify em CI / pós-deploy:**

```bash
# CI: o repo deve estar em sincronia com o banco de homolog
wp sync verify --format=json          # exit ≠ 0 falha o pipeline
# Gate de idempotência: export não pode gerar diff
wp sync export --check                # exit 1 se geraria diff
```

## Observabilidade

- Audit log (`wp_cvsync_log`), conflitos (`wp_cvsync_conflicts`) e estado
  (`wp_cvsync_state`).
- Admin: metabox de origem na tela de edição, notices (drift em prod,
  conflitos em homolog, sonda PHP-off, constantes inválidas) e
  Ferramentas > CVSync (log + conflitos).
- Hooks para notificação: `cvsync_applied`, `cvsync_failed`,
  `cvsync_conflict_registered`, `cvsync_files_materialized`.
