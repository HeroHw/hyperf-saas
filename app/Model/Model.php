<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Model;

use App\Trait\SchemaTableTrait;
use Hyperf\Database\Model\SoftDeletes;
use Hyperf\DbConnection\Model\Model as BaseModel;

abstract class Model extends BaseModel
{
    use SchemaTableTrait;

    public bool $timestamps = true;
    const CREATED_AT = 'create_time';
    const UPDATED_AT = 'update_time';

    protected array $casts = [
        self::CREATED_AT => 'datetime:Y-m-d H:i:s',
        self::UPDATED_AT => 'datetime:Y-m-d H:i:s',
    ];
    public function getTable(): string
    {
        return $this->setSchema();
    }
}
