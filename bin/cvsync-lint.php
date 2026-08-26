<?php

declare(strict_types=1);

/**
 * cvsync-lint — standalone CI lint for versioned content (spec §12.3 + §A.9.5).
 *
 * Pure PHP CLI, NO WordPress: runs in the PR gate where no wp-config.php,
 * database or WP runtime exists. Exit code != 0 on ANY hard error
 * (merge blocked); warnings never fail the build.
 *
 * Usage:
 *   php plugins/cvsync/bin/cvsync-lint.php [--content-dir=DIR] [--config=FILE]
 *
 * Configuration — relationship with the CVSYNC_* constants (§A.13.10):
 *   The CI cannot read wp-config.php, so the lint reads `cvsync.json` at the
 *   repo root. That file MIRRORS the runtime defaults of the plugin
 *   constants:
 *     content_dir            <-> CVSYNC_CONTENT_DIR        (<repo>/content)
 *     attachment_mime_types  <-> CVSYNC_ATTACHMENT_MIME_TYPES
 *     attachment_max_bytes   <-> CVSYNC_ATTACHMENT_MAX_BYTES (10 MB)
 *     attachment_allow_svg   <-> CVSYNC_ATTACHMENT_ALLOW_SVG (default-deny)
 *     max_megapixels         <-> (pixel-bomb ceiling, 50 MP — §A.5.1.4)
 *   The two surfaces are declared technical debt by contract: what the export
 *   writes, the lint must accept (§A.5.4). When they DIVERGE, the apply logs
 *   a warning (§A.13.10) — keep them in sync deliberately.
 *
 * Reuses P1 engine classes via explicit require_once (no WP autoloader), with
 * graceful fallback to built-in minimal checks when the files are absent
 * (e.g. the lint copied alone into a CI image).
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "cvsync-lint: CLI only.\n");
    exit(2);
}

// ------------------------------------------------------------- configuration

const LFS_POINTER_PREFIX = 'version https://git-lfs.github.com/spec/v1';

const DEFAULT_CONFIG = [
    'content_dir' => 'content',
    'attachment_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'],
    'attachment_max_bytes' => 10485760,   // 10 MB — CVSYNC_ATTACHMENT_MAX_BYTES
    'attachment_warn_bytes' => 2097152,   // 2 MB optimization nudge (§A.9.5)
    'attachment_allow_svg' => false,      // §A.9.3 default-deny
    'max_megapixels' => 50,               // §A.5.1.4 pixel-bomb ceiling
    'environment_url_patterns' => ['localhost', '127\.0\.0\.1', '\.local(?::|/|$)', '\.test(?::|/|$)'],
];

/** Executable/double-extension hard rule (§A.9.5) — rejected in ANY position. */
const DANGEROUS_EXTENSIONS = [
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phpt',
    'sh', 'bash', 'zsh', 'exe', 'com', 'bat', 'cmd', 'scr', 'msi', 'dll',
    'pl', 'py', 'rb', 'cgi', 'htaccess',
];

/** finfo magic-bytes expectations per whitelisted MIME (never extension). */
const MAGIC_MAP = [
    'image/jpeg' => ['image/jpeg'],
    'image/png' => ['image/png'],
    'image/gif' => ['image/gif'],
    'image/webp' => ['image/webp'],
    'application/pdf' => ['application/pdf'],
    'image/svg+xml' => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'],
];

// ------------------------------------------------------------------ reporter

final class LintReport
{
    public int $errors = 0;
    public int $warnings = 0;
    public int $files = 0;

    public function error(string $path, string $check, string $message): void
    {
        $this->errors++;
        fwrite(STDOUT, "ERROR {$path}: [{$check}] {$message}\n");
    }

    public function warn(string $path, string $check, string $message): void
    {
        $this->warnings++;
        fwrite(STDOUT, "WARN  {$path}: [{$check}] {$message}\n");
    }

