<?php
/**
 * Cli — registrador dos comandos `wp sync` (contrato §8.3) e fábrica do grafo
 * de serviços do plugin (container).
 *
 * P6 chama exclusivamente:
 *
 *     if (defined('WP_CLI') && WP_CLI) {
 *         \CVSync\Cli\Cli::register();
 *     }
 *
 * O container também é a fábrica recomendada para o bootstrap web (P6): os
 * mesmos serviços alimentam Hooks/Triggers sem dupla montagem.
 *
 * Integração P4 (mídia): AttachmentAdapter é registrado no estágio 0 da ordem
 * §A.5.7 quando as classes do pacote de mídia estão presentes (class_exists —
 * degradação graciosa: sem P4 o CLI de conteúdo continua operável).
 *
 * Integração git-workflow-master: CVSync\WorktreeRegression é consumido pelo
 * ApplyRunner com fallback gracioso (class_exists + method_exists) — ver
 * class-apply-runner.php para a assinatura exata esperada.
 *
 * @package CVSync\Cli
 */

declare(strict_types=1);

namespace CVSync\Cli;

use CVSync\Adapters\AdapterRegistry;
use CVSync\Adapters\ReferenceResolver;
use CVSync\Environment;
use CVSync\Exporter;
use CVSync\ImportGuard;
use CVSync\Importer;
use CVSync\Media\AttachmentAdapter;
use CVSync\Media\Materializer;
use CVSync\Media\MediaGarbageCollector;
use CVSync\Media\MediaStore;
use CVSync\Media\MediaValidator;
use CVSync\Media\PhpExecProbe;
use CVSync\Media\ReferenceGraph;
use CVSync\PathGuard;
use CVSync\Snapshot;
use CVSync\Storage\AuditLog;
use CVSync\Storage\ConflictStore;
use CVSync\Storage\Locks;
use CVSync\Storage\StateStore;
use CVSync\Triggers;

defined('ABSPATH') || exit;

/** Grafo de serviços do plugin (imutável após a montagem). */
final class Container
{
    public StateStore $state;
    public Locks $locks;
    public PathGuard $paths;
    public AuditLog $log;
    public ConflictStore $conflicts;
    public ImportGuard $guard;
    public ReferenceResolver $resolver;
    public AdapterRegistry $adapters;
    public Exporter $exporter;
    public Importer $importer;
    public Snapshot $snapshot;
    public ?MediaStore $mediaStore        = null;
    public ?Materializer $materializer    = null;
    public ?MediaGarbageCollector $mediaGc = null;
    public ?ReferenceGraph $referenceGraph = null;
    public ?PhpExecProbe $phpExecProbe    = null;
}

final class Cli
{
    private static ?Container $container = null;

    /**
     * Registra todos os comandos do namespace `wp sync` (§8.3). Chamado pelo
     * bootstrap (P6) sob `defined('WP_CLI') && WP_CLI`.
     */
    public static function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        $c = self::container();

        \WP_CLI::add_command('sync apply', new CommandApply($c));
        \WP_CLI::add_command('sync plan', new CommandPlan($c));
        \WP_CLI::add_command('sync export', new CommandExport($c));
        \WP_CLI::add_command('sync bootstrap', new CommandBootstrap($c));
        \WP_CLI::add_command('sync verify', new CommandVerify($c));
        \WP_CLI::add_command('sync status', new CommandStatus($c));
        \WP_CLI::add_command('sync log', new CommandLog($c));
        \WP_CLI::add_command('sync blame', new CommandBlame($c));
        \WP_CLI::add_command('sync conflicts', new CommandConflicts($c));
        \WP_CLI::add_command('sync conflict', new CommandConflict($c));
        \WP_CLI::add_command('sync resolve', new CommandResolve($c));
        \WP_CLI::add_command('sync rebase', new CommandRebase($c));
        \WP_CLI::add_command('sync install-hooks', new CommandInstallHooks($c));
        \WP_CLI::add_command('sync purge-revisions', new CommandPurgeRevisions($c));
        \WP_CLI::add_command('sync attachments', new CommandAttachments($c));
        \WP_CLI::add_command('sync restore', new CommandRestore($c));
    }

    /**
     * Grafo de serviços (singleton por processo). Também usado pelo bootstrap
     * web do P6 para montar Hooks/Triggers sem dupla montagem.
     */
    public static function container(): Container
    {
        if (null !== self::$container) {
            return self::$container;
        }

        global $wpdb;

        $c             = new Container();
        $c->state      = new StateStore($wpdb);
        $c->locks      = new Locks($wpdb);
        $c->paths      = new PathGuard(Environment::contentDir());
        $c->log        = new AuditLog($wpdb);
        $c->conflicts  = new ConflictStore($wpdb);
        $c->guard      = new ImportGuard();
        $c->resolver   = new ReferenceResolver();
        $c->adapters   = AdapterRegistry::withDefaults($c->state, $c->resolver, $c->paths);

        // P4 (mídia) — registro do AttachmentAdapter no estágio 0 (§A.5.7).
        if (class_exists(MediaStore::class) && class_exists(AttachmentAdapter::class)) {
            $c->mediaStore     = new MediaStore($c->paths);
            $c->materializer   = new Materializer(
                $c->mediaStore,
                new MediaValidator(),
                $c->state,
                $c->log,
                $c->guard,
                $c->paths
            );
            $c->adapters->register(
                // Locks injetado (R3 da r9): lock por entidade fail-open do
                // fluxo dedicado de mídia (§5.8) — sem ele a proteção fica inerte.
                new AttachmentAdapter($c->state, $c->resolver, $c->paths, $c->mediaStore, $c->materializer, $c->log, $c->locks),
                0
            );
            $c->mediaGc        = new MediaGarbageCollector($c->state, $c->paths);
            $c->referenceGraph = new ReferenceGraph($c->adapters);
            $c->phpExecProbe   = new PhpExecProbe();
        }

        $c->exporter = new Exporter($c->adapters, $c->state, $c->locks, $c->paths, $c->log);
        $c->importer = new Importer($c->adapters, $c->state, $c->guard, $c->paths, $c->log, $c->resolver);
        $c->snapshot = new Snapshot($c->adapters, $c->state, $c->paths, $c->mediaStore);

        return self::$container = $c;
    }

    /** Runner do apply reutilizável fora do WP-CLI (trigger passivo §8.2). */
    public static function applyRunner(): ApplyRunner
    {
        return new ApplyRunner(self::container());
    }

    /** Triggers do check passivo, montado sobre o mesmo grafo (bootstrap P6). */
    public static function triggers(): Triggers
    {
        return new Triggers(
            self::container()->state,
            static function (string $trigger): void {
                self::applyRunner()->run(new \CVSync\ImportContext(trigger: $trigger, environment: Environment::current()));
            }
        );
    }
}
