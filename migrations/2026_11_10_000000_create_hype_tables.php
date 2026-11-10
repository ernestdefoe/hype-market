<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Index names are written out and kept short: Laravel's generated names plus a
 * table prefix can pass MySQL's 64-character limit on a prefixed forum.
 */
return [
    'up' => function (Builder $schema) {
        // A season holds the leaderboard. Exactly one is open (ended_at null) at a time.
        $schema->create('hype_seasons', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 80);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
        });

        // One market per new discussion, made when it starts (or by the tick, or on the first back).
        $schema->create('hype_markets', function (Blueprint $table) {
            $table->increments('id');
            // Null once the discussion is deleted: a settled market keeps counting on old leaderboards.
            $table->unsignedInteger('discussion_id')->nullable();
            // The discussion's author: never a backer, and their own engagement never counts.
            $table->unsignedInteger('user_id')->nullable();
            $table->string('title', 200);
            $table->dateTime('opened_at');
            // Backing closes here; the price stops moving and positions settle at settles_at.
            $table->dateTime('closes_at');
            $table->dateTime('settles_at');
            $table->double('price')->default(10);
            $table->unsignedInteger('participants')->default(0);
            $table->unsignedInteger('backers_count')->default(0);
            $table->unsignedInteger('staked')->default(0);
            // [[unix time, price], ...] hourly, for the sparklines.
            $table->json('history')->nullable();
            $table->dateTime('priced_at')->nullable();
            $table->double('final_price')->nullable();
            $table->dateTime('settled_at')->nullable();
            // Set with settled_at when the market was called off: every stake comes back flat.
            $table->dateTime('voided_at')->nullable();
            $table->timestamps();

            $table->unique('discussion_id', 'hype_markets_discussion_unique');
            $table->index(['settled_at', 'settles_at'], 'hype_markets_due_index');
            $table->foreign('discussion_id', 'hype_markets_discussion_fk')->references('id')->on('discussions')->nullOnDelete();
            $table->foreign('user_id', 'hype_markets_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        $schema->create('hype_positions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('market_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('season_id')->nullable();
            $table->unsignedInteger('stake');
            // Re-read at every reprice and at settlement with every backer's own engagement left out.
            $table->double('entry_price');
            $table->double('exit_price')->nullable();
            $table->double('multiplier')->nullable();
            $table->unsignedInteger('payout')->nullable();
            $table->integer('profit')->nullable();
            // Who made this position grow, written at settlement: the same members can lift one backer's
            // calls only so often a season (Settler::PAIR_CAP), which is what a ring of friends would do.
            $table->json('contributors')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('settled_at')->nullable();

            // One position per member per discussion, enforced by the database too.
            $table->unique(['market_id', 'user_id'], 'hype_positions_market_user_unique');
            $table->index(['user_id', 'settled_at'], 'hype_positions_user_index');
            $table->index(['season_id', 'user_id'], 'hype_positions_season_index');
            $table->foreign('market_id', 'hype_positions_market_fk')->references('id')->on('hype_markets')->cascadeOnDelete();
            $table->foreign('user_id', 'hype_positions_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('season_id', 'hype_positions_season_fk')->references('id')->on('hype_seasons')->nullOnDelete();
        });

        // A member's spending today: the row every back locks, so two at once can't both pass the caps.
        $schema->create('hype_accounts', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->date('day')->nullable();
            $table->unsignedInteger('spent')->default(0);
            $table->unsignedInteger('backs')->default(0);

            $table->foreign('user_id', 'hype_accounts_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });

        // One row per member per season, rebuilt from their positions whenever one settles.
        $schema->create('hype_scores', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('season_id');
            $table->unsignedInteger('user_id');
            $table->integer('profit')->default(0);
            $table->unsignedInteger('staked')->default(0);
            $table->unsignedInteger('settled')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->double('best_multiplier')->default(0);

            $table->unique(['season_id', 'user_id'], 'hype_scores_season_user_unique');
            $table->index(['season_id', 'profit'], 'hype_scores_board_index');
            $table->foreign('season_id', 'hype_scores_season_fk')->references('id')->on('hype_seasons')->cascadeOnDelete();
            $table->foreign('user_id', 'hype_scores_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });

        $schema->getConnection()->table('hype_seasons')->insert([
            'name' => \Carbon\Carbon::now()->format('F Y'),
            'started_at' => \Carbon\Carbon::now(),
        ]);
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('hype_scores');
        $schema->dropIfExists('hype_accounts');
        $schema->dropIfExists('hype_positions');
        $schema->dropIfExists('hype_markets');
        $schema->dropIfExists('hype_seasons');
    },
];
