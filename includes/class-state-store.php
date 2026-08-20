<?php
/**
 * StateStore — autoridade única de estado do cvsync (spec §9.1).
 *
 * Contratos normativos implementados aqui:
 *  - Lookup valida a TUPLA completa (entity_kind, post_type, entity_key) —
 *    verificação de posse do UUID (§6.3); hot path via uq_entity.
 *  - withLockedRow() é a ÚNICA porta de transação + SELECT ... FOR UPDATE por
 *    entidade (§5.8/§5.9): dentro da batch lock, a serialização por entidade é
 *    row lock InnoDB, nunca named lock.
 *  - recordSync() é o ÚNICO gravador do invariante §5.4
 *    (db_hash == file_hash == last_sync_hash): os três hashes são gravados
 *    juntos, sempre.
 *  - Fila O(dirty) via idx_status; tombstones + TTL (§5.5, default 90d);
 *    redescoberta de state no bootstrap (§5.3) via upsert() — o store nunca
 *    "adivinha" estado, o engine decide e o store persiste.
 *  - missing_binary (§A.4.1): caminho direto P4→P2 via
 *    upsert(db_hash=null) + setStatus(PendingRef) + setPendingPayload(
 *    {'refs': [], 'missing_binary': true}) — nunca passa pelo DecisionEngine.
 *  - $wpdb não lança exceções: last_error é checado após CADA operação e
 *    convertido em StorageException na fronteira (§5.9).
 *
 * @package CVSync\Storage
 */

declare(strict_types=1);

namespace CVSync\Storage;

use CVSync\Engine\EntityRef;

defined('ABSPATH') || exit;

final class StateStore
{
    /**
     * Colunas graváveis via upsert() e seus formatos $wpdb. Identidade
     * (entity_kind/post_type/entity_key) e created_at NÃO são graváveis após
     * o insert; id é autoincrement.
     */
    private const COLUMN_FORMATS = [
        'db_id'             => '%d',
        'db_hash'           => '%s',
        'db_modified'       => '%s',
        'file_hash'         => '%s',
        'file_mtime'        => '%d',
        'bin_hash'          => '%s',
        'bin_size'          => '%d',
        'bin_mtime'         => '%d',
        'last_sync_hash'    => '%s',
        'last_sync_direction' => '%s',
        'last_sync_at'      => '%s',
        'last_applied_head' => '%s',
        'format_version'    => '%d',
        'status'            => '%s',
        'tombstone_at'      => '%s',
        'pending_payload'   => '%s',
    ];

    /** Status que compõem a fila de trabalho O(dirty) (spec §5.2). */
    private const DIRTY_STATUSES = [
        EntityStatus::DirtyDb,
        EntityStatus::DirtyFile,
        EntityStatus::PendingRef,
        EntityStatus::Conflict,
        EntityStatus::PendingDelete,
    ];

    /** Guard estrutural contra transações aninhadas (START TRANSACTION em tx aberta comita implicitamente). */
    private bool $inTransaction = false;

