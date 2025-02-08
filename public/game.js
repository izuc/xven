// game.js

// Get canvas and context
const canvas = document.getElementById('gameCanvas');
const ctx = canvas.getContext('2d');
const keyState = {};

// ================================================================
// Game state and debugging
// ================================================================
const GameState = {
    CONNECTING: 'connecting',
    CONNECTED: 'connected',
    DISCONNECTED: 'disconnected',
    LOADING: 'loading',
    READY: 'ready',
    ERROR: 'error'
};

let gameState = GameState.CONNECTING;
let lastServerUpdate = Date.now();
let lastMovementTime = Date.now();
let movementQueue = [];
const MAX_QUEUE_SIZE = 10;
let serverReconciliationTimer = null;

const DEBUG_MODE = false; // Set to true to enable debug visualization

// Input buffering for smoother controls
const inputBuffer = [];
const MAX_BUFFER_SIZE = 5;

function processInputBuffer() {
    while (inputBuffer.length > 0) {
        const input = inputBuffer.shift();
        // (Optional) Process buffered input if needed.
    }
}

// ================================================================
// Game constants
// ================================================================
const TILE_SIZE = 32;
const MAP_WIDTH = 25;
const MAP_HEIGHT = 20;

const CHAR_WIDTH = 16;
const CHAR_HEIGHT = 16;
const ANIM_SPEED = 10;

// ================================================================
// Game variables
// ================================================================
let player;
let map;
let gameObjects;
let scale;

let socket;

let playerId;
let receivedPlayerPositions = {};

// ================================================================
// Helper: Create a default map (25x20 grid of ground tile [1,1])
// ================================================================
function createDefaultMap() {
    let defaultMap = [];
    for (let row = 0; row < MAP_HEIGHT; row++) {
        let rowArr = [];
        for (let col = 0; col < MAP_WIDTH; col++) {
            rowArr.push([1, 1]); // Default ground tile
        }
        defaultMap.push(rowArr);
    }
    return defaultMap;
}

// ================================================================
// Button event handlers (touch and mouse)
// ================================================================
function handleUpButtonDown(event) {
    event.preventDefault();
    keyState['ArrowUp'] = true;
}

function handleUpButtonUp(event) {
    event.preventDefault();
    resetAllKeyStates();
}

function handleLeftButtonDown(event) {
    event.preventDefault();
    keyState['ArrowLeft'] = true;
}

function handleLeftButtonUp(event) {
    event.preventDefault();
    resetAllKeyStates();
}

function handleRightButtonDown(event) {
    event.preventDefault();
    keyState['ArrowRight'] = true;
}

function handleRightButtonUp(event) {
    event.preventDefault();
    resetAllKeyStates();
}

function handleDownButtonDown(event) {
    event.preventDefault();
    keyState['ArrowDown'] = true;
}

function handleDownButtonUp(event) {
    event.preventDefault();
    resetAllKeyStates();
}

function resetAllKeyStates() {
    keyState['ArrowUp'] = false;
    keyState['ArrowLeft'] = false;
    keyState['ArrowRight'] = false;
    keyState['ArrowDown'] = false;
}

// Clear key states if the window loses focus or the document becomes hidden.
window.addEventListener('blur', resetAllKeyStates);
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        resetAllKeyStates();
    }
});

// X button event listeners
const xButton = document.getElementById('xButton');
xButton.addEventListener('click', showXButtonAlert);
xButton.addEventListener('touchstart', showXButtonAlert);

document.onkeypress = function(event) {
    if (event.key === 'x' || event.key === 'X') {
        showXButtonAlert();
    }
};

function showXButtonAlert() {
    alert('X button pressed!');
}

// Add event listeners for control buttons
document.getElementById('upButton').addEventListener('touchstart', handleUpButtonDown);
document.getElementById('upButton').addEventListener('touchend', handleUpButtonUp);
document.getElementById('upButton').addEventListener('touchcancel', handleUpButtonUp);
document.getElementById('upButton').addEventListener('mousedown', handleUpButtonDown);
document.getElementById('upButton').addEventListener('mouseup', handleUpButtonUp);

