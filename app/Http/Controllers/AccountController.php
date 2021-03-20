<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Proxy;
use App\Models\Statistic;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InstagramAPI\Constants;
use InstagramAPI\Exception\AccountDisabledException;
use InstagramAPI\Exception\ChallengeRequiredException;
use InstagramAPI\Exception\CheckpointRequiredException;
use InstagramAPI\Exception\FeedbackRequiredException;
use InstagramAPI\Exception\IncorrectPasswordException;
use InstagramAPI\Exception\InvalidSmsCodeException;
use InstagramAPI\Exception\InvalidUserException;
use InstagramAPI\Exception\SentryBlockException;
use InstagramAPI\Instagram;
use InstagramAPI\Response\LoginResponse;

class AccountController extends Controller
{

    public function index(Request $request)
    {
        $data = Account::withCount([
            'messages_on_queue',
            'messages_sent',
            'messages_failed',
        ])
            ->orderByDesc('id')
            ->paginate(9);

        return view('account.index', compact(
            'data'
        ));
    }

    public function create(Request $request)
    {
        $accountsLimit = $request->user()->package->accounts_limit;
        $hasAccounts   = $request->user()->accounts()->count();
        $needUpgrade   = $hasAccounts >= $accountsLimit && !$request->user()->can('admin') ? true : false;

        return view('account.create', compact(
            'needUpgrade'
        ));
    }

    public function store(Request $request)
    {
        $request->validate([
            'username' => 'required|max:255|unique:accounts,username|regex:/^([a-z0-9_][a-z0-9_\.]{1,28}[a-z0-9_])$/',
            'password' => 'required|max:255',
        ]);

        // Delete account related API folder before adding
        File::deleteDirectory(storage_path('instagram' . DIRECTORY_SEPARATOR . $request->username));

        $proxy = null;

        // System proxy don't takes precedence over user's proxy
        if (config('pilot.SYSTEM_PROXY') && !$request->filled('proxy')) {

            $proxy = Proxy::active()
                ->withCount(['accounts' => function ($q) {
                    $q->withoutGlobalScopes();
                }])
                ->orderBy('accounts_count')
                ->first();
        }

        // If user use own proxy
        if (config('pilot.CUSTOM_PROXY') && $request->filled('proxy')) {

            // Check for proxy validity
            try {

                $client = new GuzzleClient();
                $client->request('GET', 'https://www.instagram.com', [
                    'idn_conversion' => false,
                    'exceptions'     => false,
                    'proxy'          => $request->proxy,
                    'verify'         => true,
                    'timeout'        => 10,
                ]);

                // Create custom proxy record
                $proxy = Proxy::firstOrCreate([
                    'server'    => $request->proxy,
                    'is_active' => true,
                ]);

            } catch (\Exception $e) {

                return redirect()->route('account.create')
                    ->with('error', __('Can\'t connect to proxy'));
            }

        }

        $account = Account::create([
            'user_id'   => $request->user()->id,
            'proxy_id'  => $proxy->id ?? null,
            'username'  => $request->username,
            'password'  => $request->password,
            'is_active' => false,
        ]);

        return redirect()->route('account.confirm', $account);
    }

