<?php
// Direkt am Dateianfang: verschlüsselter Name
$encryptedName = "TWFya3VzIEdtZWluaGFydA=="; // Base64: "Markus Gmeinhart"
$decodedName = base64_decode($encryptedName);
if ($decodedName === false || is_null($decodedName)) {
    $decodedName = "Markus Gmeinhart";
}

/*****************************************************************************
 * index.php
 *
 * Dieses Open-Source-Projekt implementiert ein CRUD-System (Create, Read,
 * Update, Delete) für Links, die in einer JSON-Datei gespeichert werden.
 * Es basiert auf dem NiceAdmin-Template und nutzt Bootstrap für das Layout.
 *
 * Erweiterte Funktionen:
 * - CSRF-Schutz und Flash-Messages
 * - Eingabevalidierung (inklusive URL-Validierung)
 * - File Locking beim Schreiben in die JSON-Datei
 * - Import/Export-Funktionalität für Links und Einstellungen (settings.json)
 * - Konfigurierbare globale Einstellungen (Überschrift, Primär-, Sekundär-, 
 *   Hintergrund- und Textfarbe)
 * - Option zum Wiederherstellen der Standardwerte (Defaulteinstellungen)
 * - Bild/Icon-Upload für jeden Link (mit automatischem Resizing, gespeichert in "linkbilder")
 * - Möglichkeit, bereits gespeicherte Icons beim Bearbeiten zu löschen
 * - Export/Import der Linkbilder als ZIP-Datei (nur, wenn ZipArchive verfügbar ist)
 * - Info-Seite mit Release Notes, Entwicklerverweis und Lizenzinfo (z.B. MIT License)
 *
 * Autor: [verschlüsselt]
 * Copyright: © <?= date('Y'); ?> <?= htmlspecialchars($decodedName, ENT_QUOTES, 'UTF-8'); ?>
 * Lizenz: <a href="https://opensource.org/licenses/MIT" target="_blank">MIT License</a>
 *****************************************************************************/

// Fehleranzeige (nur in der Entwicklung!)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Prüfen, ob random_bytes() verfügbar ist (PHP 7.0+)
if (!function_exists('random_bytes')) {
    die("Error: random_bytes() wird von dieser PHP-Version nicht unterstützt. Bitte PHP 7.0+ verwenden.");
}

session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function setFlashMessage($message) {
    $_SESSION['flash_message'] = $message;
}
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $msg = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $msg;
    }
    return '';
}

// Standard-Einstellungen (inkl. erweiterter Farbanpassungen)
$defaultSettings = [
    'siteHeading'     => 'HAUSTECHNIK',
    'primaryColor'    => '#000000', // Überschrift, Navbar, Sidebar
    'secondaryColor'  => '#ffffff', // Sekundäre Elemente
    'backgroundColor' => '#f8f9fa', // Seitenhintergrund
    'textColor'       => '#333333'  // Standard-Textfarbe
];

// Einstellungen aus settings.json laden/speichern
$settingsFile = __DIR__ . '/settings.json';
function getSettings($settingsFile, $defaultSettings) {
    if (!file_exists($settingsFile)) {
        return $defaultSettings;
    }
    $data = @file_get_contents($settingsFile);
    $settings = json_decode($data, true);
    return is_array($settings) ? $settings : $defaultSettings;
}
function saveSettings($settingsFile, $settings) {
    $jsonData = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($settingsFile, $jsonData);
}
$settings = getSettings($settingsFile, $defaultSettings);

// Links-Daten (links.json)
$jsonFile = __DIR__ . '/links.json';
function getLinks($jsonFile) {
    if (!file_exists($jsonFile)) {
        error_log("Warning: JSON file not found at $jsonFile");
        return [];
    }
    $jsonData = @file_get_contents($jsonFile);
    if ($jsonData === false) {
        error_log("Error reading JSON file: $jsonFile");
        return [];
    }
    return json_decode($jsonData, true) ?? [];
}
function saveLinks($jsonFile, $linksArray) {
    $jsonData = json_encode($linksArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $maxRetries = 3;
    $retryCount = 0;
    while ($retryCount < $maxRetries) {
        $fp = fopen($jsonFile, 'c+');
        if ($fp === false) {
            error_log("Error: Could not open file $jsonFile for writing.");
            $retryCount++;
            usleep(100000);
            continue;
        }
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, $jsonData);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            error_log("Save to file succeeded after $retryCount retries.");
            return;
        } else {
            error_log("Error: Could not lock file $jsonFile (attempt $retryCount).");
            fclose($fp);
        }
        usleep(100000);
        $retryCount++;
    }
    throw new Exception("Failed to save data to file: $jsonFile after $maxRetries attempts");
}
$links = getLinks($jsonFile);

