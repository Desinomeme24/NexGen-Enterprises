<?php
/* =========================================================================
   NEXTGEN CONFIG / SECURITY / HELPERS
   ========================================================================= */

require_once __DIR__ . '/filesystem_paths.php';

/* SESSION SECURITY: set cookie controls before starting the session. */
$nxForceHttps = filter_var((string)(getenv('NEXGEN_FORCE_HTTPS') ?: '0'), FILTER_VALIDATE_BOOLEAN);
$nxRequestIsHttps = $nxForceHttps
    || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_secure', $nxRequestIsHttps ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_path', '/');
        ini_set('session.gc_maxlifetime', '600');
    }
    session_start();
}

/* SECURITY: prevent the browser from caching authenticated pages. Without
   this, hitting Back after logging out (or switching accounts on a shared
   till/PC) can redisplay a previous user's cached page - including data
   like Purchase Order History or receivables - straight from disk/memory
   cache instead of re-requesting it from the server. */
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'");
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    if ($nxRequestIsHttps && strtolower((string)(getenv('NEXGEN_ENV') ?: 'development')) === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/* DATABASE CONNECTION: Railway supplies NEXGEN_DB_* variables while the
   fallbacks keep the same file usable with a local XAMPP installation. */
$dbHostEnv = getenv('NEXGEN_DB_HOST');
$dbPortEnv = getenv('NEXGEN_DB_PORT');
$dbNameEnv = getenv('NEXGEN_DB_NAME');
$dbUserEnv = getenv('NEXGEN_DB_USER');
$dbPasswordEnv = getenv('NEXGEN_DB_PASSWORD');

$host = $dbHostEnv === false || trim((string)$dbHostEnv) === ''
    ? 'localhost'
    : trim((string)$dbHostEnv);
$dbname = $dbNameEnv === false || trim((string)$dbNameEnv) === ''
    ? 'nexgen_db'
    : trim((string)$dbNameEnv);
$dbuser = $dbUserEnv === false || trim((string)$dbUserEnv) === ''
    ? 'root'
    : trim((string)$dbUserEnv);
$dbpass = $dbPasswordEnv === false ? '' : (string)$dbPasswordEnv;

$dbPortValue = $dbPortEnv === false || trim((string)$dbPortEnv) === ''
    ? 3306
    : filter_var(
        trim((string)$dbPortEnv),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    );
$dbport = $dbPortValue === false ? 3306 : (int)$dbPortValue;

$nxEnvironment = strtolower(trim((string)(getenv('NEXGEN_ENV') ?: 'development')));

if ($nxEnvironment === 'production') {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

if ($nxEnvironment === 'production') {
    $requiredDatabaseVariables = [
        'NEXGEN_DB_HOST' => $dbHostEnv,
        'NEXGEN_DB_PORT' => $dbPortEnv,
        'NEXGEN_DB_NAME' => $dbNameEnv,
        'NEXGEN_DB_USER' => $dbUserEnv,
        'NEXGEN_DB_PASSWORD' => $dbPasswordEnv,
    ];

    foreach ($requiredDatabaseVariables as $variableName => $variableValue) {
        if ($variableValue === false || trim((string)$variableValue) === '') {
            error_log('NexGen is missing the required production variable: ' . $variableName);
            http_response_code(503);
            exit('The service is temporarily unavailable.');
        }
    }

    if ($dbPortValue === false) {
        error_log('NexGen received an invalid production database port.');
        http_response_code(503);
        exit('The service is temporarily unavailable.');
    }
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_init();

    if (!$conn) {
        throw new RuntimeException('Unable to initialize the database client.');
    }

    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $conn->real_connect($host, $dbuser, $dbpass, $dbname, $dbport);
} catch (Throwable $e) {
    error_log('NexGen database connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('The service is temporarily unavailable.');
}

try {
    if (!$conn->set_charset('utf8mb4')) {
        throw new RuntimeException('Unable to configure the database connection character set.');
    }

    /* NexGen business time is Asia/Manila (UTC+8). Railway's MySQL
       system timezone is UTC, so set the timezone for this connection.
       This makes NOW()/CURRENT_TIMESTAMP use Philippine time and makes
       TIMESTAMP values (such as stock_movements.created_at) return in
       Philippine time for this session. */
    if (!$conn->query("SET time_zone = '+08:00'")) {
        throw new RuntimeException('Unable to configure the database connection timezone.');
    }
} catch (Throwable $e) {
    error_log('NexGen database connection setup failed: ' . $e->getMessage());
    http_response_code(503);
    exit('The service is temporarily unavailable.');
}
date_default_timezone_set('Asia/Manila');

/* APPLICATION PATHS: keep one codebase working when XAMPP serves NexGen from
   /NexGen and Railway serves the published application from the domain root. */
$nxIsProduction = $nxEnvironment === 'production';

if (!defined('NEXGEN_APP_BASE_PATH')) {
    define('NEXGEN_APP_BASE_PATH', $nxIsProduction ? '' : '/NexGen/CODE/PHP');
}

if (!defined('NEXGEN_PROJECT_BASE_PATH')) {
    define('NEXGEN_PROJECT_BASE_PATH', $nxIsProduction ? '' : '/NexGen');
}

if (!defined('NEXGEN_CODE_BASE_PATH')) {
    define('NEXGEN_CODE_BASE_PATH', $nxIsProduction ? '' : '/NexGen/CODE');
}

if (!function_exists('nxPathJoin')) {
    function nxPathJoin(string $basePath, string $path = ''): string
    {
        $normalizedBase = rtrim($basePath, '/');
        $normalizedPath = ltrim($path, '/');

        if ($normalizedPath === '') {
            return $normalizedBase === '' ? '/' : $normalizedBase . '/';
        }

        return $normalizedBase . '/' . $normalizedPath;
    }
}

if (!function_exists('nxAppUrl')) {
    function nxAppUrl(string $path = ''): string
    {
        return nxPathJoin(NEXGEN_APP_BASE_PATH, $path);
    }
}

if (!function_exists('nxProjectUrl')) {
    function nxProjectUrl(string $path = ''): string
    {
        return nxPathJoin(NEXGEN_PROJECT_BASE_PATH, $path);
    }
}

if (!function_exists('nxCodeUrl')) {
    function nxCodeUrl(string $path = ''): string
    {
        return nxPathJoin(NEXGEN_CODE_BASE_PATH, $path);
    }
}

/* SESSION SECURITY: timeout constant */
if (!defined('SESSION_TIMEOUT_SECONDS')) {
    define('SESSION_TIMEOUT_SECONDS', 600); // 10 minutes
}

/* AUTH PORTALS: keep administrator authentication on a dedicated route while
   preserving the public/client portal for owners and employees. These paths
   are centralized so timeouts, role guards, login processing, and logout all
   return a user to the correct portal. */
if (!defined('NEXGEN_CLIENT_LOGIN_PATH')) {
    define('NEXGEN_CLIENT_LOGIN_PATH', nxAppUrl('index.php'));
}

// Admin login on a separate route //
if (!defined('NEXGEN_ADMIN_LOGIN_PATH')) {
    define(
        'NEXGEN_ADMIN_LOGIN_PATH',
        nxProjectUrl('nx-control-1407')
    );
}

/* =========================================================================
   COMMON HELPERS
   ========================================================================= */
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('nxProductImageUrl')) {
    function nxProductImageUrl(?string $path): string
    {
        $cleanPath = trim((string)$path);
        $legacyDefaultPath = 'uploads/products/default.png';

        if ($cleanPath === '' || strcasecmp($cleanPath, $legacyDefaultPath) === 0) {
            return nxProjectUrl('IMAGES/default-product.svg');
        }

        if (preg_match('#^uploads[\\\\/]#i', $cleanPath) === 1) {
            $relativeUploadPath = preg_replace(
                '#^uploads[\\\\/]#i',
                '',
                $cleanPath
            );
            $storedImagePath = rtrim(nxPublicUploadDirectory(), "\\/")
                . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeUploadPath);

            if (!is_file($storedImagePath)) {
                return nxProjectUrl('IMAGES/default-product.svg');
            }
        }

        return nxAppUrl($cleanPath);
    }
}

if (!function_exists('formatQty')) {
    /* Formats a decimal(12,3) quantity for display: trims trailing zeros so a
       whole number like 10.000 shows as "10" while a fractional quantity like
       2.500 shows as "2.5" - keeps piece-counted products (groceries, school
       supplies) looking like plain integers without needing per-SME-type
       display logic. */
    function formatQty($value): string
    {
        $formatted = number_format((float)$value, 3, '.', '');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');
        return $formatted === '' ? '0' : $formatted;
    }
}

/* =========================================================================
   ADAPTIVE SME-TYPE / BATCH INVENTORY HELPERS
   ========================================================================= */
if (!function_exists('nxIsBatchTrackedType')) {
    /* Which SME types manage stock as per-batch/per-expiry lots (product_batches)
       instead of a single flat stock_quantity number. */
    function nxIsBatchTrackedType(?string $businessType): bool
    {
        return in_array($businessType, ['Mini Grocery / Sari-Sari Store', 'Pharmacy / Drugstore'], true);
    }
}

if (!function_exists('nxBlocksExpiredBatches')) {
    /* Pharmacy/Drugstore may never sell or count expired batches as available
       stock. Mini Grocery still allows it - an owner is expected to manually
       pull expired stock rather than have the system block the sale outright. */
    function nxBlocksExpiredBatches(?string $businessType): bool
    {
        return $businessType === 'Pharmacy / Drugstore';
    }
}

if (!function_exists('nxGenerateBatchNumber')) {
    /* Batch numbers are system-generated, not typed by users - avoids typos/
       duplicates and matches the same auto-numbering approach already used
       for sales_no. Timestamp + a short random suffix keeps it unique even
       if two batches are created for the same product in the same second. */
    function nxGenerateBatchNumber(): string
    {
        return 'B-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    }
}

if (!function_exists('nxDeductFefo')) {
    /**
     * FEFO (first-expiry-first-out) stock deduction for a batch-tracked product.
     * Consumes the soonest-expiring batch(es) first. Depleted batches are removed;
     * products.stock_quantity is kept in sync automatically by the
     * trg_product_batches_after_update/delete triggers. Must run inside the
     * caller's transaction - throws on failure so the caller's rollback applies.
     */
    function nxDeductFefo(mysqli $conn, int $businessId, int $productId, float $quantity, bool $blockExpired): void
    {
        // status = 'active' excludes batches already dropped as expired/disposed -
        // those sit at quantity 0 and must never be touched again (deleting a
        // depleted-looking row here would erase the drop's history).
        // Batches with no expiry date sort last (MySQL puts NULL first in ASC
        // order by default, which is the opposite of "deducted last").
        // A NULL expiry means "never expires" - it must stay sellable even
        // when blocking expired stock, so it's never excluded by this clause.
        $expiryClause = $blockExpired ? "AND (expiry_date IS NULL OR expiry_date >= CURDATE())" : "";
        $batchStmt = $conn->prepare("
            SELECT id, quantity
            FROM product_batches
            WHERE product_id = ? AND business_id = ? AND status = 'active' {$expiryClause}
            ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC
            FOR UPDATE
        ");
        if (!$batchStmt) {
            throw new Exception('Failed to prepare batch lock query.');
        }
        $batchStmt->bind_param("ii", $productId, $businessId);
        $batchStmt->execute();
        $batches = $batchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $batchStmt->close();

        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0.0005) {
                break;
            }

            $batchQty = (float) $batch['quantity'];
            $take = min($batchQty, $remaining);
            $newQty = $batchQty - $take;

            if ($newQty <= 0.0005) {
                $del = $conn->prepare("DELETE FROM product_batches WHERE id = ?");
                if (!$del) {
                    throw new Exception('Failed to prepare batch delete query.');
                }
                $del->bind_param("i", $batch['id']);
                if (!$del->execute()) {
                    throw new Exception('Failed to consume depleted batch.');
                }
                $del->close();
            } else {
                $upd = $conn->prepare("UPDATE product_batches SET quantity = ? WHERE id = ?");
                if (!$upd) {
                    throw new Exception('Failed to prepare batch update query.');
                }
                $upd->bind_param("di", $newQty, $batch['id']);
                if (!$upd->execute()) {
                    throw new Exception('Failed to deduct from batch.');
                }
                $upd->close();
            }

            $remaining -= $take;
        }

        if ($remaining > 0.0005) {
            throw new Exception($blockExpired
                ? 'This product is expired and cannot be sold. Please remove it or check for a newer batch.'
                : 'Not enough stock available for this product.');
        }
    }
}

if (!function_exists('isStrongPassword')) {
    function isStrongPassword(string $password): bool
    {
        $characterLength = function_exists('mb_strlen')
            ? mb_strlen($password, 'UTF-8')
            : strlen($password);
        $byteLength = strlen($password);

        if ($characterLength < 12 || $byteLength > 64 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
            return false;
        }

        return (bool) preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9])/u', $password);
    }
}

