<?php

namespace Tests\Feature\Concerns;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;

trait BuildsUniversalCommentFixtures
{
    protected function commentBranch(string $name = 'Comment Branch'): Branch
    {
        return Branch::create([
            'name' => $name,
            'code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 5)).uniqid(),
            'is_active' => true,
        ]);
    }

    protected function commentUser(string $role, ?Branch $branch = null, array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }

    protected function commentProject(Branch $branch, string $name = 'Comment Project'): LeadMaster
    {
        return LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => $name,
            'is_active' => true,
        ]);
    }

    protected function assignCommentProject(User $user, LeadMaster $project): void
    {
        $user->assignedProjects()->attach($project->id, [
            'is_primary' => true,
            'is_active' => true,
        ]);
    }

    protected function commentPlanner(User $creator, Branch $branch, array $attributes = []): ContentItem
    {
        return ContentItem::create($attributes + [
            'branch_id' => $branch->id,
            'title' => 'Comment target',
            'item_type' => 'task',
            'visibility' => 'team',
            'scheduled_date' => today(),
            'status' => 'todo',
            'created_by' => $creator->id,
        ]);
    }

    protected function commentAgenda(User $owner, Branch $branch, ?LeadMaster $project = null, array $attributes = []): ContentItem
    {
        return ContentItem::create($attributes + [
            'branch_id' => $branch->id,
            'project_name' => $project?->project_name,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Comment sales agenda',
            'scheduled_date' => today(),
            'status' => 'planned',
            'owner_user_id' => $owner->id,
            'sales_project_id' => $project?->id,
            'created_by' => $owner->id,
        ]);
    }

    protected function commentLead(User $sales, LeadMaster $project, string $customer = 'Comment Customer'): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => $customer,
            'created_by' => $sales->id,
        ]);
    }
}
