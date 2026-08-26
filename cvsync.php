<?php
/**
 * Plugin Name:       CVSync
 * Description:       Bidirectional sync between Gutenberg content in the database and versioned files in the git repository (spec: cvsync v1 + Appendix A).
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            hacklab
 * License:           GPL-2.0-or-later
 * Text Domain:       cvsync
 *
 * Bootstrap do plugin (pacote P6): autoload (composer + fallback próprio),
 * defines default das constantes CVSYNC_* (§10.1 — a LEITURA é exclusiva de
 * CVSync\Environment), wiring dos hooks condicionado à matriz §7.3 e o
 * activation hook (schema §9 + pré-condição de revisions §3.2 + sonda §A.9.2).
 *
 * @package CVSync
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// ---------------------------------------------------------------------------
// Autoload
// ---------------------------------------------------------------------------

/**
 * Autoloader de fallback (sem vendor/). Cobre as DUAS convenções de arquivo
 * presentes no codebase:
 *
 *  1. PSR-4-like (P1, engine): CVSync\Engine\EntityRef →
 *     includes/engine/EntityRef.php — primeiro segmento do subnamespace em
 *     minúsculas ('Engine' → 'engine'), segmentos mais profundos preservados
 *     ('CVSync\Engine\Exception\X' → 'engine/Exception/X.php'), arquivo com o
 *     nome da classe;
 *  2. Convenção class-/interface-/abstract-/enum- (P2–P5):
 *     CVSync\Storage\StateStore → includes/class-state-store.php — o
 *     subnamespace 'Storage' mapeia para a RAIZ de includes/ (convenção do
 *     P2); demais subnamespaces viram diretório em minúsculas
 *     (CVSync\Cli\Cli → includes/cli/class-cli.php;
 *      CVSync\Media\MediaStore → includes/media/class-media-store.php;
 *      CVSync\Admin\AdminNotices → includes/admin/class-admin-notices.php).
 *     O slug do arquivo é o nome da classe em kebab-case.
 *
 * Exceções explícitas (arquivos multi-tipo ou nome fora da convenção —
 * pendência registrada pelo DBA em r2): mapeadas em
 * CVSYNC_AUTOLOAD_EXCEPTIONS abaixo.
 */
