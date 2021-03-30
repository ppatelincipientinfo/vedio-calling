'use strict';
const https = require('https');
const fs = require('fs');
var axios = require('axios');
var privateKey = fs.readFileSync('/etc/letsencrypt/live/turn.websocket.server/privkey.pem', 'utf8');
var certificate = fs.readFileSync('/etc/letsencrypt/live/turn.websocket.server/fullchain.pem', 'utf8');

var credentials = {
    key: privateKey,
    cert: certificate,
    rejectUnauthorized: false,
    protocolVersion: 8,
    perMessageDeflate: true
};
var express = require('express');
var app = express();
app.get('/check-user-access', (req, res) => {

    var config = {
        headers: {
            'Accept': 'application/json',
            'Authorization': "'" + req.header('authorization') + "'"
        }

    }
    //res.send('response');
    axios.get('https://server_url/api/cms/can-access-appointment/' + req.query.appointment_ref, config)
        .then(function(response) {
            res.send(response.data);
        });
});
//... bunch of other express stuff here ...

//pass in your express app and credentials to create an https server
var httpsServer = https.createServer(credentials, app);
httpsServer.on('request', (req, res) => {
    res.setHeader('Content-Type', 'text/html');
});
// httpsServer.listen(3001);

//return;
//require our websocket library
var WebSocketServer = require('ws').Server;

//creating a websocket server at port 3001
var wss = new WebSocketServer({
    perMessageDeflate: false,
    server: httpsServer,
    path: "/connect"
});
httpsServer.listen(3001);
//all connected to the server users
var users = {};

//when a user connects to our sever
wss.on('connection', function(connection) {

    //when server gets a message from a connected user
    connection.on('message', function(message) {

        var messageData;
        //accepting only JSON messages
        try {
            messageData = JSON.parse(message);
        } catch (e) {
            messageData = {};
        }

        //switching type of the user message
        switch (messageData.type) {
            //when a user tries to login

            case "new":

                //save user connection on the server
                var config = {
                    headers: {
                        'Accept': 'application/json',
                        'Authorization': String(messageData.data.token)
                    }

                }


                users[messageData.data.id] = connection;
                connection.id = messageData.data.id;

                connection.name = messageData.data.name;

                sendTo(connection, {
                    type: "new",
                    success: true,

                });

                break;

            case "offer":
                var config = {
                    headers: {
                        'Accept': 'application/json',
                        'Authorization': String(messageData.data.token)
                    }

                }
                axios.get('https://server_url/api/cms/can-access-appointment/' + messageData.data.appointment_ref, config)
                    .then(function(response) {
                        if (response.data != undefined && response.data.status == 1) {

                            //for ex. UserA wants to call UserB
                            //if UserB exists then send him offer details
                            var conn = users[messageData.data.id];
                            sendFirebaseNotification(messageData.data.appointment_ref, config);
                            if (conn != null) {
                                //setting that UserA connected with UserB
                                connection.otherid = messageData.data.id;

                                sendTo(conn, {
                                    type: "offer",
                                    offer: messageData.data.offer,
                                    id: connection.id,
                                    name: messageData.data.name,
                                    session_id: messageData.data.session_id,
                                    media: messageData.data.media,
                                    message: messageData.data.name + " is calling you. What would you like to do?",
                                    appointment_ref: messageData.data.appointment_ref
                                });
                                sendTo(connection, {
                                    type: "info",
                                    message: "Call sent successfully.waiting for opponent user to Pickup the call"
                                });
                            } else {
                                sendTo(connection, {
                                    type: "info",
                                    message: "This user is not online"
                                });
                            }
                        } else {
                            sendTo(connection, {
                                type: "error",
                                success: false,
                                message: "You can not access this conversation",
                            });
                        }
                    });

                break;

            case "answer":
                //for ex. UserB answers UserA
                var conn = users[messageData.data.id];

                if (conn != null) {
                    connection.otherid = messageData.data.id;
                    sendTo(conn, {
                        type: "answer",
                        answer: messageData.data.answer,
                        id: connection.id,
                        session_id: messageData.data.session_id
                    });
                }

                break;

            case "candidate":
                var conn = users[messageData.data.id];

                if (conn != null) {
                    sendTo(conn, {
                        type: "candidate",
                        candidate: messageData.data.candidate,

                        id: connection.id,
                        session_id: messageData.data.session_id
                    });
                }

                break;

            case "leave":
                var conn = users[messageData.data.id];

                //notify the other user so he can disconnect his peer connection
                if (conn != null && conn != undefined) {
                    conn.otherid = null;
                    sendTo(conn, {
                        type: "leave"
                    });
                }

                break;

            case "bye":
                var conn = users[messageData.data.to];
                //notify the other user so he can disconnect his peer connection
                if (conn != null && conn != undefined) {
                    conn.otherid = null;
                    sendTo(conn, {
                        type: "bye",
                        session_id: messageData.data.session_id,
                        from: messageData.data.from,
                        to: messageData.data.to
                    });
                }
                sendTo(connection, {
                    type: "bye",
                    session_id: messageData.data.session_id,
                    from: messageData.data.from,
                    to: messageData.data.to
                });

                break;

            case "invite":
                var config = {
                    headers: {
                        'Accept': 'application/json',
                        'Authorization': String(messageData.data.token)
                    }

                }
                sendFirebaseNotification(messageData.data.appointment_ref, config);
                break;

            default:
                sendTo(connection, {
                    type: "error",
                    message: "Command not found: " + messageData.type,
                    demo: messageData.data,
                    demo2: message,
                    message_type: message.type
                });

                break;
        }
    });

    //when user exits, for example closes a browser window
    //this may help if we are still in "offer","answer" or "candidate" state
    connection.on("close", function() {
        if (connection.id) {
            delete users[connection.id];

            if (connection.otherid) {
                var conn = users[connection.otherid];

                if (conn != undefined && conn != null) {
                    conn.otherid = null;
                    sendTo(conn, {
                        type: "leave"
                    });
                }
            }
        }
    });

    connection.send("Connected.");

});

function sendTo(connection, message) {
    connection.send(JSON.stringify(message));
}

function sendFirebaseNotification(appointment_ref, config) {
    axios.get('https://server_url/api/cms/send-firebase-notification/' + appointment_ref, config)
        .then(function(response) {
        });
}