if (!function_exists('nxHashPassword')) {
    function nxHashPassword(string $password): string
    {
        if (!isStrongPassword($password)) {
            throw new InvalidArgumentException('Password does not meet the required security policy.');
        }

        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 19456,
                'time_cost' => 2,
                'threads' => 1,
            ]);
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
        }

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Unable to protect the password securely.');
        }

        return $hash;
    }
}

if (!function_exists('nxSecurityRateLimitSchemaReady')) {
    function nxSecurityRateLimitSchemaReady(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        try {
            $result = $conn->query(
                "SELECT COUNT(*) AS total
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'security_rate_limits'"
            );
            $ready = $result instanceof mysqli_result
                && (int)($result->fetch_assoc()['total'] ?? 0) === 1;
        } catch (Throwable $e) {
            error_log('NexGen rate-limit schema check failed: ' . $e->getMessage());
            $ready = false;
        }

        return $ready;
    }
}

if (!function_exists('nxConsumeSecurityRateLimit')) {
    function nxConsumeSecurityRateLimit(
        mysqli $conn,
        string $action,
        string $identifier,
        int $maxAttempts,
        int $windowSeconds,
        int $blockSeconds
    ): array {
        if (
            $action === '' || $identifier === '' || $maxAttempts < 1
            || $windowSeconds < 1 || $blockSeconds < 1
            || !nxSecurityRateLimitSchemaReady($conn)
        ) {
            return ['allowed' => false, 'retry_after' => 0, 'configured' => false];
        }

        $bucketKey = hash('sha256', $action . "\0" . $identifier);
        $now = time();

        try {
            $conn->begin_transaction();

            $select = $conn->prepare(
                "SELECT attempts,
                        UNIX_TIMESTAMP(window_started_at) AS window_started_ts,
                        UNIX_TIMESTAMP(blocked_until) AS blocked_until_ts
                 FROM security_rate_limits
                 WHERE bucket_key = ?
                 FOR UPDATE"
            );
            if (!$select) {
                throw new RuntimeException('Unable to prepare rate-limit lookup.');
            }
            $select->bind_param('s', $bucketKey);
            $select->execute();
            $row = $select->get_result()->fetch_assoc();
            $select->close();

            if (!$row) {
                $insert = $conn->prepare(
                    "INSERT INTO security_rate_limits
                        (bucket_key, action_name, attempts, window_started_at, blocked_until)
                     VALUES (?, ?, 1, FROM_UNIXTIME(?), NULL)"
                );
                if (!$insert) {
                    throw new RuntimeException('Unable to prepare rate-limit record.');
                }
                $insert->bind_param('ssi', $bucketKey, $action, $now);
                $insert->execute();
                $insert->close();
                $conn->commit();

                return ['allowed' => true, 'retry_after' => 0, 'configured' => true];
            }

            $blockedUntil = (int)($row['blocked_until_ts'] ?? 0);
            if ($blockedUntil > $now) {
                $conn->commit();
                return [
                    'allowed' => false,
                    'retry_after' => $blockedUntil - $now,
                    'configured' => true,
                ];
            }

            $windowStarted = (int)($row['window_started_ts'] ?? $now);
            $attempts = (int)($row['attempts'] ?? 0);
            if (($now - $windowStarted) >= $windowSeconds) {
                $windowStarted = $now;
                $attempts = 1;
            } else {
                $attempts++;
            }

            $allowed = $attempts <= $maxAttempts;
            $newBlockedUntil = $allowed ? 0 : ($now + $blockSeconds);
            $update = $conn->prepare(
                "UPDATE security_rate_limits
                 SET attempts = ?,
                     window_started_at = FROM_UNIXTIME(?),
                     blocked_until = CASE WHEN ? > 0 THEN FROM_UNIXTIME(?) ELSE NULL END
                 WHERE bucket_key = ?"
            );
            if (!$update) {
                throw new RuntimeException('Unable to prepare rate-limit update.');
            }
            $update->bind_param(
                'iiiis',
                $attempts,
                $windowStarted,
                $newBlockedUntil,
                $newBlockedUntil,
                $bucketKey
            );
            $update->execute();
            $update->close();
            $conn->commit();

            return [
                'allowed' => $allowed,
                'retry_after' => $allowed ? 0 : $blockSeconds,
                'configured' => true,
            ];
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
            error_log('NexGen rate-limit operation failed: ' . $e->getMessage());

            return ['allowed' => false, 'retry_after' => 0, 'configured' => false];
        }
    }
}

