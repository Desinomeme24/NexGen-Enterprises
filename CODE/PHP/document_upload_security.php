<?php

final class NxDocumentUploadRejected extends RuntimeException
{
}

final class NxDocumentUploadUnavailable extends RuntimeException
{
}

function nxDocumentUploadSetPrivatePermissions(string $path, int $mode): void
{
    if (DIRECTORY_SEPARATOR !== '\\' && !chmod($path, $mode)) {
        throw new NxDocumentUploadUnavailable('Private upload permissions could not be applied.');
    }
}

function nxDocumentUploadRoot(): string
{
    $configured = trim((string)(getenv('NEXGEN_PRIVATE_UPLOAD_DIR') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, "\\/");
    }
    if (strtolower((string)(getenv('NEXGEN_ENV') ?: '')) === 'production') {
        throw new NxDocumentUploadUnavailable('Private upload storage is not configured.');
    }

    return dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'nexgen_private';
}

function nxDocumentUploadDirectories(): array
{
    $root = nxDocumentUploadRoot();
    $quarantine = trim((string)(getenv('NEXGEN_UPLOAD_QUARANTINE_DIR') ?: ''));
    if ($quarantine === '') {
        $quarantine = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexgen-upload-quarantine';
    }
    $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));

    foreach ([$root, $quarantine, $root . DIRECTORY_SEPARATOR . 'valid_ids', $root . DIRECTORY_SEPARATOR . 'business_permits'] as $directory) {
        if (is_link($directory)) {
            throw new NxDocumentUploadUnavailable('Private upload storage cannot contain symbolic links.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new NxDocumentUploadUnavailable('Private upload storage is unavailable.');
        }
        nxDocumentUploadSetPrivatePermissions($directory, 0700);
    }

    if ($documentRoot !== '') {
        $realDocumentRoot = realpath($documentRoot);
        $candidate = realpath($root);
        $quarantineCandidate = realpath($quarantine);
        if ($candidate === false || $quarantineCandidate === false || $realDocumentRoot === false) {
            throw new NxDocumentUploadUnavailable('Private upload storage could not be resolved.');
        }
        $prefix = rtrim($realDocumentRoot, "\\/") . DIRECTORY_SEPARATOR;
        $insideDocumentRoot = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($candidate . DIRECTORY_SEPARATOR, $prefix, strlen($prefix)) === 0
            : str_starts_with($candidate . DIRECTORY_SEPARATOR, $prefix);
        $quarantineInsideDocumentRoot = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($quarantineCandidate . DIRECTORY_SEPARATOR, $prefix, strlen($prefix)) === 0
            : str_starts_with($quarantineCandidate . DIRECTORY_SEPARATOR, $prefix);
        if ($insideDocumentRoot || $quarantineInsideDocumentRoot) {
            throw new NxDocumentUploadUnavailable('Private upload storage must be outside the document root.');
        }
    }

    return [
        'root' => $root,
        'quarantine' => $quarantine,
        'valid_ids' => $root . DIRECTORY_SEPARATOR . 'valid_ids',
        'business_permits' => $root . DIRECTORY_SEPARATOR . 'business_permits',
    ];
}

function nxDocumentUploadUuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function nxDocumentUploadLog(string $event, array $context = []): void
{
    $record = [
        'event' => $event,
        'at' => gmdate('c'),
        'user_id' => isset($context['user_id']) ? (int)$context['user_id'] : null,
        'role' => isset($context['role']) ? (string)$context['role'] : null,
        'request_id' => isset($context['request_id']) ? (int)$context['request_id'] : null,
        'slot' => isset($context['slot']) ? (string)$context['slot'] : null,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
    ];
    error_log('NexGen document upload: ' . json_encode($record, JSON_UNESCAPED_SLASHES));
}

