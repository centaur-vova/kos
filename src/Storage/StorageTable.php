<?php

declare(strict_types=1);

namespace App\Storage;

use Swoole\Table;

final class StorageTable extends Table
{
    public function __construct(int $size)
    {
        parent::__construct($size);
        $this->column('value', self::TYPE_STRING, 255);
        $this->column('ttl', self::TYPE_INT, 4);
        $this->create();
    }
}