// Sicherstellen, dass der Ordner für Linkbilder existiert
$linkbilderDir = __DIR__ . '/linkbilder';
if (!is_dir($linkbilderDir)) {
    mkdir($linkbilderDir, 0777, true);
}

// Hilfsfunktion für Bild-Upload und Resizing (Ziel: 64x64 Pixel)
function processIconUpload($fileInput, $targetDir, $targetWidth = 64, $targetHeight = 64) {
    if (!isset($_FILES[$fileInput]) || $_FILES[$fileInput]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmpName = $_FILES[$fileInput]['tmp_name'];
    $imageInfo = getimagesize($tmpName);
    if ($imageInfo === false) {
        return null;
    }
    $mime = $imageInfo['mime'];
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'])) {
        return null;
    }
    switch ($mime) {
        case 'image/jpeg':
            $srcImage = imagecreatefromjpeg($tmpName);
            $extension = '.jpg';
            break;
        case 'image/png':
            $srcImage = imagecreatefrompng($tmpName);
            $extension = '.png';
            break;
        case 'image/gif':
            $srcImage = imagecreatefromgif($tmpName);
            $extension = '.gif';
            break;
        default:
            return null;
    }
    $resizedImage = imagescale($srcImage, $targetWidth, $targetHeight);
    imagedestroy($srcImage);
    $fileName = uniqid('icon_', true) . $extension;
    $targetPath = rtrim($targetDir, '/') . '/' . $fileName;
    if ($mime === 'image/jpeg') {
        imagejpeg($resizedImage, $targetPath);
    } elseif ($mime === 'image/png') {
        imagepng($resizedImage, $targetPath);
    } elseif ($mime === 'image/gif') {
        imagegif($resizedImage, $targetPath);
    }
    imagedestroy($resizedImage);
    return $fileName;
}

// Navigation: Der GET-Parameter "page" steuert den Bereich (dashboard, editLinks, settings, info)
// Für die Paginierung im Dashboard verwenden wir den Parameter "p"
$section = htmlspecialchars($_GET['page'] ?? 'dashboard');
$currentP = (int)($_GET['p'] ?? 1);

// Pagination Setup (nur für Dashboard)
if ($section === 'dashboard') {
    $itemsPerPage = 24;
    $totalItems   = count($links);
    $totalPages   = $totalItems > 0 ? ceil($totalItems / $itemsPerPage) : 1;
    $currentP     = max(1, min($totalPages, $currentP));
    $offset       = ($currentP - 1) * $itemsPerPage;
    $paginatedLinks = array_slice($links, $offset, $itemsPerPage);
}