    public function summary(): void
    {
        fwrite(STDOUT, sprintf(
            "cvsync-lint: %d error(s), %d warning(s) across %d file(s)\n",
            $this->errors,
            $this->warnings,
            $this->files,
        ));
    }
}

// ------------------------------------------------------------ P1 class reuse

$pluginIncludes = dirname(__DIR__) . '/includes';
$frontmatterAvailable = false;
foreach ([
    $pluginIncludes . '/engine/Exception/EngineException.php',
    $pluginIncludes . '/engine/Frontmatter/FrontmatterException.php',
    $pluginIncludes . '/engine/Frontmatter/FrontmatterParser.php',
] as $classFile) {
    if (is_file($classFile)) {
        require_once $classFile;
    }
}
$frontmatterAvailable = class_exists('CVSync\\Engine\\Frontmatter\\FrontmatterParser');

$placeholderPattern = '/\{\{(ref|attachment_url|attachment|term|home_url|missing)(?::([^}]*))?\}\}/';
$rawIdAttributes = ['ref', 'id', 'ids'];
$vocabFile = $pluginIncludes . '/engine/Placeholders/PlaceholderVocabulary.php';
if (is_file($vocabFile)) {
    require_once $vocabFile;
    if (class_exists('CVSync\\Engine\\Placeholders\\PlaceholderVocabulary')) {
        $placeholderPattern = \CVSync\Engine\Placeholders\PlaceholderVocabulary::PATTERN;
        $rawIdAttributes = \CVSync\Engine\Placeholders\PlaceholderVocabulary::DEFAULT_RAW_ATTRIBUTES;
    }
}

// --------------------------------------------------------------------- CLI

$options = getopt('', ['content-dir:', 'config:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php cvsync-lint.php [--content-dir=DIR] [--config=FILE]\n");
    exit(0);
}

$repoRoot = getcwd() ?: '.';

$config = DEFAULT_CONFIG;
$configPath = $options['config'] ?? $repoRoot . '/cvsync.json';
if (is_file($configPath)) {
    $decoded = json_decode((string) file_get_contents($configPath), true);
    if (!is_array($decoded)) {
        fwrite(STDOUT, "ERROR {$configPath}: [config] invalid JSON — CI cannot verify policy, failing closed.\n");
        exit(1);
    }
    $config = array_merge($config, $decoded);
} else {
    fwrite(STDOUT, "WARN  cvsync.json: [config] not found — using built-in defaults (mirror of CVSYNC_* defaults).\n");
}

$report = new LintReport();
if (!$frontmatterAvailable) {
    $report->warn('cvsync-lint', 'bootstrap', 'P1 FrontmatterParser unavailable — degraded to minimal frontmatter checks.');
}

$contentDir = $options['content-dir'] ?? $repoRoot . '/' . ltrim((string) $config['content_dir'], '/');
if (!is_dir($contentDir)) {
    fwrite(STDOUT, "cvsync-lint: content dir '{$contentDir}' absent — nothing to lint.\n");
    exit(0);
}

// ------------------------------------------------------------ file discovery

/** @var list<string> $textFiles  Frontmatter+body, YAML docs, JSON docs. */
/** @var array<string,string> $sidecars slug => path */
/** @var array<string,string> $blobs repo-relative => absolute path */
$textFiles = [];
$sidecars = [];
$blobs = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($contentDir, FilesystemIterator::SKIP_DOTS),
);
/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $abs = $file->getPathname();
    $rel = substr($abs, strlen($contentDir) + 1);
    $report->files++;

    $inMedia = str_starts_with($rel, 'media/');
    if ($inMedia && preg_match('#^media/bin/[0-9a-f]{2}/#', $rel) === 1) {
        $blobs[$rel] = $abs;
        continue;
    }
    if ($inMedia) {
        if (str_ends_with($rel, '.attachment.yml')) {
            $sidecars[pathinfo($file->getFilename(), PATHINFO_FILENAME) === ''
                ? $rel
                : basename($rel, '.attachment.yml')] = $abs;
            continue;
        }
        // default-deny (errata E1): anything planted under content/media/ that
        // is neither a CAS blob nor a sidecar is rejected.
        $report->error($rel, 'media-layout', 'stray file under content/media/ (only bin/<hash> blobs and *.attachment.yml sidecars are allowed).');
        continue;
    }
    $textFiles[] = [$rel, $abs];
}

