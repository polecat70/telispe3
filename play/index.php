
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title></title>
        <script type="text/javascript" src="audio.js"></script>
        <link rel="stylesheet" type="text/css" href="audio.css"/>
        <link rel="stylesheet" type="text/css" href="button.css"/>
    </head>
    <body>
        <p>Audio player</p>
        <audio id="song" controls="controls" preload="none">
            <source src="stream.php?filename=../rec/5f1c6ce1-e711-4ced-a588-a1b06561fb9b.mp3" type="audio/mp3">
            Your browser does not support the audio tag.
        </audio>
        <!-- Div that plays song, onclick calls the javascript play function and passes the
        id of the audio tag with the appropriate song -->
        <div id="songPlay" class="button" onclick="play('song')">Play</div>
        <!-- Div that pauses the song when clicked.  Calls the pause button when clicked. -->
        <div id="songPause" class="button" onclick="pause()">Pause</div>
        <!-- Div that switches rather or not to play or pause the song based off of song state -->
        <div id="songPlayPause" class="button" onclick="playPause('song')">PlayPause</div>
        <!-- Div that stops the song when clicked -->
        <div id="songStop" class="button" onclick="stopSong()">Stop</div>
        <!-- Div that updates with current song time while playing -->
        <div id="songTime">0:00 / 0:00</div>
        <div id="volumeUp" class="button" onclick="changeVolume(10, 'up')">Plus</div><br/>
        <div id="volumeDown" class="button" onclick="changeVolume(10, 'down')">Minus</div>
        <!-- Volume Meter sets the new volume on click. The Volume Status div is embedded inside so it can grow
        within bounds to simulate percentage feel -->
        <div id="volumeMeter" onclick="setNewVolume(this,event)"><div id="volumeStatus"></div></div>
        <span id="volumeFifty" class="button" onclick="volumeUpdate(50)">Volume 50%</span>
        <!-- Song Slider tracks progress on song time change, if you click it sets the distance into the song
        based on the percentage of where was clicked -->
        <div id="songSlider" onclick="setSongPosition(this,event)">
            <div id="trackProgress"></div>
        </div>
    </body>
</html>
