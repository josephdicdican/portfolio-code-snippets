<?php

namespace App\Services\Groups;

use App\Nrb\NrbServices;
use App\Nrb\Language\Error;
use App\Nrb\Language\Message;
use App\Nrb\Mail\NrbMailConstants;

use App\Models\Group;
use App\Models\GroupImage;
use App\Models\GroupMember;
use App\Models\User;

use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\AuthServices;

use Illuminate\Support\Facades\Storage;

class GroupsServices extends NrbServices
{
    public function index($request)
    {
        $groups = Group::with('groupImage')
            ->whereHas('groupMembers', function($query) use ($request) {
                $query->userId($request->get('user_id', authUser()->id))->active();
            })
            ->nameLike($request->get('name'))
            ->orderBy($request->get('sort_by', 'updated_at'), $request->get('sort_type', 'desc'));

        $count = $groups->count();

        if ($request->get('limit')) {
            $groups = $groups->limit($request->get('limit'));
        }

        return $this->respondWithSuccess([
            'joined_groups' => $groups->get(),
            'group_count' => $count,
        ]);
    }

    public function show($id)
    {
        $group = Group::with('groupImage')->findOrFail($id);

        return $this->respondWithSuccess($group->toArray());
    }

    public function store($request)
    {
        $group = Group::create($request->only('name'));
        $message_code = Message::GROUP_CREATE_SUCCESS;

        if($request->has('email_addresses')) { // invite members if has email addresses
            $this->inviteMembers($request, $group->id);
            $message_code = Message::GROUP_CREATE_AND_SENT_INVITES;
        }

        if($request->hasFile('group_image')) {
            $group_image = Group::with('groupImage')->findOrFail($group->id);
            $image = $request->file('group_image');
            $filename = $group->id.'-'.time().'.'.$image->getClientOriginalExtension();
            $path = $image->storeAs('public/group_images', $filename, isStorageS3() ? 's3' : []);
    
            $group_image->groupImage()->save(new GroupImage(['filename' => $filename]));
            return $this->respondWithMessage($message_code, [
                'group' => $group,
                'group_image' => getStorageImageURL($path),
            ]);
        }

        return $this->respondWithMessage($message_code, $group);
    }

    public function update($request, $id)
    {
        $group = Group::findOrFail($id);
        $group->update($request->all());

        return $this->respondWithMessage(Message::GROUP_UPDATE_SUCCESS, $group);
    }

    public function addGroupImage($request, $id)
    {
        $group = Group::with('groupImage')->findOrFail($id);
        if($group->groupImage) { // deleting previous group image (only support 1 image for now)
            deleteFileIfExists('public/group_images/'.$group->groupImage->filename);
            $group->groupImage->delete();
        }

        $group_image = $request->file('group_image');
        $filename = $group->id.'-'.time().'.'.$group_image->getClientOriginalExtension();
        $path = $group_image->storeAs('public/group_images', $filename, isStorageS3() ? 's3' : []);

        $group->groupImage()->save(new GroupImage(['filename' => $filename]));

        return $this->respondWithSuccess([
            'group_id' => $group->id,
            'group_image' => getStorageImageURL($path),
        ]);
    }

    public function inviteMembers($request, $id)
    {
        $group = Group::with('createdBy')->findOrFail($id);

        $email_addresses = array_pluck($request->get('email_addresses'), 'email_address');

        // Remove email addresses that are already in the group members
        $users = User::emailAddress($email_addresses)->get();
        $group_member_user_ids = $group->groupMembers()->userId($users->pluck('id')->toArray())->get()->pluck('user_id')->toArray();
        $existing_members = User::whereIn('id', $group_member_user_ids)->get();
        $existing_members_email_addresses = $existing_members->pluck('email_address');

        // Remove existing members' email address
        $valid_email_addresses = collect($email_addresses)->diff($existing_members_email_addresses);

        $memberable_users_arr = array();
        $new_email_addresses  = array();

        foreach($valid_email_addresses as $vemail) {
            if($valid_email_addresses->isNotEmpty()) {
                if(User::emailAddress($vemail)->get()->isNotEmpty()) {
                    // existing email address
                    array_push($memberable_users_arr,User::emailAddress($vemail)->get()->toArray()[0]);
                } else {
                    // new email address - not registered
                    array_push($new_email_addresses, $vemail);
                }
            }
        }

        // Separate memberable users (existing but not members) from new email (for registration)
        $memberable_users = $valid_email_addresses->isNotEmpty() ? $memberable_users_arr : [];

        $new_users_invited = $existing_users_invited = [];

        if($new_email_addresses) {
            // create accounts for new email addresses and send group invitation
            // with Step 2 Registration link (web/mobile)
            foreach($new_email_addresses as $email) {
                $response = (new AuthServices())->register(new RegisterRequest([
                        'email_address' => $email,
                        'group_id' => $group->id,
                    ]))->getData();

                if($response->success) {
                    $new_users_invited[] = [
                        'user_id' => $response->data->id,
                        'status' => GroupMember::STATUS_PENDING,
                        'role' => GroupMember::ROLE_MEMBER,
                        'invitation_token' => generateUniqueToken($group->id.$response->data->id),
                    ];
                }
            }

            // Adding to the group members
            $group->groupMembers()->createMany($new_users_invited);
        }

        if($memberable_users) {
            // send group invitation
            // with link to the Group (web/mobile)
            foreach($memberable_users as $user) {
                $invitation_token = generateUniqueToken($group->id.$user['id']);
                $response = mailer()->sendGroupInvitation($user, $group, $invitation_token);

                if($response->getEmailStatus() == NrbMailConstants::SENT) {
                    $existing_users_invited[] = [
                        'user_id' => $user['id'],
                        'status' => GroupMember::STATUS_PENDING,
                        'role' => GroupMember::ROLE_MEMBER,
                        'invitation_token' => $invitation_token,
                    ];
                }
            }

            // Adding to the group members
            $group->groupMembers()->createMany($existing_users_invited);
        }

        return $this->respondWithMessage(Message::GROUP_MEMBERS_INVITE_SUCCESS, [
            'new_users_invited' => count($new_users_invited),
            'existing_users_invited'  => count($existing_users_invited),
            'group_members_already' => count($existing_members_email_addresses),
        ]);
    }

