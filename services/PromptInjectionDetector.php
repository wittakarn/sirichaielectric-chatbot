<?php

/**
 * Detects prompt-injection attempts in customer messages.
 *
 * Every rule has a stable id so a guardrail proof can assert that a specific
 * rule (not just "some rule") is still live. Keep each rule on ONE line:
 * the guardrail mutations disable a rule by rewriting its line.
 */
class PromptInjectionDetector {

    // Exact substrings, matched against the lower-cased message.
    // Short enough that no variation is needed.
    const SUBSTRINGS = array(
        'sub/system-prompt'      => 'system prompt',
        'sub/system-instruction' => 'system instruction',
        'sub/act-as-dan'         => 'act as dan',
        'sub/pretend-no'         => 'pretend you have no',
        'sub/jailbreak'          => 'jailbreak',
        'sub/th-forget-command'  => 'ลืมคำสั่ง',
        'sub/th-omit-command'    => 'ละเว้นคำสั่ง',
        'sub/th-ignore-command'  => 'เพิกเฉยคำสั่ง',
        'sub/th-tell-command'    => 'บอกคำสั่งของคุณ',
        'sub/th-show-command'    => 'แสดงคำสั่งของคุณ',
        'sub/th-print-command'   => 'พิมพ์คำสั่งของคุณ',
        'sub/th-system-command'  => 'คำสั่งระบบ',
    );

    // Regex patterns, matched against the original message.
    // The flexible middle (.{0,50}) catches word variations,
    // e.g. "ignore original/initial/all/the/my instructions".
    const REGEXES = array(
        'rx/override-instruction' => '/(ignore|disregard|forget|override|bypass|dismiss|drop|erase|replace)\b.{0,50}\b(instruction|prompt|directive|guideline)/is',
        'rx/reveal-instruction'   => '/(output|reveal|print|show|repeat|display|expose|dump|give me|tell me)\b.{0,40}\b(instruction|prompt|directive|guideline)/is',
        'rx/persona-switch'       => '/(you are now|act as|pretend (you are|to be)|behave as|roleplay as|simulate being)\b/i',
        'rx/new-instructions'     => '/\bnew\s+(instruction|prompt|rule|directive)s?\b/i',
        'rx/verbatim-after'       => '/\b(instruction|prompt|directive)s?\b.{0,30}\bverbatim\b/i',
        'rx/verbatim-before'      => '/\bverbatim\b.{0,30}\b(instruction|prompt|directive)s?\b/i',
        'rx/th-override-rule'     => '/(?:ละเว้น|ลบ|เปลี่ยน|แทนที่).{0,30}(?:คำสั่ง|กฎ|prompt)/u',
        'rx/th-reveal-rule'       => '/(?:บอก|แสดง|พิมพ์|เปิดเผย).{0,20}(?:กฎ|prompt|คำแนะนำระบบ)/u',
    );

    public function isInjection(string $message): bool {
        return count($this->matchedRules($message)) > 0;
    }

    /**
     * @return string[] ids of every rule that matches the message
     */
    public function matchedRules(string $message): array {
        $matched = array();

        $lower = mb_strtolower($message, 'UTF-8');
        foreach (self::SUBSTRINGS as $id => $needle) {
            if (mb_strpos($lower, $needle) !== false) {
                $matched[] = $id;
            }
        }

        foreach (self::REGEXES as $id => $pattern) {
            if (preg_match($pattern, $message)) {
                $matched[] = $id;
            }
        }

        return $matched;
    }
}