// POST-Verarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Ungültiges CSRF-Token!");
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($section === 'settings') {
            if ($action === 'updateSettings') {
                $siteHeading     = trim($_POST['siteHeading'] ?? '');
                $primaryColor    = trim($_POST['primaryColor'] ?? '');
                $secondaryColor  = trim($_POST['secondaryColor'] ?? '');
                $backgroundColor = trim($_POST['backgroundColor'] ?? '');
                $textColor       = trim($_POST['textColor'] ?? '');
                if ($siteHeading !== '' &&
                    preg_match('/^#[a-fA-F0-9]{6}$/', $primaryColor) &&
                    preg_match('/^#[a-fA-F0-9]{6}$/', $secondaryColor) &&
                    preg_match('/^#[a-fA-F0-9]{6}$/', $backgroundColor) &&
                    preg_match('/^#[a-fA-F0-9]{6}$/', $textColor)
                ) {
                    $settings['siteHeading']     = $siteHeading;
                    $settings['primaryColor']    = $primaryColor;
                    $settings['secondaryColor']  = $secondaryColor;
                    $settings['backgroundColor'] = $backgroundColor;
                    $settings['textColor']       = $textColor;
                    saveSettings($settingsFile, $settings);
                    setFlashMessage("Einstellungen wurden aktualisiert.");
                }
            } elseif ($action === 'restoreDefaults') {
                $settings = $defaultSettings;
                saveSettings($settingsFile, $settings);
                setFlashMessage("Standardwerte wurden wiederhergestellt.");
            } elseif ($action === 'exportSettings') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="settings.json"');
                echo json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            } elseif ($action === 'importSettings') {
                if (isset($_FILES['settingsFile']) && $_FILES['settingsFile']['error'] === UPLOAD_ERR_OK) {
                    $data = file_get_contents($_FILES['settingsFile']['tmp_name']);
                    $imported = json_decode($data, true);
                    if (is_array($imported)) {
                        $settings = $imported;
                        saveSettings($settingsFile, $settings);
                        setFlashMessage("Einstellungen wurden importiert.");
                    }
                }
            }
        } elseif ($section === 'info') {
            // Keine POST-Aktionen auf der Info-Seite
        } else {
            // Aktionen für "dashboard" und "editLinks"
            if ($action === 'exportImages') {
                if (!class_exists('ZipArchive')) {
                    die("ZipArchive ist nicht verfügbar. Bitte installiere diese Erweiterung.");
                }
                $zip = new ZipArchive();
                $zipFile = tempnam(sys_get_temp_dir(), 'images') . '.zip';
                if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
                    die("Konnte ZIP-Datei nicht erstellen.");
                }
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($linkbilderDir));
                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($linkbilderDir) + 1);
                        $zip->addFile($filePath, $relativePath);
                    }
                }
                $zip->close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="linkbilder.zip"');
                readfile($zipFile);
                unlink($zipFile);
                exit;
            } elseif ($action === 'importImages') {
                if (isset($_FILES['imagesZip']) && $_FILES['imagesZip']['error'] === UPLOAD_ERR_OK) {
                    if (!class_exists('ZipArchive')) {
                        die("ZipArchive ist nicht verfügbar. Bitte installiere diese Erweiterung.");
                    }
                    $zip = new ZipArchive();
                    $zipFile = $_FILES['imagesZip']['tmp_name'];
                    if ($zip->open($zipFile) === TRUE) {
                        $zip->extractTo($linkbilderDir);
                        $zip->close();
                        setFlashMessage("Linkbilder wurden importiert.");
                    } else {
                        die("Fehler beim Extrahieren der ZIP-Datei.");
                    }
                }
            } elseif ($action === 'create') {
                $title = trim($_POST['title'] ?? '');
                $url   = trim($_POST['url'] ?? '');
                if ($title !== '' && $url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                    $newId = count($links) > 0 ? max(array_column($links, 'id')) + 1 : 1;
                    $newLink = [
                        'id'    => $newId,
                        'title' => $title,
                        'url'   => $url,
                        'icon'  => ''
                    ];
                    $iconFile = processIconUpload('icon', $linkbilderDir);
                    if ($iconFile !== null) {
                        $newLink['icon'] = $iconFile;
                    }
                    $links[] = $newLink;
                    saveLinks($jsonFile, $links);
                }
            } elseif ($action === 'update') {
                $id    = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $url   = trim($_POST['url'] ?? '');
                if ($title !== '' && $url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                    foreach ($links as &$link) {
                        if ($link['id'] === $id) {
                            $link['title'] = $title;
                            $link['url']   = $url;
                            if (isset($_POST['deleteIcon']) && $_POST['deleteIcon'] == '1') {
                                if (!empty($link['icon']) && file_exists($linkbilderDir . '/' . $link['icon'])) {
                                    unlink($linkbilderDir . '/' . $link['icon']);
                                }
                                $link['icon'] = '';
                            } else {
                                $iconFile = processIconUpload('icon', $linkbilderDir);
                                if ($iconFile !== null) {
                                    if (!empty($link['icon']) && file_exists($linkbilderDir . '/' . $link['icon'])) {
                                        unlink($linkbilderDir . '/' . $link['icon']);
                                    }
                                    $link['icon'] = $iconFile;
                                }
                            }
                            break;
                        }
                    }
                    unset($link);
                    saveLinks($jsonFile, $links);
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $deletedLink = array_filter($links, function($link) use ($id) {
                    return $link['id'] === $id;
                });
                if (!empty($deletedLink)) {
                    $deletedLinkData = reset($deletedLink);
                    error_log("Deleting link: ID={$deletedLinkData['id']}, Title={$deletedLinkData['title']}, URL={$deletedLinkData['url']}");
                    if (!empty($deletedLinkData['icon']) && file_exists($linkbilderDir . '/' . $deletedLinkData['icon'])) {
                        unlink($linkbilderDir . '/' . $deletedLinkData['icon']);
                    }
                }
                $links = array_filter($links, function($link) use ($id) {
                    return $link['id'] !== $id;
                });
                saveLinks($jsonFile, $links);
            } elseif ($action === 'export') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="links.json"');
                echo json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            } elseif ($action === 'import') {
                if (isset($_FILES['importFile']) && $_FILES['importFile']['error'] === UPLOAD_ERR_OK) {
                    $fileExt = pathinfo($_FILES['importFile']['name'], PATHINFO_EXTENSION);
                    if (strtolower($fileExt) !== 'json') {
                        die("Nur JSON-Dateien sind zum Import erlaubt.");
                    }
                    $data = file_get_contents($_FILES['importFile']['tmp_name']);
                    $importedLinks = json_decode($data, true);
                    if (is_array($importedLinks)) {
                        $links = array_merge($links, $importedLinks);
                        saveLinks($jsonFile, $links);
                    }
                }
            }
        }
    } catch (Exception $e) {
        die("Fehler: " . $e->getMessage());
    }
    
    header('Location: index.php?page=' . $section);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($settings['siteHeading']); ?> - Links</title>
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <style>
    body {
      background-color: <?= htmlspecialchars($settings['backgroundColor']); ?>;
      color: <?= htmlspecialchars($settings['textColor']); ?>;
    }
    h1, .navbar, .sidebar {
      color: <?= htmlspecialchars($settings['primaryColor']); ?>;
    }
    .btn-secondary {
      background-color: <?= htmlspecialchars($settings['secondaryColor']); ?>;
      border-color: <?= htmlspecialchars($settings['secondaryColor']); ?>;
    }
    #copyright {
      position: fixed;
      bottom: 0;
      left: 0;
      font-size: 1em;
      color: #fff;
      background: rgba(0, 0, 0, 0.9);
      padding: 8px 12px;
      z-index: 10000;
      border-top-right-radius: 5px;
      box-shadow: 2px -2px 5px rgba(0, 0, 0, 0.5);
    }
    /* Weißer Hintergrund für Icons, falls nötig */
    .icon-img {
      width: 64px;
      height: 64px;
      background-color: #fff;
      float: left;
      margin-right: 10px;
    }
  </style>
