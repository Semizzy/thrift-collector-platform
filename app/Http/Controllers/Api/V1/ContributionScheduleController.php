<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessAuditEvent;
use App\Models\ContributionCycle;
use App\Models\ContributionSchedule;
use App\Models\ThriftGroup;
use App\Models\ThriftGroupMembership;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContributionScheduleController extends Controller
{
    public function index(Request $request, int $groupId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $group = ThriftGroup::query()->where('business_id', $business->id)->whereKey($groupId)
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', 'active'))
            ->firstOrFail();

        return response()->json(['data' => ContributionSchedule::query()->where('thrift_group_id', $group->id)
            ->withCount('cycles')->orderByDesc('created_at')->paginate(25)]);
    }

    public function store(Request $request, int $groupId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $group = ThriftGroup::query()->where('business_id', $business->id)->whereKey($groupId)
            ->where('status', 'active')
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', 'active'))
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'frequency' => ['required', Rule::in(['weekly', 'fortnightly', 'monthly'])],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'due_day_of_month' => ['nullable', 'integer', 'min:1', 'max:31', Rule::requiredIf(fn () => ($request->input('frequency') === 'monthly'))],
        ]);

        $schedule = DB::transaction(function () use ($request, $business, $group, $data): ContributionSchedule {
            $schedule = ContributionSchedule::query()->create([
                'thrift_group_id' => $group->id, 'name' => trim($data['name']),
                'amount_minor' => $data['amount_minor'], 'currency_code' => $group->currency_code,
                'frequency' => $data['frequency'], 'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null, 'due_day_of_month' => $data['due_day_of_month'] ?? null,
                'status' => 'active', 'created_by_user_id' => $request->user()->id,
            ]);
            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'contribution_schedule.created', 'subject_type' => 'contribution_schedule', 'subject_id' => $schedule->id,
                'metadata' => ['group_id' => $group->id, 'frequency' => $schedule->frequency, 'amount_minor' => $schedule->amount_minor, 'currency_code' => $schedule->currency_code, 'starts_on' => $schedule->starts_on->toDateString(), 'ends_on' => $schedule->ends_on?->toDateString()],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
            return $schedule;
        });

        return response()->json(['data' => $schedule, 'message' => 'Contribution schedule created. No payment or collection was initiated.'], 201);
    }

    public function generateCycles(Request $request, int $groupId, int $scheduleId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $schedule = ContributionSchedule::query()->whereKey($scheduleId)
            ->where('thrift_group_id', $groupId)
            ->whereHas('group', fn ($query) => $query->where('business_id', $business->id)
                ->whereHas('memberships', fn ($members) => $members->where('user_id', $request->user()->id)->where('status', 'active')))
            ->firstOrFail();

        if ($schedule->status !== 'active') {
            return response()->json(['message' => 'Only active schedules can generate cycles.'], 409);
        }

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'through' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $through = CarbonImmutable::parse($data['through']);
        if ($from->diffInDays($through) > 366) {
            return response()->json(['message' => 'Generate at most 367 calendar days per request.'], 422);
        }

        $scheduleStart = CarbonImmutable::parse($schedule->starts_on->toDateString());
        $scheduleEnd = $schedule->ends_on ? CarbonImmutable::parse($schedule->ends_on->toDateString()) : null;
        $created = 0;
        $existing = 0;
        $obligationsCreated = 0;

        DB::transaction(function () use ($request, $business, $schedule, $from, $through, $scheduleStart, $scheduleEnd, &$created, &$existing, &$obligationsCreated): void {
            for ($date = $from; $date->lessThanOrEqualTo($through); $date = $date->addDay()) {
                if ($date->lessThan($scheduleStart) || ($scheduleEnd && $date->greaterThan($scheduleEnd))) continue;
                $matches = match ($schedule->frequency) {
                    'weekly' => $scheduleStart->diffInDays($date) % 7 === 0,
                    'fortnightly' => $scheduleStart->diffInDays($date) % 14 === 0,
                    'monthly' => $date->day === min((int) $schedule->due_day_of_month, $date->daysInMonth),
                    default => false,
                };
                if (! $matches) continue;

                $inserted = DB::table('contribution_cycles')->insertOrIgnore([
                    'contribution_schedule_id' => $schedule->id,
                    'thrift_group_id' => $schedule->thrift_group_id,
                    'due_on' => $date->toDateString(),
                    'expected_amount_minor' => $schedule->amount_minor,
                    'currency_code' => $schedule->currency_code,
                    'status' => 'scheduled',
                    'created_by_user_id' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $cycle = ContributionCycle::query()->where('contribution_schedule_id', $schedule->id)
                    ->whereDate('due_on', $date->toDateString())->firstOrFail();
                $inserted === 1 ? $created++ : $existing++;
                $members = ThriftGroupMembership::query()->where('thrift_group_id', $schedule->thrift_group_id)
                    ->where('status', 'active')->get(['user_id']);
                foreach ($members as $member) {
                    $obligation = $cycle->obligations()->firstOrCreate([
                        'user_id' => $member->user_id,
                    ], [
                        'expected_amount_minor' => $schedule->amount_minor,
                        'currency_code' => $schedule->currency_code,
                        'status' => 'due',
                    ]);
                    if ($obligation->wasRecentlyCreated) $obligationsCreated++;
                }
            }

            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'contribution_cycles.generated', 'subject_type' => 'contribution_schedule', 'subject_id' => $schedule->id,
                'metadata' => ['from' => $from->toDateString(), 'through' => $through->toDateString(), 'created_count' => $created, 'existing_count' => $existing, 'obligations_created_count' => $obligationsCreated, 'note' => 'Cycle generation does not initiate collections or payments.'],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
        });

        return response()->json(['data' => ['created_count' => $created, 'existing_count' => $existing, 'obligations_created_count' => $obligationsCreated], 'message' => 'Cycle schedule and member obligations generated. No money movement occurred.']);
    }

    public function cycles(Request $request, int $groupId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $group = ThriftGroup::query()->where('business_id', $business->id)->whereKey($groupId)
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', 'active'))
            ->firstOrFail();

        $query = ContributionCycle::query()->where('thrift_group_id', $group->id)->with('schedule:id,name,frequency')->withCount('obligations')
            ->orderBy('due_on');
        if ($request->filled('from')) $query->whereDate('due_on', '>=', $request->validate(['from' => ['date_format:Y-m-d']])['from']);
        if ($request->filled('through')) $query->whereDate('due_on', '<=', $request->validate(['through' => ['date_format:Y-m-d']])['through']);
        return response()->json(['data' => $query->paginate(50)]);
    }
}
