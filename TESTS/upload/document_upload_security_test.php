<?php
require_once __DIR__ . '/../../CODE/PHP/document_upload_security.php';

function testAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testThrows(string $exceptionClass, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        testAssert($error instanceof $exceptionClass, $message . ': wrong exception type');
        return;
    }

    throw new RuntimeException($message . ': expected exception was not thrown');
}

testAssert(nxDocumentUploadFileNameExtension('id.jpg') === 'jpg', 'JPG extension should be accepted');
testAssert(nxDocumentUploadFileNameExtension('id.jpeg') === 'jpeg', 'JPEG extension should be accepted');
testAssert(nxDocumentUploadFileNameExtension('id.png') === 'png', 'PNG extension should be accepted');
testAssert(nxDocumentUploadFileNameExtension('permit.pdf') === 'pdf', 'PDF extension should be accepted');
foreach (['id.php.jpg', '../id.jpg', "id\0.jpg", 'id.svg', 'id.mp4', 'id.zip'] as $filename) {
    testAssert(nxDocumentUploadFileNameExtension($filename) === null, 'Unsafe extension should be rejected');
}

testAssert(isset(nxDocumentUploadSlotRules('valid_id')['extensions']['heic']), 'ID slot should allow HEIC input');
testAssert(!isset(nxDocumentUploadSlotRules('valid_id')['extensions']['pdf']), 'ID slot must not allow PDF');
testAssert(isset(nxDocumentUploadSlotRules('business_permit')['extensions']['pdf']), 'Permit slot should allow PDF');
testThrows(
    NxDocumentUploadRejected::class,
    static function (): void {
        nxDocumentUploadSlotRules('unknown');
    },
    'Unknown slots must be rejected'
);

