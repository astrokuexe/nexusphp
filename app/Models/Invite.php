<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class Invite extends NexusModel
{
    protected $table = 'invites';

    const VALID_YES = 1;
    const VALID_NO = 0;

    const TEMPORARY_INVITE_VALID_DAYS = 7;

    protected $casts = [
        'expired_at' => 'datetime',
    ];

    public static $validInfo = [
        self::VALID_NO => ['text' => 'No'],
        self::VALID_YES => ['text' => 'Yes'],
    ];

    protected $fillable = [
        'inviter', 'invitee', 'hash', 'time_invited', 'valid',
        'invitee_register_uid', 'invitee_register_email', 'invitee_register_username',
        'pre_register_email', 'pre_register_username',
    ];

    public static function hasExpired(CarbonInterface|string|null $expiredAt): bool
    {
        return $expiredAt !== null && Carbon::parse($expiredAt)->lte(now());
    }

    public static function isUnissued(?string $timeInvited): bool
    {
        return $timeInvited === null || $timeInvited === '' || $timeInvited === '0000-00-00 00:00:00';
    }

    public function scopeUnissued(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('time_invited')
                ->orWhere('time_invited', '=', 0);
        });
    }

    public function getValidTextAttribute()
    {
        return self::$validInfo[$this->valid]['text'] ?? '';
    }

    public function inviter_user()
    {
        return $this->belongsTo(User::class, 'inviter');
    }

    public function invitee_user()
    {
        return $this->belongsTo(User::class, 'invitee_register_uid');
    }

}
