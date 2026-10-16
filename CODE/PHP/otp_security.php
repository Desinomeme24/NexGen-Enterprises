<?php
/**
 * Shared one-time password helpers.
 *
 * OTPs remain six digits in the email and form, but only a one-way password
 * hash is stored in users.otp_code. The database column must be VARCHAR(255)
 * so current and future PASSWORD_DEFAULT hashes cannot be truncated.
 */

if (!function_exists('nxHashOtp')) {
    function nxHashOtp(string $otpCode): string
    {
        if (!preg_match('/^[0-9]{6}$/D', $otpCode)) {
            throw new InvalidArgumentException('OTP must contain exactly six digits.');
        }

        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);

        if (!is_string($otpHash) || $otpHash === '') {
            throw new RuntimeException('Unable to secure the OTP.');
        }

        if (strlen($otpHash) > 255) {
            throw new RuntimeException('The generated OTP hash exceeds the supported storage length.');
        }

        return $otpHash;
    }
}

if (!function_exists('nxVerifyOtp')) {
    function nxVerifyOtp(string $submittedOtp, string $storedOtpHash): bool
    {
        if (
            !preg_match('/^[0-9]{6}$/D', $submittedOtp)
            || $storedOtpHash === ''
        ) {
            return false;
        }

        return password_verify($submittedOtp, $storedOtpHash);
    }
}