document.getElementById('leftButton').addEventListener('touchstart', handleLeftButtonDown);
document.getElementById('leftButton').addEventListener('touchend', handleLeftButtonUp);
document.getElementById('leftButton').addEventListener('touchcancel', handleLeftButtonUp);
document.getElementById('leftButton').addEventListener('mousedown', handleLeftButtonDown);
document.getElementById('leftButton').addEventListener('mouseup', handleLeftButtonUp);

document.getElementById('rightButton').addEventListener('touchstart', handleRightButtonDown);
document.getElementById('rightButton').addEventListener('touchend', handleRightButtonUp);
document.getElementById('rightButton').addEventListener('touchcancel', handleRightButtonUp);
document.getElementById('rightButton').addEventListener('mousedown', handleRightButtonDown);
document.getElementById('rightButton').addEventListener('mouseup', handleRightButtonUp);

document.getElementById('downButton').addEventListener('touchstart', handleDownButtonDown);
document.getElementById('downButton').addEventListener('touchend', handleDownButtonUp);
document.getElementById('downButton').addEventListener('touchcancel', handleDownButtonUp);
document.getElementById('downButton').addEventListener('mousedown', handleDownButtonDown);
document.getElementById('downButton').addEventListener('mouseup', handleDownButtonUp);

// ================================================================
// Canvas resizing for responsiveness
// ================================================================
function resizeCanvas() {
    const maxWidth = 800;
    scale = Math.min(window.innerWidth / maxWidth, 1);

    const canvasWidth = maxWidth * scale;
    const canvasHeight = (640 * scale); // Maintain 800x640 aspect ratio

    canvas.width = canvasWidth;
    canvas.height = canvasHeight;

    canvas.style.width = scale === 1 ? maxWidth + 'px' : '100%';
    canvas.style.height = canvasHeight + 'px';
}

// ================================================================
// WebSocket connection handling with error and reconnection logic
// ================================================================
function initializeWebSocket() {
    socket = new WebSocket('wss://server.xven.org');

    socket.onopen = function() {
        console.log('WebSocket connection established');
        // Explicitly set game state to CONNECTED.
        gameState = GameState.CONNECTED;
        registerPlayer();
        // Start the game loop upon connection (if not already running)
        gameLoop();
    };

    socket.onmessage = function(event) {
        const data = JSON.parse(event.data);
        handleServerMessage(data);
    };

    socket.onerror = function(error) {
        console.error('WebSocket error:', error);
        gameState = GameState.ERROR;
        showMessage('Connection error. Attempting to reconnect...');
    };

    socket.onclose = function() {
        console.log('WebSocket connection closed');
        gameState = GameState.DISCONNECTED;
        clearInterval(serverReconciliationTimer);
        showMessage('No connection to the server. \nAttempting to reconnect...');
        canvas.style.display = 'none';
        attemptReconnect();
    };

    serverReconciliationTimer = setInterval(reconcilePosition, 100);
}

function registerPlayer() {
    if (socket.readyState === WebSocket.OPEN) {
        socket.send(JSON.stringify({
            type: 'register',
            id: playerId,
            character_index: player.characterIndex
        }));
        socket.send(JSON.stringify({
            type: 'get_position',
            id: playerId
        }));
        socket.send(JSON.stringify({
            type: 'get_map',
            mapId: player.map_id
        }));
    }
}

let reconnectInterval;
let reconnectAttempts = 0;
const maxReconnectAttempts = 5;

