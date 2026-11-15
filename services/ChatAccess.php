<?php

/**
 * Access control for the public web chat: a signed, short-lived session token
 * (issued after a Cloudflare Turnstile check) plus a per-token request limit.
 * Pure functions, no I/O except verifyTurnstile(), so the guardrail gate can test them.
 */
class ChatAccess {

    // Seconds a session token stays valid.
    const TOKEN_TTL = 1800;

    /** Token = "<id>.<expiry>.<hmac>". The id is what the rate limit counts against. */
    public static function issueToken(string $secret, int $now): string {
        $payload = bin2hex(random_bytes(8)) . '.' . ($now + self::TOKEN_TTL);
        return $payload . '.' . hash_hmac('sha256', $payload, $secret);
    }

    /** Returns the token id when valid, null otherwise. An empty secret never validates. */
    public static function verifyToken(string $token, string $secret, int $now): ?string {
        if ($secret === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        list($id, $expiry, $sig) = $parts;
        if (!hash_equals(hash_hmac('sha256', $id . '.' . $expiry, $secret), $sig)) {
            return null;
        }
        if ((int)$expiry < $now) {
            return null;
        }
        return $id;
    }

    /**
     * Fixed one-minute window. $hit(id, window) records a request and returns
     * how many this id has made in that window (including this one).
     */
    public static function withinLimit(callable $hit, string $id, int $now, int $maxPerMinute): bool {
        return $hit($id, intdiv($now, 60)) <= $maxPerMinute;
    }

    /** Server-side Turnstile check. Fails closed on any error. */
    public static function verifyTurnstile(string $secret, string $response, string $ip = ''): bool {
        if ($secret === '' || $response === '') {
            return false;
        }
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(array('secret' => $secret, 'response' => $response, 'remoteip' => $ip)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ));
        $body = curl_exec($ch);
        curl_close($ch);
        $data = is_string($body) ? json_decode($body, true) : null;
        return is_array($data) && !empty($data['success']);
    }
}
