<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpertQuestion;
use App\Models\SalesKnowledge;
use App\Services\SalesAssistant\ExpertDesk;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI team → Expert questions: what the AI asked people, who answered and how (accepted the AI's idea, fixed it,
 * wrote it), and the knowledge drafts made from the answers, which sales approves here.
 */
class ExpertQuestionController extends Controller
{
    public const FILTERS = ['open' => 'Open', 'review' => 'Knowledge to approve', 'answered' => 'Answered', 'unknown' => 'Not known', 'all' => 'All'];

    public function index(Request $request)
    {
        $filter = is_string($request->query('filter')) && array_key_exists($request->query('filter'), self::FILTERS) ? $request->query('filter') : 'open';
        $questions = ExpertQuestion::whereNull('parent_id')
            ->when($filter === 'open', fn ($q) => $q->whereIn('status', ExpertQuestion::OPEN))
            ->when($filter === 'review', fn ($q) => $q->where('status', 'answered')->whereHas('knowledge', fn ($k) => $k->where('is_active', false)))
            ->when($filter === 'answered', fn ($q) => $q->where('status', 'answered'))
            ->when($filter === 'unknown', fn ($q) => $q->where('status', 'unknown'))
            ->with('expert', 'answerer', 'customer', 'conversation', 'knowledge')->withCount(['children' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->latest('id')->paginate(30)->withQueryString();

        $answered = ExpertQuestion::whereNull('parent_id')->where('status', 'answered')->whereNotNull('verdict');

        return view('admin.ai-team.expert-questions', [
            'questions' => $questions, 'filter' => $filter,
            'counts' => ['open' => ExpertQuestion::whereNull('parent_id')->open()->count(),
                'review' => ExpertQuestion::whereNull('parent_id')->where('status', 'answered')->whereHas('knowledge', fn ($k) => $k->where('is_active', false))->count()],
            // How often the AI's own idea was right: the training signal.
            'ideas' => ['accepted' => (clone $answered)->where('verdict', 'accepted')->count(), 'fixed' => (clone $answered)->where('verdict', 'fixed')->count(),
                'written' => (clone $answered)->where('verdict', 'written')->count()],
            'experts' => ExpertQuestion::experts(),
            'admin' => ExpertQuestion::mainAdmin(),
        ]);
    }

    /** Sales approves the knowledge draft (optionally edited): the AI uses it from now on. */
    public function approve(Request $request, ExpertQuestion $question)
    {
        $knowledge = $question->knowledge;
        if (! $knowledge) {
            return back()->withErrors(['knowledge' => 'This question has no knowledge draft.']);
        }
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(SalesKnowledge::KINDS))],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:8000', 'not_regex:/\[TO FILL/i'],
        ]);
        $knowledge->update($data + ['is_active' => true]);

        return back()->with('success', 'Approved: the AI uses this knowledge from now on.');
    }

    /** Drops an open question (e.g. the customer no longer needs it). */
    public function cancel(ExpertQuestion $question)
    {
        $holder = $question->status === 'asked' ? $question->expert : null;
        foreach ($question->family() as $one) {
            if (in_array($one->status, [...ExpertQuestion::OPEN, 'grouped'], true)) {
                $one->update(['status' => 'cancelled', 'pending' => null]);
            }
        }
        rescue(fn () => app(ExpertDesk::class)->cancelled($question, $holder)); // the expert is told and gets the next question

        return back()->with('success', 'Question cancelled.');
    }
}
