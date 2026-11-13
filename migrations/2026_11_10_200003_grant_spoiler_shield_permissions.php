<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'spoiler-shield.createWorks' => Group::MEMBER_ID,
    'spoiler-shield.manageWorks' => Group::MODERATOR_ID,
]);