function attemptReconnect() {
    clearInterval(reconnectInterval);
    reconnectInterval = setInterval(() => {
        reconnectAttempts++;
        if (reconnectAttempts > maxReconnectAttempts) {
            clearInterval(reconnectInterval);
            showMessage('Unable to reconnect to the server. Please refresh the page.');
            return;
        }
        console.log('Attempting to reconnect... (Attempt ' + reconnectAttempts + ')');
        initializeWebSocket();
    }, 5000);
}

// ================================================================
// Input event handling (keyboard)
// ================================================================
function handleKeyDown(event) {
    if (inputBuffer.length < MAX_BUFFER_SIZE) {
        inputBuffer.push(event.code);
    }
    keyState[event.code] = true;
}

function handleKeyUp(event) {
    keyState[event.code] = false;
}

// ================================================================
// Game loop and state-specific rendering
// ================================================================
function gameLoop() {
    const currentTime = performance.now();
    processInputBuffer();

    console.log("Current game state:", gameState);

    switch (gameState) {
        case GameState.READY:
            update();
            render();
            break;
        case GameState.LOADING:
            renderLoadingScreen();
            break;
        case GameState.ERROR:
            renderErrorScreen();
            break;
        default:
            renderLoadingScreen();
            break;
    }

    const elapsed = performance.now() - currentTime;
    const frameTime = 1000 / 60; // 60 FPS target
    const delay = Math.max(0, frameTime - elapsed);

    setTimeout(() => requestAnimationFrame(gameLoop), delay);
}

function renderLoadingScreen() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = "#000";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = "#fff";
    ctx.font = "20px sans-serif";
    ctx.fillText("Loading...", canvas.width / 2 - 50, canvas.height / 2);
}

function renderErrorScreen() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = "#900";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = "#fff";
    ctx.font = "20px sans-serif";
    ctx.fillText("An error occurred. Please try again.", canvas.width / 2 - 100, canvas.height / 2);
}

// ================================================================
// Initialization
// ================================================================
function init() {
    playerId = getPlayerId();
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    // Initialize player so that it starts in the center of the map.
    player = {
        x: Math.floor((MAP_WIDTH * TILE_SIZE - TILE_SIZE) / 2),
        y: Math.floor((MAP_HEIGHT * TILE_SIZE - TILE_SIZE) / 2),
        width: TILE_SIZE,
        height: TILE_SIZE,
        speed: 2,
        frame: 0,
        facing: 'down',
        characterIndex: 0,
        movementDisabled: false,
        score: 0,
        map_id: 0
    };

    // Setup character picker
    const characterSelection = document.getElementById('characterSelection');
    const totalCharacters = 10;
    const frameWidth = 48;
    const frameHeight = 32;
    const partWidth = 16;
    const partHeight = 16;

    for (let i = 0; i < totalCharacters; i++) {
        const characterOption = document.createElement('canvas');
        characterOption.width = partWidth;
        characterOption.height = partHeight;
        characterOption.className = 'character-option';
        characterOption.dataset.index = i;

        const characterCtx = characterOption.getContext('2d');
        characterCtx.imageSmoothingEnabled = false;

        const srcX = i * frameWidth;
        const srcY = 0;

        characterCtx.drawImage(
            charactersImage,
            srcX, srcY,
            partWidth, partHeight,
            0, 0,
            partWidth, partHeight
        );

        characterOption.addEventListener('click', function() {
            const selectedIndex = parseInt(this.dataset.index);
            player.characterIndex = selectedIndex;
            localStorage.setItem('playerCharacterIndex', selectedIndex);

            const characterOptions = document.getElementsByClassName('character-option');
            for (let j = 0; j < characterOptions.length; j++) {
                characterOptions[j].classList.remove('selected');
            }
            this.classList.add('selected');

            if (socket && socket.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify({
                    id: playerId,
                    type: 'character_index',
                    character_index: player.characterIndex,
                }));
            }
        });

        characterSelection.appendChild(characterOption);
    }

    player.characterIndex = localStorage.getItem('playerCharacterIndex') || 0;
    if (characterSelection.children[player.characterIndex]) {
        characterSelection.children[player.characterIndex].classList.add('selected');
    }

    gameObjects = [];

    // If no valid map data is available from the server, create a default map.
    if (!map || map.length !== MAP_HEIGHT || !map[0] || map[0].length !== MAP_WIDTH) {
        map = createDefaultMap();
    }

    // Hide and remove the loading screen element from the DOM.
    hideLoadingScreen();
    const loadingScreen = document.getElementById('loadingScreen');
    if (loadingScreen && loadingScreen.parentNode) {
        loadingScreen.parentNode.removeChild(loadingScreen);
    }

    document.addEventListener('keydown', handleKeyDown);
    document.addEventListener('keyup', handleKeyUp);

    initializeWebSocket();

    // Set game state to READY so movement functions are enabled.
    gameState = GameState.READY;
    console.log("Game State set to:", gameState);

    // Start the game loop.
    gameLoop();
}