function cvsync_autoload(string $class): void
{
    if (! str_starts_with($class, 'CVSync\\')) {
        return;
    }

    /** Exceções ao mapeamento determinístico (classe FQCN => path relativo a includes/). */
    static $exceptions = [
        // P2: schema.php fora do padrão class-*.php; arquivos multi-tipo.
        'CVSync\Storage\Schema'                    => 'schema.php',
        'CVSync\Storage\LockHandle'                => 'class-locks.php',
        'CVSync\Storage\MariaDbLockHandle'         => 'class-locks.php',
        'CVSync\Storage\MigrationPendingException' => 'class-storage-exception.php',
        'CVSync\Storage\LockNotAcquiredException'  => 'class-storage-exception.php',
        'CVSync\Storage\LockViolationException'    => 'class-storage-exception.php',
        // Resultados de sync: os três VOs vivem juntos em class-sync-results.php
        // (namespace raiz CVSync). Usados em TODO import/export — sem estas
        // entradas, o primeiro sync sem vendor/ fatala.
        'CVSync\ApplyResult'  => 'class-sync-results.php',
        'CVSync\ExportResult' => 'class-sync-results.php',
        'CVSync\ImportResult' => 'class-sync-results.php',
        // P3: exceções de adapter no arquivo da família.
        'CVSync\Adapters\AdapterException'              => 'adapters/class-adapter-exceptions.php',
        'CVSync\Adapters\RejectedEntityException'       => 'adapters/class-adapter-exceptions.php',
        'CVSync\Adapters\UuidOwnershipMismatchException' => 'adapters/class-adapter-exceptions.php',
        // P3: exceções do PathGuard no mesmo arquivo.
        'CVSync\PathEscapesRootException' => 'class-path-guard.php',
        'CVSync\SymlinkTargetException'   => 'class-path-guard.php',
        // P4: exceções do MediaStore + GC com nome de arquivo abreviado.
        'CVSync\Media\OversizedException'       => 'media/class-media-store.php',
        'CVSync\Media\BinaryHashMismatchException' => 'media/class-media-store.php',
        'CVSync\Media\LfsPointerException'      => 'media/class-media-store.php',
        'CVSync\Media\MediaGarbageCollector'    => 'media/class-media-gc.php',
        // P4: DTOs readonly em arquivos multi-tipo (nome fora da convenção).
        'CVSync\Media\StoredBlob'        => 'media/class-media-store.php',
        'CVSync\Media\ValidationResult'  => 'media/class-media-validator.php',
        'CVSync\Media\SideloadOutcome'   => 'media/class-materializer.php',
        'CVSync\Media\MaterializeResult' => 'media/class-materializer.php',
        'CVSync\Media\GcReport'          => 'media/class-media-gc.php',
        // P5: Container no arquivo do Cli; comandos duplos.
        'CVSync\Cli\Container'       => 'cli/class-cli.php',
        'CVSync\Cli\CommandConflict' => 'cli/class-command-conflicts.php',
        'CVSync\Cli\CommandBlame'    => 'cli/class-command-log.php',
    ];

    $base = __DIR__ . '/includes/';

    if (isset($exceptions[$class])) {
        require $base . $exceptions[$class];
        return;
    }

    $parts     = explode('\\', substr($class, 7)); // após 'CVSync\'
    $className = array_pop($parts);

    // Diretório: 'Storage' => raiz de includes/; demais => primeiro segmento
    // em minúsculas, segmentos profundos preservados (engine/Exception/...).
    $dir = '';
    if ([] !== $parts) {
        $first = array_shift($parts);
        $dir   = 'Storage' === $first ? '' : lcfirst($first) . '/';
        if ([] !== $parts) {
            $dir .= implode('/', $parts) . '/';
        }
    }

    $slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $className));

    foreach (
        [
            $dir . $className . '.php',        // PSR-4-like (engine/)
            $dir . 'class-' . $slug . '.php',
            $dir . 'interface-' . $slug . '.php',
            $dir . 'abstract-' . $slug . '.php',
            $dir . 'enum-' . $slug . '.php',
            $dir . $slug . '.php',             // slug puro (abstract-post-adapter.php)
        ] as $file
    ) {
        if (is_file($base . $file)) {
            require $base . $file;
            return;
        }
    }
}

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    // Fallback obrigatório: o plugin funciona sem vendor/ (o parser YAML
    // próprio do P1 cobre a ausência de symfony/yaml).
    spl_autoload_register('cvsync_autoload');
}

// ---------------------------------------------------------------------------
// Defines default (§10.1) — nunca sobrescrevem wp-config/env.
// A LEITURA validada é exclusiva de CVSync\Environment::constant().
// ---------------------------------------------------------------------------

/**
 * Raiz do repositório git que hospeda o content dir (§4.1).
 *
 * Detecção (documentada — em dúvida, defina CVSYNC_CONTENT_DIR no wp-config):
 *  1. Sobe a partir do diretório do plugin até o primeiro ancestral com
 *     `.git` (diretório ou gitfile de worktree/submódulo). Neste projeto o
 *     plugin vive em `<repo>/plugins/cvsync/` → encontra `<repo>`. O
 *     diretório do próprio plugin é pulado (o plugin pode ser um submódulo —
 *     o content pertence ao superprojeto);
 *  2. Instalação WP padrão com o repo na raiz do WordPress: ABSPATH/.git;
 *  3. Fallback: dirname(ABSPATH) — alinhado ao fallback de
 *     Environment::contentDir() (content/ como irmão do WP root).
 */
function cvsync_detect_repo_root(): string
{
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
        if (file_exists($dir . '/.git')) {
            return $dir;
        }
    }

    if (file_exists(ABSPATH . '.git')) {
        return rtrim(ABSPATH, '/');
    }

    return dirname(ABSPATH);
}

/**
 * Define uma constante CVSYNC_* honrando a precedência §10.1:
 * constante em wp-config (tier 1, `defined()` curto-circuita) > variável de
 * ambiente (tier 2) > default hardcoded (tier 3, alinhado ao registry do
 * Environment).
 *
 * Sem este helper, `defined() || define(..., default)` tornaria o ramo
 * getenv() do Environment::constant() código morto (a constante sempre
 * existiria) — uma env var CVSYNC_* nunca teria efeito.
 *
 * Regras:
 *  - env presente e VÁLIDA → define a partir da env (normalizada para o tipo
 *    esperado pelos consumidores diretos: int para modos/bytes);
 *  - env presente mas INVÁLIDA → NÃO define: a leitura cai no
 *    Environment::constant(), que lê a env crua, aplica o default fail-safe
 *    e registra o warning §10.1 (typo nunca vira default silencioso);
 *  - env ausente → define o default hardcoded.
 *
 * @param callable(string): mixed $envValidator Devolve o valor normalizado ou null (inválido).
 */
