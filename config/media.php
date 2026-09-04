<?php

declare(strict_types=1);

return [
    /*
     * Which filesystem disk holds uploaded covers and intros.
     *
     * 'local' keeps them under storage/app, which is right for one server and
     * wrong for two: a video uploaded to one machine is a 404 on the other.
     * When that day comes, point this at an s3 disk in config/filesystems.php.
     * Nothing else has to change.
     */
    'disk' => env('MEDIA_DISK', 'local'),
];
