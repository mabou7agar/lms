<?php

namespace App\Domains\Authoring\Enums;

/**
 * P2/W02 - Coarse grouping for content block types, mirroring the frontend block-registry
 * categories (src/lib/authoring/block-registry.ts).
 */
enum BlockFamily: string
{
    case Content = 'content';
    case Media = 'media';
    case Interactive = 'interactive';
    case Package = 'package';
    case Engagement = 'engagement';
}
