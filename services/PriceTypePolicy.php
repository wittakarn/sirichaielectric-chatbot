<?php

/**
 * Decides which price tier (rate) a quotation may use.
 * Rate types are internal and never shown to customers.
 */
class PriceTypePolicy {

    // Lowest-privilege tier: the only one unauthorized users may receive.
    const DEFAULT_TYPE = 'c';

    const VALID_TYPES = array('ss', 's', 'a', 'b', 'c', 'vb', 'vc', 'd', 'e', 'f');

    /**
     * @param mixed $requested Value supplied by the model; untrusted
     */
    public function resolve($requested, bool $isAuthorized): string {
        if (!$isAuthorized) {
            return self::DEFAULT_TYPE;
        }
        // Strict comparison: a non-string must never loosely match a tier.
        if (!is_string($requested) || !in_array($requested, self::VALID_TYPES, true)) {
            return self::DEFAULT_TYPE;
        }
        return $requested;
    }
}
