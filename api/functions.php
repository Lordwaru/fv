<?php
require('connect.php');

function fetchChannels($conn) {
    $sql = "SELECT _id, channel, src, label, online, popularity, icon, iconExternal, lastUpdate, priority FROM channels";
    $sql .= " WHERE src != 'dead'";
    $sql .= " ORDER BY priority, _id";
    $result = $conn->query($sql);
    $output = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $_id = "FV_" . $row['_id'];
            $row['_id'] = (int) $row['_id'];
            $row['online'] = !!$row['online'];
            $row['popularity'] = (int)$row['popularity'];
            $row['priority'] = (int)$row['priority'];
            $row['icon'] = 'http://fightanvidya.com/SI/IC/' . $row['icon'];
            $output[$_id] = $row;
        }
    } else {
        $output = "No Data";
    }
    
    return $output;
}

function fetchPopular($conn) {
    $sql = "SELECT user, COUNT(*), site, chan, MIN(created), MIN(start_time) FROM `water`";
    $sql .= " GROUP BY site, chan";
    $sql .= " ORDER BY COUNT(*) ASC, created ASC";

    $result = $conn->query($sql);
    $output = [];

    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $site = $row['site'];
            $channel = $row['chan'];
            $popularity = (int)$row['COUNT(*)'];
            $startTime = $row['MIN(start_time)'];
            $createdTime = $row['MIN(created)'];
            $timeStamp = $startTime ?: $createdTime;
            $output[$site][$channel] = [
                'popularity' => $popularity,
                'timeStamp' => $timeStamp,
                'createdTime' => $createdTime
            ];
        }
    } else {
        $output = [];
    }
    
    return $output;
}

function addChannels($conn, $src, $channels) {
    $values = [];
    foreach ($channels as $channel) {
        $value = "(";
        $value .= "'" . $src . "'";
        $value .= ", '" . $channel['channel'] . "'";
        $value .= ", '" . $channel['label'] . "'";
        $value .= ", '" . $channel['isOnline'] . "'";
        $value .= ", '" . $channel['expires'] . "'";
        $value .= ", '" . $channel['iconExternal'] . "'";
        $value .= ", '" . $channel['popularity'] . "'";
        $value .= ", '" . $channel['sessionStart'] . "'";
        $value .= ", '" . $channel['dateCreated'] . "'";
        $value .= ")";
        $values[] = $value;
    }
    $sql = "INSERT INTO channels";
    $sql .= " (src, channel, label, online, expires, iconExternal, popularity, sessionStart, dateCreated)";
    $sql .= " VALUES " . implode(",", $values);

    // echo $sql;

    if ($conn->query($sql) === TRUE) {
        return "Record updated successfully";
    } else {
        return "Error updating record: " . $conn->error;
    }
}

function updateChannelPopularity($conn, $src, $channels) {
    $values = [];
    foreach ($channels as $channel) {
        $channel =  $channel['channel'];
        $popularity = $channel['popularity'];
        $sessionStart = $channel['sessionStart'];
    }
    $sql = "UPDATE channels SET";
    $sql .= " popularity = $popularity, sessionStart = '$sessionStart'";
    $sql .= " WHERE channel = '$channel' AND src = '$src'";

    // echo $sql;

    if ($conn->query($sql) === TRUE) {
        return "Record updated successfully";
    } else {
        return "Error updating record: " . $conn->error;
    }
}

function resetUnpopularChannels($conn, $src, $ignore) {
    $values = [];
    $ignore = implode($ignore, "', '" );

    /* Delete unpopular expirable channels */
    $sql = "DELETE FROM channels";
    $sql .= " WHERE expires = 1";
    $sql .= " AND src = '$src'";
    $sql .= " AND channel NOT IN ('$ignore')";

    if ($conn->query($sql) !== TRUE) {
        return "Error updating record: " . $conn->error;
    }

    /* Reset unpopular permanent channels */
    $sql = "UPDATE channels SET popularity = 0";
    $sql .= " WHERE expires = 0";
    $sql .= " AND src = '$src'";
    $sql .= " AND channel NOT IN ('$ignore')";

    if ($conn->query($sql) === TRUE) {
        return "Record updated successfully";
    } else {
        return "Error updating record: " . $conn->error;
    }
}

// TODO: Sanitize data
function updateLiveStatus($conn, $isOnline, $channels, $src) {
    $channels = implode($channels, "','");
    $isOnline = $isOnline ? 1 : 0;
    $sql = "UPDATE channels";
    $sql .= " SET online = $isOnline";
    $sql .= " WHERE channel IN ('$channels') AND src = '$src'";

    if ($conn->query($sql) === TRUE) {
        return "Record updated successfully";
    } else {
        return "Error updating record: " . $conn->error;
    }
}

function updateMiscAttributes($conn, $atts, $src) {
    $channel = $atts['channel'];
    $iconExternal = $atts['iconExternal'];
    $isOnline = $atts['isOnline'] ? 1 : 0;
    $popularity = $atts['popularity'];

    $sql = "UPDATE channels";
    $sql .= " SET iconExternal = '$iconExternal'";
    $sql .= ", online = $isOnline";
    $sql .= ", popularity = $popularity";
    $sql .= " WHERE channel = '$channel' AND src = '$src'";

    if ($conn->query($sql) === TRUE) {
        return "Record updated successfully";
    } else {
        return "Error updating record: " . $conn->error;
    }
}