if (!function_exists('nxMaskUsername')) {
    /* Masks a username for display when a forgot-password lookup matches more
       than one account under the same email (e.g. an SME owner's branch
       accounts) - shows enough to be recognizable without exposing the full
       username to anyone who isn't already looking at their own inbox. */
    function nxMaskUsername(string $username): string
    {
        $len = strlen($username);

        if ($len <= 2) {
            return str_repeat('*', $len);
        }

        if ($len <= 4) {
            return substr($username, 0, 1) . str_repeat('*', $len - 1);
        }

        return substr($username, 0, 2) . str_repeat('*', $len - 3) . substr($username, -1);
    }
}

if (!function_exists('nxMaskEmail')) {
    /* Masks an email for display after an OTP is sent (e.g. "jo***@gmail.com")
       - lets the user confirm where the code went without echoing the full
       address back on screen. */
    function nxMaskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2 || $parts[1] === '') {
            return $email;
        }

        [$local, $domain] = $parts;
        $len = strlen($local);

        $maskedLocal = $len <= 2
            ? str_repeat('*', $len)
            : substr($local, 0, 2) . str_repeat('*', max(1, $len - 2));

        return $maskedLocal . '@' . $domain;
    }
}

if (!function_exists('isUserLocked')) {
    function isUserLocked(array $user): bool
    {
        return !empty($user['locked_until']) && strtotime((string)$user['locked_until']) > time();
    }
}

