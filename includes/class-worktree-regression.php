<?php

declare(strict_types=1);

namespace CVSync;

/**
 * Working-tree regression signature (spec §5.7 — mandatory clause).
 *
 * Scenario: converged state S1 (db=file=synced) → `git stash` → file returns
 * to S0, HEAD does NOT move, no hook fires. By the pure decision table this
 * would be case 3 (import) — and the apply would silently push S0 over S1,
 * destroying human work.
 *
 * Detection signature (CLI/hooks path only, where the git binary is allowed):
 * for a file diverging from state, if
 *   (a) file_hash != last_synced_hash, AND
 *   (b) the file is CLEAN against HEAD (not listed by `git status --porcelain`), AND
 *   (c) HEAD == last_applied_head,
 * then the file REGRESSED to the last applied state (stash, `git checkout -- .`,
 * `git restore`). It is not new repo content: the caller reclassifies it as a
 * conflict → environment policy (local: db wins → re-export, lossless).
 *
 * Normative operational conditions (spec §5.7, one by one):
 *  1. ONE single read-only git command per apply (here: one
 *     `git status --porcelain` for the WHOLE set in captureContext()) —
 *     NEVER per entity. isRegressed() runs zero subprocesses.
 *  2. GIT_OPTIONAL_LOCKS=0 is mandatory (prevents index refresh/locks during
 *     a concurrent merge or rebase) — injected into the subprocess env.
 *  3. Confined to the CLI SAPI (php_sapi_name() === 'cli'); the "plugin never
 *     runs git" prohibition refers to the WEB runtime.
 *  4. Only if the git binary AND `.git` (dir or gitfile, covering worktrees)
 *     exist. HEAD itself is read in PURE PHP (.git/HEAD + ref resolution,
 *     no binary invocation) — same rule as the passive HEAD-hash trigger.
 *  5. Without git or `.git`: captureContext() returns null after logging
 *     `regression_check_unavailable: no-git`; the caller falls through to the
 *     normal environment policy (safe: the signature is only material in
 *     local; in CI staging file wins anyway; in homolog/prod db wins or the
 *     apply is off).
 *
 * Consumed by the apply as:
 *   $ctx = \CVSync\WorktreeRegression::captureContext();
 *   if ($ctx !== null && $ctx->isRegressed($file, $fileHash, $lastSynced, $lastHead)) { … }
 */
final class WorktreeRegression
{
    /** Repo-relative paths dirty against HEAD (incl. untracked). @var array<string,true> */
    private array $dirtyPaths;

    /** Resolved HEAD commit SHA (pure-PHP read), lowercase hex. */
    private string $head;

    /** Absolute repo root (dir containing `.git`). */
    private string $repoRoot;

    /**
     * @param array<string,true> $dirtyPaths
     */
    private function __construct(array $dirtyPaths, string $head, string $repoRoot)
    {
        $this->dirtyPaths = $dirtyPaths;
        $this->head = $head;
        $this->repoRoot = $repoRoot;
    }

    /**
     * Operational gate: CLI SAPI + git binary in PATH + `.git` present.
     * Pure checks only — zero subprocesses.
     */
    public static function isAvailable(?string $contentDir = null): bool
    {
        if (php_sapi_name() !== 'cli') {
            return false;
        }
        if (self::findGitBinary() === null) {
            return false;
        }

        return self::findRepoRoot($contentDir ?? self::defaultContentDir()) !== null;
    }

    /**
     * Runs the ONE allowed git read-only command and captures the context.
     * Returns null (with log `regression_check_unavailable: no-git`) whenever
     * the signature cannot be computed — never throws.
     */
    public static function captureContext(?string $contentDir = null): ?self
    {
        if (!self::isAvailable($contentDir)) {
            self::log('regression_check_unavailable: no-git');
            return null;
        }

        $repoRoot = self::findRepoRoot($contentDir ?? self::defaultContentDir());
        $git = self::findGitBinary();
        if ($repoRoot === null || $git === null) {
            self::log('regression_check_unavailable: no-git');
            return null;
        }

        $head = self::readHead($repoRoot);
        if ($head === null) {
            self::log('regression_check_unavailable: no-head');
            return null;
        }

        $dirty = self::runStatusPorcelain($git, $repoRoot);
        if ($dirty === null) {
            self::log('regression_check_unavailable: git-status-failed');
            return null;
        }

        return new self($dirty, $head, $repoRoot);
    }

    /**
     * The regression signature itself. Zero I/O, zero subprocesses.
     *
     * @param string $filePath        Entity file (absolute or repo-relative).
     * @param string $fileHash        Current canonical hash of the file.
     * @param string $lastSyncedHash  state.last_sync_hash.
     * @param string $lastAppliedHead state.last_applied_head.
     */
    public function isRegressed(
        string $filePath,
        string $fileHash,
        string $lastSyncedHash,
        string $lastAppliedHead
    ): bool {
        // (a) diverges from the last synced state.
        if ($fileHash === '' || $lastSyncedHash === '' || $fileHash === $lastSyncedHash) {
            return false;
        }

        // (c) HEAD did not move since the last apply (stash / checkout -- / restore).
        if ($lastAppliedHead === '' || !hash_equals(strtolower($lastAppliedHead), $this->head)) {
            return false;
        }

        // (b) clean against HEAD: NOT listed by `git status --porcelain`.
        // (An untracked file is listed as '??' → treated as dirty → not a
        // regression: new repo content follows the normal decision table.)
        $relative = $this->toRepoRelative($filePath);
        if ($relative === null) {
            return false;
        }

        return !isset($this->dirtyPaths[$relative]);
    }