function cvsync_define_from_env(string $name, callable $envValidator, mixed $default): void
{
    if (defined($name)) {
        return; // tier 1: wp-config vence sempre.
    }

    $env = getenv($name);
    if (false !== $env && '' !== $env) {
        $value = $envValidator($env);
        if (null !== $value) {
            define($name, $value); // tier 2: env validada.
        }
        // Env inválida: não sombrear — o Environment lê a env crua, aplica o
        // default fail-safe e emite o admin notice (§10.1).
        return;
    }

    define($name, $default); // tier 3: default hardcoded.
}

cvsync_define_from_env(
    'CVSYNC_CONTENT_DIR',
    static fn (string $v): ?string => ! str_contains($v, "\0") ? $v : null,
    cvsync_detect_repo_root() . '/content'
);
cvsync_define_from_env(
    'CVSYNC_FILE_MODE',
    // Octal → int (consumidores diretos fazem (int) constant() p/ chmod).
    static fn (string $v): ?int => preg_match('/^0?[0-7]{3,4}$/', $v) === 1 ? octdec($v) : null,
    0664
);
cvsync_define_from_env(
    'CVSYNC_DIR_MODE',
    static fn (string $v): ?int => preg_match('/^0?[0-7]{3,4}$/', $v) === 1 ? octdec($v) : null,
    0775
);
// Constantes de mídia: o validador vive EXCLUSIVAMENTE no registry do
// Environment (única fonte §10.1 — não duplicar a lógica aqui). O bootstrap
// resolve via Environment::constant() — que aplica const > env > default com
// validação + warning fail-safe — e define o valor JÁ RESOLVIDO para os
// consumidores que ainda leem a constante diretamente. Assim:
//  (a) env inválida NUNCA é definida crua — o define recebe o default
//      fail-safe e o warning §10.1 já foi registrado pelo Environment;
//  (b) tipos normalizados: int para MAX_BYTES, CSV validado por-entrada para
//      MIME_TYPES, bool para ALLOW_SVG (raw readers comparam `=== true` —
//      definir false por default não altera comportamento);
//  (c) wp-config vence: constante já definida → Environment a lê e valida,
//      e o define abaixo é pulado.
foreach (
    [
        'CVSYNC_ATTACHMENT_MAX_BYTES',  // §A.5.4
        'CVSYNC_ATTACHMENT_MIME_TYPES', // §A.5.1.1
        'CVSYNC_ATTACHMENT_ALLOW_SVG',  // §A.9.3 — default-deny
    ] as $mediaConst
) {
    if (! defined($mediaConst)) {
        define($mediaConst, \CVSync\Environment::constant($mediaConst));
    }
}

// ---------------------------------------------------------------------------
// Activation hook
// ---------------------------------------------------------------------------

register_activation_hook(__FILE__, 'cvsync_activate');

/**
 * Ativação:
 *  1. PRÉ-CONDIÇÃO DURA §3.2: todo post type versionado (filtro
 *     'cvsync/post_types' aplicado) DEVE ter suporte a revisions — exceção
 *     declarada: attachment (errata E4). Falha → erro claro, ativação negada;
 *  2. Schema §9 (dbDelta idempotente — Schema::install() do P2);
 *  3. Sonda PHP-off de uploads (§A.9.2): atalho .htaccess + sonda HTTP (esta
 *     última só em CLI — em ativação web o resultado típico é o atalho ou
 *     INDETERMINATE). FALHA → admin notice crítico persistente; NUNCA
 *     bloqueia a ativação.
 */
