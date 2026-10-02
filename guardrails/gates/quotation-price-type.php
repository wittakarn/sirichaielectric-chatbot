<?php

require_once __DIR__ . '/../lib/Runner.php';

/** Gate: which price tier a quotation may use. */

const POLICY_FILE = 'services/PriceTypePolicy.php';

$rules = array(
    'price/unauthorized-forced-default' => 'Unauthorized users always get the default tier, whatever the model asks for.',
    'price/invalid-falls-back'          => 'An unknown or non-string tier falls back to the default.',
    'price/authorized-keeps-valid'      => 'Authorized users keep a valid requested tier.',
);

return new Gate(
    'quotation-price-type',
    $rules,
    'resolve requested tiers for authorized and unauthorized users through PriceTypePolicy',
    function (string $root): Result {
        $file = $root . '/' . POLICY_FILE;
        if (!is_file($file)) {
            return Result::refuse('PriceTypePolicy source missing');
        }
        require_once $file;
        $policy = new PriceTypePolicy();

        // [rule, requested, isAuthorized, expected]
        $cases = array();
        foreach (array('ss', 'a', 'vb', 'f') as $tier) {
            $cases[] = array('price/unauthorized-forced-default', $tier, false, 'c');
        }
        $cases[] = array('price/unauthorized-forced-default', null, false, 'c');
        foreach (array('zz', '', 'VB', 0, null, array('vb')) as $bad) {
            $cases[] = array('price/invalid-falls-back', $bad, true, 'c');
        }
        foreach (array('ss', 's', 'a', 'b', 'vb', 'vc', 'd', 'e', 'f') as $tier) {
            $cases[] = array('price/authorized-keeps-valid', $tier, true, $tier);
        }

        $breaches = array();
        foreach ($cases as list($rule, $requested, $authorized, $expected)) {
            $actual = $policy->resolve($requested, $authorized);
            if ($actual !== $expected) {
                $breaches[] = Result::breach($rule, 'requested ' . json_encode($requested) . ' authorized=' . json_encode($authorized) . " → $actual (expected $expected)");
            }
        }
        return Result::fromBreaches(count($cases), $breaches);
    },
    array(
        Proof::red('price/unauthorized-forced-default', 'unauthorized user cannot pick a tier',
            Mutate::replaceText(POLICY_FILE, 'if (!$isAuthorized) {', 'if (false) {')),
        Proof::red('price/invalid-falls-back', 'rejects unknown tiers',
            Mutate::replaceText(POLICY_FILE, '!is_string($requested) || !in_array($requested, self::VALID_TYPES, true)', '!is_string($requested)')),
        // No proof for dropping the strict flag: is_string() and strict in_array() each
        // already reject non-strings, so that mutant is equivalent (defense in depth).
        Proof::red('price/authorized-keeps-valid', 'does not collapse every tier to the default',
            Mutate::replaceText(POLICY_FILE, 'return $requested;', 'return self::DEFAULT_TYPE;')),
        Proof::green('accepts the unmodified policy'),
    )
);
