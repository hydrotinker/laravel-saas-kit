<?php

namespace App\Data;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class ProjectData extends Data
{
    public function __construct(
        public int|Optional $id,
        #[Max(255)]
        public string $name,
        public ?string $description = null,
        public ProjectStatus $status = ProjectStatus::Active,
        public Carbon|Optional|null $created_at = null,
        public Carbon|Optional|null $updated_at = null,
    ) {}

    public static function fromModel(Project $project): self
    {
        return new self(
            id: $project->id,
            name: $project->name,
            description: $project->description,
            status: $project->status,
            created_at: $project->created_at,
            updated_at: $project->updated_at,
        );
    }
}