/* =========================================================================
   CAPTCHA: IMAGE CAPTCHA FUNCTIONS
   ========================================================================= */
if (!function_exists('generateImageCaptcha')) {
    function generateImageCaptcha(string $formKey): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $baseDir = nxCaptchaImageDirectory();
        if ($baseDir === null) {
            return [
                'success' => false,
                'message' => 'Captcha image folder not found.',
                'target_label' => '',
                'items' => []
            ];
        }

        $categoryDirs = array_filter(glob($baseDir . '/*'), 'is_dir');
        $categories = [];

        foreach ($categoryDirs as $dir) {
            $category = basename($dir);
            $images = glob($dir . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);

            if (!empty($images)) {
                $categories[$category] = array_values($images);
            }
        }

        if (count($categories) < 2) {
            return [
                'success' => false,
                'message' => 'Captcha needs at least 2 category folders with images.',
                'target_label' => '',
                'items' => []
            ];
        }

        $targetCategory = array_rand($categories);
        $targetImages = $categories[$targetCategory];

        if (count($targetImages) < 3) {
            return [
                'success' => false,
                'message' => 'Captcha target category needs at least 3 images.',
                'target_label' => '',
                'items' => []
            ];
        }

        shuffle($targetImages);

        /*
        |--------------------------------------------------------------------------
        | LIMIT TARGET IMAGES SHOWN TO 3 TO 5 ONLY
        |--------------------------------------------------------------------------
        */
        $maxCorrect = min(5, count($targetImages));
        $correctCount = random_int(3, $maxCorrect);
        $correctImages = array_slice($targetImages, 0, $correctCount);

        $otherImages = [];
        foreach ($categories as $categoryName => $images) {
            if ($categoryName === $targetCategory) {
                continue;
            }

            foreach ($images as $img) {
                $otherImages[] = [
                    'category' => $categoryName,
                    'path' => $img
                ];
            }
        }

        $distractorCount = 9 - count($correctImages);

        if (count($otherImages) < $distractorCount) {
            return [
                'success' => false,
                'message' => 'Captcha does not have enough distractor images.',
                'target_label' => '',
                'items' => []
            ];
        }

        shuffle($otherImages);
        $distractors = array_slice($otherImages, 0, $distractorCount);

        $items = [];
        $imagePaths = [];

        foreach ($correctImages as $imgPath) {
            $token = bin2hex(random_bytes(16));
            $items[] = [
                'id' => $token,
                'relative_path' => nxAppUrl('captcha_image.php?form=') . rawurlencode($formKey) . '&id=' . rawurlencode($token),
                'is_correct' => true
            ];
            $imagePaths[$token] = $imgPath;
        }

        foreach ($distractors as $item) {
            $token = bin2hex(random_bytes(16));
            $items[] = [
                'id' => $token,
                'relative_path' => nxAppUrl('captcha_image.php?form=') . rawurlencode($formKey) . '&id=' . rawurlencode($token),
                'is_correct' => false
            ];
            $imagePaths[$token] = $item['path'];
        }

        shuffle($items);

        if (empty($_SESSION['image_captcha']) || !is_array($_SESSION['image_captcha'])) {
            $_SESSION['image_captcha'] = [];
        }

        $_SESSION['image_captcha'][$formKey] = [
            'target' => $targetCategory,
            'expires_at' => time() + 300,
            'image_paths' => $imagePaths,
            'correct_ids' => array_values(array_map(
                fn($item) => $item['id'],
                array_filter($items, fn($item) => $item['is_correct'] === true)
            ))
        ];

        return [
            'success' => true,
            'message' => '',
            'target_label' => ucwords(str_replace('_', ' ', $targetCategory)),
            'items' => $items
        ];
    }
}

