<?php

namespace App\Platform\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only snapshot of a BlogPost's fields at a point in time. Rows are created by
 * BlogPost::recordVersion() on every post update and are never mutated. The admin version-history
 * relation manager lists these and can restore any of them via BlogPost::rollbackTo().
 *
 * @property int $id
 * @property int $blog_post_id
 * @property int $version
 * @property array<string, mixed> $snapshot
 * @property int|null $author_id
 * @property Carbon|null $created_at
 */
class BlogPostVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['blog_post_id', 'version', 'snapshot', 'author_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BlogPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }
}
