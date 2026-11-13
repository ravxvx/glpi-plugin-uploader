<?php

/**
 * Plugin Uploader - upload page (Setup > Plugins > Plugin Uploader).
 */

use GlpiPlugin\Pluginupload\Installer;

Session::checkRight('config', UPDATE);

// GLPI 12+: installing code is a sensitive action - ask for the password again (same as the Plugins page)
if (class_exists(\Glpi\Security\ReAuth\ReAuthManager::class)) {
    \Glpi\Security\ReAuth\ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();
}

global $CFG_GLPI;
$self    = $CFG_GLPI['root_doc'] . '/plugins/pluginupload/front/upload.php';
$plugins = $CFG_GLPI['root_doc'] . '/front/plugin.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $installer = new Installer();
    $error     = $installer->installFromUpload($_FILES['plugin'] ?? []);

    if ($error !== null) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
        Html::redirect($self);
    }

    $msg = sprintf(
        'Plugin "%s" %s uploaded. Now click Install / Update and then Enable on this page.',
        $installer->plugin_name,
        $installer->plugin_version
    );
    foreach ($installer->notes as $note) {
        $msg .= ' ' . $note;
    }
    Session::addMessageAfterRedirect(htmlescape($msg), false, INFO);
    Html::redirect($plugins);
}

Html::header('Plugin Uploader', '', 'config', 'plugin');

$e       = static fn(string $s): string => htmlescape($s);
$limit   = min(
    (int) Toolbox::return_bytes_from_ini_vars((string) ini_get('upload_max_filesize')),
    (int) Toolbox::return_bytes_from_ini_vars((string) ini_get('post_max_size'))
);
$limitMb = $limit > 0 ? round($limit / 1048576, 1) . ' MB' : '?';
$dir     = GLPI_MARKETPLACE_DIR;
$canWrite = is_dir($dir) && is_writable($dir);

echo '<div class="container-fluid" style="max-width: 900px">';

if (!$canWrite) {
    echo '<div class="alert alert-danger mt-3"><b>GLPI cannot write to ' . $e($dir) . '.</b> '
        . 'Run once on the server: <code>chown -R www-data:www-data ' . $e($dir) . '</code></div>';
}

echo <<<HTML
  <div class="card mt-3">
    <div class="card-header"><h3 class="card-title"><i class="ti ti-upload me-2"></i>Upload a GLPI plugin</h3></div>
    <div class="card-body">
      <form method="post" action="{$e($self)}" enctype="multipart/form-data">
        <div class="mb-3">
          <label class="form-label" for="pu_file">Plugin archive (.zip, .tar.gz, .tgz)</label>
          <input class="form-control" type="file" id="pu_file" name="plugin" accept=".zip,.tgz,.gz,.bz2,.tar" required>
          <div class="form-hint">New plugins are added, existing ones are updated (same folder name). Max size: {$e($limitMb)}.</div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="ti ti-upload me-1"></i>Upload</button>
        <a class="btn btn-outline-secondary ms-2" href="{$e($plugins)}">Back to Plugins</a>
      </form>
      <div class="alert alert-warning mt-4 mb-0">
        <div><b>Only upload plugins you trust</b> (your own code, GitHub releases of known plugins, GLPI Marketplace).
        A plugin runs with full access to GLPI and its database.</div>
      </div>
    </div>
  </div>
HTML;

$rows = Installer::listUploaded();
echo '<div class="card mt-3"><div class="card-header"><h3 class="card-title">Plugins in ' . $e($dir) . '</h3></div>';
if ($rows === []) {
    echo '<div class="card-body text-muted">None yet.</div>';
} else {
    echo '<div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr>'
        . '<th>Name</th><th>Folder</th><th>Version</th><th>Files date</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . $e($r['name']) . '</td><td><code>' . $e($r['key']) . '</code></td><td>'
            . $e($r['version']) . '</td><td>' . $e($r['date']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';

Html::footer();
