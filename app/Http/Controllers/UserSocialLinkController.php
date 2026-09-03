<?php

namespace App\Http\Controllers;

use App\Models\UserSocialLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserSocialLinkController extends Controller
{
    //
    public function create(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return back()->with('error', 'Unauthorized');
        }

        $data = $request->validate([
            'title' => 'required|string|max:80',
            'url' => UserSocialLink::URL_RULES,
        ]);

        UserSocialLink::create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'url' => $data['url'],
            'sort_order' => UserSocialLink::where('user_id', $user->id)->max('sort_order') + 1,
        ]);

        return redirect()->back()->with('success', 'Social link added');
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return back()->with('error', 'Unauthorized');
        }

        $link = UserSocialLink::findOrFail($id);
        if ((int) $link->user_id !== (int) $user->id) {
            return back()->with('error', "You can't edit this link");
        }

        $data = $request->validate([
            'title' => 'required|string|max:80',
            'url' => UserSocialLink::URL_RULES,
        ]);

        $link->update($data);

        return redirect()->back()->with('success', 'Social link updated');
    }

    public function delete($id)
    {
        $user = Auth::user();
        if (!$user) {
            return back()->with('error', 'Unauthorized');
        }

        $link = UserSocialLink::findOrFail($id);
        if ((int) $link->user_id !== (int) $user->id) {
            return back()->with('error', "You can't delete this link");
        }

        $link->delete();
        return redirect()->back()->with('success', 'Social link deleted');
    }
    public function reorderSocialLinks(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'links' => 'required|array',
            'links.*' => 'integer|exists:user_social_links,id',
        ]);

        $linkIds = $request->input('links');

        // Verify all links belong to the authenticated user
        $userLinks = UserSocialLink::where('user_id', $user->id)
            ->whereIn('id', $linkIds)
            ->pluck('id')
            ->toArray();

        if (count($userLinks) !== count($linkIds)) {
            return response()->json(['error' => 'Invalid link IDs'], 403);
        }

        // Update the order
        foreach ($linkIds as $index => $linkId) {
            UserSocialLink::where('id', $linkId)
                ->where('user_id', $user->id)
                ->update(['sort_order' => $index]);
        }

        return response()->json(['success' => true]);
    }
}