// ================================================================
// Movement and synchronization improvements
// ================================================================
function updatePlayerMovement() {
    console.log('Movement state:', {
        disabled: player.movementDisabled,
        gameState: gameState,
        position: { x: player.x, y: player.y },
        keyStates: keyState
    });

    if (player.movementDisabled || gameState !== GameState.READY) {
        return;
    }

    const currentTime = Date.now();
    if (currentTime - lastMovementTime < 16) {
        return;
    }
    lastMovementTime = currentTime;

    let dx = 0, dy = 0;
    let moved = false;

    if (keyPressed('ArrowUp')) {
        dy = -player.speed;
        player.facing = 'up';
        moved = true;
    } else if (keyPressed('ArrowDown')) {
        dy = player.speed;
        player.facing = 'down';
        moved = true;
    }

    if (keyPressed('ArrowLeft')) {
        dx = -player.speed;
        player.facing = 'left';
        moved = true;
    } else if (keyPressed('ArrowRight')) {
        dx = player.speed;
        player.facing = 'right';
        moved = true;
    }

    if (!moved) {
        player.frame = 0;
        return;
    }

    const newX = player.x + dx;
    const newY = player.y + dy;

    if (!isColliding(player, dx, dy)) {
        if (movementQueue.length < MAX_QUEUE_SIZE) {
            movementQueue.push({
                x: newX,
                y: newY,
                facing: player.facing,
                frame: player.frame + 1,
                timestamp: currentTime
            });
        }
        console.log("Movement queued to send:", movementQueue[movementQueue.length - 1]);
        player.x = newX;
        player.y = newY;
        player.frame++;
        sendMovementToServer();
    }
}

function sendMovementToServer() {
    if (socket && socket.readyState === WebSocket.OPEN) {
        const movement = movementQueue[movementQueue.length - 1];
        if (movement) {
            console.log("Sending movement to server:", movement);
            socket.send(JSON.stringify({
                id: playerId,
                type: 'move',
                x: movement.x,
                y: movement.y,
                facing: movement.facing,
                frame: movement.frame,
                map_id: player.map_id,
                timestamp: movement.timestamp
            }));
            // Clear the movement queue after sending to avoid duplicate sends.
            movementQueue = [];
        }
    }
}

function reconcilePosition() {
    const currentTime = Date.now();
    if (currentTime - lastServerUpdate > 1000 && socket && socket.readyState === WebSocket.OPEN) {
        socket.send(JSON.stringify({
            type: 'get_position',
            id: playerId
        }));
    }
}

// ================================================================
// Update game state (only if READY)
// ================================================================
function update() {
    if (gameState === GameState.READY) {
        updatePlayerMovement();
        interpolatePlayerPositions();
        checkCollisions();
        checkMapTransitions();
    }
}

