<?php

namespace Laravel\Spark\Http\Controllers\Settings;

use Exception;
use Laravel\Spark\Spark;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Laravel\Spark\Http\Controllers\Controller;
use Laravel\Spark\Events\Team\Deleting as DeletingTeam;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Laravel\Spark\Contracts\Repositories\TeamRepository;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;

class TeamController extends Controller
{
    use ValidatesRequests;

    /**
     * The team repository instance.
     *
     * @var \Laravel\Spark\Contracts\Repositories\TeamRepository
     */
    protected $teams;

    /**
     * Create a new controller instance.
     *
     * @param  \Laravel\Spark\Contracts\Repositories\TeamRepository  $teams
     * @return void
     */
    public function __construct(TeamRepository $teams)
    {
        $this->teams = $teams;

        $this->middleware('auth');
    }

    /**
     * Create a new team.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $this->validate($request, [
            'name' => 'required|max:255',
        ]);

        $team = $this->teams->create(
            $user, ['name' => $request->name]
        );

        return $this->teams->getAllTeamsForUser($user);
    }

    /**
     * Show the edit screen for a given team.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $teamId
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $teamId)
    {
        $user = $request->user();

        $team = $user->teams()->findOrFail($teamId);

        $activeTab = $request->get(
            'tab', Spark::firstTeamSettingsTabKey($team, $user)
        );

        return view('spark::settings.team', compact('team', 'activeTab'));
    }

    /**
     * Update the team's owner information.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $teamId
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $teamId)
    {
        $user = $request->user();

        $team = $user->teams()
                ->where('owner_id', $user->id)
                ->findOrFail($teamId);

        if (! is_null($response = $this->validateTeamUpdate($request))) {
            return $response;
        }

        if (Spark::$updateTeamsWith) {
            call_user_func(Spark::$updateTeamsWith, $request, $team);
        } else {
            $team->fill(['name' => $request->name])->save();
        }

        return $this->teams->getTeam($user, $teamId);
    }

    /**
     * Validate a team update request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    protected function validateTeamUpdate(Request $request)
    {
        if (Spark::$validateTeamUpdatesWith) {
            return $this->getResponseFromCustomValidator(
                Spark::$validateTeamUpdatesWith, $request
            );
        } else {
            $this->validate($request, [
                'name' => 'required|max:255',
            ]);
        }
    }

    /**
     * Switch the team the user is currently viewing.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $teamId
     * @return \Illuminate\Http\Response
     */
    public function switchCurrentTeam(Request $request, $teamId)
    {
        $user = $request->user();

        $team = $user->teams()->findOrFail($teamId);

        $user->switchToTeam($team);

        return back();
    }

    /**
     * Send an invitation for the given team.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $teamId
     * @return \Illuminate\Http\Response
     */
    public function sendTeamInvitation(Request $request, $teamId)
    {
        $user = $request->user();

        $this->validate($request, [
            'email' => 'required|max:255|email',
        ]);

        $team = $user->teams()
                ->where('owner_id', $user->id)
                ->findOrFail($teamId);

        if ($team->invitations()->where('email', $request->email)->exists()) {
            return response()->json(['email' => 'That user is already invited to the team.'], 422);
        }

        $team->inviteUserByEmail($request->email);

        return $team->fresh(['users', 'invitations']);
    }

    /**
     * Accept the given team invitation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $inviteId
     * @return \Illuminate\Http\Response
     */
    public function acceptTeamInvitation(Request $request, $inviteId)
    {
        $user = $request->user();

        $invitation = $user->invitations()->findOrFail($inviteId);

        $user->joinTeamById($invitation->team_id);

        $invitation->delete();

        return $this->teams->getAllTeamsForUser($user);
    }

    /**
     * Destroy the given team invitation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $teamId
     * @param  string  $inviteId
     * @return \Illuminate\Http\Response
     */
    public function destroyTeamInvitationForOwner(Request $request, $teamId, $inviteId)
    {
        $user = $request->user();

        $team = $user->teams()
                ->where('owner_id', $user->id)
                ->findOrFail($teamId);

        $team->invitations()->where('id', $inviteId)->delete();

        return $this->teams->getTeam($user, $teamId);
    }

    /**
     * Destroy the given team invitation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $inviteId
     * @return \Illuminate\Http\Response
     */
    public function destroyTeamInvitationForUser(Request $request, $inviteId)
    {
        $request->user()->invitations()->findOrFail($inviteId)->delete();
    }

    /**
     * Update a team member on the given team.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $teamId
     * @param  string  $userId
     * @return \Illuminate\Http\Response
     */
    public function updateTeamMember(Request $request, $teamId, $userId)
    {
        $user = $request->user();

        $team = $user->teams()
                ->where('owner_id', $user->id)->findOrFail($teamId);

        $userToUpdate = $team->users->find($userId);

        if (! $userToUpdate) {
            abort(404);
        }

        $availableRoles = implode(
            ',', array_except(array_keys(Spark::roles()), 'owner')
        );

        $this->validate($request, [
            'role' => 'required|in:'.$availableRoles
        ]);

        $userToUpdate->teams()->updateExistingPivot(
            $team->id, ['role' => $request->role]
        );

        return $this->teams->getTeam($user, $teamId);
    }

    /**
     * Remove a team member from the team.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $teamId
     * @param  string  $userId
     * @return \Illuminate\Http\Response
     */
    public function removeTeamMember(Request $request, $teamId, $userId)
    {
        $user = $request->user();

        $team = $user->teams()
                ->where('owner_id', $user->id)->findOrFail($teamId);

        $team->removeUserById($userId);

        return $this->teams->getTeam($user, $teamId);
    }

    /**
     * Remove the user from the given team.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $teamId
     * @return \Illuminate\Http\Response
     */
    public function leaveTeam(Request $request, $teamId)
    {
        $user = $request->user();

        $team = $user->teams()
                    ->where('owner_id', '!=', $user->id)
                    ->where('id', $teamId)->firstOrFail();

        $team->removeUserById($user->id);

        return $this->teams->getAllTeamsForUser($user);
    }

    /**
     * Destroy the given team.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $teamId
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $teamId)
    {
        $user = $request->user();

        $team = $request->user()->teams()
                ->where('owner_id', $user->id)
                ->findOrFail($teamId);

        event(new DeletingTeam($team));

        $team->users()->where('current_team_id', $team->id)
                        ->update(['current_team_id' => null]);

        $team->users()->detach();

        $team->delete();

        return $this->teams->getAllTeamsForUser($user);
    }
}