if (!function_exists('validateImageCaptchaSelection')) {
    function validateImageCaptchaSelection(string $formKey, array $selectedIds): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['image_captcha']) || !isset($_SESSION['image_captcha'][$formKey])) {
            return false;
        }

        $captcha = $_SESSION['image_captcha'][$formKey];
        unset($_SESSION['image_captcha'][$formKey]);

        if (
            empty($captcha['correct_ids']) || !is_array($captcha['correct_ids'])
            || (int)($captcha['expires_at'] ?? 0) < time()
        ) {
            return false;
        }

        $expected = array_values(array_map('strval', $captcha['correct_ids']));

        $selectedIds = array_values(array_unique(array_map('strval', $selectedIds)));
        if (count($selectedIds) > 9) {
            return false;
        }
        sort($selectedIds);
        sort($expected);

        return $selectedIds === $expected;
    }
}

if (!function_exists('nxResolveCaptchaImagePath')) {
    function nxResolveCaptchaImagePath(string $formKey, string $imageToken): ?string
    {
        if (
            !preg_match('/^[a-z0-9_-]{1,50}$/', $formKey)
            || !preg_match('/^[a-f0-9]{32}$/', $imageToken)
            || empty($_SESSION['image_captcha'][$formKey])
        ) {
            return null;
        }

        $captcha = $_SESSION['image_captcha'][$formKey];
        if (
            (int)($captcha['expires_at'] ?? 0) < time()
            || empty($captcha['image_paths'][$imageToken])
        ) {
            return null;
        }

        $baseDirectory = nxCaptchaImageDirectory();
        $imagePath = realpath((string)$captcha['image_paths'][$imageToken]);
        if ($baseDirectory === null || $imagePath === false || !is_file($imagePath)) {
            return null;
        }

        $basePrefix = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($imagePath, $basePrefix) ? $imagePath : null;
    }
}

/* =========================================================================
   CSRF
   ========================================================================= */
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(string $formName): string
    {
        if (empty($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }

        // Reuse the existing token for this form if one is already pending.
        // Pages like inventory_management.php re-run this on every AJAX
        // filter/tab refresh (not just full loads) to keep their static
        // modals' hidden tokens working - minting a brand-new token every
        // time silently invalidated any modal that was already open,
        // showing "session expired" even though the session was fine.
        if (!empty($_SESSION['csrf_tokens'][$formName])) {
            return $_SESSION['csrf_tokens'][$formName];
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_tokens'][$formName] = $token;
        return $token;
    }
}

if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken(string $formName, ?string $token): bool
    {
        if (
            empty($_SESSION['csrf_tokens']) ||
            !isset($_SESSION['csrf_tokens'][$formName]) ||
            empty($token)
        ) {
            return false;
        }

        $isValid = hash_equals($_SESSION['csrf_tokens'][$formName], $token);
        unset($_SESSION['csrf_tokens'][$formName]);
        return $isValid;
    }
}

/* =========================================================================
   SESSION TIMEOUT / RBAC
   ========================================================================= */
if (!function_exists('nxNormalizeLoginPortal')) {
    function nxNormalizeLoginPortal(?string $portal): string
    {
        return strtolower(trim((string)$portal)) === 'admin' ? 'admin' : 'client';
    }
}

if (!function_exists('nxLoginPathForPortal')) {
    function nxLoginPathForPortal(?string $portal): string
    {
        return nxNormalizeLoginPortal($portal) === 'admin'
            ? NEXGEN_ADMIN_LOGIN_PATH
            : NEXGEN_CLIENT_LOGIN_PATH;
    }
}

if (!function_exists('nxRoleMatchesLoginPortal')) {
    function nxRoleMatchesLoginPortal(?string $role, ?string $portal): bool
    {
        $normalizedPortal = nxNormalizeLoginPortal($portal);
        $normalizedRole = strtolower(trim((string)$role));

        return $normalizedPortal === 'admin'
            ? $normalizedRole === 'system_admin'
            : in_array($normalizedRole, ['owner', 'employee'], true);
    }
}

if (!function_exists('nxLoginPathForRole')) {
    function nxLoginPathForRole(?string $role = null): string
    {
        return nxLoginPathForPortal($role === 'system_admin' ? 'admin' : 'client');
    }
}

if (!function_exists('enforceSessionTimeout')) {
    function enforceSessionTimeout(int $timeoutSeconds = SESSION_TIMEOUT_SECONDS): void
    {
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $now = time();
        $lastActivity = (int) ($_SESSION['last_activity'] ?? $now);

        if (($now - $lastActivity) > $timeoutSeconds) {
            $loginPath = nxLoginPathForRole((string)($_SESSION['role'] ?? ''));

            /* Close the session's existing login audit row BEFORE the
               authenticated session is destroyed. This is the timeout
               equivalent of manual logout and must update the same row. */
            $auditLogId = (int)($_SESSION['audit_log_id'] ?? 0);
            if ($auditLogId > 0 && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
                closeAuthAuditLog($GLOBALS['conn'], $auditLogId, 'timeout');
            }

            unset($_SESSION['audit_log_id']);
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            session_destroy();
            session_id('');
            session_start();
            $_SESSION['error'] = 'Your session has expired due to inactivity. Please log in again.';
            $_SESSION['form_type'] = 'login';
            header('Location: ' . $loginPath);
            exit();
        }

        $_SESSION['last_activity'] = $now;
    }
}

