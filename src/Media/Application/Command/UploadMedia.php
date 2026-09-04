<?php

declare(strict_types=1);

namespace Wob\Media\Application\Command;

use Illuminate\Http\UploadedFile;
use Wob\Library\Domain\ValueObject\OwnerId;

final readonly class UploadMedia
{
    public function __construct(
        public OwnerId $owner,
        public UploadedFile $file,
    ) {
    }
}