$temporary = tempnam(sys_get_temp_dir(), 'nx-upload-test-');
if ($temporary === false) {
    throw new RuntimeException('Unable to create test data.');
}
try {
    $magicSamples = [
        ['jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF"],
        ['png', "\x89PNG\r\n\x1A\n"],
        ['pdf', '%PDF-1.7'],
        ['heic', "\x00\x00\x00\x18ftypheic"],
    ];
    foreach ($magicSamples as [$extension, $contents]) {
        file_put_contents($temporary, $contents);
        testAssert(nxDocumentUploadMagicMatches($temporary, $extension), 'Valid magic bytes should pass');
    }
    file_put_contents($temporary, "\x00\x00\x00\x18ftypavif");
    testAssert(!nxDocumentUploadMagicMatches($temporary, 'heic'), 'AVIF must not pass HEIC magic validation');
    file_put_contents($temporary, "MZ\x00\x00\x00\x00");
    testAssert(!nxDocumentUploadMagicMatches($temporary, 'jpg'), 'Renamed executable must fail JPEG magic validation');
    testAssert(!nxDocumentUploadMagicMatches($temporary, 'pdf'), 'Renamed executable must fail PDF magic validation');

    $nested = [
        'name' => ['id.jpg'],
        'type' => ['image/jpeg'],
        'tmp_name' => ['tmp'],
        'error' => [UPLOAD_ERR_NO_FILE],
        'size' => [0],
    ];
    testThrows(
        NxDocumentUploadRejected::class,
        static function () use ($nested): void {
            nxDocumentUploadValidateBag(['valid_id' => $nested], ['valid_id']);
        },
        'Nested/folder upload payloads must be rejected'
    );
    $folderFile = [
        'name' => 'id.jpg',
        'full_path' => 'nested/id.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => 'tmp',
        'error' => UPLOAD_ERR_NO_FILE,
        'size' => 0,
    ];
    testThrows(
        NxDocumentUploadRejected::class,
        static function () use ($folderFile): void {
            nxDocumentUploadValidateBag(['valid_id' => $folderFile], ['valid_id']);
        },
        'Folder-relative upload paths must be rejected'
    );
} finally {
    unlink($temporary);
}

$originalHost = getenv('CLAMAV_HOST');
$originalPort = getenv('CLAMAV_PORT');
$originalTimeout = getenv('CLAMAV_TIMEOUT_SECONDS');
putenv('CLAMAV_HOST=127.0.0.1');
putenv('CLAMAV_PORT=1');
putenv('CLAMAV_TIMEOUT_SECONDS=1');
try {
    testThrows(
        NxDocumentUploadUnavailable::class,
        static function () use ($temporary): void {
            nxDocumentUploadScanWithClamAv($temporary);
        },
        'Scanner outage must fail closed'
    );
} finally {
    putenv('CLAMAV_HOST=' . ($originalHost === false ? '' : $originalHost));
    putenv('CLAMAV_PORT=' . ($originalPort === false ? '' : $originalPort));
    putenv('CLAMAV_TIMEOUT_SECONDS=' . ($originalTimeout === false ? '' : $originalTimeout));
}

if (getenv('RUN_CLAMAV_TESTS') === '1') {
    $eicar = tempnam(sys_get_temp_dir(), 'nx-eicar-test-');
    $clean = tempnam(sys_get_temp_dir(), 'nx-clean-test-');
    if ($eicar === false || $clean === false) {
        throw new RuntimeException('Unable to create scanner test data.');
    }
    try {
        file_put_contents(
            $eicar,
            'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
        );
        file_put_contents($clean, 'NexGen ClamAV connectivity test.');
        nxDocumentUploadScanWithClamAv($clean);
        testThrows(
            NxDocumentUploadRejected::class,
            static function () use ($eicar): void {
                nxDocumentUploadScanWithClamAv($eicar);
            },
            'EICAR test content must be rejected'
        );
    } finally {
        unlink($eicar);
        unlink($clean);
    }
}

if (function_exists('sodium_crypto_secretbox')) {
    $testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nx-document-test-' . bin2hex(random_bytes(8));
    $testPublic = $testRoot . DIRECTORY_SEPARATOR . 'public';
    $testStorage = $testRoot . DIRECTORY_SEPARATOR . 'private';
    $testQuarantine = $testRoot . DIRECTORY_SEPARATOR . 'quarantine';
    mkdir($testPublic, 0700, true);
    mkdir($testQuarantine, 0700, true);

    $originalStorage = getenv('NEXGEN_PRIVATE_UPLOAD_DIR');
    $originalQuarantine = getenv('NEXGEN_UPLOAD_QUARANTINE_DIR');
    $originalKey = getenv('NEXGEN_UPLOAD_ENCRYPTION_KEY');
    $originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $reference = null;
    $deliveryPath = null;
    $stagedPath = $testQuarantine . DIRECTORY_SEPARATOR . 'sanitized.png';
    try {
        putenv('NEXGEN_PRIVATE_UPLOAD_DIR=' . $testStorage);
        putenv('NEXGEN_UPLOAD_QUARANTINE_DIR=' . $testQuarantine);
        putenv('NEXGEN_UPLOAD_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
        $_SERVER['DOCUMENT_ROOT'] = $testPublic;

        $pngPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'IMAGES'
            . DIRECTORY_SEPARATOR . 'introbg.png';
        testAssert(is_file($pngPath), 'PNG fixture must be available');
        copy($pngPath, $stagedPath);
        $expectedBytes = file_get_contents($stagedPath);
        $reference = nxPersistSensitiveDocument([
            'path' => $stagedPath,
            'extension' => 'png',
            'slot' => 'valid_id',
        ], 'valid_id');
        $document = nxReadSensitiveDocument($reference, 'valid_id');
        testAssert(is_array($document), 'Encrypted document should decrypt');
        $deliveryPath = $document['path'];
        testAssert(file_get_contents($deliveryPath) === $expectedBytes, 'Decrypted document should match sanitized bytes');
        testAssert(nxDeleteSensitiveDocument($reference, 'valid_id'), 'Document and key should be deleted');
        testAssert(nxDeleteSensitiveDocument($reference, 'valid_id'), 'Document deletion should be idempotent');
        $reference = null;
        echo "Private document encryption round-trip passed.\n";
    } finally {
        if ($deliveryPath !== null && is_file($deliveryPath)) {
            unlink($deliveryPath);
        }
        if ($reference !== null) {
            nxDeleteSensitiveDocument($reference, 'valid_id');
        }
        if (is_file($stagedPath)) {
            unlink($stagedPath);
        }
        foreach ([
            $testStorage . DIRECTORY_SEPARATOR . 'valid_ids',
            $testStorage . DIRECTORY_SEPARATOR . 'business_permits',
            $testStorage,
            $testQuarantine,
            $testPublic,
            $testRoot,
        ] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        putenv('NEXGEN_PRIVATE_UPLOAD_DIR=' . ($originalStorage === false ? '' : $originalStorage));
        putenv('NEXGEN_UPLOAD_QUARANTINE_DIR=' . ($originalQuarantine === false ? '' : $originalQuarantine));
        putenv('NEXGEN_UPLOAD_ENCRYPTION_KEY=' . ($originalKey === false ? '' : $originalKey));
        if ($originalDocumentRoot === null) {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $originalDocumentRoot;
        }
    }
} else {
    echo "Private document encryption round-trip skipped: sodium is not enabled.\n";
}

echo "Document upload security tests passed.\n";
