<?php

require_once __DIR__ . '/../lib/Runner.php';

/**
 * Gate: the guardrails are actually connected.
 * The other gates prove the policies work in isolation; this one proves the
 * chatbot still calls them and the system prompt still carries its security block.
 */

const CHATBOT_FILE = 'chatbot/SirichaiElectricChatbot.php';
const PROMPT_FILE = 'system-prompt.txt';

// rule id => [file, substring that must be present, description]
$requirements = array(
    'wiring/guard-before-model' => array(CHATBOT_FILE, '$this->inputGuard->refusalReason($message)', 'chat() consults InputGuard before the model.'),
    'wiring/guard-then-parent'  => array(CHATBOT_FILE, 'return parent::chat($message, $conversationHistory);', 'chat() only reaches the model through the guard.'),
    'wiring/price-policy'       => array(CHATBOT_FILE, '$this->priceTypePolicy->resolve(', 'generate_quotation resolves its tier through PriceTypePolicy.'),
    'wiring/prompt-security'    => array(PROMPT_FILE, 'CRITICAL — SECURITY', 'system-prompt.txt keeps its SECURITY block.'),
);

$rules = array();
$proofs = array();
foreach ($requirements as $id => $req) {
    $rules[$id] = $req[2];
    // Mutation: remove the required text, as an accidental refactor or merge would.
    $proofs[] = Proof::red($id, "notices when $id is removed", Mutate::replaceText($req[0], $req[1], '/* removed */'));
}
$proofs[] = Proof::green('accepts the unmodified wiring');

return new Gate(
    'wiring',
    $rules,
    'scan the chatbot class and system prompt for the required guardrail hooks',
    function (string $root) use ($requirements): Result {
        $breaches = array();
        $inspected = 0;
        foreach ($requirements as $id => $req) {
            $path = $root . '/' . $req[0];
            if (!is_file($path)) {
                continue; // not inspected; zero inspected overall becomes REFUSE
            }
            $inspected++;
            if (strpos(file_get_contents($path), $req[1]) === false) {
                $breaches[] = Result::breach($id, "{$req[0]} no longer contains: {$req[1]}");
            }
        }
        return Result::fromBreaches($inspected, $breaches);
    },
    $proofs
);
