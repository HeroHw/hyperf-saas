<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class SchemaDiffCommand extends HyperfCommand
{
    protected ?string $signature = 'schema:diff {--connection=default : Database connection to use} {--table= : Compare specific table only}';

    protected string $description = 'Generate migration files for database schema differences';

    public function __construct(
        protected ContainerInterface $container,
        protected ConfigInterface $config
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $connectionName = $this->input->getOption('connection') ?? 'default';
        $tableName = $this->input->getOption('table');

        try {
            $connection = $this->getDBALConnection($connectionName);
            $schemaManager = $connection->createSchemaManager();
            
            $currentSchema = $schemaManager->introspectSchema();
            // 修复：传入 Connection 对象而不是 SchemaManager
            $expectedSchema = $this->buildExpectedSchema($connection);
            
            // 修复：传入 Platform 参数给 Comparator 构造函数
            $comparator = new Comparator($connection->getDatabasePlatform());
            $schemaDiff = $comparator->compareSchemas($expectedSchema, $currentSchema);
            
            if ($tableName) {
                $this->generateTableDiff($schemaDiff, $tableName);
            } else {
                $this->generateAllDiffs($schemaDiff);
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

        return DriverManager::getConnection($connectionParams);
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

    protected function buildExpectedSchema(Connection $connection): Schema
    {
        $schema = new Schema();
        // 直接使用传入的 Connection 对象
        
        try {
            // 查询已执行的迁移文件
            $executedMigrations = $connection->fetchAllAssociative(
                "SELECT migration FROM la_migrations ORDER BY batch, id"
            );
            
            $migrationsPath = BASE_PATH . '/migrations';
            
            // 按顺序执行迁移来构建预期的 schema
            foreach ($executedMigrations as $migration) {
                $migrationName = $migration['migration'];
                $migrationFile = $migrationsPath . '/' . $migrationName . '.php';
                
                if (file_exists($migrationFile)) {
                    $this->simulateMigration($migrationFile, $schema);
                }
            }
            
        } catch (\Exception $e) {
            $this->output->warning("Warning: Could not read migrations table: " . $e->getMessage());
            return new Schema();
        }
        
        return $schema;
    }

    /**
     * 模拟执行迁移文件来构建 schema
     */
    protected function simulateMigration(string $filePath, Schema $schema): void
    {
        $content = file_get_contents($filePath);
        
        // 解析 Schema::create 操作
        if (preg_match('/Schema::create\([\'"]([^\'"]*)[\'"]/i', $content, $matches)) {
            $tableName = $matches[1];
            $this->parseCreateTable($content, $tableName, $schema);
        }
        
        // 解析 Schema::table 操作（ALTER TABLE）
        if (preg_match('/Schema::table\([\'"]([^\'"]*)[\'"]/i', $content, $matches)) {
            $tableName = $matches[1];
            $this->parseAlterTable($content, $tableName, $schema);
        }
        
        // 解析 Schema::drop 操作
        if (preg_match('/Schema::drop(?:IfExists)?\([\'"]([^\'"]*)[\'"]/i', $content, $matches)) {
            $tableName = $matches[1];
            if ($schema->hasTable($tableName)) {
                $schema->dropTable($tableName);
            }
        }
    }

    /**
     * 解析 CREATE TABLE 语句
     */
    protected function parseCreateTable(string $content, string $tableName, Schema $schema): void
    {
        $table = $schema->createTable($tableName);
        
        // 解析列定义
        $this->parseColumns($content, $table);
        
        // 解析索引
        $this->parseIndexes($content, $table);
    }

    /**
     * 解析 ALTER TABLE 语句
     */
    protected function parseAlterTable(string $content, string $tableName, Schema $schema): void
    {
        if (!$schema->hasTable($tableName)) {
            return;
        }
        
        $table = $schema->getTable($tableName);
        
        // 解析添加列
        $this->parseAddColumns($content, $table);
        
        // 解析删除列
        $this->parseDropColumns($content, $table);
        
        // 解析修改列
        $this->parseModifyColumns($content, $table);
        
        // 解析索引变更
        $this->parseIndexChanges($content, $table);
    }

    /**
     * 解析列定义
     */
    protected function parseColumns(string $content, $table): void
    {
        // 匹配各种列定义模式
        $patterns = [
            // $table->string('name', 100)->nullable()->comment('用户名')
            '/\$table->([a-zA-Z]+)\([\'"]([^\'"]*)[\'"](,\s*\d+)?\)([^;]*);/m',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $columnType = $match[1];
                    $columnName = $match[2];
                    $length = isset($match[3]) ? (int)trim($match[3], ', ') : null;
                    $modifiers = $match[4] ?? '';
                    
                    $this->addColumnToTable($table, $columnName, $columnType, $length, $modifiers);
                }
            }
        }
    }

    /**
     * 添加列到表
     */
    protected function addColumnToTable($table, string $columnName, string $columnType, ?int $length, string $modifiers): void
    {
        // 映射 Laravel 列类型到 DBAL 类型
        $dbalType = $this->mapLaravelTypeToDbal($columnType);
        
        $options = [];
        
        // 解析长度
        if ($length !== null) {
            $options['length'] = $length;
        }
        
        // 解析 nullable
        if (strpos($modifiers, '->nullable()') !== false) {
            $options['notnull'] = false;
        } else {
            $options['notnull'] = true;
        }
        
        // 解析 default 值
        if (preg_match('/->default\([\'"]?([^\'")]*)[\'"]?\)/', $modifiers, $defaultMatch)) {
            $options['default'] = $defaultMatch[1];
        }
        
        // 解析 comment
        if (preg_match('/->comment\([\'"]([^\'"]*)[\'"]/i', $modifiers, $commentMatch)) {
            $options['comment'] = $commentMatch[1];
        }
        
        // 解析 autoincrement
        if (strpos($modifiers, '->autoIncrement()') !== false || $columnType === 'id') {
            $options['autoincrement'] = true;
        }
        
        $table->addColumn($columnName, $dbalType, $options);
        
        // 如果是主键
        if ($columnType === 'id' || strpos($modifiers, '->primary()') !== false) {
            $table->setPrimaryKey([$columnName]);
        }
    }

    /**
     * 映射 Laravel 列类型到 DBAL 类型
     */
    protected function mapLaravelTypeToDbal(string $laravelType): string
    {
        return match ($laravelType) {
            'id', 'bigIncrements', 'increments' => 'integer',
            'string', 'char' => 'string',
            'text', 'longText', 'mediumText' => 'text',
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger' => 'integer',
            'decimal', 'double', 'float' => 'decimal',
            'boolean' => 'boolean',
            'date' => 'date',
            'dateTime', 'timestamp' => 'datetime',
            'time' => 'time',
            'json', 'jsonb' => 'json',
            'binary' => 'blob',
            'uuid' => 'guid',
            default => 'string'
        };
    }

    /**
     * 解析添加列操作
     */
    protected function parseAddColumns(string $content, $table): void
    {
        // 匹配 $table->addColumn 或新的列定义
        if (preg_match_all('/\$table->([a-zA-Z]+)\([\'"]([^\'"]*)[\'"]/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $columnType = $match[1];
                $columnName = $match[2];
                
                // 跳过非列定义的方法
                if (in_array($columnType, ['dropColumn', 'renameColumn', 'index', 'unique', 'primary'])) {
                    continue;
                }
                
                if (!$table->hasColumn($columnName)) {
                    $dbalType = $this->mapLaravelTypeToDbal($columnType);
                    $table->addColumn($columnName, $dbalType);
                }
            }
        }
    }

    /**
     * 解析删除列操作
     */
    protected function parseDropColumns(string $content, $table): void
    {
        if (preg_match_all('/\$table->dropColumn\([\'"]([^\'"]*)[\'"]/m', $content, $matches)) {
            foreach ($matches[1] as $columnName) {
                if ($table->hasColumn($columnName)) {
                    $table->dropColumn($columnName);
                }
            }
        }
    }

    /**
     * 解析修改列操作
     */
    protected function parseModifyColumns(string $content, $table): void
    {
        // 匹配 ->change() 修饰符
        if (preg_match_all('/\$table->([a-zA-Z]+)\([\'"]([^\'"]*)[\'"]/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $line = $this->getFullLine($content, $match[0]);
                if (strpos($line, '->change()') !== false) {
                    $columnType = $match[1];
                    $columnName = $match[2];
                    
                    if ($table->hasColumn($columnName)) {
                        $column = $table->getColumn($columnName);
                        $newType = $this->mapLaravelTypeToDbal($columnType);
                        $column->setType(\Doctrine\DBAL\Types\Type::getType($newType));
                    }
                }
            }
        }
    }

    /**
     * 获取完整的代码行
     */
    protected function getFullLine(string $content, string $match): string
    {
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (strpos($line, $match) !== false) {
                return $line;
            }
        }
        return '';
    }

    /**
     * 解析索引定义
     */
    protected function parseIndexes(string $content, $table): void
    {
        // 解析各种索引类型
        $indexPatterns = [
            'unique' => '/\$table->unique\(\[?[\'"]([^\'"]*)[\'"]/m',
            'index' => '/\$table->index\(\[?[\'"]([^\'"]*)[\'"]/m',
            'primary' => '/\$table->primary\(\[?[\'"]([^\'"]*)[\'"]/m',
        ];
        
        foreach ($indexPatterns as $type => $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $columnName) {
                    if ($table->hasColumn($columnName)) {
                        switch ($type) {
                            case 'unique':
                                $table->addUniqueIndex([$columnName]);
                                break;
                            case 'index':
                                $table->addIndex([$columnName]);
                                break;
                            case 'primary':
                                $table->setPrimaryKey([$columnName]);
                                break;
                        }
                    }
                }
            }
        }
    }

    /**
     * 解析索引变更
     */
    protected function parseIndexChanges(string $content, $table): void
    {
        // 解析删除索引
        if (preg_match_all('/\$table->dropIndex\([\'"]([^\'"]*)[\'"]/m', $content, $matches)) {
            foreach ($matches[1] as $indexName) {
                if ($table->hasIndex($indexName)) {
                    $table->dropIndex($indexName);
                }
            }
        }
        
        // 解析添加新索引
        $this->parseIndexes($content, $table);
    }

    protected function generateAllDiffs($schemaDiff): void
    {
        $changes = [];
        
        // New tables
        foreach ($schemaDiff->getCreatedTables() as $table) {
            $changes[] = "New table: {$table->getName()}";
            $this->generateCreateTableMigration($table);
        }
        
        // Dropped tables
        foreach ($schemaDiff->getDroppedTables() as $table) {
            $changes[] = "Dropped table: {$table->getName()}";
            $this->generateDropTableMigration($table);
        }
        
        // Modified tables
        foreach ($schemaDiff->getAlteredTables() as $tableDiff) {
            $tableName = $tableDiff->getOldTable()->getName();
            $changes[] = "Modified table: {$tableName}";
            $this->generateAlterTableMigration($tableDiff);
        }
        
        if (empty($changes)) {
            $this->output->info('No schema differences found.');
        } else {
            $this->output->success('Generated migrations for schema differences:');
            foreach ($changes as $change) {
                $this->output->writeln("- {$change}");
            }
        }
    }

    protected function generateTableDiff($schemaDiff, string $tableName): void
    {
        $found = false;
        
        foreach ($schemaDiff->getAlteredTables() as $tableDiff) {
            if ($tableDiff->getOldTable()->getName() === $tableName) {
                $this->generateAlterTableMigration($tableDiff);
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $this->output->info("No differences found for table '{$tableName}'.");
        }
    }

    protected function generateCreateTableMigration($table): void
    {
        $tableName = $table->getName();
        $timestamp = date('Y_m_d_His');
        $className = 'Create' . str_replace('_', '', ucwords($tableName, '_')) . 'Table';
        $fileName = "{$timestamp}_create_{$tableName}_table.php";
        $filePath = BASE_PATH . "/migrations/{$fileName}";
        
        // Implementation similar to SchemaGenerateCommand
        $this->output->success("Created migration: {$filePath}");
    }

    protected function generateDropTableMigration($table): void
    {
        $tableName = $table->getName();
        $timestamp = date('Y_m_d_His');
        $className = 'Drop' . str_replace('_', '', ucwords($tableName, '_')) . 'Table';
        $fileName = "{$timestamp}_drop_{$tableName}_table.php";
        $filePath = BASE_PATH . "/migrations/{$fileName}";
        
        $migrationContent = $this->generateDropMigrationContent($className, $tableName);
        file_put_contents($filePath, $migrationContent);
        
        $this->output->success("Created migration: {$filePath}");
    }

    protected function generateAlterTableMigration($tableDiff): void
    {
        $tableName = $tableDiff->getOldTable()->getName();
        $timestamp = date('Y_m_d_His');
        $className = 'Update' . str_replace('_', '', ucwords($tableName, '_')) . 'Table';
        $fileName = "{$timestamp}_update_{$tableName}_table.php";
        $filePath = BASE_PATH . "/migrations/{$fileName}";
        
        $changes = [];
        $alterations = [];
        
        // Added columns
        foreach ($tableDiff->getAddedColumns() as $column) {
            $changes[] = "Added column `{$column->getName()}`";
            $alterations[] = $this->generateAddColumnCode($column);
        }
        
        // Dropped columns
        foreach ($tableDiff->getDroppedColumns() as $column) {
            $changes[] = "Removed column `{$column->getName()}`";
            $alterations[] = "\$table->dropColumn('{$column->getName()}');";
        }
        
        // Modified columns
        foreach ($tableDiff->getModifiedColumns() as $columnDiff) {
            $columnName = $columnDiff->getOldColumn()->getName();
            $changes[] = "Modified column `{$columnName}`";
            $alterations[] = $this->generateModifyColumnCode($columnDiff->getNewColumn());
        }
        
        // Added indexes
        foreach ($tableDiff->getAddedIndexes() as $index) {
            $changes[] = "Added index `{$index->getName()}`";
            $alterations[] = $this->generateAddIndexCode($index);
        }
        
        // Dropped indexes
        foreach ($tableDiff->getDroppedIndexes() as $index) {
            $changes[] = "Removed index `{$index->getName()}`";
            $alterations[] = "\$table->dropIndex('{$index->getName()}');";
        }
        
        if (!empty($alterations)) {
            $migrationContent = $this->generateAlterMigrationContent($className, $tableName, $alterations);
            file_put_contents($filePath, $migrationContent);
            
            $this->output->success("Generated ALTER migration for {$tableName}:");
            foreach ($changes as $change) {
                $this->output->writeln("- {$change}");
            }
        }
    }

    protected function generateAddColumnCode($column): string
    {
        // Similar to generateColumnDefinition in SchemaGenerateCommand
        return "\$table->string('{$column->getName()}')->nullable();";
    }

    protected function generateModifyColumnCode($column): string
    {
        return "\$table->string('{$column->getName()}')->change();";
    }

    protected function generateAddIndexCode($index): string
    {
        $columns = $index->getColumns();
        if ($index->isUnique()) {
            return "\$table->unique(['" . implode("', '", $columns) . "']);";
        } else {
            return "\$table->index(['" . implode("', '", $columns) . "']);";
        }
    }

    protected function generateDropMigrationContent(string $className, string $tableName): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class {$className} extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('{$tableName}');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse a drop operation without knowing the original structure
        throw new \Exception('Cannot reverse drop table migration');
    }
}
PHP;
    }

    protected function generateAlterMigrationContent(string $className, string $tableName, array $alterations): string
    {
        $alterationsCode = implode("\n            ", $alterations);
        
        return <<<PHP
<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class {$className} extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
            {$alterationsCode}
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Implement reverse operations here
    }
}
PHP;
    }
}