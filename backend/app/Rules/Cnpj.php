<?php
declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cnpj = preg_replace('/\D/', '', (string)$value);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1+$/', $cnpj)) {
            $fail('CNPJ inválido.'); return;
        }

        $calc = function (string $cnpj, int $len): int {
            $sum = 0;
            $pos = $len - 7;
            for ($i = $len; $i >= 1; $i--) {
                $sum += (int)$cnpj[$len - $i] * $pos--;
                if ($pos < 2) $pos = 9;
            }
            $rem = $sum % 11;
            return $rem < 2 ? 0 : 11 - $rem;
        };

        if ((int)$cnpj[12] !== $calc($cnpj, 12) || (int)$cnpj[13] !== $calc($cnpj, 13)) {
            $fail('CNPJ inválido.');
        }
    }
}
