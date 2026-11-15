<?php

use Flarum\Database\Migration;

// The share of its stake a call loses if the discussion stays flat, fixed when the call is made:
// changing the setting later never changes a call already open. 0 = nobody loses (the default).
return Migration::addColumns('hype_positions', [
    'loss' => ['tinyInteger', 'unsigned' => true, 'default' => 0],
]);
