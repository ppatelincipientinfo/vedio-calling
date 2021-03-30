<template>
  <div class="container">
    <h1 class="text-center">Laravel Video Chat</h1>
    <p class="video-info" ref="video-info" v-html="videoInfo"></p>
    <div class="video-container" ref="video-container">
      <video class="video-here" ref="video-here" autoplay></video>  
      <video class="video-there" ref="video-there" autoplay></video>
      <button id="closeVideo" @click="closeVideo()" class="end-bton">End call</button>
      <!-- <div class="text-right" v-for="(appointmentRef,userId) in conversations" :key="userId">
        <button @click="startVideoChat(userId,appointmentRef)" v-text="`Conversation: ${appointmentRef}`"/>
      </div> -->
      
      <button @click="startVideoChat(otherId,appointment_ref)" class="call-bton">Call</button>
      <button @click="pickupCall()" class="pickup-bton" :disabled="canPickup ? null : true">Pickup</button>
    </div>
  </div>
</template>
<script>
// import Pusher from 'pusher-js';
// import Peer from 'simple-peer';
export default {
  props: ['user', 'conversations', 'pusherKey', 'pusherCluster','appointment_ref','otherId'],
  data() {
    return {
      channel: null,
      stream: null,
      peers: {},
      otherUserId: null,
      connection: null,
      yourConn: null,
      canPickup: false,
      videoInfo:null
    }
  },
  mounted() {
    this.setupVideoChat();
  },
  created: function() {
    this.connection = new WebSocket("wss://turn.websocket.server:3001/connect")
    var thisInstance = this;
    
    var thisUser = this.user;

    this.connection.onopen = function(event) {
      var jsonData = {
        type:"new",
        data: {
          id: thisUser.uuid,
          otherid: thisInstance.otherId,
          name:thisUser.name,
          user_agent:navigator.userAgent,
          token:thisInstance.token
          appointment_ref: thisInstance.appointment_ref
        }
      }
      thisInstance.connection.send(JSON.stringify(jsonData))      
      console.log("Successfully connected to the echo websocket server...")
    }

    this.connection.onmessage = function (msg) { 
      
      var data = JSON.parse(msg.data); 
      
          switch(data.type) { 
              case "new": 
                // thisInstance.handleLogin(data.success); 
                break; 
              //when somebody wants to call us 
              case "offer": 
                thisInstance.handleOffer(data.offer, data.id); 
                break; 
              case "answer": 
                thisInstance.handleAnswer(data.answer); 
                break; 
              //when a remote peer sends an ice candidate to us 
              case "candidate": 
                thisInstance.handleCandidate(data.candidate); 
                break; 
              case "bye": 
                thisInstance.handleLeave(); 
                break; 
                case "info": 
                thisInstance.handleInfo(data.message); 
                break;
              default: 
                break; 
          }
    };

  },
  methods: {
    startVideoChat(userId,appointmentRef) {
      var thisInstance = this;
      var thisUser = thisInstance.user;
      
      // create an offer
      thisInstance.yourConn.createOffer(function (offer) { 
        var jsonData = {
          type:"offer",
          data: {
            id: thisInstance.otherId,
            user_agent:navigator.userAgent,
            appointment_ref: thisInstance.appointment_ref,
            offer:offer,
            token:thisInstance.token
          }
        }
         thisInstance.send(jsonData)
			
         thisInstance.yourConn.setLocalDescription(offer); 
			
      }, function (error) { 
         alert("Error when creating an offer"); 
      });  
      
      var thisInstance = this;
      return;     

    },
    pickupCall(){
      var thisInstance = this;
      thisInstance.yourConn.createAnswer(function (answer) { 
          thisInstance.yourConn.setLocalDescription(answer); 
        
          thisInstance.send({
            type: "answer", 
            data:{
              id:thisInstance.otherId,
              answer: answer,
              appointment_ref: thisInstance.appointment_ref,
              token:"Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpdjIuYnJvd25zLnBoYXJtYWN5XC9hcGlcL2F1dGhcL2FwcC1sb2dpbiIsImlhdCI6MTYxMDEwMDE2OCwiZXhwIjoxNjEwNDYwMTY4LCJuYmYiOjE2MTAxMDAxNjgsImp0aSI6InVRbE5iZ0xheGpqWWx6R2kiLCJzdWIiOjYzNCwicHJ2IjoiODdlMGFmMWVmOWZkMTU4MTJmZGVjOTcxNTNhMTRlMGIwNDc1NDZhYSJ9.LYSUfcUGReg9m11S0zNZqSUMtweKvilNd3Yt7Nqne5w"
            } 
          }); 
        
      }, function (error) { 
          alert("Error when creating an answer"); 
      });
    },
    async setupVideoChat() {
      // To show pusher errors
      // Pusher.logToConsole = true;
      var thisInstance = this;
      navigator.mediaDevices.getUserMedia = navigator.mediaDevices.getUserMedia ||
      navigator.webkitGetUserMedia ||
      navigator.mozGetUserMedia;
      
      const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
      const videoHere = this.$refs['video-here'];
      videoHere.srcObject = stream;
      this.stream = stream;
      //using Google public stun server 
            var configuration = { 
              iceTransportPolicy: "relay",
                iceServers: [
                {
                  urls:"turn:turn.websocket.server",
                  username:'urvish',
                  credential: '123123'
                }
              ],
              sdpSemantics: 'unified-plan'
            }; 
          
            thisInstance.yourConn = new RTCPeerConnection(configuration); 
          
            // setup stream listening 
            thisInstance.yourConn.addStream(stream); 
          
            //when a remote user adds stream to the peer connection, we display it 
            thisInstance.yourConn.onaddstream = function (e) { 
              const remoteVideo = thisInstance.$refs['video-there'];
                remoteVideo.srcObject = e.stream; 
            };
          
            // Setup ice handling 
            thisInstance.yourConn.onicecandidate = function (event) { 
                if (event.candidate) { 
                  
                  thisInstance.send({
                      
                      type: "candidate",
                      data:{
                        id:thisInstance.otherId, 
                        candidate: event.candidate ,
                        appointment_ref: thisInstance.appointment_ref,
                        token:"Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpdjIuYnJvd25zLnBoYXJtYWN5XC9hcGlcL2F1dGhcL2FwcC1sb2dpbiIsImlhdCI6MTYxMDEwMDE2OCwiZXhwIjoxNjEwNDYwMTY4LCJuYmYiOjE2MTAxMDAxNjgsImp0aSI6InVRbE5iZ0xheGpqWWx6R2kiLCJzdWIiOjYzNCwicHJ2IjoiODdlMGFmMWVmOWZkMTU4MTJmZGVjOTcxNTNhMTRlMGIwNDc1NDZhYSJ9.LYSUfcUGReg9m11S0zNZqSUMtweKvilNd3Yt7Nqne5w"
                      } 
                      
                  }); 
                } 
            };  
    },
    closeVideo() {
      this.send({
                      
                      type: "bye",
                      data:{
                        from:this.user.uuid, 
                        to:this.otherId,
                        appointment_ref: this.appointment_ref,
                        token:"Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYXBpdjIuYnJvd25zLnBoYXJtYWN5XC9hcGlcL2F1dGhcL2FwcC1sb2dpbiIsImlhdCI6MTYxMDEwMDE2OCwiZXhwIjoxNjEwNDYwMTY4LCJuYmYiOjE2MTAxMDAxNjgsImp0aSI6InVRbE5iZ0xheGpqWWx6R2kiLCJzdWIiOjYzNCwicHJ2IjoiODdlMGFmMWVmOWZkMTU4MTJmZGVjOTcxNTNhMTRlMGIwNDc1NDZhYSJ9.LYSUfcUGReg9m11S0zNZqSUMtweKvilNd3Yt7Nqne5w"
                      } 
                      
                  });
                  this.canPickup=false;
      // this.peers[this.otherUserId]._events.close();
    },
    videoDurationNote(appointmentRef) {
      axios.post(
        '/videocall-duration-time-note', 
        {conversation_ref: appointmentRef,started_at:'12:15:14', ended_at:'12:30:20' }
      ).then(function(response) {
              if(response.data.status){
                console.log(response);
              } else{
                alert(response.data.message);
              }    
                }).catch(function(e) {
                    console.log(e)
                });
    },
    handleOffer(offer, name) { 
      // connectedUser = name; 
      var thisInstance = this;
      thisInstance.yourConn.setRemoteDescription(new RTCSessionDescription(offer));
      alert("please pickup the call");
      this.canPickup=true;
      
    },
    handleAnswer(answer) { 
      this.yourConn.setRemoteDescription(new RTCSessionDescription(answer));
    },
    handleCandidate(candidate) { 
      this.yourConn.addIceCandidate(new RTCIceCandidate(candidate)); 
    },
    handleLogin(success) { 
      var thisInstance = this;
      if (success === false) { 
          alert("Ooops...try a different username"); 
      } else { 
         
        
          //********************** 
          //Starting a peer connection 
          //********************** 
        
          //getting local video stream 
          navigator.mediaDevices.getUserMedia = navigator.mediaDevices.getUserMedia ||
      navigator.webkitGetUserMedia ||
      navigator.mozGetUserMedia;
           navigator.mediaDevices.getUserMedia({ video: true, audio: true },function (myStream) { 
            var stream = myStream; 
            const localVideo = thisInstance.$refs['video-here'];
            //displaying local video stream on the page 
            localVideo.src = stream;
          
            //using Google public stun server 
            var configuration = { 
              iceTransportPolicy: "relay",
                iceServers: [
                {
                  urls:"turn:turn.websocket.server",
                  username:'urvish',
                  credential: '123123'
                }
              ],
              sdpSemantics: 'unified-plan'
            }; 
          
            thisInstance.yourConn = new webkitRTCPeerConnection(configuration); 
          
            // setup stream listening 
            thisInstance.yourConn.addStream(stream); 
          
            //when a remote user adds stream to the peer connection, we display it 
            thisInstance.yourConn.onaddstream = function (e) { 
              const remoteVideo = thisInstance.$refs['video-there'];
                remoteVideo.src = e.stream; 
            };
          
            // Setup ice handling 
            thisInstance.yourConn.onicecandidate = function (event) { 
                if (event.candidate) { 
                  
                  thisInstance.send({
                      
                      type: "candidate",
                      data:{
                        id:thisInstance.otherId, 
                        candidate: event.candidate ,
                        appointment_ref: thisInstance.appointment_ref,
                        token:thisInstance.token
                      } 
                      
                  }); 
                } 
            };  
          
          }, function (error) { 
            console.log(error); 
          }); 
        
      } 
    },
    handleLeave() { 
      this.yourConn.close(); 
      this.yourConn.onicecandidate = null; 
      this.yourConn.onaddstream = null; 
      window.location.replace("/appointment_list");

      // location.reload();
    },
    handleInfo(message) {
      this.videoInfo = message;
    },
    send(message) { 
      this.connection.send(JSON.stringify(message)); 
    }
  }
};
</script>
<style>
.video-container {
  width: 500px;
  height: 380px;
  margin: 8px auto;
  border: 3px solid #000;
  position: relative;
  box-shadow: 1px 1px 1px #9e9e9e;
}
.video-here {
  width: 130px;
  position: absolute;
  left: 10px;
  bottom: 16px;
  border: 1px solid #000;
  border-radius: 2px;
  z-index: 2;
}
.video-there {
  width: 100%;
  height: 100%;
  z-index: 1;
}
.text-right {
  text-align: right;
}
.call-bton{
    color: white;
    font-weight: bold;
    background: green;
    border: 1px solid green;
}
.end-bton{
    color: white;
    font-weight: bold;
    background: red;
    border: 1px solid red;
}
.pickup-bton{
    color: white;
    font-weight: bold;
    background: orange;
    border: 1px solid orange;
}
.video-info{
  font-size: 18px;
    text-align: center;
    color: cornflowerblue;
}
</style>
