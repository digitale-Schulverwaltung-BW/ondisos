<?php
// public/tenants.php
// Platform-admin-only UI: tenant list, create, edit, admin management.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

use App\Repositories\TenantRepository;
use App\Repositories\TenantAdminRepository;

// ---------------------------------------------------------------------------
// Platform-admin guard
// ---------------------------------------------------------------------------
if (empty($_SESSION['is_platform_admin'])) {
    http_response_code(403);
    die('Zugriff nur für Plattform-Admins.');
}

$tenantRepo = new TenantRepository();
$adminRepo  = new TenantAdminRepository();

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_POST['action'] ?? null;

// ---------------------------------------------------------------------------
// Flash helper — read-and-clear at render time
// ---------------------------------------------------------------------------
$flash = [];
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ---------------------------------------------------------------------------
// POST handlers (PRG pattern — all redirect on success)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF validation for all POST requests
    csrf_validate();

    // ------------------------------------------------------------------
    // 1. Create tenant
    // ------------------------------------------------------------------
    if ($action === 'create_tenant') {
        $name   = trim($_POST['name']   ?? '');
        $slug   = trim($_POST['slug']   ?? '');
        $origin = trim($_POST['origin'] ?? '');

        $createError = null;
        if ($name === '') {
            $createError = 'Name ist erforderlich.';
        } elseif (strlen($name) > 255) {
            $createError = 'Name darf maximal 255 Zeichen haben.';
        } elseif ($slug === '') {
            $createError = 'Slug ist erforderlich.';
        } elseif (strlen($slug) > 100) {
            $createError = 'Slug darf maximal 100 Zeichen haben.';
        } elseif (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $createError = 'Slug darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.';
        }

        if ($createError === null) {
            $apiSecret = bin2hex(openssl_random_pseudo_bytes(32));
            try {
                $newId = $tenantRepo->create([
                    'name'       => $name,
                    'slug'       => $slug,
                    'origin'     => $origin !== '' ? $origin : null,
                    'api_secret' => $apiSecret,
                ]);
                $_SESSION['flash'] = ['new_api_secret' => $apiSecret];
                header('Location: tenants.php?id=' . $newId . '&created=1');
                exit;
            } catch (\InvalidArgumentException $e) {
                $createError = 'Slug ist bereits vergeben. Bitte einen anderen Slug wählen.';
            }
        }

        // Fall through to render list view with $createError set
        // (no redirect — show error inline)
    }

    // ------------------------------------------------------------------
    // 2. Update tenant (name, origin, active)
    // ------------------------------------------------------------------
    elseif ($action === 'update_tenant' && $id !== null) {
        $name   = trim($_POST['name']   ?? '');
        $origin = trim($_POST['origin'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;

        $tenantRepo->update($id, [
            'name'   => $name,
            'origin' => $origin !== '' ? $origin : null,
            'active' => $active,
        ]);
        header('Location: tenants.php?id=' . $id . '&saved=1');
        exit;
    }

    // ------------------------------------------------------------------
    // 3. Regenerate API secret — MUST use updateApiSecret(), NOT update()
    // ------------------------------------------------------------------
    elseif ($action === 'regenerate_secret' && $id !== null) {
        $newSecret = bin2hex(openssl_random_pseudo_bytes(32));
        $tenantRepo->updateApiSecret($id, $newSecret);
        $_SESSION['flash'] = ['new_api_secret' => $newSecret];
        header('Location: tenants.php?id=' . $id . '&secret_regenerated=1');
        exit;
    }

    // ------------------------------------------------------------------
    // 4. Create admin for tenant
    // ------------------------------------------------------------------
    elseif ($action === 'create_admin' && $id !== null) {
        $username  = trim($_POST['username'] ?? '');
        $plainPass = $_POST['password'] ?? '';

        $adminError = null;
        if ($username === '') {
            $adminError = 'Benutzername ist erforderlich.';
        } elseif ($plainPass === '') {
            $adminError = 'Passwort ist erforderlich.';
        }

        if ($adminError === null) {
            $hash = password_hash($plainPass, PASSWORD_BCRYPT);
            try {
                $adminRepo->create([
                    'tenant_id'     => $id,
                    'username'      => $username,
                    'password_hash' => $hash,
                ]);
                $_SESSION['flash'] = [
                    'new_password'   => $plainPass,
                    'admin_username' => $username,
                ];
                header('Location: tenants.php?id=' . $id . '&admin_created=1');
                exit;
            } catch (\InvalidArgumentException $e) {
                $adminError = 'Benutzername existiert bereits (global eindeutig). Bitte einen anderen wählen.';
            }
        }

        // Fall through to render edit view with $adminError set
    }

    // ------------------------------------------------------------------
    // 5. Toggle admin active/inactive
    // ------------------------------------------------------------------
    elseif ($action === 'toggle_admin' && $id !== null) {
        $adminId = (int)($_POST['admin_id'] ?? 0);
        $active  = (bool)(int)($_POST['active'] ?? 0);
        $adminRepo->toggleActive($adminId, $active);
        header('Location: tenants.php?id=' . $id);
        exit;
    }

    // ------------------------------------------------------------------
    // 6. Reset admin password
    // ------------------------------------------------------------------
    elseif ($action === 'reset_password' && $id !== null) {
        $adminId   = (int)($_POST['admin_id'] ?? 0);
        $plainPass = bin2hex(random_bytes(8)); // generate a secure random password
        $hash      = password_hash($plainPass, PASSWORD_BCRYPT);
        $adminRepo->resetPassword($adminId, $hash);
        $_SESSION['flash'] = ['new_password' => $plainPass];
        header('Location: tenants.php?id=' . $id . '&password_reset=1');
        exit;
    }
}

// ---------------------------------------------------------------------------
// Render HTML
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../inc/header.php';
?>

<div class="container mt-4">

<?php
// ===========================================================================
// EDIT VIEW (when $id is set)
// ===========================================================================
if ($id !== null && $id > 0):
    $tenant = $tenantRepo->findById($id);
    if ($tenant === null) {
        http_response_code(404);
        echo '<div class="alert alert-danger">Tenant nicht gefunden.</div>';
        echo '<a href="tenants.php" class="btn btn-secondary">← Zurück zur Übersicht</a>';
        require_once __DIR__ . '/../inc/footer.php';
        exit;
    }

    $admins     = $adminRepo->findByTenantId($id);
    $savedOk    = !empty($_GET['saved']);
    $createdOk  = !empty($_GET['created']);
    $secretRegen = !empty($_GET['secret_regenerated']);
    $adminCreated = !empty($_GET['admin_created']);
    $pwdReset   = !empty($_GET['password_reset']);
?>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="tenants.php">Tenants</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($tenant['name']) ?></li>
        </ol>
    </nav>

    <h1 class="mb-4">Tenant bearbeiten: <?= htmlspecialchars($tenant['name']) ?></h1>

    <?php if ($createdOk): ?>
        <div class="alert alert-success">Tenant wurde erfolgreich erstellt.</div>
    <?php endif; ?>
    <?php if ($savedOk): ?>
        <div class="alert alert-success">Änderungen wurden gespeichert.</div>
    <?php endif; ?>
    <?php if ($secretRegen): ?>
        <div class="alert alert-success">API-Schlüssel wurde erneuert.</div>
    <?php endif; ?>
    <?php if ($adminCreated): ?>
        <div class="alert alert-success">Admin wurde erfolgreich angelegt.</div>
    <?php endif; ?>
    <?php if ($pwdReset): ?>
        <div class="alert alert-success">Passwort wurde zurückgesetzt.</div>
    <?php endif; ?>

    <?php if (!empty($flash['new_api_secret'])): ?>
        <div class="alert alert-warning">
            <strong>API-Schlüssel — jetzt sichern! Wird nur einmal angezeigt.</strong>
            <div class="mt-2">
                <code class="user-select-all fs-6"><?= htmlspecialchars($flash['new_api_secret']) ?></code>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($flash['new_password'])): ?>
        <div class="alert alert-warning">
            <?php if (!empty($flash['admin_username'])): ?>
                <strong>Neuer Admin: <?= htmlspecialchars($flash['admin_username']) ?></strong><br>
            <?php endif; ?>
            <strong>Passwort — jetzt sichern! Wird nur einmal angezeigt.</strong>
            <div class="mt-2">
                <code class="user-select-all fs-6"><?= htmlspecialchars($flash['new_password']) ?></code>
            </div>
        </div>
    <?php endif; ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- Tenant edit form                                                      -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Tenant-Daten</h5></div>
        <div class="card-body">
            <form method="POST" action="tenants.php?id=<?= $id ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="update_tenant">

                <div class="mb-3">
                    <label for="edit_name" class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" id="edit_name" name="name"
                           class="form-control" maxlength="255" required
                           value="<?= htmlspecialchars($tenant['name']) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Slug</label>
                    <input type="text" class="form-control" readonly
                           value="<?= htmlspecialchars($tenant['slug']) ?>">
                    <div class="form-text">Der Slug kann nach der Erstellung nicht mehr geändert werden.</div>
                </div>

                <div class="mb-3">
                    <label for="edit_origin" class="form-label">CORS Origin</label>
                    <input type="text" id="edit_origin" name="origin"
                           class="form-control" maxlength="255"
                           placeholder="https://anmeldung.example.com"
                           value="<?= htmlspecialchars($tenant['origin'] ?? '') ?>">
                    <div class="form-text">Optional. Erlaubte Origin für CORS-Anfragen.</div>
                </div>

                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" id="edit_active" name="active"
                               class="form-check-input" value="1"
                               <?= $tenant['active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="edit_active">Tenant aktiv</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Speichern</button>
                <a href="tenants.php" class="btn btn-secondary ms-2">← Zurück zur Übersicht</a>
            </form>
        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- API Secret                                                            -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">API-Schlüssel</h5></div>
        <div class="card-body">
            <p class="mb-2">
                Aktueller Schlüssel: <code>***</code>
                <span class="text-muted">(aus Sicherheitsgründen verborgen)</span>
            </p>
            <form method="POST" action="tenants.php?id=<?= $id ?>"
                  onsubmit="return confirm('API-Schlüssel wirklich erneuern? Der alte Schlüssel wird sofort ungültig.')">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="regenerate_secret">
                <button type="submit" class="btn btn-warning">API-Schlüssel erneuern</button>
            </form>
        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Tenant admin list                                                     -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Administratoren</h5></div>
        <div class="card-body">

            <?php if (!empty($adminError ?? null)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($adminError) ?></div>
            <?php endif; ?>

            <?php if (empty($admins)): ?>
                <p class="text-muted">Noch keine Administratoren vorhanden.</p>
            <?php else: ?>
                <table class="table table-bordered table-sm table-hover mb-4">
                    <thead class="table-light">
                        <tr>
                            <th>Benutzername</th>
                            <th>Status</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td><?= htmlspecialchars($admin['username']) ?></td>
                                <td>
                                    <?php if ($admin['active']): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- Toggle active/inactive -->
                                    <form method="POST" action="tenants.php?id=<?= $id ?>" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_admin">
                                        <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                        <input type="hidden" name="active" value="<?= $admin['active'] ? 0 : 1 ?>">
                                        <button type="submit" class="btn btn-sm <?= $admin['active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                                            <?= $admin['active'] ? 'Deaktivieren' : 'Aktivieren' ?>
                                        </button>
                                    </form>
                                    <!-- Reset password -->
                                    <form method="POST" action="tenants.php?id=<?= $id ?>" class="d-inline"
                                          onsubmit="return confirm('Passwort für <?= htmlspecialchars(addslashes($admin['username'])) ?> zurücksetzen?')">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning">
                                            Passwort zurücksetzen
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Add admin form -->
            <h6 class="mt-3">Admin hinzufügen</h6>
            <form method="POST" action="tenants.php?id=<?= $id ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_admin">
                <div class="row g-2">
                    <div class="col-sm-5">
                        <input type="text" name="username" class="form-control"
                               placeholder="Benutzername" required maxlength="100"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="col-sm-5">
                        <input type="password" name="password" class="form-control"
                               placeholder="Passwort" required minlength="8">
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary w-100">Hinzufügen</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php
// ===========================================================================
// LIST VIEW (no $id set) + CREATE FORM
// ===========================================================================
else:
    $tenants = $tenantRepo->findAllForAdmin();
?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Tenants</h1>
    </div>

    <?php if (!empty($createError ?? null)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($createError) ?></div>
    <?php endif; ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- Tenant list table                                                     -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Alle Tenants</h5></div>
        <div class="card-body p-0">
            <?php if (empty($tenants)): ?>
                <p class="p-3 text-muted mb-0">Noch keine Tenants vorhanden.</p>
            <?php else: ?>
                <table class="table table-bordered table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenants as $t): ?>
                            <tr>
                                <td>
                                    <a href="tenants.php?id=<?= (int)$t['id'] ?>">
                                        <?= htmlspecialchars($t['name']) ?>
                                    </a>
                                </td>
                                <td><code><?= htmlspecialchars($t['slug']) ?></code></td>
                                <td>
                                    <?php if ($t['active']): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Create tenant form                                                    -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Neuen Tenant erstellen</h5></div>
        <div class="card-body">
            <form method="POST" action="tenants.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_tenant">

                <div class="mb-3">
                    <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" id="name" name="name"
                           class="form-control" maxlength="255" required
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label for="slug" class="form-label">Slug <span class="text-danger">*</span></label>
                    <input type="text" id="slug" name="slug"
                           class="form-control" maxlength="100" required
                           pattern="[a-z0-9-]+" title="Nur Kleinbuchstaben, Ziffern und Bindestriche"
                           value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>">
                    <div class="form-text">Wird automatisch aus dem Namen generiert. Nur a–z, 0–9, Bindestrich.</div>
                </div>

                <div class="mb-3">
                    <label for="origin" class="form-label">CORS Origin</label>
                    <input type="text" id="origin" name="origin"
                           class="form-control" maxlength="255"
                           placeholder="https://anmeldung.example.com"
                           value="<?= htmlspecialchars($_POST['origin'] ?? '') ?>">
                    <div class="form-text">Optional. Erlaubte Origin für CORS-Anfragen.</div>
                </div>

                <button type="submit" class="btn btn-primary">Tenant erstellen</button>
            </form>
        </div>
    </div>

    <script>
    document.getElementById('name').addEventListener('input', function () {
        const slug = this.value
            .toLowerCase()
            .replace(/\s+/g, '-')
            .replace(/[^a-z0-9-]/g, '');
        document.getElementById('slug').value = slug;
    });
    </script>

<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