function curl_get_contents($url, $clientId = null, $cookie = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    if ($clientId) curl_setopt($ch, CURLOPT_HTTPHEADER, ['Client-ID: ' . $clientId]);
    if ($cookie) curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    if ($cookie) curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_URL, $url);
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
}

function updateTwitchChannels($conn, $data) {
    $twitchChannels = [];
    $popularChannels = [];
    $liveChannels = [];
    $otherUpdates = [];
    
    $popularData = fetchPopular($conn);
    foreach ($popularData as $site => $channel) {
        if ($site === 'twitch' || $site == "ttv") {
            foreach ($channel as $name => $arr) {
                $popularChannels[] = $name;
            }
        }
    }

    foreach ($data as $channel) {
        if ($channel['src'] == "twitch") $twitchChannels[] = $channel['channel'];
    }
    $response = curl_get_contents("https://api.twitch.tv/kraken/streams?channel=". implode($twitchChannels, ','), "1p9iy0mek7mur7n1jja9lejw3");
    $newData = json_decode($response);
    foreach ($newData->streams as $online) {
        $name = $online->channel->name;
        $label = $online->channel->display_name;
        $iconExternal = $online->channel->logo;
        $popularity = isset($popularData['ttv'][$name]) ? $popularData['ttv'][$name]['popularity'] : 0;

        $liveChannels[] = $name;
        $otherUpdates[$name] = [
            'channel' => $name,
            'isOnline' => 1,
            'iconExternal' => $iconExternal,
            'popularity' => $popularity
        ];
    }

    $offlinePopular = array_diff($popularChannels, $liveChannels);
    $offlinePopular = array_intersect($offlinePopular, $twitchChannels);
    foreach ($offlinePopular as $channel) {
        $name = $channel;
        $iconExternal = "";
        $popularity = isset($popularData['ttv'][$name]) ? $popularData['ttv'][$name]['popularity'] : 0;

        $otherUpdates[$name] = [
            'channel' => $name,
            'isOnline' => 0,
            'iconExternal' => $iconExternal,
            'popularity' => $popularity
        ];
    }

    $offline = array_diff($twitchChannels, $liveChannels);
    foreach ($otherUpdates as $atts) {
        updateMiscAttributes($conn, $atts, 'twitch');
    }
    // updateLiveStatus($conn, true, $liveChannels, 'twitch'); // TODO: remove since we're looping through everything anyways
    updateLiveStatus($conn, false, $offline, 'twitch');
    return $newData;
}

function addTemporaryTwitchChannels($conn, $data) {
    $twitchChannels = [];
    $popularChannels = [];
    $channelsToAdd = [];
    
    $popularData = fetchPopular($conn);
    foreach ($popularData as $site => $channel) {
        if ($site === 'twitch' || $site == "ttv") {
            foreach ($channel as $name => $arr) {
                $popularChannels[] = $name;
            }
        }
    }

    foreach ($data as $channel) {
        if ($channel['src'] == "twitch") $twitchChannels[] = $channel['channel'];
    }

    $unique = array_diff($popularChannels, $twitchChannels);
    $response = curl_get_contents("https://api.twitch.tv/kraken/streams?channel=". implode($unique, ','), "1p9iy0mek7mur7n1jja9lejw3");
    $newData = json_decode($response);

    $temp = [];
    foreach ($newData->streams as $online) {
        $name = $online->channel->name;
        $label = $online->channel->display_name;
        $isOnline = 1;
        $expires = 1;
        $iconExternal = $online->channel->logo;
        $popularity = $popularData['ttv'][$name]['popularity'];
        $sessionStart = $popularData['ttv'][$name]['timeStamp'];
        $dateCreated = $popularData['ttv'][$name]['createdTime'];

        $temp[] = $name;
        $channelsToAdd[] = [
            'channel' => $name,
            'label' => $label,
            'isOnline' => $isOnline,
            'expires' => $expires,
            'iconExternal' => $iconExternal,
            'popularity' => $popularity,
            'sessionStart' => $sessionStart,
            'dateCreated' => $dateCreated
        ];
    }

    $offline = array_diff($popularChannels, $temp);
    foreach ($offline as $channel) {
        $name = $channel;
        $label = $channel;
        $isOnline = 0;
        $expires = 1;
        $iconExternal = "http://fightanvidya.com/twitch.png";
        $popularity = $popularData['ttv'][$name]['popularity'];
        $sessionStart = $popularData['ttv'][$name]['timeStamp'];
        $dateCreated = $popularData['ttv'][$name]['createdTime'];

        $channelsToAdd[] = [
            'channel' => $name,
            'label' => $label,
            'isOnline' => $isOnline,
            'expires' => $expires,
            'iconExternal' => $iconExternal,
            'popularity' => $popularity,
            'sessionStart' => $sessionStart,
            'dateCreated' => $dateCreated
        ];
    }

    if (count($channelsToAdd)) addChannels($conn, "twitch", $channelsToAdd);
    resetUnpopularChannels($conn, "twitch", $popularChannels);
    return $channelsToAdd;
}