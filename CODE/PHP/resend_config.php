<?php
/**
 * Minimal Resend HTTPS client used by NexGen security emails.
 *
 * Required environment variable:
 *   NEXGEN_RESEND_API_KEY
 *
 * Optional environment variables:
 *   NEXGEN_RESEND_FROM_ADDRESS (required in production)
 *   NEXGEN_RESEND_FROM_NAME    (defaults to NexGen)
 */

if (!function_exists('nxSendResendEmail')) {
    function nxSendResendEmail(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $textBody
    ): string {
        $recipientEmail = trim($recipientEmail);
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('The email recipient is invalid.');
        }

        $apiKey = trim((string)(getenv('NEXGEN_RESEND_API_KEY') ?: ''));
        if ($apiKey === '') {
            throw new RuntimeException('NexGen Resend delivery is not configured.');
        }

        $fromAddress = trim((string)(getenv('NEXGEN_RESEND_FROM_ADDRESS') ?: ''));
        if ($fromAddress === '') {
            $isProduction = strtolower(trim((string)(getenv('NEXGEN_ENV') ?: 'development'))) === 'production';
            if ($isProduction) {
                throw new RuntimeException('NexGen Resend sender address is not configured.');
            }
            $fromAddress = 'onboarding@resend.dev';
        }
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('NexGen Resend sender address is invalid.');
        }

        $fromName = trim((string)(getenv('NEXGEN_RESEND_FROM_NAME') ?: 'NexGen'));
        $fromName = trim((string)preg_replace('/[\r\n<>]+/', ' ', $fromName));
        if ($fromName === '') {
            $fromName = 'NexGen';
        }

        $recipientName = trim((string)preg_replace('/[\r\n<>]+/', ' ', $recipientName));
        $toValue = $recipientName === ''
            ? $recipientEmail
            : $recipientName . ' <' . $recipientEmail . '>';

        $payload = json_encode([
            'from' => $fromName . ' <' . $fromAddress . '>',
            'to' => [$toValue],
            'subject' => $subject,
            'text' => $textBody,
        ], JSON_THROW_ON_ERROR);

        $endpoint = 'https://api.resend.com/emails';
        $statusCode = 0;
        $responseBody = '';

        if (function_exists('curl_init')) {
            $curl = curl_init($endpoint);
            if ($curl === false) {
                throw new RuntimeException('Unable to initialize Resend delivery.');
            }

            $curlOptions = [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => $payload,
            ];

            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }

            curl_setopt_array($curl, $curlOptions);
            $curlResponse = curl_exec($curl);

            if ($curlResponse === false) {
                $curlError = curl_error($curl);
                curl_close($curl);
                throw new RuntimeException('Unable to reach Resend: ' . $curlError);
            }

            $responseBody = (string)$curlResponse;
            $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", [
                        'Authorization: Bearer ' . $apiKey,
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ]),
                    'content' => $payload,
                    'timeout' => 15,
                    'ignore_errors' => true,
                ],
            ]);

            $streamResponse = @file_get_contents($endpoint, false, $context);
            $responseHeaders = $http_response_header ?? [];

            foreach ($responseHeaders as $headerLine) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $headerLine, $matches)) {
                    $statusCode = (int)$matches[1];
                }
            }

            if ($streamResponse === false && $statusCode === 0) {
                throw new RuntimeException('Unable to reach Resend.');
            }

            $responseBody = $streamResponse === false ? '' : (string)$streamResponse;
        }

        $responseData = json_decode($responseBody, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $providerMessage = is_array($responseData)
                ? trim((string)($responseData['message'] ?? ''))
                : '';
            $providerMessage = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $providerMessage));
            $suffix = $providerMessage === '' ? '' : ': ' . substr($providerMessage, 0, 240);
            throw new RuntimeException('Resend rejected the email request (HTTP ' . $statusCode . ')' . $suffix);
        }

        $messageId = is_array($responseData) ? trim((string)($responseData['id'] ?? '')) : '';
        if ($messageId === '') {
            throw new RuntimeException('Resend accepted the request without returning a message ID.');
        }

        return $messageId;
    }
}