// ----------------------------------------------------------- helper closures

$readFile = static function (string $abs): ?string {
    $bytes = @file_get_contents($abs);
    return $bytes === false ? null : $bytes;
};

$lineOf = static function (string $haystack, int $offset): int {
    return substr_count($haystack, "\n", 0, $offset) + 1;
};

/** UTF-8 + LF (§12.3). Returns false when the file already failed hard. */
$checkEncoding = static function (string $rel, string $bytes) use ($report): bool {
    $ok = true;
    if (!mb_check_encoding($bytes, 'UTF-8')) {
        $report->error($rel, 'encoding', 'invalid UTF-8.');
        $ok = false;
    }
    if (str_contains($bytes, "\r")) {
        $report->error($rel, 'eol', 'CR/CRLF detected — EOL must be LF (.gitattributes: text eol=lf).');
        $ok = false;
    }
    return $ok;
};

/** No hardcoded environment URLs (§12.3). */
$checkUrls = static function (string $rel, string $bytes) use ($report, $config): void {
    if (preg_match_all('#https?://[^\s"\'<>)]+#', $bytes, $m) !== false) {
        foreach (array_unique($m[0]) as $url) {
            if (str_contains($url, '/wp-content/uploads/')) {
                $report->error($rel, 'env-url', "hardcoded uploads URL '{$url}' — use {{attachment_url:slug}} / {{home_url}} (§A.6).");
                continue;
            }
            foreach ((array) $config['environment_url_patterns'] as $pattern) {
                if (preg_match('#' . $pattern . '#i', $url) === 1) {
                    $report->error($rel, 'env-url', "environment URL hardcoded: '{$url}' — use placeholders ({{home_url}} etc.).");
                    break;
                }
            }
        }
    }
};

/** Minimal frontmatter fallback when the P1 parser is absent (graceful). */
$parseFrontmatterFallback = static function (string $raw): array {
    foreach (explode("\n", $raw) as $i => $line) {
        $stripped = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '""', $line) ?? '';
        if (preg_match('/(?:^|\s)!/', $stripped) === 1) {
            throw new RuntimeException(sprintf('YAML tag (!) rejected at line %d.', $i + 1));
        }
    }
    $data = [];
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $m) === 1) {
            $data[$m[1]] = trim($m[2], ' "');
        }
    }
    return $data;
};

/**
 * Splits a frontmatter document; returns [frontmatter, body].
 * Throws RuntimeException on any violation.
 */
$splitDocument = static function (string $bytes) use ($frontmatterAvailable, $parseFrontmatterFallback): array {
    if ($frontmatterAvailable) {
        try {
            return \CVSync\Engine\Frontmatter\FrontmatterParser::splitDocument($bytes);
        } catch (\Throwable $e) {
            throw new RuntimeException($e->getMessage());
        }
    }
    if (!str_starts_with($bytes, "---\n")) {
        throw new RuntimeException('Document must open with a "---" fence line (LF).');
    }
    $close = strpos($bytes, "\n---\n", 3);
    if ($close === false) {
        throw new RuntimeException('Closing "---" fence line not found.');
    }
    return [$parseFrontmatterFallback(substr($bytes, 4, $close - 4)), substr($bytes, $close + 5)];
};

/** Parses a full-YAML document (menus, sidecars). */
$parseYaml = static function (string $bytes) use ($frontmatterAvailable, $parseFrontmatterFallback): array {
    if ($frontmatterAvailable) {
        try {
            return \CVSync\Engine\Frontmatter\FrontmatterParser::parse($bytes);
        } catch (\Throwable $e) {
            throw new RuntimeException($e->getMessage());
        }
    }
    return $parseFrontmatterFallback($bytes);
};

