<?php
/** @file UserController.php
 *
 *  The user controller holds the functionality to register new users, login existing users and fetch details of users.
 */

namespace App\Http\Controllers\API;

use Auth;
use App\User;
use App\Beer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Auth\VerifiesEmails;
use Validator;

class UserController extends Controller
{
    use VerifiesEmails;

    public $successStatus = 200;
    public $errorStatus = 400;
    public $unauthorisedStatus = 401;

    /**
     * @par API\UserController@login (POST)
     * Login to the application.
     *
     * @param email     Email address of the user
     * @param password  Password of the user
     *
     * @retval JSON     Unauthorised
     * @retval JSON     Token to identify the logged in user
     */
    public function login()
    {
        if(Auth::attempt(['email' => request('email'), 'password' => request('password')])) {
            $user = Auth::user();
            $success['token'] = $user->createToken('MyStudentHouse')->accessToken;
            Log::info("Login for user ID ". $user->id ." succeeded");
            return response()->json(['success' => $success], $this->successStatus);
        } else {
            Log::info("Login for email ". request('email') ." failed");
            return response()->json(['error' => 'Login failed. Please check your credentials.'], $this->errorStatus);
        }
    }

    /**
     * @par API\UserController@logout (POST)
     * Perform a POST (to prevent XSS) to this route to log the user out.
     *
     * @return JSON     Success
     */
    public function logout(Request $request)
    {
        if(Auth::check()) {
            $user = Auth::user();
            $user->token()->revoke();
            Log::info("User ID ". $user->id ." log out successful");
            return response()->json(['success' => 'You are successfully logged out'],  $this->successStatus);
        } else {
            Log::info("Log out failed");
            return response()->json(['error' => 'Logout failed'],  $this->errorStatus);
        }
    }

    /**
     * @par API\UserController@register (POST)
     * Register a new user.
     *
     * @param name              Name of the new user
     * @param email             Email address of the new user
     * @param password          Password of the new user
     * @param confirm_password  Should be the same value as 'password'
     *
     * @retval JSON     Success
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'email' => 'required|email|unique:users,email',
            'password' => 'required',
            'confirm_password' => 'required|same:password',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], $this->errorStatus);
        }

        $input = $request->all();
        $input['password'] = bcrypt($input['password']);
        $user = User::create($input);

        $success['token'] = $user->createToken('MyStudentHouse')->accessToken;
        $success['name'] = $user->name;

        $user->sendEmailVerificationNotification();

        Log::info("Registration for user ID ". $user->id ." succeeded");
        return response()->json(['success' => $success], $this->successStatus);
    }

    /**
     * @par API\UserController@getDetails (POST)
     * Retrieve the details of the current user.
     *
     * @retval JSON     Success
     */
    public function getDetails()
    {
        if(Auth::check()) {
            $user = Auth::user();
            Log::info("Obtaining details for user ID ". $user->id ." succeeded");
            return response()->json(['success' => $user], $this->successStatus);
        } else {
            Log::info("Obtaining details failed");
            return response()->json(['failed' => ''], $this->errorStatus);
        }
    }

    public function getDetailsPerUserId($user_id)
    {
        $user = DB::table('users')->where('id', $user_id)->get();
        return $user[0];
    }

    /**
     * @par API\UserController@updateDetails (POST)
     * Update the details of the current user.
     *
     * @param avatar    Profile image of the user (not required to be posted)
     * @param email     Email address of the user (not required to be posted)
     * @param phone     Phone number of the user (not required to be posted)
     * @param iban      IBAN of the user (not required to be posted)
     *
     * @retval JSON     Error 412
     * @retval JSON     Success 200
     */
    public function updateDetails(Request $request)
    {
        if ($request->hasFile('avatar') && !$request->file('avatar')->isValid()) {
            return response()->json(['error' => 'Uploaded avatar not valid'], $this->errorStatus);   
        }

        $validator = Validator::make($request->all(), [
            // Only validate when posted
            'avatar' => 'sometimes|mimes:jpeg,png|max:1024',
            'email' => 'sometimes|email|unique:users,email',
            'phone' => 'sometimes|digits:10',
            'iban' => 'sometimes|size:18',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], $this->errorStatus);
        }

        $user = Auth::user();
        $original_user = $user;
        if ($request->has('avatar')) {
            $user->image = Storage::putFile('avatars', $request->file('avatar'));
        }
        if ($request->has('email')) {
            $user->email = $request->input('email');
        }
        if ($request->has('phone')) {
            $user->phone = $request->input('phone');
        }
        if ($request->has('iban')) {
            $user->iban = $request->input('iban');
        }
        $user->save();

        // Send verification notification to new email address if the email address was updated
        /* TODO(PATBRO): if new email address is entered incorrectly, the email address cannot be 
         * changed back if this route requires a verified email address. Exclude from verfied route?
         */
        if ($request->has('email') && ($original_user->email != $user->email)) {
            $user->sendEmailVerificationNotification();
        }

        Log::info("Details for user ID ". $user->id ." were updated successfully");
        return response()->json(['success' => $user], $this->successStatus);
    }
}
?>
