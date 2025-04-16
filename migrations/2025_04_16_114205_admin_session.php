<?php
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Migrations\Migration;

class AdminSession extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_session', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('admin_id')->comment('用户id');
            $table->smallInteger('terminal')->default(1)->comment('客户端类型：1-pc管理后台 2-mobile手机管理后台');
            $table->string('token')->comment('令牌');
            $table->integer('update_time')->nullable()->comment('更新时间');
            $table->integer('expire_time')->comment('到期时间');
            $table->comment('管理员会话表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_session');
    }
}