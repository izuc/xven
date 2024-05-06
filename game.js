// game.js

const canvas = document.getElementById('gameCanvas');
const ctx = canvas.getContext('2d');
const keyState = {};

// Game constants
const TILE_SIZE = 32;
const MAP_WIDTH = 25;
const MAP_HEIGHT = 20;

const CHAR_WIDTH = 16;
const CHAR_HEIGHT = 16;
const ANIM_SPEED = 10;

// Game variables
let player;
let map;
let gameObjects;
let scale;

// WebSocket connection
let socket;

let playerId;
let receivedPlayerPositions = {};

// Button event handlers
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

// Reset all key states to false
function resetAllKeyStates() {
	keyState['ArrowUp'] = false;
	keyState['ArrowLeft'] = false;
	keyState['ArrowRight'] = false;
	keyState['ArrowDown'] = false;
}

// Add event listener for the X button
const xButton = document.getElementById('xButton');
xButton.addEventListener('click', showXButtonAlert);
xButton.addEventListener('touchstart', showXButtonAlert);

// Handle key press event
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

// Set canvas size to match the window size
function resizeCanvas() {
	const maxWidth = 800;
	scale = Math.min(window.innerWidth / maxWidth, 1); // Ensure it never scales above 1 on larger screens

	const canvasWidth = maxWidth * scale;
	const canvasHeight = (640 * scale); // Maintain the 800x640 aspect ratio

	canvas.width = canvasWidth;
	canvas.height = canvasHeight;

	// Adjust CSS for full responsiveness
	canvas.style.width = scale === 1 ? maxWidth + 'px' : '100%';
	canvas.style.height = canvasHeight + 'px';
}

// Game loop
function gameLoop() {
	const startTime = performance.now(); // High precision timer

	update();
	render();

	const elapsedTime = performance.now() - startTime;
	const frameTime = 1000 / 100; // Target 60 FPS
	let timeToWait = Math.max(0, frameTime - elapsedTime);

	// Request the next frame, potentially with timeout for smoothness
	requestAnimationFrame(() => {
		setTimeout(gameLoop, timeToWait);
	});
}

