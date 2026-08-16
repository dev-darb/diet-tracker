<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The working behind every estimated figure (founder decision, Aug 2026).
 *
 * A model may now produce nutrition where no source has any — inside guardrails,
 * and never anonymously. Every accepted estimate leaves a row here carrying what
 * was asked, what the model reasoned, what it assumed, what it based the answer
 * on, what the guard accepted, and what the guard threw out.
 *
 * ## Why the rejections are kept too
 * A rejected estimate is the more interesting record. It is the evidence that
 * the guardrails are doing something, and the only way to tell a model that
 * cannot estimate a food from a model that was never asked. Keeping only the
 * accepted ones would make the system look infallible by discarding its misses.
 *
 * ## Why it is not just a log line
 * Because it may need to be shown. "Where did this number come from" is a
 * question a user is entitled to ask about their own nutrition history, and it
 * has to be answerable months later, from the record, without the original
 * request still being in a log buffer somewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // What was estimated: a consumption event, a product version, a meal
            // component. Nullable because the estimate is written BEFORE the
            // thing it belongs to exists, then linked once it does.
            $table->nullableMorphs('subject');

            $table->string('subject_label');            // "Flat white", in the user's words
            $table->string('reason', 40);               // why estimation was permitted at all
            $table->string('basis', 20);                // whole item, or per 100 g/ml

            $table->json('request');                    // exactly what was asked for
            $table->json('steps');                      // the reasoning, in order
            $table->json('assumptions');                // what had to be assumed
            $table->text('reference')->nullable();      // what it reasoned from
            $table->json('values')->nullable();         // the figures the guard accepted
            $table->json('guard_notes')->nullable();    // what the guard dropped or refused, and why

            $table->boolean('accepted')->default(false);
            $table->decimal('confidence', 4, 3)->nullable();

            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['accepted', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_estimates');
    }
};
