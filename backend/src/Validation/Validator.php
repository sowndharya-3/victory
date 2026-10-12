<?php

namespace App\Validation;

use App\Http\ApiException;

class Validator
{
    public static function required(array $data, array $fields): void
    {
        $errors = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                $errors[$field] = 'This field is required';
            }
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }
    }

    public static function email(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('Validation failed', 400, 'validation_error', ['email' => 'Invalid email address']);
        }
    }

    public static function minLength(string $value, int $min, string $field): void
    {
        if (strlen($value) < $min) {
            throw new ApiException('Validation failed', 400, 'validation_error', [$field => "Must be at least {$min} characters"]);
        }
    }
}
