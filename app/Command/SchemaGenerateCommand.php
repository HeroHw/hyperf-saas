<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Types\Type;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Config\Annotation\Value;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Command]
class SchemaGenerateCommand extends HyperfCommand
{
    protected ?string $signature = 'schema:generate {--table= : Generate migration for specific table} {--all : Generate migrations for all tables} {--connection=default : Database connection to use} {--schema=public : PostgreSQL schema to filter tables (default: public)}';

    protected string $description = 'Generate migration files from existing database schema';
    
    // 声明identityColumns属性
    protected array $identityColumns = [];

    public function __construct(
        protected ContainerInterface $container,
        protected ConfigInterface $config
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $table = $this->input->getOption('table');
        $all = $this->input->getOption('all');
        $connectionName = $this->input->getOption('connection') ?? 'default';

        if (!$table && !$all) {
            $this->output->error('Please specify either --table=table_name or --all option');
            return 1;
        }

        try {
            $connection = $this->getDBALConnection($connectionName);
            $schemaManager = $connection->createSchemaManager();

            if ($all) {
                $this->generateAllTables($schemaManager);
            } else {
                $this->generateSingleTable($schemaManager, $table);
            }

            return 0;
        } catch (\Exception $e) {
            $this->output->error('Error: ' . $e->getMessage());
            return 1;
        }
    }

    protected function getDBALConnection(string $connectionName): Connection
    {
        $dbConfig = $this->config->get("databases.{$connectionName}");
        
        if (!$dbConfig) {
            throw new \InvalidArgumentException("Database connection '{$connectionName}' not found");
        }

        $connectionParams = [
            'dbname' => $dbConfig['database'],
            'user' => $dbConfig['username'],
            'password' => $dbConfig['password'],
            'host' => $dbConfig['host'],
            'port' => $dbConfig['port'],
            'driver' => $this->mapDriver($dbConfig['driver']),
            'charset' => $dbConfig['charset'] ?? 'utf8',
        ];

        $connection = DriverManager::getConnection($connectionParams);
        
        // 为PostgreSQL注册自定义类型映射
        if ($dbConfig['driver'] === 'pgsql') {
            $this->registerPostgreSQLTypes($connection);
        }
        
        return $connection;
    }

    /**
     * 注册PostgreSQL特殊类型
     */
    protected function registerPostgreSQLTypes(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        
        // PostgreSQL数组类型映射
        $arrayTypeMappings = [
            '_int8' => 'bigint',     // bigint[]
            '_int4' => 'integer',    // integer[]
            '_int2' => 'smallint',   // smallint[]
            '_text' => 'text',       // text[]
            '_varchar' => 'string',  // varchar[]
            '_bool' => 'boolean',    // boolean[]
            '_float8' => 'float',    // float[]
            '_numeric' => 'decimal', // decimal[]
            '_timestamp' => 'datetime', // timestamp[]
            '_date' => 'date',       // date[]
            '_time' => 'time',       // time[]
            '_json' => 'json',       // json[]
            '_jsonb' => 'json',      // jsonb[]
        ];
        
        foreach ($arrayTypeMappings as $pgType => $mappedType) {
            $platform->registerDoctrineTypeMapping($pgType, $mappedType);
        }
        
        // PostgreSQL特殊类型映射
        $specialTypeMappings = [
            'int8' => 'bigint',
            'int4' => 'integer', 
            'int2' => 'smallint',
            'float8' => 'float',
            'float4' => 'float',
            'numeric' => 'decimal',
            'varchar' => 'string',
            'bpchar' => 'string',
            'timestamptz' => 'datetime',
            'timetz' => 'time',
            'jsonb' => 'json',
            'uuid' => 'string',
            'inet' => 'string',
            'cidr' => 'string',
            'macaddr' => 'string',
            'bytea' => 'text',
        ];
        
        foreach ($specialTypeMappings as $pgType => $mappedType) {
            $platform->registerDoctrineTypeMapping($pgType, $mappedType);
        }
    }

    protected function mapDriver(string $driver): string
    {
        return match ($driver) {
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            default => throw new \InvalidArgumentException("Unsupported driver: {$driver}")
        };
    }