function cvsync_activate(): void
{
    global $wpdb;

    // 1. Pré-condição dura §3.2 (assertOperable() é do P3 — inclui a
    //    exceção E4 de attachment e o erro de post type não registrado).
    try {
        $adapters = \CVSync\Adapters\AdapterRegistry::withDefaults(
            new \CVSync\Storage\StateStore($wpdb),
            new \CVSync\Adapters\ReferenceResolver(),
            new \CVSync\PathGuard(\CVSync\Environment::contentDir())
        );
        $adapters->assertOperable();
    } catch (\Throwable $e) {
        wp_die(
            sprintf(
                /* translators: %s: mensagem da pré-condição violada. */
                esc_html__('CVSync: pré-condição §3.2 violada — %s Habilite revisions no post type (supports) ou remova-o do filtro cvsync/post_types e tente ativar novamente.', 'cvsync'),
                esc_html($e->getMessage())
            ),
            esc_html__('CVSync — ativação recusada', 'cvsync'),
            ['back_link' => true]
        );
    }

    // 2. Schema (§9). Falha de DDL: log interno + gate §5.9 recusa operação —
    //    não bloqueia a ativação (decisão do P2).
    \CVSync\Storage\Schema::install();

    // 3. Sonda PHP-off (§A.9.2). Persiste o resultado para o notice admin.
    if (class_exists(\CVSync\Media\PhpExecProbe::class)) {
        $result = ( new \CVSync\Media\PhpExecProbe() )->check();
        update_option(
            \CVSync\Admin\AdminNotices::OPTION_PROBE,
            [
                'status' => (string) ($result['status'] ?? 'indeterminate'),
                'detail' => (string) ($result['detail'] ?? ''),
                'at'     => time(),
            ],
            false
        );
    }
}

// ---------------------------------------------------------------------------
// Apêndice B — termos de taxonomia (componentes do CMS; wiring do bootstrap)
// ---------------------------------------------------------------------------

/**
 * Registra os TermAdapters (Apêndice B) quando ainda não registrados pelo
 * próprio registry do CMS (P3) — cobre o caso de o filtro 'cvsync/taxonomies'
 * ser adicionado DEPOIS da construção do Container (tema em init tardio):
 * o registry leu o filtro vazio e os adapters de termo não existem lá.
 *
 * Estágio 0 (B.6.2 — a ordem interna attachments→termos é garantida pelo
 * byStage() do registry, que pós-ordena o estágio 0). Construção: UM adapter
 * por taxonomia — assinatura real do CMS: (state, resolver, paths, taxonomy,
 * dir, metaWhitelist), com defaults derivados idênticos aos do registry.
 *
 * Degradação graciosa: sem a classe, nada muda. Se o filtro optou por
 * taxonomias e o componente falta, sinaliza via error_log (nunca silencioso).
 */
function cvsync_register_term_adapter(\CVSync\Cli\Container $container): void
{
    $adapterClass = \CVSync\Adapters\TermAdapter::class;

    // Idempotente: registry do CMS já cobre TODAS as taxonomias do filtro?
    $configured = cvsync_configured_taxonomies();
    $registered = $container->adapters->versionedTaxonomies();
    $missing    = array_diff($configured, $registered);
    if ([] === $missing) {
        return;
    }

    if (! class_exists($adapterClass)) {
        if ([] !== $configured) {
            error_log(sprintf(
                'cvsync: filtro cvsync/taxonomies configurado (%d taxonomia(s)) mas %s ausente — termos NAO serao versionados (Apendice B).',
                count($configured),
                $adapterClass
            ));
        }

        return;
    }

    $whitelistDefault = ['thumbnail_id'];
    foreach ($missing as $taxonomy) {
        try {
            $container->adapters->register(
                new $adapterClass(
                    $container->state,
                    $container->resolver,
                    $container->paths,
                    $taxonomy,
                    str_replace(['_', '.'], '-', $taxonomy) . 's', // 🟡B4: dir default sanitizado (idem registry)
                    $whitelistDefault
                ),
                0
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                'cvsync: falha ao registrar %s para "%s" (%s) — prosseguindo sem esta taxonomia.',
                $adapterClass,
                $taxonomy,
                $e->getMessage()
            ));
        }
    }
}

/**
 * Taxonomias do filtro 'cvsync/taxonomies' (default VAZIO, B.1.1) — leitura
 * única, mesmos defaults derivados do AdapterRegistry::taxonomyConfig().
 *
 * @return list<string>
 */
function cvsync_configured_taxonomies(): array
{
    $configured = apply_filters('cvsync/taxonomies', []);
    $taxonomies = [];
    foreach ((array) $configured as $key => $value) {
        $taxonomies[] = is_int($key) ? (string) $value : (string) $key;
    }

    return array_values(array_unique($taxonomies));
}

/**
 * Hooks do ciclo de vida de termos (B.2.4 — created/edited/pre_delete/
 * delete_term + term meta): VIVEM no \CVSync\Hooks do P3 (register() inclui a
 * família desde a implementação do CMS — mesmo gate export_auto, mesmos
 * guards ImportGuard/taxonomy/_cvsync_*). Nenhuma classe TermHooks separada
 * existe ou é necessária; esta função permanece como ponto de extensão caso
 * um componente dedicado surja no futuro (probe + wiring pelo precedente).
 */
