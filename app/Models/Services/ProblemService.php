<?php

namespace App\Models\Services;

use App\Models\Eloquent\Problem;
use App\Models\Eloquent\Contest;
use App\Models\Eloquent\OJ;
use App\Models\Eloquent\ProblemSolution;
use App\Models\Eloquent\Submission;
use Cache;
use DB;
use Auth;
use Carbon;
use Exception;

class ProblemService
{
    public static function pid($pcode)
    {
        $problem = Problem::where('pcode', $pcode)->first();
        return blank($problem) ? null : $problem->pid;
    }

    public static function getStatistics(Problem $problem, int $currentContestId = 0)
    {
        $statistics = Cache::tags(['problem', 'statistics'])->get($problem->pid . '@' . $currentContestId);
        if (is_null($statistics)) {
            $statistics = $problem->submissions()->select(
                DB::raw("count(sid) as submission_count"),
                DB::raw("sum(verdict='accepted') as passed_count"),
                DB::raw("sum(verdict='accepted')/count(sid)*100 as ac_rate")
            );

            if ($currentContestId != 0) {
                $contest = Contest::find($currentContestId);
                if (filled($contest)) {
                    $statistics = $statistics->where('cid', $currentContestId)->where("submission_date", "<", $contest->frozen_time);
                }
            } else {
                $statistics = $statistics->whereNull('cid');
            }

            $statistics = $statistics->first()->only(['submission_count', 'passed_count', 'ac_rate']);

            $statistics['submission_count'] = intval($statistics['submission_count']);
            $statistics['passed_count'] = intval($statistics['passed_count']);
            $statistics['ac_rate'] = floatval($statistics['ac_rate']);
            Cache::tags(['problem', 'statistics'])->put($problem->pid . '@' . $currentContestId, $statistics, 60);
        }
        return $statistics;
    }

    public static function getProblemStatus(Problem $problem, $userID = null, $contestID = null, Carbon $till = null)
    {
        if (filled($userID)) {
            $submission = ProblemService::getProblemStatusFromDB($problem, $userID, $contestID, $till);
            if (blank($submission)) {
                return [
                    "icon" => "checkbox-blank-circle-outline",
                    "color" => "wemd-grey-text",
                    "verdict" => null,
                    "compile_info" => null,
                    "score" => 0,
                ];
            } else {
                if (filled($contestID)) {
                    $contest = Contest::find($contestID);
                    if (filled($contest) && $contest->rule == 5) {
                        if (in_array($submission->verdict, [
                            "Runtime Error",
                            "Wrong Answer",
                            "Time Limit Exceed",
                            "Real Time Limit Exceed",
                            "Accepted",
                            "Memory Limit Exceed",
                            "Presentation Error",
                            "Partially Accepted",
                            "Output Limit Exceeded",
                            "Idleness Limit Exceed",
                        ])) {
                            // Turn into Judged Status
                            return [
                                "icon" => "checkbox-blank-circle",
                                "color" => "wemd-indigo-text",
                                "verdict" => "Judged",
                                "compile_info" => null,
                                "score" => 0,
                            ];
                        };
                    }
                }
                return [
                    "icon" => $submission->verdict == "Accepted" ? "checkbox-blank-circle" : "cisco-webex",
                    "color" => $submission->color,
                    "verdict" => $submission->verdict,
                    "compile_info" => $submission->compile_info,
                    "score" => $submission->score,
                ];
            }
        } else {
            return [
                "icon" => "checkbox-blank-circle-outline",
                "color" => "wemd-grey-text",
                "verdict" => null,
                "compile_info" => null,
                "score" => 0,
            ];
        }
    }

    /**
     * @return \App\Models\Eloquent\Submission
     */
    private static function getProblemStatusFromDB(Problem $problem, $userID, $contestID = null, Carbon $till = null)
    {
        $endedAt = Carbon::now();

        if (filled($contestID)) {
            try {
                $endedAt = Carbon::parse(Contest::findOrFail($contestID)->endedAt);
            } catch (Exception $e) {
                return null;
            }
        }

        // Get the very first AC record

        $acRecords = $problem->submissions()->where([
            'uid' => $userID,
            'cid' => $contestID,
            'verdict' => 'Accepted'
        ]);
        if (filled($contestID)) {
            $acRecords = $acRecords->where("submission_date", "<", $endedAt->timestamp);
        }
        if (filled($till)) {
            $acRecords = $acRecords->where("submission_date", "<", $till->timestamp);
        }
        $acRecords = $acRecords->orderBy('submission_date', 'desc')->first();
        if (blank($acRecords)) {
            $pacRecords = $problem->submissions()->where([
                'uid' => $userID,
                'cid' => $contestID,
                'verdict' => 'Partially Accepted'
            ]);
            if (filled($contestID)) {
                $pacRecords = $pacRecords->where("submission_date", "<", $endedAt->timestamp);
            }
            if (filled($till)) {
                $pacRecords = $pacRecords->where("submission_date", "<", $till->timestamp);
            }
            $pacRecords = $pacRecords->orderBy('submission_date', 'desc')->first();
            if (blank($pacRecords)) {
                $otherRecords = $problem->submissions()->where([
                    'uid' => $userID,
                    'cid' => $contestID
                ]);
                if (filled($contestID)) {
                    $otherRecords = $otherRecords->where("submission_date", "<", $endedAt->timestamp);
                }
                if (filled($till)) {
                    $otherRecords = $otherRecords->where("submission_date", "<", $till->timestamp);
                }
                return $otherRecords->orderBy('submission_date', 'desc')->first();
            }
            return $pacRecords;
        } else {
            return $acRecords;
        }
    }

