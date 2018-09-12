<?php

include_once('subsonic.php');
include_once('config.php');

$sub = new Subsonic;

$sub->setHost($conf['subSonicUrl']);
$sub->setUser($conf['subSonicUsername']);
$sub->setPass($conf['subSonicPassword']);

function streamSong($session, $song)
{
    sendMessage([
        'source' => 'subsonic',
        'fulfillmentText' => 'Playing ' . $song['title'],
        'payload' => [
            'google' => [
                'expectUserResponse' => true,
                'richResponse' => [
                    'items' => [
                        [
                            'simpleResponse' => [
                                'textToSpeech' => 'Playing ' . $song['title'],
                            ],
                        ],
                        [
                            'mediaResponse' => [
                                'mediaType' => 'AUDIO',
                                'mediaObjects' => [
                                    [
                                        'name' => $song['title'],
                                        'description' => $song['title'] . ' by ' . $song['artist'],
                                        'largeImage' => [
                                            'url' => $song['coverUrl'],
                                            'accessibilityText' => 'Album cover of ' . $song['album'] . ' by ' . $song['artist'],
                                        ],
                                        'contentUrl' => $song['url'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'suggestions' => [
                        [
                            'title' => 'Play this album'
                        ],
                    ],
                ],
            ],
        ],
        'outputContexts' => [
            [
                'name' => $session . '/contexts/playing',
                'lifespanCount' => 5,
                'parameters' => [
                    "id" => $song['id'],
                    "albumId" => $song['albumId'],
                    "artistId" => $song['artistId']
                ],
            ],
        ],
    ]);
}

function searchBySongArtist($session, $params)
{
    global $sub;
    if(!isset($params['song']) || !isset($params['artist']))
    {
        sendMessage([
            'source' => 'subsonic',
            'fulfillmentText' => 'Request problem: missing mandatory parameter.',
        ]);
        return;
    }

    $ret = $sub->apiSearch($params['artist'], $params['song']);
    switch(count($ret))
    {
        case 0:
            sendMessage([
                'source' => 'subsonic',
                'fulfillmentText' => 'Sorry, but I didn\'t find any songs.',
            ]);
            break;
        default:
            streamSong($session, $ret[0]);
            break;
    }
}

function playSongInCurrentSongsAlbum($session, $params, $startAtBeginning)
{
    global $sub;
    if(!isset($params['id']))
    {
        sendMessage([
            'source' => 'subsonic',
            'fulfillmentText' => 'Request problem: missing mandatory parameter: id.',
        ]);
        return;
    }

    $nextSong = $sub->findNextSongByDirectory($params['id'], $startAtBeginning);
    if($nextSong === null)
    {
        sendMessage([
            'source' => 'subsonic',
            'fulfillmentText' => 'I\'m sorry, I ran out of songs.',
        ]);
    }

    streamSong($session, $nextSong);
}

function nextSong($session, $params)
{
    playSongInCurrentSongsAlbum($session, $params, false);
}

function playAlbum($session, $params)
{
    //todo: support 'next' album?
    playSongInCurrentSongsAlbum($session, $params, true);       
}

function playArtist($session, $params)
{
    global $sub;
    if (!isset($params["artistId"]))
    {
        sendMessage([
            'source' => 'subsonic',
            'fulfillmentText' => 'Request problem: missing mandatory parameter: artistId.',
        ]);
        return;
    }

    //todo: check for $params['shuffle'] (or random), then use array_rand to choose next song

    $nextSong = $sub->findSongByArtist($params['artistId']);
    if($nextSong === null)
    {
        sendMessage([
            'source' => 'subsonic',
            'fulfillmentText' => 'I\'m sorry, I ran out of songs.',
        ]);
    }
    streamSong($session, $nextSong);
}

function processMessage($request)
{
    if (!isset($request['queryResult']['action']))
    {
        sendMessage([
            'source' => 'subsonic',
            'fulfillmentText' => 'Request problem: missing action.',
        ]);
        return;
    }

    if(!isset($request['session']))
    {
        sendMessage([
            'source' => 'subsonic',
            'fulfillmentText' => 'Request problem: missing session.',
        ]);
        return;
    }

    $action = $request['queryResult']['action'];
    $session = $request['session'];
    $params = $request['queryResult']['parameters'];

    switch($action)
    {
        case 'search':
            searchBySongArtist($session, $params);
            return;
        case 'next':
            nextSong($session, $params);
            return;
        case 'playArtist':
            playArtist($session, $params);
            return;
        case 'playAlbum':
            playAlbum($session, $params);
            return;
        default:
            sendMessage([
                'source' => 'subsonic',
                'fulfillmentText' => 'I\'m sorry, that command is not implemented yet.',
            ]);
            return;
    }
}

function sendMessage($parameters)
{
    header('Content-Type: application/json');
    $txt = json_encode($parameters);
    echo $txt;
}

// begin auth
$token = null;
$headers = apache_request_headers();
if(isset($headers['Authorization']))
{
    $matches = array();
    preg_match('/Token token="(.*)"/', $headers['Authorization'], $matches);
    if(isset($matches[1]))
    {
        $token = $matches[1];
    }
}
// end auth

if ($token === $conf['token'])
{    
    $input = file_get_contents('php://input');
    $request = json_decode($input, true);
    
    processMessage($request);
}
else
{
    sendMessage([
        'source' => 'subsonic',
        'fulfillmentText' => 'Sorry, but you aren\'t authorized.'
    ]);
}