// ================================================================
// Interpolation for other players
// ================================================================
function interpolatePlayerPositions() {
    const currentTime = Date.now();
    for (let id in receivedPlayerPositions) {
        if (id !== playerId) {
            const p = receivedPlayerPositions[id];
            if (!p.lastUpdate || p.status !== 1) continue;
            const timeDiff = currentTime - p.lastUpdate;
            // Extend timeout to 100 seconds (100000 ms)
            if (timeDiff > 100000) {
                p.status = 0;
                continue;
            }
            const interpolationFactor = Math.min(timeDiff / 100, 1);
            const ease = t => t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
            const easedFactor = ease(interpolationFactor);
            p.renderX = p.prevX + (p.x - p.prevX) * easedFactor;
            p.renderY = p.prevY + (p.y - p.prevY) * easedFactor;
        }
    }
}

// ================================================================
// Collision, map transitions, and game objects
// ================================================================
function checkCollisions() {
    for (let i = 0; i < gameObjects.length; i++) {
        const object = gameObjects[i];
        if (collides(player, object)) {
            if (object.type === 'collectible') {
                gameObjects.splice(i, 1);
                i--;
                player.score += 10;
            } else if (object.type === 'obstacle') {
                player.health = (player.health || 100) - 1;
            }
        }
    }
}

function checkMapTransitions() {
    if (player.x < 0) {
        player.x = 0;
    } else if (player.x + player.width > MAP_WIDTH * TILE_SIZE) {
        player.x = MAP_WIDTH * TILE_SIZE - player.width;
    }
    if (player.y < 0) {
        player.y = 0;
    } else if (player.y + player.height > MAP_HEIGHT * TILE_SIZE) {
        player.y = MAP_HEIGHT * TILE_SIZE - player.height;
    }
}

// ================================================================
// Rendering functions
// ================================================================
function render() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawMap();
    drawPlayers();
    drawGameObjects();
    drawDebugInfo();
}

function drawMap() {
    if (map && map.length === MAP_HEIGHT && map[0].length === MAP_WIDTH) {
        // Draw ground layer
        for (let row = 0; row < map.length; row++) {
            for (let col = 0; col < map[row].length; col++) {
                const [tileRow, tileCol] = map[row][col];
                const tileWidth = 16;
                const tileHeight = 16;
                ctx.drawImage(
                    tilesetImage,
                    1 * tileWidth,
                    1 * tileHeight,
                    tileWidth,
                    tileHeight,
                    col * TILE_SIZE * scale,
                    row * TILE_SIZE * scale,
                    TILE_SIZE * scale,
                    TILE_SIZE * scale
                );
            }
        }
        // Draw additional map tiles if needed
        for (let row = 0; row < map.length; row++) {
            for (let col = 0; col < map[row].length; col++) {
                const [tileRow, tileCol] = map[row][col];
                const tileWidth = 16;
                const tileHeight = 16;
                if (tileRow !== 1 || tileCol !== 1) {
                    ctx.drawImage(
                        tilesetImage,
                        tileCol * tileWidth,
                        tileRow * tileHeight,
                        tileWidth,
                        tileHeight,
                        col * TILE_SIZE * scale,
                        row * TILE_SIZE * scale,
                        TILE_SIZE * scale,
                        TILE_SIZE * scale
                    );
                }
            }
        }
    } else {
        console.warn("Map data is not available or has unexpected dimensions.");
    }
}

function drawPlayers() {
    for (let id in receivedPlayerPositions) {
        const p = receivedPlayerPositions[id];
        if (id !== playerId && p.lastUpdate && p.status === 1) {
            drawPlayer(p);
        }
    }
    drawPlayer(player);
}

