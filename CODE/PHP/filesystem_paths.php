<?php
/* Filesystem-only path helpers shared by normal pages and lightweight
   endpoints that deliberately avoid loading config.php and opening MySQL. */

if (!function_exists('nxCaptchaImageDirectory')) {
    function nxCaptchaImageDirectory(): ?string
    {
        $configured = trim((string)(getenv('NEXGEN_CAPTCHA_IMAGE_DIR') ?: ''));
        $candidates = [];

        if ($configured !== '') {
            $candidates[] = $configured;
        }

        $candidates[] = __DIR__ . '/../../IMAGES/captcha'; // XAMPP source layout.
        $candidates[] = __DIR__ . '/IMAGES/captcha'; // Flattened Docker layout.

        foreach (array_unique($candidates) as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_dir($resolved)) {
                return $resolved;
            }
        }

        return null;
    }
}
