<?php
namespace App\Http\Controllers\Front;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pusher\Pusher;
use App\Helpers\Hasher;
use App\Http\Requests\VideoChat\VideoChatAuthRequest;
use App\Models\Chat\GeneralConversation;
use App\Models\Chat\VideoChatHistory;
use Illuminate\Support\Facades\Validator;
use App\Models\PharmacyAppointments\Appointment;
use App\Services\AntMediaService;
use App\Models\FirebaseToken\FirebaseToken;
use App\Http\Traits\FirebaseMessagingNewTrait;   //for firebase notification

class VideoChatController extends GenericAppApiController
{
    use FirebaseMessagingNewTrait;

    public function __construct()
    {
        $this->middleware('auth:web',['except' => ['authApp','generateAntmediaToken','checkRoom','validateAntmediaToken']]);
    }

    /**
     * Enter in video chat room.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $appointment_ref
     * @return \Illuminate\Support\Facades\View
     */
    public function index(Request $request,string $appointment_ref) {
        $user = $request->user();
        
        $appointment = Appointment::findByRef($appointment_ref);
        $otherUser = $appointment->patient_id == $user->id ? $appointment->doctor : $appointment->patient;
        return view('video_chat.index')->with([
            'user' => collect($user->only(['id', 'name', 'uuid'])),
            'appointment_ref' => $appointment_ref,
            'other_id' => $otherUser != null ? $otherUser->uuid : null
        ]);
    }
    /**
     * Auth for video chat room.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function auth(Request $request) {
        $user = $request->user();
        $socket_id = $request->socket_id;
        $channel_name = $request->channel_name;
        $pusher = new Pusher(
            config('broadcasting.connections.pusher.key'),
            config('broadcasting.connections.pusher.secret'),
            config('broadcasting.connections.pusher.app_id'),
            [
                'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                'encrypted' => true
            ]
        );
        return response(
            $pusher->presence_auth($channel_name, $socket_id, $user->id)
        );
    }

    /**
     * Check if the current user is accessible for conversation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function checkUserAccess(VideoChatAuthRequest $request){
        $user = $request->user();
        $conversation = GeneralConversation::findByRef($request->conversation_ref);
        if($conversation->owner_id != $user->id && $conversation->customer_id != $user->id){
            return $this->getApiblueprint()->setErrorMessage("You have not access to use this conversation")->jsonResponse();
        }

        if($conversation->owner_id != $request->user_id && $conversation->customer_id != $request->user_id){
            return $this->getApiblueprint()->setErrorMessage("This user is not related to this conversation")->jsonResponse();
        }

        if($conversation->status == 0){
            return $this->getApiblueprint()->setErrorMessage("This Conversation is not active. Need to change the status to active by the Doctor.")->jsonResponse();
        }

        return $this->getApiblueprint()->setSuccess(true)->setMessage("You can access this conversation.")->jsonResponse();
    }

    /**
     * Get Appointment's conversation list
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getAppointmentConversation(Request $request){
        $user = \Auth::user();
        if(!$user->isCustomer()){
            $conversations = GeneralConversation::where('owner_id',$user->id)->get();
            
            $demo = $conversations->reduce(function($demo,$conversation){
                
                $demo[$conversation->customer_id] = $conversation->uuid;
                return $demo;
            });
            return $demo;
        }
        
        $conversations = GeneralConversation::where('customer_id',$user->id)->get();
        
        
            $demo = $conversations->reduce(function($demo,$conversation){
                $demo[$conversation->owner_id] = $conversation->uuid;
                return $demo;
            });
            return $demo;
    }

    /**
     * Get Appointment's list for call
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Support\Facades\View
     */
    public function getAppointmentListForCall(Request $request){
        $user = \Auth::user();
        if($user->isDoctor()){
            $appointments = Appointment::where('doctor_id',$user->id)->get();
            
            return view('video_chat.appointments')->with([
                'appointments' => $appointments
            ]);
        }
        
        $appointments = Appointment::where('patient_id',$user->id)->get();
        
            return view('video_chat.appointments')->with([
                'appointments' => $appointments
            ]);
    }

    /**
     * Note video chat duration time
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Illuminate\Http\JsonResponse
     */
    public function videoDurationTimeNote(Request $request){
        $validator = Validator::make($request->all(),[
            'started_at' => 'required|date_format:H:i:s',
            'started_at' => 'required|date_format:H:i:s',
            'conversation_ref' => 'required'
        ]);
        if($validator->fails()){
            return $this->getApiblueprint()->setSuccess(false)->setValidatorMessage($validator)->jsonResponse();
        }
        $conversation = GeneralConversation::findByRef($request->conversation_ref);
        
        $videoChatHistory = new VideoChatHistory();
        $videoChatHistory->started_at = date('H:i:s',strtotime($request->started_at));
        $videoChatHistory->ended_at = date('H:i:s',strtotime($request->ended_at));
        $videoChatHistory->conversation_id = $conversation->id;
        $videoChatHistory->save();

        $startTime = \Carbon\Carbon::createFromFormat("H:i:s",$request->started_at);
        $endTime = \Carbon\Carbon::createFromFormat("H:i:s",$request->ended_at);
        $seconds = $startTime->diffInSeconds($endTime);

        $conversation->increment('video_duration',$seconds);

        return $this->getApiblueprint()->setSuccess(true)->setData($videoChatHistory->basicResponse())->setMessage("Time duration added in this conversation.")->jsonResponse();
    }

