<?php

namespace Talivio\Sdk\Human;

/**
 * Bir kayıt denemesinin insanlık değerlendirmesi.
 *
 * `reasons` yalnızca log/teşhis içindir — kullanıcıya asla gösterilmez
 * (botlara hangi sinyalin yakalandığını söylemek savunmayı zayıflatır).
 */
final class HumanVerdict
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly bool $passed,
        public readonly int $score,
        public readonly array $reasons = [],
    ) {
    }

    public static function pass(int $score): self
    {
        return new self(true, $score);
    }

    /** @param list<string> $reasons */
    public static function fail(array $reasons, int $score = 0): self
    {
        return new self(false, $score, $reasons);
    }
}