function init() {
	playerId = getPlayerId();

	// Initial canvas resize
	resizeCanvas();

	// Resize canvas on window resize
	window.addEventListener('resize', resizeCanvas);

	player = {
		width: TILE_SIZE,
		height: TILE_SIZE,
		speed: 2,
		frame: 0,
		facing: 'down',
		characterIndex: 0,
	};

	const characterPicker = document.getElementById('characterPicker');

	// Generate character options dynamically
	const characterSelection = document.getElementById('characterSelection');
	const totalCharacters = 10; // Total number of characters
	const frameWidth = 48; // Width of each frame in the spritesheet
	const frameHeight = 32; // Height of each frame in the spritesheet
	const partWidth = 16; // Width of the part to display
	const partHeight = 16; // Height of the part to display

	for (let i = 0; i < totalCharacters; i++) {
		const characterOption = document.createElement('canvas');
		characterOption.width = partWidth;
		characterOption.height = partHeight;
		characterOption.className = 'character-option';
		characterOption.dataset.index = i;

		const characterCtx = characterOption.getContext('2d');
		characterCtx.imageSmoothingEnabled = false;

		// Correct srcX to start at the first frame of each character's set
		const srcX = i * frameWidth; // Move directly to the next character's first frame
		const srcY = 0; // Assuming the characters are on the top row

		characterCtx.drawImage(
			charactersImage,
			srcX, srcY, // Use the start of the first frame of each character
			partWidth, partHeight, // Only take the upper-left 16x16 portion of the frame
			0, 0, // Draw at (0, 0) on the canvas
			partWidth, partHeight // Destination size on canvas
		);

		characterOption.addEventListener('click', function() {
			const selectedIndex = parseInt(this.dataset.index);
			player.characterIndex = selectedIndex;

			localStorage.setItem('playerCharacterIndex', selectedIndex);

			// Remove 'selected' class from all options
			const characterOptions = document.getElementsByClassName('character-option');
			for (let j = 0; j < characterOptions.length; j++) {
				characterOptions[j].classList.remove('selected');
			}

			// Add 'selected' class to the clicked option
			this.classList.add('selected');

			// Send the updated character index to the server only if the WebSocket is open
			if (socket.readyState === WebSocket.OPEN) {
				socket.send(JSON.stringify({
					id: playerId,
					type: 'character_index',
					character_index: player.characterIndex,
				}));
			}
		});

		characterSelection.appendChild(characterOption);
	}

	// Set the initial character index
	player.characterIndex = localStorage.getItem('playerCharacterIndex') || 0;
	characterSelection.children[player.characterIndex].classList.add('selected');

	// Initialize game objects
	gameObjects = [];

	// Add event listener for keyboard events
	document.addEventListener('keydown', handleKeyDown);
	document.addEventListener('keyup', handleKeyUp);

	// Establish WebSocket connection
	socket = new WebSocket('wss://server.xven.org');

	socket.onopen = function() {
		console.log('WebSocket connection established');
		// Send the player ID and color to the server for registration
		socket.send(JSON.stringify({
			type: 'register',
			id: playerId,
			character_index: player.characterIndex
		}));

		socket.send(JSON.stringify({
			type: 'get_position',
			id: playerId,
		}));

		socket.send(JSON.stringify({
			type: 'get_map',
			mapId: player.map_id
		}));

		// Hide the message and start the game loop
		const messageElement = document.getElementById('message');
		messageElement.style.display = 'none';
		canvas.style.display = 'block';
		gameLoop();
	};

	socket.onmessage = function(event) {
		const data = JSON.parse(event.data);

		handleServerMessage(data);
	};

	render();

	let reconnectInterval;
	let reconnectAttempts = 0;
	const maxReconnectAttempts = 5;

	socket.onclose = function() {
		console.log('WebSocket connection closed');
		// Show the message and hide the canvas
		showMessage('No connection to the server. \nAttempting to reconnect...');
		canvas.style.display = 'none';

		// Clear any existing reconnect interval
		clearInterval(reconnectInterval);

		// Attempt to reconnect every 5 seconds
		reconnectInterval = setInterval(reconnect, 5000);
	};

	function reconnect() {
		// Increment the reconnect attempts counter
		reconnectAttempts++;

		// Check if the maximum number of reconnect attempts has been reached
		if (reconnectAttempts > maxReconnectAttempts) {
			clearInterval(reconnectInterval);
			showMessage('Unable to reconnect to the server. Please refresh the page.');
			return;
		}

		// Establish a new WebSocket connection
		socket = new WebSocket('wss://server.xven.org');

		socket.onopen = function() {
			console.log('WebSocket connection re-established');
			// Send the player ID and color to the server for registration
			socket.send(JSON.stringify({
				type: 'register',
				id: playerId,
				character_index: player.characterIndex
			}));

			// Hide the message and start the game loop
			const messageElement = document.getElementById('message');
			messageElement.style.display = 'none';
			canvas.style.display = 'block';
			gameLoop();

			// Clear the reconnect interval and reset the reconnect attempts counter
			clearInterval(reconnectInterval);
			reconnectAttempts = 0;
		};

		socket.onmessage = function(event) {
			const data = JSON.parse(event.data);

			handleServerMessage(data);
			render(); // Ensure immediate rendering after processing new data
		};

		socket.onclose = function() {
			console.log('WebSocket connection closed');
			// Show the message and hide the canvas
			showMessage('No connection to the server. \nAttempting to reconnect...');
			canvas.style.display = 'none';

			// Clear any existing reconnect interval
			clearInterval(reconnectInterval);

			// Attempt to reconnect every 5 seconds
			reconnectInterval = setInterval(reconnect, 5000);
		};

		socket.onerror = function(error) {
			console.error('WebSocket error:', error);
			// Show the message and hide the canvas
			showMessage('Error connecting to the server. \nAttempting to reconnect...');
			canvas.style.display = 'none';
		};
	}
}

// Handle key down event
function handleKeyDown(event) {
	keyState[event.code] = true;
}

// Handle key up event
function handleKeyUp(event) {
	keyState[event.code] = false;
}

// Update game state
function update() {
	const startTime = performance.now(); // High-precision timestamp

	// Update player movement (frame-independent)
	updatePlayerMovement();

	// Interpolate player positions (consider timestamps)
	interpolatePlayerPositions();

	// Check for collisions (potential optimization)
	checkCollisions();

	// Check for map transitions 
	checkMapTransitions();

	const endTime = performance.now();
	console.log("Update Time:", endTime - startTime); // For profiling
}