function nxDocumentUploadSlotRules(string $slot): array
{
    $rules = [
        'valid_id' => [
            'directory' => 'valid_ids',
            'prefix' => 'private-valid-id:',
            'extensions' => [
                'jpg' => ['image/jpeg', 5 * 1024 * 1024],
                'jpeg' => ['image/jpeg', 5 * 1024 * 1024],
                'png' => ['image/png', 5 * 1024 * 1024],
                'heic' => ['image/heic', 10 * 1024 * 1024],
                'heif' => ['image/heif', 10 * 1024 * 1024],
            ],
            'worker' => 'image',
        ],
        'business_permit' => [
            'directory' => 'business_permits',
            'prefix' => 'private-business-permit:',
            'extensions' => [
                'jpg' => ['image/jpeg', 5 * 1024 * 1024],
                'jpeg' => ['image/jpeg', 5 * 1024 * 1024],
                'png' => ['image/png', 5 * 1024 * 1024],
                'heic' => ['image/heic', 10 * 1024 * 1024],
                'heif' => ['image/heif', 10 * 1024 * 1024],
                'pdf' => ['application/pdf', 10 * 1024 * 1024],
            ],
            'worker' => 'document',
        ],
    ];

    if (!isset($rules[$slot])) {
        throw new NxDocumentUploadRejected('Unsupported document slot.');
    }

    return $rules[$slot];
}

function nxDocumentUploadFileNameExtension(string $name): ?string
{
    if (
        $name === ''
        || str_contains($name, "\0")
        || str_contains($name, '/')
        || str_contains($name, '\\')
        || preg_match('/[\x00-\x1F\x7F]/', $name)
        || substr_count($name, '.') !== 1
    ) {
        return null;
    }

    $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    return preg_match('/^(?:jpe?g|png|heic|heif|pdf)$/', $extension) ? $extension : null;
}

function nxDocumentUploadMagicMatches(string $path, string $extension): bool
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }

    $header = fread($handle, 12);
    fclose($handle);

    if (in_array($extension, ['jpg', 'jpeg'], true)) {
        return is_string($header) && str_starts_with($header, "\xFF\xD8\xFF");
    }
    if ($extension === 'png') {
        return is_string($header) && str_starts_with($header, "\x89PNG\r\n\x1A\n");
    }
    if ($extension === 'pdf') {
        return is_string($header) && str_starts_with($header, '%PDF');
    }
    if (in_array($extension, ['heic', 'heif'], true)) {
        if (!is_string($header) || strlen($header) < 12 || substr($header, 4, 4) !== 'ftyp') {
            return false;
        }
        return in_array(substr($header, 8, 4), [
            'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1',
        ], true);
    }

    return false;
}

function nxDocumentUploadDetectMime(string $path): string
{
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) {
        throw new NxDocumentUploadUnavailable('Upload MIME detection is unavailable.');
    }

    $mime = finfo_file($fileInfo, $path);
    finfo_close($fileInfo);

    if (!is_string($mime) || $mime === '') {
        throw new NxDocumentUploadRejected('Upload MIME detection failed.');
    }

    return $mime;
}

function nxDocumentUploadValidateBag(array $files, array $expectedFields): void
{
    $actualFields = array_keys($files);
    sort($actualFields);
    sort($expectedFields);
    if ($actualFields !== $expectedFields) {
        throw new NxDocumentUploadRejected('Unexpected upload field set.');
    }

    foreach ($files as $file) {
        if (!is_array($file)) {
            throw new NxDocumentUploadRejected('Invalid upload structure.');
        }
        $allowedKeys = ['error', 'full_path', 'name', 'size', 'tmp_name', 'type'];
        if (array_diff(array_keys($file), $allowedKeys) !== []) {
            throw new NxDocumentUploadRejected('Unexpected upload metadata.');
        }
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
            if (!array_key_exists($key, $file) || is_array($file[$key]) || is_object($file[$key])) {
                throw new NxDocumentUploadRejected('Nested or multiple file uploads are not allowed.');
            }
        }
        if (
            array_key_exists('full_path', $file)
            && (
                !is_string($file['full_path'])
                || $file['full_path'] !== $file['name']
                || str_contains($file['full_path'], '/')
                || str_contains($file['full_path'], '\\')
            )
        ) {
            throw new NxDocumentUploadRejected('Folder uploads are not allowed.');
        }
    }
}

function nxDocumentUploadIsMissing(array $file): bool
{
    return isset($file['error']) && (int)$file['error'] === UPLOAD_ERR_NO_FILE;
}

