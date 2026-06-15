<?php

namespace App\Http\Controllers\Api;

use App\Data\ProjectData;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\Cache\TenantCache;
use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\DataCollection;

class ProjectController extends Controller
{
    public function index(TenantCache $cache): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $payload = $cache->remember('projects', 'index', fn () => ProjectData::collect(
            Project::latest()->get(),
            DataCollection::class,
        )->toArray());

        return response()->json($payload);
    }

    public function store(ProjectData $data): JsonResponse
    {
        $this->authorize('create', Project::class);

        $project = Project::create([
            'name' => $data->name,
            'description' => $data->description,
            'status' => $data->status,
        ]);

        return response()->json(ProjectData::fromModel($project), 201);
    }

    public function show(int $project): ProjectData
    {
        $model = Project::findOrFail($project);
        $this->authorize('view', $model);

        return ProjectData::fromModel($model);
    }

    public function update(ProjectData $data, int $project): ProjectData
    {
        $model = Project::findOrFail($project);
        $this->authorize('update', $model);

        $model->update([
            'name' => $data->name,
            'description' => $data->description,
            'status' => $data->status,
        ]);

        return ProjectData::fromModel($model->refresh());
    }

    public function destroy(int $project): JsonResponse
    {
        $model = Project::findOrFail($project);
        $this->authorize('delete', $model);

        $model->delete();

        return response()->json(status: 204);
    }
}