function interpolatePlayerPositions() {
	const currentTime = Date.now();

	for (let id in receivedPlayerPositions) {
		if (id !== playerId) {
			const player = receivedPlayerPositions[id];

			// Optimized calculation with timestamps
			const elapsedTime = currentTime - player.lastUpdate;
			const interpolationFactor = Math.min(elapsedTime / 100, 1);

			// Consider easing functions for smoother interpolation
			player.renderX = player.prevX + (player.x - player.prevX) * interpolationFactor;
			player.renderY = player.prevY + (player.y - player.prevY) * interpolationFactor;
		}
	}
}

// Update player movement
function updatePlayerMovement() {
	// Check if movement is disabled
	if (player.movementDisabled) {
		return;
	}
	let dx = 0;
	let dy = 0;

	if (keyPressed('ArrowUp')) {
		dy = -player.speed;
		player.facing = 'up';
	}
	if (keyPressed('ArrowDown')) {
		dy = player.speed;
		player.facing = 'down';
	}
	if (keyPressed('ArrowLeft')) {
		dx = -player.speed;
		player.facing = 'left';
	}
	if (keyPressed('ArrowRight')) {
		dx = player.speed;
		player.facing = 'right';
	}

	const newX = player.x + dx;
	const newY = player.y + dy;
	if (map && map.length === MAP_HEIGHT && map[0].length === MAP_WIDTH) {
		if (!isColliding(player, dx, dy)) {
			player.x = newX;
			player.y = newY;

			// Update the animation frame only if the player is moving
			if (dx !== 0 || dy !== 0) {
				player.frame++;
			} else {
				player.frame = 0;
			}

			// Send player movement, facing direction, and frame to the server only if the WebSocket is open
			if (socket.readyState === WebSocket.OPEN) {
				socket.send(JSON.stringify({
					id: playerId,
					type: 'move',
					x: player.x,
					y: player.y,
					facing: player.facing,
					frame: player.frame,
					map_id: player.map_id
				}));
			}
		}
	}
}

// Check for collisions
function checkCollisions() {
	// Check for collisions between the player and game objects
	for (let i = 0; i < gameObjects.length; i++) {
		const object = gameObjects[i];
		if (collides(player, object)) {
			if (object.type === 'collectible') {
				// Handle collectible object collision
				gameObjects.splice(i, 1);
				i--;
				// Example: Increase player score
				player.score += 10;
			} else if (object.type === 'obstacle') {
				// Handle obstacle object collision
				// Example: Reduce player health
				player.health -= 1;
			}
		}
	}
}

// Check for map transitions
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

// Render the game
function render() {
	ctx.clearRect(0, 0, canvas.width, canvas.height);
	drawMap();
	drawPlayers();
	drawGameObjects();
}

