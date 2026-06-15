<?php

namespace App\Http\Controllers\Api;

use App\Data\TaskData;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Support\Cache\TenantCache;
use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\DataCollection;

class TaskController extends Controller
{
    public function index(int $project, TenantCache $cache): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $model = Project::findOrFail($project);

        $payload = $cache->remember("project:{$project}:tasks", 'index', fn () => TaskData::collect(
            $model->tasks()->latest()->get(),
            DataCollection::class,
        )->toArray());

        return response()->json($payload);
    }

    public function store(TaskData $data, int $project): JsonResponse
    {
        $this->authorize('create', Task::class);

        $projectModel = Project::findOrFail($project);

        $task = $projectModel->tasks()->create([
            'title' => $data->title,
            'description' => $data->description,
            'status' => $data->status,
            'assignee_id' => $data->assignee_id,
            'due_date' => $data->due_date,
        ]);

        return response()->json(TaskData::fromModel($task), 201);
    }

    public function show(int $project, int $task): TaskData
    {
        $model = $this->findTask($project, $task);
        $this->authorize('view', $model);

        return TaskData::fromModel($model);
    }

    public function update(TaskData $data, int $project, int $task): TaskData
    {
        $model = $this->findTask($project, $task);
        $this->authorize('update', $model);

        $model->update([
            'title' => $data->title,
            'description' => $data->description,
            'status' => $data->status,
            'assignee_id' => $data->assignee_id,
            'due_date' => $data->due_date,
        ]);

        return TaskData::fromModel($model->refresh());
    }

    public function destroy(int $project, int $task): JsonResponse
    {
        $model = $this->findTask($project, $task);
        $this->authorize('delete', $model);

        $model->delete();

        return response()->json(status: 204);
    }

    protected function findTask(int $project, int $task): Task
    {
        return Project::findOrFail($project)
            ->tasks()
            ->findOrFail($task);
    }
}
