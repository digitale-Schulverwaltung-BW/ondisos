<?php
// public/restore.php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

use App\Repositories\AnmeldungRepository;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: trash.php');
    exit;
}

$repository = new AnmeldungRepository();

try {
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        throw new InvalidArgumentException('Ungültige ID');
    }

    $success = $repository->restore($id);
    
    if ($success) {
        header('Location: trash.php?restored=1&id=' . $id);
    } else {
        header('Location: trash.php?error=not_found');
    }
    exit;

} catch (Throwable $e) {
    error_log('Restore error: ' . $e->getMessage());
    header('Location: trash.php?error=failed');
    exit;
}