function drawPlayer(p) {
    const characterIndex = p.characterIndex || 0;
    const frameIndex = Math.floor(p.frame / ANIM_SPEED) % 3;
    let spriteRow;
    switch (p.facing) {
        case 'down': spriteRow = 0; break;
        case 'up': spriteRow = 1; break;
        case 'left': spriteRow = 2; break;
        case 'right': spriteRow = 3; break;
    }

    let drawX, drawY;
    if (p === player) {
        drawX = p.x;
        drawY = p.y;
    } else {
        const currentTime = Date.now();
        const elapsedTime = currentTime - p.lastUpdate;
        const interpolationFactor = Math.min(elapsedTime / 100, 1);
        const extrapolationTime = 50;
        const extrapolatedX = p.x + (p.x - p.prevX) * (elapsedTime + extrapolationTime) / 100;
        const extrapolatedY = p.y + (p.y - p.prevY) * (elapsedTime + extrapolationTime) / 100;
        drawX = p.prevX + (extrapolatedX - p.prevX) * interpolationFactor;
        drawY = p.prevY + (extrapolatedY - p.prevY) * interpolationFactor;
    }

    ctx.drawImage(
        charactersImage,
        characterIndex * CHAR_WIDTH * 3 + frameIndex * CHAR_WIDTH,
        spriteRow * CHAR_HEIGHT,
        CHAR_WIDTH,
        CHAR_HEIGHT,
        Math.round(drawX * scale),
        Math.round(drawY * scale),
        p.width * scale,
        p.height * scale
    );
}

function drawGameObjects() {
    for (let i = 0; i < gameObjects.length; i++) {
        const object = gameObjects[i];
        const objectImage = new Image();
        objectImage.src = object.sprite;
        ctx.drawImage(objectImage, object.x * scale, object.y * scale, object.width * scale, object.height * scale);
    }
}

function drawDebugInfo() {
    if (DEBUG_MODE) {
        ctx.fillStyle = 'rgba(255,0,0,0.2)';
        ctx.fillRect(player.x * scale, player.y * scale, player.width * scale, player.height * scale);
    }
}

// ================================================================
// Server message handling and reconciliation
// ================================================================
function handleServerMessage(data) {
    switch (data.type) {
        case 'init':
            // Set game state to READY upon receiving init data.
            gameState = GameState.READY;
            console.log("Game State set to READY from 'init' message.");
            player.map_id = data.player.map_id;
            map = data.mapData;
            receivedPlayerPositions = {};
            if (data.players && Object.keys(data.players).length > 0) {
                for (let id in data.players) {
                    if (data.players[id].map_id === player.map_id) {
                        updatePlayerPosition(data.players[id]);
                    }
                }
            } else {
                // Request players data if missing.
                socket.send(JSON.stringify({ type: 'get_players', mapId: player.map_id }));
            }
            break;
        case 'update':
            for (let id in data.players) {
                if (data.players[id].map_id === player.map_id) {
                    updatePlayerPosition(data.players[id]);
                }
            }
            render();
            break;
        case 'playerUpdate':
            if (data.player.map_id === player.map_id) {
                const updatedPlayer = data.player;
                console.log("Received playerUpdate for", updatedPlayer.id, "with x:", updatedPlayer.x, "y:", updatedPlayer.y);
                if (updatedPlayer.id === playerId) {
                    // For debugging, snap directly to the server position.
                    handleServerCorrection(updatedPlayer.x, updatedPlayer.y);
                    lastServerUpdate = Date.now();
                } else {
                    receivedPlayerPositions[updatedPlayer.id] = {
                        x: updatedPlayer.x,
                        y: updatedPlayer.y,
                        prevX: updatedPlayer.x,
                        prevY: updatedPlayer.y,
                        width: updatedPlayer.width,
                        height: updatedPlayer.height,
                        characterIndex: updatedPlayer.character_index,
                        frame: updatedPlayer.frame,
                        facing: updatedPlayer.facing,
                        status: updatedPlayer.status !== undefined ? updatedPlayer.status : 1,
                        lastUpdate: Date.now()
                    };
                }
            }
            break;
        case 'mapData':
            player.map_id = data.map_id;
            if (data.data && data.data.length === MAP_HEIGHT && data.data[0].length === MAP_WIDTH) {
                map = data.data;
            } else {
                console.warn("Invalid map data received, using default map.");
                map = createDefaultMap();
            }
            if (data.id !== undefined && data.x !== undefined && data.y !== undefined) {
                if (data.id == playerId) {
                    player.x = data.x;
                    player.y = data.y;
                }
            }
            receivedPlayerPositions = {};
            if (data.players && Object.keys(data.players).length > 0) {
                for (let id in data.players) {
                    updatePlayerPosition(data.players[id]);
                }
            } else {
                // Request players data if missing.
                socket.send(JSON.stringify({ type: 'get_players', mapId: player.map_id }));
            }
            render();
            hideLoadingScreen();
            enableMovement();
            gameState = GameState.READY;
            console.log("Game State set to READY from 'mapData' message.");
            break;
        case 'playersData':
            receivedPlayerPositions = {};
            for (let id in data.players) {
                if (data.players[id].map_id === player.map_id) {
                    receivedPlayerPositions[id] = {
                        x: data.players[id].x,
                        y: data.players[id].y,
                        width: data.players[id].width,
                        height: data.players[id].height,
                        characterIndex: data.players[id].character_index,
                        frame: data.players[id].frame,
                        facing: data.players[id].facing,
                        lastUpdate: Date.now(),
                        status: 1
                    };
                }
            }
            render();
            break;
        case 'reloadPlayers':
            receivedPlayerPositions = {};
            socket.send(JSON.stringify({
                type: 'get_players',
                mapId: data.mapId
            }));
            break;
        case 'position':
            if (data.id === playerId) {
                player.x = data.x;
                player.y = data.y;
            }
            break;
        case 'playerLeaveMap':
            delete receivedPlayerPositions[data.playerId];
            render();
            break;
        case 'enteringNewMap':
            showLoadingScreen();
            disableMovement();
            break;
        case 'playerEnterMap':
            if (data.playerId === playerId) {
                receivedPlayerPositions = {};
                socket.send(JSON.stringify({
                    type: 'get_players',
                    mapId: data.mapId
                }));
            } else {
                delete receivedPlayerPositions[data.playerId];
            }
            break;
    }
}

