<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/db.php';

function startAppSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

function isLoggedIn(): bool {
    startAppSession();
    return isset($_SESSION['user_id']);
}

function currentUser(): ?array {
    startAppSession();
    if (!isset($_SESSION['user_id'])) return null;
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/auth/login');
        exit;
    }
}

function requireRole(string $role): void {
    requireLogin();
    $user = currentUser();
    if (!$user || $user['role'] !== $role) {
        header('Location: ' . APP_URL . '/');
        exit;
    }
}

function refreshUser(): void {
    startAppSession();
    if (!isset($_SESSION['user_id'])) return;
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['user'] = $user;
    }
}

function generateCsrfToken(): string {
    startAppSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    startAppSession();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
