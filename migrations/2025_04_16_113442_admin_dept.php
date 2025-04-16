<?php

use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Migrations\Migration;

class AdminDept extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_dept', function (Blueprint $table) {
            $table->integer('admin_id')->default(0)->comment('管理员id');
            $table->integer('dept_id')->default(0)->comment('部门id');
            $table->primary(['admin_id', 'dept_id']);
            $table->comment('管理员部门关联表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_dept');
    }
}