function cvsync_register_term_hooks(\CVSync\Cli\Container $container): void
{
    foreach ([\CVSync\TermHooks::class, \CVSync\Adapters\TermHooks::class] as $candidate) {
        if (class_exists($candidate)) {
            try {
                ( new $candidate(
                    $container->adapters,
                    $container->state,
                    $container->exporter,
                    $container->guard
                ) )->register();
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'cvsync: falha ao registrar %s (%s) — export automatico de termos desativado.',
                    $candidate,
                    $e->getMessage()
                ));
            }

            return;
        }
    }

    // B.2.4 já coberto pelo \CVSync\Hooks::register() (wiring do bloco gated).
}

// ---------------------------------------------------------------------------
// Wiring (init@1: após plugins E tema — filtros cvsync/post_types do tema já
// registrados; Hooks adia os save_post_* para init@1000 internamente)
// ---------------------------------------------------------------------------

add_action('init', 'cvsync_bootstrap', 1);

/**
 * Montagem dos serviços e registro dos hooks — condicionado à matriz §7.3:
 *
 *  - WP-CLI: Cli::register() (contrato §8.3) — o próprio P5 re-valida;
 *  - Triggers (check passivo HEAD-hash, §8.2): sempre registrado; o gate de
 *    apply_auto é interno (prod nunca agenda reconcile);
 *  - Hooks de EXPORT automático (P3) + MediaHooks (P4) + TermHooks
 *    (Apêndice B): SOMENTE quando Environment::policy()['export_auto'] — em
 *    prod ficam OFF (export manual via CLI é livre, read-only no banco);
 *  - Admin: notices (Environment warnings §10.1 + AdminNotices), metabox de
 *    blame (§11.1) e tela em Ferramentas (§10 capabilities).
 *
 * O grafo de serviços vem da fábrica do P5 (Cli::container()) — sem dupla
 * montagem (contrato declarado em class-cli.php).
 */
function cvsync_bootstrap(): void
{
    if (defined('WP_CLI') && WP_CLI) {
        \CVSync\Cli\Cli::register();
    }

    $container = \CVSync\Cli\Cli::container();

    // Apêndice B — TermAdapter no estágio 0 (quando o componente do CMS
    // existe). Fora do gate export_auto: o adapter serve CLI apply/export/
    // verify/bootstrap em QUALQUER ambiente (prod incluso).
    cvsync_register_term_adapter($container);

    // Check passivo (§8.2) — gate de apply_auto interno ao Triggers.
    \CVSync\Cli\Cli::triggers()->register();

    // Export automático — gate §7.3 (prod: OFF).
    if (\CVSync\Environment::policy()['export_auto']) {
        ( new \CVSync\Hooks(
            $container->adapters,
            $container->state,
            $container->exporter,
            $container->guard
        ) )->register();

        // P4 (mídia) — só presente quando o container montou o estágio 0.
        // Lookup via forRef(EntityRef::post(...)): o registry pós-Apêndice B
        // não expõe mais forPostType() — kind 'post' consulta o mesmo mapa
        // interno byPostType (a key do probe é irrelevante).
        $attachmentAdapter = $container->adapters->forRef(
            \CVSync\Engine\EntityRef::post('attachment', 'cvsync:probe')
        );
        if ($attachmentAdapter instanceof \CVSync\Media\AttachmentAdapter
            && null !== $container->referenceGraph
        ) {
            ( new \CVSync\Media\MediaHooks(
                $attachmentAdapter,
                $container->referenceGraph,
                $container->state,
                $container->guard
            ) )->register();
        }

        // Apêndice B — hooks de termos (B.2.4), mesmo gate dos demais.
        cvsync_register_term_hooks($container);
    }

    // Superfícies admin (§10 capabilities).
    if (is_admin()) {
        \CVSync\Environment::registerAdminNotices(); // warnings §10.1 (renderer do P5)

        ( new \CVSync\Admin\AdminNotices(
            $container->state,
            $container->conflicts,
            $container->log
        ) )->register();

        ( new \CVSync\Admin\BlameMetabox(
            $container->adapters,
            $container->log
        ) )->register();

        ( new \CVSync\Admin\ToolsPage(
            $container->log,
            $container->conflicts
        ) )->register();
    }
}
