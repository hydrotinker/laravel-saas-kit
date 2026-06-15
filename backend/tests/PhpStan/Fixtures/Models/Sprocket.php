<?php

namespace Tests\PhpStan\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

// No explicit $table: the rule must derive "sprockets" from the class name
// (Str::snake(Str::pluralStudly('Sprocket'))) and still flag the missing trait.
class Sprocket extends Model
{
    //
}
