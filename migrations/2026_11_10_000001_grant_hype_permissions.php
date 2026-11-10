<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Everyone can look, members can back, moderators can call off a market that was gamed.
return Migration::addPermissions([
    'hype.view' => Group::GUEST_ID,
    'hype.back' => Group::MEMBER_ID,
    'hype.moderate' => Group::MODERATOR_ID,
]);
