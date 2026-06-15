<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Observers\ProjectObserver;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property ProjectStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'status'])]
#[ObservedBy(ProjectObserver::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
        ];
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
