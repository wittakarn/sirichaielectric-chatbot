<?php

require_once __DIR__ . '/PromptInjectionDetector.php';

/**
 * Pre-LLM gate for customer messages. Returns why a message must be refused,
 * or null when it may go on to the model.
 */
class InputGuard {

    const REASON_TOO_LONG  = 'too_long';
    const REASON_INJECTION = 'prompt_injection';

    // Longest message (in characters) that is still forwarded to the model.
    const MAX_LENGTH = 1000;

    /** @var PromptInjectionDetector */
    private $detector;

    public function __construct(?PromptInjectionDetector $detector = null) {
        $this->detector = $detector !== null ? $detector : new PromptInjectionDetector();
    }

    public function refusalReason(string $message): ?string {
        if (mb_strlen($message, 'UTF-8') > self::MAX_LENGTH) {
            return self::REASON_TOO_LONG;
        }
        if ($this->detector->isInjection($message)) {
            return self::REASON_INJECTION;
        }
        return null;
    }
}
