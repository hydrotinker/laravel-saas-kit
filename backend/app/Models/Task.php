<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Observers\TaskObserver;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $assignee_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property Carbon|null $due_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'assignee_id', 'title', 'description', 'status', 'due_date'])]
#[ObservedBy(TaskObserver::class)]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_date' => 'date',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