</head>
<body>
  <header id="header" class="header fixed-top d-flex align-items-center">
    <div class="d-flex align-items-center justify-content-between">
      <a href="index.php?page=dashboard" class="logo d-flex align-items-center">
        <img src="assets/img/logo.png" alt="">
        <span class="d-none d-lg-block"><?= htmlspecialchars($settings['siteHeading']); ?></span>
      </a>
    </div>
  </header>
  <aside id="sidebar" class="sidebar">
    <ul class="sidebar-nav" id="sidebar-nav">
      <li class="nav-item">
        <a class="nav-link<?= $section === 'dashboard' ? '' : ' collapsed'; ?>" href="index.php?page=dashboard">
          <i class="bi bi-house-door"></i>
          <span>Dashboard</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link<?= $section === 'editLinks' ? '' : ' collapsed'; ?>" href="index.php?page=editLinks">
          <i class="bi bi-pencil-square"></i>
          <span>Edit Links</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link<?= $section === 'settings' ? '' : ' collapsed'; ?>" href="index.php?page=settings">
          <i class="bi bi-gear"></i>
          <span>Settings</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link<?= $section === 'info' ? '' : ' collapsed'; ?>" href="index.php?page=info">
          <i class="bi bi-info-circle"></i>
          <span>Info</span>
        </a>
      </li>
    </ul>
  </aside>
  <main id="main" class="main">
    <div class="pagetitle">
      <h1>
        <?php
          if ($section === 'dashboard') {
              echo "Dashboard";
          } elseif ($section === 'editLinks') {
              echo "Edit Links";
          } elseif ($section === 'settings') {
              echo "Settings";
          } elseif ($section === 'info') {
              echo "Info & Release Notes";
          }
        ?>
      </h1>
    </div>
    <section class="section">
      <div class="container-fluid">
        <?php if ($flash = getFlashMessage()): ?>
          <div class="alert alert-info" role="alert">
            <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php endif; ?>
        <?php if ($section === 'dashboard'): ?>
          <div class="row">
            <?php if (!isset($paginatedLinks)) { $paginatedLinks = []; } ?>
            <?php foreach ($paginatedLinks as $link): ?>
              <div class="col-lg-3 col-md-4 col-sm-6 mb-4">
                <div class="card h-100">
                  <div class="card-body">
                    <?php if (!empty($link['icon']) && file_exists($linkbilderDir . '/' . $link['icon'])): ?>
                      <img src="linkbilder/<?= htmlspecialchars($link['icon']); ?>" alt="Icon" class="icon-img">
                    <?php endif; ?>
                    <h5 class="card-title text-truncate" title="<?= htmlspecialchars($link['title']); ?>">
                      <?= htmlspecialchars($link['title']); ?>
                    </h5>
                    <p>
                      <a href="<?= htmlspecialchars($link['url']); ?>" target="_blank">
                        <?= htmlspecialchars($link['url']); ?>
                      </a>
                    </p>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <nav>
            <ul class="pagination justify-content-center">
              <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item<?= $i === $currentP ? ' active' : ''; ?>">
                  <a class="page-link" href="?page=dashboard&p=<?= $i; ?>">
                    <?= $i; ?>
                  </a>
                </li>
              <?php endfor; ?>
            </ul>
          </nav>
        <?php elseif ($section === 'editLinks'): ?>
          <form method="post" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
            <div class="card mb-3">
              <div class="card-body">
                <h5 class="card-title">Neuen Link hinzufügen</h5>
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                  <label for="title" class="form-label">Titel</label>
                  <input type="text" id="title" name="title" class="form-control" required>
                </div>
                <div class="mb-3">
                  <label for="url" class="form-label">URL</label>
                  <input type="url" id="url" name="url" class="form-control" required>
                </div>
                <div class="mb-3">
                  <label for="icon" class="form-label">Icon (optional)</label>
                  <input type="file" id="icon" name="icon" class="form-control">
                </div>
                <button type="submit" class="btn btn-primary">Hinzufügen</button>
              </div>
            </div>
          </form>
          <form method="post" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
            <div class="d-flex justify-content-between mb-3">
              <button type="submit" name="action" value="export" class="btn btn-success">Links exportieren</button>
              <div>
                <input type="file" name="importFile" class="form-control d-inline" style="width: auto;">
                <button type="submit" name="action" value="import" class="btn btn-secondary">Links importieren</button>
              </div>
            </div>
          </form>
          <div class="mb-3">
            <form method="post" action="" style="display:inline-block;">
              <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
              <input type="hidden" name="action" value="exportImages">
              <button type="submit" class="btn btn-info">Linkbilder exportieren (ZIP)</button>
            </form>
            <form method="post" action="" enctype="multipart/form-data" style="display:inline-block;">
              <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
              <input type="hidden" name="action" value="importImages">
              <input type="file" name="imagesZip" class="form-control d-inline" style="width: auto;">
              <button type="submit" class="btn btn-secondary">Linkbilder importieren (ZIP)</button>
            </form>
          </div>
          <table class="table table-striped">
            <thead>
              <tr>
                <th>Titel</th>
                <th>URL</th>
                <th>Aktionen</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($links as $link): ?>
                <tr>
                  <td><?= htmlspecialchars($link['title']); ?></td>
                  <td><a href="<?= htmlspecialchars($link['url']); ?>" target="_blank"><?= htmlspecialchars($link['url']); ?></a></td>
                  <td>
                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?= $link['id']; ?>">Bearbeiten</button>
                    <form method="post" action="" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $link['id']; ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Löschen</button>
                    </form>
                  </td>
                </tr>
                <div class="modal fade" id="editModal<?= $link['id']; ?>" tabindex="-1" aria-labelledby="editModalLabel<?= $link['id']; ?>" aria-hidden="true">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <form method="post" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <div class="modal-header">
                          <h5 class="modal-title" id="editModalLabel<?= $link['id']; ?>">Link bearbeiten</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                          <input type="hidden" name="action" value="update">
                          <input type="hidden" name="id" value="<?= $link['id']; ?>">
                          <div class="mb-3">
                            <label for="title_<?= $link['id']; ?>" class="form-label">Titel</label>
                            <input type="text" id="title_<?= $link['id']; ?>" name="title" class="form-control" value="<?= htmlspecialchars($link['title']); ?>" required>
                          </div>
                          <div class="mb-3">
                            <label for="url_<?= $link['id']; ?>" class="form-label">URL</label>
                            <input type="url" id="url_<?= $link['id']; ?>" name="url" class="form-control" value="<?= htmlspecialchars($link['url']); ?>" required>
                          </div>
                          <div class="mb-3">
                            <label for="icon_<?= $link['id']; ?>" class="form-label">Neues Icon hochladen (optional)</label>
                            <input type="file" id="icon_<?= $link['id']; ?>" name="icon" class="form-control">
                          </div>
                          <?php if (!empty($link['icon']) && file_exists($linkbilderDir . '/' . $link['icon'])): ?>
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="deleteIcon" id="deleteIcon_<?= $link['id']; ?>" value="1">
                            <label class="form-check-label" for="deleteIcon_<?= $link['id']; ?>">
                              Vorhandenes Icon löschen
                            </label>
                          </div>
                          <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                          <button type="submit" class="btn btn-primary">Speichern</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php elseif ($section === 'settings'): ?>
          <form method="post" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="updateSettings">
            <div class="card mb-3">
              <div class="card-body">
                <h5 class="card-title">Globale Einstellungen</h5>
                <div class="mb-3">
                  <label for="siteHeading" class="form-label">Überschrift</label>
                  <input type="text" id="siteHeading" name="siteHeading" class="form-control" value="<?= htmlspecialchars($settings['siteHeading']); ?>">
                </div>
                <div class="mb-3">
                  <label for="primaryColor" class="form-label">Primärfarbe</label>
                  <input type="color" id="primaryColor" name="primaryColor" class="form-control" value="<?= htmlspecialchars($settings['primaryColor']); ?>">
                </div>
                <div class="mb-3">
                  <label for="secondaryColor" class="form-label">Sekundärfarbe</label>
                  <input type="color" id="secondaryColor" name="secondaryColor" class="form-control" value="<?= htmlspecialchars($settings['secondaryColor'] ?? '#ffffff'); ?>">
                </div>
                <div class="mb-3">
                  <label for="backgroundColor" class="form-label">Hintergrundfarbe</label>
                  <input type="color" id="backgroundColor" name="backgroundColor" class="form-control" value="<?= htmlspecialchars($settings['backgroundColor'] ?? '#f8f9fa'); ?>">
                </div>
                <div class="mb-3">
                  <label for="textColor" class="form-label">Textfarbe</label>
                  <input type="color" id="textColor" name="textColor" class="form-control" value="<?= htmlspecialchars($settings['textColor'] ?? '#333333'); ?>">
                </div>
                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
              </div>
            </div>
          </form>
          <!-- Separates Formular zum Zurücksetzen auf Standardwerte -->
          <form method="post" action="" style="display:inline-block; margin-bottom: 15px;">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
            <button type="submit" name="action" value="restoreDefaults" class="btn btn-dark">Standardwerte wiederherstellen</button>
          </form>
          <div class="mb-3">
            <form method="post" action="" enctype="multipart/form-data" style="display:inline-block;">
              <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
              <input type="hidden" name="action" value="exportSettings">
              <button type="submit" class="btn btn-success">Settings exportieren</button>
            </form>
            <form method="post" action="" enctype="multipart/form-data" style="display:inline-block;">
              <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
              <input type="hidden" name="action" value="importSettings">
              <input type="file" name="settingsFile" class="form-control d-inline" style="width: auto;">
              <button type="submit" class="btn btn-dark">Settings importieren</button>
            </form>
          </div>
        <?php elseif ($section === 'info'): ?>
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Release Notes</h5>
              <ul>
                <li><strong>Beta 0.1:</strong>
                  <ul>
                    <li>Grundlegende CRUD-Funktionalität für Links</li>
                    <li>Datenhaltung in einer JSON-Datei</li>
                    <li>Einfache Navigation über Dashboard und Edit Links</li>
                  </ul>
                </li>
                <li><strong>Beta 0.6:</strong>
                  <ul>
                    <li>Erweiterte Funktionen: Import/Export von Links und Einstellungen</li>
                    <li>Bild/Icon-Upload mit automatischem Resizing und Anzeige in den Links</li>
                    <li>Konfigurierbare globale Einstellungen (Farben, Überschrift)</li>
                    <li>Neuer Menüpunkt "Info" mit Release Notes</li>
                  </ul>
                </li>
              </ul>
              <p>Entwickler: <a href="https://opensource.org/" target="_blank">Markus Gmeinhart</a></p>
              <p>Lizenz: Dieses Projekt steht unter der <a href="https://opensource.org/licenses/MIT" target="_blank">MIT License</a>.</p>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>
  <div id="copyright">
    &copy; <?= htmlspecialchars($decodedName, ENT_QUOTES, 'UTF-8'); ?>
  </div>
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