/** Block markup parseability + raw numeric reference anti-regression (§6.2). */
$checkBlockMarkup = static function (string $rel, string $body) use ($report, $lineOf, $rawIdAttributes): void {
    if (preg_match_all(
        '/<!-- (\/?)wp:([a-zA-Z0-9\/-]+?)( (\{.*?\}))?(\s*\/)?\s*-->/s',
        $body,
        $m,
        PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
    ) === false) {
        return;
    }
    $stack = [];
    foreach ($m as $match) {
        [$full, $offset] = $match[0];
        $isCloser = $match[1][0] === '/';
        $name = $match[2][0];
        $json = $match[4][0] ?? '';
        $selfClosed = isset($match[5]) && trim((string) $match[5][0]) === '/';
        $line = $lineOf($body, $offset);

        if (!$isCloser && $json !== '') {
            $attrs = json_decode($json, true);
            if (!is_array($attrs) && trim($json) !== '{}') {
                $report->error($rel, 'block-markup', "line {$line}: block '{$name}' attributes are not valid JSON.");
            } elseif (is_array($attrs)) {
                foreach ($rawIdAttributes as $key) {
                    if (isset($attrs[$key]) && (is_int($attrs[$key]) || (is_array($attrs[$key]) && array_filter($attrs[$key], 'is_int') !== []))) {
                        $report->error($rel, 'raw-numeric-ref', "line {$line}: raw numeric '{$key}' in block '{$name}' — IDs never cross environments; re-export from origin (§6.2).");
                    }
                }
            }
        }

        if ($isCloser) {
            $open = array_pop($stack);
            if ($open === null || $open['name'] !== $name) {
                $report->error($rel, 'block-markup', "line {$line}: unbalanced closer '/wp:{$name}'.");
            }
        } elseif (!$selfClosed) {
            $stack[] = ['name' => $name, 'line' => $line];
        }
    }
    foreach ($stack as $open) {
        $report->error($rel, 'block-markup', "line {$open['line']}: block 'wp:{$open['name']}' never closed.");
    }
};

// ------------------------------------------------ pass 1: text artifacts

$referencedSlugs = []; // slug => first referencing file