if (!function_exists('requireRole')) {
    function requireRole(array $roles): void
    {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            $adminOnly = in_array('system_admin', $roles, true)
                && !in_array('owner', $roles, true)
                && !in_array('employee', $roles, true);
            header('Location: ' . ($adminOnly ? NEXGEN_ADMIN_LOGIN_PATH : NEXGEN_CLIENT_LOGIN_PATH));
            exit();
        }

        enforceSessionTimeout();

        if (!in_array($_SESSION['role'], $roles, true)) {
            $_SESSION['error'] = 'Unauthorized access.';
            $safeDashboard = $_SESSION['role'] === 'system_admin'
                ? nxAppUrl('admin_dashboard.php')
                : nxAppUrl('dashboard.php');
            header('Location: ' . $safeDashboard);
            exit();
        }
    }
}

/* =========================================================================
   FILE VALIDATION / ANTIVIRUS-STYLE SCANNING
   ========================================================================= */
if (!function_exists('validateUploadedFile')) {
    function validateUploadedFile(array $file, array $allowedExtensions, int $maxSizeBytes, bool $mustBeImage = false): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return [false, 'Upload failed.'];
        }

        if (($file['size'] ?? 0) > $maxSizeBytes) {
            return [false, 'File exceeds the allowed size limit.'];
        }

        $originalName = (string)($file['name'] ?? '');
        $tmpName = (string)($file['tmp_name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            return [false, 'File type is not allowed.'];
        }

        if ($mustBeImage) {
            $imageInfo = @getimagesize($tmpName);
            if ($imageInfo === false) {
                return [false, 'Uploaded file is not a valid image.'];
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmpName) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        return [true, $mime];
    }
}

