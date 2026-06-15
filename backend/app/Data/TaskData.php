<?php

namespace App\Data;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class TaskData extends Data
{
    public function __construct(
        public int|Optional $id,
        #[Max(255)]
        public string $title,
        public ?string $description = null,
        public TaskStatus $status = TaskStatus::Todo,
        public ?int $assignee_id = null,
        public ?Carbon $due_date = null,
        public int|Optional $project_id = new Optional,
        public Carbon|Optional|null $created_at = null,
        public Carbon|Optional|null $updated_at = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'assignee_id' => [
                'nullable',
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    public static function fromModel(Task $task): self
    {
        return new self(
            id: $task->id,
            title: $task->title,
            description: $task->description,
            status: $task->status,
            assignee_id: $task->assignee_id,
            due_date: $task->due_date,
            project_id: $task->project_id,
            created_at: $task->created_at,
            updated_at: $task->updated_at,
        );
    }
}
