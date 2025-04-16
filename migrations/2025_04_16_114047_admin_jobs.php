<?php
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Migrations\Migration;

class AdminJobs extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_jobs', function (Blueprint $table) {
            $table->integer('admin_id')->comment('管理员id');
            $table->integer('jobs_id')->comment('岗位id');
            $table->primary(['admin_id', 'jobs_id']);
            $table->comment('管理员岗位关联表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_jobs');
    }
}