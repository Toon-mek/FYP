<?php
declare(strict_types=1);

/**
 * Store a profile image supplied as a base64 data URI and return the relative path.
 *
 * @throws InvalidArgumentException
 * @throws RuntimeException
 */
function saveProfileImageFromData(string $accountType, int $accountId, string $dataUri, ?string $previousPath = null): string
{
    $normalised = trim((string)$dataUri);
    if ($normalised === '') {
        throw new InvalidArgumentException('Profile image data is empty.');
    }

    if (!preg_match('#^data:(image/(png|jpeg|jpg|webp));base64,(.+)$#i', $normalised, $matches)) {
        throw new InvalidArgumentException('Unsupported profile image format.');
    }

    $mime = strtolower($matches[1]);
    $extension = resolveImageExtension($matches[2] ?? '');
    $rawBase64 = $matches[3] ?? '';

    $binary = base64_decode(str_replace(' ', '+', $rawBase64), true);
    if ($binary === false) {
        throw new InvalidArgumentException('Invalid profile image payload.');
    }

    $sizeLimit = 4 * 1024 * 1024; // 4MB
    if (strlen($binary) > $sizeLimit) {
        throw new InvalidArgumentException('Profile image exceeds the 4MB size limit.');
    }

    $directoryName = profileImageDirectoryName($accountType);
    $baseDirectory = profileImageBaseDirectory();
    $targetDirectory = $baseDirectory . DIRECTORY_SEPARATOR . $directoryName;

    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
        throw new RuntimeException('Unable to create profile image directory.');
    }

    $filename = buildProfileImageFilename($directoryName, $accountId, $extension);
    $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;

    if (file_put_contents($targetPath, $binary) === false) {
        throw new RuntimeException('Failed to write profile image to disk.');
    }

    if ($previousPath) {
        deleteProfileImage($previousPath);
    }

    return $directoryName . '/' . $filename;
}

/**
 * Remove an existing profile image from disk.
 */
function deleteProfileImage(string $relativePath): void
{
    $relative = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relative === '') {
        return;
    }

    if (!preg_match('#^(travelerProfilePic|businessoperatorProfilePic|adminProfilePic)/#', $relative)) {
        return;
    }

    $fullPath = profileImageBaseDirectory() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/**
 * Provide the absolute base directory where profile images reside.
 */
function profileImageBaseDirectory(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public_assets';
}

/**
 * Map account types to their dedicated profile image folders.
 */
function profileImageDirectoryName(string $accountType): string
{
    switch (strtolower($accountType)) {
        case 'traveler':
            return 'travelerProfilePic';
        case 'operator':
        case 'business':
        case 'tourismoperator':
            return 'businessoperatorProfilePic';
        case 'admin':
        case 'administrator':
            return 'adminProfilePic';
        default:
            throw new InvalidArgumentException(sprintf('Unsupported account type "%s" for profile images.', $accountType));
    }
}

/**
 * Build a deterministic but unique file name for the stored profile image.
 */
function buildProfileImageFilename(string $directory, int $accountId, string $extension): string
{
    $accountId = max(0, $accountId);
    $suffix = '';
    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        try {
            $suffix = (string)random_int(100000, 999999);
        } catch (Throwable $nested) {
            $suffix = (string)mt_rand(100000, 999999);
        }
    }

    $safeExtension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'png';
    return sprintf('%s_%d_%s.%s', strtolower($directory), $accountId, $suffix, strtolower($safeExtension));
}

/**
 * Normalise the file extension based on the data URI subtype.
 */
function resolveImageExtension(string $subType): string
{
    $normalised = strtolower($subType);
    return match ($normalised) {
        'jpeg', 'jpg' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
        default => 'png',
    };
}

/**
 * Convert a relative profile image path into a publicly accessible URL.
 */
function profileImagePublicUrl(?string $relativePath): string
{
    $relative = trim((string)$relativePath);
    if ($relative === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $relative) || str_starts_with($relative, 'data:')) {
        return $relative;
    }

    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '') {
        return '';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = '';
    if ($scriptName !== '' && $scriptName !== '.') {
        $basePath = preg_replace('#/api(?:/.*)?$#', '', $scriptName);
        $basePath = trim($basePath ?? '', '/');
    }
    $prefix = $basePath === '' ? '' : '/' . $basePath;

    return sprintf('%s://%s%s/public_assets/%s', $scheme, $host, $prefix, $relative);
}