function nxDocumentUploadScanWithClamAv(string $path): void
{
    $host = trim((string)(getenv('CLAMAV_HOST') ?: ''));
    $port = filter_var(getenv('CLAMAV_PORT') ?: '3310', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    $timeout = filter_var(getenv('CLAMAV_TIMEOUT_SECONDS') ?: '8', FILTER_VALIDATE_FLOAT);
    if ($host === '' || $port === false || $timeout === false || $timeout <= 0 || $timeout > 30) {
        throw new NxDocumentUploadUnavailable('Malware scanning is not configured.');
    }

    $deadline = microtime(true) + (float)$timeout;
    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errorNumber,
        $errorMessage,
        (float)$timeout,
        STREAM_CLIENT_CONNECT
    );
    if ($socket === false) {
        throw new NxDocumentUploadUnavailable('Malware scanning is unavailable.');
    }

    $setRemainingTimeout = static function ($stream) use ($deadline): bool {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return false;
        }
        $remainingSeconds = (int)$remaining;
        stream_set_timeout(
            $stream,
            $remainingSeconds,
            (int)(($remaining - $remainingSeconds) * 1000000)
        );
        return true;
    };
    $writeAll = static function ($stream, string $data) use ($deadline, $setRemainingTimeout): bool {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            if (microtime(true) >= $deadline || !$setRemainingTimeout($stream)) {
                return false;
            }
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
        }
        return true;
    };

    try {
        if (!$writeAll($socket, "nINSTREAM\n")) {
            throw new NxDocumentUploadUnavailable('Malware scanner protocol failure.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new NxDocumentUploadUnavailable('Quarantined upload could not be read.');
        }
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    throw new NxDocumentUploadUnavailable('Quarantined upload could not be scanned.');
                }
                if ($chunk === '') {
                    continue;
                }
                if (!$writeAll($socket, pack('N', strlen($chunk)) . $chunk)) {
                    throw new NxDocumentUploadUnavailable('Malware scanner protocol failure.');
                }
            }
        } finally {
            fclose($handle);
        }

        if (!$writeAll($socket, pack('N', 0))) {
            throw new NxDocumentUploadUnavailable('Malware scanner protocol failure.');
        }

        $response = '';
        while (!feof($socket) && strlen($response) < 4096) {
            if (!$setRemainingTimeout($socket)) {
                throw new NxDocumentUploadUnavailable('Malware scanner timed out.');
            }
            $line = fgets($socket, 4096);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (str_contains($line, "\n")) {
                break;
            }
        }
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out']) || $response === '') {
            throw new NxDocumentUploadUnavailable('Malware scanner timed out.');
        }
        if (preg_match('/\bFOUND\b/', $response)) {
            throw new NxDocumentUploadRejected('Malware detected.');
        }
        if (!preg_match('/\bOK\b/', $response) || preg_match('/\bERROR\b/', $response)) {
            throw new NxDocumentUploadUnavailable('Malware scanner returned an invalid response.');
        }
    } finally {
        fclose($socket);
    }
}

