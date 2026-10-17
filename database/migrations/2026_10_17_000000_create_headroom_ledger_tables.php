<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Portable across SQLite, PostgreSQL, MySQL and MariaDB: no driver-specific
 * types, and index names are explicit and short because MySQL caps them at
 * 64 characters and PostgreSQL needs them unique per schema.
 *
 * Money columns are integer micro-units (millionths of the currency), so
 * sub-cent costs survive and sums stay exact. No column holds prompt or
 * response text.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('headroom-ledger.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $prefix = $this->prefix();

        Schema::create($prefix.'entries', function (Blueprint $table) use ($prefix): void {
            $table->string('request_id', 64)->primary();
            $table->string('invocation_id', 64)->nullable();
            $table->string('agent')->nullable();

            $table->string('provider_counted', 64)->nullable();
            $table->string('model_counted', 128)->nullable();
            $table->string('provider_used', 64)->nullable();
            $table->string('model_used', 128)->nullable();
            $table->string('count_method', 16)->nullable();

            $table->unsignedInteger('estimated_input_tokens')->nullable();
            $table->json('breakdown')->nullable();
            $table->unsignedInteger('context_window')->nullable();
            $table->decimal('percent_used', 7, 2)->nullable();

            $table->unsignedInteger('actual_input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();

            $table->char('currency', 3)->nullable();
            $table->bigInteger('estimated_cost')->nullable();
            $table->bigInteger('actual_cost')->nullable();
            $table->boolean('cost_approximate')->default(false);

            $table->boolean('streamed')->default(false);
            $table->string('outcome', 16)->default('counted');
            $table->string('outcome_reason')->nullable();

            $table->date('occurred_on');
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('used_at')->nullable();

            $table->index('invocation_id', $prefix.'entries_invocation_idx');
            $table->index('agent', $prefix.'entries_agent_idx');
            $table->index('outcome', $prefix.'entries_outcome_idx');
            $table->index('occurred_on', $prefix.'entries_occurred_idx');
            $table->index('counted_at', $prefix.'entries_counted_idx');
            $table->index('used_at', $prefix.'entries_used_idx');
        });

        Schema::create($prefix.'tags', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->string('request_id', 64);
            $table->string('key', 64);
            $table->string('value', 191);

            $table->foreign('request_id', $prefix.'tags_request_fk')
                ->references('request_id')
                ->on($prefix.'entries')
                ->cascadeOnDelete();

            $table->unique(['request_id', 'key'], $prefix.'tags_request_key_uq');
            $table->index(['key', 'value'], $prefix.'tags_key_value_idx');
        });

        Schema::create($prefix.'daily', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->date('date');
            $table->string('agent')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('count_method', 16)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('primary_tag_key', 64)->nullable();
            $table->string('primary_tag_value', 191)->nullable();

            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('warned')->default(0);
            $table->unsignedInteger('blocked')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('streamed')->default(0);

            $table->unsignedBigInteger('estimated_input_tokens')->default(0);
            $table->unsignedBigInteger('actual_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);

            $table->bigInteger('estimated_cost')->default(0);
            $table->bigInteger('actual_cost')->default(0);
            $table->bigInteger('estimated_only_cost')->default(0);
            $table->unsignedInteger('estimated_only_requests')->default(0);
            $table->unsignedInteger('approximate_requests')->default(0);

            $table->unsignedInteger('context_samples')->default(0);
            $table->decimal('percent_used_sum', 14, 2)->default(0);
            $table->decimal('percent_used_max', 7, 2)->default(0);

            $table->unsignedInteger('accuracy_samples')->default(0);
            $table->unsignedBigInteger('accuracy_estimated_tokens')->default(0);
            $table->unsignedBigInteger('accuracy_actual_tokens')->default(0);
            $table->unsignedBigInteger('accuracy_absolute_error')->default(0);

            $table->index('date', $prefix.'daily_date_idx');
            $table->index(['primary_tag_key', 'primary_tag_value', 'date'], $prefix.'daily_tag_idx');
        });
    }

    public function down(): void
    {
        $prefix = $this->prefix();

        Schema::dropIfExists($prefix.'tags');
        Schema::dropIfExists($prefix.'daily');
        Schema::dropIfExists($prefix.'entries');
    }

    private function prefix(): string
    {
        $prefix = config('headroom-ledger.table_prefix', 'headroom_ledger_');

        return is_string($prefix) ? $prefix : 'headroom_ledger_';
    }
};
