<?php
// public/hard_delete.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

use App\Repositories\AnmeldungRepository;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: trash.php');
    exit;
}

$repository = new AnmeldungRepository();

try {
    // Validate CSRF token
    csrf_validate();

    $id = (int)($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        throw new InvalidArgumentException('Ungültige ID');
    }

    $success = $repository->hardDelete($id);
    
    if ($success) {
        error_log("Hard delete: Entry #$id permanently deleted");
        header('Location: trash.php?hard_deleted=1&id=' . $id);
    } else {
        header('Location: trash.php?error=not_found');
    }
    exit;

} catch (Throwable $e) {
    error_log('Hard delete error: ' . $e->getMessage());
    header('Location: trash.php?error=failed');
    exit;
}