<?php
require_once __DIR__ . '/../config/stripe.php';

function stripeRequest(string $method, string $endpoint, array $data = []): array {
    $url = 'https://api.stripe.com/v1' . $endpoint;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['error' => ['message' => 'Stripe API request failed: ' . $curlError]];
    }

    return json_decode($response, true) ?? ['error' => ['message' => 'Invalid response from Stripe']];
}

function stripeCreateCheckoutSession(array $params): array {
    return stripeRequest('POST', '/checkout/sessions', $params);
}

function stripeCreateTransfer(array $params): array {
    return stripeRequest('POST', '/transfers', $params);
}

function stripeVerifyWebhookSignature(string $payload, string $sigHeader, string $secret): bool {
    $parts = explode(',', $sigHeader);
    $timestamp = null;
    $signatures = [];
    foreach ($parts as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) !== 2) continue;
        [$key, $val] = $kv;
        if ($key === 't') $timestamp = $val;
        if ($key === 'v1') $signatures[] = $val;
    }
    if (!$timestamp || empty($signatures)) return false;
    $signedPayload = $timestamp . '.' . $payload;
    $expectedSig = hash_hmac('sha256', $signedPayload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expectedSig, $sig)) return true;
    }
    return false;
}
