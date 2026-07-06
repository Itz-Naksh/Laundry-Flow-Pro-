<?php
declare(strict_types=1);

namespace App\Helpers;

class Validator
{
    private array $errors = [];

    // ── Rules ─────────────────────────────────────────────────────────────────

    public function required(string $field, mixed $value, string $label = ''): self
    {
        if ($value === null || trim((string) $value) === '') {
            $this->errors[$field] = ($label ?: ucfirst($field)) . ' is required';
        }
        return $this;
    }

    public function minLength(string $field, mixed $value, int $min, string $label = ''): self
    {
        if (isset($this->errors[$field])) return $this;
        if (mb_strlen((string) $value) < $min) {
            $this->errors[$field] = ($label ?: ucfirst($field)) . " must be at least {$min} characters";
        }
        return $this;
    }

    public function maxLength(string $field, mixed $value, int $max, string $label = ''): self
    {
        if (isset($this->errors[$field])) return $this;
        if (mb_strlen((string) $value) > $max) {
            $this->errors[$field] = ($label ?: ucfirst($field)) . " must not exceed {$max} characters";
        }
        return $this;
    }

    public function email(string $field, mixed $value): self
    {
        if (isset($this->errors[$field])) return $this;
        if ((string) $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = 'Enter a valid email address';
        }
        return $this;
    }

    public function regex(string $field, mixed $value, string $pattern, string $message): self
    {
        if (isset($this->errors[$field])) return $this;
        if ((string) $value !== '' && !preg_match($pattern, (string) $value)) {
            $this->errors[$field] = $message;
        }
        return $this;
    }

    public function confirmed(string $field, mixed $value, mixed $confirmation, string $label = ''): self
    {
        if (isset($this->errors[$field])) return $this;
        if ($value !== $confirmation) {
            $this->errors[$field] = ($label ?: 'Passwords') . ' do not match';
        }
        return $this;
    }

    /**
     * Requires a password strength score of at least 2 / 4:
     * +1 if ≥ 8 chars, +1 if has uppercase, +1 if has digit, +1 if has special char.
     */
    public function minPasswordStrength(string $field, ?string $password): self
    {
        if (isset($this->errors[$field])) return $this;
        if (!$password) return $this;

        $score = 0;
        if (strlen($password) >= 8)                   $score++;
        if (preg_match('/[A-Z]/', $password))          $score++;
        if (preg_match('/[0-9]/', $password))          $score++;
        if (preg_match('/[^A-Za-z0-9]/', $password))  $score++;

        if ($score < 2) {
            $this->errors[$field] = 'Password is too weak. Use 8+ chars with uppercase, number, and special character.';
        }
        return $this;
    }

    // ── Result ────────────────────────────────────────────────────────────────

    public function passes(): bool  { return empty($this->errors); }
    public function errors(): array { return $this->errors; }
}