    public function confirm(Account $account)
    {
        $instagram = new Instagram(config('pilot.debug'), config('pilot.truncatedDebug'), config('pilot.storageConfig'));

        if ($account->proxy) {
            $instagram->setProxy($account->proxy->server);
        }

        try {

            $loginResponse = $instagram->login($account->username, $account->password);

            if ($loginResponse !== null && $loginResponse->isTwoFactorRequired()) {

                $getTwoFactorInfo      = $loginResponse->getTwoFactorInfo();
                $two_factor_identifier = $getTwoFactorInfo->getTwoFactorIdentifier();
                $phone                 = $getTwoFactorInfo->getObfuscatedPhoneNumber();

                return redirect()->route('account.2fa', [
                    'account'               => $account,
                    'two_factor_identifier' => $two_factor_identifier,
                    'phone'                 => $phone,
                ]);

            }

            if ($loginResponse instanceof LoginResponse || $loginResponse === null) {

                $account->is_active = true;
                $account->save();

                return redirect()->route('account.index')
                    ->with('success', __('Account has been successfully confirmed.'));

            }

        } catch (ChallengeRequiredException $e) {

            try {

                $api_path = $e->getResponse()->getChallenge()->getApiPath();

                return redirect()->route('account.challenge.choice', [
                    'account'  => $account,
                    'api_path' => $api_path,
                ]);

            } catch (\Exception $e) {

                return redirect()->route('account.edit', $account)
                    ->with('error', __('Something went wrong: ') . $e->getMessage());
            }

        } catch (IncorrectPasswordException $e) {

            return redirect()->route('account.edit', $account)
                ->with('error', __('The password you entered is incorrect. Please try again.'));

        } catch (InvalidUserException $e) {

            return redirect()->route('account.edit', $account)
                ->with('error', __('The username you entered doesn\'t appear to belong to an account. Please check your username and try again.'));

        } catch (SentryBlockException $e) {

            return redirect()->route('account.edit', $account)
                ->with('error', __('Your account has been banned from Instagram API for spam behaviour or otherwise abusing.'));

        } catch (AccountDisabledException $e) {

            return redirect()->route('account.edit', $account)
                ->with('error', __('Your account has been disabled for violating Instagram terms. <a href="https://help.instagram.com/366993040048856" target="_blank">Click here</a> to learn how you may be able to restore your account.'));

        } catch (FeedbackRequiredException $e) {

            return redirect()->route('account.edit', $account)
                ->with('error', __('It looks like you were misusing this feature by going too fast.'));

        } catch (CheckpointRequiredException $e) {

            return redirect()->route('account.edit', $account)
                ->with('error', __('Your account is subject to verification checkpoint. Please go to <a href="http://instagram.com" target="_blank">instagram.com</a> and pass checkpoint!'));

        } catch (\Exception $e) {

            return redirect()->route('account.edit', $account)
                ->with('error', __('Something went wrong: ') . $e->getMessage());

        }

        return $response;
    }

    public function two_factor(Account $account)
    {
        return view('account.2fa', compact(
            'account'
        ));
    }

    public function two_factor_confirm(Request $request, Account $account)
    {
        $request->validate([
            'code'                  => 'required|digits:6',
            'two_factor_identifier' => 'required',
            'phone'                 => 'required',
        ]);

        $instagram = new Instagram(config('pilot.debug'), config('pilot.truncatedDebug'), config('pilot.storageConfig'));

        if ($account->proxy) {
            $instagram->setProxy($account->proxy->server);
        }

        try {

            $instagram->finishTwoFactorLogin(
                $account->username,
                $account->password,
                $request->two_factor_identifier,
                $request->code
            );

            $account->is_active = true;
            $account->save();

            return redirect()->route('account.index')
                ->with('success', __('Account has been successfully confirmed.'));

        } catch (ChallengeRequiredException $e) {

            try {

                $api_path = $e->getResponse()->getChallenge()->getApiPath();

                return redirect()->route('account.challenge.choice', [
                    'account'  => $account,
                    'api_path' => $api_path,
                ]);

            } catch (\Exception $e) {

                $request->session()->flash('error', __('Something went wrong: ') . $e->getMessage());

                return redirect()->route('account.2fa', [
                    'account'               => $account,
                    'two_factor_identifier' => $request->two_factor_identifier,
                    'phone'                 => $request->phone,
                ]);

            }

        } catch (InvalidSmsCodeException $e) {

            $request->session()->flash('error', __('Please check the security code sent you and try again.'));

            return redirect()->route('account.2fa', [
                'account'               => $account,
                'two_factor_identifier' => $request->two_factor_identifier,
                'phone'                 => $request->phone,
            ]);

        } catch (\Exception $e) {

            return redirect()->route('account.index')
                ->with('error', __('Something went wrong: ') . $e->getMessage());

        }

    }

    public function challenge_choice(Account $account)
    {
        return view('account.challenge_choice', compact(
            'account'
        ));
    }

