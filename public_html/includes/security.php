<?php
declare(strict_types=1);

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8')
        . '">';
}

function isValidCsrfPost(): bool
{
    $postedToken = $_POST['csrf_token'] ?? null;
    if (!is_string($postedToken) || $postedToken === '') {
        return false;
    }

    return hash_equals(csrfToken(), $postedToken);
}

function csrfErrorMessage(): string
{
    return 'セッションの確認に失敗しました。ページを再読み込みして、もう一度操作してください。';
}
