<?php

namespace App\Platform\Shared\Catalog\Contracts;

interface CourseLookupPort
{
    /**
     * Resolve a published course by public id without exposing Catalog's Eloquent model.
     *
     * @return array{id:int, public_id:string, title:string}|null
     */
    public function publishedCourseByPublicId(string $publicId): ?array;
}
