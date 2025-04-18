<?php
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Migrations\Migration;

class LaTenantAdminDept extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('la_tenant_admin_dept', function (Blueprint $table) {
            $table->integer('admin_id')->default(0)->comment('管理员id');
            $table->integer('dept_id')->default(0)->comment('部门id');
            $table->primary(['admin_id', 'dept_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('la_tenant_admin_dept');
    }
}