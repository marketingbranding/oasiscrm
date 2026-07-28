<?php

namespace App\Http\Requests\Auth;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim($this->string('email')->toString())),
        ]);
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt([
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'account_status' => AccountStatus::Active->value,
        ], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            $user = User::where('email', $this->string('email')->toString())->first();
            if ($user && Hash::check($this->string('password')->toString(), $user->password)) {
                $message = match ($user->account_status) {
                    AccountStatus::Suspended => 'Akun Anda sedang ditangguhkan. Hubungi administrator OASIS.',
                    AccountStatus::Inactive => 'Akun Anda sudah dinonaktifkan. Hubungi administrator OASIS.',
                    default => 'Email atau kata sandi tidak valid. Jika Anda menerima undangan, gunakan tautan aktivasi dari email terbaru.',
                };

                throw ValidationException::withMessages(['email' => $message]);
            }

            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak valid. Jika Anda menerima undangan, gunakan tautan aktivasi dari email terbaru.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
