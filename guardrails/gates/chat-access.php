<?php

require_once __DIR__ . '/../lib/Runner.php';

/** Gate: the web chat only serves holders of a valid, unexpired token within the rate limit. */

const ACCESS_FILE = 'services/ChatAccess.php';
const INDEX_FILE = 'index.php';

$rules = array(
    'access/accepts-valid-token'   => 'A freshly issued token verifies.',
    'access/rejects-tampered'      => 'A token with an altered id/expiry/signature is rejected.',
    'access/rejects-wrong-secret'  => 'A token signed with another secret is rejected.',
    'access/rejects-expired'       => 'An expired token is rejected.',
    'access/rejects-empty-secret'  => 'With no secret configured nothing validates (fail closed).',
    'access/rate-limit-blocks'     => 'The request over the per-minute limit is refused; the limit itself is allowed.',
    'access/chat-route-guarded'    => '/chat in index.php calls requireChatAccess() before handleChat().',
);

return new Gate(
    'chat-access',
    $rules,
    'issue, tamper with and expire tokens; hammer the limiter; confirm /chat is wired to the guard',
    function (string $root): Result {
        $file = $root . '/' . ACCESS_FILE;
        $index = $root . '/' . INDEX_FILE;
        if (!is_file($file) || !is_file($index)) {
            return Result::refuse('ChatAccess or index.php missing');
        }
        require_once $file;

        $secret = 'test-secret';
        $now = 1000000;
        $token = ChatAccess::issueToken($secret, $now);
        list($id, $exp, $sig) = explode('.', $token);
        $breaches = array();
        $fail = function (string $rule, string $why) use (&$breaches) {
            $breaches[] = Result::breach($rule, $why);
        };

        if (ChatAccess::verifyToken($token, $secret, $now) !== $id) {
            $fail('access/accepts-valid-token', 'a fresh token did not verify');
        }
        $tampered = array($id . 'x.' . $exp . '.' . $sig, $id . '.' . ($exp + 9999) . '.' . $sig, $id . '.' . $exp . '.' . strrev($sig), 'garbage', '');
        foreach ($tampered as $t) {
            if (ChatAccess::verifyToken($t, $secret, $now) !== null) {
                $fail('access/rejects-tampered', 'accepted tampered token: ' . $t);
            }
        }
        if (ChatAccess::verifyToken($token, 'other-secret', $now) !== null) {
            $fail('access/rejects-wrong-secret', 'accepted a token signed with another secret');
        }
        if (ChatAccess::verifyToken($token, $secret, $now + ChatAccess::TOKEN_TTL + 1) !== null) {
            $fail('access/rejects-expired', 'accepted an expired token');
        }
        if (ChatAccess::verifyToken(ChatAccess::issueToken('', $now), '', $now) !== null) {
            $fail('access/rejects-empty-secret', 'accepted a token with an empty secret');
        }

        $count = 0;
        $hit = function ($id, $window) use (&$count) { return ++$count; };
        $results = array();
        for ($i = 1; $i <= 4; $i++) {
            $results[] = ChatAccess::withinLimit($hit, 'a', $now, 3);
        }
        if ($results !== array(true, true, true, false)) {
            $fail('access/rate-limit-blocks', 'expected [true,true,true,false], got ' . json_encode($results));
        }

        // Hardcoded on purpose: the route must call the guard before the handler.
        if (!preg_match('/requireChatAccess\(\$config, \$dbConfig\);\s*handleChat\(/', file_get_contents($index))) {
            $fail('access/chat-route-guarded', 'index.php /chat no longer calls requireChatAccess() before handleChat()');
        }
        return Result::fromBreaches(7, $breaches);
    },
    array(
        Proof::red('access/rejects-tampered', 'checks the signature',
            Mutate::replaceText(ACCESS_FILE, 'if (!hash_equals(hash_hmac(\'sha256\', $id . \'.\' . $expiry, $secret), $sig)) {', 'if (false) {')),
        Proof::red('access/rejects-wrong-secret', 'checks the signature against the secret',
            Mutate::replaceText(ACCESS_FILE, 'if (!hash_equals(hash_hmac(\'sha256\', $id . \'.\' . $expiry, $secret), $sig)) {', 'if (false) {')),
        Proof::red('access/rejects-expired', 'enforces expiry',
            Mutate::replaceText(ACCESS_FILE, 'if ((int)$expiry < $now) {', 'if (false) {')),
        Proof::red('access/rejects-empty-secret', 'fails closed on empty secret',
            Mutate::replaceText(ACCESS_FILE, 'if ($secret === \'\') {
            return null;
        }', '')),
        Proof::red('access/rate-limit-blocks', 'enforces the limit',
            Mutate::replaceText(ACCESS_FILE, '<= $maxPerMinute', '<= $maxPerMinute + 1000000')),
        Proof::red('access/accepts-valid-token', 'still accepts a good token',
            Mutate::replaceText(ACCESS_FILE, 'if (count($parts) !== 3) {', 'if (true) {')),
        Proof::red('access/chat-route-guarded', 'notices the guard being removed',
            Mutate::replaceText(INDEX_FILE, '    requireChatAccess($config, $dbConfig);
', '')),
        Proof::green('accepts the unmodified access control'),
    )
);
