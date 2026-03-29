<?php

declare(strict_types=1);

namespace App\Services;

class SignupService
{
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function normalizeMobile(string $mobile): string
    {
        return preg_replace('/\D+/', '', trim($mobile)) ?? '';
    }

    public static function sanitizeText(string $value): string
    {
        return trim(strip_tags($value));
    }

    public static function validateSignupInput(array $input, array $settings, ?array $scheme): array
    {
        $required = ['owner_name', 'company_name', 'mobile', 'email', 'city', 'state', 'password'];
        foreach ($required as $field) {
            if (self::sanitizeText((string) ($input[$field] ?? '')) === '') {
                return [false, 'Please fill all required fields.'];
            }
        }

        if (!filter_var(self::normalizeEmail((string) $input['email']), FILTER_VALIDATE_EMAIL)) {
            return [false, 'Please enter a valid email address.'];
        }

        $mobile = self::normalizeMobile((string) $input['mobile']);
        if (!preg_match('/^\d{10,15}$/', $mobile)) {
            return [false, 'Please enter a valid mobile number.'];
        }

        $password = (string) ($input['password'] ?? '');
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return [false, 'Password must be at least 8 characters and include letters and numbers.'];
        }

        if (empty($settings['allow_signup_globally'])) {
            return [false, 'Signup is currently unavailable.'];
        }

        if (!$scheme || empty($scheme['active_flag']) || empty($scheme['public_visible']) || empty($scheme['signup_enabled'])) {
            return [false, 'Signup is currently unavailable for this scheme.'];
        }

        return [true, ''];
    }

    public static function findDuplicate(array $pending, array $vendors, string $email, string $mobile): ?string
    {
        foreach (array_merge($pending, $vendors) as $row) {
            $rowEmail = self::normalizeEmail((string) ($row['email'] ?? ''));
            $rowMobile = self::normalizeMobile((string) ($row['mobile'] ?? ''));
            if ($rowEmail !== '' && $rowEmail === $email) {
                return 'An account or signup already exists with this email.';
            }
            if ($rowMobile !== '' && $rowMobile === $mobile) {
                return 'An account or signup already exists with this mobile number.';
            }
        }

        return null;
    }

    public static function buildPendingSignup(array $input, string $schemeKey): array
    {
        return [
            'signup_id' => CounterService::next('signup'),
            'requested_scheme_key' => $schemeKey,
            'owner_name' => self::sanitizeText((string) $input['owner_name']),
            'company_name' => self::sanitizeText((string) $input['company_name']),
            'mobile' => self::normalizeMobile((string) $input['mobile']),
            'email' => self::normalizeEmail((string) $input['email']),
            'city' => self::sanitizeText((string) $input['city']),
            'state' => self::sanitizeText((string) $input['state']),
            'address' => self::sanitizeText((string) ($input['address'] ?? '')),
            'business_details' => self::sanitizeText((string) ($input['business_details'] ?? '')),
            'gst_number' => self::sanitizeText((string) ($input['gst_number'] ?? '')),
            'website' => self::sanitizeText((string) ($input['website'] ?? '')),
            'notes' => self::sanitizeText((string) ($input['notes'] ?? '')),
            'password_hash' => password_hash((string) ($input['password'] ?? ''), PASSWORD_DEFAULT),
            'verification_status' => 'pending',
            'submitted_at' => date('c'),
            'processed_at' => null,
            'processed_by' => null,
            'process_note' => null,
        ];
    }
}