function nxDocumentUploadRunSanitizer(string $mode, string $inputPath, string $outputPath): void
{
    $python = trim((string)(getenv('NEXGEN_PYTHON_BIN') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3')));
    $worker = trim((string)(getenv('NEXGEN_UPLOAD_SANITIZER_PATH') ?: ''));
    if ($worker === '') {
        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'upload_sanitizer.py';
    }
    if (!is_file($worker)) {
        throw new NxDocumentUploadUnavailable('Document sanitizer worker is unavailable.');
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([$python, $worker, $mode, $inputPath, $outputPath], $descriptors, $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new NxDocumentUploadUnavailable('Document sanitizer could not start.');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $startedAt = microtime(true);
    $exitCode = null;
    $output = '';
    $errorOutput = '';

    while (true) {
        $output .= (string)stream_get_contents($pipes[1]);
        $errorOutput .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int)$status['exitcode'];
            break;
        }
        if (microtime(true) - $startedAt > 45) {
            proc_terminate($process, 9);
            foreach ([1, 2] as $index) {
                fclose($pipes[$index]);
            }
            proc_close($process);
            throw new NxDocumentUploadUnavailable('Document sanitizer timed out.');
        }
        usleep(10000);
    }

    $output .= (string)stream_get_contents($pipes[1]);
    $errorOutput .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedCode = proc_close($process);
    if ($exitCode === -1) {
        $exitCode = $closedCode;
    }
    if ($exitCode !== 0 || !is_file($outputPath) || filesize($outputPath) <= 0) {
        throw new NxDocumentUploadRejected('Document sanitization failed.');
    }
}

function nxStageSensitiveDocument(array $file, string $slot, array $context = []): array
{
    $rules = nxDocumentUploadSlotRules($slot);
    $context = array_merge($context, ['slot' => $slot]);
    foreach (['name', 'tmp_name', 'error', 'size'] as $field) {
        if (!isset($file[$field]) || is_array($file[$field]) || is_object($file[$field])) {
            throw new NxDocumentUploadRejected('Invalid upload structure.');
        }
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new NxDocumentUploadRejected('Upload was not received securely.');
    }

    $extension = nxDocumentUploadFileNameExtension((string)$file['name']);
    if ($extension === null || !isset($rules['extensions'][$extension])) {
        throw new NxDocumentUploadRejected('Document type is not allowed.');
    }

    $directories = nxDocumentUploadDirectories();
    $originalPath = $directories['quarantine'] . DIRECTORY_SEPARATOR
        . nxDocumentUploadUuidV4() . '.incoming';
    $sanitizedPath = $directories['quarantine'] . DIRECTORY_SEPARATOR
        . nxDocumentUploadUuidV4() . '.sanitized';

    if (!move_uploaded_file((string)$file['tmp_name'], $originalPath)) {
        throw new RuntimeException('Upload quarantine failed.');
    }
    $keepSanitized = false;

    try {
        nxDocumentUploadSetPrivatePermissions($originalPath, 0600);
        $size = filesize($originalPath);
        [$expectedMime, $maxBytes] = $rules['extensions'][$extension];
        if ($size === false || $size <= 0 || $size > $maxBytes) {
            throw new NxDocumentUploadRejected('Document size is not allowed.');
        }

        $detectedMime = nxDocumentUploadDetectMime($originalPath);
        if (
            $detectedMime !== $expectedMime
            || !nxDocumentUploadMagicMatches($originalPath, $extension)
        ) {
            throw new NxDocumentUploadRejected('Document type validation failed.');
        }

        try {
            nxDocumentUploadScanWithClamAv($originalPath);
            nxDocumentUploadLog('scan.clean', $context);
        } catch (NxDocumentUploadRejected $infected) {
            nxDocumentUploadLog('scan.infected', $context);
            throw $infected;
        } catch (NxDocumentUploadUnavailable $scannerError) {
            nxDocumentUploadLog('scan.unavailable', $context);
            throw $scannerError;
        }

        $mode = $extension === 'pdf' ? 'pdf' : 'image';
        nxDocumentUploadRunSanitizer($mode, $originalPath, $sanitizedPath);
        $outputExtension = in_array($extension, ['heic', 'heif', 'jpeg', 'jpg'], true)
            ? 'jpg'
            : $extension;
        $outputMime = $outputExtension === 'pdf'
            ? 'application/pdf'
            : ($outputExtension === 'png' ? 'image/png' : 'image/jpeg');
        $outputSize = filesize($sanitizedPath);
        $outputLimit = $outputExtension === 'pdf' ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
        if (
            $outputSize === false
            || $outputSize <= 0
            || $outputSize > $outputLimit
            || nxDocumentUploadDetectMime($sanitizedPath) !== $outputMime
            || !nxDocumentUploadMagicMatches($sanitizedPath, $outputExtension)
        ) {
            throw new NxDocumentUploadRejected('Sanitized document validation failed.');
        }

        nxDocumentUploadSetPrivatePermissions($sanitizedPath, 0600);
        $keepSanitized = true;
        return [
            'path' => $sanitizedPath,
            'extension' => $outputExtension,
            'mime' => $outputMime,
            'slot' => $slot,
            'context' => $context,
        ];
    } finally {
        if (is_file($originalPath) && !unlink($originalPath)) {
            nxDocumentUploadLog('quarantine.cleanup_failed', $context);
        }
        if (!$keepSanitized && is_file($sanitizedPath) && !unlink($sanitizedPath)) {
            nxDocumentUploadLog('quarantine.cleanup_failed', $context);
        }
    }
}

function nxDiscardStagedSensitiveDocument(?array $staged): void
{
    if ($staged !== null && isset($staged['path']) && is_file((string)$staged['path'])) {
        if (!unlink((string)$staged['path'])) {
            nxDocumentUploadLog('quarantine.cleanup_failed', array_merge(
                (array)($staged['context'] ?? []),
                ['slot' => (string)($staged['slot'] ?? 'unknown')]
            ));
        }
    }
}

function nxDocumentUploadMasterKey(): string
{
    $encoded = trim((string)(getenv('NEXGEN_UPLOAD_ENCRYPTION_KEY') ?: ''));
    $key = $encoded !== '' ? base64_decode($encoded, true) : false;
    if (
        !function_exists('sodium_crypto_secretbox')
        || !is_string($key)
        || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
    ) {
        throw new NxDocumentUploadUnavailable('Private document encryption is not configured.');
    }

    return $key;
}

function nxDocumentUploadWritePrivateFile(string $path, string $contents): void
{
    $handle = fopen($path, 'xb');
    if ($handle === false) {
        throw new NxDocumentUploadUnavailable('Private document storage write failed.');
    }
    try {
        nxDocumentUploadSetPrivatePermissions($path, 0600);
    } catch (Throwable $error) {
        fclose($handle);
        unlink($path);
        throw $error;
    }
    try {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new NxDocumentUploadUnavailable('Private document storage write failed.');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new NxDocumentUploadUnavailable('Private document storage flush failed.');
        }
    } finally {
        fclose($handle);
    }
}

function nxPersistSensitiveDocument(array $staged, string $slot): string
{
    $rules = nxDocumentUploadSlotRules($slot);
    if (($staged['slot'] ?? null) !== $slot || !is_file((string)($staged['path'] ?? ''))) {
        throw new NxDocumentUploadRejected('Sanitized document staging state is invalid.');
    }

    $directories = nxDocumentUploadDirectories();
    $extension = (string)$staged['extension'];
    $filename = nxDocumentUploadUuidV4() . '.' . $extension;
    $directory = $directories[$rules['directory']];
    $cipherPath = $directory . DIRECTORY_SEPARATOR . $filename;
    $keyPath = $cipherPath . '.key';
    $cipherTemp = $cipherPath . '.tmp';
    $keyTemp = $keyPath . '.tmp';
    $masterKey = nxDocumentUploadMasterKey();
    $documentKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $documentNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $keyNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $plain = file_get_contents((string)$staged['path']);
    if (!is_string($plain)) {
        throw new NxDocumentUploadUnavailable('Sanitized document could not be read.');
    }
    $ciphertext = "NXD1" . $documentNonce . sodium_crypto_secretbox($plain, $documentNonce, $documentKey);
    $wrappedKey = "NXK1" . $keyNonce . sodium_crypto_secretbox($documentKey, $keyNonce, $masterKey);
    sodium_memzero($plain);
    sodium_memzero($documentKey);
    sodium_memzero($masterKey);

    try {
        nxDocumentUploadWritePrivateFile($cipherTemp, $ciphertext);
        nxDocumentUploadWritePrivateFile($keyTemp, $wrappedKey);
        if (!rename($keyTemp, $keyPath) || !rename($cipherTemp, $cipherPath)) {
            throw new NxDocumentUploadUnavailable('Private document could not be committed.');
        }
        if (!unlink((string)$staged['path'])) {
            throw new NxDocumentUploadUnavailable('Sanitized staging file could not be removed.');
        }
    } catch (Throwable $error) {
        foreach ([$keyTemp, $keyPath, $cipherTemp, $cipherPath, (string)$staged['path']] as $cleanupPath) {
            if (is_file($cleanupPath) && !unlink($cleanupPath)) {
                error_log('NexGen private document write cleanup failed.');
            }
        }
        throw $error;
    }

    return $rules['prefix'] . $filename;
}

function nxDocumentUploadResolveReference(string $reference, string $slot): ?array
{
    $rules = nxDocumentUploadSlotRules($slot);
    if (!str_starts_with($reference, $rules['prefix'])) {
        return null;
    }

    $filename = substr($reference, strlen($rules['prefix']));
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\.(?:jpg|png|pdf)$/i', $filename)) {
        return null;
    }
    $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if (!isset($rules['extensions'][$extension])) {
        return null;
    }

    $directory = nxDocumentUploadDirectories()[$rules['directory']];
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    $realDirectory = realpath($directory);
    $realPath = realpath($path);
    if ($realDirectory === false || $realPath === false || !is_file($realPath) || is_link($path)) {
        return null;
    }
    $prefix = rtrim($realDirectory, "\\/") . DIRECTORY_SEPARATOR;
    $inside = DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($realPath, $prefix, strlen($prefix)) === 0
        : str_starts_with($realPath, $prefix);
    if (!$inside) {
        return null;
    }
    $keyPath = $realPath . '.key';
    if (is_link($keyPath)) {
        return null;
    }
    $realKeyPath = realpath($keyPath);
    if ($realKeyPath !== false) {
        $insideKeyDirectory = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($realKeyPath, $prefix, strlen($prefix)) === 0
            : str_starts_with($realKeyPath, $prefix);
        if (!$insideKeyDirectory || !is_file($realKeyPath)) {
            return null;
        }
    }

    return [
        'path' => $realPath,
        'key_path' => $keyPath,
        'extension' => $extension,
    ];
}

