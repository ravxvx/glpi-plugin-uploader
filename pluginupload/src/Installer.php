<?php

namespace GlpiPlugin\Pluginupload;

use Plugin;
use Toolbox;

/**
 * Extracts an uploaded plugin archive into GLPI's marketplace directory
 * (the same place GLPI's own Marketplace installs plugins) and registers it.
 */
class Installer
{
    public const MAX_FILES = 20000;

    /** @var string[] */
    public array $notes = [];

    public string $plugin_key = '';
    public string $plugin_name = '';
    public string $plugin_version = '';

    /**
     * @return string|null error message, or null on success
     */
    public function installFromUpload(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'No file selected (or the file is bigger than the server upload limit).';
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return 'The file is bigger than the server upload limit (' . ini_get('upload_max_filesize') . ').';
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return 'Upload failed (error code ' . (int) $file['error'] . ').';
        }

        $name = strtolower((string) $file['name']);
        if (str_ends_with($name, '.zip')) {
            $type = 'zip';
        } elseif (str_ends_with($name, '.tar.gz') || str_ends_with($name, '.tgz')) {
            $type = 'tar.gz';
        } elseif (str_ends_with($name, '.tar.bz2')) {
            $type = 'tar.bz2';
        } elseif (str_ends_with($name, '.tar')) {
            $type = 'tar';
        } else {
            return 'Allowed archive types: .zip, .tar.gz, .tgz, .tar.bz2, .tar';
        }

        if (!is_dir(GLPI_MARKETPLACE_DIR) || !is_writable(GLPI_MARKETPLACE_DIR)) {
            return 'GLPI cannot write to the plugin folder ' . GLPI_MARKETPLACE_DIR
                . '. Fix it once on the server: chown -R www-data:www-data ' . GLPI_MARKETPLACE_DIR;
        }

        $work = GLPI_TMP_DIR . '/pluginupload_' . bin2hex(random_bytes(6));
        if (!@mkdir($work . '/x', 0755, true)) {
            return 'Cannot create a temporary folder in ' . GLPI_TMP_DIR . '.';
        }

