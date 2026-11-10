<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// The share of its stake a call loses if the discussion stays flat, fixed when the call is made:
// changing the setting later never changes a call already open. 0 = nobody loses (the default).
return [
    'up' => function (Builder $schema) {
        $schema->table('hype_positions', function (Blueprint $table) {
            $table->unsignedTinyInteger('loss')->default(0)->after('stake');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('hype_positions', function (Blueprint $table) {
            $table->dropColumn('loss');
        });
    },
];
