<?php
namespace App\Trait;


use Hyperf\Context\Context;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\env;

trait SchemaTableTrait
{
    public function setSchema(): string
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

    protected function schemaExists(string $schema): bool
    {
        return (bool) Db::selectOne(
        /** @lang text */ "SELECT 1 FROM information_schema.schemata WHERE schema_name = ?",
            [$schema]
        );
    }
}