        try {
            $archive = $work . '/upload.' . $type;
            if (!move_uploaded_file($file['tmp_name'], $archive)) {
                return 'Cannot read the uploaded file.';
            }

            $error = $this->extract($archive, $type, $work . '/x');
            if ($error !== null) {
                return $error;
            }

            $root = $this->findPluginRoot($work . '/x');
            if ($root === null) {
                return 'No GLPI plugin found in the archive (a setup.php with a plugin_version_xxx() function is required).';
            }

            $error = $this->readPluginInfo($root);
            if ($error !== null) {
                return $error;
            }

            $error = $this->deploy($root);
            if ($error !== null) {
                return $error;
            }

            $this->register();
            return null;
        } finally {
            Toolbox::deleteDir($work);
        }
    }

    /* ------------------------------------------------------------ extract */

    private function safeEntry(string $entry): bool
    {
        $entry = str_replace('\\', '/', $entry);
        if ($entry === '' || str_starts_with($entry, '/') || preg_match('#^[A-Za-z]:#', $entry)) {
            return false;
        }
        foreach (explode('/', $entry) as $part) {
            if ($part === '..') {
                return false;
            }
        }
        return true;
    }

    private function extract(string $archive, string $type, string $dest): ?string
    {
        try {
            if ($type === 'zip') {
                if (class_exists(\ZipArchive::class)) {
                    $zip = new \ZipArchive();
                    if ($zip->open($archive) !== true) {
                        return 'The ZIP file is damaged or not a ZIP archive.';
                    }
                    if ($zip->numFiles > self::MAX_FILES) {
                        $zip->close();
                        return 'The archive contains too many files.';
                    }
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        if (!$this->safeEntry((string) $zip->getNameIndex($i))) {
                            $zip->close();
                            return 'The archive contains unsafe paths - refused.';
                        }
                    }
                    $ok = $zip->extractTo($dest);
                    $zip->close();
                    return $ok ? null : 'Could not extract the ZIP file.';
                }
                return $this->extractWithUnifiedArchive($archive, $dest);
            }

            $phar = new \PharData($archive);
            $count = 0;
            foreach (new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST) as $f) {
                $rel = substr($f->getPathname(), strlen('phar://' . $archive) + 1);
                if (!$this->safeEntry($rel) || ++$count > self::MAX_FILES) {
                    return 'The archive contains unsafe paths or too many files - refused.';
                }
            }
            $phar->extractTo($dest, null, true);
            return null;
        } catch (\Throwable $e) {
            return 'Could not extract the archive: ' . $e->getMessage();
        }
    }

    /** Fallback when the PHP zip extension is missing: GLPI's bundled archive library. */
    private function extractWithUnifiedArchive(string $archive, string $dest): ?string
    {
        if (!class_exists(\wapmorgan\UnifiedArchive\UnifiedArchive::class)) {
            return 'This server cannot open ZIP files - upload a .tar.gz instead.';
        }
        $ua = \wapmorgan\UnifiedArchive\UnifiedArchive::open($archive);
        if ($ua === null) {
            return 'This server cannot open ZIP files - upload a .tar.gz instead.';
        }
        $names = $ua->getFiles();
        if (count($names) > self::MAX_FILES) {
            return 'The archive contains too many files.';
        }
        foreach ($names as $n) {
            if (!$this->safeEntry((string) $n)) {
                return 'The archive contains unsafe paths - refused.';
            }
        }
        $ua->extract($dest);
        return null;
    }

    /* ------------------------------------------------------------ inspect */

    /** setup.php at the top, or one / two folders deep (e.g. "myplugin-main/"). */
    private function findPluginRoot(string $dir): ?string
    {
        $candidates = [$dir];
        foreach ([1, 2] as $depth) {
            $next = [];
            foreach ($candidates as $c) {
                if (is_file($c . '/setup.php') && $this->setupKey($c . '/setup.php') !== null) {
                    return $c;
                }
                foreach (glob($c . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
                    if (basename($sub) !== '__MACOSX') {
                        $next[] = $sub;
                    }
                }
            }
            $candidates = $next;
        }
        foreach ($candidates as $c) {
            if (is_file($c . '/setup.php') && $this->setupKey($c . '/setup.php') !== null) {
                return $c;
            }
        }
        return null;
    }

    private function setupKey(string $setup): ?string
    {
        $code = (string) @file_get_contents($setup);
        if (preg_match('/function\s+plugin_version_([a-z0-9]+)\s*\(/i', $code, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    private function readPluginInfo(string $root): ?string
    {
        $key = $this->setupKey($root . '/setup.php');
        if ($key === null || !preg_match('/^[a-z0-9]+$/', $key)) {
            return 'Invalid plugin name in setup.php (only lowercase letters and digits are allowed).';
        }
        $this->plugin_key = $key;

        $folder = strtolower(basename($root));
        if ($folder !== $key && $folder !== 'x') {
            $this->notes[] = sprintf('The archive folder "%s" was renamed to "%s".', basename($root), $key);
        }

        $code = (string) file_get_contents($root . '/setup.php');
        if (preg_match("/'name'\s*=>\s*'([^']{1,100})'/", $code, $m)) {
            $this->plugin_name = $m[1];
        }
        if (preg_match("/define\(\s*'[A-Z0-9_]*VERSION'\s*,\s*'([^']{1,30})'/", $code, $m)
            || preg_match("/'version'\s*=>\s*'([^']{1,30})'/", $code, $m)) {
            $this->plugin_version = $m[1];
        }
        if ($this->plugin_name === '') {
            $this->plugin_name = $key;
        }
        return null;
    }

    /* ------------------------------------------------------------- deploy */

    private function deploy(string $root): ?string
    {
        $target = GLPI_MARKETPLACE_DIR . '/' . $this->plugin_key;

        if (is_dir($target)) {
            if (!is_writable($target)) {
                return 'The existing plugin folder ' . $target . ' is not writable by GLPI.';
            }
            if (function_exists('opcache_invalidate')) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && $f->getExtension() === 'php') {
                        @opcache_invalidate($f->getPathname(), true);
                    }
                }
            }
            Toolbox::deleteDir($target);
            $this->notes[] = 'The previous version was replaced.';
        }

        if (!$this->copyTree($root, $target)) {
            return 'Could not copy the plugin files into ' . $target . '.';
        }

        if (is_dir(GLPI_ROOT . '/plugins/' . $this->plugin_key)) {
            $this->notes[] = 'An older copy also exists in ' . GLPI_ROOT . '/plugins/' . $this->plugin_key
                . ' - GLPI now uses the uploaded version (marketplace folder has priority).';
        }
        return null;
    }

    private function copyTree(string $src, string $dst): bool
    {
        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            if ($item->isLink()) {
                continue; // never copy symlinks
            }
            $rel  = substr($item->getPathname(), strlen($src) + 1);
            $dest = $dst . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
                    return false;
                }
            } elseif (!@copy($item->getPathname(), $dest)) {
                return false;
            } else {
                @chmod($dest, 0644);
            }
        }
        return true;
    }

    /* ----------------------------------------------------------- register */

    /** Same steps as GLPI's Marketplace after a download. */
    private function register(): void
    {
        $plugin = new Plugin();
        if ($plugin->getFromDBbyDir($this->plugin_key)) {
            $input = ['id' => $plugin->fields['id']];
            if ($this->plugin_version !== '') {
                $input['version'] = $this->plugin_version;
            }
            if (!in_array((int) $plugin->fields['state'], [Plugin::ANEW, Plugin::NOTINSTALLED, Plugin::NOTUPDATED], true)) {
                $input['state'] = Plugin::NOTUPDATED;
            }
            $plugin->update($input);
        } else {
            $plugin->add([
                'name'      => $this->plugin_name,
                'directory' => $this->plugin_key,
                'version'   => $this->plugin_version !== '' ? $this->plugin_version : '0',
                'state'     => Plugin::NOTINSTALLED,
            ]);
        }

        if (!$plugin->isPluginsExecutionSuspended()) {
            $plugin->checkPluginState($this->plugin_key);
        }
    }

    /** Plugins currently stored in the marketplace folder. */
    public static function listUploaded(): array
    {
        $rows = [];
        foreach (glob(GLPI_MARKETPLACE_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $key  = basename($dir);
            $info = (new Plugin())->getInformationsFromDirectory($key, false);
            $rows[] = [
                'key'     => $key,
                'name'    => (string) ($info['name'] ?? $key),
                'version' => (string) ($info['version'] ?? '?'),
                'date'    => date('Y-m-d H:i', (int) @filemtime($dir . '/setup.php')),
            ];
        }
        usort($rows, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $rows;
    }
}