function drawMap() {
	if (map && map.length === MAP_HEIGHT && map[0].length === MAP_WIDTH) {
		// Draw the ground layer first
		for (let row = 0; row < map.length; row++) {
			for (let col = 0; col < map[row].length; col++) {
				const [tileRow, tileCol] = map[row][col];
				const tileWidth = 16;
				const tileHeight = 16;

				// Draw the ground tile (1, 1) for all positions
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

		// Draw the other objects on top of the ground layer
		for (let row = 0; row < map.length; row++) {
			for (let col = 0; col < map[row].length; col++) {
				const [tileRow, tileCol] = map[row][col];
				const tileWidth = 16;
				const tileHeight = 16;

				// Draw the other objects tiles only if they are not the ground tile (1, 1)
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
	console.log('receivedPlayerPositions:', receivedPlayerPositions);
	// Draw other players first
	for (let id in receivedPlayerPositions) {
		const p = receivedPlayerPositions[id];
		if (id !== playerId) {
			drawPlayer(p);
		}
	}

	// Draw the main player last (to appear on top)
	drawPlayer(player);
}

function drawPlayer(p) {
	const characterIndex = p.characterIndex || 0;
	const frameIndex = Math.floor(p.frame / ANIM_SPEED) % 3;
	let spriteRow;
	switch (p.facing) {
		case 'down':
			spriteRow = 0;
			break;
		case 'up':
			spriteRow = 1;
			break;
		case 'left':
			spriteRow = 2;
			break;
		case 'right':
			spriteRow = 3;
			break;
	}

	let drawX, drawY;
	if (p === player) {
		// Use the current position for the main player
		drawX = p.x;
		drawY = p.y;
	} else {
		const currentTime = Date.now();
		const elapsedTime = currentTime - p.lastUpdate;
		const interpolationFactor = Math.min(elapsedTime / 100, 1);
		const extrapolationTime = 50; // Adjust this value to control the extrapolation
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

function handleServerMessage(data) {
	switch (data.type) {
		case 'init':
			player.map_id = data.player.map_id;
			map = data.mapData;
			// Clear existing player positions
			//receivedPlayerPositions = {};
			// Add players only for the current map
			for (let id in data.players) {
				if (data.players[id].map_id === player.map_id) {
					updatePlayerPosition(data.players[id]);
				}
			}
			break;
		case 'update':
			if (data.mapId === player.map_id) {
				for (let id in data.players) {
					updatePlayerPosition(data.players[id]);
				}
			} else {
				// Clear the receivedPlayerPositions object if the received map ID doesn't match
				receivedPlayerPositions = {};
			}
			render();
			break;
		case 'playerUpdate':
			console.log('Received playerUpdate:', data.player);
			if (data.player.map_id === player.map_id) {
				const updatedPlayer = data.player;
				if (updatedPlayer.id === playerId) {
					// Server reconciliation for the player's own position
					const serverX = updatedPlayer.x;
					const serverY = updatedPlayer.y;
					const interpolationFactor = 0.1; // Adjust this value to control the smoothness
					player.x += (serverX - player.x) * interpolationFactor;
					player.y += (serverY - player.y) * interpolationFactor;
				} else {
					// Add new player if not existing
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
						lastUpdate: Date.now()
					};
				}
			}
			break;
		case 'mapData':
			player.map_id = data.map_id; // Set current map ID
			map = data.data; // Update the map data

			console.log(data);
			if (data.id !== undefined && data.x !== undefined && data.y !== undefined) {
				if (data.id == playerId) {
					player.x = data.x; // Update player's x position
					player.y = data.y; // Update player's y position
				}
			}

			// Clear existing player positions
			receivedPlayerPositions = {};

			// Load new players into the scene
			for (let id in data.players) {
				updatePlayerPosition(data.players[id]);
			}

			// Render to reflect changes
			render();

			hideLoadingScreen(); // Hide the loading screen
			enableMovement(); // Enable player movement
			break;
		case 'playersData':
			// Clear existing player positions from previous map
			receivedPlayerPositions = {};

			// Update players data for the current map
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
					};
				}
			}

			render();
			break;
		case 'reloadPlayers':
			// Clear existing player positions
			receivedPlayerPositions = {};
			// Request player data for the current map
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
			// Remove the player from the receivedPlayerPositions object
			delete receivedPlayerPositions[data.playerId];
			// Render to reflect the changes
			render();
			break;
		case 'enteringNewMap':
			showLoadingScreen(); // Show the loading screen
			disableMovement(); // Disable player movement
			break;
		case 'playerEnterMap':
			if (data.playerId === playerId) {
				// If it's the current player entering a new map, clear existing player positions and request player data for the new map
				receivedPlayerPositions = {};
				socket.send(JSON.stringify({
					type: 'get_players',
					mapId: data.mapId
				}));
			} else {
				// If it's another player entering the map, remove their position from the receivedPlayerPositions object
				delete receivedPlayerPositions[data.playerId];
			}
			break;
	}
}

function disableMovement() {
	// Set a flag to indicate that movement is disabled
	player.movementDisabled = true;
}

function enableMovement() {
	// Set the flag to indicate that movement is enabled
	player.movementDisabled = false;
}

function updatePlayerPosition(playerData) {
	const existingPlayer = receivedPlayerPositions[playerData.id];
	if (existingPlayer) {
		// Store the previous position before updating
		existingPlayer.prevX = existingPlayer.x;
		existingPlayer.prevY = existingPlayer.y;
		existingPlayer.x = playerData.x || existingPlayer.x;
		existingPlayer.y = playerData.y || existingPlayer.y;
		existingPlayer.width = playerData.width || existingPlayer.width;
		existingPlayer.height = playerData.height || existingPlayer.height;
		existingPlayer.characterIndex = playerData.character_index !== undefined ? playerData.character_index : existingPlayer.characterIndex;
		existingPlayer.frame = playerData.frame || existingPlayer.frame;
		existingPlayer.facing = playerData.facing || existingPlayer.facing;
	} else {
		// Add new player if not existing
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
		};
	}
}

// Utility functions

// Check if a key is currently pressed
function keyPressed(key) {
	return keyState[key];
}

// Check if two objects collide
function collides(obj1, obj2) {
	return (
		obj1.x < obj2.x + obj2.width &&
		obj1.x + obj1.width > obj2.x &&
		obj1.y < obj2.y + obj2.height &&
		obj1.y + obj1.height > obj2.y
	);
}

const walkableTiles = [
	[1, 1], // Dirt

	[0, 0], // top left of green area, dirt in bottom right corner
	[0, 1], // top middle of green area, dirt at the bottom
	[0, 2], // top right of the green area, dirt at the bottom left corner
	[1, 0], // left middle of the green area, dirt at the right
	[1, 2], // right of the green area, dirt at the left
	[2, 0], // bottom left of the green area, dirt at the top right corner
	[2, 1], // bottom middle of the green area, dirt at the top
	[2, 2], // bottom right of the green area, dirt at the top left corner

	[0, 3], // top left of the dirt with grass corner in bottom right
	[0, 4], // top right of the dirt with grass corner in bottom left
	[1, 3], // bottom left of the dirt with grass corner in top right
	[1, 4], // bottom right of the dirt with grass corner in top left

	[0, 6], // Grass with 3 small blue flowers on the ground
	[0, 7], // Grass with 2 small red flowers on the ground
	[0, 8], // Grass with 3 small blue flowers on the ground (flowers facing different direction)
	[1, 6], // Grass with bigger lighter green leaves
	[1, 7], // Grass with 1 blue flower
	[1, 8], // Grass with bigger lighter green leaves (subtle differences)

	[4, 6],
	[4, 7],
	[4, 8],
	[4, 9], // Normal grass
	[5, 6],
	[5, 7],
	[5, 8],
	[5, 9], // Normal grass with different patterns
];

// Check if a move is valid
function isColliding(obj, offsetX, offsetY) {
	const newX = obj.x + offsetX;
	const newY = obj.y + offsetY;

	// Calculate all corners of the new position
	const leftX = Math.floor(newX / TILE_SIZE);
	const rightX = Math.floor((newX + obj.width - 1) / TILE_SIZE);
	const topY = Math.floor(newY / TILE_SIZE);
	const bottomY = Math.floor((newY + obj.height - 1) / TILE_SIZE);

	// Check for collision with any solid tile in the area covered
	for (let row = topY; row <= bottomY; row++) {
		for (let col = leftX; col <= rightX; col++) {
			if (row < 0 || row >= MAP_HEIGHT || col < 0 || col >= MAP_WIDTH) {
				continue; // Skip checking out of bounds indices
			}
			const [tileRow, tileCol] = map[row][col];
			if (!isWalkable(tileRow, tileCol)) {
				return true;
			}
		}
	}

	// Check for collision with other players
	for (let id in receivedPlayerPositions) {
		if (id !== playerId) {
			const otherPlayer = receivedPlayerPositions[id];
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

	return false;
}


// Check if a tile is walkable
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
	return `rgb(${red},${green},${blue})`; // Return RGB color string
}

function showMessage(message) {
	const messageElement = document.getElementById('message');
	messageElement.innerText = message;
	messageElement.style.display = 'block';
}

// Generate a unique player ID
function getPlayerId() {
	let playerId = localStorage.getItem('playerId');
	if (!playerId) {
		playerId = generatePlayerId();
		localStorage.setItem('playerId', playerId);
	}
	return playerId;
}

function generatePlayerId() {
	return 'player_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

function showLoadingScreen() {
	const loadingScreen = document.getElementById('loadingScreen');
	loadingScreen.style.display = 'flex';
}

function hideLoadingScreen() {
	const loadingScreen = document.getElementById('loadingScreen');
	loadingScreen.style.display = 'none';
}

const tilesetImage = new Image();
const charactersImage = new Image();

let imagesLoaded = 0; // Counter to track the number of images loaded

function imageLoaded() {
	imagesLoaded++; // Increment the counter each time an image loads
	if (imagesLoaded === 2) { // Check if all images are loaded
		init(); // Initialize the game only when all images are loaded
	}
}

// Set the source and load event handler for the tileset image
tilesetImage.src = 'tileset.png';
tilesetImage.onload = imageLoaded;

// Set the source and load event handler for the characters image
charactersImage.src = 'characters.png';
charactersImage.onload = imageLoaded;
