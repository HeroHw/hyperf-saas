<?php

use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Migrations\Migration;

class CreateLaTenantTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('la_tenant', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('ID');
            $table->string('sn')->notNullable()->comment('序列号');
            $table->string('name')->notNullable()->default('')->comment('名称');
            $table->string('avatar')->notNullable()->default('')->comment('头像');
            $table->string('tel')->nullable()->comment('电话');
            $table->smallInteger('disable')->default(0)->comment('是否禁用');
            $table->smallInteger('tactics')->notNullable()->default(0)->comment('策略');
            $table->string('notes')->nullable()->comment('备注');
            $table->string('domain_alias')->nullable()->comment('域名别名');
            $table->smallInteger('domain_alias_enable')->notNullable()->default(1)->comment('域名别名启用状态');
            $table->integer('create_time')->notNullable()->comment('创建时间');
            $table->integer('update_time')->nullable()->comment('更新时间');
            $table->integer('delete_time')->nullable()->comment('删除时间');
            $table->primary('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('la_tenant');
    }
}