    public function __construct(private readonly \wpdb $db)
    {
    }

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    /**
     * Lookup pela tupla completa (§6.3 — posse do UUID). Hot path: uq_entity.
     */
    public function get(EntityRef $ref): ?StateRecord
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                'SELECT * FROM %i WHERE entity_kind = %s AND post_type = %s AND entity_key = %s',
                $this->table(),
                $ref->kind,
                $ref->postType,
                $ref->key
            ),
            ARRAY_A
        );
        $this->assertNoError('state.get');

        return null === $row ? null : StateRecord::fromRow($row);
    }

    /**
     * Resolve post salvo → linha de state (os hooks de escrita recebem ID).
     * Via idx_db.
     */
    public function findByDbId(string $postType, int $dbId): ?StateRecord
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                'SELECT * FROM %i WHERE entity_kind = %s AND post_type = %s AND db_id = %d',
                $this->table(),
                'post',
                $postType,
                $dbId
            ),
            ARRAY_A
        );
        $this->assertNoError('state.findByDbId');

        return null === $row ? null : StateRecord::fromRow($row);
    }

    /**
     * Linhas compartilhando um blob CAS (auditoria, guards dos GCs — §A.4.2).
     * Via idx_binhash.
     *
     * @return list<StateRecord>
     */
    public function findByBinHash(string $binHash): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT * FROM %i WHERE bin_hash = %s',
                $this->table(),
                $binHash
            ),
            ARRAY_A
        );
        $this->assertNoError('state.findByBinHash');

        return array_map(StateRecord::fromRow(...), $rows ?: []);
    }

    /**
     * Fila O(dirty): status IN (dirty_db, dirty_file, pending_ref, conflict,
     * pending-delete), ordem estável por id. Via idx_status.
     *
     * @return list<StateRecord>
     */
    public function dirtyQueue(int $limit = 500): array
    {
        $statuses     = array_map(static fn (EntityStatus $s): string => $s->value, self::DIRTY_STATUSES);
        $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));

        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM %i WHERE status IN ({$placeholders}) ORDER BY id ASC LIMIT %d",
                $this->table(),
                ...[...$statuses, $limit]
            ),
            ARRAY_A
        );
        $this->assertNoError('state.dirtyQueue');

        return array_map(StateRecord::fromRow(...), $rows ?: []);
    }

    /**
     * Entidades pending_ref para reprocessamento (§6.2/§A.5.7).
     *
     * Estratégia (r1-t2, D2): busca indexada por idx_status (status='pending_ref')
     * + filtro do JSON EM PHP sobre o conjunto — nunca LIKE em MEDIUMTEXT, sem
     * coluna gerada (dbDelta não gerencia). Na escala alvo (dezenas/centenas de
     * entidades, §13.9) o conjunto pending_ref é de zero a poucas dezenas de
     * linhas; o filtro em PHP custa microssegundos.
     *
     * O filtro é ESTRUTURAL: espera o array plano de slugs no topo do payload
     * ({"refs": ["slug-a", ...], ...}) — contrato com o Importer (P3).
     *
     * @param string|null $mentionedSlug Quando informado, retorna só as
     *        pendências que mencionam o slug.
     * @return list<StateRecord>
     */
    public function pendingRefs(?string $mentionedSlug = null): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT * FROM %i WHERE status = %s ORDER BY id ASC',
                $this->table(),
                EntityStatus::PendingRef->value
            ),
            ARRAY_A
        );
        $this->assertNoError('state.pendingRefs');

        $records = array_map(StateRecord::fromRow(...), $rows ?: []);

        if (null === $mentionedSlug) {
            return $records;
        }

        return array_values(array_filter(
            $records,
            static fn (StateRecord $r): bool => in_array($mentionedSlug, $r->pendingPayload['refs'] ?? [], true)
        ));
    }

    /**
     * Alias semântico para o reprocessamento §A.5.7 (P3 Importer).
     *
     * @return list<StateRecord>
     */
    public function findPendingReferencing(string $slug): array
    {
        return $this->pendingRefs($slug);
    }

    /**
     * Dirty flag O(1) do trigger passivo (§8.2): MAX(last_applied_head).
     */
    public function lastAppliedHead(): ?string
    {
        $value = $this->db->get_var(
            $this->db->prepare('SELECT MAX(last_applied_head) FROM %i', $this->table())
        );
        $this->assertNoError('state.lastAppliedHead');

        return null === $value || '' === $value ? null : (string) $value;
    }

    /**
     * Tree-hash por tipo (spec §11.1): SHA-256 sobre os pares
     * "entity_key:db_hash" ordenados por entity_key — um único valor comparável
     * entre ambientes ("o conteúdo do staging é igual ao do repo?").
     *
     * Escopo: linhas não-tombstone com db_hash computado (entidades vivas dos
     * dois lados; tombstones estão fora por definição de "conteúdo").
     *
     * @param string $postType '' = todos os post types do kind.
     */
    public function treeHash(string $kind, string $postType = ''): string
    {
        if ('' === $postType) {
            $rows = $this->db->get_results(
                $this->db->prepare(
                    "SELECT entity_key, db_hash FROM %i
                     WHERE entity_kind = %s AND status != %s AND db_hash IS NOT NULL
                     ORDER BY entity_key ASC",
                    $this->table(),
                    $kind,
                    EntityStatus::Tombstone->value
                ),
                ARRAY_A
            );
        } else {
            $rows = $this->db->get_results(
                $this->db->prepare(
                    "SELECT entity_key, db_hash FROM %i
                     WHERE entity_kind = %s AND post_type = %s AND status != %s AND db_hash IS NOT NULL
                     ORDER BY entity_key ASC",
                    $this->table(),
                    $kind,
                    $postType,
                    EntityStatus::Tombstone->value
                ),
                ARRAY_A
            );
        }
        $this->assertNoError('state.treeHash');

        $lines = array_map(
            static fn (array $r): string => $r['entity_key'] . ':' . $r['db_hash'],
            $rows ?: []
        );

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Scan read-only da state table para os comandos CLI (verify/status/plan/
     * blame) — substitui leituras diretas da tabela fora deste pacote.
     *
     * Retorna array materializado (não generator): na escala alvo (dezenas a
     * centenas de entidades, spec §13.9) o conjunto inteiro cabe em memória
     * sem esforço, e o array é o formato que o CLI quer (count, filtro,
     * --format=json). Generator complicaria o manejo de StorageException do
     * fromRow() para economia de memória irrelevante nesta escala.
     *
     * Ordem determinística: id ASC (ordem de adoção da entidade — estável
     * entre execuções e entre ambientes para o mesmo histórico).
     *
     * @param string|null $kind Filtro opcional por entity_kind ('post', 'nav_menu', ...).
     * @param EntityStatus|null $status Filtro opcional por status.
     * @param int|null $limit Teto opcional de linhas (null = sem teto — seguro
     *        na escala alvo; os comandos de relatório passam o seu).
     * @return list<StateRecord>
     */
    public function all(?string $kind = null, ?EntityStatus $status = null, ?int $limit = null): array
    {
        $columns = 'id, entity_kind, post_type, entity_key, db_id, db_hash, db_modified,'
            . ' file_hash, file_mtime, bin_hash, bin_size, bin_mtime,'
            . ' last_sync_hash, last_sync_direction, last_sync_at, last_applied_head,'
            . ' format_version, status, tombstone_at, pending_payload, created_at, updated_at';

        $where  = [];
        $params = [$this->table()];

        if (null !== $kind) {
            $where[]  = 'entity_kind = %s';
            $params[] = $kind;
        }
        if (null !== $status) {
            $where[]  = 'status = %s';
            $params[] = $status->value;
        }

        $sql = "SELECT {$columns} FROM %i";
        if ([] !== $where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id ASC';
        if (null !== $limit) {
            $sql     .= ' LIMIT %d';
            $params[] = $limit;
        }

        $rows = $this->db->get_results($this->db->prepare($sql, ...$params), ARRAY_A);
        $this->assertNoError('state.all');

        return array_map(StateRecord::fromRow(...), $rows ?: []);
    }

    // ------------------------------------------------------------------
    // Escrita
    // ------------------------------------------------------------------

    /**
     * Cria ou atualiza a linha da tupla. $fields é whitelist-validado contra
     * COLUMN_FORMATS — nunca array cruzado direto para $wpdb->update().
     *
     * Cobre a redescoberta do bootstrap (§5.3): o engine decide ok/conflict, o
     * store apenas persiste. E o caminho missing_binary (§A.4.1):
     * upsert($ref, ['db_hash' => null]) — P4 → P2 direto, sem engine.
     *
     * @param array<string, mixed> $fields Valores podem ser scalar|null,
     *        EntityStatus, SyncDirection, \DateTimeImmutable ou array
     *        (pending_payload — serializado como JSON).
     * @throws \InvalidArgumentException Coluna fora da whitelist.
     * @throws StorageException Erro de banco (inclui duplicate key em insert concorrente).
     */
    public function upsert(EntityRef $ref, array $fields): StateRecord
    {
        [$data, $formats] = $this->normalizeFields($fields);
        $now              = $this->now();

        $existing = $this->get($ref);

        if (null !== $existing) {
            $data['updated_at'] = $now;
            $formats[]          = '%s';

            $result = $this->db->update(
                $this->table(),
                $data,
                [
                    'entity_kind' => $ref->kind,
                    'post_type'   => $ref->postType,
                    'entity_key'  => $ref->key,
                ],
                $formats,
                ['%s', '%s', '%s']
            );
            if (false === $result) {
                $this->assertNoError('state.upsert.update');
                throw new StorageException('state.upsert.update falhou sem last_error.');
            }

            return $this->requireRow($ref);
        }

        $insert = array_merge(
            [
                'entity_kind' => $ref->kind,
                'post_type'   => $ref->postType,
                'entity_key'  => $ref->key,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            $data
        );
        $insertFormats = array_merge(['%s', '%s', '%s', '%s', '%s'], $formats);

        $result = $this->db->insert($this->table(), $insert, $insertFormats);
        if (false === $result) {
            $this->assertNoError('state.upsert.insert');
            throw new StorageException('state.upsert.insert falhou sem last_error.');
        }

        return $this->requireRow($ref);
    }

    /**
     * Dirty-mark (hooks, §8.1): status + pré-filtro db_modified. Re-marcação é
     * no-op de conteúdo (só toca updated_at) — coalesce natural de rajadas.
     */
    public function markDirty(
        EntityRef $ref,
        EntityStatus $status = EntityStatus::DirtyDb,
        ?\DateTimeImmutable $dbModified = null
    ): void {
        $fields = ['status' => $status];
        if (null !== $dbModified) {
            $fields['db_modified'] = $dbModified;
        }

        $this->upsert($ref, $fields);
    }

    /**
     * Persiste o status decidido pelo engine. A validação de domínio acontece
     * AQUI (EntityStatus é o tipo do parâmetro — impossível gravar valor fora
     * do vocabulário §5.2). Nunca persistência cega de string.
     */
    public function setStatus(EntityRef $ref, EntityStatus $status): void
    {
        $this->upsert($ref, ['status' => $status]);
    }

    /**
     * ÚNICO gravador do invariante §5.4: após sync convergente,
     * db_hash == file_hash == last_sync_hash — gravados juntos, sempre.
     * Fecha o ciclo: direction, last_sync_at, status=ok.
     */
    public function recordSync(
        EntityRef $ref,
        SyncDirection $direction,
        string $syncedHash,
        ?\DateTimeImmutable $dbModified = null,
        ?int $fileMtime = null
    ): void {
        $fields = [
            'db_hash'             => $syncedHash,
            'file_hash'           => $syncedHash,
            'last_sync_hash'      => $syncedHash,
            'last_sync_direction' => $direction,
            'last_sync_at'        => $this->nowDate(),
            'status'              => EntityStatus::Ok,
        ];
        if (null !== $dbModified) {
            $fields['db_modified'] = $dbModified;
        }
        if (null !== $fileMtime) {
            $fields['file_mtime'] = $fileMtime;
        }

        $this->upsert($ref, $fields);
    }

    /**
     * Atualiza apenas os pré-filtros do lado arquivo quando NÃO houve sync
     * (ex.: arquivo lido e descartado por hash igual ao do banco).
     */
    public function touchFileMeta(EntityRef $ref, ?string $fileHash, ?int $fileMtime): void
    {
        $this->upsert($ref, ['file_hash' => $fileHash, 'file_mtime' => $fileMtime]);
    }

    /**
     * Atributos binários auxiliares (§A.4.2) — nunca input isolado de decisão.
     */
    public function setBinaryMeta(EntityRef $ref, ?string $binHash, ?int $binSize, ?int $binMtime): void
    {
        $this->upsert($ref, ['bin_hash' => $binHash, 'bin_size' => $binSize, 'bin_mtime' => $binMtime]);
    }

    /**
     * Grava/limpa pendências (§6.2, §A.4.1). null limpa (self-heal §A.5.3.5).
     * Formato normativo do payload: {"refs": ["slug", ...], ...} — o array
     * plano de slugs no topo alimenta pendingRefs($slug) (r1-t2, D2).
     *
     * @param array<string, mixed>|null $payload
     */
    public function setPendingPayload(EntityRef $ref, ?array $payload): void
    {
        $this->upsert($ref, ['pending_payload' => $payload]);
    }

    /**
     * Deleção (§5.5/§A.7): status=tombstone + tombstone_at=now; o último hash
     * é preservado (anti-ressurreição por clone git desatualizado).
     */
    public function tombstone(EntityRef $ref): void
    {
        $this->upsert($ref, [
            'status'       => EntityStatus::Tombstone,
            'tombstone_at' => $this->nowDate(),
        ]);
    }

    /**
     * Purge de tombstones por TTL (default 90d, §5.5). Via idx_tombstone.
     *
     * @return int Linhas removidas.
     */
    public function purgeTombstones(int $ttlDays = 90): int
    {
        $cutoff = (new \DateTimeImmutable('now', wp_timezone()))
            ->modify("-{$ttlDays} days")
            ->format('Y-m-d H:i:s');

        $result = $this->db->query(
            $this->db->prepare(
                'DELETE FROM %i WHERE status = %s AND tombstone_at IS NOT NULL AND tombstone_at < %s',
                $this->table(),
                EntityStatus::Tombstone->value,
                $cutoff
            )
        );
        $this->assertNoError('state.purgeTombstones');

        return (int) $result;
    }

    /**
     * Caso 8 da tabela de decisão: remove state órfão (db ausente + arquivo
     * ausente, sem tombstone pendente de TTL).
     */
    public function deleteRow(EntityRef $ref): bool
    {
        $result = $this->db->delete(
            $this->table(),
            [
                'entity_kind' => $ref->kind,
                'post_type'   => $ref->postType,
                'entity_key'  => $ref->key,
            ],
            ['%s', '%s', '%s']
        );
        $this->assertNoError('state.deleteRow');

        return $result > 0;
    }

    /**
     * Bulk update do HEAD aplicado ao final do apply (dirty flag O(1) do
     * trigger passivo, §8.2). $refs = entidades tocadas no lote.
     *
     * @param list<EntityRef> $refs
     * @return int Linhas atualizadas.
     */
    public function updateLastAppliedHead(string $headSha, array $refs): int
    {
        $now    = $this->now();
        $count  = 0;

        foreach ($refs as $ref) {
            if (! $ref instanceof EntityRef) {
                throw new \InvalidArgumentException('updateLastAppliedHead: $refs deve ser list<EntityRef>.');
            }

            $result = $this->db->update(
                $this->table(),
                ['last_applied_head' => $headSha, 'updated_at' => $now],
                [
                    'entity_kind' => $ref->kind,
                    'post_type'   => $ref->postType,
                    'entity_key'  => $ref->key,
                ],
                ['%s', '%s'],
                ['%s', '%s', '%s']
            );
            if (false === $result) {
                $this->assertNoError('state.updateLastAppliedHead');
                throw new StorageException('state.updateLastAppliedHead falhou sem last_error.');
            }
            $count += $result;
        }

        return $count;
    }

    // ------------------------------------------------------------------
    // Concorrência por linha (§5.8/§5.9)
    // ------------------------------------------------------------------

    /**
     * Transação DML por entidade + SELECT ... FOR UPDATE na linha de state.
     * ÚNICA porta de transação do pacote (r1, D6): begin/commit/rollback
     * soltos permitiriam esquecer o rollback e contaminar a entidade seguinte
     * na mesma tx aberta (autocommit desligado).
     *
     * Contrato:
     *  1. START TRANSACTION;
     *  2. SELECT ... FOR UPDATE na linha da tupla (null se a linha não existe);
     *  3. $callback(?StateRecord $locked): mixed — o Importer (P3) executa
     *     wp_update_post() etc. DENTRO do callback: write de conteúdo e update
     *     de state comitam juntos ou não acontecem;
     *  4. retorno normal + last_error limpo ⇒ COMMIT;
     *     Throwable OU last_error sujo ⇒ ROLLBACK + StorageException.
     *
     * PROIBIDO DDL no callback (commit implícito). Aninhamento proibido
     * (START TRANSACTION em tx aberta comita implicitamente) — detectado e
     * rejeitado estruturalmente.
     *
     * Aninhamento esperado (r1-t2 do CMS): ImportGuard::run() é o envelope
     * EXTERNO; withLockedRow() o interno.
     *
     * @param callable(?StateRecord): mixed $callback
     * @throws StorageException Em qualquer falha (após ROLLBACK).
     */
    public function withLockedRow(EntityRef $ref, callable $callback): mixed
    {
        if ($this->inTransaction) {
            throw new StorageException(
                'withLockedRow aninhado é proibido (START TRANSACTION em tx aberta comita implicitamente).'
            );
        }

        $this->inTransaction = true;
        $this->db->query('START TRANSACTION');
        $this->assertNoError('state.tx.begin');

        try {
            $locked  = $this->getForUpdate($ref);
            $result  = $callback($locked);

            // O callback pode ter usado APIs WP que falham silenciosamente:
            // last_error sujo aqui significa DML falho dentro da tx.
            $this->assertNoError('state.tx.callback');

            $this->db->query('COMMIT');
            $this->assertNoError('state.tx.commit');
            $this->inTransaction = false;

            return $result;
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            $this->inTransaction = false;

            if ($e instanceof StorageException) {
                throw $e;
            }

            throw new StorageException(
                sprintf('Transação da entidade %s falhou (rollback executado): %s', $ref->toTupleString(), $e->getMessage()),
                0,
                $e
            );
        }
    }

    // ------------------------------------------------------------------
    // Auditoria (wp sync verify — consumido por P5)
    // ------------------------------------------------------------------

    /**
     * Anti-join de cobertura: posts no escopo sem linha de state.
     *
     * $statusMap é o mapa post_type→statuses da errata E3/§A.2.3 (ex.:
     * ['page' => ['publish','draft','private'], 'attachment' => ['inherit']])
     * — injetado pelo chamador (dono da config: P3/P6). O store NUNCA assume
     * lista global de status: filtrar 'inherit' cru silenciaria todos os
     * attachments (§A.2.3). Lê wp_posts sem tocá-la (nenhum índice em tabela
     * do core é criado — restrição do projeto).
     *
     * @param array<string, list<string>> $statusMap
     * @return list<array{id: int, post_type: string, slug: string}>
     */
    public function findUntrackedPosts(array $statusMap): array
    {
        $found = [];
        $posts = $this->db->posts;
        $table = $this->table();

        foreach ($statusMap as $postType => $statuses) {
            if ([] === $statuses) {
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
            $rows         = $this->db->get_results(
                $this->db->prepare(
                    "SELECT p.ID AS id, p.post_type, p.post_name AS slug
                     FROM {$posts} p
                     LEFT JOIN {$table} s
                       ON s.entity_kind = 'post' AND s.post_type = p.post_type AND s.db_id = p.ID
                     WHERE p.post_type = %s AND p.post_status IN ({$placeholders}) AND s.id IS NULL
                     ORDER BY p.ID ASC",
                    ...[...[$postType], ...$statuses]
                ),
                ARRAY_A
            );
            $this->assertNoError('state.findUntrackedPosts');

            foreach ($rows ?: [] as $row) {
                $found[] = [
                    'id'        => (int) $row['id'],
                    'post_type' => (string) $row['post_type'],
                    'slug'      => (string) $row['slug'],
                ];
            }
        }

        return $found;
    }

    /**
     * Anti-join de consistência (§A.4.3): linhas com db_id cujo post sumiu
     * (sem tombstone). Restrito aos post types do escopo (chaves do mapa).
     *
     * @param array<string, list<string>> $statusMap
     * @return list<StateRecord>
     */
    public function findDanglingPostRefs(array $statusMap): array
    {
        $postTypes = array_keys($statusMap);
        if ([] === $postTypes) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $posts        = $this->db->posts;
        $table        = $this->table();

        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT s.* FROM {$table} s
                 LEFT JOIN {$posts} p ON p.ID = s.db_id AND p.post_type = s.post_type
                 WHERE s.entity_kind = 'post' AND s.db_id IS NOT NULL
                   AND s.status != %s AND s.post_type IN ({$placeholders})
                   AND p.ID IS NULL
                 ORDER BY s.id ASC",
                ...[...[EntityStatus::Tombstone->value], ...$postTypes]
            ),
            ARRAY_A
        );
        $this->assertNoError('state.findDanglingPostRefs');

        return array_map(StateRecord::fromRow(...), $rows ?: []);
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function table(): string
    {
        return Schema::table('state');
    }

    private function now(): string
    {
        return current_time('mysql');
    }

    private function nowDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', wp_timezone());
    }

    /**
     * SELECT ... FOR UPDATE na linha da tupla. Somente dentro de transação
     * (chamado por withLockedRow).
     */
    private function getForUpdate(EntityRef $ref): ?StateRecord
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                'SELECT * FROM %i WHERE entity_kind = %s AND post_type = %s AND entity_key = %s FOR UPDATE',
                $this->table(),
                $ref->kind,
                $ref->postType,
                $ref->key
            ),
            ARRAY_A
        );
        $this->assertNoError('state.getForUpdate');

        return null === $row ? null : StateRecord::fromRow($row);
    }

    private function requireRow(EntityRef $ref): StateRecord
    {
        $record = $this->get($ref);
        if (null === $record) {
            throw new StorageException(
                sprintf('state: linha ausente após upsert da tupla %s.', $ref->toTupleString())
            );
        }

        return $record;
    }

    /**
     * Whitelist + normalização de tipos dos campos graváveis.
     *
     * @param array<string, mixed> $fields
     * @return array{0: array<string, mixed>, 1: list<string>} [data, formats]
     */
    private function normalizeFields(array $fields): array
    {
        $data    = [];
        $formats = [];

        foreach ($fields as $column => $value) {
            if (! isset(self::COLUMN_FORMATS[$column])) {
                throw new \InvalidArgumentException(
                    sprintf('state.upsert: coluna "%s" fora da whitelist.', $column)
                );
            }

            if ($value instanceof EntityStatus || $value instanceof SyncDirection) {
                $value = $value->value;
            } elseif ($value instanceof \DateTimeImmutable) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif ('pending_payload' === $column && is_array($value)) {
                $value = wp_json_encode($value);
            }

            $data[$column] = $value;
            $formats[]     = self::COLUMN_FORMATS[$column];
        }

        return [$data, $formats];
    }

    /**
     * $wpdb não lança exceções (§5.9): last_error vira StorageException na
     * fronteira. Consumidores nunca checam last_error.
     *
     * @throws StorageException
     */
    private function assertNoError(string $operation): void
    {
        if ('' !== $this->db->last_error) {
            throw new StorageException(
                sprintf('%s: %s', $operation, $this->db->last_error)
            );
        }
    }
}