foreach ($textFiles as [$rel, $abs]) {
    $bytes = $readFile($abs);
    if ($bytes === null) {
        $report->error($rel, 'io', 'unreadable file.');
        continue;
    }
    $encodingOk = $checkEncoding($rel, $bytes);
    $checkUrls($rel, $bytes);

    // attachment references — collected even from broken files (gate below).
    if (preg_match_all($placeholderPattern, $bytes, $pm, PREG_SET_ORDER) !== false) {
        foreach ($pm as $token) {
            if (($token[1] === 'attachment' || $token[1] === 'attachment_url') && isset($token[2]) && $token[2] !== '') {
                $referencedSlugs[$token[2]] ??= $rel;
            }
        }
    }
    if (!$encodingOk) {
        continue;
    }

    if (str_ends_with($rel, '.global-styles.json')) {
        // §4.5 + GlobalStylesAdapter: the file is a frontmatter document —
        // YAML frontmatter (uuid/post_type/slug/title/status/stylesheet/hash)
        // + JSON body. json_decode on the WHOLE file would reject every
        // legitimate global-styles artifact (r7 🔴2).
        try {
            [$fm, $body] = $splitDocument($bytes);
        } catch (\Throwable $e) {
            $report->error($rel, 'frontmatter', $e->getMessage());
            continue;
        }
        foreach (['uuid', 'post_type', 'slug', 'title', 'status', 'stylesheet', 'hash'] as $key) {
            if (!array_key_exists($key, $fm)) {
                $report->error($rel, 'schema', "frontmatter missing required key '{$key}'.");
            }
        }
        if (isset($fm['uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $fm['uuid']) !== 1) {
            $report->error($rel, 'schema', "'uuid' is not a UUID.");
        }
        if (isset($fm['stylesheet'])) {
            if (preg_match('/^[a-z0-9][a-z0-9_\-]*$/', (string) $fm['stylesheet']) !== 1) {
                $report->error($rel, 'schema', "'stylesheet' violates ^[a-z0-9][a-z0-9_\\-]*\$.");
            } elseif ($fm['stylesheet'] !== basename($rel, '.global-styles.json')) {
                $report->error($rel, 'schema', "frontmatter stylesheet '{$fm['stylesheet']}' != filename '" . basename($rel, '.global-styles.json') . "' (§4.5: um arquivo por stylesheet).");
            }
        }
        if (isset($fm['hash']) && preg_match('/^sha256:[0-9a-f]{64}$/', (string) $fm['hash']) !== 1) {
            $report->error($rel, 'schema', "'hash' must be sha256:<64 hex>.");
        }
        if (json_decode($body, true) === null && trim($body) !== 'null') {
            $report->error($rel, 'json', 'invalid JSON in global styles body: ' . json_last_error_msg());
        }
        continue;
    }

    if (str_ends_with($rel, '.menu.yml')) {
        try {
            $doc = $parseYaml($bytes);
        } catch (\Throwable $e) {
            $report->error($rel, 'frontmatter', $e->getMessage());
            continue;
        }
        foreach (['uuid', 'name', 'slug'] as $key) {
            if (!isset($doc[$key]) || $doc[$key] === '') {
                $report->error($rel, 'schema', "menu document missing required key '{$key}'.");
            }
        }
        if (isset($doc['items']) && !is_array($doc['items'])) {
            $report->error($rel, 'schema', "'items' must be a list.");
        }
        continue;
    }

    // Apêndice B.3 — sidecar de termo (espelho do menu + parent/taxonomy×dir).
    if (str_ends_with($rel, '.term.yml')) {
        try {
            $doc = $parseYaml($bytes);
        } catch (\Throwable $e) {
            $report->error($rel, 'frontmatter', $e->getMessage());
            continue;
        }
        foreach (['uuid', 'taxonomy', 'slug', 'name'] as $key) {
            if (!isset($doc[$key]) || $doc[$key] === '') {
                $report->error($rel, 'schema', "term document missing required key '{$key}'.");
            }
        }
        if (isset($doc['uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $doc['uuid']) !== 1) {
            $report->error($rel, 'schema', "'uuid' is not a UUID.");
        }
        if (isset($doc['taxonomy']) && preg_match('/^[a-z0-9_.\-]+$/', (string) $doc['taxonomy']) !== 1) {
            $report->error($rel, 'schema', "'taxonomy' must match sanitize_key alphabet (no ':').");
        }
        if (isset($doc['slug']) && preg_match('/^[a-z0-9][a-z0-9_\-]*$/', (string) $doc['slug']) !== 1) {
            $report->error($rel, 'schema', "'slug' violates ^[a-z0-9][a-z0-9_\\-]*\$ (§6.4/B.3).");
        }
        if (isset($doc['slug']) && basename($rel, '.term.yml') !== (string) $doc['slug']) {
            $report->error($rel, 'schema', "file name must equal 'slug' (found '" . basename($rel, '.term.yml') . "' vs '{$doc['slug']}').");
        }
        if (isset($doc['parent'])) {
            if (!is_string($doc['parent']) && $doc['parent'] !== null) {
                $report->error($rel, 'schema', "'parent' must be a slug string or null.");
            } elseif (is_string($doc['parent']) && preg_match('/^[a-z0-9][a-z0-9_\-]*$/', $doc['parent']) !== 1) {
                $report->error($rel, 'schema', "'parent' must be a slug of the SAME taxonomy (§6.4/B.4).");
            }
        }
        if (isset($doc['hash']) && preg_match('/^sha256:[0-9a-f]{64}$/', (string) $doc['hash']) !== 1) {
            $report->error($rel, 'schema', "'hash' must be sha256:<64 hex>.");
        }
        if (isset($doc['meta']) && !is_array($doc['meta'])) {
            $report->error($rel, 'schema', "'meta' must be a map.");
        }
        // taxonomy × diretório: content/terms/{dir}/{slug}.term.yml, com o dir
        // default sanitizado (B.1.1 + 🟡B4: '_'/'.' → '-'). Sem a config do
        // filtro não sabemos o dir custom — validamos apenas a forma do prefixo.
        if (isset($doc['taxonomy']) && !preg_match('#^terms/[a-z0-9][a-z0-9\-]*/#', $rel)) {
            $report->error($rel, 'layout', 'term sidecar must live under content/terms/<taxonomy-dir>/ (B.3).');
        }
        continue;
    }

    if (str_ends_with($rel, '.html')) {
        try {
            [$fm, $body] = $splitDocument($bytes);
        } catch (\Throwable $e) {
            $report->error($rel, 'frontmatter', $e->getMessage());
            continue;
        }
        // frontmatter schema (§4.2)
        foreach (['uuid', 'post_type', 'slug', 'title', 'status', 'hash'] as $key) {
            if (!array_key_exists($key, $fm)) {
                $report->error($rel, 'schema', "frontmatter missing required key '{$key}'.");
            }
        }
        if (isset($fm['uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $fm['uuid']) !== 1) {
            $report->error($rel, 'schema', "'uuid' is not a UUID.");
        }
        if (isset($fm['slug']) && preg_match('/^[a-z0-9][a-z0-9_\-]*$/', (string) $fm['slug']) !== 1) {
            $report->error($rel, 'schema', "'slug' violates ^[a-z0-9][a-z0-9_\\-]*\$ (§6.4).");
        }
        if (isset($fm['status'])) {
            if (!in_array($fm['status'], ['publish', 'draft', 'private'], true)) {
                $report->error($rel, 'schema', "unexpected status '{$fm['status']}'.");
            } elseif ($fm['status'] !== 'publish') {
                $report->warn($rel, 'sensitive-content', "status '{$fm['status']}' — non-public content enters the repo history (§3.2).");
            }
        }
        if (isset($fm['hash']) && preg_match('/^sha256:[0-9a-f]{64}$/', (string) $fm['hash']) !== 1) {
            $report->error($rel, 'schema', "'hash' must be sha256:<64 hex>.");
        }
        $checkBlockMarkup($rel, $body);
        continue;
    }

    $report->warn($rel, 'layout', 'unrecognized artifact under content/ — no lint rules applied.');
}

// ------------------------------------------------ pass 2: media sidecars

$finfo = null;
$sidecarOk = []; // slug => bool (passed all hard checks)

foreach ($sidecars as $slug => $abs) {
    $rel = substr($abs, strlen($contentDir) + 1);
    $ok = true;
    $bytes = $readFile($abs);
    if ($bytes === null) {
        $report->error($rel, 'io', 'unreadable sidecar.');
        continue;
    }
    if (!$checkEncoding($rel, $bytes)) {
        continue;
    }
    try {
        $doc = $parseYaml($bytes);
    } catch (\Throwable $e) {
        $report->error($rel, 'frontmatter', $e->getMessage());
        continue;
    }

    foreach (['uuid', 'slug', 'mime', 'original_filename', 'blob', 'hash'] as $key) {
        if (!isset($doc[$key]) || $doc[$key] === '') {
            $report->error($rel, 'schema', "sidecar missing required key '{$key}'.");
            $ok = false;
        }
    }
    if (isset($doc['slug']) && $doc['slug'] !== $slug) {
        $report->error($rel, 'schema', "filename slug '{$slug}' != sidecar slug '{$doc['slug']}'.");
        $ok = false;
    }

    // MIME whitelist (static, independent — §A.5.1.1) + SVG policy (§A.9.3)
    $mime = (string) ($doc['mime'] ?? '');
    if ($mime !== '') {
        if ($mime === 'image/svg+xml' && empty($config['attachment_allow_svg'])) {
            $report->error($rel, 'mime', 'SVG is default-deny (§A.9.3) — enable attachment_allow_svg knowingly.');
            $ok = false;
        } elseif ($mime === 'image/svg+xml' && !class_exists('enshrined\\svgSanitize\\Sanitizer')) {
            $report->error($rel, 'mime', 'SVG opt-in requires a sanitizer (enshrined/svg-sanitize) in the lint — fail-closed (§A.9.3).');
            $ok = false;
        } elseif (!in_array($mime, (array) $config['attachment_mime_types'], true)) {
            $report->error($rel, 'mime', "MIME '{$mime}' outside the whitelist.");
            $ok = false;
        }
    }

    // Double-extension / executable — hard rule (§A.9.5)
    $original = (string) ($doc['original_filename'] ?? '');
    if ($original !== '') {
        $parts = explode('.', strtolower($original));
        array_shift($parts);
        foreach ($parts as $ext) {
            if (in_array($ext, DANGEROUS_EXTENSIONS, true)) {
                $report->error($rel, 'double-extension', "original_filename '{$original}' contains executable extension '.{$ext}'.");
                $ok = false;
            }
        }
    }

    // CAS integrity (§A.9.5): blob field == real hash; blob exists.
    $blobField = (string) ($doc['blob'] ?? '');
    if ($blobField !== '' && preg_match('/^sha256:([0-9a-f]{64})$/', $blobField, $bm) !== 1) {
        $report->error($rel, 'cas', "'blob' must be sha256:<64 hex>.");
        $ok = false;
    } elseif ($blobField !== '') {
        $hash = $bm[1];
        $blobAbs = null;
        foreach ($blobs as $blobRel => $candidate) {
            if (str_starts_with($blobRel, 'media/bin/' . substr($hash, 0, 2) . '/' . $hash . '.')) {
                $blobAbs = $candidate;
                break;
            }
        }
        if ($blobAbs === null) {
            $report->error($rel, 'cas', "sidecar references blob {$hash} but no blob exists under media/bin/ — restore from history: git checkout <commit> -- content/media/bin/ (§A.13.9).");
            $ok = false;
        } else {
            $blobBytes = $readFile($blobAbs);
            $blobRel = substr($blobAbs, strlen($contentDir) + 1);
            if ($blobBytes === null) {
                $report->error($blobRel, 'io', 'unreadable blob.');
                $ok = false;
            } else {
                // LFS pointer detection (§A.9.4) — before any hash comparison.
                if (str_starts_with($blobBytes, LFS_POINTER_PREFIX)) {
                    $report->error($blobRel, 'lfs-pointer-detected', 'Git LFS pointer committed instead of the binary. LFS is unsupported in v1: run `git lfs uninstall`, replace the pointer with the real file and re-commit (§A.9.4).');
                    $ok = false;
                } else {
                    $real = hash('sha256', $blobBytes);
                    $stem = pathinfo(basename($blobRel), PATHINFO_FILENAME);
                    if ($real !== $hash || $stem !== $hash) {
                        $report->error($blobRel, 'binary-hash-mismatch', "CAS triple-equality failed (filename={$stem}, sidecar={$hash}, recomputed={$real}) — never materialize (§A.5.1.7).");
                        $ok = false;
                    }

                    // size policy (§A.9.5 / §A.5.4)
                    $size = strlen($blobBytes);
                    if ($size > (int) $config['attachment_max_bytes']) {
                        $report->error($blobRel, 'size', sprintf('%0.1f MB exceeds attachment_max_bytes (%0.1f MB) — not versionable.', $size / 1048576, $config['attachment_max_bytes'] / 1048576));
                        $ok = false;
                    } elseif ($size >= (int) $config['attachment_warn_bytes']) {
                        $dims = isset($doc['width'], $doc['height']) ? " ({$doc['width']}x{$doc['height']})" : '';
                        $report->warn($blobRel, 'size', sprintf('%0.1f MB%s — optimize / convert to WebP (§A.9.5).', $size / 1048576, $dims));
                    }
                    if (isset($doc['blob_size']) && (int) $doc['blob_size'] !== $size) {
                        $report->warn($rel, 'cas', "declared blob_size {$doc['blob_size']} != real {$size} (informative field).");
                    }

                    // magic bytes × whitelist (finfo — never extension)
                    if (!extension_loaded('fileinfo')) {
                        $report->error($blobRel, 'mime', 'ext-fileinfo unavailable in CI — cannot verify magic bytes; failing closed.');
                        $ok = false;
                    } else {
                        $finfo ??= finfo_open(FILEINFO_MIME_TYPE);
                        $magic = finfo_file($finfo, $blobAbs) ?: '';
                        $expected = MAGIC_MAP[$mime] ?? [];
                        if ($expected !== [] && !in_array($magic, $expected, true)) {
                            $report->error($blobRel, 'mime', "magic bytes '{$magic}' contradict declared/whitelisted MIME '{$mime}'.");
                            $ok = false;
                        }
                    }

                    // full-file PHP tag scan — classified honestly as HEURISTIC (§A.9.5)
                    if (str_contains($blobBytes, '<?php') || str_contains($blobBytes, '<?=')) {
                        $report->error($blobRel, 'content-scan', 'blob contains <?php/<?= (heuristic, full-file scan — polyglot post-IEND coverage).');
                        $ok = false;
                    }

                    // megapixels (pixel-bomb ceiling — header-only getimagesize)
                    if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
                        $info = @getimagesize($blobAbs);
                        if ($info === false) {
                            $report->warn($blobRel, 'megapixels', 'getimagesize() failed on an image blob (would apply as applied-degraded, §A.5.6).');
                        } elseif (($info[0] * $info[1]) > ((int) $config['max_megapixels']) * 1000000) {
                            $report->error($blobRel, 'megapixels', sprintf('%0.1f MP exceeds the %d MP ceiling (pixel-bomb guard, §A.5.1.4).', ($info[0] * $info[1]) / 1000000, $config['max_megapixels']));
                            $ok = false;
                        }
                    }
                }
            }
        }
    }

    $sidecarOk[$slug] = $ok;
}

// ------------------------------------------- pass 3: referential gate (hard)

// Forward gate (§A.9.5, mandatory): every {{attachment:slug}} /
// {{attachment_url:slug}} requires an INTACT sidecar in PR/repo.
foreach ($referencedSlugs as $slug => $referencingFile) {
    if (!isset($sidecars[$slug])) {
        $report->error($referencingFile, 'referential-gate', "reference {{attachment:{$slug}}} has no '{$slug}.attachment.yml' in PR/repo — merge blocked (§A.9.5).");
    } elseif (($sidecarOk[$slug] ?? false) === false) {
        $report->error($referencingFile, 'referential-gate', "referenced sidecar '{$slug}.attachment.yml' is not intact (see its errors above).");
    }
}

// Reverse direction: warnings only (§A.9.5).
foreach ($sidecars as $slug => $abs) {
    if (!isset($referencedSlugs[$slug])) {
        $report->warn(substr($abs, strlen($contentDir) + 1), 'referential-gate', "sidecar '{$slug}' is not referenced by any versioned entity (kept; cleanup is manual GC, §A.5.5).");
    }
}
$referencedBlobs = [];
foreach ($sidecars as $slug => $abs) {
    $bytes = $readFile($abs);
    if ($bytes !== null && preg_match('/^blob:\s*"?sha256:([0-9a-f]{64})"?/m', $bytes, $bm) === 1) {
        $referencedBlobs[$bm[1]] = true;
    }
}
foreach ($blobs as $blobRel => $blobAbs) {
    $stem = pathinfo(basename($blobRel), PATHINFO_FILENAME);
    if (!isset($referencedBlobs[$stem])) {
        $report->warn($blobRel, 'referential-gate', 'blob has no sidecar referencing it (GC candidate, manual — §A.7.1).');
    }
}

// --------------------------------------------------------------------- exit

$report->summary();
exit($report->errors > 0 ? 1 : 0);
