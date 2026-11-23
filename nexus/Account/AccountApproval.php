<?php

namespace Nexus\Account;

use App\Enums\ModelEventEnum;
use App\Models\Invite;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

final class AccountApproval
{
    public const LINK_LIFETIME_SECONDS = 604800;

    private const ACCOUNT_TYPES = ['signup', 'apply', 'donation'];

    public static function createToken(string $type, int $requestId, string $email): string
    {
        if (!self::isSupportedType($type) || $requestId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid account approval request details.');
        }

        return Crypt::encryptString(json_encode([
            'type' => $type,
            'request_id' => $requestId,
            'email' => $email,
            'expires_at' => now()->timestamp + self::LINK_LIFETIME_SECONDS,
        ], JSON_THROW_ON_ERROR));
    }

    public static function decodeToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 4096) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (
            !is_array($payload)
            || !self::isSupportedType($payload['type'] ?? null)
            || !is_int($payload['request_id'] ?? null)
            || $payload['request_id'] < 1
            || !is_string($payload['email'] ?? null)
            || !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)
            || !is_int($payload['expires_at'] ?? null)
            || $payload['expires_at'] <= now()->timestamp
        ) {
            return null;
        }

        return $payload;
    }

    public static function getPendingRequest(string $type, int $requestId): ?array
    {
        if ($requestId < 1 || !self::isSupportedType($type)) {
            return null;
        }

        if ($type === 'invite') {
            $request = DB::table('invite_registration_requests')
                ->where('id', $requestId)
                ->where('status', 'pending')
                ->first(['username', 'email']);
        } else {
            $request = DB::table('account_requests')
                ->join('users', 'users.id', '=', 'account_requests.user_id')
                ->where('account_requests.id', $requestId)
                ->where('account_requests.type', $type)
                ->where('account_requests.status', 'pending')
                ->where('users.status', User::STATUS_PENDING)
                ->first(['users.username', 'users.email', 'account_requests.plan']);
        }

        if (!$request) {
            return null;
        }

        return [
            'username' => (string) $request->username,
            'email' => (string) $request->email,
            'plan' => $request->plan ?? null,
        ];
    }

    public static function approve(string $type, int $requestId, ?string $expectedEmail = null): ?array
    {
        if (!self::isSupportedType($type) || $requestId < 1) {
            return null;
        }

        return $type === 'invite'
            ? self::approveInvite($requestId, $expectedEmail)
            : self::approveAccountRequest($type, $requestId, $expectedEmail);
    }

    public static function deny(string $type, int $requestId): bool
    {
        if (!self::isSupportedType($type) || $requestId < 1) {
            return false;
        }

        $userId = DB::transaction(function () use ($type, $requestId): ?int {
            $table = $type === 'invite' ? 'invite_registration_requests' : 'account_requests';
            $request = DB::table($table)
                ->where('id', $requestId)
                ->where('status', 'pending')
                ->when($type !== 'invite', fn($query) => $query->where('type', $type))
                ->lockForUpdate()
                ->first();
            if (!$request) {
                return null;
            }

            if ($type === 'invite') {
                DB::table($table)->where('id', $requestId)->update([
                    'status' => 'denied',
                    'passhash' => '',
                    'secret' => '',
                    'updated_at' => now(),
                ]);
                return 0;
            }

            $user = DB::table('users')
                ->where('id', $request->user_id)
                ->where('status', User::STATUS_PENDING)
                ->lockForUpdate()
                ->first();
            if (!$user) {
                return null;
            }

            DB::table('users')->where('id', $request->user_id)->update([
                'enabled' => User::ENABLED_NO,
                'editsecret' => '',
            ]);
            DB::table($table)->where('id', $requestId)->update([
                'status' => 'denied',
                'updated_at' => now(),
            ]);

            return (int) $request->user_id;
        });

        if ($userId === null) {
            return false;
        }
        if ($userId > 0) {
            clear_user_cache($userId);
        }

        return true;
    }

    public static function complete(array $approval): void
    {
        if (($approval['type'] ?? null) === 'invite') {
            self::completeInviteApproval($approval);
            return;
        }

        $userId = (int) ($approval['user_id'] ?? 0);
        clear_user_cache($userId);
        publish_model_event(ModelEventEnum::USER_UPDATED, $userId);
    }

    private static function approveAccountRequest(string $type, int $requestId, ?string $expectedEmail): ?array
    {
        return DB::transaction(function () use ($type, $requestId, $expectedEmail): ?array {
            $request = DB::table('account_requests')
                ->where('id', $requestId)
                ->where('type', $type)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if (!$request) {
                return null;
            }

            $user = DB::table('users')
                ->where('id', $request->user_id)
                ->lockForUpdate()
                ->first();
            if (!$user || $user->status !== User::STATUS_PENDING) {
                return null;
            }
            if ($expectedEmail !== null && !hash_equals($expectedEmail, (string) $user->email)) {
                return null;
            }

            $userUpdate = [
                'status' => User::STATUS_CONFIRMED,
                'enabled' => User::ENABLED_YES,
                'editsecret' => '',
            ];
            if ($type === 'donation') {
                $uploadGigabytes = match ($request->plan) {
                    '30days' => 50,
                    'lifetime' => 500,
                    default => null,
                };
                if ($uploadGigabytes === null) {
                    return null;
                }
                $userUpdate['class'] = User::CLASS_VIP;
                $userUpdate['vip_added'] = 'yes';
                $userUpdate['vip_until'] = $request->plan === '30days' ? now()->addDays(30) : null;
                $userUpdate['uploaded'] = (int) getsize_int($uploadGigabytes, 'G');
            } elseif ($type === 'apply') {
                $userUpdate['uploaded'] = (int) getsize_int(50, 'G');
            }

            DB::table('users')->where('id', $request->user_id)->update($userUpdate);
            DB::table('account_requests')->where('id', $requestId)->update([
                'status' => 'accepted',
                'updated_at' => now(),
            ]);

            return ['type' => $type, 'user_id' => (int) $request->user_id];
        });
    }

    private static function approveInvite(int $requestId, ?string $expectedEmail): ?array
    {
        global $defaultclass_class, $invite_count;

        return DB::transaction(function () use ($requestId, $expectedEmail, $defaultclass_class, $invite_count): ?array {
            $request = DB::table('invite_registration_requests')
                ->where('id', $requestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if (!$request) {
                return null;
            }
            if ($expectedEmail !== null && !hash_equals($expectedEmail, (string) $request->email)) {
                return null;
            }

            $invite = DB::table('invites')
                ->where('id', $request->invite_id)
                ->lockForUpdate()
                ->first();
            if (!$invite) {
                return null;
            }

            $usernameOrEmailExists = DB::table('users')
                ->where('username', $request->username)
                ->orWhere('email', $request->email)
                ->exists();
            if ($usernameOrEmailExists) {
                throw new DomainException('The requested username or email is now in use.');
            }

            $now = now()->toDateTimeString();
            $userId = DB::table('users')->insertGetId([
                'username' => $request->username,
                'email' => $request->email,
                'passhash' => $request->passhash,
                'secret' => $request->secret,
                'auth_key' => bin2hex(random_bytes(32)),
                'editsecret' => '',
                'status' => User::STATUS_CONFIRMED,
                'class' => (int) $defaultclass_class,
                'invites' => (int) $invite_count,
                'invited_by' => (int) $invite->inviter,
                'added' => $now,
                'last_access' => $now,
                'ip' => $request->ip,
                'lang' => (int) $request->lang,
                'stylesheet' => (int) $request->stylesheet,
                'uploaded' => (int) getsize_int(100, 'G'),
                'passkey' => md5($request->username . $now . $request->passhash),
            ]);

            DB::table('invites')->where('id', $invite->id)->update([
                'valid' => Invite::VALID_NO,
                'invitee_register_uid' => $userId,
                'invitee_register_email' => $request->email,
                'invitee_register_username' => $request->username,
            ]);
            DB::table('invite_registration_requests')->where('id', $requestId)->update([
                'status' => 'accepted',
                'passhash' => '',
                'secret' => '',
                'updated_at' => now(),
            ]);

            return [
                'type' => 'invite',
                'user_id' => (int) $userId,
                'inviter_id' => (int) $invite->inviter,
            ];
        });
    }

    private static function completeInviteApproval(array $approval): void
    {
        global $Cache;

        $userId = (int) ($approval['user_id'] ?? 0);
        $inviterId = (int) ($approval['inviter_id'] ?? 0);
        $userInfo = User::query()->findOrFail($userId);
        fire_event('user_created', $userInfo);

        $temporaryInviteCount = (int) get_setting('main.tmp_invite_count');
        if ($temporaryInviteCount > 0) {
            (new \App\Repositories\UserRepository())->addTemporaryInvite(null, $userId, 'increment', $temporaryInviteCount, 7);
        }

        Message::add([
            'sender' => 0,
            'receiver' => $userId,
            'subject' => Setting::getSiteName() . ' registration approved',
            'added' => now()->toDateTimeString(),
            'msg' => 'Your invite registration has been approved and your account is active.',
        ]);
        Message::add([
            'sender' => 0,
            'receiver' => $inviterId,
            'subject' => Setting::getSiteName() . ' invite registration approved',
            'added' => now()->toDateTimeString(),
            'msg' => $userInfo->username . ' has had their invite registration approved.',
        ]);

        $Cache->delete_value('user_' . $inviterId . '_unread_message_count');
        $Cache->delete_value('user_' . $inviterId . '_inbox_count');
    }

    private static function isSupportedType(mixed $type): bool
    {
        return $type === 'invite' || (is_string($type) && in_array($type, self::ACCOUNT_TYPES, true));
    }
}