function nxReadSensitiveDocument(string $reference, string $slot): ?array
{
    $resolved = nxDocumentUploadResolveReference($reference, $slot);
    if ($resolved === null || !is_file($resolved['key_path'])) {
        return null;
    }

    $ciphertext = file_get_contents($resolved['path']);
    $wrappedKey = file_get_contents($resolved['key_path']);
    if (
        !is_string($ciphertext)
        || !str_starts_with($ciphertext, 'NXD1')
        || strlen($ciphertext) <= 4 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        || !is_string($wrappedKey)
        || !str_starts_with($wrappedKey, 'NXK1')
        || strlen($wrappedKey) !== 4 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            + SODIUM_CRYPTO_SECRETBOX_KEYBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES
    ) {
        throw new NxDocumentUploadRejected('Stored document integrity check failed.');
    }

    $masterKey = nxDocumentUploadMasterKey();
    $keyNonce = substr($wrappedKey, 4, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $documentKey = sodium_crypto_secretbox_open(
        substr($wrappedKey, 4 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        $keyNonce,
        $masterKey
    );
    sodium_memzero($masterKey);
    if (!is_string($documentKey) || strlen($documentKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new NxDocumentUploadRejected('Stored document key integrity check failed.');
    }

    $documentNonce = substr($ciphertext, 4, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(
        substr($ciphertext, 4 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        $documentNonce,
        $documentKey
    );
    sodium_memzero($documentKey);
    if (!is_string($plain)) {
        throw new NxDocumentUploadRejected('Stored document decryption failed.');
    }

    $directories = nxDocumentUploadDirectories();
    $temporaryPath = $directories['quarantine'] . DIRECTORY_SEPARATOR
        . nxDocumentUploadUuidV4() . '.delivery';
    try {
        try {
            nxDocumentUploadWritePrivateFile($temporaryPath, $plain);
        } finally {
            sodium_memzero($plain);
        }

        $expectedMime = $resolved['extension'] === 'pdf'
            ? 'application/pdf'
            : ($resolved['extension'] === 'png' ? 'image/png' : 'image/jpeg');
        if (
            nxDocumentUploadDetectMime($temporaryPath) !== $expectedMime
            || !nxDocumentUploadMagicMatches($temporaryPath, $resolved['extension'])
        ) {
            throw new NxDocumentUploadRejected('Stored document type integrity check failed.');
        }

        return [
            'path' => $temporaryPath,
            'mime' => $expectedMime,
            'extension' => $resolved['extension'],
            'disposition' => $expectedMime === 'application/pdf' ? 'attachment' : 'inline',
        ];
    } catch (Throwable $error) {
        if (is_file($temporaryPath) && !unlink($temporaryPath)) {
            error_log('NexGen private document delivery cleanup failed.');
        }
        throw $error;
    }
}

function nxDeleteSensitiveDocument(string $reference, string $slot): bool
{
    $resolved = nxDocumentUploadResolveReference($reference, $slot);
    if ($resolved !== null) {
        $documentPath = $resolved['path'];
        $keyPath = $resolved['key_path'];
    } else {
        $rules = nxDocumentUploadSlotRules($slot);
        if (!str_starts_with($reference, $rules['prefix'])) {
            return false;
        }
        $filename = substr($reference, strlen($rules['prefix']));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\.(?:jpg|png|pdf)$/i', $filename)) {
            return false;
        }
        $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        if (!isset($rules['extensions'][$extension])) {
            return false;
        }
        $directory = nxDocumentUploadDirectories()[$rules['directory']];
        $documentPath = $directory . DIRECTORY_SEPARATOR . $filename;
        $keyPath = $documentPath . '.key';
        if (is_link($documentPath) || is_link($keyPath)) {
            return false;
        }
    }

    $keyRemoved = !file_exists($keyPath) || unlink($keyPath);
    $fileRemoved = !file_exists($documentPath) || unlink($documentPath);
    return $keyRemoved && $fileRemoved;
}
