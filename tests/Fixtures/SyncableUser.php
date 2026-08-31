<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Tests\Fixtures;

use AutomateFlow\Laravel\Concerns\SyncsToAutomateFlow;
use Illuminate\Database\Eloquent\Model;

/** Stand-in for a host application's user model. Never persisted in these tests. */
class SyncableUser extends Model
{
    use SyncsToAutomateFlow;

    protected $guarded = [];

    protected $table = 'users';
}
