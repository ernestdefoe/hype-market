<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// Scores::heal() walks settled positions in (settled_at, id) order from a cursor. Laravel names the index.
return [
    'up' => function (Builder $schema) {
        $schema->table('hype_positions', function (Blueprint $table) {
            $table->index(['settled_at', 'id']);
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('hype_positions', function (Blueprint $table) {
            $table->dropIndex(['settled_at', 'id']);
        });
    },
];
