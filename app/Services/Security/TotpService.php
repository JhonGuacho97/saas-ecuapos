<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $email): string
    {
        $issuer = config('app.name', 'EcuaPOS');
        $label = rawurlencode($issuer.':'.$email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer=".rawurlencode($issuer).'&digits=6&period=30';
    }

    public function matchingStep(string $secret, string $code, ?int $lastUsedStep = null): ?int
    {
        $normalized = preg_replace('/\D/', '', $code);
        if (strlen($normalized) !== 6) {
            return null;
        }

        $currentStep = intdiv(time(), 30);
        foreach ([-1, 0, 1] as $offset) {
            $step = $currentStep + $offset;
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }

            if (hash_equals($this->codeAtStep($secret, $step), $normalized)) {
                return $step;
            }
        }

        return null;
    }

    public function codeAtStep(string $secret, int $step): string
    {
        $binary = $this->base32Decode($secret);
        $counter = pack('N2', intdiv($step, 4294967296), $step % 4294967296);
        $hash = hash_hmac('sha1', $counter, $binary, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function recoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))->map(fn () => strtoupper(
            Str::random(5).'-'.Str::random(5)
        ))->all();
    }

    public function recoveryHash(string $code): string
    {
        return hash('sha256', strtoupper(str_replace([' ', '-'], '', $code)));
    }

    public function storeRecoveryCodes(User $user, array $plainCodes): void
    {
        $hashes = array_map(fn (string $code) => $this->recoveryHash($code), $plainCodes);
        $user->two_factor_recovery_codes = Crypt::encryptString(json_encode($hashes));
    }

    public function verifyUserCode(User $user, string $code): bool
    {
        $secret = $user->twoFactorSecret();
        if (! $secret) {
            return false;
        }

        $step = $this->matchingStep($secret, $code, $user->two_factor_last_used_step);
        if ($step !== null) {
            $user->forceFill(['two_factor_last_used_step' => $step])->save();
            return true;
        }

        $hashes = $user->recoveryCodeHashes();
        $index = array_search($this->recoveryHash($code), $hashes, true);
        if ($index === false) {
            return false;
        }

        unset($hashes[$index]);
        $user->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($hashes))),
        ])->save();

        return true;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($value, '='))) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new \InvalidArgumentException('Secreto TOTP inválido.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
