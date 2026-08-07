<?php

namespace App\Platform\Identity\Models;

use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A verified link between a local user and an external identity provider account.
 *
 * The unique (provider, provider_subject) pair is the stable key a returning social login is matched
 * on — never the email, which can change. Owned by Identity (the only layer permitted to touch User).
 */
class SocialAccount extends Model
{
    use HasPublicId;

    protected $fillable = [
        'user_id', 'provider', 'provider_subject', 'email',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
