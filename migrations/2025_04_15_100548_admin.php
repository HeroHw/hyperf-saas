<?php

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class Admin extends Migration
{
    /**
    * Run the migrations.
    */
    public function up(): void
    {
        Schema::create('admin', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->smallInteger('root')->unsigned()->default(0)->comment('是否超级管理员 0-否 1-是');
            $table->string('name', 32)->default('')->comment('名称');
            $table->string('avatar', 255)->default('')->comment('用户头像');
            $table->string('account', 32)->default('')->comment('账号');
            $table->string('password', 32)->comment('密码');
            $table->integer('login_time')->nullable()->comment('最后登录时间');
            $table->string('login_ip', 39)->nullable()->default('')->comment('最后登录ip');
            $table->smallInteger('multipoint_login')->unsigned()->default(1)->comment('允许多点登录');
            $table->boolean('disable')->default(false)->comment('是否禁用：0-否；1-是');
            $table->integer('create_time')->comment('创建时间');
            $table->integer('update_time')->nullable()->comment('修改时间');
            $table->integer('delete_time')->nullable()->comment('删除时间');
            $table->comment('管理员表');
        });
    }

    /**
    * Reverse the migrations.
    */
    public function down(): void
    {
        Schema::dropIfExists('admin');
    }
}