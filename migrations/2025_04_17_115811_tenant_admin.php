<?php

use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Migrations\Migration;

class CreateLaTenantAdminTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('la_tenant_admin', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('ID');
            $table->integer('tenant_id')->notNullable()->comment('租户ID');
            $table->smallInteger('root')->notNullable()->default(0)->comment('是否为根管理员');
            $table->string('name')->notNullable()->default('')->comment('名称');
            $table->string('avatar')->notNullable()->default('')->comment('头像');
            $table->string('account')->notNullable()->default('')->comment('账号');
            $table->string('password')->notNullable()->comment('密码');
            $table->integer('login_time')->nullable()->comment('登录时间');
            $table->string('login_ip')->nullable()->default('')->comment('登录IP');
            $table->smallInteger('multipoint_login')->default(1)->comment('多点登录');
            $table->smallInteger('disable')->default(0)->comment('是否禁用');
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
        Schema::dropIfExists('la_tenant_admin');
    }
}