    /** HEAD SHA captured at context time (useful for last_applied_head bookkeeping). */
    public function getHead(): string
    {
        return $this->head;
    }

    // --------------------------------------------------------------- internals

    private static function defaultContentDir(): string
    {
        if (defined('CVSYNC_CONTENT_DIR') && is_string(CVSYNC_CONTENT_DIR)) {
            return CVSYNC_CONTENT_DIR;
        }

        $cwd = getcwd();

        return ($cwd === false ? '.' : $cwd) . '/content';
    }

    /**
     * Walks up from the content dir looking for `.git` (directory OR gitfile —
     * worktrees). Returns the absolute repo root or null.
     */
    private static function findRepoRoot(string $startDir): ?string
    {
        $dir = rtrim($startDir, '/');
        // The content dir itself may not exist yet on a fresh clone.
        while ($dir !== '' && $dir !== '/' && !is_dir($dir)) {
            $dir = dirname($dir);
        }

        for ($i = 0; $i < 32 && $dir !== '' && $dir !== '/'; $i++) {
            if (is_dir($dir . '/.git') || is_file($dir . '/.git')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        return null;
    }

    /** Locates an executable `git` in PATH without spawning a process. */
    private static function findGitBinary(): ?string
    {
        $path = getenv('PATH');
        if ($path === false || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = $dir . '/git';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Pure-PHP HEAD read (spec §5.7: HEAD reading never invokes the binary).
     * Handles `.git` directory and gitfile (worktrees), loose refs and
     * packed-refs, and detached HEAD.
     */
    private static function readHead(string $repoRoot): ?string
    {
        $gitDir = $repoRoot . '/.git';
        if (is_file($gitDir)) {
            $gitfile = @file_get_contents($gitDir);
            if ($gitfile === false || preg_match('/^gitdir:\s*(.+)$/m', $gitfile, $m) !== 1) {
                return null;
            }
            $gitDir = trim($m[1]);
            if (!str_starts_with($gitDir, '/')) {
                $gitDir = $repoRoot . '/' . $gitDir;
            }
        }

        $headRaw = @file_get_contents($gitDir . '/HEAD');
        if ($headRaw === false) {
            return null;
        }
        $headRaw = trim($headRaw);

        if (preg_match('/^[0-9a-f]{40}$/i', $headRaw) === 1) {
            return strtolower($headRaw); // detached HEAD
        }

        if (preg_match('/^ref:\s*(\S+)$/', $headRaw, $m) !== 1) {
            return null;
        }
        $ref = $m[1];

        $loose = @file_get_contents($gitDir . '/' . $ref);
        if ($loose !== false && preg_match('/^([0-9a-f]{40})/i', trim($loose), $lm) === 1) {
            return strtolower($lm[1]);
        }

        $packed = @file_get_contents($gitDir . '/packed-refs');
        if ($packed !== false) {
            foreach (explode("\n", $packed) as $line) {
                if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                    continue;
                }
                $parts = explode(' ', trim($line), 2);
                if (count($parts) === 2 && $parts[1] === $ref
                    && preg_match('/^[0-9a-f]{40}$/i', $parts[0]) === 1
                ) {
                    return strtolower($parts[0]);
                }
            }
        }

        return null;
    }

    /**
     * THE single git command of the apply (spec §5.7 condition 1):
     * `git status --porcelain -z` for the whole tree, GIT_OPTIONAL_LOCKS=0.
     *
     * @return array<string,true>|null repo-relative dirty paths, or null on failure
     */
    private static function runStatusPorcelain(string $git, string $repoRoot): ?array
    {
        $env = getenv(); // inherit everything…
        if (!is_array($env)) {
            $env = [];
        }
        $env['GIT_OPTIONAL_LOCKS'] = '0'; // …plus the mandatory override (condition 2)

        $cmd = [$git, '-C', $repoRoot, 'status', '--porcelain', '-z'];

        $process = @proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env,
        );

        if (!is_resource($process)) {
            return null;
        }

        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);

        if ($code !== 0 || !is_string($out)) {
            return null;
        }

        $dirty = [];
        $entries = explode("\0", $out);
        $count = count($entries);
        for ($i = 0; $i < $count; $i++) {
            $entry = $entries[$i];
            if (strlen($entry) < 4) {
                continue;
            }
            $path = substr($entry, 3);
            $dirty[$path] = true;
            // Rename/copy entries carry a second NUL-separated path (the source).
            $status = substr($entry, 0, 2);
            if ($status[0] === 'R' || $status[0] === 'C') {
                $i++;
                if ($i < $count && $entries[$i] !== '') {
                    $dirty[$entries[$i]] = true;
                }
            }
        }

        return $dirty;
    }

    /** Normalizes absolute paths to repo-relative; null if outside the repo. */
    private function toRepoRelative(string $filePath): ?string
    {
        if (!str_starts_with($filePath, '/')) {
            return ltrim($filePath, './') === '' ? null : ltrim($filePath, './');
        }
        $prefix = $this->repoRoot . '/';
        if (!str_starts_with($filePath, $prefix)) {
            return null;
        }

        return substr($filePath, strlen($prefix));
    }

    /** CLI-only log channel: WP_CLI when present, error_log otherwise. */
    private static function log(string $message): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::log('cvsync: ' . $message);
            return;
        }
        error_log('cvsync: ' . $message);
    }
}