    public function challenge_choice_confirm(Request $request, Account $account)
    {
        $request->validate([
            'choice'   => 'required|in:0,1',
            'api_path' => 'required',
        ]);

        $instagram = new Instagram(config('pilot.debug'), config('pilot.truncatedDebug'), config('pilot.storageConfig'));

        if ($account->proxy) {
            $instagram->setProxy($account->proxy->server);
        }

        try {

            $instagram->changeUser($account->username, $account->password);

            $challengeResponse = $instagram->sendChallangeCode($request->api_path, $request->choice);

            $challengeResponse = json_decode(json_encode($challengeResponse), true);

            if (isset($challengeResponse['action']) && strtoupper($challengeResponse['action']) === 'CLOSE') {

                $account->is_active = true;
                $account->save();

                return redirect()->route('account.index')
                    ->with('success', __('Account has been successfully confirmed.'));

            } else {

                if (strtoupper($challengeResponse['status']) === 'OK') {

                    if ($request->choice == Constants::CHALLENGE_CHOICE_SMS) {

                        $number  = $challengeResponse['step_data']['phone_number_formatted'];
                        $message = __('Enter the code sent to your number: ') . $number;

                    } else {

                        $email   = $challengeResponse['step_data']['contact_point'];
                        $message = __('Enter the 6-digit code sent to the email address: ') . $email;

                    }

                    $request->session()->flash('success', $message);

                    return redirect()->route('account.challenge', [
                        'account'  => $account,
                        'api_path' => $request->api_path,
                    ]);

                } elseif (strtoupper($challengeResponse['status']) === 'FAIL') {

                    return redirect()->route('account.edit', $account)
                        ->with('error', __('Challenge request failed') . ' ' . $challengeResponse['message']);

                } else {

                    return redirect()->route('account.edit', $account)
                        ->with('error', __('Could\'t send verification code for the login challenge! Please try again later!'));

                }
            }

        } catch (\Exception $e) {

            return redirect()->route('account.edit', $account)
                ->with('error', __('Something went wrong: ') . $e->getMessage());

        }
    }

    public function challenge(Account $account)
    {
        return view('account.challenge_confirm', compact(
            'account'
        ));
    }

    public function challenge_confirm(Request $request, Account $account)
    {
        $request->validate([
            'code'     => 'required|digits:6',
            'api_path' => 'required',
        ]);

        $instagram = new Instagram(config('pilot.debug'), config('pilot.truncatedDebug'), config('pilot.storageConfig'));

        if ($account->proxy) {
            $instagram->setProxy($account->proxy->server);
        }

        try {

            $challengeResponse = $instagram->finishChallengeLogin(
                $account->username,
                $account->password,
                $request->api_path,
                $request->code
            );

            $challengeResponse = json_decode(json_encode($challengeResponse), true);

            if (strtoupper($challengeResponse['status']) === 'OK') {

                $account->is_active = true;
                $account->save();

                return redirect()->route('account.index')
                    ->with('success', __('Account has been successfully confirmed.'));

            } else {

                $request->session()->flash('error', __('Please check the security code sent you and try again.'));

                return redirect()->route('account.challenge', [
                    'account'  => $account,
                    'api_path' => $request->api_path,
                ]);

            }

        } catch (\Exception $e) {

            $request->session()->flash('error', __('Please check the security code sent you and try again.'));

            return redirect()->route('account.challenge', [
                'account'  => $account,
                'api_path' => $request->api_path,
            ]);

        }

    }

    public function edit(Account $account)
    {
        return view('account.edit', compact(
            'account'
        ));
    }

    public function update(Request $request, Account $account)
    {
        if ($request->filled('password')) {
            $account->password = $request->password;
        }

        if ($request->filled('proxy')) {
            $proxy = Proxy::firstOrCreate([
                'server'    => $request->proxy,
                'is_active' => true,
            ]);
            $account->proxy_id = $proxy->id;
        } else {
            $account->proxy_id = null;
        }

        $account->save();

        return redirect()->route('account.edit', $account)
            ->with('success', __('Updated successfully'));
    }

