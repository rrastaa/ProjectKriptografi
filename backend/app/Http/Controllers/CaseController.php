<?php

namespace App\Http\Controllers;

use App\Models\CaseRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CaseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CaseController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'categories' => config('caselock.categories'),
            'statuses' => CaseRecord::STATUSES,
            'max_file_mb' => config('caselock.max_file_mb'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = CaseRecord::query()->with('owner:id,name')->withCount(['files', 'messages'])->orderByDesc('id');
        if (!$user->isAdmin()) {
            $query->whereHas('members', fn ($q) => $q->where('users.id', $user->id));
        }

        return response()->json(['cases' => $query->get()->map(fn ($c) => $this->summary($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'category' => ['required', Rule::in(array_keys(config('caselock.categories')))],
            'description' => 'nullable|string|max:10000',
            'reporter' => 'nullable|string|max:300',
        ]);
        $user = $request->user();

        $case = DB::transaction(function () use ($data, $user) {
            $next = (int) CaseRecord::max('id') + 1;
            $case = CaseRecord::create($data + [
                'case_number' => sprintf('CL-%s-%04d', now()->format('Y'), $next),
                'status' => 'open',
                'owner_id' => $user->id,
            ]);
            $case->members()->attach($user->id, ['role' => 'owner']);
            return $case;
        });

        $this->audit->record($user, 'case.create', 'case', $case->id, $case->id, ['case_number' => $case->case_number, 'category' => $case->category]);
        return response()->json(['case' => $this->detail($case)], 201);
    }

    public function show(Request $request, CaseRecord $case): JsonResponse
    {
        $this->authorizeView($request, $case);
        $this->audit->record($request->user(), 'case.view', 'case', $case->id, $case->id);
        return response()->json(['case' => $this->detail($case, $request->user())]);
    }

    public function updateStatus(Request $request, CaseRecord $case): JsonResponse
    {
        abort_unless(CaseAccess::canManage($request->user(), $case), 403, 'Hanya pemilik kasus atau admin yang dapat mengubah status.');
        $data = $request->validate(['status' => ['required', Rule::in(CaseRecord::STATUSES)]]);

        $old = $case->status;
        $case->update(['status' => $data['status']]);
        $this->audit->record($request->user(), 'case.status', 'case', $case->id, $case->id, ['from' => $old, 'to' => $case->status]);

        return response()->json(['case' => $this->detail($case, $request->user())]);
    }

    public function addMember(Request $request, CaseRecord $case): JsonResponse
    {
        abort_unless(CaseAccess::canManage($request->user(), $case), 403, 'Hanya pemilik kasus atau admin yang dapat mengelola anggota.');
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role' => ['required', Rule::in(['investigator', 'viewer'])],
        ]);

        $target = User::findOrFail($data['user_id']);
        if (!$target->is_active) {
            return response()->json(['message' => 'Pengguna tidak aktif.'], 422);
        }
        if ($case->owner_id === $target->id) {
            return response()->json(['message' => 'Pemilik kasus sudah menjadi anggota.'], 422);
        }

        $case->members()->syncWithoutDetaching([$target->id => ['role' => $data['role']]]);
        $case->members()->updateExistingPivot($target->id, ['role' => $data['role']]);
        $this->audit->record($request->user(), 'case.member.add', 'case', $case->id, $case->id, ['user_id' => $target->id, 'role' => $data['role']]);

        return response()->json(['case' => $this->detail($case, $request->user())]);
    }

    public function removeMember(Request $request, CaseRecord $case, User $user): JsonResponse
    {
        abort_unless(CaseAccess::canManage($request->user(), $case), 403, 'Hanya pemilik kasus atau admin yang dapat mengelola anggota.');
        if ($case->owner_id === $user->id) {
            return response()->json(['message' => 'Pemilik kasus tidak dapat dihapus.'], 422);
        }

        DB::transaction(function () use ($case, $user) {
            $case->members()->detach($user->id);
            // Cabut juga izin dekripsi berkas kasus ini milik anggota tersebut.
            DB::table('file_permissions')
                ->where('user_id', $user->id)
                ->whereIn('case_file_id', $case->files()->pluck('id'))
                ->delete();
        });
        $this->audit->record($request->user(), 'case.member.remove', 'case', $case->id, $case->id, ['user_id' => $user->id]);

        return response()->json(['case' => $this->detail($case, $request->user())]);
    }

    private function authorizeView(Request $request, CaseRecord $case): void
    {
        abort_unless(CaseAccess::canView($request->user(), $case), 403, 'Anda bukan anggota kasus ini.');
    }

    private function summary(CaseRecord $c): array
    {
        return [
            'id' => $c->id,
            'case_number' => $c->case_number,
            'title' => $c->title,
            'category' => $c->category,
            'status' => $c->status,
            'owner' => $c->owner?->name,
            'files_count' => $c->files_count ?? null,
            'messages_count' => $c->messages_count ?? null,
            'created_at' => $c->created_at,
        ];
    }

    private function detail(CaseRecord $c, ?User $viewer = null): array
    {
        $c->load('owner:id,name', 'members');
        $viewer ??= $c->owner;

        return [
            'id' => $c->id,
            'case_number' => $c->case_number,
            'title' => $c->title,
            'category' => $c->category,
            'status' => $c->status,
            'description' => $c->description,
            'reporter' => $c->reporter,
            'owner' => ['id' => $c->owner->id, 'name' => $c->owner->name],
            'created_at' => $c->created_at,
            'members' => $c->members->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->pivot->role,
            ])->values(),
            'me' => [
                'can_manage' => CaseAccess::canManage($viewer, $c),
                'can_contribute' => CaseAccess::canContribute($viewer, $c),
            ],
        ];
    }
}
