<?php

namespace App\Enums;

/**
 * Where a stored picture (product photo, shop banner, user photo) came from, beside its `*_source_url`.
 * GiftNest's docs/photos-from-links.md explains it; Kheengz writes its own with the products module.
 *
 *  - Upload:   a file from someone's device (or a client's pack); the file is ours.
 *  - Link:     an image link we downloaded once; the file is ours, `source_url` keeps the address it came from.
 *  - External: an address we do not hold and only display (seeded demo pictures); the path is that address.
 */
enum PhotoSource: string
{
    case Upload = 'Upload';
    case Link = 'Link';
    case External = 'External';
}