function disableMovement() {
    player.movementDisabled = true;
}

function enableMovement() {
    player.movementDisabled = false;
}

function updatePlayerPosition(playerData) {
    const existingPlayer = receivedPlayerPositions[playerData.id];
    if (existingPlayer) {
        existingPlayer.prevX = existingPlayer.x;
        existingPlayer.prevY = existingPlayer.y;
        existingPlayer.x = playerData.x || existingPlayer.x;
        existingPlayer.y = playerData.y || existingPlayer.y;
        existingPlayer.width = playerData.width || existingPlayer.width;
        existingPlayer.height = playerData.height || existingPlayer.height;
        existingPlayer.characterIndex = playerData.character_index !== undefined ? playerData.character_index : existingPlayer.characterIndex;
        existingPlayer.frame = playerData.frame || existingPlayer.frame;
        existingPlayer.facing = playerData.facing || existingPlayer.facing;
        existingPlayer.status = playerData.status !== undefined ? playerData.status : existingPlayer.status;
        existingPlayer.lastUpdate = Date.now();
    } else {
        receivedPlayerPositions[playerData.id] = {
            x: playerData.x,
            y: playerData.y,
            prevX: playerData.x,
            prevY: playerData.y,
            width: playerData.width,
            height: playerData.height,
            characterIndex: playerData.character_index,
            frame: playerData.frame,
            facing: playerData.facing,
            status: playerData.status !== undefined ? playerData.status : 1,
            lastUpdate: Date.now()
        };
    }
}

// ================================================================
// Utility functions
// ================================================================
function keyPressed(key) {
    return keyState[key];
}

function collides(obj1, obj2) {
    return (
        obj1.x < obj2.x + obj2.width &&
        obj1.x + obj1.width > obj2.x &&
        obj1.y < obj2.y + obj2.height &&
        obj1.y + obj1.height > obj2.y
    );
}