    /**
     * Generate Antmedia server token
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Illuminate\Http\JsonResponse
     */
    public function generateAntmediaToken(Request $request,AntMediaService $antMedia){
        $validator = Validator::make($request->all(),[
            'type' => 'required|in:play,publish',
            'roomId' => 'required',
            'called_by_me'=>'nullable|in:1,0'
        ],['type.in'=>'type must be either publish or play','called_by_me.in'=>'called_by_me must be either 0 or 1']);
        if($validator->fails()){
            return $this->getApiblueprint()->setSuccess(false)->setValidatorMessage($validator)->jsonResponse();
        }
        $user = \Auth::user();
        $appointment = Appointment::findByRef($request->roomId);
        if ($user->id != $appointment->patient_id && $user->id != $appointment->doctor_id) {
            return $this->getApiblueprint()->setErrorMessage("You have not access to do this Action.")->jsonResponse();
        }
        if($appointment->isAvailable() == false){
            return $this->getApiblueprint()->setErrorMessage("This appointment is not available now.")->jsonResponse();
        }
        $notification_user_id = $user->id == $appointment->patient_id ? $appointment->doctor_id : $appointment->patient_id;
        $request->merge(['appointment_id' => $appointment->id]);
        $streamID = $request->roomId."-".$user->uuid;
        $expireDate = strtotime("+1 hour");
        // $antMedia = $antMedia->generateToken($user->id,$streamID,(int)$expireDate,$request->type,$appointment->id,$request->roomId);
        $antMedia = $antMedia->getPublishPlayTokens($request);
        $antMedia['socketURL'] = config('antmedia.socketURL');
        $antMedia['httpURL'] = config('antmedia.httpURL');
        if($request->called_by_me){
            $this->notification($notification_user_id,$appointment->uuid);
        }
        
        return $this->getApiblueprint()->setData($antMedia)->setMessage("Token generated Successfully")->jsonResponse();
    }

    /**
     * Validate if antmedia token is valid
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Illuminate\Http\JsonResponse
     */
    public function validateAntmediaToken(Request $request,AntMediaService $antMedia){
        $validator = Validator::make($request->all(),[
            'type' => 'required|in:play,publish',
            'roomId' => 'required',
            'streamId' => 'required',
            'tokenId' => 'required'
        ],['type.in'=>'type must be either publish or play','called_by_me.in'=>'called_by_me must be either 0 or 1']);
        if($validator->fails()){
            return $this->getApiblueprint()->setSuccess(false)->setValidatorMessage($validator)->jsonResponse();
        }
        $user = \Auth::user();
        $appointment = Appointment::findByRef($request->roomId);
        if ($user->id != $appointment->patient_id && $user->id != $appointment->doctor_id) {
            return $this->getApiblueprint()->setErrorMessage("You have not access to do this Action.")->jsonResponse();
        }
        if($appointment->isAvailable() == false){
            return $this->getApiblueprint()->setErrorMessage("This appointment is not available now.")->jsonResponse();
        }
        $notification_user_id = $user->id == $appointment->patient_id ? $appointment->doctor_id : $appointment->patient_id;
        $streamID = $request->roomId."-".$user->uuid;
        
        $antMedia = $antMedia->validateToken($request->token,$streamID,12345,$request->type,$request->roomId);
        $response = $this->getApiblueprint();
        if($antMedia){
            $response->setStatus(true)->setMessage("Token is valid")->jsonResponse();
        }    
       
        return $response->setStatus(false)->setMessage("Token is not valid")->jsonResponse();
    }

    /**
     * Check the number of user available in the room of antmedia server
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Illuminate\Http\JsonResponse
     */
    public function checkRoom($roomId,AntMediaService $antMedia){
        
        $user = \Auth::user();
        $appointment = Appointment::findByRef($roomId);
        if ($user->id != $appointment->patient_id && $user->id != $appointment->doctor_id) {
            return $this->getApiblueprint()->setErrorMessage("You have not access to do this Action.")->jsonResponse();
        }
        if($appointment->isAvailable() == false){
            return $this->getApiblueprint()->setErrorMessage("This appointment is not available now.")->jsonResponse();
        }

        $antMedia = $antMedia->roomInfo($roomId);
        
        return $this->getApiblueprint()->setData($antMedia)->setMessage("Room Information")->jsonResponse();
    }

    /**
     * Send firebase notification
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Illuminate\Http\Response
     */
    private function notification($userId,$roomId,$message=""){
        $userFirebaseData = FirebaseToken::where('customer_id',$userId)->pluck('firebase_token')->toArray();
        
        $dataArray = [
            'message'=> $message,
            'appointment_ref'=> $roomId,
            'click_action' => "FLUTTER_NOTIFICATION_CLICK"
        ];

        $body = "Hey, You have a call please join";

        $result = $this->sendFireBaseMsg($userFirebaseData, "Video Call", $body, $dataArray);
        return $result;
    }
}
