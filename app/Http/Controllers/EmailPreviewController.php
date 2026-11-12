<?php

namespace App\Http\Controllers;

use App\Support\EmailPreviews;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The local-only page for looking at every email the app sends. Its routes
 * are only registered in the local environment.
 */
class EmailPreviewController extends Controller
{
    /**
     * The preview page: the list of emails beside the selected one.
     */
    public function index(Request $request): View
    {
        $previews = collect(EmailPreviews::all());
        $selected = $previews->has($request->query('email')) ? $request->query('email') : $previews->keys()->first();

        return view('dev.email-previews', [
            'groups' => $previews->map(fn (array $preview): string => $preview['name'])->groupBy(
                fn (string $name, string $key): string => $previews[$key]['group'],
                preserveKeys: true,
            ),
            'selected' => $selected,
            'name' => $previews[$selected]['name'],
            'subject' => EmailPreviews::message($selected)->subject,
            'from' => config('mail.from.name').' <'.config('mail.from.address').'>',
        ]);
    }

    /**
     * One email exactly as it would be sent, shown inside the preview frame.
     */
    public function show(string $email): Response
    {
        $message = EmailPreviews::message($email) ?? abort(404);

        return response($message->render());
    }
}
