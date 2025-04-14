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

use Hyperf\Context\Context;
use Hyperf\DbConnection\Db;
use Hyperf\DbConnection\Model\Model as BaseModel;
use function Hyperf\Support\env;

abstract class Model extends BaseModel
{
    public function getTable(): string
    {
        $tenant = Context::get('current_tenant');
        $schema = $tenant['schema'] ?? 'public';

        // 自动处理表前缀
        $prefix = env('DB_SCHEMA_PREFIX');
        if ($this->schemaExists($schema) === false) {
            throw new \Exception("schema不存在");
        }

        return "{$schema}.{$prefix}{$this->getRawTableName()}";
    }

    protected function getRawTableName(): string
    {
        return $this->table;
    }

    public function schemaExists(string $schema): bool
    {
        return (bool) Db::selectOne(
            /** @lang text */ "SELECT 1 FROM information_schema.schemata WHERE schema_name = ?",
            [$schema]
        );
    }
}