    protected function generateAllTables(AbstractSchemaManager $schemaManager): void
    {
        $tables = $schemaManager->listTableNames();
        $schemaName = $this->input->getOption('schema') ?? 'public';
        $connectionName = $this->input->getOption('connection') ?? 'default';
        $dbConfig = $this->config->get("databases.{$connectionName}");
        
        // 如果是PostgreSQL数据库，按schema过滤表
        if ($dbConfig['driver'] === 'pgsql') {
            try {
                $connection = $this->getDBALConnection($connectionName);
                $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'";
                $stmt = $connection->executeQuery($sql, [$schemaName]);
                $schemaTables = $stmt->fetchFirstColumn();
                
                // 只保留指定schema中的表
                $tables = array_filter($tables, function($tableName) use ($schemaTables) {
                    return in_array($tableName, $schemaTables);
                });
            } catch (\Exception $e) {
                $this->output->warning("Failed to filter tables by schema: {$e->getMessage()}");
            }
        }
        
        foreach ($tables as $tableName) {
            $this->generateSingleTable($schemaManager, $tableName);
        }

        $this->output->success(sprintf('Generated migrations for %d tables', count($tables)));
    }

    protected function generateSingleTable(AbstractSchemaManager $schemaManager, string $tableName): void
    {
        if (!$schemaManager->tablesExist([$tableName])) {
            $this->output->error("Table '{$tableName}' does not exist");
            return;
        }

        // 检查表是否在指定的schema中（仅PostgreSQL）
        $connectionName = $this->input->getOption('connection') ?? 'default';
        $dbConfig = $this->config->get("databases.{$connectionName}");
        $schemaName = $this->input->getOption('schema') ?? 'public';
        
        if ($dbConfig['driver'] === 'pgsql') {
            try {
                $connection = $this->getDBALConnection($connectionName);
                $sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ? AND table_type = 'BASE TABLE'";
                $count = $connection->executeQuery($sql, [$schemaName, $tableName])->fetchOne();
                
                if ($count == 0) {
                    $this->output->warning("Table '{$tableName}' does not exist in schema '{$schemaName}'");
                    return;
                }
            } catch (\Exception $e) {
                // 如果查询失败，继续执行，不进行schema过滤
                $this->output->warning("Failed to check schema: {$e->getMessage()}");
            }
        }

        $table = $schemaManager->introspectTable($tableName);
        $timestamp = date('Y_m_d_His');
        
        // 移除表前缀，只保留核心表名
        $cleanTableName = $this->removeTablePrefix($tableName);
        $className = $this->getClassName($cleanTableName);
        $fileName = "{$timestamp}_create_{$cleanTableName}_table.php";
        $filePath = BASE_PATH . "/migrations/{$fileName}";
    
        // 传递清理后的表名给迁移内容生成
        $migrationContent = $this->generateMigrationContent($className, $cleanTableName, $table, $schemaManager);
    
        if (!is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        file_put_contents($filePath, $migrationContent);
        $this->output->success("Created migration: {$filePath}");
    }

    /**
     * 移除表前缀
     */
    protected function removeTablePrefix(string $tableName): string
    {
        // 获取数据库配置中的表前缀
        $connectionName = $this->input->getOption('connection') ?? 'default';
        $dbConfig = $this->config->get("databases.{$connectionName}");
        $prefix = $dbConfig['prefix'] ?? '';
        
        // 如果有前缀且表名以前缀开头，则移除前缀
        if ($prefix && strpos($tableName, $prefix) === 0) {
            return substr($tableName, strlen($prefix));
        }
        
        // 如果没有配置前缀，尝试自动检测常见前缀模式
        $commonPrefixes = ['la_', 'app_', 'sys_', 'tbl_'];
        foreach ($commonPrefixes as $commonPrefix) {
            if (strpos($tableName, $commonPrefix) === 0) {
                return substr($tableName, strlen($commonPrefix));
            }
        }
        
        return $tableName;
    }

    protected function getClassName(string $tableName): string
    {
        return 'Create' . str_replace('_', '', ucwords($tableName, '_')) . 'Table';
    }

    protected function generateMigrationContent(string $className, string $tableName, \Doctrine\DBAL\Schema\Table $table, AbstractSchemaManager $schemaManager): string
    {
        $fields = [];
        $indexes = [];
        $foreignKeys = [];
        $this->identityColumns = []; // 初始化数组
        $connectionName = $this->input->getOption('connection') ?? 'default';
        $dbConfig = $this->config->get("databases.{$connectionName}");
        $prefix = $dbConfig['prefix'] ?? '';

        // 获取表注释
        $tableComment = $this->getTableComment($tableName);

        // Generate field definitions
        foreach ($table->getColumns() as $column) {
            $fields[] = $this->generateColumnDefinition($column);
        }

        // Generate index definitions
        foreach ($table->getIndexes() as $index) {
            if (!$index->isPrimary()) {
                $indexes[] = $this->generateIndexDefinition($index);
            }
        }

        // Generate foreign key definitions
        foreach ($table->getForeignKeys() as $foreignKey) {
            $foreignKeys[] = $this->generateForeignKeyDefinition($foreignKey);
        }

        $fieldsCode = implode("\n            ", $fields);
        $indexesCode = $indexes ? "\n            " . implode("\n            ", $indexes) : '';
        $foreignKeysCode = $foreignKeys ? "\n            " . implode("\n            ", $foreignKeys) : '';
        
        // 添加PostgreSQL IDENTITY列的处理 - 使用与Schema::create相同的表名
        $identityStatementsCode = '';
        if (!empty($this->identityColumns)) {
            $statements = [];
            foreach ($this->identityColumns as $column) {
                // 这里使用与Schema::create相同的表名，确保一致性
                $statements[] = "DB::statement('ALTER TABLE {$prefix}{$tableName} ALTER COLUMN {$column['name']} ADD GENERATED BY DEFAULT AS IDENTITY');";
            }
            $identityStatementsCode = "\n\n        // 设置IDENTITY列\n        " . implode("\n        ", $statements);
        }
        
        // 添加表注释的处理
        $tableCommentCode = '';
        if ($tableComment) {
            $tableCommentCode = "\n\n        // 设置表注释\n        DB::statement(\"COMMENT ON TABLE {$tableName} IS '{$tableComment}'\");";
        }

        return <<<PHP
<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class {$className} extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            {$fieldsCode}{$indexesCode}{$foreignKeysCode}
        });{$identityStatementsCode}{$tableCommentCode}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
}
PHP;
    }

    /**
     * 获取表注释
     */
    protected function getTableComment(string $tableName): ?string
    {
        try {
            $connectionName = $this->input->getOption('connection') ?? 'default';
            $connection = $this->getDBALConnection($connectionName);
            $dbConfig = $this->config->get("databases.{$connectionName}");
            
            // 获取完整的表名（包含前缀）
            $fullTableName = $tableName;
            $prefix = $dbConfig['prefix'] ?? '';
            if ($prefix) {
                $fullTableName = $prefix . $tableName;
            } else {
                // 如果没有配置前缀，尝试自动检测
                $commonPrefixes = ['la_', 'app_', 'sys_', 'tbl_'];
                foreach ($commonPrefixes as $commonPrefix) {
                    if (strpos($tableName, $commonPrefix) === 0) {
                        $fullTableName = $tableName;
                        break;
                    }
                }
            }
            
            if ($dbConfig['driver'] === 'pgsql') {
                // PostgreSQL 查询表注释
                $schemaName = $this->input->getOption('schema') ?? 'public';
                $sql = "SELECT obj_description(c.oid) as comment 
                        FROM pg_class c 
                        JOIN pg_namespace n ON n.oid = c.relnamespace 
                        WHERE c.relname = ? AND n.nspname = ?";
                $result = $connection->executeQuery($sql, [$fullTableName, $schemaName]);
                $comment = $result->fetchOne();
                return $comment ?: null;
            } elseif ($dbConfig['driver'] === 'mysql') {
                // MySQL 查询表注释
                $sql = "SELECT table_comment 
                        FROM information_schema.tables 
                        WHERE table_schema = ? AND table_name = ?";
                $result = $connection->executeQuery($sql, [$dbConfig['database'], $fullTableName]);
                $comment = $result->fetchOne();
                return $comment ?: null;
            }
            
            return null;
        } catch (\Exception $e) {
            $this->output->warning("Failed to get table comment for '{$tableName}': {$e->getMessage()}");
            return null;
        }
    }

    protected function generateColumnDefinition(\Doctrine\DBAL\Schema\Column $column): string
    {
        $name = $column->getName();
        $type = $column->getType();
        $length = $column->getLength();
        $precision = $column->getPrecision();
        $scale = $column->getScale();
        $unsigned = $column->getUnsigned();
        $nullable = !$column->getNotnull();
        $default = $column->getDefault();
        $autoIncrement = $column->getAutoincrement();
        $comment = $column->getComment();

        $definition = '$table->';

        // Map DBAL types to Hyperf Blueprint methods
        $typeMapping = [
            'integer' => 'integer',
            'bigint' => 'bigInteger',
            'smallint' => 'smallInteger',
            'string' => 'string',
            'text' => 'text',
            'boolean' => 'boolean',
            'datetime' => 'dateTime',
            'date' => 'date',
            'time' => 'time',
            'timestamp' => 'timestamp',
            'decimal' => 'decimal',
            'float' => 'float',
            'json' => 'json',
        ];

        // 使用反射获取类型名称，兼容不同版本的 Doctrine DBAL
        $typeName = $this->getTypeName($type);
        $blueprintMethod = $typeMapping[$typeName] ?? 'string';

        // 处理自增字段 - 使用GENERATED BY DEFAULT AS IDENTITY
        if ($autoIncrement && in_array($typeName, ['integer', 'bigint'])) {
            // 对于自增字段，我们先创建普通字段，然后在迁移中添加IDENTITY属性
            $definition .= "{$blueprintMethod}('{$name}')";
            // 标记这是一个需要IDENTITY的字段
            $this->identityColumns[] = [
                'name' => $name,
                'type' => $typeName === 'bigint' ? 'BIGINT' : 'INTEGER'
            ];
        } else {
            $definition .= "{$blueprintMethod}('{$name}'";
            
            if ($length && in_array($typeName, ['string', 'char'])) {
                $definition .= ", {$length}";
            } elseif ($precision && $scale && $typeName === 'decimal') {
                $definition .= ", {$precision}, {$scale}";
            }
            
            $definition .= ')';
        }

        if ($unsigned && in_array($typeName, ['integer', 'bigint', 'smallint']) && !$autoIncrement) {
            $definition .= '->unsigned()';
        }

        if ($nullable && !$autoIncrement) {
            $definition .= '->nullable()';
        }

        // 自增字段不设置默认值
        if ($default !== null && !$autoIncrement) {
            // 检查默认值是否为序列名称（PostgreSQL特有）
            if (is_string($default) && (strpos($default, '_seq') !== false || strpos($default, 'nextval') !== false)) {
                // 跳过序列相关的默认值
            } elseif (is_string($default)) {
                $definition .= "->default('{$default}')";
            } elseif (is_bool($default)) {
                $definition .= '->default(' . ($default ? 'true' : 'false') . ')';
            } else {
                $definition .= "->default({$default})";
            }
        }

        if ($comment) {
            $definition .= "->comment('{$comment}')";
        }

        return $definition . ';';
    }

    /**
     * 获取数据库类型名称，兼容不同版本的 Doctrine DBAL
     */
    protected function getTypeName(\Doctrine\DBAL\Types\Type $type): string
    {
        // 尝试使用新版本的方法
        // if (method_exists($type, 'getName')) {
        //     return $type->getName();
        // }
        
        // 使用反射获取类名并转换为类型名称
        $className = get_class($type);
        $shortName = (new \ReflectionClass($className))->getShortName();
        
        // 移除 'Type' 后缀并转换为小写
        $typeName = strtolower(str_replace('Type', '', $shortName));
        
        // 处理特殊情况的映射
        $typeMap = [
            'bigint' => 'bigint',
            'smallint' => 'smallint', 
            'string' => 'string',
            'text' => 'text',
            'boolean' => 'boolean',
            'datetime' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'timestamp' => 'timestamp',
            'decimal' => 'decimal',
            'float' => 'float',
            'json' => 'json',
            'integer' => 'integer',
            // PostgreSQL特殊类型处理
            'array' => 'json',  // 将数组类型映射为JSON
            'simple_array' => 'json',
            'guid' => 'string',
            'binary' => 'text',
            'blob' => 'text',
        ];
        
        return $typeMap[$typeName] ?? 'string';
    }

    protected function generateIndexDefinition(\Doctrine\DBAL\Schema\Index $index): string
    {
        $columns = $index->getColumns();
        $name = $index->getName();
        
        if ($index->isUnique()) {
            if (count($columns) === 1) {
                return "\$table->unique('{$columns[0]}');";
            } else {
                $columnsList = "['" . implode("', '", $columns) . "']";
                return "\$table->unique({$columnsList}, '{$name}');";
            }
        } else {
            if (count($columns) === 1) {
                return "\$table->index('{$columns[0]}');";
            } else {
                $columnsList = "['" . implode("', '", $columns) . "']";
                return "\$table->index({$columnsList}, '{$name}');";
            }
        }
    }

    protected function generateForeignKeyDefinition(\Doctrine\DBAL\Schema\ForeignKeyConstraint $foreignKey): string
    {
        $localColumns = $foreignKey->getLocalColumns();
        $foreignTable = $foreignKey->getForeignTableName();
        $foreignColumns = $foreignKey->getForeignColumns();
        
        if (count($localColumns) === 1 && count($foreignColumns) === 1) {
            $definition = "\$table->foreign('{$localColumns[0]}')->references('{$foreignColumns[0]}')->on('{$foreignTable}')";
            
            $onDelete = $foreignKey->onDelete();
            $onUpdate = $foreignKey->onUpdate();
            
            if ($onDelete) {
                $definition .= "->onDelete('{$onDelete}')";
            }
            
            if ($onUpdate) {
                $definition .= "->onUpdate('{$onUpdate}')";
            }
            
            return $definition . ';';
        }
        
        return "// Complex foreign key: {$foreignKey->getName()}";
    }
}