    public static function getLastSubmission(Problem $problem, int $userId, int $contestId = 0)
    {
        $lastUserSubmission = $problem->submissions()->where("uid", $userId);
        if ($contestId != 0) $lastUserSubmission = $lastUserSubmission->where("cid", $contestId);
        else $lastUserSubmission = $lastUserSubmission->whereNull("cid");
        return $lastUserSubmission->orderBy('submission_date', 'desc')->first();
    }

    public static function getPreferableCompiler(Problem $problem, int $userId, int $contestId = 0)
    {
        $ret = ['preferredId' => -1, 'submission' => ProblemService::getLastSubmission($problem, $userId, $contestId), 'compilers' => $problem->available_compilers];
        $lastUserSubmission = $ret['submission'];

        if (blank($lastUserSubmission)) {
            $lastUserSubmission = Submission::where('uid', $userId)->whereHas('problem.online_judge', function ($innerQuery) use ($problem) {
                $innerQuery->where(["oid" => $problem->online_judge->oid]);
            })->orderBy('submission_date', 'desc')->first();

            if (blank($lastUserSubmission)) {
                $lastUserSubmission = Submission::where('uid', $userId)->orderBy('submission_date', 'desc')->first();
                if (blank($lastUserSubmission)) {
                    return $ret;
                }
            }
        }
        $ret['preferredId'] = ProblemService::calcPreferredCompilerBasedOnSubmission($ret['compilers'], $lastUserSubmission);
        return $ret;
    }

    public static function calcPreferredCompilerBasedOnSubmission($availableCompilers, Submission $lastUserSubmission)
    {
        // try matching the precise compiler
        foreach ($availableCompilers as $index => $compiler) {
            if ($compiler->coid == $lastUserSubmission->coid) {
                return $index;
            }
        }
        // the precise compiler is dead, try mathcing other compiler with same lang
        foreach ($availableCompilers as $index => $compiler) {
            if ($compiler->lang == $lastUserSubmission->lang) {
                return $index;
            }
        }
        // same lang compilers are all dead, use other compiler within the same group
        foreach ($availableCompilers as $index => $compiler) {
            if ($compiler->comp == $lastUserSubmission->comp) {
                return $index;
            }
        }
        // the entire comp group dead
        return -1;
    }

    public static function list($filter, $uid = null)
    {
        $preQuery = Problem::where('hide', false);
        if ($filter['oj']) {
            $OJ = OJ::find($filter['oj']);
            if (blank($OJ) || !$OJ->status) {
                return null;
            }
            $preQuery = $preQuery->where(["OJ" => $filter['oj']]);
        }
        if ($filter['tag']) {
            $preQuery = $preQuery->whereHas('tags', function ($innerQuery) use ($filter) {
                $innerQuery->where(["tag" => $filter['tag']]);
            });
        }
        return $preQuery->orderBy("OJ", "ASC")->orderBy("order_index", "ASC")->orderBy(DB::raw("length(contest_id)"), "ASC")->orderBy("contest_id", "ASC")->orderBy(DB::raw("length(index_id)"), "ASC")->orderBy("index_id", "ASC")->orderBy("pcode", "ASC")->paginate(max(config('pagination.problem.per_page'), 1));
    }

    public static function createSolution(Problem $problem, int $user_id, string $content): bool
    {
        if ($problem->solutions()->where(['uid' => $user_id])->count()) {
            return false;
        } else {
            $solution = new ProblemSolution(['uid' => $user_id, 'content' => $content]);
            $solution->inteliAudit();
            return $problem->solutions()->save($solution) !== false;
        }
    }

    public static function updateSolution(Problem $problem, int $user_id, string $content): bool
    {
        $solution = $problem->solutions()->where(['uid' => $user_id])->first();
        if (blank($solution)) {
            return false;
        }
        $solution->content = $content;
        $solution->inteliAudit();
        return $solution->save() === true;
    }

    public static function removeSolution(Problem $problem, int $user_id): bool
    {
        $solution = $problem->solutions()->where(['uid' => $user_id])->first();
        if (blank($solution)) {
            return false;
        }
        return $solution->delete() === true;
    }
}
