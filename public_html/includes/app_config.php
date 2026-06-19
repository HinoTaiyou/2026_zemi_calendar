<?php
declare(strict_types=1);

function appConfigPath(): string
{
    $testPath = $GLOBALS['app_config_path_for_test'] ?? null;
    if (is_string($testPath) && $testPath !== '') {
        return $testPath;
    }

    return dirname(__DIR__) . '/config.php';
}

function setAppConfigPathForTest(?string $path): void
{
    if ($path === null) {
        unset($GLOBALS['app_config_path_for_test']);
    } else {
        $GLOBALS['app_config_path_for_test'] = $path;
    }
}

function appConfigFileExists(): bool
{
    return is_file(appConfigPath());
}

function loadAppConfigFile(): array
{
    $path = appConfigPath();
    $cache = $GLOBALS['app_config_file_cache'] ?? [];

    if (array_key_exists($path, $cache)) {
        return is_array($cache[$path]) ? $cache[$path] : [];
    }

    $values = [];
    if (is_file($path)) {
        $returned = require $path;
        if (is_array($returned)) {
            $values = $returned;
        }
    }

    $cache[$path] = $values;
    $GLOBALS['app_config_file_cache'] = $cache;

    return $values;
}

function normalizeConfigValue(mixed $value, bool $trim = true): ?string
{
    if ($value === null) {
        return null;
    }

    $stringValue = (string) $value;
    $normalized = $trim ? trim($stringValue) : $stringValue;

    return $normalized === '' ? null : $normalized;
}

function appConfigValue(string $name, string $default = '', bool $trim = true): string
{
    $envValue = normalizeConfigValue(getenv($name), $trim);
    if ($envValue !== null) {
        return $envValue;
    }

    $fileValues = loadAppConfigFile();
    if (array_key_exists($name, $fileValues)) {
        $fileValue = normalizeConfigValue($fileValues[$name], $trim);
        if ($fileValue !== null) {
            return $fileValue;
        }
    }

    if (defined($name)) {
        $constantValue = normalizeConfigValue(constant($name), $trim);
        if ($constantValue !== null) {
            return $constantValue;
        }
    }

    return $default;
}

function appConfigSourceState(string $name, bool $trim = true): array
{
    $envValue = getenv($name);
    $envState = is_string($envValue)
        ? (normalizeConfigValue($envValue, $trim) === null ? '空文字' : '設定あり')
        : '環境変数なし';

    $fileValues = loadAppConfigFile();
    $fileState = '未定義';
    if (array_key_exists($name, $fileValues)) {
        $fileState = normalizeConfigValue($fileValues[$name], $trim) === null ? '空文字' : '設定あり';
    } elseif (defined($name)) {
        $fileState = normalizeConfigValue(constant($name), $trim) === null ? '空文字' : '設定あり';
    }

    return [
        'env' => $envState,
        'local' => $fileState,
        'effective' => appConfigValue($name, '', $trim) === '' ? '未設定' : '設定済み',
    ];
}
