<?php

namespace App\Platform\Identity\Services;

use App\Platform\Identity\Enums\OtpChannel;
use App\Platform\Identity\Exceptions\ExpiredOtpException;
use App\Platform\Identity\Exceptions\InvalidOtpException;
use App\Platform\Identity\Exceptions\OtpNotFoundException;
use App\Platform\Identity\Exceptions\OtpRateLimitedException;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserOtp;
use App\Platform\Identity\Notifications\EmailOtpNotification;
use App\Platform\Identity\Notifications\PhoneOtpNotification;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Facades\Log;

/**
 * Issues and verifies one-time codes. The DB is authoritative: codes are stored hashed,
 * time-boxed, rate-limited, and verified under a row lock to prevent double-consume/guess.
 */
class OtpService extends BaseService
{
    /** Generate, persist (hashed) and dispatch an OTP for the given channel. */
    public function send(User $user, OtpChannel $channel, string $destination): void
    {
        $config = (array) config($channel->configKey());
        $this->assertWithinRateLimit($user, $channel, (int) $config['max_per_hour']);

        $code = $this->generateCode((int) $config['length']);

        $this->transaction(function () use ($user, $channel, $destination, $code, $config): void {
            UserOtp::create([
                'user_id' => $user->id,
                'channel' => $channel->value,
                'destination' => $destination,
                'code_hash' => hash('sha256', $code),
                'expires_at' => now()->addMinutes((int) $config['ttl_minutes']),
                'attempts' => 0,
            ]);
        });

        // Dispatched after commit (notifications are queued with afterCommit).
        match ($channel) {
            OtpChannel::Email => $user->notify(new EmailOtpNotification($code)),
            OtpChannel::Sms => $this->sendSms($user, $code),
        };
    }

    /** Verify a submitted code. Throws typed exceptions on failure; consumes it on success. */
    public function verify(User $user, OtpChannel $channel, string $destination, string $code): void
    {
        $this->transaction(function () use ($user, $channel, $destination, $code): void {
            $otp = UserOtp::query()
                ->where('user_id', $user->id)
                ->where('channel', $channel->value)
                ->where('destination', $destination)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null) {
                throw new OtpNotFoundException;
            }

            if ($otp->isExpired()) {
                throw new ExpiredOtpException;
            }

            if (! hash_equals($otp->code_hash, hash('sha256', $code))) {
                $otp->increment('attempts');

                // Enforce the per-code guess ceiling: the `attempts` column was recorded but never
                // acted on, so a code was brute-forceable until it expired. Once the ceiling is hit,
                // burn the code (consume it) so no further guesses against it are possible.
                $maxAttempts = max(1, (int) config('identity.otp.max_verify_attempts', 5));
                if ((int) $otp->getAttribute('attempts') >= $maxAttempts) {
                    $otp->forceFill(['consumed_at' => now()])->save();
                }

                throw new InvalidOtpException;
            }

            $otp->forceFill(['consumed_at' => now()])->save();
        });
    }

    private function assertWithinRateLimit(User $user, OtpChannel $channel, int $maxPerHour): void
    {
        $recent = UserOtp::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel->value)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recent >= $maxPerHour) {
            throw new OtpRateLimitedException(3600);
        }
    }

    private function generateCode(int $length): string
    {
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function sendSms(User $user, string $code): void
    {
        // No SMS provider is wired at this stage (never send real SMS). The queued
        // PhoneOtpNotification has no live channel yet; log in local for debugging only.
        $user->notify(new PhoneOtpNotification($code));

        if (app()->environment('local')) {
            Log::info('Phone OTP (local only)', ['user_id' => $user->id, 'code' => $code]);
        }
    }
}