const walkableTiles = [
    [1, 1],
    [0, 0],
    [0, 1],
    [0, 2],
    [1, 0],
    [1, 2],
    [2, 0],
    [2, 1],
    [2, 2],
    [0, 3],
    [0, 4],
    [1, 3],
    [1, 4],
    [0, 6],
    [0, 7],
    [0, 8],
    [1, 6],
    [1, 7],
    [1, 8],
    [4, 6],
    [4, 7],
    [4, 8],
    [4, 9],
    [5, 6],
    [5, 7],
    [5, 8],
    [5, 9],
];

function isColliding(obj, offsetX, offsetY) {
    const newX = obj.x + offsetX;
    const newY = obj.y + offsetY;

    const leftX = Math.floor(newX / TILE_SIZE);
    const rightX = Math.floor((newX + obj.width - 1) / TILE_SIZE);
    const topY = Math.floor(newY / TILE_SIZE);
    const bottomY = Math.floor((newY + obj.height - 1) / TILE_SIZE);

    for (let row = topY; row <= bottomY; row++) {
        for (let col = leftX; col <= rightX; col++) {
            if (row < 0 || row >= MAP_HEIGHT || col < 0 || col >= MAP_WIDTH) {
                continue;
            }
            const [tileRow, tileCol] = map[row][col];
            if (!isWalkable(tileRow, tileCol)) {
                return true;
            }
        }
    }

    for (let id in receivedPlayerPositions) {
        if (id !== playerId) {
            const otherPlayer = receivedPlayerPositions[id];
            if (otherPlayer.lastUpdate && otherPlayer.status === 1) {
                if (collides({
                    x: newX,
                    y: newY,
                    width: obj.width,
                    height: obj.height
                }, otherPlayer)) {
                    return true;
                }
            }
        }
    }

    return false;
}

function isWalkable(tileRow, tileCol) {
    for (const [row, col] of walkableTiles) {
        if (tileRow === row && tileCol === col) {
            return true;
        }
    }
    return false;
}

function getRandomColor() {
    const red = Math.floor(Math.random() * 256);
    const green = Math.floor(Math.random() * 256);
    const blue = Math.floor(Math.random() * 256);
    return `rgb(${red},${green},${blue})`;
}

function showMessage(message) {
    const messageElement = document.getElementById('message');
    messageElement.innerText = message;
    messageElement.style.display = 'block';
}

function hideLoadingScreen() {
    const loadingScreen = document.getElementById('loadingScreen');
    if (loadingScreen) {
        loadingScreen.style.display = 'none';
    }
}

function showLoadingScreen() {
    const loadingScreen = document.getElementById('loadingScreen');
    if (loadingScreen) {
        loadingScreen.style.display = 'flex';
    }
}

function getPlayerId() {
    let id = localStorage.getItem('playerId');
    if (!id) {
        id = generatePlayerId();
        localStorage.setItem('playerId', id);
    }
    return id;
}

function generatePlayerId() {
    return 'player_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

// ================================================================
// Prediction rollback for server corrections
// ================================================================
function handleServerCorrection(serverX, serverY) {
    console.log("handleServerCorrection: serverX:", serverX, "serverY:", serverY, "local x:", player.x, "local y:", player.y);
    // For debugging, simply snap to the server coordinates.
    player.x = serverX;
    player.y = serverY;
}

// ================================================================
// Image loading
// ================================================================
const tilesetImage = new Image();
const charactersImage = new Image();

let imagesLoaded = 0;
function imageLoaded() {
    imagesLoaded++;
    if (imagesLoaded === 2) {
        init();
    }
}

tilesetImage.src = 'tileset.png';
tilesetImage.onload = imageLoaded;

charactersImage.src = 'characters.png';
charactersImage.onload = imageLoaded;