    public function resendInvitation($id, $member_id)
    {
        $group = Group::findOrFail($id);
        $group_member = $group->groupMembers()->with('user')->whereHas('user')->where('id', $member_id)->first();

        if(!$group_member) {
            return $this->respondWithError(Error::NOT_FOUND);
        }

        $mail_response = null;

        if(!$group_member->user->isVerifiedAndActive()) { // if not verified, send Invitation with Registration link
            $mail_response = mailer()->sendEmailVerification($group_member->user, $group->id);
        } else { // else send Group Invitation with accept/decline link
            $mail_response = mailer()->sendGroupInvitation($group_member->user, $group, $group_member->invitation_token);
        }

        if($mail_response && $mail_response->getEmailStatus() == NrbMailConstants::SENT) {
            return $this->respondWithMessage(Message::GROUP_MEMBER_INVITE_RESEND_SUCCESS);
        }

        return $this->respondWithError(Error::GROUP_MEMBER_INVITE_RESEND_FAILED);
    }

    public function searchMember($request, $id)
    {
        $group = Group::findOrFail($id);
        $name = $request->get('name');

        $group_members = $group->groupMembers()
            ->selectRaw('group_members.*, users.name, users.email_address, users.id as user_id')
            ->with('user.userImage')
            ->leftJoin('users', 'users.id', '=', 'group_members.user_id')
            ->where('group_members.role', '=', GroupMember::ROLE_MEMBER)
            ->active()
            ->like('name', "%$name%")
            ->orderBy($request->get('sort_by', 'updated_at'), $request->get('sort_type', 'desc'));

        if ($request->get('limit')) {
            $group_members = $group_members->limit($request->get('limit'));
        }

        return $this->respondWithSuccess($group_members->get());
    }

    public function showMembers($request, $id)
    {
        $group = Group::findOrFail($id);

        $group_members = $group->groupMembers()
            ->selectRaw('group_members.*, users.name, users.email_address, users.id as user_id')
            ->with('user.userImage')
            ->leftJoin('users', 'users.id', '=', 'group_members.user_id')
            ->activeAndPending()
            ->role($request->get('role'))
            ->status($request->get('status'))
            ->orderBy($request->get('sort_by', 'updated_at'), $request->get('sort_type', 'desc'))
            ->paginate($request->get('per_page'));

        return $this->respondWithData($group_members);
    }

    public function processInvite($request)
    {
        $group_member_invitation = GroupMember::invitationToken($request->get('token'))->first();

        if($group_member_invitation && $group_member_invitation->user_id == authUser()->id) {
            $group_member_invitation->status = GroupMember::STATUS_DECLINED;
            $message_code = Message::GROUP_INVITATION_DECLINED;

            if($request->get('accept')) {
                $group_member_invitation->status = GroupMember::STATUS_ACTIVE;
                $group_member_invitation->invitation_token = null;
                $message_code = Message::GROUP_INVITATION_ACCEPTED;
                $group_member_invitation->save();
            } else {
                $group_member_invitation->delete(); // remove to avoid conflict on reinvite
            }

            return $this->respondWithMessage($message_code);
        }

        return $this->respondWithError(Error::GROUP_INVITATION_TOKEN_INVALID);
    }

    public function showInvitationDetails($token)
    {
        $group_member = GroupMember::with('group')
            ->invitationToken($token)->userId(authUser()->id)->first();

        if($group_member) {
            $user = User::select('id', 'name', 'email_address')
                ->verifiedAndActive()
                ->findOrFail($group_member->created_by);

            return $this->respondWithSuccess([
                'inviter' => $user,
                'group' => $group_member->group,
            ]);
        }

        return $this->respondWithError(Error::NOT_FOUND);
    }

    public function destroy($id)
    {
        $group = Group::findOrFail($id);
        $group->delete();

        return $this->respondWithMessage(Message::GROUP_DELETED_SUCCESS);
    }

    public function leaveGroup($id)
    {
        $group = Group::findOrFail($id);
        $group_member = $group->groupMembers()->userId(authUser()->id)->first();

        if(!$group_member) {
            return $this->respondWithError(Error::NOT_FOUND);
        }

        $is_admin_left = $group_member->isAdmin();

        if($is_admin_left) { // If admin remove the group, the whole group must be deleted
            $group->delete();
            $message_code = [
                Message::GROUP_AUTO_DELETED
            ];
        } else { // Delete only the member if the group_member is not admin
            $group_member->delete();
            $message_code = [
                Message::GROUP_MEMBER_LEFT_SUCCESS
            ];
        }

        return $this->respondWithMessage($message_code);
    }

    public function removeMember($id, $member_id)
    {
        $group = Group::findOrFail($id);
        $group_member = $group->groupMembers()->userId($member_id)->first();

        if($group_member && $group_member->isGroupCreator()) {
            return $this->respondWithError(Error::GROUP_CANT_REMOVE_CREATOR);
        }

        if(!$group_member) {
            return $this->respondWithError(Error::NOT_FOUND);
        }

        $group_member->delete();

        return $this->respondWithMessage(Message::GROUP_MEMBER_REMOVED_SUCCESS);
    }
}