    public function destroy(Account $account)
    {
        // Delete account related API folder
        File::deleteDirectory(storage_path('instagram' . DIRECTORY_SEPARATOR . $account->username));

        $account->messages_on_queue()->delete();
        $account->messages_sent()->delete();
        $account->messages_failed()->delete();
        $account->followers()->delete();
        $account->autopilot()->delete();
        $account->posts()->delete();
        $account->statistic()->delete();
        $account->bot->qa()->delete();
        $account->bot->delete();

        foreach ($account->rss()->get() as $rss) {
            $rss->items()->delete();
            $rss->delete();
        }

        $account->delete();

        return redirect()->route('account.index')
            ->with('success', __('Deleted successfully'));
    }

    public function export(Account $account, $type = 'followers')
    {
        if ($type == 'followers') {
            $data = $account->followers()->followers()->get();
        } else {
            $data = $account->followers()->following()->get();
        }

        return response()->streamDownload(function () use ($data) {
            echo $data->pluck('username')->join("\r\n");
        }, 'export-for-' . $account->username . '-' . $type . '.txt');

    }

    public function chart(Request $request, Account $account)
    {
        if (in_array($request->type, [
            config('pilot.STATISTICS_FOLLOWERS'),
            config('pilot.STATISTICS_FOLLOWING'),
            config('pilot.STATISTICS_MEDIA'),
        ])) {
            $type = $request->type;
        } else {
            $type = config('pilot.STATISTICS_FOLLOWERS');
        }

        $rise_data = DB::select(DB::Raw("SELECT
            `mt1`.`sync_at`,
            `mt1`.`count`,
            `mt1`.`type`,
            `mt1`.count - IFNULL(`mt2`.count, 0) AS rise
        FROM
            statistics mt1
        LEFT JOIN statistics mt2 ON `mt2`.`sync_at` = (
            SELECT
                MAX(`sync_at`)
            FROM
                statistics mt3
            WHERE
                `mt3`.account_id = '" . $account->id . "'
            AND `mt3`.user_id = '" . $request->user()->id . "'
            AND `mt3`.type = '" . $type . "'
            AND `mt3`.`sync_at` < `mt1`.`sync_at`
            AND `mt3`.`sync_at` BETWEEN DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY) AND CURRENT_DATE()
        )
        WHERE
            `mt1`.account_id = '" . $account->id . "'
        AND `mt2`.account_id = '" . $account->id . "'
        AND `mt1`.user_id = '" . $request->user()->id . "'
        AND `mt2`.user_id = '" . $request->user()->id . "'
        AND `mt1`.type = '" . $type . "'
        AND `mt2`.type = '" . $type . "'
        AND `mt1`.`sync_at` BETWEEN DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY) AND CURRENT_DATE()
        AND `mt2`.`sync_at` BETWEEN DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY) AND CURRENT_DATE()
        ORDER BY
            `mt1`.`sync_at`"));

        $rise = 0;
        if (isset($rise_data[0])) {
            if ($rise_data[0]->rise >= 0) {
                $rise = '+' . $rise_data[0]->rise;
            } else {
                $rise = '-' . abs($rise_data[0]->rise);
            }
        }

        switch ($type) {
            case config('pilot.STATISTICS_MEDIA'):

                $chart = Statistic::media()
                    ->where('account_id', $account->id)
                    ->orderBy('sync_at')
                    ->pluck('count')
                    ->prepend('data');

                $data = [
                    'chart'       => $chart,
                    'rise'        => $rise,
                    'total_count' => number_format($account->posts_count),
                ];

                break;

            case config('pilot.STATISTICS_FOLLOWING'):

                $chart = Statistic::following()
                    ->where('account_id', $account->id)
                    ->orderBy('sync_at')
                    ->pluck('count')
                    ->prepend('data');

                $data = [
                    'chart'       => $chart,
                    'rise'        => $rise,
                    'total_count' => number_format($account->following_count),
                ];

                break;

            default:

                $chart = Statistic::followers()
                    ->where('account_id', $account->id)
                    ->orderBy('sync_at')
                    ->pluck('count')
                    ->prepend('data');

                $data = [
                    'chart'       => $chart,
                    'rise'        => $rise,
                    'total_count' => number_format($account->followers_count),
                ];

                break;
        }

        return response()->json($data);
    }

}