if (!function_exists('nxIsSuspiciousFilename')) {
    function nxIsSuspiciousFilename(string $filename): bool
    {
        $filename = strtolower(trim($filename));

        if ($filename === '') {
            return true;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $filename)) {
            return true;
        }

        if (preg_match('/\.(php|phtml|php3|php4|php5|phar|exe|com|bat|cmd|sh|js|jsp|asp|aspx|cgi|pl|py|rb)(\..+)?$/i', $filename)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('nxFileStartsWithPdfSignature')) {
    function nxFileStartsWithPdfSignature(string $tmpPath): bool
    {
        $handle = @fopen($tmpPath, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 5);
        fclose($handle);

        return $header === '%PDF-';
    }
}

if (!function_exists('nxScanFileForDangerousPatterns')) {
    function nxScanFileForDangerousPatterns(string $tmpPath): bool
    {
        $content = @file_get_contents($tmpPath);
        if ($content === false) {
            return true;
        }

        $patterns = [
            '/<\?php/i',
            '/<script\b/i',
            '/<html\b/i',
            '/javascript:/i',
            '/eval\s*\(/i',
            '/base64_decode\s*\(/i',
            '/cmd\.exe/i',
            '/powershell/i',
            '/\/JavaScript\b/i',
            '/\/JS\s/i',
            '/\/OpenAction\b/i',
            '/\/Launch\b/i',
            '/\/EmbeddedFile\b/i',
            '/\/RichMedia\b/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('nxValidateSecureUpload')) {
    function nxValidateSecureUpload(array $file, array $options = []): array
    {
        $allowedExtensions = $options['allowed_extensions'] ?? [];
        $allowedMimeTypes = $options['allowed_mime_types'] ?? [];
        $maxSizeBytes = (int)($options['max_size'] ?? (5 * 1024 * 1024));
        $requireImage = !empty($options['require_image']);
        $allowPdf = !empty($options['allow_pdf']);
        $mimeExtensionMap = $options['mime_extension_map'] ?? [];

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return [false, 'Upload failed.'];
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return [false, 'Invalid uploaded file.'];
        }

        $originalName = (string)($file['name'] ?? '');
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (nxIsSuspiciousFilename($originalName)) {
            return [false, 'Suspicious filename detected.'];
        }

        if ($size <= 0 || $size > $maxSizeBytes) {
            return [false, 'File exceeds the allowed size limit.'];
        }

        if (!in_array($extension, $allowedExtensions, true)) {
            return [false, 'File type is not allowed.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmpPath) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($mime === false || $mime === '') {
            return [false, 'Unable to detect file type.'];
        }

        if (!in_array($mime, $allowedMimeTypes, true)) {
            return [false, 'Detected file type is not allowed.'];
        }

        if (
            !empty($mimeExtensionMap)
            && (!isset($mimeExtensionMap[$extension]) || !in_array($mime, $mimeExtensionMap[$extension], true))
        ) {
            return [false, 'The filename extension does not match the detected file type.'];
        }

        if ($requireImage || str_starts_with($mime, 'image/')) {
            $imageInfo = @getimagesize($tmpPath);
            if ($imageInfo === false) {
                return [false, 'Uploaded file is not a valid image.'];
            }
        }

        if ($allowPdf && $extension === 'pdf' && !nxFileStartsWithPdfSignature($tmpPath)) {
            return [false, 'Uploaded PDF file is invalid or corrupted.'];
        }

        if (nxScanFileForDangerousPatterns($tmpPath)) {
            return [false, 'File failed the security scan.'];
        }

        return [true, $mime];
    }
}

if (!function_exists('nxPublicUploadDirectory')) {
    function nxPublicUploadDirectory(): string
    {
        $configured = trim((string)(getenv('NEXGEN_PUBLIC_UPLOAD_DIR') ?: ''));
        return $configured !== ''
            ? rtrim($configured, "\\/")
            : __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    }
}

if (!function_exists('nxSaleReceiptDirectory')) {
    function nxSaleReceiptDirectory(): string
    {
        $configured = trim((string)(getenv('NEXGEN_PRIVATE_UPLOAD_DIR') ?: ''));
        if ($configured !== '') {
            return rtrim($configured, "\\/") . DIRECTORY_SEPARATOR . 'receipts';
        }

        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'nexgen_private'
            . DIRECTORY_SEPARATOR . 'receipts';
    }
}

if (!function_exists('nxSaleReceiptPath')) {
    function nxSaleReceiptPath(int $saleId): ?string
    {
        if ($saleId <= 0) {
            return null;
        }

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            $path = nxSaleReceiptDirectory() . DIRECTORY_SEPARATOR . 'receipt_' . $saleId . '.' . $extension;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}

if (!function_exists('nxPrivateValidIdDirectory')) {
    function nxPrivateValidIdDirectory(): string
    {
        $configured = trim((string)(getenv('NEXGEN_PRIVATE_UPLOAD_DIR') ?: ''));
        if ($configured !== '') {
            return rtrim($configured, "\\/") . DIRECTORY_SEPARATOR . 'valid_ids';
        }

        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'nexgen_private'
            . DIRECTORY_SEPARATOR . 'valid_ids';
    }
}

if (!function_exists('nxCreatePrivateValidIdReference')) {
    function nxCreatePrivateValidIdReference(string $filename): string
    {
        if (!preg_match('/^valid_id_[a-f0-9]{24}\.(?:jpe?g|png|pdf)$/', $filename)) {
            throw new InvalidArgumentException('Invalid private document filename.');
        }

        return 'private-valid-id:' . $filename;
    }
}

if (!function_exists('nxResolveValidIdPath')) {
    function nxResolveValidIdPath(string $storedReference): ?string
    {
        $filename = '';
        $baseDirectory = '';

        if (str_starts_with($storedReference, 'private-valid-id:')) {
            $filename = substr($storedReference, strlen('private-valid-id:'));
            $baseDirectory = nxPrivateValidIdDirectory();
        } elseif (str_starts_with($storedReference, 'uploads/valid_ids/')) {
            // Backward-compatible access for files created before private storage.
            $filename = basename($storedReference);
            $baseDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'valid_ids';
        } else {
            return null;
        }

        if (!preg_match('/^valid_id_[a-f0-9]{24}\.(?:jpe?g|png|gif|webp|pdf)$/', $filename)) {
            return null;
        }

        $realBase = realpath($baseDirectory);
        $candidate = $baseDirectory . DIRECTORY_SEPARATOR . $filename;
        $realCandidate = realpath($candidate);
        if ($realBase === false || $realCandidate === false || !is_file($realCandidate)) {
            return null;
        }

        $basePrefix = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realCandidate, $basePrefix)) {
            return null;
        }

        return $realCandidate;
    }
}

/* =========================================================================
   GENERAL AUDIT LOGS (DEPRECATED — audit_logs was repurposed, see below)
   ========================================================================= */
if (!function_exists('logActivity')) {
    function logActivity(mysqli $conn, ?int $userId, string $username, string $role, string $eventType, string $targetType, int $targetId, string $details): void
    {
        /* The audit_logs table was repurposed to record only login/logout
           events (see logAuthActivity() below) — it no longer has the
           table_name / action / record_id / old_values / new_values columns
           this function used to write to.

           This function is intentionally left as a no-op stub, with its
           original signature intact, purely so that existing call-sites
           elsewhere in the codebase (e.g. workspace_action.php) keep working
           without any code changes on their end and without throwing errors
           against the new schema. It does not write anywhere. If general
           critical-operation logging is wanted again later, point this at a
           new dedicated table instead of audit_logs. */
        return;
    }
}

/* =========================================================================
   LOGIN / LOGOUT AUDIT LOGS
   ========================================================================= */
if (!function_exists('logAuthActivity')) {
    /**
     * Create exactly one audit row for a successful login.
     *
     * Logout is deliberately NOT an INSERT anymore. The audit row created
     * here is the session's single source of truth and is closed later by
     * closeAuthAuditLog(). The returned ID is stored in $_SESSION.
     */
    function logAuthActivity(
        mysqli $conn,
        ?int $userId,
        string $username,
        string $role,
        string $eventType,
        ?string $logoutReason = null
    ): ?int {
        if ($eventType !== 'login') {
            return null;
        }

        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            if ($userAgent !== null && mb_strlen($userAgent) > 255) {
                $userAgent = mb_substr($userAgent, 0, 255);
            }

            $stmt = $conn->prepare(
                "INSERT INTO audit_logs (
                    user_id,
                    username,
                    role,
                    event_type,
                    logout_reason,
                    ip_address,
                    user_agent
                ) VALUES (?, ?, ?, 'login', NULL, ?, ?)"
            );

            if (!$stmt) {
                error_log('NexGen login audit prepare failed: ' . $conn->error);
                return null;
            }

            $stmt->bind_param(
                "issss",
                $userId,
                $username,
                $role,
                $ipAddress,
                $userAgent
            );
            $stmt->execute();

            $auditLogId = (int)$stmt->insert_id;
            $stmt->close();

            return $auditLogId > 0 ? $auditLogId : null;
        } catch (Throwable $e) {
            error_log('NexGen login audit insert failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('closeAuthAuditLog')) {
    /**
     * Close the existing login audit row for this session.
     *
     * The event row is updated in place, never duplicated. The WHERE clause
     * makes the operation idempotent: once a session is closed, another
     * logout/timeout request cannot create or re-close another audit row.
     */
    function closeAuthAuditLog(mysqli $conn, int $auditLogId, string $logoutReason): bool
    {
        if ($auditLogId < 1 || !in_array($logoutReason, ['manual', 'timeout'], true)) {
            return false;
        }

        try {
            $stmt = $conn->prepare(
                "UPDATE audit_logs
                 SET event_type = 'logout',
                     logout_reason = ?,
                     logout_at = NOW()
                 WHERE id = ?
                   AND event_type = 'login'
                 LIMIT 1"
            );

            if (!$stmt) {
                error_log('NexGen logout audit prepare failed: ' . $conn->error);
                return false;
            }

            $stmt->bind_param("si", $logoutReason, $auditLogId);
            $stmt->execute();
            $updated = $stmt->affected_rows === 1;
            $stmt->close();

            return $updated;
        } catch (Throwable $e) {
            error_log('NexGen logout audit update failed: ' . $e->getMessage());
            return false;
        }
    }
}

/* =========================================================================
   ADMIN LOGS - LEGACY + SECURE HASHED
   ========================================================================= */
if (!function_exists('logAdminActivity')) {
    function logAdminActivity(mysqli $conn, int $adminId, string $action, string $targetType, int $targetId, string $description): void
    {
        $stmt = $conn->prepare("
            INSERT INTO admin_logs (admin_id, action, target_type, target_id, description)
            VALUES (?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            return;
        }

        $stmt->bind_param("issis", $adminId, $action, $targetType, $targetId, $description);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('getLatestAdminLogHash')) {
    function getLatestAdminLogHash(mysqli $conn): string
    {
        $sql = "SELECT log_hash FROM admin_logs WHERE log_hash IS NOT NULL AND log_hash <> '' ORDER BY id DESC LIMIT 1";
        $result = $conn->query($sql);

        if ($result && $row = $result->fetch_assoc()) {
            return (string)($row['log_hash'] ?? '');
        }

        return str_repeat('0', 64);
    }
}

if (!function_exists('buildAdminLogHash')) {
    function buildAdminLogHash(
        int $adminId,
        string $action,
        string $targetType,
        int $targetId,
        string $description,
        string $createdAt,
        string $previousHash,
        string $ipAddress,
        string $userAgent
    ): string {
        $payload = implode('|', [
            $adminId,
            $action,
            $targetType,
            $targetId,
            $description,
            $createdAt,
            $previousHash,
            $ipAddress,
            $userAgent
        ]);

        return hash('sha256', $payload);
    }
}

if (!function_exists('logAdminActivitySecure')) {
    function logAdminActivitySecure(mysqli $conn, int $adminId, string $action, string $targetType, int $targetId, string $description): bool
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        $createdAt = date('Y-m-d H:i:s');
        $previousHash = getLatestAdminLogHash($conn);
        $logHash = buildAdminLogHash(
            $adminId,
            $action,
            $targetType,
            $targetId,
            $description,
            $createdAt,
            $previousHash,
            $ipAddress,
            $userAgent
        );

        $stmt = $conn->prepare("
            INSERT INTO admin_logs (
                admin_id,
                action,
                target_type,
                target_id,
                description,
                previous_hash,
                log_hash,
                ip_address,
                user_agent,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "ississssss",
            $adminId,
            $action,
            $targetType,
            $targetId,
            $description,
            $previousHash,
            $logHash,
            $ipAddress,
            $userAgent,
            $createdAt
        );

        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('verifyAdminLogRowIntegrity')) {
    function verifyAdminLogRowIntegrity(array $row): bool
    {
        $expected = buildAdminLogHash(
            (int)($row['admin_id'] ?? 0),
            (string)($row['action'] ?? ''),
            (string)($row['target_type'] ?? ''),
            (int)($row['target_id'] ?? 0),
            (string)($row['description'] ?? ''),
            (string)($row['created_at'] ?? ''),
            (string)($row['previous_hash'] ?? str_repeat('0', 64)),
            (string)($row['ip_address'] ?? 'UNKNOWN'),
            (string)($row['user_agent'] ?? 'UNKNOWN')
        );

        return hash_equals((string)($row['log_hash'] ?? ''), $expected);
    }
}

// Shared local-time formatter. NexGen uses Asia/Manila for both PHP and
// the MySQL connection. DATETIME values such as sales.sale_date are stored
// as Philippine clock time, while TIMESTAMP values such as
// stock_movements.created_at are returned by MySQL in the current session
// timezone. Do not apply another UTC->Manila conversion here, or timestamps
// would be shifted by an extra 8 hours.
if (!function_exists('nxFormatLocalDateTime')) {
    function nxFormatLocalDateTime(?string $localDateTime, string $format = 'M d, Y h:i A', string $timezone = 'Asia/Manila'): ?string
    {
        if (empty($localDateTime)) {
            return null;
        }
        try {
            $dt = new DateTime($localDateTime, new DateTimeZone($timezone));
            return $dt->format($format);
        } catch (Exception $e) {
            return null;
        }
    }
}
?>